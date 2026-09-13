<?php
/**
 * Invoice generation + rendering + emailing.
 *
 * Two entry points:
 *   invoicing_create_for_upgrade(activityLog)  — cron helper. Idempotent.
 *   invoicing_render_html(invoice)             — build the viewer/email HTML.
 *
 * Payment gateway is out of scope for this phase — customer receives an
 * invoice, pays via the bank details in the invoice, admin manually
 * marks paid via /admin/invoices.php. Billplz webhook would slot in on
 * top of this table when we wire it.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email.php';

/**
 * Given an activity_logs row of type 'broadcast_plan_selfserve_change',
 * INSERT an invoice for it if none exists yet. Returns the invoice id
 * or null when there's nothing to invoice (downgrades to free, cycle-
 * only changes on the same plan).
 */
function invoicing_create_for_upgrade(array $log): ?int
{
    // The activity log message is "Before/cycle → After/cycle" — parse it
    // back out. If the "after" plan isn't a billable one, skip.
    $msg = (string)($log['message'] ?? '');
    if (!preg_match('/->|→/u', $msg)) return null;
    $parts = preg_split('/\s*(?:->|→)\s*/u', $msg, 2);
    if (count($parts) !== 2) return null;
    $afterLabel = trim($parts[1]);   // e.g. "Paid/yearly"
    if (!preg_match('#^([^/]+)/(\w+)$#', $afterLabel, $m)) return null;
    $planAfter  = mb_strtolower(trim($m[1]));
    $cycleAfter = mb_strtolower(trim($m[2]));

    // Only invoice Paid + PAYG. Downgrades to Free skip.
    if (!in_array($planAfter, ['paid', 'payg'], true)) return null;
    if (!in_array($cycleAfter, ['monthly', 'yearly'], true)) $cycleAfter = 'monthly';

    $companyId = (int)($log['company_id'] ?? 0);
    $sourceId  = (int)($log['id']         ?? 0);
    if ($companyId <= 0 || $sourceId <= 0) return null;

    $q = broadcast_quota_for_workspace($companyId);
    $currency = (string)$q['currency'];

    if ($planAfter === 'paid') {
        // Monthly = one recurring month. Yearly = one full year up-front.
        $unit     = $cycleAfter === 'yearly'
            ? (float)$q['yearly_price']
            : (float)$q['price'];
        $quantity = 1;
    } else {
        // PAYG — invoice is $0 at activation. Actual invoicing done at
        // month-end from broadcast_recipients total. For now, mark
        // draft with quantity=0.
        $unit     = 0.0;
        $quantity = 0;
    }
    $subtotal = round($unit * $quantity, 2);
    $tax      = 0.0;   // TODO: add SST 8% here when the operator turns it on
    $total    = round($subtotal + $tax, 2);

    // Invoice number: INV-YYYY-XXXXX (per-year sequence).
    $year = date('Y');
    try {
        $db = aiserve_db();
        $c  = $db->prepare(
            "SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?"
        );
        $c->execute(['INV-' . $year . '-%']);
        $next = (int)$c->fetchColumn() + 1;
        $invNumber = sprintf('INV-%s-%05d', $year, $next);

        $token = bin2hex(random_bytes(24));
        $ins = $db->prepare(
            'INSERT IGNORE INTO invoices
                (company_id, source_activity_id, invoice_number,
                 plan, billing_cycle, quantity, unit_price,
                 subtotal, tax, total, currency,
                 status, view_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
        );
        $ins->execute([
            $companyId, $sourceId, $invNumber,
            $planAfter, $cycleAfter, $quantity, $unit,
            $subtotal, $tax, $total, $currency, $token,
        ]);
        // If INSERT IGNORE hit the unique(source_activity_id), lastInsertId is 0.
        $newId = (int)$db->lastInsertId();
        return $newId > 0 ? $newId : null;
    } catch (Throwable $e) {
        error_log('[AiServe invoicing_create_for_upgrade] ' . $e->getMessage());
        return null;
    }
}

/**
 * Look up an invoice by (id, token) for the public viewer.
 */
function invoicing_get_by_token(int $id, string $token): ?array
{
    try {
        $s = aiserve_db()->prepare(
            'SELECT i.*, c.name AS company_name, c.slug AS company_slug
             FROM invoices i
             INNER JOIN companies c ON c.id = i.company_id
             WHERE i.id = ? AND i.view_token = ? LIMIT 1'
        );
        $s->execute([$id, $token]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Full-page HTML for the invoice viewer AND the email body. Uses
 * inline styles (no external CSS) so it renders identically in an
 * email client, in a browser, and in print-to-PDF.
 */
function invoicing_render_html(array $invoice, bool $forEmail = false): string
{
    // Prefer trading name; fall back to legal name so an operator who
    // only fills one field still gets a labelled invoice.
    $operator = [
        'name'    => platform_setting('operator_business_name',
                     platform_setting('operator_legal_name',    'AiServe')),
        'address' => platform_setting('operator_address',        ''),
        // Historical duplicate key names — check both.
        'reg_no'  => platform_setting('operator_registration_no',
                     platform_setting('operator_reg_no',         '')),
        'tax_id'  => platform_setting('operator_tax_id',         ''),
        'email'   => platform_setting('operator_email',
                     platform_setting('operator_contact_email',  '')),
        'phone'   => platform_setting('operator_phone',          ''),
    ];
    $payText = platform_setting('broadcast_payment_instructions',
        "Bank transfer to the account on the invoice. "
      . "Once paid, reply to this email or WhatsApp us with your reference.");

    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : '';
    $viewUrl = $base . '/invoice.php?id=' . (int)$invoice['id']
             . '&token=' . urlencode((string)$invoice['view_token']);

    $desc = $invoice['plan'] === 'paid'
        ? 'Broadcast — Paid plan (' . ucfirst((string)$invoice['billing_cycle']) . ')'
        : 'Broadcast — Pay-as-you-go activation';
    $currency = (string)$invoice['currency'];

    ob_start(); ?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Invoice <?= e((string)$invoice['invoice_number']) ?></title>
<style>
    body { font-family: -apple-system, system-ui, sans-serif; color: #0f172a; margin: 0; padding: 24px;
           background: #f6f9fb; }
    .inv-page { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 10px;
                padding: 32px 36px; border: 1px solid #e3e8ee; }
    .inv-head { display: flex; justify-content: space-between; align-items: flex-start;
                margin-bottom: 26px; }
    .inv-brand { font-size: 22px; font-weight: 700; color: #16A34A; }
    .inv-brand .sub { font-size: 12px; color: #64748b; font-weight: 400; margin-top: 4px; }
    .inv-meta { text-align: right; font-size: 12.5px; color: #334155; line-height: 1.5; }
    .inv-meta .label { color: #94a3b8; text-transform: uppercase; font-size: 10px; letter-spacing: .04em; }
    .inv-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 26px;
                   font-size: 13px; }
    .inv-parties h4 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
                      color: #64748b; }
    table.inv-lines { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; }
    table.inv-lines th { text-align: left; padding: 10px 8px; background: #f1f5f9; color: #334155;
                         font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
    table.inv-lines td { padding: 12px 8px; border-bottom: 1px solid #eef2f7; }
    table.inv-lines .right { text-align: right; }
    .inv-totals { width: 260px; margin-left: auto; font-size: 13px; }
    .inv-totals .row { display: flex; justify-content: space-between; padding: 4px 0; }
    .inv-totals .grand { font-weight: 700; font-size: 16px; padding-top: 10px;
                          border-top: 2px solid #0f172a; margin-top: 8px; }
    .inv-status {
      display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px;
      font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    }
    .st-draft { background: #f1f5f9; color: #64748b; }
    .st-sent  { background: #dbeafe; color: #1e3a8a; }
    .st-paid  { background: #dcfce7; color: #14532d; }
    .st-void  { background: #fee2e2; color: #7f1d1d; }
    .inv-pay { background: #fefce8; border: 1px solid #fef08a; padding: 12px 14px;
               border-radius: 8px; margin-top: 24px; font-size: 12.5px; white-space: pre-wrap; }
    .inv-actions { text-align: right; margin-top: 20px; }
    .inv-actions button, .inv-actions a {
        padding: 8px 14px; background: #0f172a; color: #fff; border: 0; border-radius: 6px;
        cursor: pointer; text-decoration: none; font-size: 13px;
    }
    @media print {
        body { background: #fff; padding: 0; }
        .inv-page { border: 0; }
        .inv-actions { display: none; }
    }
</style>
</head>
<body>
<div class="inv-page">
    <div class="inv-head">
        <div>
            <div class="inv-brand">
                <?= e((string)$operator['name']) ?>
                <div class="sub"><?= e((string)$operator['address']) ?></div>
            </div>
        </div>
        <div class="inv-meta">
            <div>
                <span class="inv-status st-<?= e((string)$invoice['status']) ?>">
                    <?= e((string)$invoice['status']) ?>
                </span>
            </div>
            <div style="margin-top:8px;">
                <div class="label">Invoice #</div>
                <strong><?= e((string)$invoice['invoice_number']) ?></strong>
            </div>
            <div style="margin-top:6px;">
                <div class="label">Issued</div>
                <?= e(fmt_dt((string)$invoice['created_at'])) ?>
            </div>
            <?php if ($invoice['paid_at']): ?>
                <div style="margin-top:6px;">
                    <div class="label">Paid</div>
                    <?= e(fmt_dt((string)$invoice['paid_at'])) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="inv-parties">
        <div>
            <h4>From</h4>
            <strong><?= e((string)$operator['name']) ?></strong>
            <?php if ($operator['reg_no']  !== ''): ?><br>Reg: <?= e((string)$operator['reg_no']) ?><?php endif; ?>
            <?php if ($operator['tax_id']  !== ''): ?><br>SST: <?= e((string)$operator['tax_id']) ?><?php endif; ?>
            <?php if ($operator['email']   !== ''): ?><br><?= e((string)$operator['email']) ?><?php endif; ?>
            <?php if ($operator['phone']   !== ''): ?><br><?= e((string)$operator['phone']) ?><?php endif; ?>
        </div>
        <div>
            <h4>Bill to</h4>
            <strong><?= e((string)$invoice['company_name']) ?></strong>
            <br><span style="color:#64748b;">Workspace: <?= e((string)$invoice['company_slug']) ?></span>
        </div>
    </div>

    <table class="inv-lines">
        <thead>
            <tr>
                <th style="width:60%;">Description</th>
                <th class="right">Qty</th>
                <th class="right">Unit price</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= e($desc) ?></td>
                <td class="right"><?= number_format((int)$invoice['quantity']) ?></td>
                <td class="right"><?= e($currency) ?> <?= number_format((float)$invoice['unit_price'], 2) ?></td>
                <td class="right"><?= e($currency) ?> <?= number_format((float)$invoice['subtotal'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="inv-totals">
        <div class="row"><span>Subtotal</span><span><?= e($currency) ?> <?= number_format((float)$invoice['subtotal'], 2) ?></span></div>
        <div class="row"><span>Tax</span><span><?= e($currency) ?> <?= number_format((float)$invoice['tax'], 2) ?></span></div>
        <div class="row grand"><span>Total due</span><span><?= e($currency) ?> <?= number_format((float)$invoice['total'], 2) ?></span></div>
    </div>

    <?php if ((string)$invoice['status'] !== 'paid'): ?>
        <div class="inv-pay">
            <strong>💰 Payment instructions</strong>
            <?= "\n" . e($payText) ?>
        </div>
    <?php endif; ?>

    <?php if (!$forEmail): ?>
        <div class="inv-actions">
            <button onclick="window.print()">🖨 Print / save as PDF</button>
            <a href="<?= e($viewUrl) ?>" style="background:#64748b; margin-left:6px;">Copy link</a>
        </div>
    <?php else: ?>
        <p style="text-align:center; margin-top:26px; font-size:13px;">
            <a href="<?= e($viewUrl) ?>" style="background:#0f172a; color:#fff; padding:10px 18px; border-radius:6px; text-decoration:none;">
                View invoice online
            </a>
        </p>
    <?php endif; ?>
</div>
</body></html>
    <?php
    return (string)ob_get_clean();
}

/**
 * Email the invoice link to the workspace's first super_admin.
 * Marks status = sent + records email_sent_at. Idempotent per call —
 * safe to re-run after a mail failure.
 */
function invoicing_send_email(int $invoiceId): bool
{
    try {
        $db = aiserve_db();
        $s  = $db->prepare(
            'SELECT i.*, c.name AS company_name, c.slug AS company_slug
             FROM invoices i
             INNER JOIN companies c ON c.id = i.company_id
             WHERE i.id = ? LIMIT 1'
        );
        $s->execute([$invoiceId]);
        $inv = $s->fetch();
        if (!$inv) return false;

        // Pick the first super_admin of the workspace.
        $u = $db->prepare(
            "SELECT email FROM users
             WHERE company_id = ? AND role = 'super_admin' AND status = 'active'
             ORDER BY id ASC LIMIT 1"
        );
        $u->execute([(int)$inv['company_id']]);
        $to = (string)($u->fetchColumn() ?: '');
        if ($to === '') return false;

        $html    = invoicing_render_html($inv, true);
        $subject = 'Invoice ' . $inv['invoice_number'] . ' — ' . APP_NAME;

        $sent = send_email_html($to, $subject, $html);
        if ($sent) {
            $db->prepare(
                'UPDATE invoices SET status = "sent", email_sent_to = ?, email_sent_at = NOW()
                 WHERE id = ?'
            )->execute([$to, $invoiceId]);
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('[AiServe invoicing_send_email] ' . $e->getMessage());
        return false;
    }
}
