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
 * Claude Vision — OCR + describe an uploaded image, return extracted
 * text suitable for a KB article. Uses the workspace's own Anthropic
 * key + costs are logged under feature = 'kb_image_extract'.
 *
 * Returns ['ok' => bool, 'text' => string, 'title' => string, 'error' => string].
 */
function kb_extract_from_image(int $companyId, string $imgBytes, string $mime): array
{
    require_once __DIR__ . '/whatsapp_api.php';
    require_once __DIR__ . '/ai_api.php';
    require_once __DIR__ . '/ai_billing.php';

    $company = load_company_settings($companyId);
    if (!$company || empty($company['ai_enabled'])) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') return ['ok' => false, 'error' => 'Anthropic key missing.'];

    // Anthropic wants base64 for image content blocks.
    $b64 = base64_encode($imgBytes);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
        return ['ok' => false, 'error' => 'Unsupported image MIME.'];
    }

    // Vision needs a real model — a Haiku default might not support
    // images. Use Sonnet by default for extract, but respect a per-
    // feature override if the operator set one.
    $model = ai_model_for_feature($company, 'kb_image_extract');
    if (!$model || str_contains($model, 'haiku')) {
        $model = 'claude-sonnet-5';
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => 1200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $mime, 'data' => $b64,
                ]],
                ['type' => 'text', 'text' =>
                    "Extract every readable piece of text from this image (menu items, prices, "
                  . "opening hours, policies, contact info — whatever's there). Format as a clean "
                  . "markdown article suitable for a customer-service FAQ. Preserve prices, phone "
                  . "numbers, times exactly. If the image has multiple sections, use ## headings. "
                  . "Start your reply with a single line: 'TITLE: <short 4-8 word title>' then a "
                  . "blank line, then the article body. No preamble."],
            ],
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $raw  = trim((string)($data['content'][0]['text'] ?? ''));
    if ($raw === '') return ['ok' => false, 'error' => 'Vision returned empty response.'];

    if (!empty($data['usage'])) {
        ai_log_usage($companyId, null, 'kb_image_extract', $data['usage'], (string)($data['model'] ?? $model));
    }

    // Parse "TITLE: …" line off the top.
    $title = '';
    $body  = $raw;
    if (preg_match('/^TITLE:\s*(.+?)\r?\n\r?\n(.+)$/s', $raw, $m)) {
        $title = trim($m[1]);
        $body  = trim($m[2]);
    }
    if (mb_strlen($body) > KB_MAX_CHARS_PER_ITEM) {
        $body = mb_substr($body, 0, KB_MAX_CHARS_PER_ITEM) . '…';
    }
    return ['ok' => true, 'title' => $title, 'text' => $body];
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

// =====================================================================
// Google Sheets sync for Q&A pairs.
//
// Design: no OAuth. Operator publishes a Google Sheet as CSV via
// "File → Share → Publish to web → CSV" and pastes the URL. Every sync
// we fetch the CSV, parse (question, answer[, tag]) rows, wipe every
// kb_qa_pairs row tagged with THIS sheet's URL, and reinsert. Rows
// added by hand (source_sheet_url IS NULL) are never touched.
//
// Accepts three URL shapes so the operator can paste whatever they have:
//   - .../pub?output=csv                (the "publish as CSV" URL)
//   - .../edit#gid=0                    (the normal edit URL — we
//                                         rewrite to /export?format=csv)
//   - .../export?format=csv&gid=0       (already-formed export URL)
// =====================================================================

/**
 * Rewrite a Google Sheets URL into a CSV-fetchable form. Returns the URL
 * unchanged if it doesn't match a known pattern.
 */
function kb_qa_sheet_normalize_url(string $url): string
{
    $url = trim($url);
    // /edit → /export?format=csv (preserve gid if present in the fragment)
    if (preg_match('#^(https?://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+)/edit(?:#gid=(\d+))?#i', $url, $m)) {
        $base = $m[1] . '/export?format=csv';
        return isset($m[2]) && $m[2] !== '' ? $base . '&gid=' . $m[2] : $base;
    }
    return $url;
}

/**
 * Fetch a Google Sheet CSV URL and return the raw CSV text.
 * @return array{ok:bool, csv?:string, error?:string}
 */
function kb_qa_sheet_fetch_csv(string $url): array
{
    $url = kb_qa_sheet_normalize_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => 'Sheet URL is not a valid http(s) URL.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'AiServe-KB-Bot/1.0 (+https://inbox.aiserve.my)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        return ['ok' => false, 'error' => 'Fetch failed (HTTP ' . $status . '): ' . ($err ?: 'sheet may not be published to the web')];
    }
    if (mb_strlen($body) > KB_MAX_BYTES_PER_FILE) {
        return ['ok' => false, 'error' => 'Sheet is over the 5 MB fetch cap — split into smaller sheets.'];
    }
    // A published sheet returns text/csv; an unpublished / private one
    // returns text/html with a login page. Detect the common failure so
    // the error is friendlier than a random parse.
    if (str_starts_with(ltrim($body), '<')) {
        return ['ok' => false, 'error' => 'Sheet is not published as CSV. In Google Sheets: File → Share → Publish to web → choose the tab, format CSV, then click Publish.'];
    }
    return ['ok' => true, 'csv' => (string)$body];
}

