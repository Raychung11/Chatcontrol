<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$conversationId = (int)($_GET['id'] ?? 0);

if ($conversationId <= 0) {
    redirect('/inbox/index.php');
}

$db = aiserve_db();

$stmt = $db->prepare(
    'SELECT c.*, ct.wa_id, ct.display_name, ct.profile_name, ct.phone AS contact_phone,
            u.name AS agent_name, d.name AS department_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     LEFT  JOIN users u ON u.id = c.assigned_user_id
     LEFT  JOIN departments d ON d.id = c.department_id
     WHERE c.id = ? AND c.company_id = ? LIMIT 1'
);
$stmt->execute([$conversationId, $companyId]);
$conv = $stmt->fetch();
if (!$conv) {
    http_response_code(404);
    exit('Conversation not found.');
}
if (!user_can_view_conversation($current_user, $conv)) {
    http_response_code(403);
    exit('Forbidden: you do not have access to this conversation.');
}

// Mark as read for the viewer (best-effort)
if ((int)$conv['unread_count'] > 0) {
    $db->prepare('UPDATE conversations SET unread_count = 0 WHERE id = ?')->execute([$conversationId]);
    $conv['unread_count'] = 0;
}

// Messages
$mstmt = $db->prepare(
    'SELECT m.*, u.name AS sender_name
     FROM messages m
     LEFT JOIN users u ON u.id = m.sender_user_id
     WHERE m.conversation_id = ?
     ORDER BY m.created_at ASC, m.id ASC
     LIMIT 500'
);
$mstmt->execute([$conversationId]);
$messages = $mstmt->fetchAll();

// Internal notes
$nstmt = $db->prepare(
    'SELECT n.*, u.name AS user_name
     FROM internal_notes n
     INNER JOIN users u ON u.id = n.user_id
     WHERE n.conversation_id = ?
     ORDER BY n.created_at ASC LIMIT 200'
);
$nstmt->execute([$conversationId]);
$notes = $nstmt->fetchAll();

// Agents list for assignment dropdown (only managers/admin can change)
$agents = [];
if (user_can_assign($current_user)) {
    $astmt = $db->prepare(
        'SELECT id, name, role FROM users
         WHERE company_id = ? AND status = "active" AND role IN ("agent","manager","super_admin")
         ORDER BY name'
    );
    $astmt->execute([$companyId]);
    $agents = $astmt->fetchAll();
}

// Departments
$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

// Templates (for showing when window expired)
$tstmt = $db->prepare(
    'SELECT id, template_name, language, body_text, status FROM message_templates
     WHERE company_id = ? AND status = "approved" ORDER BY template_name'
);
$tstmt->execute([$companyId]);
$templates = $tstmt->fetchAll();

$windowOpen = is_within_service_window($conv['service_window_expires_at']);

