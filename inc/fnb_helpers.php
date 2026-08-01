<?php
/**
 * F&B module shared helpers — order number, line total, status labels.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Formatted human-facing order number from a row's numeric id.
 *   id=1   -> "A10001"
 *   id=42  -> "A10042"
 *   id=500 -> "A10500"
 * Adds 10000 so the count of orders on a fresh workspace isn't visible.
 */
function fnb_order_number_from_id(int $id): string
{
    return 'A' . str_pad((string)($id + 10000), 5, '0', STR_PAD_LEFT);
}

/**
 * Compute the price of one line item given base + variant deltas +
 * addon deltas + quantity. Both variant / addon arrays accept either
 * ['price_delta' => 2.5] or the raw float (for flexibility).
 */
function fnb_line_total(float $unitPrice, array $variants, array $addons, int $qty): float
{
    $extra = 0.0;
    foreach ($variants as $v) {
        $extra += is_array($v) ? (float)($v['price_delta'] ?? 0) : (float)$v;
    }
    foreach ($addons as $a) {
        $extra += is_array($a) ? (float)($a['price_delta'] ?? 0) : (float)$a;
    }
    return round(($unitPrice + $extra) * $qty, 2);
}

/**
 * Colored status label used across the order UI. Reserves the palette
 * so a rebrand only touches this map.
 */
function fnb_status_label(string $status): string
{
    $map = [
        'new'        => ['New',         '#2563EB'],
        'confirmed'  => ['Confirmed',   '#9333EA'],
        'processing' => ['Processing',  '#F59E0B'],
        'completed'  => ['Completed',   '#16A34A'],
        'cancelled'  => ['Cancelled',   '#94A3B8'],
    ];
    if (!isset($map[$status])) return htmlspecialchars($status);
    [$label, $color] = $map[$status];
    return '<span style="display:inline-block; padding:2px 10px; border-radius:999px; '
         . 'background:' . $color . '22; color:' . $color . '; font-weight:600; font-size:12px;">'
         . htmlspecialchars($label) . '</span>';
}

/**
 * The forward-only status transition. Given the current status, returns
 * the next natural status + a button label, or null for terminal states.
 * cancelled is reachable from any non-terminal state via a separate button.
 */
function fnb_next_status(string $status): ?array
{
    return match ($status) {
        'new'        => ['confirmed',  'Confirm →'],
        'confirmed'  => ['processing', 'Start processing →'],
        'processing' => ['completed',  'Mark completed →'],
        default      => null,
    };
}
