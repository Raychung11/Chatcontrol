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
    $stmt = aiserve_db()->prepare(
        'SELECT id, title, content_text, content_chars
         FROM knowledge_base
         WHERE company_id = ? AND status = "active"
         ORDER BY id ASC'
    );
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return null;

    $parts = [];
    $total = 0;
    $titles = [];
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
    ];
}
