<?php
/**
 * SPP Passkey Profile
 *
 * Adds a "My Passkeys" section to the WordPress user profile and the
 * Ultimate Member account page, allowing users to:
 *   - See their registered passkeys (device name, created date, last used)
 *   - Register a new passkey
 *   - Rename a passkey
 *   - Delete a passkey (with last-passkey guard)
 *
 * The UI is self-contained: one shortcode [spp_passkey_profile] for
 * embedding in a Divi page or UM account tab, plus hooks into the
 * standard WP profile screen for admin use.
 *
 * Version: 1.1.0
 * Date:    2026-07-08
 *
 * Changes from 1.0.0:
 *   - Added type="button" to all buttons to prevent UM form submission
 *     when passkey profile is rendered inside a UM account tab.
 *   - Added e.preventDefault() to list click handlers.
 */

defined( 'ABSPATH' ) || exit;

// ── Shortcode: [spp_passkey_profile] ─────────────────────────────────────────
add_shortcode( 'spp_passkey_profile', 'spp_passkey_profile_shortcode' );

function spp_passkey_profile_shortcode(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to manage your passkeys.</p>';
    }

    $user_id     = get_current_user_id();
    $credentials = spp_passkey_get_user_credentials( $user_id );

    ob_start();
    spp_passkey_profile_enqueue();
    spp_passkey_profile_render( $credentials );
    return ob_get_clean();
}

// ── WP admin profile hook ─────────────────────────────────────────────────────
add_action( 'show_user_profile', 'spp_passkey_profile_wp_admin' );
add_action( 'edit_user_profile', 'spp_passkey_profile_wp_admin' );

