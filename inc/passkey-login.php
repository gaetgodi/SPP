<?php
/**
 * SPP Passkey Login
 *
 * Version: 1.1.0
 * Date:    2026-07-07
 *
 * Changes from 1.0.0:
 *   - Button hidden by default, only shown after username entered and
 *     server confirms the user has a passkey registered.
 *   - Added change/blur events and polling to support RoboForm and
 *     browser autofill (which don't trigger the input event).
 *   - Fixed UM username field selector to match dynamic ID pattern
 *     (e.g. username-1431).
 *   - Divider "or" also hidden/shown with button.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'login_form', 'spp_passkey_login_button' );
add_action( 'um_after_login_fields', 'spp_passkey_login_button' );

add_shortcode( 'spp_passkey_login_btn', function() {
    ob_start();
    spp_passkey_login_button();
    return ob_get_clean();
} );

function spp_passkey_login_button(): void {
    if ( is_user_logged_in() ) return;

    $nonce       = wp_create_nonce( SPP_PASSKEY_NONCE_AUTH );
    $ajax_url    = esc_js( admin_url( 'admin-ajax.php' ) );
    $redirect_to = esc_js( $_GET['redirect_to'] ?? home_url() );
    ?>
    <style>
        .spp-pk-login-wrap { margin: 16px 0; text-align: center; }
        .spp-pk-login-divider {
            display: flex; align-items: center; gap: 10px;
            margin: 12px 0; color: #aaa; font-size: 12px;
        }
        .spp-pk-login-divider::before,
        .spp-pk-login-divider::after { content: ''; flex: 1; height: 1px; background: #ddd; }
        .spp-pk-login-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 10px; width: 100%; max-width: 320px; padding: 12px 18px;
            background: #2c3e50; color: #ffffff !important; border: none;
            border-radius: 6px; font-size: 15px; font-weight: bold;
            cursor: pointer; transition: background 0.2s;
        }
        .spp-pk-login-btn:hover { background: #3766AB; }
        .spp-pk-login-btn:disabled { background: #aaa; cursor: not-allowed; }
        .spp-pk-login-btn-icon { font-size: 20px; line-height: 1; }
        .spp-pk-login-status { margin-top: 8px; font-size: 13px; min-height: 18px; color: #555; }
        .spp-pk-login-status.error { color: #c0392b; }
        .spp-pk-login-status.success { color: #27ae60; }
        .spp-pk-login-not-supported { font-size: 12px; color: #aaa; margin-top: 6px; display: none; }
    </style>

    <div class="spp-pk-login-wrap" id="spp-pk-login-wrap">
        <div class="spp-pk-login-divider" id="spp-pk-login-divider" style="display:none;">or</div>
        <button type="button" class="spp-pk-login-btn" id="spp-pk-login-btn" disabled style="display:none;">
            <span class="spp-pk-login-btn-icon">&#128273;</span>
            Sign in with Passkey
        </button>
        <div class="spp-pk-login-status" id="spp-pk-login-status"></div>
        <div class="spp-pk-login-not-supported" id="spp-pk-login-not-supported">
            Passkeys not supported in this browser.
        </div>
    </div>

    <script>
    (function() {
        var ajaxUrl    = '<?php echo $ajax_url; ?>';
        var nonce      = '<?php echo esc_js( $nonce ); ?>';
        var redirectTo = '<?php echo $redirect_to; ?>';

        var btn      = document.getElementById('spp-pk-login-btn');
        var divider  = document.getElementById('spp-pk-login-divider');
        var statusEl = document.getElementById('spp-pk-login-status');
        var noSupp   = document.getElementById('spp-pk-login-not-supported');

        if (!btn) return;

        if (!window.PublicKeyCredential) {
            noSupp.style.display = 'block';
            return;
        }

        function setStatus(text, type) {
            statusEl.textContent = text;
            statusEl.className = 'spp-pk-login-status' + (type ? ' ' + type : '');
        }
        function showBtn() {
            btn.style.display = 'inline-flex';
            btn.disabled = false;
            if (divider) divider.style.display = '';
        }
        function hideBtn() {
            btn.style.display = 'none';
            btn.disabled = true;
            if (divider) divider.style.display = 'none';
            setStatus('', '');
        }
        function base64urlToBuffer(b64) {
            b64 = b64.replace(/-/g, '+').replace(/_/g, '/');
            while (b64.length % 4) b64 += '=';
            var bin = atob(b64);
            var buf = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
            return buf.buffer;
        }
        function bufferToBase64url(buf) {
            var bytes = new Uint8Array(buf);
            var str = '';
            for (var i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
            return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        }
        function encodeAssertion(cred) {
            return {
                id: cred.id, type: cred.type,
                rawId: bufferToBase64url(cred.rawId),
                response: {
                    clientDataJSON:    bufferToBase64url(cred.response.clientDataJSON),
                    authenticatorData: bufferToBase64url(cred.response.authenticatorData),
                    signature:         bufferToBase64url(cred.response.signature),
                    userHandle: cred.response.userHandle
                                ? bufferToBase64url(cred.response.userHandle) : null,
                }
            };
        }
        function getUsernameField() {
            return document.getElementById('user_login')
                || document.getElementById('um-login-id')
                || document.querySelector('[name="username"]')
                || document.querySelector('[name="log"]')
                || document.querySelector('[id^="username-"]');
        }
        function getUsername() {
            var f = getUsernameField(); return f ? f.value.trim() : '';
        }
        function getRemember() {
            var r = document.getElementById('rememberme')
                 || document.querySelector('[name="rememberme"]');
            return r && r.checked ? '1' : '0';
        }

        var checkTimeout = null;
        var lastChecked  = '';

        function checkPasskey() {
            var username = getUsername();
            if (!username) { hideBtn(); return; }
            if (username === lastChecked) return;
            lastChecked = username;
            clearTimeout(checkTimeout);
            checkTimeout = setTimeout(function() {
                var data = new FormData();
                data.append('action', 'spp_passkey_has_passkey');
                data.append('nonce', nonce);
                data.append('username', username);
                fetch(ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data.has_passkey) { showBtn(); }
                        else { hideBtn(); }
                    })
                    .catch(function() { hideBtn(); });
            }, 600);
        }

        var usernameField = getUsernameField();
        if (usernameField) {
            usernameField.addEventListener('input',  checkPasskey);
            usernameField.addEventListener('change', checkPasskey);
            usernameField.addEventListener('blur',   checkPasskey);
        }

        // Poll for RoboForm/autofill (they fill fields without firing events)
        var pollCount = 0;
        var pollInterval = setInterval(function() {
            pollCount++;
            var username = getUsername();
            if (username && username !== lastChecked) checkPasskey();
            if (pollCount >= 40) clearInterval(pollInterval);
        }, 300);

        btn.addEventListener('click', function() {
            btn.disabled = true;
            setStatus('Requesting passkey\u2026', '');

            var username = getUsername();
            var remember = getRemember();

            var data = new FormData();
            data.append('action', 'spp_passkey_auth_options');
            data.append('nonce', nonce);
            if (username) data.append('username', username);

            fetch(ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        if (res.data && res.data.fallback) {
                            setStatus('No passkeys found for this account. Please use your password.', 'error');
                            btn.disabled = false;
                            return Promise.reject('no_passkey');
                        }
                        throw new Error(res.data && res.data.message ? res.data.message : 'Failed to get options.');
                    }
                    var opts = res.data;
                    opts.challenge = base64urlToBuffer(opts.challenge);
                    if (opts.allowCredentials) {
                        opts.allowCredentials = opts.allowCredentials.map(function(c) {
                            c.id = base64urlToBuffer(c.id); return c;
                        });
                    }
                    setStatus('Waiting for passkey\u2026', '');
                    return navigator.credentials.get({ publicKey: opts });
                })
                .then(function(assertion) {
                    if (!assertion) return;
                    setStatus('Verifying\u2026', '');
                    var encoded = encodeAssertion(assertion);
                    var data2 = new FormData();
                    data2.append('action', 'spp_passkey_verify_auth');
                    data2.append('nonce', nonce);
                    data2.append('response', JSON.stringify(encoded));
                    data2.append('remember', remember);
                    if (username) data2.append('username', username);
                    return fetch(ajaxUrl, { method:'POST', body:data2, credentials:'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(res2) {
                            if (res2.success) {
                                setStatus('\u2713 Signed in! Redirecting\u2026', 'success');
                                setTimeout(function() {
                                    window.location.href = res2.data.redirect_to || redirectTo;
                                }, 600);
                            } else {
                                setStatus('\u26a0 ' + (res2.data.message || 'Authentication failed.'), 'error');
                                btn.disabled = false;
                            }
                        });
                })
                .catch(function(err) {
                    if (err === 'no_passkey') return;
                    btn.disabled = false;
                    if (err && err.name === 'NotAllowedError') {
                        setStatus('Passkey prompt was cancelled or timed out.', 'error');
                    } else if (err && err.name === 'SecurityError') {
                        setStatus('Security error \u2014 please use HTTPS.', 'error');
                    } else {
                        setStatus('\u26a0 ' + (err && err.message ? err.message : 'Authentication failed.'), 'error');
                    }
                });
        });

    })();
    </script>
    <?php
}

add_action( 'login_enqueue_scripts', function() {
    // admin-ajax.php is always available, nothing extra needed.
} );