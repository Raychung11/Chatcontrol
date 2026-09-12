<?php
/**
 * Flows list — the "n8n dashboard" equivalent.
 *
 * Shows every flow in the workspace with its trigger, status, and how
 * many instances have run against it (running / completed / failed).
 * Create is a one-click that lands the operator in the flow_edit page
 * with an empty entry_node.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';
require_once __DIR__ . '/../inc/flow_templates.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $flowId = (int)($_POST['flow_id'] ?? 0);
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { $err = 'Give the flow a name.'; }
        else {
            $ins = $db->prepare(
                'INSERT INTO flows (company_id, name, trigger_type, status, created_by_user_id)
                 VALUES (?, ?, "new_conversation", "draft", ?)'
            );
            $ins->execute([$companyId, $name, (int)$current_user['id']]);
            $newId = (int)$db->lastInsertId();
            log_activity($companyId, (int)$current_user['id'], 'flow_created', 'flow', $newId, $name);
            redirect('/admin/flow_edit.php?id=' . $newId);
        }
    } elseif ($action === 'toggle' && $flowId > 0) {
        $db->prepare(
            'UPDATE flows SET status = CASE status
                WHEN "active" THEN "paused"
                WHEN "paused" THEN "active"
                ELSE "active"
              END
             WHERE id = ? AND company_id = ?'
        )->execute([$flowId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'flow_toggled', 'flow', $flowId);
    } elseif ($action === 'delete' && $flowId > 0) {
        $db->prepare('DELETE FROM flows WHERE id = ? AND company_id = ?')
           ->execute([$flowId, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'flow_deleted', 'flow', $flowId);
        $msg = 'Flow deleted.';
    } elseif ($action === 'seed_fnb' && fnb_module_active($companyId)) {
        // Legacy — kept for backward compat with the webchat quick-setup.
        // New callers should use action=apply_template with template=fnb_ordering.
        $goLive = !empty($_POST['go_live']);
        try {
            $fid = fnb_seed_starter_flow($db, $companyId, (int)$current_user['id'], $goLive);
            log_activity($companyId, (int)$current_user['id'], 'flow_fnb_seeded', 'flow', $fid);
            redirect('/admin/flow_edit.php?id=' . $fid);
        } catch (Throwable $e) {
            $err = 'Could not seed the F&B flow: ' . $e->getMessage();
        }
    } elseif ($action === 'apply_template') {
        // Apply a flow template from the gallery. $goLive comes from a
        // "Ship it live now" checkbox on the confirm dialog; default is
        // false so the flow lands as draft the operator can review.
        $key = (string)($_POST['template'] ?? '');
        $tpl = flow_templates_lookup($key);
        $goLive = !empty($_POST['go_live']);
        if (!$tpl) {
            $err = 'Unknown template.';
        } elseif (!empty($tpl['requires_fnb']) && !fnb_module_active($companyId)) {
            $err = 'This template needs the F&B module enabled.';
        } elseif (!is_callable($tpl['builder'])) {
            $err = 'Template is missing its builder.';
        } else {
            try {
                $fid = call_user_func($tpl['builder'], $db, $companyId, (int)$current_user['id'], $goLive);
                log_activity($companyId, (int)$current_user['id'], 'flow_template_applied', 'flow', $fid, $key);
                redirect('/admin/flow_edit.php?id=' . $fid);
            } catch (Throwable $e) {
                $err = 'Could not apply template: ' . $e->getMessage();
            }
        }
    }
}

$flows = $db->prepare(
    'SELECT f.*,
            (SELECT COUNT(*) FROM flow_instances WHERE flow_id = f.id AND status = "running") AS running,
            (SELECT COUNT(*) FROM flow_instances WHERE flow_id = f.id AND status = "waiting") AS waiting,
            (SELECT COUNT(*) FROM flow_instances WHERE flow_id = f.id AND status = "completed") AS completed,
            (SELECT COUNT(*) FROM flow_instances WHERE flow_id = f.id AND status = "failed") AS failed
     FROM flows f
     WHERE f.company_id = ?
     ORDER BY f.status <> "active", f.updated_at DESC'
);
$flows->execute([$companyId]);
$flows = $flows->fetchAll();

layout_start($current_user, 'Message flows', 'flows');
?>
<div class="card">
  <div class="card-head">
    <h2>Message flows</h2>
  </div>
  <p class="muted small">
    Automated conversations. A flow is a sequence of steps
    (<em>send message</em>, <em>wait for reply</em>, <em>branch</em>,
    <em>assign to department</em>, …) that fires when its trigger matches.
    Currently supports qualification-style flows: ask a customer questions
    and route them based on the answers.
  </p>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" style="display:flex; gap:8px; margin-bottom:8px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" placeholder="New flow name (e.g. Lead qualification)"
           required maxlength="150" style="flex:1; min-width:220px;">
    <button class="btn btn-primary" type="submit">Create</button>
  </form>
  <!-- =====================================================
       TEMPLATE GALLERY - pre-wired starter flows
       ===================================================== -->
  <div style="margin: 14px 0 18px; padding: 14px; background:#faf5ff; border:1px solid #e9d5ff; border-radius:10px;">
    <div style="font-size:14px; font-weight:600; color:#6b21a8; margin-bottom:4px;">🎨 Start from a template</div>
    <p class="muted small" style="margin: 0 0 12px;">
      Pick a pre-wired flow that matches your business — nodes, wording, and branch logic are already
      set up. Ships as <strong>draft</strong> by default so you can review and tweak before flipping
      live. Tick "ship it live now" to skip review and go straight to production.
    </p>
    <div style="display:grid; gap:10px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
      <?php foreach (flow_templates_registry() as $tpl):
        $disabled = !empty($tpl['requires_fnb']) && !fnb_module_active($companyId);
      ?>
        <form method="post"
              onsubmit="return confirm('Apply the &quot;<?= e($tpl['name']) ?>&quot; template?<?= '\n\n' ?>' + (this.go_live.checked ? '✅ Will ship LIVE immediately with a new-conversation trigger.' : '📝 Will land as DRAFT with a keyword trigger — you review and activate.'));"
              style="background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="apply_template">
          <input type="hidden" name="template" value="<?= e($tpl['key']) ?>">

          <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <div style="font-size:26px;"><?= $tpl['icon'] ?></div>
            <div style="flex:1;">
              <div style="font-weight:700; font-size:13.5px; color:#0f172a;"><?= e($tpl['name']) ?></div>
              <div style="font-size:11px; color:#64748b;"><?= e($tpl['category']) ?></div>
            </div>
          </div>
          <p style="font-size:12.5px; color:#334155; line-height:1.4; margin:6px 0 10px; min-height:52px;">
            <?= e($tpl['description']) ?>
          </p>
          <?php if ($disabled): ?>
            <div class="muted small" style="margin-bottom:6px; color:#94a3b8;">
              Requires F&B module — enable in Admin → Workspaces (platform side).
            </div>
            <button class="btn btn-sm" type="submit" disabled style="width:100%; opacity:0.5; cursor:not-allowed;">
              Locked
            </button>
          <?php else: ?>
            <label style="display:flex; align-items:center; gap:6px; font-size:11.5px; color:#64748b; margin-bottom:8px;">
              <input type="checkbox" name="go_live" value="1"> Ship it LIVE now (skip draft review)
            </label>
            <button class="btn btn-primary btn-sm" type="submit" style="width:100%;">
              ✨ Apply this template
            </button>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Trigger</th>
        <th>Status</th>
        <th>Instances</th>
        <th>Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$flows): ?>
        <tr><td colspan="6">
          <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px;">
            <div style="font-weight:600; margin-bottom:8px;">🔀 Build your first flow</div>
            <ul style="margin:0; padding-left:20px; line-height:1.7;">
              <li>Pick a <strong>prebuilt template</strong> above (F&amp;B ordering, Reservation, FAQ router, Lead capture, Furniture showroom, etc.) — one click seeds the whole thing</li>
              <li>Or click <strong>+ New flow</strong> to start from scratch</li>
            </ul>
          </div>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($flows as $f): ?>
        <tr>
          <td><a href="/admin/flow_edit.php?id=<?= (int)$f['id'] ?>"><strong><?= e($f['name']) ?></strong></a></td>
          <td class="muted small">
            <?= e(str_replace('_', ' ', (string)$f['trigger_type'])) ?>
            <?php if ($f['trigger_type'] === 'keyword' && !empty($f['trigger_keywords'])): ?>
              <br><code><?= e((string)$f['trigger_keywords']) ?></code>
            <?php endif; ?>
          </td>
          <td><?= status_badge($f['status']) ?></td>
          <td class="muted small">
            <?= (int)$f['running']   ?> running,
            <?= (int)$f['waiting']   ?> waiting,
            <?= (int)$f['completed'] ?> done,
            <?= (int)$f['failed']    ?> failed
          </td>
          <td class="muted small"><?= e(fmt_dt($f['updated_at'])) ?></td>
          <td class="actions">
            <a class="btn btn-sm" href="/admin/flow_edit.php?id=<?= (int)$f['id'] ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="flow_id" value="<?= (int)$f['id'] ?>">
              <button type="submit" class="btn btn-sm">
                <?= $f['status'] === 'active' ? 'Pause' : 'Activate' ?>
              </button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Delete this flow? All node config is lost. Running instances are cancelled.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="flow_id" value="<?= (int)$f['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
