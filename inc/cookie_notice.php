<?php
/**
 * Minimal cookie notice.
 *
 * We use exactly one cookie - the session cookie that keeps users logged in.
 * No analytics, no marketing cookies. The banner exists to be transparent
 * about that and link to the Privacy Policy. Dismissal is remembered in
 * localStorage so it never re-shows on the same browser.
 *
 * Include via: cookie_notice();  immediately before </body>.
 */

require_once __DIR__ . '/helpers.php';

function cookie_notice(): void
{
    ?>
<div class="cookie-notice" id="cookie-notice" role="dialog" aria-label="Cookie notice">
  <div class="cookie-notice-body">
    <strong>🍪 Cookies</strong>
    We use one first-party cookie to keep you signed in. No analytics or
    advertising cookies. <a href="/privacy.php">Privacy Policy</a>.
  </div>
  <button type="button" class="cookie-notice-close" id="cookie-notice-close">Got it</button>
</div>
<script>
(function () {
  try {
    if (localStorage.getItem('aiserve_cookie_ack') === '1') return;
  } catch (_) { /* private mode: just show the notice every visit */ }
  var el = document.getElementById('cookie-notice');
  if (!el) return;
  el.classList.add('show');
  document.getElementById('cookie-notice-close').addEventListener('click', function () {
    el.classList.remove('show');
    try { localStorage.setItem('aiserve_cookie_ack', '1'); } catch (_) {}
  });
})();
</script>
<?php
}
