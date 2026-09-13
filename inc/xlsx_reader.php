<?php
/**
 * Pure-PHP xlsx reader — no PhpSpreadsheet dep.
 *
 * An xlsx file is a zip of XMLs. We pull the first worksheet plus the
 * shared-strings table and yield rows as string arrays, one array per
 * row, in column order (A, B, C, …). Missing cells in the middle of a
 * row are filled with empty strings so downstream code can index by
 * position without null checks.
 *
 * Deliberately narrow: no formulas, no styles, no dates. This module
 * exists to feed contact_import.php the same shape it already gets
 * from fgetcsv — a flat 2D string array — so the existing header
 * detection + row-processing logic works unchanged.
 *
 * @return array{ok:bool, rows?:array<int,array<int,string>>, error?:string}
 */
function xlsx_read_rows(string $localPath, int $maxRows = 100000): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP ZipArchive extension not available — install php-zip.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($localPath) !== true) {
        return ['ok' => false, 'error' => 'Not a valid xlsx (could not open as zip).'];
    }

    // Shared strings — the actual text content lives here for any cell
    // whose type is "s" (the cell's <v> is the index into this table).
    // Files without any inline strings can skip the file entirely.
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($ssXml) && $ssXml !== '') {
        $shared = _xlsx_parse_shared_strings($ssXml);
    }

    // Try worksheet 1. Files with only one sheet always name it sheet1.xml.
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        return ['ok' => false, 'error' => 'Could not read the first worksheet.'];
    }

    $rows = _xlsx_parse_sheet($sheetXml, $shared, $maxRows);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * sharedStrings.xml has <si> elements — each is one string. Strings
 * with rich formatting have multiple <t> children; we concatenate them.
 */
function _xlsx_parse_shared_strings(string $xml): array
{
    $out = [];
    $doc = @simplexml_load_string($xml);
    if (!$doc) return $out;
    // Strip XML namespaces so <si>/<t>/<r> lookups don't need xpath prefixes.
    $doc = simplexml_load_string(str_replace(
        [' xmlns=', 'xmlns:'], [' ns=', 'ns:'], $xml
    ));
    if (!$doc || !isset($doc->si)) return $out;
    foreach ($doc->si as $si) {
        if (isset($si->t)) {
            $out[] = (string)$si->t;
        } elseif (isset($si->r)) {
            // Rich-text: concat every <r><t>…</t></r>.
            $buf = '';
            foreach ($si->r as $r) {
                if (isset($r->t)) $buf .= (string)$r->t;
            }
            $out[] = $buf;
        } else {
            $out[] = '';
        }
    }
    return $out;
}

/**
 * Parse the sheet's <row>/<c> tree into a 2D string array. Missing
 * columns inside a row (Excel sparsely encodes empties) are padded so
 * downstream indexing by position works. Streams up to $maxRows rows
 * to keep memory bounded for big member reports.
 */
function _xlsx_parse_sheet(string $xml, array $shared, int $maxRows): array
{
    $doc = @simplexml_load_string(str_replace(
        [' xmlns=', 'xmlns:'], [' ns=', 'ns:'], $xml
    ));
    if (!$doc || !isset($doc->sheetData)) return [];

    $rows = [];
    $maxColSeen = 0;
    foreach ($doc->sheetData->row as $row) {
        if (count($rows) >= $maxRows) break;
        $rowCells = [];
        foreach ($row->c as $c) {
            $ref  = (string)$c['r'];         // e.g. "B7"
            $type = (string)$c['t'];         // "s" = shared string, else numeric/inline
            $colIndex = _xlsx_ref_to_col_index($ref); // 0-based

            $val = '';
            if ($type === 's') {
                $idx = (int)$c->v;
                $val = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                if (isset($c->is->t)) $val = (string)$c->is->t;
            } else {
                // Numeric, boolean, date-serial — take the raw value.
                if (isset($c->v)) $val = (string)$c->v;
            }
            // Fill any gap between previous col and this one with ''.
            for ($i = count($rowCells); $i < $colIndex; $i++) $rowCells[] = '';
            $rowCells[] = $val;
            if ($colIndex + 1 > $maxColSeen) $maxColSeen = $colIndex + 1;
        }
        $rows[] = $rowCells;
    }
    // Pad every row to the widest so callers can index by position.
    foreach ($rows as &$r) {
        for ($i = count($r); $i < $maxColSeen; $i++) $r[] = '';
    }
    unset($r);
    return $rows;
}

/**
 * Convert an Excel cell ref ("A1", "AB3") to a 0-based column index
 * (0, 27). Ignores the row part.
 */
function _xlsx_ref_to_col_index(string $ref): int
{
    $col = 0;
    for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
        $ch = $ref[$i];
        if ($ch < 'A' || $ch > 'Z') break;
        $col = $col * 26 + (ord($ch) - ord('A') + 1);
    }
    return max(0, $col - 1);
}
