
<?php
/**
 * /admin/broadcast_credits.php — platform-admin manual broadcast credits.
 *
 * Grants extra broadcast recipients to a workspace on top of its plan
 * (paid customer bought an offline top-up, goodwill after an outage,
 * a promo). Every grant is a signed ledger row in broadcast_credits —
 * positive amount = add, negative = debit / correction, revoked_at
 * takes the row out of the sum.
 *
 * broadcast_quota_for_workspace() (inc/helpers.php) sums the non-
 * revoked, non-expired rows into the effective limit, so a workspace's
 * 10,000-recipient paid plan + 2,000 bonus credits = 12,000 recipients
 * this month. See sql/migration_phase54.sql for the schema.
 *
 * URL params:
 *   ?company_id=N   scope the page to a single workspace (shows grant
 *                   form + only that workspace's history). Otherwise
 *                   shows the full cross-workspace audit trail.
 */
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
if (is_impersonating()) {
    redirect('/dashboard.php');
}

$db       = aiserve_db();
$currency = platform_setting('pricing_currency', 'RM');

// -------------------- Which workspace are we scoping to? --------------------
$scopeCompanyId = (int)($_GET['company_id'] ?? 0);
$scopeCompany   = null;
if ($scopeCompanyId > 0) {
    $s = $db->prepare('SELECT id, name, slug, broadcast_plan FROM companies WHERE id = ? LIMIT 1');
    $s->execute([$scopeCompanyId]);
    $scopeCompany = $s->fetch();
    if (!$scopeCompany) {
        http_response_code(404);
        exit('Workspace not found.');
    }
}

// -------------------- POST: grant / revoke --------------------
$msg = '';
$err = '';
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'grant') {
        $cid    = (int)($_POST['company_id'] ?? 0);
        $amount = (int)($_POST['amount']     ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $expIn  = trim((string)($_POST['expires_in_days'] ?? ''));

        if ($cid <= 0) {
            $err = 'Pick a workspace to grant credits to.';
        } elseif ($amount === 0) {
            $err = 'Enter a non-zero amount (positive to add, negative to debit).';
        } elseif (abs($amount) > 1_000_000) {
            // Sanity guard — nobody's granting a million bonus recipients
            // by mistake. Increase if you ever need to.
            $err = 'Amount is out of range (max ±1,000,000).';
        } else {
            $chk = $db->prepare('SELECT id, name FROM companies WHERE id = ? LIMIT 1');
            $chk->execute([$cid]);
            $target = $chk->fetch();
            if (!$target) {
                $err = 'Target workspace not found.';
            } else {
                $expiresAt = null;
                if ($expIn !== '' && ctype_digit($expIn)) {
                    $days = max(1, min(3650, (int)$expIn));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
                }
                $ins = $db->prepare(
                    'INSERT INTO broadcast_credits
                        (company_id, amount, reason, granted_by_user_id, granted_at, expires_at)
                     VALUES (?, ?, ?, ?, NOW(), ?)'
                );
                $ins->execute([
                    $cid,
                    $amount,
                    $reason !== '' ? $reason : ($amount > 0 ? 'Manual credit' : 'Manual debit'),
                    (int)$current_user['id'],
                    $expiresAt,
                ]);
                $newId = (int)$db->lastInsertId();

                log_activity(
                    $cid,
                    (int)$current_user['id'],
                    'broadcast_credits_granted',
                    'broadcast_credits',
                    $newId,
                    'amount=' . $amount
                    . ' reason=' . mb_substr($reason, 0, 120)
                    . ($expiresAt ? (' expires=' . $expiresAt) : '')
                );

                $back = '/admin/broadcast_credits.php'
                      . ($scopeCompanyId > 0 ? '?company_id=' . $scopeCompanyId : '')
                      . ($scopeCompanyId > 0 ? '&' : '?') . 'msg='
                      . rawurlencode(
                          ($amount > 0 ? '+' : '') . number_format($amount)
                          . ' credit(s) granted to ' . $target['name']
                      );
                redirect($back);
            }
        }
    } elseif ($action === 'revoke') {
        $rowId = (int)($_POST['row_id'] ?? 0);
        if ($rowId > 0) {
            $r = $db->prepare('SELECT id, company_id, amount, revoked_at FROM broadcast_credits WHERE id = ? LIMIT 1');
            $r->execute([$rowId]);
            $row = $r->fetch();
            if ($row && !$row['revoked_at']) {
                $db->prepare(
                    'UPDATE broadcast_credits
                     SET revoked_at = NOW(), revoked_by_user_id = ?
                     WHERE id = ? LIMIT 1'
                )->execute([(int)$current_user['id'], $rowId]);
                log_activity(
                    (int)$row['company_id'],
                    (int)$current_user['id'],
                    'broadcast_credits_revoked',
                    'broadcast_credits',
                    $rowId,
                    'amount=' . $row['amount']
                );
                $msg = 'Credit row revoked.';
            } else {
                $err = 'That credit row was already revoked or does not exist.';
            }
        }
    }
}

