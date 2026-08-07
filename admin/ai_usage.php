<?php
/**
 * Workspace-side AI usage transparency page.
 *
 * Shows the workspace their own MTD AI consumption + accrued charge in
 * MYR (no USD, no cost visibility — clients see final price only).
 * Feature breakdown + daily trend chart help them explain their bill.
 *
 * Read-only. Plan / multiplier / cap changes are platform admin only.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$company = load_company_settings($companyId);
$plan    = (string)($company['ai_chatbot_plan'] ?? 'none');
$cap     = (float)($company['ai_chatbot_monthly_cap'] ?? 0);

$mtd      = ai_usage_mtd($companyId);
$byFeat   = ai_usage_mtd_by_feature($companyId);
$daily    = ai_usage_mtd_daily($companyId);
$currency = platform_setting('pricing_currency', 'RM');

// Cap in MYR (client-facing view) = cap_usd × multiplier × fx.
$capMyr = $cap > 0 ? round($cap * $mtd['multiplier'] * $mtd['fx'], 2) : 0.0;
$usedPct = ($cap > 0 && $mtd['raw_usd'] > 0)
    ? min(100, ($mtd['raw_usd'] / $cap) * 100)
    : 0;

layout_start($current_user, 'AI usage', 'ai_usage');
?>
<style>
.au-kpi-row { display: grid; gap: 10px; margin-bottom: 14px; grid-template-columns: repeat(4, 1fr); }
@media (max-width: 900px) { .au-kpi-row { grid-template-columns: repeat(2, 1fr); } }
.au-kpi { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; padding: 14px 16px; }
.au-kpi .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.au-kpi .val { color: #0f172a; font-size: 24px; font-weight: 700; margin-top: 3px; }
.au-kpi .sub { color: #94a3b8; font-size: 11px; margin-top: 4px; }
.au-kpi .val.green { color: #16A34A; }

.au-plan-banner {
    background: #eef2ff; border: 1px solid #c7d2fe; padding: 10px 14px;
    border-radius: 10px; font-size: 13px; margin-bottom: 14px; color: #1e293b;
}
.au-plan-banner .strong { font-weight: 700; }
.au-plan-off {
    background: #fef2f2; border-color: #fecaca; color: #7f1d1d;
}

.au-cap-bar { height: 8px; background: #e3e8ee; border-radius: 4px; overflow: hidden; margin-top: 6px; }
.au-cap-fill { height: 100%; border-radius: 4px; }
.au-cap-ok   { background: #16A34A; }
.au-cap-warn { background: #F59E0B; }
.au-cap-full { background: #DC2626; }

.au-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; }
.au-card h3 { margin: 0 0 10px; font-size: 14px; }

.au-feat-list { display: flex; flex-direction: column; gap: 6px; }
.au-feat-row { display: grid; grid-template-columns: 160px 1fr 100px 80px; gap: 8px; align-items: center; font-size: 12.5px; }
.au-feat-row .lbl { font-weight: 500; color: #0f172a; }
.au-feat-bar { height: 10px; background: #eef2f7; border-radius: 3px; overflow: hidden; }
.au-feat-fill { height: 100%; background: #0072B2; border-radius: 3px; }
.au-mono { font-variant-numeric: tabular-nums; text-align: right; }

.au-svg { display: block; width: 100%; height: auto; }
</style>

<?php if ($plan === 'none'): ?>
    <div class="au-plan-banner au-plan-off">
        <strong>The AI chatbot module is not enabled for this workspace.</strong>
        Contact us to activate — you'll only pay for what you use, no monthly commitment.
    </div>
<?php else: ?>
    <div class="au-plan-banner">
        <span class="strong">Plan:</span>
        <?= $plan === 'payg' ? 'Pay as you go (no monthly commitment)' : 'Paid (monthly cap)' ?>
        · <span class="strong">Rate:</span> billed in <?= e($currency) ?>, updated at each Anthropic API call.
        <?php if ($plan === 'paid' && $capMyr > 0): ?>
            · <span class="strong">Monthly cap:</span> ~<?= e($currency) ?> <?= number_format($capMyr, 2) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="au-kpi-row">
    <div class="au-kpi">
        <div class="lbl">This month — charge</div>
        <div class="val green"><?= e($currency) ?> <?= number_format($mtd['charge_myr'], 2) ?></div>
        <div class="sub">across <?= number_format($mtd['calls']) ?> AI calls</div>
    </div>
    <div class="au-kpi">
        <div class="lbl">Tokens used</div>
        <div class="val"><?= number_format($mtd['total_tokens']) ?></div>
        <div class="sub"><?= number_format($mtd['in_tokens']) ?> in · <?= number_format($mtd['out_tokens']) ?> out</div>
    </div>
    <div class="au-kpi">
        <div class="lbl">Avg cost per call</div>
        <div class="val">
            <?= e($currency) ?>
            <?= $mtd['calls'] > 0 ? number_format($mtd['charge_myr'] / $mtd['calls'], 3) : '0.000' ?>
        </div>
        <div class="sub">this month</div>
    </div>
    <div class="au-kpi">
        <div class="lbl">Monthly cap</div>
        <?php if ($plan === 'paid' && $cap > 0): ?>
            <div class="val"><?= number_format($usedPct, 0) ?>%</div>
            <div class="au-cap-bar">
                <div class="au-cap-fill <?= $usedPct >= 100 ? 'au-cap-full' : ($usedPct >= 80 ? 'au-cap-warn' : 'au-cap-ok') ?>"
                     style="width: <?= number_format($usedPct, 1) ?>%;"></div>
            </div>
            <div class="sub">
                <?php if ($usedPct >= 100): ?>
                    ⚠ Cap reached — AI replies paused until next month
                <?php else: ?>
                    ~<?= e($currency) ?> <?= number_format(max(0, $capMyr - $mtd['charge_myr']), 2) ?> left
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="val" style="font-size:16px; color:#64748b;">No cap</div>
            <div class="sub">pay as you go</div>
        <?php endif; ?>
    </div>
</div>

<!-- Feature breakdown -->
<div class="au-card">
    <h3>Where the spend went (this month)</h3>
    <?php if (!$byFeat): ?>
        <p class="muted small">No AI calls yet this month.</p>
    <?php else:
        $featLabels = [
            'first_touch'    => '🤖 First-touch auto-reply',
            'always_on'      => '💬 Always-on AI',
            'suggest_reply'  => '✍️ Agent-composer suggest',
            'fnb_cart_parse' => '🍜 F&B AI cart parser',
        ];
        $maxCharge = 0;
        foreach ($byFeat as $f) $maxCharge = max($maxCharge, (float)$f['charge_myr']);
        if ($maxCharge <= 0) $maxCharge = 1;
    ?>
        <div class="au-feat-list">
            <?php foreach ($byFeat as $f):
                $pct = ((float)$f['charge_myr'] / $maxCharge) * 100;
            ?>
                <div class="au-feat-row">
                    <div class="lbl"><?= e($featLabels[$f['feature']] ?? $f['feature']) ?></div>
                    <div class="au-feat-bar"><div class="au-feat-fill" style="width: <?= number_format($pct, 1) ?>%;"></div></div>
                    <div class="au-mono">
                        <?= e($currency) ?> <?= number_format((float)$f['charge_myr'], 2) ?>
                    </div>
                    <div class="au-mono muted"><?= number_format((int)$f['calls']) ?>× · <?= number_format((int)$f['tokens']) ?> tok</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Daily trend -->
<div class="au-card">
    <h3>Daily spend (this month)</h3>
    <?php
        $dailyMax = 0;
        foreach ($daily as $d) $dailyMax = max($dailyMax, (float)$d['charge_myr']);
        if ($dailyMax <= 0) $dailyMax = 1;
        $svgW = 800; $svgH = 180;
        $padL = 44; $padR = 12; $padT = 10; $padB = 26;
        $plotW = $svgW - $padL - $padR;
        $plotH = $svgH - $padT - $padB;
        $n = max(1, count($daily));
        $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
        $pts = [];
        foreach ($daily as $i => $d) {
            $x = $padL + $i * $stepX;
            $y = $padT + $plotH - ((float)$d['charge_myr'] / $dailyMax) * $plotH;
            $pts[] = [$x, $y, $d];
        }
    ?>
    <?php if (!$daily): ?>
        <p class="muted small">No usage yet this month.</p>
    <?php else: ?>
        <svg class="au-svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" role="img">
            <?php for ($t = 0; $t <= 4; $t++):
                $frac = $t / 4; $y = $padT + $plotH - $frac * $plotH; $val = $dailyMax * $frac; ?>
                <line x1="<?= $padL ?>" y1="<?= $y ?>" x2="<?= $svgW - $padR ?>" y2="<?= $y ?>" stroke="#eef2f7"/>
                <text x="<?= $padL - 6 ?>" y="<?= $y + 3 ?>" font-size="10" fill="#94a3b8" text-anchor="end">
                    <?= e($currency) ?> <?= number_format($val, 1) ?>
                </text>
            <?php endfor; ?>

            <?php if (count($pts) > 1):
                $area = 'M ' . $pts[0][0] . ' ' . ($padT + $plotH);
                foreach ($pts as [$x, $y]) $area .= ' L ' . $x . ' ' . $y;
                $area .= ' L ' . end($pts)[0] . ' ' . ($padT + $plotH) . ' Z';
                $line = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
                for ($k = 1; $k < count($pts); $k++) $line .= ' L ' . $pts[$k][0] . ' ' . $pts[$k][1];
            ?>
                <path d="<?= $area ?>" fill="#0072B2" fill-opacity="0.12"/>
                <path d="<?= $line ?>" fill="none" stroke="#0072B2" stroke-width="2"/>
            <?php endif; ?>

            <?php $labelStep = max(1, (int)ceil($n / 6));
              foreach ($pts as $i => [$x, $y, $d]): ?>
                <circle cx="<?= $x ?>" cy="<?= $y ?>" r="2.5" fill="#0072B2">
                    <title><?= e((string)$d['d']) ?>: <?= e($currency) ?> <?= number_format((float)$d['charge_myr'], 2) ?> · <?= number_format((int)$d['tokens']) ?> tokens</title>
                </circle>
                <?php if ($i % $labelStep === 0 || $i === $n - 1): ?>
                    <text x="<?= $x ?>" y="<?= $svgH - 8 ?>" font-size="10" fill="#94a3b8" text-anchor="middle">
                        <?= e((new DateTimeImmutable((string)$d['d']))->format('j M')) ?>
                    </text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
    <?php endif; ?>
</div>

<?php layout_end(); ?>
