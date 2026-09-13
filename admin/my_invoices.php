<?php
/**
 * /admin/my_invoices.php — the workspace's own invoices.
 *
 * Read-only for the customer. Links to the public viewer for each row
 * so they can print / save as PDF from their browser. Also shows
 * outstanding amount so they know what's due.
 */
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$rows = $db->prepare(
    'SELECT * FROM invoices WHERE company_id = ?
     ORDER BY id DESC LIMIT 100'
);
$rows->execute([$companyId]);
$rows = $rows->fetchAll();

$outstanding = 0.0;
$paid        = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'sent' || $r['status'] === 'draft') $outstanding += (float)$r['total'];
    if ($r['status'] === 'paid') $paid += (float)$r['total'];
}

layout_start($current_user, 'My invoices', 'my_invoices');
?>
<div class="card">
    <h2>📄 My invoices</h2>
    <p class="muted small">
        Every invoice we've issued to this workspace. Click an invoice to open it in a new tab
        — from there you can print or save as PDF.
    </p>

    <div style="display:grid; gap:10px; grid-template-columns: 1fr 1fr; margin-bottom:16px;">
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px;">
            <div class="muted small">Outstanding</div>
            <div style="font-size:22px; font-weight:700; color:#7f1d1d;">
                RM <?= number_format($outstanding, 2) ?>
            </div>
        </div>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px;">
            <div class="muted small">Paid to date</div>
            <div style="font-size:22px; font-weight:700; color:#14532d;">
                RM <?= number_format($paid, 2) ?>
            </div>
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Item</th>
                <th style="text-align:right;">Total</th>
                <th>Status</th>
                <th>Issued</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="muted" style="text-align:center; padding:24px;">
                    No invoices yet. When you upgrade a plan, an invoice appears here.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $viewUrl = '/invoice.php?id=' . (int)$r['id'] . '&token=' . urlencode((string)$r['view_token']);
                $statusCls = 'iv-' . $r['status'];
            ?>
                <tr>
                    <td><a href="<?= e($viewUrl) ?>" target="_blank"><?= e((string)$r['invoice_number']) ?></a></td>
                    <td>
                        <?= e(ucfirst((string)$r['plan'])) ?>
                        <span class="muted small">/ <?= e((string)$r['billing_cycle']) ?></span>
                    </td>
                    <td style="text-align:right; font-variant-numeric: tabular-nums;">
                        <?= e((string)$r['currency']) ?> <?= number_format((float)$r['total'], 2) ?>
                    </td>
                    <td>
                        <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600;
                                     background:<?= $r['status']==='paid'?'#dcfce7':($r['status']==='void'?'#fee2e2':($r['status']==='sent'?'#dbeafe':'#f1f5f9')) ?>;
                                     color:<?= $r['status']==='paid'?'#14532d':($r['status']==='void'?'#7f1d1d':($r['status']==='sent'?'#1e3a8a':'#64748b')) ?>;">
                            <?= e((string)$r['status']) ?>
                        </span>
                    </td>
                    <td class="muted small"><?= e(fmt_dt((string)$r['created_at'])) ?></td>
                    <td style="text-align:right;">
                        <a class="btn btn-sm" href="<?= e($viewUrl) ?>" target="_blank">📄 View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php layout_end(); ?>
