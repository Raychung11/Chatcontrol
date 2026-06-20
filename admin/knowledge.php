<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/knowledge_base.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        if (!user_can_edit_settings($current_user)) {
            $err = 'Only Super Admins can add knowledge base articles.';
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $pasted = trim((string)($_POST['content_text'] ?? ''));
            $haveFile = !empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if ($title === '') {
                $err = 'Title is required.';
            } elseif ($pasted === '' && !$haveFile) {
                $err = 'Upload a file or paste the article text.';
            } else {
                $text = $pasted;
                $sourceName = null;
                $mime = null;

                if ($haveFile) {
                    $tmp = (string)$_FILES['file']['tmp_name'];
                    $sourceName = (string)$_FILES['file']['name'];
                    $mime = '';
                    if (function_exists('finfo_open')) {
                        $fi = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = (string)finfo_file($fi, $tmp);
                        finfo_close($fi);
                    }
                    $mime = $mime ?: (string)($_FILES['file']['type'] ?? '');
                    $res = kb_extract_text($tmp, $mime, $sourceName);
                    if (!$res['ok']) {
                        $err = 'Could not read file: ' . ($res['error'] ?? 'unknown');
                    } else {
                        $text = trim($res['text'] . ($pasted !== '' ? "\n\n" . $pasted : ''));
                    }
                }

                if ($err === '' && $text !== '') {
                    $stmt = $db->prepare(
                        'INSERT INTO knowledge_base
                            (company_id, title, source_filename, mime_type, content_text, content_chars, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, "active", ?)'
                    );
                    $stmt->execute([$companyId, $title, $sourceName, $mime, $text, mb_strlen($text), (int)$current_user['id']]);
                    $newId = (int)$db->lastInsertId();
                    log_activity($companyId, (int)$current_user['id'], 'kb_added', 'knowledge_base', $newId, $title);
                    $msg = 'Article saved (' . mb_strlen($text) . ' characters).';
                }
            }
        }
    } elseif ($action === 'toggle' && $id > 0) {
        $db->prepare(
            'UPDATE knowledge_base SET status = IF(status = "active","inactive","active")
             WHERE id = ? AND company_id = ?'
        )->execute([$id, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'kb_toggled', 'knowledge_base', $id);
    } elseif ($action === 'delete' && $id > 0 && user_can_edit_settings($current_user)) {
        $db->prepare('DELETE FROM knowledge_base WHERE id = ? AND company_id = ?')
           ->execute([$id, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'kb_deleted', 'knowledge_base', $id);
        $msg = 'Article deleted.';
    }
}

$stmt = $db->prepare(
    'SELECT k.*, u.name AS author_name
     FROM knowledge_base k
     LEFT JOIN users u ON u.id = k.created_by
     WHERE k.company_id = ?
     ORDER BY k.id DESC'
);
$stmt->execute([$companyId]);
$articles = $stmt->fetchAll();

$totalActiveChars = 0;
foreach ($articles as $a) {
    if ($a['status'] === 'active') $totalActiveChars += (int)$a['content_chars'];
}

layout_start($current_user, 'AI Knowledge base', 'knowledge');
?>
<div class="card">
  <h2>AI Knowledge base</h2>
  <p class="muted small">
    Upload PDFs, Word docs, or paste plain text. Active articles are injected into
    the AI's system prompt so it can answer customer questions using your company's
    facts (FAQs, pricing, opening hours, policies). Prompt caching makes repeat
    requests cheap.
  </p>
  <div class="alert alert-info">
    <strong>Cap:</strong> up to <?= number_format(KB_MAX_TOTAL_CHARS) ?> characters
    total in one AI request. Currently active: <strong><?= number_format($totalActiveChars) ?></strong> chars
    across <?= count(array_filter($articles, fn($a) => $a['status'] === 'active')) ?> article(s).
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
</div>

<?php if (user_can_edit_settings($current_user)): ?>
<div class="card">
  <h3>Add an article</h3>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Title
      <input type="text" name="title" required maxlength="200" placeholder="e.g. Pricing &amp; payment FAQ">
    </label>
    <label>Upload file <small class="muted">(TXT, MD, PDF, DOCX — max 5 MB)</small>
      <input type="file" name="file" accept=".txt,.md,.csv,.pdf,.docx,text/plain,text/markdown,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </label>
    <label>…or paste the text directly <small class="muted">(adds to the file content if both supplied)</small>
      <textarea name="content_text" rows="6" placeholder="Paste FAQs, policies, opening hours…"></textarea>
    </label>
    <div>
      <button class="btn btn-primary" type="submit">Add article</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Articles</h3>
  <?php if (!$articles): ?>
    <p class="muted">No articles yet. Add one above and the AI will use it on the next reply suggestion.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Title</th><th>Source</th><th>Chars</th><th>Status</th><th>Added by</th><th>Created</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($articles as $a): ?>
          <tr>
            <td><?= e($a['title']) ?></td>
            <td class="muted small"><?= e($a['source_filename'] ?? 'pasted') ?></td>
            <td><?= number_format((int)$a['content_chars']) ?></td>
            <td><?= status_badge($a['status']) ?></td>
            <td class="muted small"><?= e($a['author_name'] ?? '—') ?></td>
            <td class="muted small"><?= e(fmt_dt($a['created_at'])) ?></td>
            <td class="actions">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $a['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
              <?php if (user_can_edit_settings($current_user)): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this article?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
