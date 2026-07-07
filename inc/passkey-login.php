<?php
/**
 * SPP Passkey Login
 *
 * Integrates passkey authentication into the WordPress and Ultimate Member
 * login forms. Adds a "Sign in with Passkey" button that triggers the
 * WebAuthn authentication flow without requiring a password.
 *
 * Two integration points:
 *   1. Standard WP login page (wp-login.php) via login_form hook
 *   2. Ultimate Member login form via um_after_login_fields hook
 *      and shortcode [spp_passkey_login_btn] for Divi placement
 *
 * Flow:
 *   1. User clicks "Sign in with Passkey"
 *   2. JS fetches authentication options from server (challenge)
 *   3. Browser prompts for biometric/PIN
 *   4. JS sends assertion to server for verification
 *   5. Server verifies, sets WP auth cookie, JS redirects
 *
 * Version: 1.0.0
 * Date:    2026-07-07
 */

defined( 'ABSPATH' ) || exit;

// ── Hook into WP login form ───────────────────────────────────────────────────
add_action( 'login_form', 'spp_passkey_login_button' );

// ── Hook into Ultimate Member login form ──────────────────────────────────────
add_action( 'um_after_login_fields', 'spp_passkey_login_button' );

// ── Shortcode for Divi Code module placement ──────────────────────────────────
add_shortcode( 'spp_passkey_login_btn', function() {
    ob_start();
    spp_passkey_login_button();
    return ob_get_clean();
} );

