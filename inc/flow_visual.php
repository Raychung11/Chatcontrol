<?php
/**
 * Flow visual overview renderer.
 *
 * Top-down inline-SVG flowchart of a message flow. Each node is a
 * clickable rounded rectangle that anchors to #node-<id> — the
 * corresponding edit row in /admin/flow_edit.php. Colored per node
 * type, with the entry node marked ★.
 *
 * Layout is a longest-path layered auto-layout:
 *   1. Depth of the entry node = 0
 *   2. Every reachable node's depth = max(parents' depth) + 1
 *   3. Nodes never reached from entry get parked at the bottom
 *      (still visible so the operator can clean them up)
 *   4. Within each depth row, nodes are sorted by id for stability
 *
 * No external libraries — matches the inline-SVG style used elsewhere
 * (F&B analytics, AI usage). Pure server-side render; the SVG is a
 * fully-formed subtree in the HTML.
 */

/**
 * Compute a longest-path depth for every node reachable from the
 * entry. Returns [depth_by_id, orphan_ids] where orphans is the list
 * of unreachable node ids.
 */
function flow_visual_layout(array $nodes, array $edgesByNode, ?int $entryId): array
{
    $byId = [];
    foreach ($nodes as $n) $byId[(int)$n['id']] = $n;

    // Collect outgoing edges per node (next_node_id + branch edges).
    $children = [];
    foreach ($nodes as $n) {
        $nid = (int)$n['id'];
        $children[$nid] = [];
        if (!empty($n['next_node_id']) && isset($byId[(int)$n['next_node_id']])) {
            $children[$nid][] = (int)$n['next_node_id'];
        }
        foreach ($edgesByNode[$nid] ?? [] as $e) {
            $tid = (int)$e['to_node_id'];
            if (isset($byId[$tid])) $children[$nid][] = $tid;
        }
    }

    // Longest-path DP via iterative BFS. Converges in <= |nodes| iterations
    // for DAGs; for graphs with a cycle we cap at 100 to stay safe.
    $depth = [];
    if ($entryId && isset($byId[$entryId])) {
        $depth[$entryId] = 0;
        for ($iter = 0; $iter < 100; $iter++) {
            $changed = false;
            foreach ($depth as $nid => $d) {
                foreach ($children[$nid] ?? [] as $c) {
                    $want = $d + 1;
                    if (!isset($depth[$c]) || $depth[$c] < $want) {
                        $depth[$c] = $want;
                        $changed = true;
                    }
                }
            }
            if (!$changed) break;
        }
    }

    // Orphan nodes — unreachable from entry (or no entry set). Bucket
    // them at max_depth + 1 so they render below the main flow.
    $orphans = [];
    foreach ($byId as $nid => $_n) {
        if (!isset($depth[$nid])) $orphans[] = $nid;
    }
    if ($orphans) {
        $maxD = $depth ? max($depth) : -1;
        foreach ($orphans as $oid) $depth[$oid] = $maxD + 1;
    }

    return [$depth, $orphans];
}

const FLOW_VISUAL_PALETTE = [
    'send_message'     => ['stroke' => '#3b82f6', 'fill' => '#dbeafe', 'icon' => '💬'],
    'wait_reply'       => ['stroke' => '#f59e0b', 'fill' => '#fef3c7', 'icon' => '⏸'],
    'branch'           => ['stroke' => '#a855f7', 'fill' => '#f3e8ff', 'icon' => '🔀'],
    'assign_dept'      => ['stroke' => '#14b8a6', 'fill' => '#ccfbf1', 'icon' => '👥'],
    'save_note'        => ['stroke' => '#64748b', 'fill' => '#f1f5f9', 'icon' => '📝'],
    'end'              => ['stroke' => '#ef4444', 'fill' => '#fee2e2', 'icon' => '🏁'],
    'fnb_send_menu'    => ['stroke' => '#10b981', 'fill' => '#d1fae5', 'icon' => '📋'],
    'fnb_cart_add'     => ['stroke' => '#8b5cf6', 'fill' => '#ede9fe', 'icon' => '🛒'],
    'fnb_cart_show'    => ['stroke' => '#0ea5e9', 'fill' => '#e0f2fe', 'icon' => '👀'],
    'fnb_create_order' => ['stroke' => '#eab308', 'fill' => '#fef9c3', 'icon' => '✅'],
];

