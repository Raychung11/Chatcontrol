<?php
/**
 * inc/product_catalog.php — structured product catalog + AI grounding.
 *
 * The AI reply drafter in inc/ai_api.php calls
 * products_relevant_for_message() with the customer's message text; we
 * return the most relevant catalog rows so the model can cite real
 * prices / stock / URLs rather than paraphrase from a PDF blurb.
 *
 * Search strategy:
 *   - MySQL FULLTEXT NATURAL LANGUAGE MODE across (name, description,
 *     category). Fast enough for < ~10k products per workspace, needs
 *     no external index, no embeddings, no cron. Good default.
 *   - We STRIP obvious stopwords ("what is", "how much", "do you have",
 *     etc.) before matching so short questions with lots of filler
 *     still hit relevant rows.
 *   - We fall back to a LIKE search when the FULLTEXT match returns
 *     nothing — protects short queries below MySQL's ft_min_word_len.
 *
 * When to upgrade: past a few thousand products with genuine ambiguity
 * ("show me something in maroon under RM 200"), switch to a small
 * embedding index. That's a separate migration.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Search this workspace's active products for what looks most relevant
 * to $message. Returns 0-$limit rows sorted by relevance.
 */
function products_relevant_for_message(int $companyId, string $message, int $limit = 6): array
{
    if ($companyId <= 0 || trim($message) === '') return [];
    $db = aiserve_db();

    $needle = products_extract_search_terms($message);
    if ($needle === '') return [];

    try {
        // FULLTEXT pass first. NATURAL LANGUAGE MODE tolerates typos
        // and does relevance-ranking for us via the built-in score.
        $stmt = $db->prepare(
            "SELECT id, sku, name, category, price, currency, description,
                    image_url, product_url, in_stock,
                    MATCH(name, description, category) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
             FROM products
             WHERE company_id = ? AND status = 'active'
               AND MATCH(name, description, category) AGAINST (? IN NATURAL LANGUAGE MODE)
             ORDER BY relevance DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute([$needle, $companyId, $needle]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    } catch (Throwable $e) {
        error_log('[AiServe products FT] ' . $e->getMessage());
    }

    // Fallback — MySQL FULLTEXT ignores words shorter than
    // ft_min_word_len (default 3). Very short queries fall to LIKE.
    try {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $needle) . '%';
        $stmt = $db->prepare(
            "SELECT id, sku, name, category, price, currency, description,
                    image_url, product_url, in_stock
             FROM products
             WHERE company_id = ? AND status = 'active'
               AND (name LIKE ? OR sku LIKE ? OR category LIKE ?)
             ORDER BY updated_at DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute([$companyId, $like, $like, $like]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[AiServe products LIKE] ' . $e->getMessage());
    }
    return [];
}

/**
 * Reduce a customer's message to the words that actually matter for
 * a product search. Drops the common English + Malay filler that
 * shows up around a real query.
 */
function products_extract_search_terms(string $message): string
{
    $t = mb_strtolower($message);
    // Kill URLs, phone numbers, punctuation — anything a product name
    // wouldn't contain.
    $t = preg_replace('#https?://\S+#', ' ', $t);
    $t = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $t);

    // Drop obvious stopwords (EN + MS). Not exhaustive on purpose —
    // over-aggressive filtering can strip real product terms.
    $stop = [
        'i','you','the','a','an','is','are','was','were','do','does','did',
        'have','has','had','can','could','will','would','should','shall',
        'may','might','be','been','being','to','of','in','on','at','for',
        'and','or','but','if','then','so','as','with','by','from','about',
        'what','which','who','how','when','where','why','this','that','these','those',
        'me','my','your','yours','our','ours','their','theirs','it','its',
        // Malay filler
        'apa','macam','mana','berapa','harga','ada','ke','tak','kena','jual',
        'boleh','saya','anda','kamu','awak','yang','dan','atau','tapi',
        'bagaimana','kalau','pun','juga','lagi','perlu','nak','mahu','hendak',
        'itu','ini','sana','sini','tu','ni',
    ];
    $words = preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    $keep  = [];
    foreach ($words as $w) {
        if (in_array($w, $stop, true)) continue;
        if (mb_strlen($w) < 2) continue;
        $keep[] = $w;
    }
    return trim(implode(' ', $keep));
}

/**
 * Render a compact JSON block the AI drafter injects into the system
 * prompt. Keeps token cost low by only including populated fields.
 */
function products_render_for_ai_prompt(array $rows): string
{
    if (!$rows) return '';
    $out = [];
    foreach ($rows as $r) {
        $entry = ['name' => (string)$r['name']];
        if (!empty($r['sku']))         $entry['sku']         = (string)$r['sku'];
        if (!empty($r['category']))    $entry['category']    = (string)$r['category'];
        if ($r['price'] !== null)      $entry['price']       = (float)$r['price'];
        if (!empty($r['currency']))    $entry['currency']    = (string)$r['currency'];
        if (isset($r['in_stock']))     $entry['in_stock']    = (int)$r['in_stock'] === 1;
        if (!empty($r['description'])) $entry['description'] = mb_substr((string)$r['description'], 0, 500);
        if (!empty($r['image_url']))   $entry['image_url']   = (string)$r['image_url'];
        if (!empty($r['product_url'])) $entry['product_url'] = (string)$r['product_url'];
        $out[] = $entry;
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// -----------------------------------------------------------------
// CSV import
// -----------------------------------------------------------------

/**
 * Parse a CSV upload into an array of product rows.
 *
 * Expected headers (case-insensitive, order-agnostic):
 *   name          — REQUIRED
 *   sku
 *   category
 *   price
 *   currency
 *   description
 *   image_url
 *   product_url
 *   in_stock      — 1/0/yes/no/true/false/y/n
 *
 * Unknown columns are ignored so a workspace can upload an existing
 * catalog CSV without having to hand-edit it.
 *
 * Returns ['ok' => bool, 'error' => ?, 'rows' => array<int, array>].
 */
function products_import_parse_csv(string $localPath): array
{
    if (!is_file($localPath)) {
        return ['ok' => false, 'error' => 'Upload file missing.', 'rows' => []];
    }
    $fh = @fopen($localPath, 'r');
    if (!$fh) return ['ok' => false, 'error' => 'Could not open upload.', 'rows' => []];

    // Auto-detect delimiter. Excel exports vary between "," and ";"
    // depending on the operator's locale.
    $peek = fread($fh, 4096);
    rewind($fh);
    $delim = (substr_count($peek, ';') > substr_count($peek, ',')) ? ';' : ',';

    $headerRaw = fgetcsv($fh, 0, $delim);
    if (!$headerRaw) return ['ok' => false, 'error' => 'Empty CSV.', 'rows' => []];
    $header = array_map(fn($c) => strtolower(trim((string)$c)), $headerRaw);

    // Column indexes we care about.
    $idx = [];
    foreach (['name','sku','category','price','currency','description','image_url','product_url','in_stock'] as $col) {
        $i = array_search($col, $header, true);
        if ($i !== false) $idx[$col] = $i;
    }
    if (!isset($idx['name'])) {
        fclose($fh);
        return ['ok' => false, 'error' => 'Missing required "name" column.', 'rows' => []];
    }

    $rows = [];
    $line = 1;
    while (($cols = fgetcsv($fh, 0, $delim)) !== false) {
        $line++;
        if (!$cols || count($cols) === 1 && trim((string)$cols[0]) === '') continue;

        $r = [];
        foreach ($idx as $col => $i) {
            $v = trim((string)($cols[$i] ?? ''));
            $r[$col] = $v === '' ? null : $v;
        }
        if (empty($r['name'])) continue; // skip nameless rows silently

        // Type coercion
        if (isset($r['price']) && $r['price'] !== null) {
            $r['price'] = (float)preg_replace('/[^\d\.\-]/', '', $r['price']);
        }
        if (isset($r['in_stock']) && $r['in_stock'] !== null) {
            $v = mb_strtolower((string)$r['in_stock']);
            $r['in_stock'] = (int)in_array($v, ['1','yes','y','true','t','ada','stock'], true);
        }
        $rows[] = $r;
    }
    fclose($fh);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Upsert a batch of products for a workspace. Rows with a matching
 * (company_id, sku) update; new SKUs insert. Rows without a SKU always
 * insert as new. Returns [inserted, updated, skipped_errors].
 */
function products_import_upsert(int $companyId, int $userId, array $rows): array
{
    $db = aiserve_db();
    $inserted = 0; $updated = 0; $errors = 0;

    foreach ($rows as $r) {
        try {
            $sku = $r['sku'] ?? null;
            if ($sku !== null) {
                $chk = $db->prepare('SELECT id FROM products WHERE company_id = ? AND sku = ? LIMIT 1');
                $chk->execute([$companyId, $sku]);
                $existingId = (int)$chk->fetchColumn();
            } else {
                $existingId = 0;
            }

            if ($existingId > 0) {
                $upd = $db->prepare(
                    'UPDATE products
                     SET name=?, category=?, price=?, currency=?, description=?,
                         image_url=?, product_url=?, in_stock=?, status="active"
                     WHERE id=?'
                );
                $upd->execute([
                    (string)$r['name'],
                    $r['category'] ?? null,
                    $r['price']    ?? null,
                    $r['currency'] ?? null,
                    $r['description'] ?? null,
                    $r['image_url']   ?? null,
                    $r['product_url'] ?? null,
                    isset($r['in_stock']) ? (int)$r['in_stock'] : 1,
                    $existingId,
                ]);
                $updated++;
            } else {
                $ins = $db->prepare(
                    'INSERT INTO products
                        (company_id, sku, name, category, price, currency, description,
                         image_url, product_url, in_stock, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $companyId,
                    $sku,
                    (string)$r['name'],
                    $r['category'] ?? null,
                    $r['price']    ?? null,
                    $r['currency'] ?? null,
                    $r['description'] ?? null,
                    $r['image_url']   ?? null,
                    $r['product_url'] ?? null,
                    isset($r['in_stock']) ? (int)$r['in_stock'] : 1,
                    $userId ?: null,
                ]);
                $inserted++;
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('[AiServe products import] row skipped: ' . $e->getMessage());
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
}
