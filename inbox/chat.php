<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/provider.php';
require_once __DIR__ . '/../inc/chat_render.php';

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
    'SELECT id, template_name, language, body_text, variables_json, status FROM message_templates
     WHERE company_id = ? AND status = "approved" ORDER BY template_name'
);
$tstmt->execute([$companyId]);
$templates = $tstmt->fetchAll();

// Attached tags
$tagsAttached = $db->prepare(
    'SELECT t.id, t.name, t.color FROM conversation_tag_map m
     INNER JOIN conversation_tags t ON t.id = m.tag_id
     WHERE m.conversation_id = ? AND t.company_id = ?
     ORDER BY t.name'
);
$tagsAttached->execute([$conversationId, $companyId]);
$tagsAttached = $tagsAttached->fetchAll();
$attachedIds  = array_map(fn($t) => (int)$t['id'], $tagsAttached);

// All tags (for picker)
$tagsAll = $db->prepare('SELECT id, name, color FROM conversation_tags WHERE company_id = ? ORDER BY name');
$tagsAll->execute([$companyId]);
$tagsAll = $tagsAll->fetchAll();

$company = load_company_settings($companyId) ?: [];
// Provider is per-CHANNEL since the multi-channel refactor. Reading
// companies.provider used to show the register.php default ("cloud_api")
// even on aiserve_chatbot channels, incorrectly triggering the
// "24-hour reply window expired" warning on chats that have no such
// restriction. Resolve the actual channel this conversation runs on.
require_once __DIR__ . '/../inc/channels.php';
$chatChannel = channel_for_conversation($conv);
$providerCtx = $chatChannel ?: $company;
$enforceWindow = provider_enforces_24h_window($providerCtx);
$supportsTemplates = provider_supports_templates($providerCtx);
$windowOpen = !$enforceWindow || is_within_service_window($conv['service_window_expires_at']);

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
        <?php if ($enforceWindow && !$windowOpen): ?>
          <span class="badge badge-failed" title="24-hour service window expired">Window expired</span>
        <?php endif; ?>
        <button type="button" class="chat-side-toggle" id="chat-side-toggle" aria-label="Conversation info">ⓘ</button>
      </div>
    </header>

    <?php $lastMsgId = $messages ? (int)end($messages)['id'] : 0; ?>
    <div class="chat-stream" id="chat-stream" data-last-msg-id="<?= $lastMsgId ?>">
      <?php foreach ($messages as $m): ?>
        <?= message_bubble_html($m) ?>
      <?php endforeach; ?>
    </div>

    <footer class="chat-composer">
      <?php
        // Build a JSON-safe templates payload for the front end.
        $tplPayload = array_map(function ($t) {
            preg_match_all('/\{\{(\d+)\}\}/', (string)$t['body_text'], $m);
            $count = $m[1] ? max(array_map('intval', $m[1])) : 0;
            return [
                'id'       => (int)$t['id'],
                'name'     => $t['template_name'],
                'language' => $t['language'],
                'body'     => $t['body_text'],
                'vars'     => $count,
            ];
        }, $templates);
      ?>

      <?php if ($conv['status'] === 'closed'): ?>
        <div class="composer-locked">Conversation is closed. Reopen to send messages.</div>
      <?php elseif (!$windowOpen): ?>
        <div class="composer-locked">
          <strong>24-hour reply window expired.</strong>
          Free-text replies are blocked by Meta. Send an approved template message instead.
          <?php if (!$templates): ?>
            <p class="muted small">No approved templates yet. Add some in Admin → Templates.</p>
          <?php endif; ?>
        </div>
        <?php if ($templates): ?>
          <form id="template-form" class="composer-form" data-templates='<?= e(json_encode($tplPayload, JSON_UNESCAPED_UNICODE)) ?>'>
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
            <select name="template_id" id="template-picker" required>
              <option value="">Choose template…</option>
              <?php foreach ($templates as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['template_name']) ?> (<?= e($t['language']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div id="template-vars" class="template-vars"></div>
            <div id="template-preview" class="template-preview muted small"></div>
            <div class="composer-actions">
              <span class="muted small" id="template-status"></span>
              <button class="btn btn-primary" type="submit">Send template</button>
            </div>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <?php $aiEnabled = !empty($company['ai_enabled']); $aiAuto = !empty($company['ai_auto_suggest']); ?>
        <form id="composer-form" class="composer-form"
              data-ai-enabled="<?= $aiEnabled ? '1' : '0' ?>"
              data-ai-auto="<?= $aiAuto ? '1' : '0' ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
          <?php if ($aiEnabled): ?>
            <div id="ai-draft" class="ai-draft hidden">
              <div class="ai-draft-head">
                <strong>🤖 AI suggested reply</strong>
                <span class="muted small" id="ai-draft-meta"></span>
              </div>
              <div class="ai-draft-body" id="ai-draft-body"></div>
              <div class="ai-draft-actions">
                <button type="button" class="btn btn-sm btn-primary" id="ai-draft-use">Use this</button>
                <button type="button" class="btn btn-sm" id="ai-draft-regen">Regenerate</button>
                <button type="button" class="btn btn-sm" id="ai-draft-dismiss">Dismiss</button>
              </div>
            </div>
          <?php endif; ?>
          <textarea name="message_text" id="composer-text" rows="2" maxlength="4000"
                    placeholder="Type a reply (the customer will see this on WhatsApp)…" required></textarea>
          <div class="composer-actions">
            <div class="composer-extras">
              <?php if ($aiEnabled): ?>
                <button type="button" class="btn btn-sm" id="ai-suggest-btn" title="Get an AI draft for the customer's last message">🤖 AI suggest</button>
              <?php endif; ?>
              <?php if ($templates && $supportsTemplates): ?>
                <button type="button" class="btn btn-sm" id="open-template-picker">Send template</button>
              <?php endif; ?>
              <label class="btn btn-sm" for="media-input">📎 Attach</label>
              <input type="file" id="media-input" name="media" hidden
                     accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
              <button type="button" class="btn btn-sm" id="voice-record-btn"
                      title="Record a voice message">🎙 Record</button>
              <span class="muted small" id="media-status"></span>
            </div>
            <div>
              <span class="muted small" id="composer-status"></span>
              <button class="btn btn-primary" type="submit">Send</button>
            </div>
          </div>
        </form>

        <?php if ($templates && $supportsTemplates): ?>
          <form id="template-form" class="composer-form hidden" data-templates='<?= e(json_encode($tplPayload, JSON_UNESCAPED_UNICODE)) ?>'>
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
            <div class="template-head">
              <strong>Send approved template</strong>
              <button type="button" class="btn btn-sm" id="close-template-picker">Cancel</button>
            </div>
            <select name="template_id" id="template-picker" required>
              <option value="">Choose template…</option>
              <?php foreach ($templates as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['template_name']) ?> (<?= e($t['language']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div id="template-vars" class="template-vars"></div>
            <div id="template-preview" class="template-preview muted small"></div>
            <div class="composer-actions">
              <span class="muted small" id="template-status"></span>
              <button class="btn btn-primary" type="submit">Send template</button>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </footer>
  </section>

  <aside class="chat-side" id="chat-side">
    <button type="button" class="chat-side-close" id="chat-side-close" aria-label="Close">×</button>
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
      <h3>Tags</h3>
      <div class="tag-list" id="tag-list" data-conversation-id="<?= (int)$conv['id'] ?>">
        <?php foreach ($tagsAttached as $t): ?>
          <span class="tag-chip" data-tag-id="<?= (int)$t['id'] ?>" style="background: <?= e($t['color']) ?>">
            <?= e($t['name']) ?>
            <button type="button" class="tag-chip-x" aria-label="Remove tag" title="Remove">&times;</button>
          </span>
        <?php endforeach; ?>
        <?php if (!$tagsAttached): ?>
          <span class="muted small" data-empty>No tags yet.</span>
        <?php endif; ?>
      </div>
      <?php if ($tagsAll): ?>
        <form class="tag-add-form" id="tag-add-form" data-conversation-id="<?= (int)$conv['id'] ?>">
          <?= csrf_field() ?>
          <select name="tag_id">
            <option value="">+ Add tag…</option>
            <?php foreach ($tagsAll as $t): if (in_array((int)$t['id'], $attachedIds, true)) continue; ?>
              <option value="<?= (int)$t['id'] ?>" data-color="<?= e($t['color']) ?>" data-name="<?= e($t['name']) ?>">
                <?= e($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm" type="submit">Add</button>
        </form>
      <?php else: ?>
        <p class="muted small">No tags configured. Create them in Admin → Tags.</p>
      <?php endif; ?>
    </div>

    <?php if (!empty($company['ai_enabled'])): ?>
    <div class="side-section">
      <h3>Handover summary</h3>
      <p class="muted small">AI-generated summary so a teammate can pick up cleanly.</p>
      <button type="button" class="btn btn-sm" id="summarize-btn" data-conversation-id="<?= (int)$conv['id'] ?>">
        🤖 Generate summary
      </button>
      <div id="summary-box" class="summary-box hidden">
        <div class="summary-text" id="summary-text"></div>
        <div class="summary-meta muted small" id="summary-meta"></div>
        <div class="summary-actions">
          <button type="button" class="btn btn-sm btn-primary" id="summary-save">Save as note</button>
          <button type="button" class="btn btn-sm" id="summary-copy">Copy</button>
          <button type="button" class="btn btn-sm" id="summary-regen">Regenerate</button>
          <button type="button" class="btn btn-sm" id="summary-dismiss">Dismiss</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

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