/**
 * The public entry point. Returns a complete <svg>…</svg> string
 * ready to drop into the page, or a small placeholder when the flow
 * has no nodes.
 */
function flow_visual_render(array $nodes, array $edgesByNode, ?int $entryId): string
{
    if (!$nodes) {
        return '<div class="muted small" style="padding:24px; text-align:center; background:#fafbfc; border:1px solid #e3e8ee; border-radius:8px;">'
             . 'No nodes yet — add one below to see the diagram.'
             . '</div>';
    }

    [$depth, $orphans] = flow_visual_layout($nodes, $edgesByNode, $entryId ?: 0);

    // Group nodes into rows by depth, sorted by id within each row so
    // the layout is stable between refreshes.
    $rows = [];
    foreach ($depth as $nid => $d) $rows[$d][] = $nid;
    foreach ($rows as $d => $ids) { sort($ids); $rows[$d] = $ids; }
    ksort($rows);

    // Layout parameters.
    $nodeW = 220; $nodeH = 62;
    $hgap  = 24;  $vgap  = 70;
    $padL  = 20;  $padT  = 20;

    // Position each node.
    $pos = [];
    $maxCols = 1;
    foreach ($rows as $d => $ids) {
        $maxCols = max($maxCols, count($ids));
    }
    foreach ($rows as $d => $ids) {
        // Center the row so shorter rows sit under the widest.
        $rowW = count($ids) * $nodeW + max(0, count($ids) - 1) * $hgap;
        $canvasInnerW = $maxCols * $nodeW + max(0, $maxCols - 1) * $hgap;
        $xOffset = ($canvasInnerW - $rowW) / 2;
        foreach ($ids as $col => $nid) {
            $pos[$nid] = [
                'x' => $padL + $xOffset + $col * ($nodeW + $hgap),
                'y' => $padT + $d * ($nodeH + $vgap),
            ];
        }
    }

    $svgW = max(800, $padL * 2 + $maxCols * $nodeW + max(0, $maxCols - 1) * $hgap);
    $svgH = $padT * 2 + count($rows) * $nodeH + max(0, count($rows) - 1) * $vgap;

    // Build the SVG.
    $byId = [];
    foreach ($nodes as $n) $byId[(int)$n['id']] = $n;

    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
          . ' viewBox="0 0 ' . $svgW . ' ' . $svgH . '"'
          . ' style="width:100%; height:auto; max-height:640px; background:#fafbfc;'
          . ' border:1px solid #e3e8ee; border-radius:8px; display:block;">';

    $svg .= '<defs>'
          . '<marker id="fvArrow" markerWidth="10" markerHeight="10" refX="8" refY="4" orient="auto">'
          . '<path d="M0,0 L8,4 L0,8 Z" fill="#94a3b8"/></marker>'
          . '<marker id="fvArrowDashed" markerWidth="10" markerHeight="10" refX="8" refY="4" orient="auto">'
          . '<path d="M0,0 L8,4 L0,8 Z" fill="#cbd5e1"/></marker>'
          . '</defs>';

    // Edges first so nodes render on top.
    foreach ($nodes as $n) {
        $nid = (int)$n['id'];
        if (!isset($pos[$nid])) continue;
        $fromX = $pos[$nid]['x'] + $nodeW / 2;
        $fromY = $pos[$nid]['y'] + $nodeH;

        // Main "next" edge (unlabeled).
        $nextId = (int)($n['next_node_id'] ?? 0);
        if ($nextId && isset($pos[$nextId])) {
            $toX = $pos[$nextId]['x'] + $nodeW / 2;
            $toY = $pos[$nextId]['y'];
            $svg .= flow_visual_edge($fromX, $fromY, $toX, $toY, '', false);
        }

        // Branch edges (labeled). Default edge is dashed.
        foreach ($edgesByNode[$nid] ?? [] as $e) {
            $tid = (int)$e['to_node_id'];
            if (!isset($pos[$tid])) continue;
            $toX = $pos[$tid]['x'] + $nodeW / 2;
            $toY = $pos[$tid]['y'];
            $dashed = ($e['condition_type'] === 'default');
            $label  = $dashed ? 'else' : (string)($e['condition_value'] ?? '');
            $svg .= flow_visual_edge($fromX, $fromY, $toX, $toY, $label, $dashed);
        }
    }

    // Nodes.
    foreach ($nodes as $n) {
        $nid = (int)$n['id'];
        if (!isset($pos[$nid])) continue;
        $svg .= flow_visual_node($n, $pos[$nid]['x'], $pos[$nid]['y'], $nodeW, $nodeH, $entryId === $nid);
    }

    // Orphan warning under the diagram if we parked any.
    $svg .= '</svg>';

    if ($orphans) {
        $svg .= '<div class="muted small" style="margin-top:6px;">'
              . '⚠ ' . count($orphans) . ' node(s) not reachable from the entry node — they\'re rendered in the last row. Set the entry node or wire them in to remove this warning.'
              . '</div>';
    }
    return $svg;
}

