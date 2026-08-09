<?php
/**
 * Knowledge base helpers.
 *
 * Extracts plain text from uploaded files and loads the per-tenant KB
 * content for injection into the AI system prompt.
 *
 * Supported formats:
 *   - text/plain, text/markdown           -> read directly
 *   - application/pdf                     -> `pdftotext - -` via shell (Poppler)
 *   - application/vnd.openxmlformats-...  -> unzip word/document.xml + strip tags
 *
 * Anything else returns null + an error message so the admin can paste
 * the text manually.
 */

require_once __DIR__ . '/helpers.php';

// Soft caps so a runaway upload can't blow the AI context window.
const KB_MAX_BYTES_PER_FILE = 5 * 1024 * 1024;   // 5 MB raw
const KB_MAX_CHARS_PER_ITEM = 100000;            // 100k chars extracted
const KB_MAX_TOTAL_CHARS    = 200000;            // 200k chars in one AI call

/**
 * @return array{ok:bool, text?:string, error?:string}
 */
function kb_extract_text(string $localPath, string $mime, string $originalName = ''): array
{
    if (!is_readable($localPath)) {
        return ['ok' => false, 'error' => 'File not readable.'];
    }
    if (filesize($localPath) > KB_MAX_BYTES_PER_FILE) {
        return ['ok' => false, 'error' => 'File exceeds ' . (KB_MAX_BYTES_PER_FILE / 1024 / 1024) . ' MB upload cap.'];
    }

    $ext = strtolower(pathinfo($originalName ?: $localPath, PATHINFO_EXTENSION));

    if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'csv'], true)) {
        $text = (string)file_get_contents($localPath);
        return ['ok' => true, 'text' => kb_normalize_text($text)];
    }

    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return kb_extract_pdf($localPath);
    }

    if ($ext === 'docx'
        || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        return kb_extract_docx($localPath);
    }

    return [
        'ok' => false,
        'error' => 'Unsupported file type (' . ($mime ?: 'unknown') . '). Paste the text manually, or convert to TXT/PDF/DOCX first.',
    ];
}

/**
 * Best-effort PDF extraction using pdftotext (Poppler). Returns a helpful
 * error if the tool isn't installed so the admin knows what to do.
 */
function kb_extract_pdf(string $localPath): array
{
    $pdftotext = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($pdftotext === '') {
        return [
            'ok' => false,
            'error' => 'pdftotext is not installed on this server. Either install Poppler (apt install poppler-utils), or paste the PDF text manually.',
        ];
    }
    $cmd = escapeshellcmd($pdftotext) . ' -layout -nopgbrk ' . escapeshellarg($localPath) . ' - 2>/dev/null';
    $out = (string)@shell_exec($cmd);
    if (trim($out) === '') {
        return ['ok' => false, 'error' => 'pdftotext returned no text. The PDF may be image-only (needs OCR) - paste manually.'];
    }
    return ['ok' => true, 'text' => kb_normalize_text($out)];
}

/**
 * Lightweight DOCX extraction: unzip and read the body XML, then strip tags.
 * Handles plain prose well; doesn't preserve tables/lists structure but the
 * text content still lands in the KB.
 */
function kb_extract_docx(string $localPath): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP ZipArchive extension not available - install php-zip.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($localPath) !== true) {
        return ['ok' => false, 'error' => 'Could not open DOCX (not a valid zip).'];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml) || $xml === '') {
        return ['ok' => false, 'error' => 'Could not read DOCX body.'];
    }
    // Replace paragraph + line-break tags with newlines before stripping.
    $xml = preg_replace('#</w:p>|<w:br[^>]*/>#', "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return ['ok' => true, 'text' => kb_normalize_text($text)];
}

function kb_normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    $text = trim((string)$text);
    if (mb_strlen($text) > KB_MAX_CHARS_PER_ITEM) {
        $text = mb_substr($text, 0, KB_MAX_CHARS_PER_ITEM) . "\n\n[…truncated…]";
    }
    return $text;
}

/**
 * Concatenate all active KB articles for a company, capped at
 * KB_MAX_TOTAL_CHARS. Returns null if there's no active KB so the AI
 * client can omit the KB system block entirely (and skip a cache miss).
 */
