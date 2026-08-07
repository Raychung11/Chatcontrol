<?php
/**
 * AI chatbot billing helpers.
 *
 * Capture raw Anthropic cost per call, apply workspace multiplier +
 * USD→MYR at DISPLAY time so the multiplier can be tuned retroactively
 * without rewriting history.
 *
 * Call sites:
 *   ai_log_usage()      — one line after every customer-facing AI call
 *                         (first_touch, always_on, fnb_cart_parse,
 *                          suggest_reply)
 *   ai_can_spend()      — quota gate before the same calls, blocks paid-
 *                         tier workspaces that hit their monthly cap
 *   ai_usage_mtd()      — month-to-date summary for admin views
 *   ai_charge_myr()     — raw USD → billed MYR (multiplier × FX)
 */

require_once __DIR__ . '/helpers.php';

/**
 * Anthropic list price per 1M tokens (USD). Reads platform_settings
 * so pricing is bumpable without a deploy.
 */
function ai_price_per_million_usd(string $model): array
{
    $m = mb_strtolower($model);
    $tier = str_contains($m, 'opus')                          ? 'opus'
          : (str_contains($m, 'haiku')                        ? 'haiku'
          : (str_contains($m, 'sonnet') || str_contains($m, 'fable') ? 'sonnet'
          : 'default'));
    return [
        'input'  => (float)platform_setting('ai_price_' . $tier . '_input',  '3.00'),
        'output' => (float)platform_setting('ai_price_' . $tier . '_output', '15.00'),
        'tier'   => $tier,
    ];
}

/**
 * Raw USD cost for a single call. Rounded to 6 decimals so tiny
 * per-message costs still aggregate accurately.
 */
function ai_raw_cost_usd(string $model, int $promptTokens, int $completionTokens): float
{
    $p = ai_price_per_million_usd($model);
    return round(
        ($promptTokens * $p['input'] + $completionTokens * $p['output']) / 1_000_000,
        6
    );
}

/**
 * Log one AI call. Best-effort — never throws to caller. Callers pass
 * whatever they already have; missing pieces default to safe values so
 * a partial call still records something useful.
 */
function ai_log_usage(int $companyId, ?int $conversationId, string $feature, ?array $usage, ?string $model): void
{
    if ($companyId <= 0) return;
    try {
        $pt = (int)($usage['input_tokens']  ?? $usage['prompt_tokens']     ?? 0);
        $ct = (int)($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $md = (string)($model ?: 'unknown');
        $cost = ai_raw_cost_usd($md, $pt, $ct);
        aiserve_db()->prepare(
            'INSERT INTO ai_usage_events
                (company_id, conversation_id, feature, model,
                 prompt_tokens, completion_tokens, raw_cost_usd)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$companyId, $conversationId, $feature, $md, $pt, $ct, $cost]);
    } catch (Throwable $e) {
        error_log('[AiServe ai_log_usage] ' . $e->getMessage());
    }
}

/**
 * Multiplier applied to raw cost for this workspace. Falls back to
 * platform default (5×) if the workspace column is 0 or NULL.
 */