function flow_visual_node(array $n, float $x, float $y, float $w, float $h, bool $isEntry): string
{
    $nid   = (int)$n['id'];
    $type  = (string)$n['node_type'];
    $pal   = FLOW_VISUAL_PALETTE[$type] ?? ['stroke' => '#64748b', 'fill' => '#f1f5f9', 'icon' => '❓'];
    $label = trim((string)($n['label'] ?? '')) ?: str_replace('_', ' ', $type);
    if (mb_strlen($label) > 26) $label = mb_substr($label, 0, 24) . '…';

    $badge = '';
    if ($isEntry) {
        $bx = $x + $w - 12;
        $by = $y - 6;
        $badge = '<circle cx="' . $bx . '" cy="' . $by . '" r="10" fill="#16a34a" stroke="#fff" stroke-width="2"/>'
               . '<text x="' . $bx . '" y="' . ($by + 4) . '" text-anchor="middle" font-size="11" fill="#fff" font-weight="700">★</text>';
    }

    return '<a xlink:href="#node-' . $nid . '" href="#node-' . $nid . '" style="cursor:pointer;">'
         . '<title>Click to jump to edit form for node #' . $nid . '</title>'
         . '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="10"'
         . ' fill="' . $pal['fill'] . '" stroke="' . $pal['stroke'] . '" stroke-width="2"/>'
         . '<text x="' . ($x + 14) . '" y="' . ($y + 28) . '" font-size="20">' . $pal['icon'] . '</text>'
         . '<text x="' . ($x + 44) . '" y="' . ($y + 24) . '" font-size="13" font-weight="700" fill="' . $pal['stroke'] . '">'
         . '#' . $nid . ' ' . htmlspecialchars($label) . '</text>'
         . '<text x="' . ($x + 44) . '" y="' . ($y + 44) . '" font-size="11" fill="#64748b">'
         . htmlspecialchars(str_replace('_', ' ', $type)) . '</text>'
         . $badge
         . '</a>';
}

function flow_visual_edge(float $fromX, float $fromY, float $toX, float $toY, string $label, bool $dashed): string
{
    $midY = ($fromY + $toY) / 2;
    $path = sprintf('M%.1f,%.1f C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
        $fromX, $fromY, $fromX, $midY, $toX, $midY, $toX, $toY - 4);

    $stroke = $dashed ? '#cbd5e1' : '#94a3b8';
    $dash   = $dashed ? ' stroke-dasharray="4 4"' : '';
    $marker = $dashed ? 'fvArrowDashed' : 'fvArrow';

    $out = '<path d="' . $path . '" fill="none" stroke="' . $stroke . '" stroke-width="1.5"'
         . $dash . ' marker-end="url(#' . $marker . ')"/>';

    if ($label !== '') {
        if (mb_strlen($label) > 18) $label = mb_substr($label, 0, 16) . '…';
        // Approximate width — 7px per char + padding.
        $labelW = min(140, max(40, mb_strlen($label) * 7 + 16));
        $lx = ($fromX + $toX) / 2;
        $ly = $midY;
        $out .= '<rect x="' . ($lx - $labelW / 2) . '" y="' . ($ly - 9) . '"'
              . ' width="' . $labelW . '" height="18" rx="3" fill="#fff" stroke="' . $stroke . '"/>'
              . '<text x="' . $lx . '" y="' . ($ly + 4) . '" text-anchor="middle" font-size="11" fill="#334155">'
              . htmlspecialchars($label) . '</text>';
    }
    return $out;
}
