/**
 * AiServe Inbox - PWA install + service worker registration.
 *
 * Three flows:
 *   - Chromium / Edge / Android: capture beforeinstallprompt, show a custom
 *     green banner with an "Install" button.
 *   - iOS Safari: beforeinstallprompt never fires, so we detect iOS Safari
 *     and show a different banner telling the user to use Share -> Add to
 *     Home Screen. (iOS PWAs need this manual step - there's no
 *     programmatic install on iOS yet.)
 *   - Already-installed (standalone display mode): suppress everything.
 *
 * Dismissal is persisted in localStorage so the banner doesn't reappear on
 * the same browser. Install completion is also recorded.
 */
(function () {
  'use strict';

  // ---- Service worker registration ----
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .catch((e) => console.warn('SW registration failed:', e));
    });
  }

  const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  const isStandalone =
    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
    window.navigator.standalone === true;

  if (isStandalone) return; // user already installed - nothing to prompt
  if (localStorage.getItem('pwa_dismissed') === '1') return;

  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showBanner(false);
  });

  // iOS - prompt after 3s (browsers don't fire beforeinstallprompt on iOS).
  if (isIos) {
    setTimeout(() => showBanner(true), 3000);
  }

  function showBanner(ios) {
    if (document.getElementById('pwa-install-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Install AiServe Inbox');

    const msgEl = document.createElement('span');
    msgEl.className = 'pwa-install-text';
    if (ios) {
      msgEl.innerHTML =
        '📱 Tap <strong>Share</strong> → <strong>Add to Home Screen</strong> to install AiServe Inbox';
    } else {
      msgEl.innerHTML =
        '📱 Install <strong>AiServe Inbox</strong> on your home screen for faster access';
    }
    banner.appendChild(msgEl);

    const actions = document.createElement('div');
    actions.className = 'pwa-install-actions';

    if (!ios) {
      const installBtn = document.createElement('button');
      installBtn.type = 'button';
      installBtn.className = 'pwa-btn-primary';
      installBtn.textContent = 'Install';
      installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        installBtn.disabled = true;
        deferredPrompt.prompt();
        try {
          await deferredPrompt.userChoice;
        } catch (_) {}
        deferredPrompt = null;
        banner.remove();
      });
      actions.appendChild(installBtn);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pwa-btn-secondary';
    closeBtn.setAttribute('aria-label', 'Dismiss');
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', () => {
      banner.remove();
      localStorage.setItem('pwa_dismissed', '1');
    });
    actions.appendChild(closeBtn);

    banner.appendChild(actions);
    document.body.appendChild(banner);
  }

  window.addEventListener('appinstalled', () => {
    const banner = document.getElementById('pwa-install-banner');
    if (banner) banner.remove();
    localStorage.setItem('pwa_installed', '1');
  });
})();
