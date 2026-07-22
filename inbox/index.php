<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/inbox_query.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];

$filter     = $_GET['filter']     ?? 'all';
$search     = trim((string)($_GET['q'] ?? ''));
$deptFilter = (int)($_GET['department_id'] ?? 0);
$tagFilter  = (int)($_GET['tag_id'] ?? 0);

$db = aiserve_db();

$data          = inbox_fetch($db, $current_user, $filter, $search, $deptFilter, $tagFilter);
$conversations = $data['conversations'];
$tagsByConv    = $data['tags_by_conv'];
$counts        = $data['counts'];

$departments = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$departments->execute([$companyId]);
$departments = $departments->fetchAll();

$tagsAll = $db->prepare('SELECT id, name, color FROM conversation_tags WHERE company_id = ? ORDER BY name');
$tagsAll->execute([$companyId]);
$tagsAll = $tagsAll->fetchAll();

layout_start($current_user, 'Inbox', 'inbox');
?>
<div class="inbox-shell"
     data-poll-scope="inbox"
     data-filter="<?= e($filter) ?>"
     data-q="<?= e($search) ?>"
     data-department-id="<?= (int)$deptFilter ?>"
     data-tag-id="<?= (int)$tagFilter ?>">
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
      <a class="btn btn-sm" href="/inbox/new_chat.php" style="margin-left:4px;">+ New chat</a>
    </form>

    <ul class="inbox-quickfilters">
      <?php
      $links = [
          'all'        => ['All',        'open_total'],
          'unread'     => ['Unread',     'awaiting'],
          'replied'    => ['Replied',    'replied'],
          'mine'       => ['Mine',       'mine'],
          'unassigned' => ['Unassigned', 'unassigned'],
          'open'       => ['Open',       's_open'],
          'pending'    => ['Pending',    's_pending'],
          'escalated'  => ['Escalated',  's_escalated'],
          'closed'     => ['Closed',     's_closed'],
      ];
      foreach ($links as $key => [$label, $countKey]):
        $href = '?filter=' . urlencode($key)
              . ($search !== '' ? '&q=' . urlencode($search) : '')
              . ($deptFilter > 0 ? '&department_id=' . $deptFilter : '')
              . ($tagFilter  > 0 ? '&tag_id=' . $tagFilter : '');
      ?>
        <li><a class="<?= $filter === $key ? 'active' : '' ?>" href="<?= e($href) ?>" data-filter-key="<?= e($key) ?>">
          <?= e($label) ?> <span class="count" data-count="<?= e($countKey) ?>"><?= (int)($counts[$countKey] ?? 0) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="inbox-list" id="inbox-list">
    <?php if (!$conversations): ?>
      <div class="empty-state">No conversations match this view.</div>
    <?php endif; ?>
    <?php foreach ($conversations as $c): ?>
      <?= inbox_row_html($c, $tagsByConv[(int)$c['id']] ?? []) ?>
    <?php endforeach; ?>
  </section>
</div>
<?php layout_end(); ?>
