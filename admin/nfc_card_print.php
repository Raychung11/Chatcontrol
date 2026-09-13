<?php
/**
 * Printable QR sheet for NFC / QR cards.
 *
 * Renders one card per grid cell, laid out for A4 with 3 columns × 8
 * rows = 24 stickers per page. QR codes are generated in-browser
 * with the qrcode-generator library (cdnjs) so the operator can
 * print directly with no server-side dependency.
 *
 * The QR image encodes the same /tap.php?c=<TOKEN> URL that lives
 * on the NFC tag, so a printed sticker and an NFC tag can carry
 * identical routing — the workspace can offer either / both.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// Only the workspace's own cards.
$cards = $db->prepare(
    'SELECT c.token, c.label, c.table_number, c.campaign, b.name AS branch_name
     FROM nfc_cards c
     LEFT JOIN branches b ON b.id = c.branch_id
     WHERE c.company_id = ? AND c.enabled = 1
     ORDER BY c.id ASC'
);
$cards->execute([$companyId]);
$cards = $cards->fetchAll();

$baseUrl = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'inbox.aiserve.my'));

// Small self-contained page — no sidebar / header, just the sheet.
// Screen preview is centered; @media print switches to a paged layout.
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Printable QR sheet · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
<style>
  body { background: #f4f6f9; padding: 24px; }
  .print-toolbar { max-width: 900px; margin: 0 auto 18px; display: flex; justify-content: space-between; align-items: center; }
  .print-sheet {
    background: #fff; padding: 12mm; border-radius: 8px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    max-width: 210mm; margin: 0 auto;
  }
  .print-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 6mm;
  }
  .print-cell {
    border: 1px dashed #cbd5e1; border-radius: 6px;
    padding: 6mm; text-align: center;
    break-inside: avoid;
  }
  .print-cell .qr { width: 100%; aspect-ratio: 1 / 1; display: flex;
                    align-items: center; justify-content: center; }
  .print-cell .qr svg, .print-cell .qr img { width: 100%; height: 100%; }
  .print-cell .lbl { font-size: 12.5px; font-weight: 600; margin-top: 6px; }
  .print-cell .sub { font-size: 10.5px; color: #64748b; }
  .print-cell .u   { font-family: ui-monospace, Menlo, monospace;
                     font-size: 9px; color: #94a3b8; margin-top: 3px; word-break: break-all; }
  @media print {
    body { background: #fff; padding: 0; }
    .print-toolbar { display: none; }
    .print-sheet { box-shadow: none; padding: 0; max-width: none; }
    .print-cell { break-inside: avoid; page-break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="print-toolbar">
  <a href="/admin/nfc_cards.php">← Back to cards</a>
  <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Print this sheet</button>
</div>

<div class="print-sheet">
  <?php if (!$cards): ?>
    <p style="text-align:center; padding:40px; color:#64748b;">
      No enabled cards. Create some on the <a href="/admin/nfc_cards.php">NFC cards</a> page.
    </p>
  <?php else: ?>
    <div class="print-grid">
      <?php foreach ($cards as $c): ?>
        <?php $url = $baseUrl . '/tap.php?c=' . $c['token']; ?>
        <div class="print-cell">
          <div class="qr" data-url="<?= e($url) ?>"></div>
          <div class="lbl"><?= e((string)($c['label'] ?? 'Card')) ?></div>
          <div class="sub">
            <?php if ($c['table_number']): ?>Table <?= (int)$c['table_number'] ?><?php endif; ?>
            <?php if ($c['branch_name']): ?> · <?= e((string)$c['branch_name']) ?><?php endif; ?>
            <?php if ($c['campaign']):    ?> · <?= e((string)$c['campaign'])    ?><?php endif; ?>
          </div>
          <div class="u"><?= e(preg_replace('#^https?://#', '', $url)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- qrcode-generator: tiny (~20 KB), no dependencies. Renders each URL
     into an inline <svg> so print quality doesn't depend on the
     browser's PNG scaler. Loaded from cdnjs (existing allowlisted
     origin for the workspace). -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
  // Render one QR per .qr cell. Error-correction 'M' gives a good
  // scan/tolerance balance for small (~40 mm) printed stickers.
  document.querySelectorAll('.qr').forEach((cell) => {
    const url = cell.getAttribute('data-url');
    if (!url) return;
    // typeNumber 0 = auto-size to the shortest payload that fits.
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    cell.innerHTML = qr.createSvgTag({ scalable: true });
  });
</script>

</body>
</html>
