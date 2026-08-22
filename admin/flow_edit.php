<?php
/**
 * Flow editor — form-based (visual canvas ships in a follow-up).
 *
 * Layout:
 *   1. Trigger + status form (name, trigger_type, keywords, entry node).
 *   2. Nodes list — one row per node. Each row edits its own node
 *      (type, config, next_node).
 *   3. Branch edges — for any branch node, a secondary sub-form to
 *      manage its outgoing edges (keyword / default -> to_node_id).
 *   4. "Add node" button at the bottom.
 *
 * All mutations go through this single page; save = full form POST.
 * The engine reads the same rows the next time a trigger fires.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/flow_visual.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$flowId = (int)($_GET['id'] ?? 0);
if ($flowId <= 0) redirect('/admin/flows.php');

$fStmt = $db->prepare('SELECT * FROM flows WHERE id = ? AND company_id = ? LIMIT 1');
$fStmt->execute([$flowId, $companyId]);
$flow = $fStmt->fetch();
if (!$flow) { http_response_code(404); exit('Flow not found.'); }

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_flow') {
        $name    = trim((string)($_POST['name'] ?? ''));
        $trigger = (string)($_POST['trigger_type'] ?? 'new_conversation');
        $kws     = trim((string)($_POST['trigger_keywords'] ?? ''));
        $entryId = (int)($_POST['entry_node_id'] ?? 0);
        $status  = (string)($_POST['status'] ?? 'draft');
        if (!in_array($trigger, ['new_conversation','keyword','manual'], true)) $trigger = 'new_conversation';
        if (!in_array($status,  ['draft','active','paused'], true)) $status = 'draft';
        if ($name === '') { $err = 'Name is required.'; }
        else {
            $db->prepare(
                'UPDATE flows
                 SET name = ?, trigger_type = ?, trigger_keywords = ?, entry_node_id = ?, status = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([$name, $trigger, $kws ?: null, $entryId ?: null, $status, $flowId, $companyId]);
            $msg = 'Flow saved.';
        }
    } elseif ($action === 'add_node') {
        $ins = $db->prepare(
            'INSERT INTO flow_nodes (flow_id, node_type, config) VALUES (?, "send_message", ?)'
        );
        $ins->execute([$flowId, json_encode(['text' => 'Hi 👋'], JSON_UNESCAPED_UNICODE)]);
        $msg = 'Node added.';
    } elseif ($action === 'save_node') {
        $nodeId = (int)($_POST['node_id'] ?? 0);
        $type   = (string)($_POST['node_type'] ?? 'send_message');
        $label  = trim((string)($_POST['label'] ?? '')) ?: null;
        $nextId = (int)($_POST['next_node_id'] ?? 0);
        if (!in_array($type, ['send_message','wait_reply','branch','assign_dept','assign_branch','assign_nearest_branch','save_note','end',
                               'fnb_send_menu','fnb_cart_add','fnb_cart_show','fnb_create_order','fnb_order_status'], true)) {
            $type = 'send_message';
        }
        $cfg = flow_edit_pack_config($type, $_POST);
        $db->prepare(
            'UPDATE flow_nodes
             SET node_type = ?, label = ?, config = ?, next_node_id = ?
             WHERE id = ? AND flow_id = ?'
        )->execute([$type, $label, $cfg, $nextId ?: null, $nodeId, $flowId]);
        $msg = 'Node saved.';
    } elseif ($action === 'delete_node') {
        $nodeId = (int)($_POST['node_id'] ?? 0);
        $db->prepare('DELETE FROM flow_nodes WHERE id = ? AND flow_id = ?')
           ->execute([$nodeId, $flowId]);
        // Clear entry_node_id if it pointed here.
        $db->prepare('UPDATE flows SET entry_node_id = NULL WHERE id = ? AND entry_node_id = ?')
           ->execute([$flowId, $nodeId]);
        $msg = 'Node deleted.';
    } elseif ($action === 'save_edges') {
        $nodeId = (int)($_POST['node_id'] ?? 0);
        // Wipe + re-insert branch edges from the sub-form.
        $db->prepare('DELETE FROM flow_edges WHERE from_node_id = ?')->execute([$nodeId]);
        $ins = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $rows = (array)($_POST['edge'] ?? []);
        foreach ($rows as $i => $r) {
            $toId  = (int)($r['to_node_id'] ?? 0);
            $ctype = (string)($r['condition_type'] ?? 'keyword');
            $cval  = trim((string)($r['condition_value'] ?? ''));
            if ($toId <= 0) continue;
            if (!in_array($ctype, ['keyword','default'], true)) $ctype = 'keyword';
            if ($ctype === 'keyword' && $cval === '') continue;
            $ins->execute([$flowId, $nodeId, $toId, $ctype, $cval ?: null, (int)$i]);
        }
        $msg = 'Branch edges saved.';
    }

    if ($msg) {
        // Reload the flow so the just-saved fields render.
        $fStmt->execute([$flowId, $companyId]);
        $flow = $fStmt->fetch();
    }
}

// Load nodes + departments + edges-by-node.
$nodes = $db->prepare('SELECT * FROM flow_nodes WHERE flow_id = ? ORDER BY id ASC');
$nodes->execute([$flowId]);
$nodes = $nodes->fetchAll();

$edges = $db->prepare('SELECT * FROM flow_edges WHERE flow_id = ? ORDER BY from_node_id, sort_order');
$edges->execute([$flowId]);
$edgesByNode = [];
foreach ($edges->fetchAll() as $e) {
    $edgesByNode[(int)$e['from_node_id']][] = $e;
}

$depts = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$depts->execute([$companyId]);
$depts = $depts->fetchAll();

// Branches for the "Assign to branch" node type — empty if the workspace
// hasn't set any up yet (the node still saves but the picker sits empty).
// address + area_keywords are only used by the assign_nearest_branch
// editor's data-gap warning, but selecting all costs nothing here.
$branches = $db->prepare(
    'SELECT id, name, address, area_keywords
     FROM branches WHERE company_id = ? AND status = "active" ORDER BY name'
);
$branches->execute([$companyId]);
$branches = $branches->fetchAll();

layout_start($current_user, 'Edit flow · ' . $flow['name'], 'flows');
?>
<div class="card">
  <div class="card-head">
    <h2>Edit flow · <?= e($flow['name']) ?></h2>
    <a class="btn" href="/admin/flows.php">← All flows</a>
  </div>
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_flow">
    <label>Name
      <input type="text" name="name" required maxlength="150" value="<?= e($flow['name']) ?>">
    </label>
    <label>Trigger
      <select name="trigger_type" onchange="document.getElementById('kw-row').style.display = (this.value === 'keyword') ? '' : 'none';">
        <?php foreach (['new_conversation' => 'When a new conversation opens',
                        'keyword'          => 'When a message contains keyword(s)',
                        'manual'           => 'Manual (started from the chat by an agent)'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $flow['trigger_type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div id="kw-row" style="<?= $flow['trigger_type'] === 'keyword' ? '' : 'display:none;' ?>">
      <label>Trigger keywords
        <input type="text" name="trigger_keywords" maxlength="500" value="<?= e((string)($flow['trigger_keywords'] ?? '')) ?>"
               placeholder="menu, help, sales">
        <small class="muted">Comma-separated. Case-insensitive substring match on the customer's message.</small>
      </label>
    </div>
    <label>Entry node <small class="muted">(the first step the flow runs)</small>
      <select name="entry_node_id">
        <option value="0">— pick a node —</option>
        <?php foreach ($nodes as $n): ?>
          <option value="<?= (int)$n['id'] ?>" <?= (int)$flow['entry_node_id'] === (int)$n['id'] ? 'selected' : '' ?>>
            #<?= (int)$n['id'] ?> <?= e(flow_edit_node_label($n)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <?php foreach (['draft' => 'Draft (not running)',
                        'active' => 'Active (running)',
                        'paused' => 'Paused'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $flow['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div>
      <button class="btn btn-primary" type="submit">Save flow</button>
    </div>
  </form>
</div>

<!-- =====================================================
     VISUAL OVERVIEW - top-down SVG flowchart, click to jump
     ===================================================== -->
<div class="card">
  <div class="card-head">
    <h2>🗺 Visual overview</h2>
    <span class="muted small">Click any node to jump to its edit row below. Entry node is marked ★.</span>
  </div>
  <?= flow_visual_render($nodes, $edgesByNode, (int)($flow['entry_node_id'] ?? 0)) ?>
  <div class="muted small" style="margin-top:10px; display:flex; gap:14px; flex-wrap:wrap;">
    <span><span style="display:inline-block;width:10px;height:10px;background:#dbeafe;border:1.5px solid #3b82f6;border-radius:2px;vertical-align:middle;"></span> send msg</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#fef3c7;border:1.5px solid #f59e0b;border-radius:2px;vertical-align:middle;"></span> wait reply</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#f3e8ff;border:1.5px solid #a855f7;border-radius:2px;vertical-align:middle;"></span> branch</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#ccfbf1;border:1.5px solid #14b8a6;border-radius:2px;vertical-align:middle;"></span> assign</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#cffafe;border:1.5px solid #0891b2;border-radius:2px;vertical-align:middle;"></span> 🗺 AI-map</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#d1fae5;border:1.5px solid #10b981;border-radius:2px;vertical-align:middle;"></span> F&amp;B</span>
    <span><span style="display:inline-block;width:10px;height:10px;background:#fee2e2;border:1.5px solid #ef4444;border-radius:2px;vertical-align:middle;"></span> end</span>
    <span style="margin-left:auto;">Dashed edge = default / else branch</span>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Nodes</h2>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_node">
      <button type="submit" class="btn btn-primary">+ Add node</button>
    </form>
  </div>
  <p class="muted small">
    Each node is a step. Use <code>{{var_name}}</code> in text templates to inject
    something the customer replied earlier (variables come from
    <em>Wait for reply</em> nodes).
  </p>

  <?php if (!$nodes): ?>
    <p class="muted">No nodes yet — click <strong>+ Add node</strong>.</p>
  <?php endif; ?>

  <?php foreach ($nodes as $n): ?>
    <?php $cfg = json_decode((string)($n['config'] ?? ''), true) ?: []; ?>
    <div id="node-<?= (int)$n['id'] ?>"
         style="border:1px solid var(--c-border); border-radius:8px; padding:14px; margin: 10px 0; scroll-margin-top: 20px;">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_node">
        <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div><strong>Node #<?= (int)$n['id'] ?></strong>
            <?php if ((int)$flow['entry_node_id'] === (int)$n['id']): ?>
              <span class="badge badge-open" style="margin-left:6px;">entry</span>
            <?php endif; ?>
          </div>
          <div>
            <label style="display:inline-flex; align-items:center; gap:6px;">
              Type
              <select name="node_type" onchange="this.form.submit()">
                <?php foreach ([
                  'Basic' => [
                    'send_message' => 'Send message',
                    'wait_reply'   => 'Wait for reply',
                    'branch'       => 'Branch',
                  ],
                  'Assign &amp; save' => [
                    'assign_dept'           => 'Assign to department',
                    'assign_branch'         => 'Assign to branch',
                    'assign_nearest_branch' => '🗺 Assign to nearest branch (AI)',
                    'save_note'             => 'Save internal note',
                  ],
                  'Terminal' => [
                    'end' => 'End',
                  ],
                ] as $groupLabel => $opts): ?>
                  <optgroup label="<?= $groupLabel ?>">
                    <?php foreach ($opts as $k => $v): ?>
                      <option value="<?= $k ?>" <?= $n['node_type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
                <?php if (fnb_module_active($companyId)): ?>
                  <optgroup label="🍜 F&amp;B">
                    <?php foreach ([
                      'fnb_send_menu'    => 'Send menu to customer',
                      'fnb_cart_add'     => 'AI: parse reply into cart',
                      'fnb_cart_show'    => 'Send current cart',
                      'fnb_create_order' => 'Create the order',
                      'fnb_order_status' => '🔎 Reply with order status',
                    ] as $k => $v): ?>
                      <option value="<?= $k ?>" <?= $n['node_type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              </select>
            </label>
          </div>
        </div>

        <label>Label <small class="muted">(optional, for your reference)</small>
          <input type="text" name="label" maxlength="120" value="<?= e((string)($n['label'] ?? '')) ?>"
                 placeholder="e.g. Ask for name">
        </label>

        <?php switch ($n['node_type']):
          case 'send_message': ?>
            <label>Message text
              <textarea name="text" rows="3" maxlength="4000"
                        placeholder="Hi! What's your name?"><?= e((string)($cfg['text'] ?? '')) ?></textarea>
              <small class="muted">Supports <code>{{var_name}}</code> from earlier Wait-for-reply nodes.</small>
            </label>
            <?php break; ?>

          <?php case 'wait_reply':
            $waitVarName = trim((string)($cfg['var_name'] ?? ''));
            $waitVarEcho = $waitVarName !== '' ? $waitVarName : 'customer_name';
          ?>
            <label>Save the reply into variable
              <input type="text" name="var_name" maxlength="60"
                     value="<?= e($waitVarName) ?>"
                     placeholder="customer_name"
                     data-wait-var-input>
              <small class="muted">letters, digits, underscore. Reference it later as
                <code data-wait-var-echo>{{<?= e($waitVarEcho) ?>}}</code>.</small>
            </label>
            <script>
              (function () {
                var inp = document.querySelector('[data-wait-var-input]');
                var echo = document.querySelector('[data-wait-var-echo]');
                if (!inp || !echo) return;
                inp.addEventListener('input', function () {
                  var v = (inp.value || '').replace(/[^A-Za-z0-9_]/g, '');
                  echo.textContent = '{{' + (v || 'customer_name') + '}}';
                });
              })();
            </script>
            <?php break; ?>

          <?php case 'assign_dept': ?>
            <label>Department
              <select name="department_id">
                <option value="0">— pick a department —</option>
                <?php foreach ($depts as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= (int)($cfg['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                    <?= e($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php break; ?>

          <?php case 'assign_branch': ?>
            <label>Branch
              <select name="branch_id">
                <option value="0">— pick a branch —</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>" <?= (int)($cfg['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                    <?= e($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="muted">
                Tags the CONTACT with this branch — every future conversation
                from this customer routes there too, and rotation picks
                agents whose primary branch matches.
                <?php if (!$branches): ?>
                  <br><strong>Heads-up:</strong> no active branches yet — set them up in
                  <a href="/admin/branches.php">Settings → Branches</a> first.
                <?php endif; ?>
              </small>
            </label>
            <?php break; ?>

          <?php case 'assign_nearest_branch': ?>
            <label>Location source variable <small class="muted">(optional — defaults to the customer's last reply)</small>
              <input type="text" name="location_var" maxlength="60"
                     value="<?= e((string)($cfg['location_var'] ?? '')) ?>"
                     placeholder="customer_location">
              <small class="muted">
                If you captured the customer's area with a <em>Wait for reply</em>
                node into a variable (say <code>customer_location</code>), name
                it here. Leave blank to use whatever the customer typed most
                recently.
              </small>
            </label>
            <label>Fallback branch <small class="muted">(if AI can't decide)</small>
              <select name="fallback_branch_id">
                <option value="0">— no fallback (leave contact unassigned) —</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>" <?= (int)($cfg['fallback_branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                    <?= e($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="muted small" style="padding:8px 10px; background:#f0f9ff; border-left:3px solid #0072B2; border-radius:4px;">
              🗺 <strong>How it works:</strong> Claude reads the customer's location text
              + all your active branches (name, address, area keywords) and picks
              the closest one. Then tags the contact's branch and exposes
              <code>{{assigned_branch_name}}</code>, <code>{{assigned_branch_address}}</code>,
              and <code>{{assigned_branch_id}}</code> as variables you can use in
              downstream Send-message nodes (e.g. <em>"Routed you to {{assigned_branch_name}}
              at {{assigned_branch_address}} 🙌"</em>).
              <?php if (!$branches): ?>
                <br><br><strong>⚠ Prereq:</strong> set up branches at
                <a href="/admin/branches.php">Settings → Branches</a>, and fill in each
                branch's <em>address</em> and <em>area keywords</em> — that's what the AI
                matches against.
              <?php else:
                $missing = 0;
                foreach ($branches as $bx) {
                    if (empty($bx['address']) && empty($bx['area_keywords'])) $missing++;
                }
                if ($missing > 0): ?>
                <br><br><strong>⚠ Data gap:</strong> <?= $missing ?> of <?= count($branches) ?>
                branch(es) have no address AND no area keywords. Claude can only
                guess from the name for those. Fill them in on
                <a href="/admin/branches.php">Settings → Branches</a> for better mapping.
              <?php endif; endif; ?>
            </div>
            <?php break; ?>

          <?php case 'save_note': ?>
            <label>Note template
              <textarea name="template" rows="3" maxlength="2000"
                        placeholder="Qualification: name={{customer_name}}, budget={{budget}}"><?= e((string)($cfg['template'] ?? '')) ?></textarea>
              <small class="muted">Rendered with the collected variables and dropped as an internal note.</small>
            </label>
            <?php break; ?>

          <?php case 'branch': ?>
            <div class="muted small">Branch has no config — use the "Edges" panel below to route.</div>
            <?php break; ?>

          <?php case 'end': ?>
            <div class="muted small">End marks the instance completed.</div>
            <?php break; ?>

          <?php case 'fnb_send_menu': ?>
            <div class="muted small">
              Sends the workspace's active menu as a WhatsApp text
              message (grouped by category, numbered items). Advances
              automatically. Configure the menu itself at
              <a href="/admin/fnb_menu.php">F&amp;B → Menu</a>.
            </div>
            <?php break; ?>

          <?php case 'fnb_cart_add': ?>
            <div class="muted small">
              Uses Claude to interpret the customer's last reply against
              the current menu AND the running cart. Detects intent and
              acts:
              <ul style="margin: 6px 0 0 20px;">
                <li><strong>Add</strong> — <em>"2 chicken rice, 1 nasi lemak less spicy"</em> → appends items</li>
                <li><strong>Remove</strong> — <em>"remove item 2"</em> / <em>"take out the nasi lemak"</em> → drops those cart lines</li>
                <li><strong>Clear</strong> — <em>"clear cart"</em> / <em>"start over"</em> → empties the cart</li>
                <li><strong>Unclear</strong> → asks a clarification and re-enters wait state</li>
              </ul>
              Requires the workspace's Anthropic API key in
              <a href="/admin/ai_settings.php">AI settings</a>. Cart lines are
              rendered with 1-based numbers so the customer can reference
              them (<em>"remove #3"</em>).
            </div>
            <?php break; ?>

          <?php case 'fnb_cart_show': ?>
            <div class="muted small">
              Sends the current cart contents back to the customer as a
              summary message. No config.
            </div>
            <?php break; ?>

          <?php case 'fnb_create_order': ?>
            <label>Default order type <small class="muted">(when the customer didn't pick)</small>
              <select name="default_order_type">
                <?php $dft = (string)($cfg['default_order_type'] ?? ''); ?>
                <option value=""         <?= $dft === ''         ? 'selected' : '' ?>>— use customer's answer, fall back to delivery —</option>
                <option value="delivery" <?= $dft === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                <option value="pickup"   <?= $dft === 'pickup'   ? 'selected' : '' ?>>Self-pickup</option>
                <option value="dine_in"  <?= $dft === 'dine_in'  ? 'selected' : '' ?>>🍽 Dine-in / in-store</option>
              </select>
              <small class="muted">
                For a dine-in flow (customer scanned a table QR), set this to <em>Dine-in</em>
                so the order lands with the correct type even when your flow skips the delivery/pickup question.
              </small>
            </label>
            <div class="muted small" style="margin-top:6px;">
              Materializes the cart + captured vars into an F&amp;B order
              row (visible on the <a href="/admin/fnb_orders.php">kanban dashboard</a>),
              stamps the order number, links this conversation as the source,
              and sends a "🎉 Order confirmed" reply with the number.<br>
              Expects these vars to have been captured earlier:
              <code>order_type</code> (delivery / pickup / dine_in),
              <code>customer_name</code>, <code>delivery_address</code>
              (if delivery), <code>pickup_time</code> (if pickup),
              <code>table_number</code> (if dine-in — the widget's ?t= URL
              param pre-fills this).
            </div>
            <?php break; ?>

          <?php case 'fnb_order_status': ?>
            <div class="muted small">
              Looks up this customer's most recent F&amp;B order and replies
              with a friendly status line + ETA — "⏳ We got your order",
              "👨‍🍳 Being prepared right now", "🎉 Your order is ready…",
              etc. Falls back to a "no recent order found" message if
              nothing matches. Best used as the entry node of a
              keyword-triggered flow ("status", "ready?", "mana dah").
            </div>
            <?php break; ?>
        <?php endswitch; ?>

        <?php if ($n['node_type'] !== 'branch' && $n['node_type'] !== 'end'): ?>
          <?php
            // Nodes that collect data or produce state expect a downstream
            // consumer. Warn if the operator is about to save an "end after
            // this step" on one of them — that's how F&B ordering flows
            // silently died right after the address prompt.
            $expectsDownstream = in_array($n['node_type'], [
                'wait_reply','assign_dept','assign_branch','assign_nearest_branch',
                'fnb_cart_add','fnb_send_menu',
            ], true);
            $currentNextId = (int)($n['next_node_id'] ?? 0);
          ?>
          <label>Next node
            <select name="next_node_id" data-next-node-select>
              <option value="0">— end after this step —</option>
              <?php foreach ($nodes as $n2):
                if ((int)$n2['id'] === (int)$n['id']) continue; ?>
                <option value="<?= (int)$n2['id'] ?>" <?= $currentNextId === (int)$n2['id'] ? 'selected' : '' ?>>
                  #<?= (int)$n2['id'] ?> <?= e(flow_edit_node_label($n2)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($expectsDownstream): ?>
            <div data-next-node-warn
                 style="margin:-8px 0 10px 0; padding:8px 10px; border-radius:6px;
                        background:#fef3c7; border:1px solid #fcd34d; color:#78350f;
                        font-size:13px; <?= $currentNextId === 0 ? '' : 'display:none;' ?>">
              ⚠ This <strong><?= e($n['node_type']) ?></strong> node captures/produces state that
              downstream nodes usually need. Ending the flow here means nothing consumes it —
              likely wire this to the next step instead.
            </div>
            <script>
              (function () {
                var sel  = document.querySelector('[data-next-node-select]');
                var warn = document.querySelector('[data-next-node-warn]');
                if (!sel || !warn) return;
                sel.addEventListener('change', function () {
                  warn.style.display = (parseInt(sel.value, 10) === 0) ? '' : 'none';
                });
              })();
            </script>
          <?php endif; ?>
        <?php endif; ?>

        <div style="display:flex; gap:6px;">
          <button class="btn btn-primary btn-sm" type="submit">Save node</button>
          <button class="btn btn-sm btn-danger" type="submit"
                  formaction="?id=<?= $flowId ?>"
                  onclick="if(!confirm('Delete this node?')){return false;} this.form.querySelector('input[name=action]').value='delete_node';">
            Delete node
          </button>
        </div>
      </form>

      <?php if ($n['node_type'] === 'branch'): ?>
        <form method="post" style="margin-top:12px; padding-top:12px; border-top:1px dashed var(--c-border);">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_edges">
          <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
          <div class="muted small" style="margin-bottom:6px;">Edges from this branch:</div>
          <table class="data-table" style="margin-bottom:6px;">
            <thead><tr><th>#</th><th>Condition</th><th>Keyword</th><th>Go to node</th></tr></thead>
            <tbody>
              <?php
                $ee = $edgesByNode[(int)$n['id']] ?? [];
                $rowCount = max(4, count($ee) + 1);
                for ($i = 0; $i < $rowCount; $i++):
                    $e = $ee[$i] ?? ['condition_type' => '', 'condition_value' => '', 'to_node_id' => 0];
              ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td>
                    <select name="edge[<?= $i ?>][condition_type]">
                      <option value="">— skip —</option>
                      <option value="keyword" <?= $e['condition_type'] === 'keyword' ? 'selected' : '' ?>>If reply contains</option>
                      <option value="default" <?= $e['condition_type'] === 'default' ? 'selected' : '' ?>>Default (no match)</option>
                    </select>
                  </td>
                  <td><input type="text" name="edge[<?= $i ?>][condition_value]" maxlength="255" value="<?= e((string)($e['condition_value'] ?? '')) ?>" placeholder="e.g. 1 or sales"></td>
                  <td>
                    <select name="edge[<?= $i ?>][to_node_id]">
                      <option value="0">—</option>
                      <?php foreach ($nodes as $n2): ?>
                        <option value="<?= (int)$n2['id'] ?>" <?= (int)($e['to_node_id'] ?? 0) === (int)$n2['id'] ? 'selected' : '' ?>>
                          #<?= (int)$n2['id'] ?> <?= e(flow_edit_node_label($n2)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
          <button class="btn btn-sm" type="submit">Save edges</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php layout_end(); ?>

<?php
function flow_edit_node_label(array $n): string
{
    $lbl = trim((string)($n['label'] ?? ''));
    if ($lbl !== '') return $lbl;
    $map = [
        'send_message'     => 'Send message',
        'wait_reply'       => 'Wait for reply',
        'branch'           => 'Branch',
        'assign_dept'      => 'Assign to dept',
        'assign_branch'    => 'Assign to branch',
        'assign_nearest_branch' => '🗺 Assign to nearest branch',
        'save_note'        => 'Save note',
        'end'              => 'End',
        'fnb_send_menu'    => 'F&B · Send menu',
        'fnb_cart_add'     => 'F&B · AI add to cart',
        'fnb_cart_show'    => 'F&B · Show cart',
        'fnb_create_order' => 'F&B · Create order',
        'fnb_order_status' => 'F&B · Order status',
    ];
    return $map[$n['node_type']] ?? (string)$n['node_type'];
}

function flow_edit_pack_config(string $type, array $post): string
{
    switch ($type) {
        case 'send_message':
            return json_encode(['text' => (string)($post['text'] ?? '')], JSON_UNESCAPED_UNICODE);
        case 'wait_reply':
            $v = trim((string)($post['var_name'] ?? ''));
            $v = preg_replace('/[^a-zA-Z0-9_]/', '', $v);
            return json_encode(['var_name' => $v], JSON_UNESCAPED_UNICODE);
        case 'assign_dept':
            return json_encode(['department_id' => (int)($post['department_id'] ?? 0)], JSON_UNESCAPED_UNICODE);
        case 'assign_branch':
            return json_encode(['branch_id' => (int)($post['branch_id'] ?? 0)], JSON_UNESCAPED_UNICODE);
        case 'assign_nearest_branch':
            $lv = trim((string)($post['location_var'] ?? ''));
            $lv = preg_replace('/[^a-zA-Z0-9_]/', '', $lv);
            return json_encode([
                'location_var'       => $lv,
                'fallback_branch_id' => (int)($post['fallback_branch_id'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        case 'save_note':
            return json_encode(['template' => (string)($post['template'] ?? '')], JSON_UNESCAPED_UNICODE);
        case 'fnb_create_order':
            $t = (string)($post['default_order_type'] ?? '');
            if (!in_array($t, ['delivery', 'pickup', 'dine_in'], true)) $t = '';
            return json_encode(['default_order_type' => $t], JSON_UNESCAPED_UNICODE);
        case 'branch':
        case 'end':
        default:
            return '{}';
    }
}
?>
