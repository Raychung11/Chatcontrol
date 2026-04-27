<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$id   = (int)($_GET['id'] ?? 0);
$tpl  = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$id, $companyId]);
    $tpl = $stmt->fetch();
    if (!$tpl) { http_response_code(404); exit('Template not found.'); }
}

$err = '';
if (is_post()) {
    csrf_check();
    $name     = trim((string)($_POST['template_name'] ?? ''));
    $category = trim((string)($_POST['category']      ?? ''));
    $language = trim((string)($_POST['language']      ?? 'en'));
    $body     = trim((string)($_POST['body_text']     ?? ''));
    $status   = (string)($_POST['status'] ?? 'draft');
    $vars     = trim((string)($_POST['variables_json'] ?? ''));

    if (!preg_match('/^[a-z0-9_]{3,120}$/', $name)) {
        $err = 'Template name must use lowercase letters, numbers, and underscores only.';
    } elseif ($body === '') {
        $err = 'Body text is required.';
    } elseif (!in_array($status, ['draft','approved','rejected','paused'], true)) {
        $err = 'Invalid status.';
    } else {
        try {
            if ($tpl) {
                $stmt = $db->prepare(
                    'UPDATE message_templates SET template_name=?, category=?, language=?, body_text=?, variables_json=?, status=?
                     WHERE id=? AND company_id=?'
                );
                $stmt->execute([$name, $category ?: null, $language ?: 'en', $body, $vars ?: null, $status, $id, $companyId]);
                log_activity($companyId, (int)$current_user['id'], 'template_updated', 'template', $id);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO message_templates (company_id, template_name, category, language, body_text, variables_json, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$companyId, $name, $category ?: null, $language ?: 'en', $body, $vars ?: null, $status]);
                log_activity($companyId, (int)$current_user['id'], 'template_created', 'template', (int)$db->lastInsertId());
            }
            redirect('/admin/templates.php');
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                $err = 'A template with that name and language already exists.';
            } else {
                error_log($e->getMessage());
                $err = 'Could not save template.';
            }
        }
    }
}

layout_start($current_user, $tpl ? 'Edit template' : 'New template', 'templates');
?>
<div class="card">
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label>Template name (lowercase, underscores)
      <input type="text" name="template_name" required pattern="[a-z0-9_]{3,120}"
             value="<?= e($tpl['template_name'] ?? ($_POST['template_name'] ?? '')) ?>">
    </label>
    <label>Category
      <input type="text" name="category" placeholder="MARKETING / UTILITY / AUTHENTICATION"
             value="<?= e($tpl['category'] ?? ($_POST['category'] ?? '')) ?>">
    </label>
    <label>Language
      <input type="text" name="language" value="<?= e($tpl['language'] ?? ($_POST['language'] ?? 'en')) ?>">
    </label>
    <label>Status
      <select name="status">
        <?php foreach (['draft','approved','rejected','paused'] as $s): ?>
          <option value="<?= $s ?>" <?= ($tpl['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Body
      <textarea name="body_text" rows="5" required><?= e($tpl['body_text'] ?? ($_POST['body_text'] ?? '')) ?></textarea>
    </label>
    <label>Variables (optional JSON, e.g. {"1":"customer_name"})
      <textarea name="variables_json" rows="3"><?= e($tpl['variables_json'] ?? ($_POST['variables_json'] ?? '')) ?></textarea>
    </label>
    <div>
      <button class="btn btn-primary" type="submit"><?= $tpl ? 'Save' : 'Create' ?></button>
      <a class="btn" href="/admin/templates.php">Cancel</a>
    </div>
  </form>
</div>
<?php layout_end(); ?>