function kb_load_for_company(int $companyId): ?array
{
    $db = aiserve_db();

    // High-signal Q&A pairs FIRST — they're compact and the AI treats
    // rendered "Q: … A: …" blocks as answered examples. Falls back
    // silently when the kb_qa_pairs table doesn't exist yet (pre-
    // phase47 workspaces).
    $qaBlock = '';
    $qaCount = 0;
    try {
        $q = $db->prepare(
            'SELECT question, answer FROM kb_qa_pairs
             WHERE company_id = ? AND status = "active"
             ORDER BY id ASC LIMIT 200'
        );
        $q->execute([$companyId]);
        $qa = $q->fetchAll();
        if ($qa) {
            $lines = ["### Common questions the team already has answers for"];
            foreach ($qa as $r) {
                $lines[] = "Q: " . trim((string)$r['question']);
                $lines[] = "A: " . trim((string)$r['answer']);
                $lines[] = '';
            }
            $qaBlock = implode("\n", $lines);
            $qaCount = count($qa);
        }
    } catch (Throwable $e) { /* pre-phase47 = skip */ }

    $stmt = $db->prepare(
        'SELECT id, title, content_text, content_chars
         FROM knowledge_base
         WHERE company_id = ? AND status = "active"
         ORDER BY id ASC'
    );
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll();
    if (!$rows && $qaBlock === '') return null;

    $parts = [];
    $total = 0;
    $titles = [];

    if ($qaBlock !== '') {
        $parts[]  = $qaBlock;
        $titles[] = 'Q&A pairs (' . $qaCount . ')';
        $total   += mb_strlen($qaBlock);
    }

    foreach ($rows as $r) {
        $title = (string)$r['title'];
        $body  = (string)$r['content_text'];
        $block = "### " . $title . "\n" . $body;
        if ($total + mb_strlen($block) > KB_MAX_TOTAL_CHARS) {
            $remaining = KB_MAX_TOTAL_CHARS - $total;
            if ($remaining < 200) break;
            $block = mb_substr($block, 0, $remaining) . "\n[…truncated…]";
            $parts[]  = $block;
            $titles[] = $title;
            break;
        }
        $parts[]  = $block;
        $titles[] = $title;
        $total += mb_strlen($block);
    }
    return [
        'text'         => implode("\n\n---\n\n", $parts),
        'titles'       => $titles,
        'char_count'   => $total,
        'article_ids'  => array_map(fn($r) => (int)$r['id'], $rows),
        'qa_count'     => $qaCount,
    ];
}

// =====================================================================
// URL scraping — fetch a URL, extract clean text, save/refresh a KB
// article. Uses curl + a simple HTML-to-text pass (strip scripts /
// styles / nav / footer, then strip_tags). Handles PDFs at URLs too.
// =====================================================================

/**
 * Fetch a URL and extract the primary text body. Returns
 *   ['ok' => bool, 'text' => string, 'title' => string, 'mime' => string, 'error' => string]
 */
function kb_fetch_url_text(string $url): array
{
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => 'Only http(s) URLs are supported.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'AiServe-KB-Bot/1.0 (+https://inbox.aiserve.my)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime   = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $err    = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        return ['ok' => false, 'error' => 'Fetch failed (HTTP ' . $status . '): ' . $err];
    }
    if (mb_strlen($body) > KB_MAX_BYTES_PER_FILE) {
        $body = mb_substr($body, 0, KB_MAX_BYTES_PER_FILE);
    }

    // PDF at URL: pipe through the same pdftotext path we use for uploads.
    if (str_contains($mime, 'application/pdf')) {
        $tmp = tempnam(sys_get_temp_dir(), 'kburl');
        file_put_contents($tmp, $body);
        $ext = kb_extract_text($tmp, 'application/pdf', basename($url));
        @unlink($tmp);
        if (!$ext['ok']) return $ext;
        return ['ok' => true, 'text' => (string)$ext['text'],
                'title' => basename(parse_url($url, PHP_URL_PATH) ?: 'Untitled PDF'),
                'mime'  => 'application/pdf'];
    }

    // HTML: strip scripts/styles/nav/footer/aside/header first so we
    // keep the meaningful body text and drop chrome.
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    $clean = preg_replace('#<(script|style|nav|footer|aside|header|noscript|form|svg)[^>]*>.*?</\\1>#is', ' ', $body);
    $clean = strip_tags((string)$clean);
    $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);
    if (mb_strlen($clean) > KB_MAX_CHARS_PER_ITEM) {
        $clean = mb_substr($clean, 0, KB_MAX_CHARS_PER_ITEM) . '…';
    }
    if ($clean === '') return ['ok' => false, 'error' => 'Fetched but no readable text — the page might be JS-rendered.'];

    return [
        'ok'    => true,
        'text'  => $clean,
        'title' => $title !== '' ? $title : (parse_url($url, PHP_URL_HOST) ?: 'Untitled'),
        'mime'  => $mime ?: 'text/html',
    ];
}

/**
 * Fetch a URL and save/update as a KB article. If an existing article
 * with the same source_url exists, refresh it. Otherwise create a new
 * one. Returns the article id or null on failure.
 */
function kb_upsert_from_url(int $companyId, int $userId, string $url, ?string $overrideTitle = null): ?int
{
    $r = kb_fetch_url_text($url);
    if (!$r['ok']) return null;
    $title = trim((string)($overrideTitle ?? $r['title']));
    if ($title === '') $title = parse_url($url, PHP_URL_HOST) ?: 'Untitled';
    $text  = (string)$r['text'];
    $chars = mb_strlen($text);
    try {
        $db = aiserve_db();
        $existing = $db->prepare('SELECT id FROM knowledge_base WHERE company_id = ? AND source_url = ? LIMIT 1');
        $existing->execute([$companyId, $url]);
        $id = (int)($existing->fetchColumn() ?: 0);
        if ($id > 0) {
            $db->prepare(
                'UPDATE knowledge_base
                 SET title = ?, content_text = ?, content_chars = ?, status = "active",
                     source_last_fetched_at = NOW()
                 WHERE id = ?'
            )->execute([$title, $text, $chars, $id]);
            return $id;
        }
        $ins = $db->prepare(
            'INSERT INTO knowledge_base
                (company_id, title, source_filename, mime_type, content_text, content_chars,
                 status, created_by, source_url, source_last_fetched_at)
             VALUES (?, ?, NULL, ?, ?, ?, "active", ?, ?, NOW())'
        );
        $ins->execute([$companyId, $title, (string)$r['mime'], $text, $chars, $userId, $url]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('[AiServe kb_upsert_from_url] ' . $e->getMessage());
        return null;
    }
}
