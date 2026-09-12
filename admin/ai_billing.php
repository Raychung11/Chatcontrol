<?php
/**
 * Platform admin view — AI chatbot billing across all workspaces.
 *
 * KPI strip (MTD raw cost, billed revenue, tokens, calls) + per-workspace
 * table showing tokens/raw-USD/billed-MYR/plan/multiplier/cap. Sortable
 * by revenue so the biggest spenders are top.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}

$db = aiserve_db();

// -------------------- Fetch --------------------
// One row per workspace with MTD aggregate + billing config.
$rows = $db->query(
    "SELECT
        c.id,
        c.name,
        c.slug,
        c.ai_chatbot_plan,
        c.ai_chatbot_multiplier,
        c.ai_chatbot_monthly_cap,
        COALESCE(u.in_tokens,  0) AS in_tokens,
        COALESCE(u.out_tokens, 0) AS out_tokens,
        COALESCE(u.calls,      0) AS calls,
        COALESCE(u.raw_usd,    0) AS raw_usd,
        COALESCE(u.last_call,  NULL) AS last_call
     FROM companies c
     LEFT JOIN (
        SELECT company_id,
               SUM(prompt_tokens)     AS in_tokens,
               SUM(completion_tokens) AS out_tokens,
               SUM(raw_cost_usd)      AS raw_usd,
               COUNT(*)               AS calls,
               MAX(created_at)        AS last_call
        FROM ai_usage_events
        WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        GROUP BY company_id
     ) u ON u.company_id = c.id
     WHERE c.status = 'active'
     ORDER BY raw_usd DESC, c.name ASC"
)->fetchAll();

// -------------------- Aggregate --------------------
$fx        = ai_usd_to_myr();
$defaultMx = (float)platform_setting('ai_default_multiplier', '5.00');

$totalRawUsd    = 0.0;
$totalRevMyr    = 0.0;
$totalTokens    = 0;
$totalCalls     = 0;
$workspacesUsed = 0;
foreach ($rows as &$r) {
    $mult = (float)($r['ai_chatbot_multiplier'] ?? 0);
    if ($mult <= 0) $mult = $defaultMx;
    $r['multiplier']    = $mult;
    $r['total_tokens']  = (int)$r['in_tokens'] + (int)$r['out_tokens'];
    $r['charge_myr']    = round((float)$r['raw_usd'] * $mult * $fx, 2);
    $r['raw_myr']       = round((float)$r['raw_usd'] * $fx, 2);   // your cost of goods
    $r['margin_myr']    = $r['charge_myr'] - $r['raw_myr'];

    $totalRawUsd += (float)$r['raw_usd'];
    $totalRevMyr += $r['charge_myr'];
    $totalTokens += $r['total_tokens'];
    $totalCalls  += (int)$r['calls'];
    if ((int)$r['calls'] > 0) $workspacesUsed++;
}
unset($r);
$totalCostMyr   = round($totalRawUsd * $fx, 2);
$totalMarginMyr = round($totalRevMyr - $totalCostMyr, 2);

layout_start($current_user, 'AI chatbot billing', 'ai_billing');
?>
<style>
.ab-kpi-row { display: grid; gap: 10px; margin-bottom: 14px; grid-template-columns: repeat(5, 1fr); }
@media (max-width: 1000px) { .ab-kpi-row { grid-template-columns: repeat(2, 1fr); } }
.ab-kpi { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; padding: 12px 14px; }
.ab-kpi .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.ab-kpi .val { color: #0f172a; font-size: 22px; font-weight: 700; margin-top: 2px; }
.ab-kpi .sub { color: #94a3b8; font-size: 11px; margin-top: 2px; }
.ab-kpi .val.green  { color: #16A34A; }
.ab-kpi .val.blue   { color: #0072B2; }
.ab-kpi .val.orange { color: #D55E00; }

.ab-plan-none { background: #f1f5f9; color: #64748b; }
.ab-plan-payg { background: #e0f2fe; color: #075985; }
.ab-plan-paid { background: #dcfce7; color: #14532d; }
.ab-plan-chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }

.ab-inline-form { display: inline-flex; gap: 4px; align-items: center; flex-wrap: wrap; }
.ab-inline-form select, .ab-inline-form input {
    padding: 2px 5px; font-size: 12px; border: 1px solid #d0d7de; border-radius: 4px;
}
.ab-inline-form input.narrow { width: 62px; }
.ab-icon-btn {
    width: 24px; height: 24px; border-radius: 5px; border: 1px solid #d0d7de;
    background: #fff; cursor: pointer; padding: 0; font-size: 12px;
}
.ab-icon-btn:hover { border-color: #0072B2; }

.ab-mono { font-variant-numeric: tabular-nums; }
.ab-hint {
    background: #eef2ff; border-left: 3px solid #0072B2; padding: 10px 14px;
    border-radius: 6px; font-size: 13px; color: #1e293b; margin-bottom: 12px;
}
</style>

<div class="ab-hint">
    <strong>How billing works:</strong>
    Every AI call (first-touch, always-on, F&amp;B cart parse, agent-composer suggest) writes one row to <code>ai_usage_events</code> with the raw Anthropic USD cost snapshot.
    Client charge = <strong>raw × workspace multiplier × USD→MYR</strong> (default <?= number_format($defaultMx, 1) ?>× · FX <?= number_format($fx, 2) ?>).
    Multiplier / cap changes take effect immediately across historical data.
</div>

<div class="ab-kpi-row">
    <div class="ab-kpi">
        <div class="lbl">MTD your cost</div>
        <div class="val orange">RM <?= number_format($totalCostMyr, 2) ?></div>
        <div class="sub">USD <?= number_format($totalRawUsd, 2) ?> raw Anthropic</div>
    </div>
    <div class="ab-kpi">
        <div class="lbl">MTD client revenue</div>
        <div class="val green">RM <?= number_format($totalRevMyr, 2) ?></div>
        <div class="sub">billed at <?= number_format($defaultMx, 1) ?>× default</div>
    </div>
    <div class="ab-kpi">
        <div class="lbl">MTD margin</div>
        <div class="val green">RM <?= number_format($totalMarginMyr, 2) ?></div>
        <div class="sub">revenue − cost</div>
    </div>
    <div class="ab-kpi">
        <div class="lbl">MTD tokens</div>
        <div class="val blue"><?= number_format($totalTokens) ?></div>
        <div class="sub"><?= number_format($totalCalls) ?> calls</div>
    </div>
    <div class="ab-kpi">
        <div class="lbl">Workspaces using AI</div>
        <div class="val"><?= $workspacesUsed ?> / <?= count($rows) ?></div>
        <div class="sub">this month</div>
    </div>
</div>

<div class="card" style="padding: 0;">
    <table class="data-table" style="margin: 0;">
        <thead>
            <tr>
                <th>Workspace</th>
                <th>Plan</th>
                <th>Multiplier</th>
                <th>Monthly cap (USD)</th>
                <th class="ab-mono">Tokens</th>
                <th class="ab-mono">Calls</th>
                <th class="ab-mono">Cost (USD)</th>
                <th class="ab-mono">Cost (MYR)</th>
                <th class="ab-mono">Charge (MYR)</th>
                <th class="ab-mono">Margin</th>
                <th>Last call</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">
                    No workspaces yet.
                </td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r):
                $plan = (string)($r['ai_chatbot_plan'] ?? 'none');
                $cap  = (float)($r['ai_chatbot_monthly_cap'] ?? 0);
                $capHit = $cap > 0 && (float)$r['raw_usd'] >= $cap;
            ?>
                <tr>
                    <td>
                        <strong><?= e((string)$r['name']) ?></strong>
                        <div class="muted small"><code><?= e((string)$r['slug']) ?></code></div>
                    </td>
                    <td>
                        <form method="post" action="/api/workspace_action.php" class="ab-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_ai_chatbot">
                            <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="field" value="plan">
                            <select name="value">
                                <option value="none" <?= $plan === 'none' ? 'selected' : '' ?>>Disabled</option>
                                <option value="payg" <?= $plan === 'payg' ? 'selected' : '' ?>>PAYG</option>
                                <option value="paid" <?= $plan === 'paid' ? 'selected' : '' ?>>Paid</option>
                            </select>
                            <button type="submit" class="ab-icon-btn" title="Save">✓</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="/api/workspace_action.php" class="ab-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_ai_chatbot">
                            <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="field" value="multiplier">
                            <input type="number" name="value" step="0.1" min="1" max="20"
                                   class="narrow" value="<?= number_format((float)$r['multiplier'], 1) ?>">
                            <span style="font-size:11px; color:#64748b;">×</span>
                            <button type="submit" class="ab-icon-btn" title="Save multiplier">✓</button>
                        </form>
                    </td>
                    <td>
                        <?php if ($plan === 'paid'): ?>
                            <form method="post" action="/api/workspace_action.php" class="ab-inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_ai_chatbot">
                                <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="field" value="cap">
                                <input type="number" name="value" step="1" min="0"
                                       class="narrow" value="<?= $cap > 0 ? (int)$cap : '' ?>"
                                       placeholder="none">
                                <button type="submit" class="ab-icon-btn" title="Save cap">✓</button>
                            </form>
                            <?php if ($capHit): ?>
                                <div style="color:#DC2626; font-size:11px; margin-top:2px;">⚠ over cap</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted small">n/a</span>
                        <?php endif; ?>
                    </td>
                    <td class="ab-mono"><?= number_format((int)$r['total_tokens']) ?></td>
                    <td class="ab-mono"><?= number_format((int)$r['calls']) ?></td>
                    <td class="ab-mono">$<?= number_format((float)$r['raw_usd'], 4) ?></td>
                    <td class="ab-mono">RM <?= number_format((float)$r['raw_myr'], 2) ?></td>
                    <td class="ab-mono" style="font-weight:600; color:#16A34A;">
                        RM <?= number_format((float)$r['charge_myr'], 2) ?>
                    </td>
                    <td class="ab-mono" style="color: <?= $r['margin_myr'] >= 0 ? '#16A34A' : '#DC2626' ?>;">
                        RM <?= number_format((float)$r['margin_myr'], 2) ?>
                    </td>
                    <td class="muted small"><?= e($r['last_call'] ? fmt_dt($r['last_call']) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php layout_end(); ?>
