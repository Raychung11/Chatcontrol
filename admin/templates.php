<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    if (!user_can_edit_settings($current_user)) {
        http_response_code(403); exit('Forbidden.');
    }
    $tid = (int)($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM message_templates WHERE id = ? AND company_id = ?')
       ->execute([$tid, $companyId]);
    log_activity($companyId, (int)$current_user['id'], 'template_deleted', 'template', $tid);
    redirect('/admin/templates.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'seed') {
    csrf_check();
    if (!user_can_edit_settings($current_user)) {
        http_response_code(403); exit('Forbidden.');
    }
    // Starter pack tuned for Malaysian SMB customer service - a mix of
    // greetings, hours, catalog / price, order + delivery status, and
    // a couple of Bahasa Malaysia variants. All start as 'draft' so the
    // operator reviews and submits to Meta before use.
    $seeds = [
        ['welcome_greeting', 'UTILITY', 'en',
         "Hi {{1}}! 👋 Welcome to {{2}} — how can we help you today?",
         '{"1":"customer_name","2":"business_name"}'],
        ['welcome_greeting_bm', 'UTILITY', 'ms',
         "Hi {{1}}! 👋 Selamat datang ke {{2}} — bagaimana kami boleh bantu?",
         '{"1":"customer_name","2":"business_name"}'],
        ['business_hours', 'UTILITY', 'en',
         "Thanks for reaching out! Our team is available {{1}}. We'll get back to you as soon as we're back online.",
         '{"1":"business_hours"}'],
        ['catalog_menu', 'MARKETING', 'en',
         "Hi {{1}}, here's our latest menu 👇 Let us know if anything catches your eye!",
         '{"1":"customer_name"}'],
        ['price_inquiry', 'UTILITY', 'en',
         "Hi {{1}}, thanks for asking about pricing. Our full price list is attached. For custom quotes, just tell us what you need!",
         '{"1":"customer_name"}'],
        ['order_confirmation', 'UTILITY', 'en',
         "Hi {{1}}, we've received your order #{{2}}. Total: {{3}}. We'll notify you when it's ready — thanks for shopping with us!",
         '{"1":"customer_name","2":"order_id","3":"total_amount"}'],
        ['delivery_ready', 'UTILITY', 'en',
         "Good news {{1}}! Your order #{{2}} is on the way. Expected delivery: {{3}}. Track it here: {{4}} — thanks for your patience!",
         '{"1":"customer_name","2":"order_id","3":"eta","4":"tracking_url"}'],
        ['booking_confirmed', 'UTILITY', 'en',
         "Hi {{1}}, your booking on {{2}} at {{3}} is confirmed ✅ Reply here if you need to change anything.",
         '{"1":"customer_name","2":"date","3":"time"}'],
        ['payment_reminder', 'UTILITY', 'en',
         "Hi {{1}}, a friendly reminder that invoice {{2}} for {{3}} is due on {{4}}. Reply here for payment options.",
         '{"1":"customer_name","2":"invoice_id","3":"amount","4":"due_date"}'],
        ['thanks_review', 'MARKETING', 'en',
         "Thanks for choosing {{1}}, {{2}}! If you had a great experience, a quick Google review helps us a lot ⭐ Leave one here: {{3}} — we appreciate it!",
         '{"1":"business_name","2":"customer_name","3":"review_url"}'],
    ];

    $ins = $db->prepare(
        'INSERT IGNORE INTO message_templates
            (company_id, template_name, category, language, body_text, variables_json, status)
         VALUES (?, ?, ?, ?, ?, ?, "draft")'
    );
    $added = 0;
    foreach ($seeds as [$n, $cat, $lang, $body, $vars]) {
        $ins->execute([$companyId, $n, $cat, $lang, $body, $vars]);
        if ($ins->rowCount() > 0) $added++;
    }
    log_activity($companyId, (int)$current_user['id'], 'templates_seeded', 'company', $companyId, 'added=' . $added);
    redirect('/admin/templates.php?seeded=' . $added);
}

$stmt = $db->prepare('SELECT * FROM message_templates WHERE company_id = ? ORDER BY template_name');
$stmt->execute([$companyId]);
$templates = $stmt->fetchAll();

layout_start($current_user, 'Message templates', 'templates');
$seeded = (int)($_GET['seeded'] ?? 0);
?>
<div class="card">
  <?php if ($seeded > 0): ?>
    <div class="alert alert-success">Added <?= (int)$seeded ?> starter template<?= $seeded === 1 ? '' : 's' ?>. Review each one, tweak the text if needed, then submit to Meta for approval.</div>
  <?php elseif (isset($_GET['seeded']) && $seeded === 0): ?>
    <div class="alert alert-info">Starter pack already loaded — no duplicates were added.</div>
  <?php endif; ?>

  <div class="card-head">
    <h2>WhatsApp message templates</h2>
    <?php if (user_can_edit_settings($current_user)): ?>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if (!$templates): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="seed">
            <button class="btn" type="submit" title="Add 10 common customer-service templates to save time">📦 Load starter pack</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-primary" href="/admin/template_edit.php">+ New template</a>
      </div>
    <?php endif; ?>
  </div>
  <p class="muted small">
    Templates are pre-approved by Meta and required when replying outside the 24-hour window.
    Save the approved template name and body here so agents can reference them.
    <?php if (!$templates && user_can_edit_settings($current_user)): ?>
      <br>New workspace? Click <strong>📦 Load starter pack</strong> for 10 ready-to-edit templates
      (greeting, hours, menu, price list, order confirmation, delivery, booking, payment reminder, review request),
      or use <strong>+ New template</strong> and describe your template in plain English —
      AI will draft it for you.
    <?php endif; ?>
  </p>

  <table class="data-table">
    <thead><tr><th>Name</th><th>Category</th><th>Language</th><th>Body</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$templates): ?>
        <tr><td colspan="6" class="muted">No templates yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td><code><?= e($t['template_name']) ?></code></td>
          <td><?= e($t['category'] ?? '—') ?></td>
          <td><?= e($t['language']) ?></td>
          <td><?= e(mb_strimwidth((string)$t['body_text'], 0, 100, '…')) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td>
            <?php if (user_can_edit_settings($current_user)): ?>
              <a class="btn btn-sm" href="/admin/template_edit.php?id=<?= (int)$t['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this template?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
