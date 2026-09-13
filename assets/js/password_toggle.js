/**
 * Auto-attach a show/hide 👁 toggle to every <input type="password">
 * on the page. Zero configuration — include the script and it works.
 *
 * Opt out by adding data-no-toggle to an input (e.g. very sensitive
 * fields where showing the entered value is undesirable).
 *
 * Also re-scans on DOMSubtreeModified-style dynamic additions via a
 * light MutationObserver so late-inserted password inputs get the
 * toggle too (used by AI settings, mail settings, etc. after AJAX).
 */
(function () {
    'use strict';

    function wrap(input) {
        if (input.dataset.pwToggleWired === '1' || input.dataset.noToggle === '1') return;
        // Skip inputs that already sit inside a wrapper we created.
        if (input.parentElement && input.parentElement.classList.contains('pw-wrap')) return;
        input.dataset.pwToggleWired = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle-btn';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
        btn.textContent = '👁';
        btn.addEventListener('click', function () {
            var isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            btn.textContent = isPw ? '🙈' : '👁';
            btn.setAttribute('aria-label', isPw ? 'Hide password' : 'Show password');
            btn.setAttribute('title',      isPw ? 'Hide password' : 'Show password');
            input.focus();
        });
        wrap.appendChild(btn);
    }

    function scan(root) {
        (root || document).querySelectorAll('input[type="password"]').forEach(wrap);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }

    // Late-added inputs get wrapped too (e.g. after JS injects a form).
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    if (added[j].nodeType === 1) scan(added[j]);
                }
            }
        }).observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
})();