$flash = trim((string)($_GET['msg'] ?? ''));
if ($flash !== '' && $msg === '') $msg = $flash;

// -------------------- Load ledger --------------------
if ($scopeCompanyId > 0) {
    $stmt = $db->prepare(
        'SELECT bc.*, u.name AS granted_by_name, ru.name AS revoked_by_name, c.name AS company_name
         FROM broadcast_credits bc
         LEFT JOIN users u  ON u.id  = bc.granted_by_user_id
         LEFT JOIN users ru ON ru.id = bc.revoked_by_user_id
         LEFT JOIN companies c ON c.id = bc.company_id
         WHERE bc.company_id = ?
         ORDER BY bc.granted_at DESC
         LIMIT 500'
    );
    $stmt->execute([$scopeCompanyId]);
} else {
    $stmt = $db->query(
        'SELECT bc.*, u.name AS granted_by_name, ru.name AS revoked_by_name, c.name AS company_name
         FROM broadcast_credits bc
         LEFT JOIN users u  ON u.id  = bc.granted_by_user_id
         LEFT JOIN users ru ON ru.id = bc.revoked_by_user_id
         LEFT JOIN companies c ON c.id = bc.company_id
         ORDER BY bc.granted_at DESC
         LIMIT 500'
    );
}
$rows = $stmt->fetchAll();

// Cross-workspace roll-ups for the summary strip.
$totalActive = 0;
$totalGranted = 0;
$totalRevoked = 0;
$expiringSoon = 0;
$soonCutoff = strtotime('+30 days');
foreach ($rows as $r) {
    $totalGranted += (int)$r['amount'];
    if ($r['revoked_at']) {
        $totalRevoked += (int)$r['amount'];
        continue;
    }
    if ($r['expires_at'] && strtotime((string)$r['expires_at']) < time()) continue;
    $totalActive += (int)$r['amount'];
    if ($r['expires_at'] && strtotime((string)$r['expires_at']) < $soonCutoff) {
        $expiringSoon++;
    }
}

// If we're scoped to one workspace, get its live quota so we can show
// "Effective quota after credits".
$scopeQuota = null;
if ($scopeCompanyId > 0) {
    $scopeQuota = broadcast_quota_for_workspace($scopeCompanyId);
}

// If we're NOT scoped, load a workspace list for the grant form dropdown.
$allWorkspaces = [];
if ($scopeCompanyId <= 0) {
    $allWorkspaces = $db->query(
        "SELECT id, name, slug FROM companies
         WHERE status = 'active'
         ORDER BY name ASC"
    )->fetchAll();
}