/**
 * Parse CSV text into [{question, answer, tag}, …]. First row is treated
 * as a header when it contains "question" (case-insensitive); otherwise
 * every row is a data row. Column order tolerated: question|answer|tag,
 * a|q|tag, or plain 2-3 columns.
 *
 * @return array<int, array{question:string, answer:string, tag:?string}>
 */
function kb_qa_sheet_parse_csv(string $csv): array
{
    $csv  = str_replace(["\r\n", "\r"], "\n", $csv);
    $rows = [];
    $fh   = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($row === [null] || $row === false) continue;
        $rows[] = $row;
    }
    fclose($fh);
    if (!$rows) return [];

    // Header detection + column mapping.
    $qIdx = 0; $aIdx = 1; $tIdx = 2;
    $first = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $hasHeader = false;
    foreach ($first as $i => $h) {
        if (in_array($h, ['question', 'q', 'ask'], true)) { $qIdx = $i; $hasHeader = true; }
        elseif (in_array($h, ['answer', 'a', 'reply', 'response'], true)) { $aIdx = $i; $hasHeader = true; }
        elseif (in_array($h, ['tag', 'category', 'label'], true)) { $tIdx = $i; $hasHeader = true; }
    }
    if ($hasHeader) array_shift($rows);

    $out = [];
    foreach ($rows as $r) {
        $q = trim((string)($r[$qIdx] ?? ''));
        $a = trim((string)($r[$aIdx] ?? ''));
        if ($q === '' || $a === '') continue;
        if (mb_strlen($q) > 500)  $q = mb_substr($q, 0, 500);
        // kb_qa_pairs.answer is TEXT — cap at a generous 8 KB for safety.
        if (mb_strlen($a) > 8000) $a = mb_substr($a, 0, 8000);
        $tag = trim((string)($r[$tIdx] ?? ''));
        if ($tag !== '' && mb_strlen($tag) > 60) $tag = mb_substr($tag, 0, 60);
        $out[] = ['question' => $q, 'answer' => $a, 'tag' => $tag !== '' ? $tag : null];
    }
    return $out;
}

/**
 * Sync one configured sheet — fetch, parse, wipe rows tagged with this
 * sheet's URL, reinsert fresh. Updates last_synced_at / last_synced_count
 * / last_error on the kb_qa_sheets row.
 *
 * @return array{ok:bool, count:int, error?:string}
 */
function kb_sync_qa_sheet(int $sheetId): array
{
    $db = aiserve_db();
    $s  = $db->prepare('SELECT id, company_id, sheet_url FROM kb_qa_sheets WHERE id = ? LIMIT 1');
    $s->execute([$sheetId]);
    $sheet = $s->fetch();
    if (!$sheet) return ['ok' => false, 'count' => 0, 'error' => 'Sheet not found.'];

    $companyId = (int)$sheet['company_id'];
    $url       = (string)$sheet['sheet_url'];

    $fetch = kb_qa_sheet_fetch_csv($url);
    if (!$fetch['ok']) {
        $db->prepare('UPDATE kb_qa_sheets SET last_error = ?, last_synced_at = NOW() WHERE id = ?')
           ->execute([mb_substr((string)$fetch['error'], 0, 500), $sheetId]);
        return ['ok' => false, 'count' => 0, 'error' => (string)$fetch['error']];
    }
    $pairs = kb_qa_sheet_parse_csv((string)$fetch['csv']);
    if (!$pairs) {
        $err = 'CSV had no question/answer rows. Row 1 should be "question,answer[,tag]" or a header line with those column names.';
        $db->prepare('UPDATE kb_qa_sheets SET last_error = ?, last_synced_at = NOW(), last_synced_count = 0 WHERE id = ?')
           ->execute([$err, $sheetId]);
        return ['ok' => false, 'count' => 0, 'error' => $err];
    }

    try {
        $db->beginTransaction();
        // Wipe only rows tagged with THIS sheet URL — hand-added ones
        // (source_sheet_url IS NULL) are safe.
        $db->prepare('DELETE FROM kb_qa_pairs WHERE company_id = ? AND source_sheet_url = ?')
           ->execute([$companyId, $url]);

        $ins = $db->prepare(
            'INSERT INTO kb_qa_pairs
                (company_id, question, answer, tag, status, source_sheet_url)
             VALUES (?, ?, ?, ?, "active", ?)'
        );
        foreach ($pairs as $p) {
            $ins->execute([$companyId, $p['question'], $p['answer'], $p['tag'], $url]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = 'DB error during sync: ' . $e->getMessage();
        $db->prepare('UPDATE kb_qa_sheets SET last_error = ?, last_synced_at = NOW() WHERE id = ?')
           ->execute([mb_substr($err, 0, 500), $sheetId]);
        return ['ok' => false, 'count' => 0, 'error' => $err];
    }

    $db->prepare(
        'UPDATE kb_qa_sheets
         SET last_error = NULL, last_synced_at = NOW(), last_synced_count = ?
         WHERE id = ?'
    )->execute([count($pairs), $sheetId]);

    return ['ok' => true, 'count' => count($pairs)];
}
