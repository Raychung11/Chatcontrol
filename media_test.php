<?php
/**
 * /media_test.php
 *
 * One-shot diagnostic for outbound media via the AiServe Chatbot Gateway.
 * Picks the most recent outgoing media message for the currently-logged-in
 * admin's workspace, reconstructs the signed public URL, displays it as a
 * clickable link, and runs a server-side fetch against it to confirm the
 * file is reachable. If the fetch works here but the customer still doesn't
 * receive the image, the failure is on the gateway's side - share the URL
 * with your partner so they can test from their server.
 *
 * Delete this file once the issue is debugged.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/aiserve_chatbot_api.php';

$user = require_role(['super_admin']);
$db   = aiserve_db();

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([(int)$user['company_id']]);
$company = $stmt->fetch() ?: [];

$mstmt = $db->prepare(
    'SELECT * FROM messages
     WHERE company_id = ? AND direction = "outgoing"
       AND message_type IN ("image","video","document")
     ORDER BY id DESC LIMIT 1'
);
$mstmt->execute([(int)$user['company_id']]);
$msg = $mstmt->fetch();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Media URL test</title>
  <style>
    body { font: 14px -apple-system, sans-serif; max-width: 900px; margin: 24px auto; padding: 0 16px; }
    h1 { font-size: 20px; }
    h2 { font-size: 14px; margin-top: 24px; padding-top: 10px; border-top: 1px solid #eee; }
    code, pre { background: #f4f6f8; padding: 6px 8px; border-radius: 4px; font-size: 12.5px; word-break: break-all; }
    pre { padding: 12px; max-height: 280px; overflow: auto; white-space: pre-wrap; }
    .ok  { color: #1f7a3f; }
    .err { color: #b3261e; }
    table { border-collapse: collapse; }
    th, td { padding: 4px 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
  </style>
</head>
<body>
<h1>Outbound media diagnostic</h1>

<?php if (!$msg): ?>
  <p class="err">No outgoing media message found for this workspace.</p>
<?php else: ?>

  <h2>Most recent outbound media message</h2>
  <table>
    <tr><th>id</th>             <td><?= (int)$msg['id'] ?></td></tr>
    <tr><th>type</th>           <td><?= htmlspecialchars($msg['message_type']) ?></td></tr>
    <tr><th>original name</th>  <td><?= htmlspecialchars((string)$msg['media_filename']) ?></td></tr>
    <tr><th>mime</th>           <td><?= htmlspecialchars((string)$msg['media_mime_type']) ?></td></tr>
    <tr><th>local path</th>     <td><code><?= htmlspecialchars((string)$msg['media_local_path']) ?></code></td></tr>
    <tr><th>file readable?</th> <td><?= is_readable((string)$msg['media_local_path']) ? '<span class="ok">yes</span>' : '<span class="err">NO</span>' ?></td></tr>
    <tr><th>file size</th>      <td><?= is_file((string)$msg['media_local_path']) ? filesize((string)$msg['media_local_path']) . ' bytes' : '—' ?></td></tr>
    <tr><th>status</th>         <td><?= htmlspecialchars($msg['status']) ?></td></tr>
    <tr><th>wa_message_id</th>  <td><code><?= htmlspecialchars((string)$msg['wa_message_id']) ?></code></td></tr>
    <tr><th>error_message</th>  <td><?= htmlspecialchars((string)($msg['error_message'] ?: '—')) ?></td></tr>
  </table>

  <?php
    $url = chatbot_public_media_url($company, (string)$msg['media_local_path']);
  ?>
  <h2>Signed public URL</h2>
  <?php if (!$url): ?>
    <p class="err">⚠ chatbot_public_media_url() returned NULL. Likely cause: webhook_verify_token is empty in companies row, or the file isn't under /uploads/<?= (int)$user['company_id'] ?>/.</p>
  <?php else: ?>
    <p>This is the URL the gateway tried to fetch:</p>
    <pre><a href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($url) ?></a></pre>
    <p><strong>Click it above</strong> - should show the image in a new tab. If it shows but the recipient still didn't get the message, the issue is on the gateway's side.</p>

    <h2>Server-side fetch test (simulates what the gateway does)</h2>
    <?php
      $ch = curl_init($url);
      curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_NOBODY         => true,
          CURLOPT_HEADER         => true,
          CURLOPT_TIMEOUT        => 15,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
      ]);
      $resp = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      $info = curl_getinfo($ch);
      curl_close($ch);
    ?>
    <table>
      <tr><th>HTTP status</th>     <td class="<?= ($code >= 200 && $code < 400) ? 'ok' : 'err' ?>"><?= $code ?></td></tr>
      <tr><th>curl error</th>      <td><?= $err === '' ? '<span class="ok">none</span>' : '<span class="err">' . htmlspecialchars($err) . '</span>' ?></td></tr>
      <tr><th>resolved IP</th>     <td><?= htmlspecialchars((string)($info['primary_ip'] ?? '?')) ?></td></tr>
      <tr><th>SSL verify result</th><td><?= isset($info['ssl_verify_result']) ? (int)$info['ssl_verify_result'] : '—' ?></td></tr>
      <tr><th>total time</th>      <td><?= number_format((float)($info['total_time'] ?? 0), 2) ?>s</td></tr>
    </table>
    <h3>Response headers</h3>
    <pre><?= htmlspecialchars((string)$resp) ?></pre>
  <?php endif; ?>
<?php endif; ?>

<h2>What this tells us</h2>
<ul>
  <li>If the click test above <strong>shows the image</strong> AND the server-side HTTP status is <strong>200</strong>: the URL is reachable. The image isn't arriving because of something on the gateway's side - send the URL to your partner so they can test from their server.</li>
  <li>If you get <strong>403 Bad signature</strong>: the URL builder and the verifier are using different secrets. Re-save Settings to refresh.</li>
  <li>If you get <strong>404 Not found</strong>: the file path moved or was deleted.</li>
  <li>If the click works but the server-side curl gets a <strong>SSL error</strong>: Hostinger's cert chain isn't trusted by all clients - usually self-resolves within an hour of provisioning.</li>
</ul>

<p style="margin-top: 32px; color: #b3261e;"><strong>Important:</strong> delete /media_test.php once you've found the issue. It exposes file paths and a working signed URL.</p>
</body>
</html>
