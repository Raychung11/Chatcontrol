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
            ct.wa_lid AS contact_wa_lid,
            ct.branch_id AS contact_branch_id,
            u.name AS agent_name, d.name AS department_name,
            b.name AS contact_branch_name,
            ch.name AS channel_name, ch.display_phone AS channel_phone, ch.provider AS channel_provider
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     LEFT  JOIN users u ON u.id = c.assigned_user_id
     LEFT  JOIN departments d ON d.id = c.department_id
     LEFT  JOIN branches b ON b.id = ct.branch_id
     LEFT  JOIN channels ch ON ch.id = c.channel_id
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

// Messages — soft-deleted rows (phase 52) are excluded from the visible
// stream. The `deleted_at IS NULL OR NOT EXISTS(deleted_at)` shape lets
// this query run against pre-phase-52 databases too via a runtime column
// check; simpler to just always include the filter now that phase 52 is
// the baseline.
$mstmt = $db->prepare(
    'SELECT m.*, u.name AS sender_name
     FROM messages m
     LEFT JOIN users u ON u.id = m.sender_user_id
     WHERE m.conversation_id = ?
       AND (m.deleted_at IS NULL)
     ORDER BY m.created_at ASC, m.id ASC
     LIMIT 500'
);
$mstmt->execute([$conversationId]);
$messages = $mstmt->fetchAll();

// Internal notes — LEFT JOIN so system-generated notes (user_id NULL)
// still render. F&B order-created notes and flow-emitted 'save_note'
// entries both use user_id NULL by design.
$nstmt = $db->prepare(
    'SELECT n.*, COALESCE(u.name, "System") AS user_name
     FROM internal_notes n
     LEFT  JOIN users u ON u.id = n.user_id
     WHERE n.conversation_id = ?
     ORDER BY n.created_at ASC LIMIT 200'
);
$nstmt->execute([$conversationId]);
$notes = $nstmt->fetchAll();

