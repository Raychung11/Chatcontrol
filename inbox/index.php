<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$role         = $current_user['role'];

$filter     = $_GET['filter']     ?? 'all';
$search     = trim((string)($_GET['q'] ?? ''));
$deptFilter = (int)($_GET['department_id'] ?? 0);
$tagFilter  = (int)($_GET['tag_id'] ?? 0);

$db = aiserve_db();

// Base scope: company_id always enforced.
$where  = ['c.company_id = ?'];
$params = [$companyId];

switch ($filter) {
    case 'unassigned':
        $where[] = 'c.assigned_user_id IS NULL';
        $where[] = 'c.status <> "closed"';
        break;
    case 'mine':
        $where[] = 'c.assigned_user_id = ?';
        $params[] = (int)$current_user['id'];
        break;
    case 'open':
        $where[] = 'c.status = "open"';
        break;
    case 'pending':
        $where[] = 'c.status = "pending"';
        break;
    case 'closed':
        $where[] = 'c.status = "closed"';
        break;
    case 'escalated':
        $where[] = 'c.status = "escalated"';
        break;
    case 'all':
    default:
        break;
}

// Agents only see their own / unassigned in their dept
if ($role === 'agent') {
    $where[] = '(c.assigned_user_id = ?
                 OR (c.assigned_user_id IS NULL
                     AND (c.department_id IS NULL OR c.department_id = ?)))';
    $params[] = (int)$current_user['id'];
    $params[] = (int)($current_user['department_id'] ?? 0);
}

if ($deptFilter > 0) {
    $where[] = 'c.department_id = ?';
    $params[] = $deptFilter;
}

if ($search !== '') {
    $where[] = '(ct.display_name LIKE ? OR ct.profile_name LIKE ? OR ct.phone LIKE ? OR ct.wa_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($tagFilter > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM conversation_tag_map m WHERE m.conversation_id = c.id AND m.tag_id = ?)';
    $params[] = $tagFilter;
}

$sql = 'SELECT c.*, ct.display_name, ct.profile_name, ct.phone AS contact_phone, ct.wa_id,
               u.name AS agent_name, d.name AS department_name
        FROM conversations c
        INNER JOIN contacts ct ON ct.id = c.contact_id
        LEFT  JOIN users    u  ON u.id  = c.assigned_user_id
        LEFT  JOIN departments d ON d.id = c.department_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY (c.status = "closed") ASC, COALESCE(c.last_message_at, c.created_at) DESC
        LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

// Sidebar counters
$counterSql = 'SELECT
    SUM(status <> "closed")                                          AS open_total,
    SUM(assigned_user_id IS NULL AND status <> "closed")             AS unassigned,
    SUM(assigned_user_id = ? AND status <> "closed")                 AS mine,
    SUM(status = "open")                                             AS s_open,
    SUM(status = "pending")                                          AS s_pending,
    SUM(status = "closed")                                           AS s_closed,
    SUM(status = "escalated")                                        AS s_escalated
  FROM conversations WHERE company_id = ?';
$cstmt = $db->prepare($counterSql);
$cstmt->execute([(int)$current_user['id'], $companyId]);
$counts = $cstmt->fetch() ?: [];

$departments = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$departments->execute([$companyId]);
$departments = $departments->fetchAll();

$tagsAll = $db->prepare('SELECT id, name, color FROM conversation_tags WHERE company_id = ? ORDER BY name');
$tagsAll->execute([$companyId]);
$tagsAll = $tagsAll->fetchAll();

// Map conversation_id -> [{id,name,color}, ...]
$tagsByConv = [];
if ($conversations) {
    $convIds = array_map(fn($r) => (int)$r['id'], $conversations);
    $placeholders = implode(',', array_fill(0, count($convIds), '?'));
    $sqlT = 'SELECT m.conversation_id, t.id, t.name, t.color
             FROM conversation_tag_map m
             INNER JOIN conversation_tags t ON t.id = m.tag_id
             WHERE m.conversation_id IN (' . $placeholders . ') AND t.company_id = ?';
    $tagStmt = $db->prepare($sqlT);
    $tagStmt->execute(array_merge($convIds, [$companyId]));
    foreach ($tagStmt->fetchAll() as $row) {
        $tagsByConv[(int)$row['conversation_id']][] = $row;
    }
}

layout_start($current_user, 'Inbox', 'inbox');
?>
<div class="inbox-shell">
  <section class="inbox-filters">
    <form method="get" class="inbox-search">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name / phone…">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">
      <select name="department_id" onchange="this.form.submit()">
        <option value="0">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="tag_id" onchange="this.form.submit()">
        <option value="0">All tags</option>
        <?php foreach ($tagsAll as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tagFilter === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit">Search</button>
    </form>

    <ul class="inbox-quickfilters">
      <?php
      $links = [
          'all'        => ['All',         (int)($counts['open_total']  ?? 0)],
          'mine'       => ['Mine',        (int)($counts['mine']        ?? 0)],
          'unassigned' => ['Unassigned',  (int)($counts['unassigned']  ?? 0)],
          'open'       => ['Open',        (int)($counts['s_open']      ?? 0)],
          'pending'    => ['Pending',     (int)($counts['s_pending']   ?? 0)],
          'escalated'  => ['Escalated',   (int)($counts['s_escalated'] ?? 0)],
          'closed'     => ['Closed',      (int)($counts['s_closed']    ?? 0)],
      ];
      foreach ($links as $key => [$label, $cnt]):
        $href = '?filter=' . urlencode($key)
              . ($search !== '' ? '&q=' . urlencode($search) : '')
              . ($deptFilter > 0 ? '&department_id=' . $deptFilter : '')
              . ($tagFilter  > 0 ? '&tag_id=' . $tagFilter : '');
      ?>
        <li><a class="<?= $filter === $key ? 'active' : '' ?>" href="<?= e($href) ?>">
          <?= e($label) ?> <span class="count"><?= $cnt ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="inbox-list">
    <?php if (!$conversations): ?>
      <div class="empty-state">No conversations match this view.</div>
    <?php endif; ?>
    <?php foreach ($conversations as $c): ?>
      <a class="inbox-row" href="/inbox/chat.php?id=<?= (int)$c['id'] ?>">
        <div class="row-avatar">
          <?= e(strtoupper(substr($c['display_name'] ?: $c['profile_name'] ?: '?', 0, 1))) ?>
        </div>
        <div class="row-main">
          <div class="row-top">
            <span class="row-name"><?= e($c['display_name'] ?: $c['profile_name'] ?: $c['wa_id']) ?></span>
            <span class="row-time"><?= e(relative_time($c['last_message_at'] ?? $c['created_at'])) ?></span>
          </div>
          <div class="row-mid">
            <span class="row-preview"><?= e(mb_strimwidth((string)$c['last_message_text'], 0, 70, '…')) ?></span>
            <?php if ((int)$c['unread_count'] > 0): ?>
              <span class="row-badge"><?= (int)$c['unread_count'] ?></span>
            <?php endif; ?>
          </div>
          <div class="row-bot">
            <?= status_badge($c['status']) ?>
            <span class="row-meta"><?= e($c['agent_name'] ? 'Assigned: ' . $c['agent_name'] : 'Unassigned') ?></span>
            <?php if (!empty($c['department_name'])): ?>
              <span class="row-meta">· <?= e($c['department_name']) ?></span>
            <?php endif; ?>
            <?php foreach (($tagsByConv[(int)$c['id']] ?? []) as $tg): ?>
              <span class="tag-chip" style="background: <?= e($tg['color']) ?>"><?= e($tg['name']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </section>
</div>
<?php layout_end(); ?>