function ai_multiplier_for_company(int $companyId): float
{
    static $cache = [];
    if (array_key_exists($companyId, $cache)) return $cache[$companyId];
    try {
        $s = aiserve_db()->prepare('SELECT ai_chatbot_multiplier FROM companies WHERE id = ? LIMIT 1');
        $s->execute([$companyId]);
        $m = (float)($s->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $m = 0;
    }
    if ($m <= 0) $m = (float)platform_setting('ai_default_multiplier', '5.00');
    return $cache[$companyId] = $m;
}

/**
 * Platform-editable USD → MYR conversion rate.
 */
function ai_usd_to_myr(): float
{
    return (float)platform_setting('ai_usd_to_myr', '4.70');
}

/**
 * Raw USD cost → billed MYR for this workspace.
 */
function ai_charge_myr(float $rawUsd, int $companyId): float
{
    return round($rawUsd * ai_multiplier_for_company($companyId) * ai_usd_to_myr(), 4);
}

/**
 * Month-to-date usage summary. Returns everything the admin views
 * need — tokens, calls, raw USD, billed MYR, breakdown by feature.
 */
function ai_usage_mtd(int $companyId): array
{
    $r = ['in_tokens' => 0, 'out_tokens' => 0, 'raw_usd' => 0.0, 'calls' => 0];
    try {
        $s = aiserve_db()->prepare(
            "SELECT
                COALESCE(SUM(prompt_tokens), 0)     AS in_tokens,
                COALESCE(SUM(completion_tokens), 0) AS out_tokens,
                COALESCE(SUM(raw_cost_usd), 0)      AS raw_usd,
                COUNT(*)                            AS calls
             FROM ai_usage_events
             WHERE company_id = ?
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $s->execute([$companyId]);
        $row = $s->fetch();
        if ($row) {
            $r['in_tokens']  = (int)$row['in_tokens'];
            $r['out_tokens'] = (int)$row['out_tokens'];
            $r['raw_usd']    = (float)$row['raw_usd'];
            $r['calls']      = (int)$row['calls'];
        }
    } catch (Throwable $e) { /* table missing pre-migration = zeros */ }

    $mult = ai_multiplier_for_company($companyId);
    $fx   = ai_usd_to_myr();
    return [
        'in_tokens'    => $r['in_tokens'],
        'out_tokens'   => $r['out_tokens'],
        'total_tokens' => $r['in_tokens'] + $r['out_tokens'],
        'calls'        => $r['calls'],
        'raw_usd'      => (float)$r['raw_usd'],
        'charge_myr'   => round($r['raw_usd'] * $mult * $fx, 2),
        'multiplier'   => $mult,
        'fx'           => $fx,
    ];
}

/**
 * MTD breakdown grouped by feature — powers the pie/bar on the
 * workspace-side transparency page.
 */
function ai_usage_mtd_by_feature(int $companyId): array
{
    try {
        $s = aiserve_db()->prepare(
            "SELECT feature,
                    SUM(prompt_tokens + completion_tokens) AS tokens,
                    SUM(raw_cost_usd)                      AS raw_usd,
                    COUNT(*)                               AS calls
             FROM ai_usage_events
             WHERE company_id = ?
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
             GROUP BY feature
             ORDER BY raw_usd DESC"
        );
        $s->execute([$companyId]);
        $rows = $s->fetchAll();
    } catch (Throwable $e) { return []; }

    $mult = ai_multiplier_for_company($companyId);
    $fx   = ai_usd_to_myr();
    foreach ($rows as &$row) {
        $row['tokens']     = (int)$row['tokens'];
        $row['calls']      = (int)$row['calls'];
        $row['raw_usd']    = (float)$row['raw_usd'];
        $row['charge_myr'] = round($row['raw_usd'] * $mult * $fx, 2);
    }
    return $rows;
}

/**
 * Daily MTD trend for the line chart. Fills zero-days so the chart
 * doesn't misleadingly compress a sparse month.
 */
function ai_usage_mtd_daily(int $companyId): array
{
    $days = [];
    try {
        $s = aiserve_db()->prepare(
            "SELECT DATE(created_at) AS d,
                    SUM(prompt_tokens + completion_tokens) AS tokens,
                    SUM(raw_cost_usd)                      AS raw_usd
             FROM ai_usage_events
             WHERE company_id = ?
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
             GROUP BY DATE(created_at)"
        );
        $s->execute([$companyId]);
        foreach ($s->fetchAll() as $r) {
            $days[(string)$r['d']] = ['tokens' => (int)$r['tokens'], 'raw_usd' => (float)$r['raw_usd']];
        }
    } catch (Throwable $e) { /* fine */ }

    $mult = ai_multiplier_for_company($companyId);
    $fx   = ai_usd_to_myr();

    $out = [];
    $today  = new DateTimeImmutable('today');
    $first  = new DateTimeImmutable('first day of this month 00:00:00');
    for ($cur = $first; $cur <= $today; $cur = $cur->modify('+1 day')) {
        $k = $cur->format('Y-m-d');
        $t = $days[$k]['tokens']  ?? 0;
        $u = $days[$k]['raw_usd'] ?? 0.0;
        $out[] = [
            'd'          => $k,
            'tokens'     => $t,
            'raw_usd'    => $u,
            'charge_myr' => round($u * $mult * $fx, 2),
        ];
    }
    return $out;
}

/**
 * Quota gate. Only 'paid' tier with a cap is blocked; 'payg' and 'none'
 * always pass. Compares MTD raw USD cost against ai_chatbot_monthly_cap
 * (which is set in USD raw cost — the platform's actual cost of goods,
 * not the client's billed amount).
 */
function ai_can_spend(int $companyId): bool
{
    try {
        $s = aiserve_db()->prepare(
            'SELECT ai_chatbot_plan, ai_chatbot_monthly_cap
             FROM companies WHERE id = ? LIMIT 1'
        );
        $s->execute([$companyId]);
        $row = $s->fetch();
    } catch (Throwable $e) { return true; }

    if (!$row || (string)($row['ai_chatbot_plan'] ?? 'none') !== 'paid') return true;
    $cap = (float)($row['ai_chatbot_monthly_cap'] ?? 0);
    if ($cap <= 0) return true;

    $usage = ai_usage_mtd($companyId);
    return $usage['raw_usd'] < $cap;
}

/**
 * Human label + color for a workspace's chatbot plan.
 */
function ai_chatbot_plan_badge(string $plan): string
{
    $map = [
        'none' => ['Disabled', '#94A3B8'],
        'payg' => ['PAYG',     '#0072B2'],
        'paid' => ['Paid',     '#16A34A'],
    ];
    [$label, $color] = $map[$plan] ?? $map['none'];
    return '<span style="display:inline-block; padding:2px 8px; border-radius:999px;'
         . ' background:' . $color . '22; color:' . $color . '; font-size:11px; font-weight:600;">'
         . htmlspecialchars($label) . '</span>';
}
