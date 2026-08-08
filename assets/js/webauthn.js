/**
 * Client-side WebAuthn helpers.
 *
 *  waRegister(deviceName) — enroll this device's biometric. Returns
 *      { ok, error? }. Caller shows a success/error toast.
 *  waAuthenticate()       — unlock via biometric on this device.
 *      Returns { ok, error? }. On success, the caller navigates to
 *      the intended next page.
 *
 * Uses fetch + JSON to the four /api/webauthn_* endpoints. base64url
 * conversion here mirrors inc/webauthn.php exactly.
 *
 * The browser handles all the actual biometric prompt UI — Face ID
 * pop-up on iOS, fingerprint sheet on Android, Windows Hello prompt
 * on Windows. Nothing we can style.
 */
(function (global) {
    'use strict';
    if (!window.PublicKeyCredential) return;    // browser can't do WebAuthn — helpers stay undefined

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function b64urlEncode(bytes) {
        var s = '';
        var arr = new Uint8Array(bytes);
        for (var i = 0; i < arr.length; i++) s += String.fromCharCode(arr[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function b64urlDecode(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        var pad = s.length % 4;
        if (pad) s += '='.repeat(4 - pad);
        var bin = atob(s);
        var arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return arr.buffer;
    }

    async function post(path, body) {
        var fd = new FormData();
        fd.append('_csrf', csrf());
        var opts = { method: 'POST', credentials: 'same-origin' };
        if (body === undefined) {
            opts.body = fd;
        } else {
            fd.append('_csrf', csrf());
            opts.body = JSON.stringify(body);
            opts.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() };
        }
        var r = await fetch(path, opts);
        return await r.json();
    }
    async function postForm(path) {
        var fd = new FormData();
        fd.append('_csrf', csrf());
        var r = await fetch(path, { method: 'POST', body: fd, credentials: 'same-origin' });
        return await r.json();
    }
    async function postJson(path, body) {
        // Server accepts JSON but csrf_check() reads _csrf from POST body/header.
        // Use a header so the JSON body stays clean of framework metadata.
        var r = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf(),
            },
            // csrf_check() also accepts _csrf in the body — belt + braces.
            body: JSON.stringify(Object.assign({ _csrf: csrf() }, body || {})),
        });
        return await r.json();
    }

    async function waRegister(deviceName) {
        try {
            var opt = await postForm('/api/webauthn_register_begin.php');
            if (!opt.ok) return { ok: false, error: opt.error || 'Init failed' };

            opt.challenge = b64urlDecode(opt.challenge);
            opt.user.id   = b64urlDecode(opt.user.id);
            if (opt.excludeCredentials) {
                opt.excludeCredentials = opt.excludeCredentials.map(function (c) {
                    return { type: c.type, id: b64urlDecode(c.id) };
                });
            }

            var cred = await navigator.credentials.create({ publicKey: opt });
            if (!cred) return { ok: false, error: 'No credential returned.' };

            // getPublicKey() returns SPKI DER — this is what lets us skip
            // server-side CBOR parsing. Available in Chrome 85+, Safari
            // 14.5+, Firefox 108+.
            var spki = cred.response.getPublicKey && cred.response.getPublicKey();
            if (!spki) {
                return { ok: false, error: 'This browser can\'t export the public key. Update your browser.' };
            }

            var finish = await postJson('/api/webauthn_register_finish.php', {
                id:                b64urlEncode(cred.rawId),
                publicKeyB64:      b64urlEncode(spki),
                clientDataJsonB64: b64urlEncode(cred.response.clientDataJSON),
                deviceName:        deviceName || (navigator.userAgent.match(/(iPhone|iPad|Android|Windows|Mac)/i) || ['device'])[0],
            });
            return finish;
        } catch (e) {
            return { ok: false, error: e.message || String(e) };
        }
    }

    async function waAuthenticate() {
        try {
            var opt = await postForm('/api/webauthn_auth_begin.php');
            if (!opt.ok) return { ok: false, error: opt.error || 'Init failed' };

            opt.challenge = b64urlDecode(opt.challenge);
            if (opt.allowCredentials) {
                opt.allowCredentials = opt.allowCredentials.map(function (c) {
                    return { type: c.type, id: b64urlDecode(c.id), transports: c.transports };
                });
            }

            var assertion = await navigator.credentials.get({ publicKey: opt });
            if (!assertion) return { ok: false, error: 'No assertion returned.' };

            var finish = await postJson('/api/webauthn_auth_finish.php', {
                id:                   b64urlEncode(assertion.rawId),
                clientDataJsonB64:    b64urlEncode(assertion.response.clientDataJSON),
                authenticatorDataB64: b64urlEncode(assertion.response.authenticatorData),
                signatureB64:         b64urlEncode(assertion.response.signature),
            });
            return finish;
        } catch (e) {
            return { ok: false, error: e.message || String(e) };
        }
    }

    global.waRegister     = waRegister;
    global.waAuthenticate = waAuthenticate;
})(window);