layout_start($current_user, 'Chat · ' . ($conv['display_name'] ?: $conv['wa_id']), 'inbox');
?>
<div class="chat-shell" data-conversation-id="<?= (int)$conv['id'] ?>">

  <section class="chat-main">
    <header class="chat-header">
      <a class="chat-back" href="/inbox/index.php">&larr; Back</a>
      <div class="chat-title">
        <strong><?= e($conv['display_name'] ?: $conv['profile_name'] ?: $conv['wa_id']) ?></strong>
        <span class="muted">+<?= e($conv['wa_id']) ?></span>
      </div>
      <div class="chat-status">
        <?= status_badge($conv['status']) ?>
        <?php if (!$windowOpen): ?>
          <span class="badge badge-failed" title="24-hour service window expired">Window expired</span>
        <?php endif; ?>
      </div>
    </header>

    <div class="chat-stream" id="chat-stream">
      <?php foreach ($messages as $m): ?>
        <?php
          $isOut = $m['direction'] === 'outgoing';
          $cls = $isOut ? 'msg-out' : 'msg-in';
          $cls .= ' status-' . e($m['status']);
        ?>
        <div class="msg <?= $cls ?>">
          <div class="msg-bubble">
            <?php if ($isOut && !empty($m['sender_name'])): ?>
              <div class="msg-sender"><?= e($m['sender_name']) ?></div>
            <?php endif; ?>
            <?php if ($m['message_type'] !== 'text' && $m['message_type'] !== ''): ?>
              <div class="msg-type-tag"><?= e(strtoupper($m['message_type'])) ?>
                <?php if (!empty($m['media_filename'])): ?> · <?= e($m['media_filename']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="msg-body"><?= nl2br(e((string)$m['message_text'])) ?></div>
            <div class="msg-meta">
              <span><?= e(fmt_dt($m['created_at'], 'M j, H:i')) ?></span>
              <?php if ($isOut): ?>
                <span class="msg-status">· <?= e(ucfirst($m['status'])) ?></span>
              <?php endif; ?>
              <?php if ($m['status'] === 'failed' && !empty($m['error_message'])): ?>
                <span class="msg-error" title="<?= e($m['error_message']) ?>">· error</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <footer class="chat-composer">
      <?php if ($conv['status'] === 'closed'): ?>
        <div class="composer-locked">Conversation is closed. Reopen to send messages.</div>
      <?php elseif (!$windowOpen): ?>
        <div class="composer-locked">
          <strong>24-hour reply window expired.</strong>
          Free-text replies are blocked by Meta. Please send an approved template message.
          <?php if ($templates): ?>
            <ul class="template-list">
              <?php foreach ($templates as $t): ?>
                <li><code><?= e($t['template_name']) ?></code> (<?= e($t['language']) ?>) — <?= e(mb_strimwidth($t['body_text'], 0, 80, '…')) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <form id="composer-form" class="composer-form">
          <?= csrf_field() ?>
          <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
          <textarea name="message_text" id="composer-text" rows="2" maxlength="4000"
                    placeholder="Type a reply (the customer will see this on WhatsApp)…" required></textarea>
          <div class="composer-actions">
            <span class="muted small" id="composer-status"></span>
            <button class="btn btn-primary" type="submit">Send</button>
          </div>
        </form>
      <?php endif; ?>
    </footer>
  </section>

  <aside class="chat-side">
    <div class="side-section">
      <h3>Customer</h3>
      <div class="kv"><span>Name</span><strong><?= e($conv['display_name'] ?: $conv['profile_name'] ?: '—') ?></strong></div>
      <div class="kv"><span>WhatsApp ID</span><strong>+<?= e($conv['wa_id']) ?></strong></div>
      <div class="kv"><span>Status</span><strong><?= e(ucfirst($conv['status'])) ?></strong></div>
      <div class="kv"><span>Last customer msg</span><strong><?= e(fmt_dt($conv['last_customer_message_at'])) ?></strong></div>
      <div class="kv"><span>Window expires</span>
        <strong><?= e(fmt_dt($conv['service_window_expires_at'])) ?></strong>
      </div>
    </div>

    <div class="side-section">
      <h3>Assignment</h3>
      <form class="conv-action-form" data-action="assign">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
        <?php if (user_can_assign($current_user)): ?>
          <select name="assigned_user_id">
            <option value="0">— Unassigned —</option>
            <?php foreach ($agents as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ((int)$conv['assigned_user_id'] === (int)$a['id']) ? 'selected' : '' ?>>
                <?= e($a['name']) ?> (<?= e(role_label($a['role'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm" type="submit">Save</button>
        <?php else: ?>
          <div class="muted small">Assigned to: <?= e($conv['agent_name'] ?: 'Unassigned') ?></div>
          <?php if (empty($conv['assigned_user_id']) || (int)$conv['assigned_user_id'] === (int)$current_user['id']): ?>
            <input type="hidden" name="assigned_user_id" value="<?= (int)$current_user['id'] ?>">
            <button class="btn btn-sm" type="submit">Assign to me</button>
          <?php endif; ?>
        <?php endif; ?>
      </form>

      <?php if (user_can_assign($current_user)): ?>
      <form class="conv-action-form" data-action="change_department">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_department">
        <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
        <label class="muted small">Department</label>
        <select name="department_id">
          <option value="0">— None —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= ((int)$conv['department_id'] === (int)$d['id']) ? 'selected' : '' ?>>
              <?= e($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit">Save</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="side-section">
      <h3>Status</h3>
      <form class="conv-action-form" data-action="change_status">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_status">
        <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
        <select name="status">
          <?php foreach (['open','pending','escalated','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $conv['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit">Update</button>
      </form>
    </div>

    <div class="side-section">
      <h3>Internal notes</h3>
      <ul class="note-list">
        <?php if (!$notes): ?>
          <li class="muted small">No notes yet.</li>
        <?php endif; ?>
        <?php foreach ($notes as $n): ?>
          <li>
            <div class="note-meta">
              <strong><?= e($n['user_name']) ?></strong>
              <span class="muted small"><?= e(fmt_dt($n['created_at'])) ?></span>
            </div>
            <div class="note-body"><?= nl2br(e($n['note_text'])) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <form class="conv-action-form" data-action="add_note">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
        <textarea name="note_text" rows="2" placeholder="Add an internal note (not visible to customer)"></textarea>
        <button class="btn btn-sm" type="submit">Add note</button>
      </form>
    </div>
  </aside>

</div>
<?php layout_end(); ?>