// ── Login button render ───────────────────────────────────────────────────────
function spp_passkey_login_button(): void {
    // Don't show if user is already logged in
    if ( is_user_logged_in() ) return;

    $nonce       = wp_create_nonce( SPP_PASSKEY_NONCE_AUTH );
    $ajax_url    = esc_js( admin_url( 'admin-ajax.php' ) );
    $redirect_to = esc_js( $_GET['redirect_to'] ?? home_url() );
    ?>
    <style>
        .spp-pk-login-wrap {
            margin: 16px 0;
            text-align: center;
        }
        .spp-pk-login-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 12px 0;
            color: #aaa;
            font-size: 12px;
        }
        .spp-pk-login-divider::before,
        .spp-pk-login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #ddd;
        }
        .spp-pk-login-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            max-width: 320px;
            padding: 12px 18px;
            background: #2c3e50;
            color: #ffffff !important;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        .spp-pk-login-btn:hover {
            background: #3766AB;
        }
        .spp-pk-login-btn:disabled {
            background: #aaa;
            cursor: not-allowed;
        }
        .spp-pk-login-btn-icon {
            font-size: 20px;
            line-height: 1;
        }
        .spp-pk-login-status {
            margin-top: 8px;
            font-size: 13px;
            min-height: 18px;
            color: #555;
        }
        .spp-pk-login-status.error {
            color: #c0392b;
        }
        .spp-pk-login-status.success {
            color: #27ae60;
        }
        .spp-pk-login-not-supported {
            font-size: 12px;
            color: #aaa;
            margin-top: 6px;
            display: none;
        }
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

        var btn       = document.getElementById('spp-pk-login-btn');
        var statusEl  = document.getElementById('spp-pk-login-status');
        var noSupport = document.getElementById('spp-pk-login-not-supported');

        if (!btn) return;

        // ── Check WebAuthn support ────────────────────────────────────────────
        if (!window.PublicKeyCredential) {
            btn.style.display = 'none';
            noSupport.style.display = 'block';
            return;
        }

        // Hide button initially — only show when username has a registered passkey
        btn.style.display = 'none';

        // Watch username field for changes
        var usernameInput = document.getElementById('user_login')
                         || document.getElementById('um-login-id')
                         || document.querySelector('[name="username"]')
                         || document.querySelector('[name="log"]');

        if (usernameInput) {
            var checkTimeout = null;
            usernameInput.addEventListener('input', function() {
                clearTimeout(checkTimeout);
                var username = usernameInput.value.trim();
                if (!username) {
                    btn.style.display = 'none';
                    setStatus('', '');
                    return;
                }
                // Debounce — wait 600ms after typing stops
                checkTimeout = setTimeout(function() {
                    var data = new FormData();
                    data.append('action', 'spp_passkey_has_passkey');
                    data.append('nonce', nonce);
                    data.append('username', username);
                    fetch(ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success && res.data.has_passkey) {
                                btn.style.display = 'inline-flex';
                                btn.disabled = false;
                                var divider = document.getElementById('spp-pk-login-divider');
                                if (divider) divider.style.display = '';
                                setStatus('', '');
                            } else {
                                btn.style.display = 'none';
                                setStatus('', '');
                            }
                        })
                        .catch(function() {
                            btn.style.display = 'none';
                        });
                }, 600);
            });
        }

        // ── Utilities ─────────────────────────────────────────────────────────
        function setStatus(text, type) {
            statusEl.textContent = text;
            statusEl.className = 'spp-pk-login-status' + (type ? ' ' + type : '');
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
                id:    cred.id,
                type:  cred.type,
                rawId: bufferToBase64url(cred.rawId),
                response: {
                    clientDataJSON:    bufferToBase64url(cred.response.clientDataJSON),
                    authenticatorData: bufferToBase64url(cred.response.authenticatorData),
                    signature:         bufferToBase64url(cred.response.signature),
                    userHandle:        cred.response.userHandle
                                       ? bufferToBase64url(cred.response.userHandle)
                                       : null,
                }
            };
        }

        // ── Get username from login form if present ───────────────────────────
        function getUsername() {
            var loginInput = document.getElementById('user_login')          // WP login
                          || document.getElementById('um-login-id')         // UM login
                          || document.querySelector('[name="username"]')     // generic
                          || document.querySelector('[name="log"]');         // WP legacy
            return loginInput ? loginInput.value.trim() : '';
        }

        // ── Get remember-me from login form if present ────────────────────────
        function getRemember() {
            var rememberInput = document.getElementById('rememberme')
                             || document.querySelector('[name="rememberme"]');
            return rememberInput && rememberInput.checked ? '1' : '0';
        }

        // ── Main passkey login flow ───────────────────────────────────────────
        btn.addEventListener('click', function() {
            btn.disabled = true;
            setStatus('Requesting passkey\u2026', '');

            var username = getUsername();
            var remember = getRemember();

            // Step 1: get challenge options from server
            var data = new FormData();
            data.append('action', 'spp_passkey_auth_options');
            data.append('nonce', nonce);
            if (username) data.append('username', username);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        // If fallback flag set, user has no passkeys — show friendly message
                        if (res.data && res.data.fallback) {
                            setStatus('No passkeys found for this account. Please use your password.', 'error');
                            btn.disabled = false;
                            return Promise.reject('no_passkey');
                        }
                        throw new Error(res.data && res.data.message ? res.data.message : 'Failed to get options.');
                    }

                    // Step 2: decode challenge and allowCredentials
                    var opts = res.data;
                    opts.challenge = base64urlToBuffer(opts.challenge);
                    if (opts.allowCredentials) {
                        opts.allowCredentials = opts.allowCredentials.map(function(c) {
                            c.id = base64urlToBuffer(c.id);
                            return c;
                        });
                    }

                    setStatus('Waiting for passkey\u2026', '');

                    // Step 3: prompt device
                    return navigator.credentials.get({ publicKey: opts });
                })
                .then(function(assertion) {
                    if (!assertion) return; // user cancelled prompt
                    setStatus('Verifying\u2026', '');

                    var encoded = encodeAssertion(assertion);

                    // Step 4: verify with server
                    var data2 = new FormData();
                    data2.append('action', 'spp_passkey_verify_auth');
                    data2.append('nonce', nonce);
                    data2.append('response', JSON.stringify(encoded));
                    data2.append('remember', remember);
                    if (username) data2.append('username', username);

                    return fetch(ajaxUrl, { method: 'POST', body: data2, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(res2) {
                            if (res2.success) {
                                setStatus('&#10003; Signed in! Redirecting\u2026', 'success');
                                var dest = res2.data.redirect_to || redirectTo;
                                setTimeout(function() {
                                    window.location.href = dest;
                                }, 600);
                            } else {
                                setStatus('&#9888; ' + (res2.data.message || 'Authentication failed.'), 'error');
                                btn.disabled = false;
                            }
                        });
                })
                .catch(function(err) {
                    if (err === 'no_passkey') return; // already handled above
                    btn.disabled = false;
                    if (err && err.name === 'NotAllowedError') {
                        setStatus('Passkey prompt was cancelled or timed out.', 'error');
                    } else if (err && err.name === 'SecurityError') {
                        setStatus('Security error — please use HTTPS.', 'error');
                    } else {
                        setStatus('&#9888; ' + (err && err.message ? err.message : 'Authentication failed.'), 'error');
                    }
                });
        });

    })();
    </script>
    <?php
}

// ── Enqueue login page styles/scripts ────────────────────────────────────────
// The inline script above handles everything, but we need to ensure
// admin-ajax.php is available on the login page.
add_action( 'login_enqueue_scripts', function() {
    // admin-ajax.php is always available, nothing extra needed.
    // This hook is here as a placeholder for future script enqueuing.
} );