// F&B orders from THIS contact (only if the module is active on this
// workspace). Shown in a side-panel card so operators can jump from
// the chat straight to the order detail / kanban.
$fnbOrders = [];
$contactIdForOrders = (int)($conv['contact_id'] ?? 0);
if ($contactIdForOrders > 0 && fnb_module_active($companyId)) {
    try {
        $os = $db->prepare(
            'SELECT id, order_number, status, order_type,
                    subtotal, delivery_fee, total, created_at
             FROM fnb_orders
             WHERE company_id = ? AND contact_id = ?
             ORDER BY id DESC LIMIT 10'
        );
        $os->execute([$companyId, $contactIdForOrders]);
        $fnbOrders = $os->fetchAll();
    } catch (Throwable $e) { /* fnb_orders table may not exist on non-F&B workspaces */ }
}

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
        <strong id="chat-header-name"><?= e($conv['display_name'] ?: $conv['profile_name'] ?: $conv['wa_id']) ?></strong>
        <span class="muted">+<?= e($conv['wa_id']) ?></span>
        <?php if (!empty($conv['channel_name'])): ?>
          <span class="chat-channel-badge"
                title="This conversation is on <?= e((string)$conv['channel_name']) ?><?= !empty($conv['channel_phone']) ? ' (' . e((string)$conv['channel_phone']) . ')' : '' ?>">
            via <?= e((string)$conv['channel_name']) ?>
            <?php if (!empty($conv['channel_phone'])): ?>
              <span class="muted"> · <?= e((string)$conv['channel_phone']) ?></span>
            <?php endif; ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="chat-status">
        <?= status_badge($conv['status']) ?>
        <?php if ($enforceWindow && !$windowOpen): ?>
          <span class="badge badge-failed" title="24-hour service window expired">Window expired</span>
        <?php endif; ?>
        <button type="button" class="chat-side-toggle" id="chat-side-toggle" aria-label="Conversation info">ⓘ</button>
      </div>
    </header>

    <?php
      // "🤖 AI is handling this — Take over" banner.
      // Shows only when AI is actually active for THIS conversation:
      //   - workspace has AI enabled
      //   - a mode that can auto-reply is on (always_on OR first_touch)
      //   - conversation is open (not closed)
      //   - no agent is assigned yet
      //   - at least one AI-sent message already exists (so we don't
      //     surface the banner on a brand-new chat where the AI hasn't
      //     actually stepped in — avoids false alarms on manual chats
      //     inside an AI-enabled workspace)
      $aiActive = !empty($company['ai_enabled'])
               && (!empty($company['ai_always_on']) || !empty($company['ai_first_touch']))
               && empty($conv['assigned_user_id'])
               && $conv['status'] !== 'closed';
      $aiHasReplied = false;
      if ($aiActive) {
          foreach ($messages as $m) {
              if (($m['sender_type'] ?? '') === 'ai') { $aiHasReplied = true; break; }
          }
      }

      // Impersonating platform admins aren't a real user in this
      // workspace — the assign endpoint would reject their id with
      // "Target user invalid". Verify the current user actually exists
      // in this workspace's users table before offering "Take over"; if
      // not, show a "Pause AI" variant that just unassigns / prompts to
      // assign a real agent from the sidebar dropdown.
      $currentUserInWorkspace = false;
      if ($aiActive && $aiHasReplied) {
          $chk = $db->prepare('SELECT 1 FROM users WHERE id = ? AND company_id = ? AND status = "active" LIMIT 1');
          $chk->execute([(int)$current_user['id'], (int)$conv['company_id']]);
          $currentUserInWorkspace = (bool)$chk->fetchColumn();
      }
    ?>
    <?php if ($aiActive && $aiHasReplied && $currentUserInWorkspace): ?>
      <div class="ai-takeover-banner" id="ai-takeover-banner">
        <div class="ai-takeover-icon">🤖</div>
        <div class="ai-takeover-text">
          <strong>AI is handling this conversation.</strong>
          <span class="muted small">
            Click <em>Take over</em> to stop the AI and reply yourself — it won't touch this thread again until you unassign it.
          </span>
        </div>
        <form method="post" action="/api/conversation_action.php" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="action"           value="assign">
          <input type="hidden" name="conversation_id"  value="<?= (int)$conv['id'] ?>">
          <input type="hidden" name="assigned_user_id" value="<?= (int)$current_user['id'] ?>">
          <button class="btn btn-primary btn-sm" type="submit">✋ Take over</button>
        </form>
      </div>
    <?php elseif ($aiActive && $aiHasReplied): ?>
      <div class="ai-takeover-banner" id="ai-takeover-banner">
        <div class="ai-takeover-icon">🤖</div>
        <div class="ai-takeover-text">
          <strong>AI is handling this conversation.</strong>
          <span class="muted small">
            You're viewing as platform admin — use the <em>Assigned</em> dropdown in the sidebar to hand this off to a workspace agent.
          </span>
        </div>
      </div>
    <?php endif; ?>

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
      <form class="contact-rename-form" id="contact-rename-form">
        <?= csrf_field() ?>
        <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
        <label for="contact-rename-input" class="kv-label">Name</label>
        <div class="contact-rename-row">
          <input type="text" id="contact-rename-input" name="display_name"
                 value="<?= e((string)($conv['display_name'] ?? '')) ?>"
                 maxlength="190"
                 placeholder="<?= e((string)($conv['profile_name'] ?: $conv['wa_id'])) ?>"
                 autocomplete="off">
          <button type="submit" class="btn btn-sm" id="contact-rename-save">Save</button>
        </div>
        <div class="muted small" id="contact-rename-hint">
          <?php if (!empty($conv['profile_name']) && (string)$conv['profile_name'] !== (string)$conv['display_name']): ?>
            WhatsApp shows this contact as <em><?= e($conv['profile_name']) ?></em>.
          <?php else: ?>
            Overrides what WhatsApp shows. Leave blank to fall back to the profile name.
          <?php endif; ?>
        </div>
      </form>
      <?php $isLidContact = !empty($conv['contact_wa_lid']); ?>
      <div class="kv">
        <span>WhatsApp ID</span>
        <strong>
          <?php if ($isLidContact): ?>
            <span title="This customer uses WhatsApp LID privacy. Their real phone number is masked by Meta — you can't broadcast or SMS them. Ask for phone number in-chat if you need it."
                  style="display:inline-block; background:#fef3c7; color:#78350f; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600;">
              🔒 Anonymous (LID)
            </span><br>
          <?php endif; ?>
          <span style="<?= $isLidContact ? 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#64748b;' : '' ?>">
            <?= $isLidContact ? '' : '+' ?><?= e($conv['wa_id']) ?>
          </span>
        </strong>
      </div>
      <?php if ($isLidContact): ?>
        <div class="muted small" style="padding: 6px 10px; background:#fffbeb; border-left:3px solid #f59e0b; border-radius:4px; margin: 4px 0 8px; font-size: 12px;">
          Privacy-preserving ID from Meta. Reply works normally, but this customer <strong>can't be broadcast to</strong>. If you know their real number, use <strong>Merge…</strong> below to combine records.
        </div>
      <?php endif; ?>
      <?php if (!empty($conv['channel_name'])): ?>
        <div class="kv">
          <span>Channel</span>
          <strong>
            <?= e((string)$conv['channel_name']) ?>
            <?php if (!empty($conv['channel_phone'])): ?>
              <br><span class="muted small">+<?= e(ltrim((string)$conv['channel_phone'], '+')) ?></span>
            <?php endif; ?>
          </strong>
        </div>
      <?php endif; ?>

      <?php if (in_array($current_user['role'] ?? 'agent', ['super_admin', 'manager'], true)): ?>
        <!-- Merge-contacts button — opens a modal to pick a target contact.
             Especially useful for LID phantom + real-phone duplicate cases,
             but also handles any manual dedup. -->
        <div style="margin: 6px 0 10px;">
          <button type="button" class="btn btn-sm" id="merge-contact-btn"
                  data-source-id="<?= (int)$conv['contact_id'] ?>"
                  data-source-name="<?= e((string)($conv['display_name'] ?: $conv['profile_name'] ?: $conv['wa_id'])) ?>"
                  title="Merge this contact into another one (moves all messages + conversations)">
            🔀 Merge into another contact…
          </button>
        </div>
      <?php endif; ?>

      <?php
        $branchList = $db->prepare(
            'SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name'
        );
        $branchList->execute([$companyId]);
        $branchList = $branchList->fetchAll();
      ?>
      <?php if ($branchList): ?>
        <form class="contact-branch-form" id="contact-branch-form" style="margin: 8px 0;">
          <?= csrf_field() ?>
          <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
          <label for="contact-branch-select" class="kv-label">Branch</label>
          <div class="contact-rename-row">
            <select id="contact-branch-select" name="branch_id" style="flex:1;">
              <option value="0"><?= empty($conv['contact_branch_id']) ? '— None —' : '— Remove branch —' ?></option>
              <?php foreach ($branchList as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === (int)($conv['contact_branch_id'] ?? 0) ? 'selected' : '' ?>>
                  <?= e($b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm" id="contact-branch-save">Save</button>
          </div>
          <div class="muted small" id="contact-branch-hint">
            Which office / business unit owns this customer.
          </div>
        </form>
      <?php endif; ?>
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

    <?php if ($fnbOrders): ?>
      <?php
        $orderCurrency = platform_setting('pricing_currency', 'RM');
        $statusColor = [
          'new'        => ['bg' => '#fef3c7', 'fg' => '#78350f', 'label' => 'New'],
          'confirmed'  => ['bg' => '#dbeafe', 'fg' => '#1e3a8a', 'label' => 'Confirmed'],
          'processing' => ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'label' => 'Processing'],
          'completed'  => ['bg' => '#dcfce7', 'fg' => '#14532d', 'label' => 'Completed'],
          'cancelled'  => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'label' => 'Cancelled'],
        ];
      ?>
      <div class="side-section">
        <h3>🍜 F&B orders from this customer <small class="muted">(<?= count($fnbOrders) ?>)</small></h3>
        <div style="display:flex; flex-direction:column; gap:6px;">
          <?php foreach ($fnbOrders as $o):
            $s = $statusColor[$o['status']] ?? ['bg' => '#f1f5f9', 'fg' => '#334155', 'label' => ucfirst((string)$o['status'])];
          ?>
            <a href="/admin/fnb_order_view.php?id=<?= (int)$o['id'] ?>"
               style="display:block; padding:8px 10px; border:1px solid #e3e8ee; border-radius:6px; text-decoration:none; color:#0f172a; background:#fff;"
               title="Open on kanban / dashboard">
              <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                <strong style="font-size:13px;">#<?= e((string)$o['order_number']) ?></strong>
                <span style="background: <?= e($s['bg']) ?>; color: <?= e($s['fg']) ?>; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">
                  <?= e($s['label']) ?>
                </span>
              </div>
              <div class="muted small" style="margin-top:2px;">
                <?= e($orderCurrency) ?> <?= number_format((float)$o['total'], 2) ?>
                · <?= e((string)$o['order_type']) ?>
                · <?= e(fmt_dt($o['created_at'], 'M j, H:i')) ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:8px;">
          <a class="btn btn-sm" href="/admin/fnb_orders.php" style="width:100%; text-align:center;">
            📋 Open F&B kanban →
          </a>
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
            <div class="note-body"><?php
              // Escape first, then linkify /admin/... paths and http(s):// URLs
              // so system-generated notes (e.g. F&B order creation) have a
              // clickable "View →" link right in the note body.
              $safe = nl2br(e($n['note_text']));
              $safe = preg_replace_callback(
                  '#((?:https?://|/admin/|/inbox/)[^\s<]+)#',
                  fn($m) => '<a href="' . $m[1] . '" style="color:#0072B2;">' . $m[1] . '</a>',
                  $safe
              );
              echo $safe;
            ?></div>
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