layout_start($current_user, 'Broadcast credits', 'broadcast_credits');
?>
<style>
.bc-hero { display:grid; gap:10px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom:14px; }
@media (max-width: 900px) { .bc-hero { grid-template-columns: repeat(2, 1fr); } }
.bc-kpi { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; }
.bc-kpi .lbl { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.bc-kpi .val { color:#0f172a; font-size:22px; font-weight:700; margin-top:2px; }
.bc-kpi .sub { color:#94a3b8; font-size:11px; margin-top:2px; }
.bc-amt-pos { color:#16A34A; font-weight:700; }
.bc-amt-neg { color:#DC2626; font-weight:700; }
.bc-status-active   { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#dcfce7; color:#14532d; font-weight:600; }
.bc-status-expired  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#f1f5f9; color:#64748b; font-weight:600; }
.bc-status-revoked  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#fee2e2; color:#991b1b; font-weight:600; }
.bc-grant-card {
    background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:16px;
    margin-bottom:14px;
}
.bc-grant-grid { display:grid; gap:10px; grid-template-columns: 1.4fr 1fr 1fr 1fr auto; align-items:end; }
@media (max-width: 900px) { .bc-grant-grid { grid-template-columns: 1fr; } }
.bc-grant-grid label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:#475569; }
.bc-grant-grid input, .bc-grant-grid select {
    padding:7px 9px; font-size:14px; border:1px solid #d0d7de; border-radius:6px; background:#fff;
}
.bc-preset { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.bc-preset button {
    background:#f1f5f9; border:1px solid #cbd5e1; color:#334155; border-radius:999px;
    padding:4px 10px; font-size:12px; cursor:pointer;
}
.bc-preset button:hover { background:#e2e8f0; border-color:#94a3b8; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
  <div>
    <h1 style="margin:0;">🎁 Broadcast credits</h1>
    <div class="muted small">Manual top-ups granted on top of a workspace's plan quota — audit trail below.</div>
  </div>
  <div style="display:flex; gap:8px;">
    <?php if ($scopeCompanyId > 0): ?>
      <a class="btn btn-sm" href="/admin/broadcast_credits.php">← All workspaces</a>
    <?php endif; ?>
    <a class="btn btn-sm" href="/admin/workspaces.php">Workspaces</a>
  </div>
</div>

<?php if ($msg !== ''): ?>
  <div class="alert alert-success" style="margin-bottom:10px;">✓ <?= e($msg) ?></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-error" style="margin-bottom:10px;"><?= e($err) ?></div>
<?php endif; ?>

<?php if ($scopeCompanyId > 0 && $scopeQuota): ?>
  <?php
    $planLimit = (int)($scopeQuota['plan_limit'] ?? 0);
    $creditBal = (int)($scopeQuota['credits']    ?? 0);
    $effective = $scopeQuota['unlimited'] ? '∞' : number_format($planLimit + $creditBal);
    $planLabel = ucfirst((string)$scopeQuota['plan']);
    if ($scopeQuota['plan'] === 'paid') {
        $planLabel .= ' (' . (string)$scopeQuota['billing_cycle'] . ')';
    }
  ?>
  <div class="bc-hero">
    <div class="bc-kpi">
      <div class="lbl">Workspace</div>
      <div class="val" style="font-size:16px;"><?= e($scopeCompany['name']) ?></div>
      <div class="sub"><code><?= e($scopeCompany['slug']) ?></code></div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Base plan</div>
      <div class="val" style="font-size:18px;"><?= e($planLabel) ?></div>
      <div class="sub"><?= number_format($planLimit) ?> recipients / month</div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Active bonus credits</div>
      <div class="val" style="color: <?= $creditBal > 0 ? '#16A34A' : ($creditBal < 0 ? '#DC2626' : '#0f172a') ?>;">
        <?= $creditBal > 0 ? '+' : '' ?><?= number_format($creditBal) ?>
      </div>
      <div class="sub">non-revoked, non-expired</div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Effective monthly quota</div>
      <div class="val"><?= $effective ?></div>
      <div class="sub">used this month: <?= number_format((int)$scopeQuota['used']) ?></div>
    </div>
  </div>
<?php else: ?>
  <div class="bc-hero">
    <div class="bc-kpi">
      <div class="lbl">Active credits (live)</div>
      <div class="val bc-amt-pos"><?= number_format($totalActive) ?></div>
      <div class="sub">non-revoked, non-expired</div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Total granted (all time)</div>
      <div class="val"><?= number_format($totalGranted) ?></div>
      <div class="sub">sum of all rows in ledger</div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Revoked</div>
      <div class="val bc-amt-neg"><?= number_format($totalRevoked) ?></div>
      <div class="sub">rolled back rows</div>
    </div>
    <div class="bc-kpi">
      <div class="lbl">Expiring within 30 days</div>
      <div class="val"><?= number_format($expiringSoon) ?></div>
      <div class="sub">credit rows</div>
    </div>
  </div>
<?php endif; ?>

<!-- Grant form -->
<div class="bc-grant-card">
  <h3 style="margin:0 0 12px 0;">
    <?php if ($scopeCompanyId > 0): ?>
      Grant credits to <?= e($scopeCompany['name']) ?>
    <?php else: ?>
      Grant broadcast credits
    <?php endif; ?>
  </h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="grant">
    <div class="bc-grant-grid">
      <?php if ($scopeCompanyId > 0): ?>
        <input type="hidden" name="company_id" value="<?= (int)$scopeCompanyId ?>">
        <label>Workspace<input type="text" value="<?= e($scopeCompany['name']) ?>" disabled></label>
      <?php else: ?>
        <label>Workspace
          <select name="company_id" required>
            <option value="">— pick a workspace —</option>
            <?php foreach ($allWorkspaces as $w): ?>
              <option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?> · <?= e($w['slug']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <label>Amount
        <input type="number" name="amount" value="1000" required
               placeholder="e.g. 1000 or -500" step="1">
      </label>

      <label>Reason
        <input type="text" name="reason" maxlength="200"
               placeholder="e.g. Bought 1k top-up · Goodwill · Refund #123">
      </label>

      <label>Expires in (days, optional)
        <input type="number" name="expires_in_days" min="1" max="3650"
               placeholder="blank = never">
      </label>

      <button class="btn btn-primary" type="submit" style="height:38px;">Grant</button>
    </div>
    <div class="bc-preset">
      <span class="muted small" style="align-self:center;">Quick presets:</span>
      <button type="button" data-preset="500">+500</button>
      <button type="button" data-preset="1000">+1,000</button>
      <button type="button" data-preset="2500">+2,500</button>
      <button type="button" data-preset="5000">+5,000</button>
      <button type="button" data-preset="10000">+10,000</button>
      <button type="button" data-preset="-1000">−1,000 (debit)</button>
    </div>
    <div class="muted small" style="margin-top:8px;">
      Positive amounts add to this month's quota. Negative amounts subtract (use for corrections or clawing back a promo).
      A blank "expires in" means the credit stays until you revoke it.
      Bonus credits stack on top of the plan limit — a Free workspace with 500 bonus credits sees 1,500 recipients this month.
    </div>
  </form>
</div>
<script>
  document.querySelectorAll('.bc-preset [data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = document.querySelector('input[name="amount"]');
      if (inp) { inp.value = b.getAttribute('data-preset'); inp.focus(); }
    });
  });
</script>

<!-- Ledger -->
<div class="card" style="padding:0;">
  <h3 style="margin:12px 16px 8px 16px;">
    <?php if ($scopeCompanyId > 0): ?>
      Credit history for <?= e($scopeCompany['name']) ?> (<?= count($rows) ?>)
    <?php else: ?>
      All credit grants (<?= count($rows) ?><?= count($rows) >= 500 ? '+' : '' ?>)
    <?php endif; ?>
  </h3>
  <?php if (!$rows): ?>
    <div class="muted" style="padding:24px; text-align:center;">
      No credits granted yet.
      <?php if ($scopeCompanyId > 0): ?>Use the form above to grant this workspace some extra broadcast quota.<?php endif; ?>
    </div>
  <?php else: ?>
    <table class="data-table" style="margin:0;">
      <thead>
        <tr>
          <th>Granted</th>
          <?php if ($scopeCompanyId <= 0): ?><th>Workspace</th><?php endif; ?>
          <th style="text-align:right;">Amount</th>
          <th>Reason</th>
          <th>Granted by</th>
          <th>Expires</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $isRevoked = !empty($r['revoked_at']);
          $isExpired = !$isRevoked && $r['expires_at'] && strtotime((string)$r['expires_at']) < time();
          if ($isRevoked)      $status = 'revoked';
          elseif ($isExpired)  $status = 'expired';
          else                 $status = 'active';
          $amt = (int)$r['amount'];
        ?>
          <tr>
            <td class="muted small"><?= e(fmt_dt($r['granted_at'])) ?></td>
            <?php if ($scopeCompanyId <= 0): ?>
              <td>
                <a href="/admin/broadcast_credits.php?company_id=<?= (int)$r['company_id'] ?>">
                  <?= e($r['company_name'] ?? ('#' . $r['company_id'])) ?>
                </a>
              </td>
            <?php endif; ?>
            <td style="text-align:right;">
              <span class="<?= $amt >= 0 ? 'bc-amt-pos' : 'bc-amt-neg' ?>">
                <?= $amt > 0 ? '+' : '' ?><?= number_format($amt) ?>
              </span>
            </td>
            <td><?= e((string)$r['reason']) ?></td>
            <td class="muted small"><?= e((string)($r['granted_by_name'] ?? '—')) ?></td>
            <td class="muted small">
              <?= $r['expires_at'] ? e(fmt_dt($r['expires_at'])) : 'never' ?>
            </td>
            <td>
              <span class="bc-status-<?= $status ?>"><?= $status ?></span>
              <?php if ($isRevoked): ?>
                <div class="muted small">
                  by <?= e((string)($r['revoked_by_name'] ?? '—')) ?>
                  · <?= e(fmt_dt($r['revoked_at'])) ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <?php if ($status === 'active'): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Revoke this <?= number_format($amt) ?> credit grant? This inserts a revoked_at stamp so the row stops counting. Can\'t be undone from the UI.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
