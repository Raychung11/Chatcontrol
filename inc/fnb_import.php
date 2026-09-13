<?php
/**
 * F&B menu bulk importer — CSV or XLSX → categories, products, variants, addons.
 *
 * File shape (one row per product, header row):
 *   category, name, description, price, variants, addons, status
 *
 * variants: 'Group:Opt1=+0*|Opt2=+3.00' with * marking default,
 *           multiple groups separated by ';'
 * addons:   'Extra egg=+1.50|Extra sauce=+1.00'
 *
 * Behaviour: upsert-and-replace. Products are matched on
 * (company_id + name):
 *   - New name → create product + variants + addons
 *   - Existing name → update fields AND delete-and-recreate its
 *     variants + addons (so the row is the source of truth)
 * Categories are upserted on (company_id + name).
 * Nothing is deleted for products NOT in the file — additive only.
 *
 * Reuses xlsx_reader + the same chrome-skip header detection
 * pattern as contact_import for consistency.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/xlsx_reader.php';

/**
 * @return array{
 *   ok: bool, error?: string,
 *   created:int, updated:int, skipped:int,
 *   categories_created:int, variants_created:int, addons_created:int,
 *   errors: array<int, array{line:int, reason:string}>
 * }
 */
function fnb_import_run(int $companyId, array $file): array
{
    $blank = ['created' => 0, 'updated' => 0, 'skipped' => 0,
              'categories_created' => 0, 'variants_created' => 0,
              'addons_created' => 0, 'errors' => []];

    if (empty($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded (or upload failed).'] + $blank;
    }
    if ((int)$file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too big (max 5 MB).'] + $blank;
    }

    // Route on extension / magic bytes.
    $ext    = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $fh     = @fopen($file['tmp_name'], 'rb');
    $sig    = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);
    $isXlsx = $ext === 'xlsx' || $sig === "PK\x03\x04";

    if ($isXlsx) {
        $x = xlsx_read_rows($file['tmp_name']);
        if (!$x['ok']) return ['ok' => false, 'error' => 'Could not read xlsx: ' . (string)$x['error']] + $blank;
        $rows = $x['rows'];
    } else {
        $rows = fnb_import_read_csv($file['tmp_name']);
        if ($rows === null) return ['ok' => false, 'error' => 'CSV appears to be empty or unreadable.'] + $blank;
    }
    if (!$rows) return ['ok' => false, 'error' => 'File contains no rows.'] + $blank;

    // Header detection — first row with 'name' + 'price' alias hit.
    $headerIdx = null; $col = null;
    for ($i = 0; $i < min(20, count($rows)); $i++) {
        $cells = array_map(fn($v) => trim((string)$v), $rows[$i]);
        if (count(array_filter($cells, fn($v) => $v !== '')) < 2) continue;
        $m = fnb_import_map_columns($cells);
        if ($m !== null) { $headerIdx = $i; $col = $m; break; }
    }
    if ($headerIdx === null) {
        return ['ok' => false, 'error' =>
            'Could not find a header row with a "name" AND "price" column. '
          . 'Download the template from /admin/fnb_menu_import.php for the exact format.'] + $blank;
    }
    $lineOffset = $headerIdx + 1;
    $rows = array_slice($rows, $headerIdx + 1);

    $db = aiserve_db();

    // Preload existing categories + products by name for cheap lookup.
    $cats = [];   // lower(name) → id
    $s = $db->prepare('SELECT id, name FROM fnb_categories WHERE company_id = ?');
    $s->execute([$companyId]);
    foreach ($s->fetchAll() as $r) $cats[mb_strtolower((string)$r['name'])] = (int)$r['id'];

    $prods = [];  // lower(name) → id
    $s = $db->prepare('SELECT id, name FROM fnb_products WHERE company_id = ?');
    $s->execute([$companyId]);
    foreach ($s->fetchAll() as $r) $prods[mb_strtolower((string)$r['name'])] = (int)$r['id'];

    $created = 0; $updated = 0; $skipped = 0;
    $catNew = 0; $varNew = 0; $addNew = 0;
    $errors = [];
    $catSortNext  = 100;   // for new categories, spaced by 10
    $prodSortNext = 100;   // for new products, spaced by 10

    // Prepared statements — reused per row.
    $insCat  = $db->prepare(
        'INSERT INTO fnb_categories (company_id, name, sort_order, status)
         VALUES (?, ?, ?, "active")'
    );
    $insProd = $db->prepare(
        'INSERT INTO fnb_products
            (company_id, category_id, name, description, price, sort_order, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $updProd = $db->prepare(
        'UPDATE fnb_products
         SET category_id = ?, description = ?, price = ?, status = ?
         WHERE id = ? AND company_id = ?'
    );
    $delVar  = $db->prepare('DELETE FROM fnb_variants WHERE product_id = ?');
    $delAdd  = $db->prepare('DELETE FROM fnb_addons   WHERE product_id = ?');
    $insVar  = $db->prepare(
        'INSERT INTO fnb_variants
            (product_id, group_name, name, price_delta, sort_order, is_default)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insAdd  = $db->prepare(
        'INSERT INTO fnb_addons (product_id, name, price_delta, sort_order)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($rows as $rowIdx => $row) {
        $lineNum = $lineOffset + $rowIdx + 1;
        if (!is_array($row) || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

        $catName  = mb_substr(trim((string)($row[$col['category']]    ?? '')), 0, 120);
        $name     = mb_substr(trim((string)($row[$col['name']]        ?? '')), 0, 150);
        $desc     = trim((string)($row[$col['description']]           ?? ''));
        $priceRaw = trim((string)($row[$col['price']]                 ?? ''));
        $vRaw     = $col['variants'] >= 0 ? trim((string)($row[$col['variants']] ?? '')) : '';
        $aRaw     = $col['addons']   >= 0 ? trim((string)($row[$col['addons']]   ?? '')) : '';
        $status   = $col['status']   >= 0 ? mb_strtolower(trim((string)($row[$col['status']] ?? ''))) : 'active';
        if ($status !== 'inactive') $status = 'active';

        if ($name === '') {
            $errors[] = ['line' => $lineNum, 'reason' => 'Missing name.'];
            continue;
        }
        // Malaysia rarely has centidecimals — strip currency prefixes + trim.
        $price = (float)preg_replace('/[^\d.]/', '', $priceRaw);
        if ($price < 0 || $priceRaw === '') {
            $errors[] = ['line' => $lineNum, 'reason' => 'Missing or invalid price for "' . $name . '".'];
            continue;
        }

        try {
            $db->beginTransaction();

            // Category — resolve or create.
            $catId = null;
            if ($catName !== '') {
                $lc = mb_strtolower($catName);
                if (isset($cats[$lc])) {
                    $catId = $cats[$lc];
                } else {
                    $insCat->execute([$companyId, $catName, $catSortNext]);
                    $catId = (int)$db->lastInsertId();
                    $cats[$lc] = $catId;
                    $catNew++;
                    $catSortNext += 10;
                }
            }

            // Product — upsert.
            $lcName = mb_strtolower($name);
            $prodId = $prods[$lcName] ?? null;
            if ($prodId) {
                $updProd->execute([$catId, $desc ?: null, $price, $status, $prodId, $companyId]);
                $updated++;
            } else {
                $insProd->execute([$companyId, $catId, $name, $desc ?: null, $price, $prodSortNext, $status]);
                $prodId = (int)$db->lastInsertId();
                $prods[$lcName] = $prodId;
                $created++;
                $prodSortNext += 10;
            }

            // Variants + addons — always wipe-and-rebuild from the row so
            // the file stays the source of truth per product.
            $delVar->execute([$prodId]);
            $delAdd->execute([$prodId]);
            if ($vRaw !== '') {
                $varNew += fnb_import_apply_variants($insVar, $prodId, $vRaw);
            }
            if ($aRaw !== '') {
                $addNew += fnb_import_apply_addons($insAdd, $prodId, $aRaw);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = ['line' => $lineNum, 'reason' => 'DB error: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    log_activity(
        $companyId, 0, 'fnb_menu_imported', null, null,
        'created=' . $created . ' updated=' . $updated
      . ' cats=' . $catNew . ' variants=' . $varNew . ' addons=' . $addNew
      . ' errors=' . count($errors)
    );

    return [
        'ok' => true,
        'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
        'categories_created' => $catNew,
        'variants_created'   => $varNew,
        'addons_created'     => $addNew,
        'errors' => $errors,
    ];
}

/**
 * Parse a variants string into rows and INSERT them.
 * Format: 'Group:Opt1=+0*|Opt2=+3.00;Group2:A=0*|B=+1.00'
 * Returns number of variant rows inserted.
 */
function fnb_import_apply_variants(PDOStatement $insVar, int $productId, string $raw): int
{
    $count = 0;
    $groups = array_filter(array_map('trim', explode(';', $raw)));
    foreach ($groups as $group) {
        // Split "Group:Opt1=+0*|Opt2=+3.00" → groupName + options list
        $colon = mb_strpos($group, ':');
        if ($colon === false) continue;
        $groupName = trim(mb_substr($group, 0, $colon));
        $optsPart  = trim(mb_substr($group, $colon + 1));
        if ($groupName === '' || $optsPart === '') continue;

        $sort = 100;
        foreach (explode('|', $optsPart) as $opt) {
            $opt = trim($opt);
            if ($opt === '') continue;

            // Check for trailing * (default marker) — strip before parsing.
            $isDefault = str_ends_with($opt, '*');
            if ($isDefault) $opt = rtrim(rtrim($opt, '*'), ' ');

            // Split at '=' — 'Regular' or 'Large=+3.00'.
            $eq = mb_strpos($opt, '=');
            if ($eq === false) {
                $optName = trim($opt);
                $delta = 0.00;
            } else {
                $optName = trim(mb_substr($opt, 0, $eq));
                $deltaRaw = trim(mb_substr($opt, $eq + 1));
                $delta = (float)preg_replace('/[^\-\d.]/', '', $deltaRaw);
            }
            if ($optName === '') continue;

            $insVar->execute([
                $productId,
                mb_substr($groupName, 0, 80),
                mb_substr($optName, 0, 120),
                $delta,
                $sort,
                $isDefault ? 1 : 0,
            ]);
            $sort += 10;
            $count++;
        }
    }
    return $count;
}

/**
 * Parse an addons string 'Addon=+delta|Addon=+delta' into rows.
 * Returns number of addon rows inserted.
 */
function fnb_import_apply_addons(PDOStatement $insAdd, int $productId, string $raw): int
{
    $count = 0;
    $sort = 100;
    foreach (explode('|', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $eq = mb_strpos($entry, '=');
        if ($eq === false) {
            $name = trim($entry);
            $delta = 0.00;
        } else {
            $name = trim(mb_substr($entry, 0, $eq));
            $deltaRaw = trim(mb_substr($entry, $eq + 1));
            $delta = (float)preg_replace('/[^\-\d.]/', '', $deltaRaw);
        }
        if ($name === '') continue;
        $insAdd->execute([$productId, mb_substr($name, 0, 120), $delta, $sort]);
        $sort += 10;
        $count++;
    }
    return $count;
}

/**
 * Header-row detector — returns column-index map, or NULL if this row
 * doesn't have BOTH name and price aliases (chrome / data row).
 *
 * @return array{category:int, name:int, description:int, price:int, variants:int, addons:int, status:int}|null
 */
function fnb_import_map_columns(array $headerCells): ?array
{
    static $aliases = null;
    if ($aliases === null) {
        $aliases = [
            'category'    => ['category','cat','group','section'],
            'name'        => ['name','productname','itemname','item','product'],
            'description' => ['description','desc','details','notes'],
            'price'       => ['price','baseprice','cost','amount'],
            'variants'    => ['variants','variant','options','choices','size'],
            'addons'      => ['addons','addon','extras','extra','sides'],
            'status'      => ['status','active','enabled'],
        ];
    }

    $map = array_fill_keys(array_keys($aliases), -1);
    foreach ($headerCells as $i => $h) {
        $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', (string)$h)));
        if ($norm === '') continue;
        foreach ($aliases as $field => $words) {
            if ($map[$field] === -1 && in_array($norm, $words, true)) {
                $map[$field] = $i;
                break;
            }
        }
    }
    // Header only qualifies if BOTH name and price were found.
    if ($map['name'] === -1 || $map['price'] === -1) return null;
    return $map;
}

/**
 * Same as contact_import_read_csv — small local copy since we don't
 * want /contacts.php to pull in fnb helpers on unrelated pages.
 */
function fnb_import_read_csv(string $localPath): ?array
{
    $fh = @fopen($localPath, 'r');
    if (!$fh) return null;
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return null; }
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) $first = substr($first, 3);
    $counts = ['comma' => substr_count($first, ','),
               'semi'  => substr_count($first, ';'),
               'tab'   => substr_count($first, "\t")];
    arsort($counts);
    $delim = ['comma' => ',', 'semi' => ';', 'tab' => "\t"][array_key_first($counts)];
    $rows  = [str_getcsv(rtrim($first, "\r\n"), $delim)];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) $rows[] = $r;
    fclose($fh);
    return $rows;
}