function spp_passkey_profile_wp_admin( WP_User $profile_user ): void {
    // On edit_user_profile (admin editing someone else), only show for self
    if ( $profile_user->ID !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $user_id     = $profile_user->ID;
    $credentials = spp_passkey_get_user_credentials( $user_id );
    spp_passkey_profile_enqueue();
    echo '<h2>Passkeys</h2>';
    spp_passkey_profile_render( $credentials );
}

// ── Enqueue scripts and styles ────────────────────────────────────────────────
function spp_passkey_profile_enqueue(): void {
    static $enqueued = false;
    if ( $enqueued ) return;
    $enqueued = true;

    // Inline script — no external file needed, keeps deployment simple
    add_action( 'wp_footer',    'spp_passkey_profile_script' );
    add_action( 'admin_footer', 'spp_passkey_profile_script' );
}

// ── Profile UI render ─────────────────────────────────────────────────────────
function spp_passkey_profile_render( array $credentials ): void {
    $mgt_nonce = wp_create_nonce( SPP_PASSKEY_NONCE_MGT );
    $reg_nonce = wp_create_nonce( SPP_PASSKEY_NONCE_REG );
    $ajax_url  = esc_js( admin_url( 'admin-ajax.php' ) );
    ?>
    <style>
        .spp-pk-wrap { max-width:640px; margin:20px 0; font-family:Arial,sans-serif; font-size:14px; }
        .spp-pk-heading { font-size:1.1rem; font-weight:bold; color:#2c3e50; margin:0 0 12px; border-bottom:2px solid #3766AB; padding-bottom:6px; }
        .spp-pk-intro { color:#555; margin:0 0 14px; font-size:13px; }
        .spp-pk-list { list-style:none; margin:0 0 16px; padding:0; }
        .spp-pk-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid #ddd; border-radius:6px; margin-bottom:8px; background:#fff; }
        .spp-pk-icon { font-size:22px; flex-shrink:0; }
        .spp-pk-info { flex:1 1 auto; min-width:0; }
        .spp-pk-name { font-weight:bold; color:#2c3e50; }
        .spp-pk-meta { font-size:12px; color:#888; margin-top:2px; }
        .spp-pk-actions { display:flex; gap:6px; flex-shrink:0; }
        .spp-pk-btn { padding:5px 10px; border:none; border-radius:4px; font-size:12px; cursor:pointer; }
        .spp-pk-btn-rename { background:#f0f7ff; color:#3766AB; border:1px solid #3766AB; }
        .spp-pk-btn-rename:hover { background:#3766AB; color:#fff; }
        .spp-pk-btn-delete { background:#fdf3f2; color:#c0392b; border:1px solid #c0392b; }
        .spp-pk-btn-delete:hover { background:#c0392b; color:#fff; }
        .spp-pk-empty { color:#888; font-style:italic; margin-bottom:14px; }
        .spp-pk-add-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; background:#3766AB; color:#fff; border:none; border-radius:6px; font-size:14px; cursor:pointer; }
        .spp-pk-add-btn:hover { background:#2a5290; }
        .spp-pk-add-btn:disabled { background:#aaa; cursor:not-allowed; }
        .spp-pk-msg { padding:10px 14px; border-radius:6px; margin:10px 0; font-size:13px; display:none; }
        .spp-pk-msg-ok  { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .spp-pk-msg-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .spp-pk-spinner { display:none; font-size:13px; color:#888; margin-left:10px; }

        /* Rename inline form */
        .spp-pk-rename-form { display:none; margin-top:8px; display:none; gap:6px; align-items:center; }
        .spp-pk-rename-form input { padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:200px; }
        .spp-pk-rename-save { padding:5px 10px; background:#27ae60; color:#fff; border:none; border-radius:4px; font-size:12px; cursor:pointer; }
        .spp-pk-rename-cancel { padding:5px 10px; background:#eee; color:#333; border:none; border-radius:4px; font-size:12px; cursor:pointer; }

        /* Not supported warning */
        .spp-pk-not-supported { background:#fff3cd; border:1px solid #ffc107; color:#856404; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:12px; display:none; }
    </style>

    <div class="spp-pk-wrap" id="spp-pk-wrap">
        <div class="spp-pk-heading">&#128273; My Passkeys</div>
        <p class="spp-pk-intro">
            Passkeys let you sign in with your fingerprint, face, or device PIN — no password needed.
            You can register multiple passkeys for different devices.
        </p>

        <div class="spp-pk-not-supported" id="spp-pk-not-supported">
            &#9888; Your browser does not support passkeys. Try Chrome, Safari 16+, Firefox 122+, or Edge.
        </div>

        <div class="spp-pk-msg" id="spp-pk-msg"></div>

        <ul class="spp-pk-list" id="spp-pk-list">
            <?php if ( empty( $credentials ) ) : ?>
                <li class="spp-pk-empty" id="spp-pk-empty">No passkeys registered yet.</li>
            <?php else : ?>
                <?php foreach ( $credentials as $cred ) : ?>
                    <li class="spp-pk-item" data-id="<?php echo esc_attr( $cred->id ); ?>">
                        <div class="spp-pk-icon">&#128241;</div>
                        <div class="spp-pk-info">
                            <div class="spp-pk-name"><?php echo esc_html( $cred->device_name ?: 'Unnamed device' ); ?></div>
                            <div class="spp-pk-meta">
                                Added <?php echo esc_html( wp_date( 'F j, Y', strtotime( $cred->created_at ) ) ); ?>
                                &middot;
                                Last used: <?php echo $cred->last_used ? esc_html( wp_date( 'F j, Y', strtotime( $cred->last_used ) ) ) : 'Never'; ?>
                            </div>
                            <div class="spp-pk-rename-form" id="spp-pk-rename-<?php echo esc_attr( $cred->id ); ?>">
                                <input type="text" value="<?php echo esc_attr( $cred->device_name ?: '' ); ?>" maxlength="100" placeholder="Device name">
                                <button type="button" class="spp-pk-rename-save" data-id="<?php echo esc_attr( $cred->id ); ?>">Save</button>
                                <button type="button" class="spp-pk-rename-cancel" data-id="<?php echo esc_attr( $cred->id ); ?>">Cancel</button>
                            </div>
                        </div>
                        <div class="spp-pk-actions">
                            <button type="button" class="spp-pk-btn spp-pk-btn-rename" data-action="rename" data-id="<?php echo esc_attr( $cred->id ); ?>">Rename</button>
                            <button type="button" class="spp-pk-btn spp-pk-btn-delete" data-action="delete" data-id="<?php echo esc_attr( $cred->id ); ?>">Delete</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <button class="spp-pk-add-btn" id="spp-pk-add-btn" disabled>
            <span>&#43;</span> Add a Passkey
        </button>
        <span class="spp-pk-spinner" id="spp-pk-spinner">&#8987; Working&hellip;</span>
    </div>

    <script>
    // Pass PHP values to JS
    window.sppPasskeyProfile = {
        ajaxUrl:   '<?php echo $ajax_url; ?>',
        mgtNonce:  '<?php echo esc_js( $mgt_nonce ); ?>',
        regNonce:  '<?php echo esc_js( $reg_nonce ); ?>',
        userId:    <?php echo get_current_user_id(); ?>
    };
    </script>
    <?php
}

// ── Profile JavaScript ────────────────────────────────────────────────────────
function spp_passkey_profile_script(): void {
    // Only output once
    static $done = false;
    if ( $done ) return;
    $done = true;

    // Only output if the profile wrap is on this page
    ?>
    <script>
    (function() {
        var cfg = window.sppPasskeyProfile;
        if (!cfg) return;

        var wrap    = document.getElementById('spp-pk-wrap');
        if (!wrap) return;

        var list    = document.getElementById('spp-pk-list');
        var addBtn  = document.getElementById('spp-pk-add-btn');
        var spinner = document.getElementById('spp-pk-spinner');
        var msgEl   = document.getElementById('spp-pk-msg');
        var noSupport = document.getElementById('spp-pk-not-supported');

        // ── Check WebAuthn support ────────────────────────────────────────────
        if (!window.PublicKeyCredential) {
            noSupport.style.display = 'block';
            addBtn.style.display = 'none';
            return;
        }
        addBtn.disabled = false;

        // ── Utility ───────────────────────────────────────────────────────────
        function showMsg(text, ok) {
            msgEl.textContent = text;
            msgEl.className = 'spp-pk-msg ' + (ok ? 'spp-pk-msg-ok' : 'spp-pk-msg-err');
            msgEl.style.display = 'block';
            setTimeout(function() { msgEl.style.display = 'none'; }, 5000);
        }

        function setWorking(on) {
            addBtn.disabled = on;
            spinner.style.display = on ? 'inline' : 'none';
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

        // Encode a PublicKeyCredential for sending to server
        function encodeCredential(cred) {
            var obj = {
                id:    cred.id,
                type:  cred.type,
                rawId: bufferToBase64url(cred.rawId),
                response: {}
            };
            // Attestation response
            if (cred.response.attestationObject !== undefined) {
                obj.response.clientDataJSON    = bufferToBase64url(cred.response.clientDataJSON);
                obj.response.attestationObject = bufferToBase64url(cred.response.attestationObject);
                if (cred.response.getTransports) {
                    obj.response.transports = cred.response.getTransports();
                }
            }
            // Assertion response
            if (cred.response.authenticatorData !== undefined) {
                obj.response.clientDataJSON    = bufferToBase64url(cred.response.clientDataJSON);
                obj.response.authenticatorData = bufferToBase64url(cred.response.authenticatorData);
                obj.response.signature         = bufferToBase64url(cred.response.signature);
                if (cred.response.userHandle) {
                    obj.response.userHandle    = bufferToBase64url(cred.response.userHandle);
                }
            }
            return obj;
        }

        // Decode challenge/id buffers in options from server
        function decodeCreationOptions(opts) {
            opts.challenge = base64urlToBuffer(opts.challenge);
            opts.user.id  = base64urlToBuffer(opts.user.id);
            if (opts.excludeCredentials) {
                opts.excludeCredentials = opts.excludeCredentials.map(function(c) {
                    c.id = base64urlToBuffer(c.id);
                    return c;
                });
            }
            return opts;
        }

        // ── Render credential list from server data ───────────────────────────
        function renderList(credentials) {
            var empty = document.getElementById('spp-pk-empty');
            if (empty) empty.remove();

            // Remove existing credential items
            var items = list.querySelectorAll('.spp-pk-item');
            items.forEach(function(el) { el.remove(); });

            if (!credentials || credentials.length === 0) {
                var li = document.createElement('li');
                li.className = 'spp-pk-empty';
                li.id = 'spp-pk-empty';
                li.textContent = 'No passkeys registered yet.';
                list.appendChild(li);
                return;
            }

            credentials.forEach(function(cred) {
                var li = document.createElement('li');
                li.className = 'spp-pk-item';
                li.dataset.id = cred.id;
                li.innerHTML =
                    '<div class="spp-pk-icon">&#128241;</div>' +
                    '<div class="spp-pk-info">' +
                        '<div class="spp-pk-name">' + escHtml(cred.device_name) + '</div>' +
                        '<div class="spp-pk-meta">Added ' + escHtml(cred.created_at) + ' &middot; Last used: ' + escHtml(cred.last_used) + '</div>' +
                        '<div class="spp-pk-rename-form" id="spp-pk-rename-' + cred.id + '" style="display:none;">' +
                            '<input type="text" value="' + escAttr(cred.device_name) + '" maxlength="100" placeholder="Device name">' +
                            '<button type="button" class="spp-pk-rename-save" data-id="' + cred.id + '">Save</button>' +
                            '<button type="button" class="spp-pk-rename-cancel" data-id="' + cred.id + '">Cancel</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="spp-pk-actions">' +
                        '<button type="button" class="spp-pk-btn spp-pk-btn-rename" data-action="rename" data-id="' + cred.id + '">Rename</button>' +
                        '<button type="button" class="spp-pk-btn spp-pk-btn-delete" data-action="delete" data-id="' + cred.id + '">Delete</button>' +
                    '</div>';
                list.appendChild(li);
            });
        }

        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function escAttr(str) {
            return String(str).replace(/"/g,'&quot;');
        }

        // ── Add passkey ───────────────────────────────────────────────────────
        addBtn.addEventListener('click', function() {
            setWorking(true);

            // Step 1: get options from server
            var data = new FormData();
            data.append('action', 'spp_passkey_reg_options');
            data.append('nonce', cfg.regNonce);

            fetch(cfg.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) throw new Error(res.data.message || 'Failed to get options.');
                    var opts = decodeCreationOptions(res.data);

                    // Step 2: prompt device for passkey
                    return navigator.credentials.create({ publicKey: opts });
                })
                .then(function(cred) {
                    // Step 3: prompt for device name
                    var deviceName = prompt('Name this passkey (e.g. iPhone, Work laptop):', window.sppPasskeyProfile.guessedName || '');
                    if (deviceName === null) {
                        setWorking(false);
                        return; // user cancelled
                    }

                    // Step 4: send to server for verification
                    var encoded = encodeCredential(cred);
                    var data2 = new FormData();
                    data2.append('action', 'spp_passkey_verify_reg');
                    data2.append('nonce', cfg.regNonce);
                    data2.append('response', JSON.stringify(encoded));
                    data2.append('device_name', deviceName.trim());

                    return fetch(cfg.ajaxUrl, { method:'POST', body:data2, credentials:'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(res2) {
                            setWorking(false);
                            if (res2.success) {
                                showMsg('&#10003; ' + res2.data.message, true);
                                renderList(res2.data.credentials);
                            } else {
                                showMsg('&#9888; ' + (res2.data.message || 'Registration failed.'), false);
                            }
                        });
                })
                .catch(function(err) {
                    setWorking(false);
                    if (err.name === 'NotAllowedError') {
                        showMsg('Passkey prompt was cancelled or timed out.', false);
                    } else {
                        showMsg('&#9888; ' + err.message, false);
                    }
                });
        });

        // ── Rename / Delete (event delegation on list) ────────────────────────
        list.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            e.preventDefault(); // prevent UM form submission when inside account tab

            var id     = parseInt(btn.dataset.id, 10);
            var action = btn.dataset.action;

            if (action === 'rename') {
                var form = document.getElementById('spp-pk-rename-' + id);
                if (form) {
                    form.style.display = form.style.display === 'flex' ? 'none' : 'flex';
                }
            }

            if (action === 'delete') {
                if (!confirm('Delete this passkey? You will no longer be able to sign in with it.')) return;

                var data = new FormData();
                data.append('action', 'spp_passkey_delete');
                data.append('nonce', cfg.mgtNonce);
                data.append('credential_id', id);

                fetch(cfg.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            showMsg('Passkey deleted.', true);
                            renderList(res.data.credentials);
                        } else {
                            showMsg('&#9888; ' + (res.data.message || 'Delete failed.'), false);
                        }
                    });
            }
        });

        // ── Rename save/cancel (event delegation) ─────────────────────────────
        list.addEventListener('click', function(e) {
            e.preventDefault(); // prevent UM form submission when inside account tab
            var saveBtn   = e.target.closest('.spp-pk-rename-save');
            var cancelBtn = e.target.closest('.spp-pk-rename-cancel');

            if (saveBtn) {
                var id   = parseInt(saveBtn.dataset.id, 10);
                var form = document.getElementById('spp-pk-rename-' + id);
                var name = form.querySelector('input').value.trim();
                if (!name) { showMsg('Device name cannot be empty.', false); return; }

                var data = new FormData();
                data.append('action', 'spp_passkey_rename');
                data.append('nonce', cfg.mgtNonce);
                data.append('credential_id', id);
                data.append('device_name', name);

                fetch(cfg.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            showMsg('Passkey renamed.', true);
                            renderList(res.data.credentials);
                        } else {
                            showMsg('&#9888; ' + (res.data.message || 'Rename failed.'), false);
                        }
                    });
            }

            if (cancelBtn) {
                var id2  = parseInt(cancelBtn.dataset.id, 10);
                var form2 = document.getElementById('spp-pk-rename-' + id2);
                if (form2) form2.style.display = 'none';
            }
        });

    })();
    </script>
    <?php
}