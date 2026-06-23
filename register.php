<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/cookie_notice.php';

aiserve_start_session();

if (current_user()) {
    redirect('/dashboard.php');
}

$err = '';
$old = [
    'company' => '',
    'slug'    => '',
    'name'    => '',
    'email'   => '',
    'plan'    => 'growth',
];

if (is_post()) {
    csrf_check();

    $old['company'] = trim((string)($_POST['company_name'] ?? ''));
    $old['slug']    = slugify((string)($_POST['slug']        ?? $old['company']));
    $old['name']    = trim((string)($_POST['admin_name']   ?? ''));
    $old['email']   = trim((string)($_POST['admin_email']  ?? ''));
    $password       = (string)($_POST['password']           ?? '');
    $confirm        = (string)($_POST['password_confirm']   ?? '');
    $old['plan']    = in_array(($_POST['plan'] ?? 'growth'), ['starter','growth','enterprise'], true)
                    ? (string)$_POST['plan'] : 'growth';

    $accepted = !empty($_POST['accept_terms']);

    if (!$accepted) {
        $err = 'You must accept the Terms, Privacy Policy, and Disclaimer to continue.';
    } elseif ($old['company'] === '' || $old['name'] === '' || $old['email'] === '') {
        $err = 'Company name, your name, and email are all required.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $err = 'Passwords do not match.';
    } elseif (!preg_match('/^[a-z0-9-]{3,64}$/', $old['slug'])) {
        $err = 'Workspace identifier must be 3-64 lowercase letters / numbers / dashes.';
    } else {
        $db = aiserve_db();

        // Email must be globally unique so login by email is unambiguous
        $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$old['email']]);
        if ($check->fetchColumn()) {
            $err = 'That email is already registered. Try signing in instead.';
        }

        if ($err === '') {
            $check = $db->prepare('SELECT id FROM companies WHERE slug = ? LIMIT 1');
            $check->execute([$old['slug']]);
            if ($check->fetchColumn()) {
                $err = 'That workspace identifier is taken. Pick another one.';
            }
        }

        if ($err === '') {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare(
                    'INSERT INTO companies (name, slug, plan, webhook_verify_token, brand_color, timezone, status)
                     VALUES (?, ?, ?, ?, "#25D366", ?, "active")'
                );
                $stmt->execute([
                    $old['company'],
                    $old['slug'],
                    $old['plan'],
                    bin2hex(random_bytes(16)),
                    APP_TIMEZONE,
                ]);
                $companyId = (int)$db->lastInsertId();

                $stmt = $db->prepare(
                    'INSERT INTO departments (company_id, name, status)
                     VALUES (?, "General", "active")'
                );
                $stmt->execute([$companyId]);
                $deptId = (int)$db->lastInsertId();

                $stmt = $db->prepare('UPDATE companies SET default_department_id = ? WHERE id = ?');
                $stmt->execute([$deptId, $companyId]);

                $stmt = $db->prepare(
                    'INSERT INTO users (company_id, department_id, name, email, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, "super_admin", "active")'
                );
                $stmt->execute([
                    $companyId, $deptId, $old['name'], $old['email'],
                    password_hash($password, PASSWORD_BCRYPT),
                ]);
                $userId = (int)$db->lastInsertId();

                // Default channel so the workspace has something to send through.
                require_once __DIR__ . '/inc/channels.php';
                $token = channel_generate_webhook_token();
                $db->prepare(
                    'INSERT INTO channels (company_id, name, provider, webhook_token, is_default, status)
                     VALUES (?, ?, "cloud_api", ?, 1, "active")'
                )->execute([$companyId, $old['company'] . ' default', $token]);

                $db->commit();

                log_activity($companyId, $userId, 'company_registered', 'company', $companyId,
                    'Workspace ' . $old['slug'] . ' created with plan=' . $old['plan']);
                log_activity($companyId, $userId, 'legal_accepted', 'company', $companyId,
                    'Terms+Privacy+Disclaimer accepted from ip=' . client_ip());

                // Auto-login
                $stmt = aiserve_db()->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $newUser = $stmt->fetch();
                login_user($newUser);
                redirect('/dashboard.php');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[AiServe register] ' . $e->getMessage());
                $err = 'Could not create your workspace. Please try again.';
            }
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create your workspace · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
  <div class="login-card register-card">
    <div class="login-brand">
      <span class="brand-dot" style="background:#25D366"></span>
      <span class="brand-text">AiServe Inbox</span>
    </div>
    <h1>Create your workspace</h1>
    <p class="muted">Free to start. Invite up to 10 teammates on the Growth plan.</p>

    <?php if ($err): ?>
      <div class="alert alert-error"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <label for="company_name">Company name</label>
      <input type="text" id="company_name" name="company_name" required maxlength="150"
             value="<?= e($old['company']) ?>" placeholder="Acme Sdn Bhd">

      <label for="slug">Workspace identifier
        <small class="muted">(used in your webhook URLs)</small></label>
      <input type="text" id="slug" name="slug" required maxlength="64"
             value="<?= e($old['slug']) ?>" placeholder="acme">

      <label for="admin_name">Your name</label>
      <input type="text" id="admin_name" name="admin_name" required maxlength="120"
             value="<?= e($old['name']) ?>">

      <label for="admin_email">Email</label>
      <input type="email" id="admin_email" name="admin_email" required autocomplete="username"
             value="<?= e($old['email']) ?>">

      <label for="password">Password <small class="muted">(min 8 characters)</small></label>
      <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

      <label for="password_confirm">Confirm password</label>
      <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">

      <fieldset class="plan-pick">
        <legend>Plan</legend>
        <label class="plan-radio">
          <input type="radio" name="plan" value="starter" <?= $old['plan'] === 'starter' ? 'checked' : '' ?>>
          <span><strong>Starter</strong> — 3 seats</span>
        </label>
        <label class="plan-radio">
          <input type="radio" name="plan" value="growth" <?= $old['plan'] === 'growth' ? 'checked' : '' ?>>
          <span><strong>Growth</strong> — 10 seats</span>
        </label>
        <label class="plan-radio">
          <input type="radio" name="plan" value="enterprise" <?= $old['plan'] === 'enterprise' ? 'checked' : '' ?>>
          <span><strong>Enterprise</strong> — unlimited</span>
        </label>
      </fieldset>

      <label class="legal-accept">
        <input type="checkbox" name="accept_terms" value="1" required>
        <span>
          I have read and agree to the
          <a href="/terms.php" target="_blank" rel="noopener">Terms of Service</a>,
          <a href="/privacy.php" target="_blank" rel="noopener">Privacy Policy</a>, and
          <a href="/disclaimer.php" target="_blank" rel="noopener">Disclaimer</a>.
        </span>
      </label>

      <button type="submit" class="btn btn-primary btn-block">Create workspace</button>
    </form>

    <p class="muted small">Already have an account? <a href="/login.php">Sign in</a>.</p>
    <p class="muted small" style="text-align:center; margin-top: 8px;">
      <a href="/terms.php">Terms</a> ·
      <a href="/privacy.php">Privacy</a> ·
      <a href="/disclaimer.php">Disclaimer</a>
    </p>
  </div>

<script>
  // Auto-derive slug from company name as the user types (only while slug is untouched).
  (function () {
    const name = document.getElementById('company_name');
    const slug = document.getElementById('slug');
    let touched = false;
    slug.addEventListener('input', () => { touched = true; });
    name.addEventListener('input', () => {
      if (touched) return;
      slug.value = (name.value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64);
    });
  })();
</script>
<?php cookie_notice(); ?>
</body>
</html>
