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
        // Seed a working F&B order-taking flow with all nodes wired up.
        // Operator can then flip trigger_type + status and go live.
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO flows (company_id, name, trigger_type, trigger_keywords, status, created_by_user_id)
                 VALUES (?, "F&B order taking (starter)", "keyword", "order,menu,food,makan", "draft", ?)'
            )->execute([$companyId, (int)$current_user['id']]);
            $fid = (int)$db->lastInsertId();

            // Insert nodes one by one, capturing ids so we can chain them.
            $nins = $db->prepare(
                'INSERT INTO flow_nodes (flow_id, node_type, label, config) VALUES (?, ?, ?, ?)'
            );
            $node = function (string $type, string $label, array $config = []) use ($nins, $fid) {
                $nins->execute([$fid, $type, $label, json_encode($config, JSON_UNESCAPED_UNICODE)]);
                return (int)aiserve_db()->lastInsertId();
            };

            $nWelcome    = $node('send_message',     'Welcome greeting',
                ['text' => "Welcome! 🍽️ Would you like *delivery* or *pickup*?"]);
            $nWaitType   = $node('wait_reply',       'Wait for order type',
                ['var_name' => 'order_type']);
            $nSendMenu   = $node('fnb_send_menu',    'Send menu');
            $nWaitOrder  = $node('wait_reply',       'Wait for order details',
                ['var_name' => 'raw_order']);
            $nCart       = $node('fnb_cart_add',     'AI: parse into cart');
            $nWaitDone   = $node('wait_reply',       'Wait for "done" or more items',
                ['var_name' => 'more_items']);
            $nBranchDone = $node('branch',           'Done or add more?');
            $nAskName    = $node('send_message',     'Ask for customer name',
                ['text' => "Got it. What name should we put on the order?"]);
            $nWaitName   = $node('wait_reply',       'Wait for name',
                ['var_name' => 'customer_name']);
            $nAskAddr    = $node('send_message',     'Ask for address / pickup time',
                ['text' => "Please share your *delivery address* (or *pickup time* if picking up)."]);
            $nWaitAddr   = $node('wait_reply',       'Wait for address',
                ['var_name' => 'delivery_address']);
            $nCreate     = $node('fnb_create_order', 'Create the order');
            $nEnd        = $node('end',              'End');

            // Wire next_node_id — linear default; branch has its own edges.
            $nextMap = [
                $nWelcome    => $nWaitType,
                $nWaitType   => $nSendMenu,
                $nSendMenu   => $nWaitOrder,
                $nWaitOrder  => $nCart,
                $nCart       => $nWaitDone,
                $nWaitDone   => $nBranchDone,
                // branch has no default next; edges below
                $nAskName    => $nWaitName,
                $nWaitName   => $nAskAddr,
                $nAskAddr    => $nWaitAddr,
                $nWaitAddr   => $nCreate,
                $nCreate     => $nEnd,
            ];
            $upd = $db->prepare('UPDATE flow_nodes SET next_node_id = ? WHERE id = ?');
            foreach ($nextMap as $from => $to) $upd->execute([$to, $from]);

            // Branch edges: if reply contains "done" → go to Ask name.
            // Any other reply → back to fnb_cart_add (append more items).
            $eIns = $db->prepare(
                'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $eIns->execute([$fid, $nBranchDone, $nAskName, 'keyword', 'done', 1]);
            $eIns->execute([$fid, $nBranchDone, $nCart,    'default', null,   2]);

            $db->prepare('UPDATE flows SET entry_node_id = ? WHERE id = ?')->execute([$nWelcome, $fid]);
            $db->commit();
            log_activity($companyId, (int)$current_user['id'], 'flow_fnb_seeded', 'flow', $fid);
            redirect('/admin/flow_edit.php?id=' . $fid);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Could not seed the F&B flow: ' . $e->getMessage();
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
  <?php if (fnb_module_active($companyId)): ?>
    <form method="post" style="margin-bottom: 14px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="seed_fnb">
      <button class="btn" type="submit" title="Create a pre-wired F&B order-taking flow (greeting → menu → cart → address → order)">
        🍜 Seed a starter F&amp;B ordering flow
      </button>
      <span class="muted small">
        · Creates a working 13-node flow, triggered by keywords "order / menu / food / makan". Edit + activate after.
      </span>
    </form>
  <?php endif; ?>

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
        <tr><td colspan="6" class="muted">No flows yet — create your first above.</td></tr>
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
