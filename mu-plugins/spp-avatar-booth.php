<?php
/**
 * Plugin Name: SPP Avatar Booth
 * Description: Front-end avatar capture with camera, background replacement, photo editing, and one-click profile photo update.
 * Version: 1.0.0
 * Author: Godin London Inc.
 *
 * Shortcode: [spp_avatar_booth]
 * Place on a front-end page (e.g., under the user's Account tab).
 * User must be logged in. Requires Chrome or Edge for camera access.
 *
 * mu-plugin: symlink to wp-content/mu-plugins/spp-avatar-booth.php
 */

if (!defined('ABSPATH')) exit;

// ════════════════════════
// 1. AVATAR FILTER — serve custom avatar instead of Gravatar
// ════════════════════════
add_filter('get_avatar_url', function($url, $id_or_email, $args) {
    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_object($id_or_email)) {
        if (!empty($id_or_email->user_id)) $user_id = $id_or_email->user_id;
        elseif (!empty($id_or_email->comment_author_email)) {
            $user = get_user_by('email', $id_or_email->comment_author_email);
            if ($user) $user_id = $user->ID;
        }
    } elseif (is_string($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        if ($user) $user_id = $user->ID;
    }

    if ($user_id) {
        $attachment_id = get_user_meta($user_id, 'spp_custom_avatar', true);
        if ($attachment_id) {
            $custom_url = wp_get_attachment_url($attachment_id);
            if ($custom_url) return $custom_url;
        }
    }
    return $url;
}, 10, 3);

// Hook into Ultimate Member's avatar system
add_filter('um_user_avatar_url_filter', function($url, $user_id) {
    $attachment_id = get_user_meta($user_id, 'spp_custom_avatar', true);
    if ($attachment_id) {
        $custom_url = wp_get_attachment_url($attachment_id);
        if ($custom_url) return $custom_url;
    }
    return $url;
}, 10, 2);

add_filter('um_avatar_default_in_member_directory', function($url, $user_id) {
    $attachment_id = get_user_meta($user_id, 'spp_custom_avatar', true);
    if ($attachment_id) {
        $custom_url = wp_get_attachment_url($attachment_id);
        if ($custom_url) return $custom_url;
    }
    return $url;
}, 10, 2);

// ════════════════════════
// 2. REST API — receive and save avatar photo
// ════════════════════════
add_action('rest_api_init', function() {
    register_rest_route('spp/v1', '/avatar', [
        'methods' => 'POST',
        'callback' => 'spp_avatar_booth_save',
        'permission_callback' => function() { return is_user_logged_in(); },
    ]);

    register_rest_route('spp/v1', '/avatar', [
        'methods' => 'DELETE',
        'callback' => 'spp_avatar_booth_delete',
        'permission_callback' => function() { return is_user_logged_in(); },
    ]);

    register_rest_route('spp/v1', '/avatar/email', [
        'methods' => 'POST',
        'callback' => 'spp_avatar_booth_email',
        'permission_callback' => function() { return is_user_logged_in(); },
    ]);
});

function spp_avatar_booth_email($request) {
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    $attachment_id = get_user_meta($user_id, 'spp_custom_avatar', true);

    if (!$attachment_id) {
        return new WP_Error('no_avatar', 'No custom avatar to email', ['status' => 400]);
    }
    $avatar_url = wp_get_attachment_url($attachment_id);
    $to = $user->user_email;
    $name = $user->display_name;

    // Load Brevo config — reuse the PHE70 booth config if present, else check theme
    $config_paths = [
        WP_CONTENT_DIR . '/spp-brevo-config.json',
        get_stylesheet_directory() . '/spp-brevo-config.json',
    ];
    $apiKey = ''; $fromEmail = 'noreply@pickleballstouffville.ca'; $fromName = 'Stouffville Pickleball';
    foreach ($config_paths as $cp) {
        if (file_exists($cp)) {
            $cfg = json_decode(file_get_contents($cp), true);
            if (!empty($cfg['brevo_api_key'])) {
                $apiKey = $cfg['brevo_api_key'];
                $fromEmail = $cfg['from_email'] ?? $fromEmail;
                $fromName = $cfg['from_name'] ?? $fromName;
                break;
            }
        }
    }

    if (empty($apiKey)) {
        return new WP_Error('no_config', 'Email not configured (missing spp-brevo-config.json)', ['status' => 500]);
    }

    $emailData = [
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $to, 'name' => $name]],
        'subject' => 'Your Stouffville Pickleball Profile Photo',
        'htmlContent' => "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;text-align:center;'>
                <h2 style='color:#2a7c4f;'>Your Profile Photo</h2>
                <p style='color:#555;'>Hi {$name},</p>
                <p style='color:#555;'>Here's a copy of your Stouffville Pickleball profile photo.</p>
                <img src='{$avatar_url}' alt='Profile Photo' style='max-width:300px;border:3px solid #2a7c4f;border-radius:8px;margin:1rem 0;'/>
                <p style='color:#888;font-size:0.8em;'>Stouffville Pickleball</p>
            </div>
        "
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($emailData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'api-key: ' . $apiKey],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'message' => 'Photo emailed to ' . $to];
    }
    return new WP_Error('send_failed', 'Email failed to send', ['status' => 500, 'response' => $response]);
}

function spp_avatar_booth_save($request) {
    $user_id = get_current_user_id();
    $image_data = $request->get_param('image');

    if (empty($image_data)) {
        return new WP_Error('missing_image', 'No image data provided', ['status' => 400]);
    }

    // Decode base64
    if (preg_match('/^data:image\/(\w+);base64,/', $image_data, $matches)) {
        $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $image_data = substr($image_data, strpos($image_data, ',') + 1);
    } else {
        $ext = 'png';
    }
    $decoded = base64_decode($image_data);
    if ($decoded === false || strlen($decoded) < 100) {
        return new WP_Error('invalid_image', 'Invalid image data', ['status' => 400]);
    }

    // Delete previous custom avatar if exists
    $old_attachment = get_user_meta($user_id, 'spp_custom_avatar', true);
    if ($old_attachment) {
        wp_delete_attachment($old_attachment, true);
    }

    // Save to uploads
    $upload_dir = wp_upload_dir();
    $filename = 'avatar-' . $user_id . '-' . time() . '.' . $ext;
    $filepath = $upload_dir['path'] . '/' . $filename;
    file_put_contents($filepath, $decoded);

    // Create attachment
    $attachment = [
        'post_mime_type' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
        'post_title' => 'Avatar for user ' . $user_id,
        'post_content' => '',
        'post_status' => 'inherit',
    ];
    $attachment_id = wp_insert_attachment($attachment, $filepath);

    if (is_wp_error($attachment_id)) {
        return new WP_Error('upload_failed', 'Failed to save avatar', ['status' => 500]);
    }

    // Generate metadata and thumbnails
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attachment_id, $filepath);
    wp_update_attachment_metadata($attachment_id, $metadata);

    // Store as user's custom avatar
    update_user_meta($user_id, 'spp_custom_avatar', $attachment_id);

    return [
        'ok' => true,
        'avatar_url' => wp_get_attachment_url($attachment_id),
        'message' => 'Avatar updated!',
    ];
}

function spp_avatar_booth_delete($request) {
    $user_id = get_current_user_id();
    $attachment_id = get_user_meta($user_id, 'spp_custom_avatar', true);
    if ($attachment_id) {
        wp_delete_attachment($attachment_id, true);
        delete_user_meta($user_id, 'spp_custom_avatar');
    }
    return ['ok' => true, 'message' => 'Avatar removed. Gravatar will be used.'];
}

// ════════════════════════
// 3a. SHORTCODE — [spp_user_avatar]
//     Displays the current user's avatar anywhere.
//     Attributes: size (px, default 80), link (url to avatar booth page, optional)
//     Example: [spp_user_avatar size="100" link="/my-account/update-photo/"]
// ════════════════════════
add_shortcode('spp_user_avatar', function($atts) {
    if (!is_user_logged_in()) return '';

    $atts = shortcode_atts([
        'size' => 80,
        'link' => '',
    ], $atts);

    $user = wp_get_current_user();
    $size = intval($atts['size']);
    $avatar_url = get_avatar_url($user->ID, ['size' => $size * 2]); // 2x for retina
    $alt = esc_attr($user->display_name);

    $img = sprintf(
        '<img src="%s" alt="%s" style="width:%dpx;height:%dpx;border-radius:50%%;object-fit:cover;display:block;margin:0 auto;border:3px solid var(--spp-color-primary,#2a7c4f);" />',
        esc_url($avatar_url), $alt, $size, $size
    );

    if (!empty($atts['link'])) {
        $img = sprintf('<a href="%s" title="Update your photo" style="display:block;text-align:center;">%s</a>', esc_url($atts['link']), $img);
    }

    return '<div style="text-align:center;margin:0.5rem 0;">' . $img . '</div>';
});

// ════════════════════════
// 3b. SHORTCODE — [spp_avatar_booth]
// ════════════════════════
add_shortcode('spp_avatar_booth', function() {
    if (!is_user_logged_in()) {
        return '<p>Please log in to update your photo.</p>';
    }

    $user = wp_get_current_user();
    $current_avatar = get_avatar_url($user->ID, ['size' => 200]);
    $nonce = wp_create_nonce('wp_rest');
    $rest_url = rest_url('spp/v1/avatar');

    ob_start();
    ?>
    <!-- MediaPipe Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/selfie_segmentation.js" crossorigin="anonymous"></script>

    <style>
        .avb-container { max-width: 800px; margin: 0 auto !important; font-family: var(--spp-font-body, 'Inter', sans-serif); text-align: center; width: 100%; padding: 0 1rem; box-sizing: border-box; }
        .avb-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; justify-content: center; text-align: left; }
        .avb-current { width: 80px; height: 80px; border-radius: 50%; border: 3px solid var(--spp-color-primary, #2a7c4f); object-fit: cover; }
        .avb-header h3 { margin: 0; color: var(--spp-color-heading, #1a1a1a); }
        .avb-header p { margin: 0.2rem 0 0; font-size: 0.85rem; color: #666; }
        .avb-status { font-size: 0.85rem; padding: 0.5rem 0; color: #666; min-height: 1.5em; text-align: center; }
        .avb-status.success { color: var(--spp-color-primary, #2a7c4f); font-weight: 600; }
        .avb-status.error { color: #c92a2a; }

        /* Camera area */
        .avb-camera-wrap {
            position: relative; background: #111; border-radius: 8px; overflow: hidden;
            aspect-ratio: 4/3; max-height: 450px; margin: 0 auto 0.8rem !important;
            max-width: 640px; width: 100%; box-sizing: border-box;
        }
        .avb-camera-wrap video { display: none; }
        .avb-camera-wrap canvas { width: 100%; height: 100%; display: block; }
        .avb-frame {
            position: absolute; inset: 0; pointer-events: none; z-index: 5;
        }
        .avb-camera-wrap .avb-loading {
            position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; background: rgba(0,0,0,0.8); color: #ccc; z-index: 10;
        }
        .avb-camera-wrap .avb-loading.hidden { display: none; }

        /* Controls */
        .avb-controls { display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0 auto 0.8rem !important; justify-content: center; max-width: 640px; width: 100%; }
        .avb-btn {
            padding: 0.5rem 1.2rem; border: none; border-radius: 6px;
            font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: opacity 0.2s;
        }
        .avb-btn:hover { opacity: 0.85; }
        .avb-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .avb-btn-primary { background: var(--spp-color-primary, #2a7c4f); color: white; }
        .avb-btn-gold { background: #c9a84c; color: #1a1a1a; }
        .avb-btn-danger { background: #c92a2a; color: white; }
        .avb-btn-muted { background: #999; color: white; }

        /* Background selector */
        .avb-bg-bar { display: flex; gap: 0.3rem; flex-wrap: wrap; margin: 0 auto 0.8rem !important; justify-content: center; max-width: 640px; }
        .avb-bg-opt {
            width: 40px; height: 28px; border-radius: 4px; cursor: pointer;
            border: 2px solid transparent; transition: border-color 0.2s;
        }
        .avb-bg-opt.selected { border-color: var(--spp-color-primary, #2a7c4f); box-shadow: 0 0 0 2px var(--spp-color-primary, #2a7c4f); }

        /* Preview */
        .avb-preview {
            display: none; position: absolute; inset: 0; z-index: 20;
            background: rgba(0,0,0,0.85); align-items: center; justify-content: center;
            flex-direction: column; gap: 0.8rem;
        }
        .avb-preview.active { display: flex; }
        .avb-preview img { max-width: 85%; max-height: 55%; border: 3px solid #c9a84c; border-radius: 6px; }

        /* Editor */
        .avb-editor { display: none; margin-top: 0.8rem; }
        .avb-editor.active { display: block; text-align: center; width: 100%; }
        .avb-editor-canvas-wrap { position: relative; display: inline-block; max-width: 100%; }
        .avb-editor canvas { max-width: 100%; border: 2px solid #c9a84c; border-radius: 4px; display: block; }
        .avb-sliders { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 1rem; margin: 0.8rem auto 0; text-align: left; max-width: 500px; width: 100%; padding: 0 0.5rem; box-sizing: border-box; }
        .avb-slider-label { font-size: 0.8rem !important; color: #444 !important; display: flex !important; justify-content: space-between; margin-bottom: 0.2rem; font-weight: 500; }
        .avb-slider-label span { font-weight: 700; color: var(--spp-color-primary, #2a7c4f) !important; }
        .avb-sliders input[type="range"] { width: 100%; accent-color: var(--spp-color-primary, #2a7c4f); touch-action: none; height: 24px; }
        .avb-editor-actions { display: flex; gap: 0.5rem; margin-top: 0.8rem; flex-wrap: wrap; justify-content: center; }

        /* Crop */
        .avb-crop-box {
            position: absolute; border: 2px dashed #c9a84c; cursor: move;
            min-width: 50px; min-height: 50px; display: none; touch-action: none;
        }
        .avb-crop-handle {
            position: absolute; width: 20px; height: 20px;
            background: #c9a84c; border: 2px solid #1a1a1a; border-radius: 3px;
            touch-action: none;
        }
        .avb-crop-handle.tl { top: -10px; left: -10px; cursor: nw-resize; }
        .avb-crop-handle.tr { top: -10px; right: -10px; cursor: ne-resize; }
        .avb-crop-handle.bl { bottom: -10px; left: -10px; cursor: sw-resize; }
        .avb-crop-handle.br { bottom: -10px; right: -10px; cursor: se-resize; }

        .avb-countdown {
            position: absolute; inset: 0; display: none; align-items: center;
            justify-content: center; z-index: 15; background: rgba(0,0,0,0.15);
        }
        .avb-countdown.active { display: flex; }
        .avb-countdown-num {
            font-size: 5rem; color: white; font-weight: 800;
            text-shadow: 0 4px 20px rgba(0,0,0,0.5);
            animation: avb-pulse 1s ease-in-out;
        }
        @keyframes avb-pulse { 0%{transform:scale(1.3);opacity:.3} 50%{transform:scale(1);opacity:1} 100%{transform:scale(.95);opacity:.9} }

        .avb-flash { position: absolute; inset: 0; background: white; opacity: 0; z-index: 16; pointer-events: none; transition: opacity 0.1s; }
        .avb-flash.active { opacity: 0.7; }

        @media (max-width: 600px) {
            .avb-sliders { grid-template-columns: 1fr; }
            .avb-slider-label { font-size: 0.85rem !important; }
            .avb-camera-wrap { aspect-ratio: 1/1; }
            .avb-header { flex-direction: column; text-align: center; }
            .avb-controls { flex-direction: column; align-items: center; }
            .avb-btn { width: 100%; text-align: center; }
            .avb-editor-actions { flex-direction: column; }
            .avb-editor-actions .avb-btn { width: 100%; }
        }
    </style>

    <div class="avb-container" id="avbContainer">
        <!-- Header with current avatar -->
        <div class="avb-header">
            <img src="<?php echo esc_url($current_avatar); ?>" class="avb-current" id="avbCurrentAvatar" alt="Current avatar"/>
            <div>
                <h3>Update Your Photo</h3>
                <p><?php echo esc_html($user->display_name); ?> — snap a new profile photo with the camera</p>
            </div>
        </div>

        <!-- Background selector -->
        <div class="avb-bg-bar" id="avbBgBar"></div>

        <!-- Camera area -->
        <div class="avb-camera-wrap">
            <video id="avbVideo" playsinline></video>
            <canvas id="avbCanvas" width="960" height="720"></canvas>

            <svg class="avb-frame" id="avbFrame" viewBox="0 0 960 720" preserveAspectRatio="xMidYMid meet">
                <defs><mask id="avbFrameMask"><rect width="960" height="720" fill="white"/><ellipse cx="480" cy="320" rx="180" ry="230" fill="black"/></mask></defs>
                <rect width="960" height="720" fill="rgba(0,0,0,0.25)" mask="url(#avbFrameMask)"/>
                <ellipse cx="480" cy="320" rx="180" ry="230" fill="none" stroke="rgba(42,124,79,0.4)" stroke-width="2" stroke-dasharray="8 6"/>
                <text x="480" y="580" text-anchor="middle" fill="rgba(42,124,79,0.4)" font-size="13">Center your face in the oval</text>
            </svg>

            <div class="avb-countdown" id="avbCountdown">
                <div class="avb-countdown-num" id="avbCountNum"></div>
            </div>
            <div class="avb-flash" id="avbFlash"></div>

            <div class="avb-preview" id="avbPreview">
                <img id="avbPreviewImg" src="" alt="Preview"/>
                <div style="display:flex;gap:0.5rem;">
                    <button class="avb-btn avb-btn-primary" onclick="avbEditPhoto()">✓ Edit & Use</button>
                    <button class="avb-btn avb-btn-gold" onclick="avbRetake()">↻ Retake</button>
                </div>
            </div>

            <div class="avb-loading" id="avbLoading">Loading camera...</div>
        </div>

        <!-- Controls -->
        <div class="avb-controls">
            <button class="avb-btn avb-btn-primary" id="avbCaptureBtn" onclick="avbCapture()" disabled>📸 Capture</button>
            <button class="avb-btn avb-btn-muted" id="avbToggleBtn" onclick="avbToggleCamera()">⏹ Stop Camera</button>
            <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.8rem;color:#666;">
                <input type="checkbox" id="avbBgToggle" checked onchange="avbBgEnabled=this.checked"> Background
            </label>
            <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.8rem;color:#666;">
                <input type="checkbox" id="avbMirrorToggle" checked onchange="avbMirror=this.checked"> Mirror
            </label>
        </div>

        <!-- Editor (shown after capture) -->
        <div class="avb-editor" id="avbEditor">
            <h4 style="margin:0 0 0.5rem;color:var(--spp-color-heading,#1a1a1a);text-align:center;">Adjust Your Photo</h4>
            <div class="avb-editor-canvas-wrap">
                <canvas id="avbEditorCanvas"></canvas>
                <div class="avb-crop-box" id="avbCropBox">
                    <div class="avb-crop-handle tl" data-pos="tl"></div>
                    <div class="avb-crop-handle tr" data-pos="tr"></div>
                    <div class="avb-crop-handle bl" data-pos="bl"></div>
                    <div class="avb-crop-handle br" data-pos="br"></div>
                </div>
            </div>
            <div class="avb-sliders">
                <div>
                    <div class="avb-slider-label">Brightness <span id="avbValBright">0</span></div>
                    <input type="range" id="avbSliderBright" min="-100" max="100" value="0" oninput="avbUpdateEditor()"/>
                </div>
                <div>
                    <div class="avb-slider-label">Contrast <span id="avbValContrast">0</span></div>
                    <input type="range" id="avbSliderContrast" min="-100" max="100" value="0" oninput="avbUpdateEditor()"/>
                </div>
                <div>
                    <div class="avb-slider-label">Warmth <span id="avbValWarmth">0</span></div>
                    <input type="range" id="avbSliderWarmth" min="-100" max="100" value="0" oninput="avbUpdateEditor()"/>
                </div>
                <div>
                    <div class="avb-slider-label">Exposure <span id="avbValExposure">0</span></div>
                    <input type="range" id="avbSliderExposure" min="-100" max="100" value="0" oninput="avbUpdateEditor()"/>
                </div>
            </div>
            <div class="avb-editor-actions">
                <button class="avb-btn avb-btn-gold" onclick="avbToggleCrop()">✂ Crop</button>
                <button class="avb-btn avb-btn-muted" onclick="avbResetEditor()">↺ Reset</button>
                <button class="avb-btn avb-btn-primary" onclick="avbSetAvatar()">✓ Set as My Avatar</button>
                <button class="avb-btn avb-btn-muted" onclick="avbCancelEdit()">Cancel</button>
            </div>
        </div>

        <div class="avb-status" id="avbStatus"></div>

        <!-- Email me a copy (shown after avatar set) -->
        <div id="avbEmailRow" style="display:none;text-align:center;margin-top:0.5rem;">
            <button class="avb-btn avb-btn-gold" onclick="avbEmailAvatar()">📧 Email me a copy</button>
        </div>

        <!-- Remove avatar option -->
        <div style="margin-top:1rem;padding-top:0.8rem;border-top:1px solid #eee;">
            <button class="avb-btn avb-btn-danger" onclick="avbRemoveAvatar()" style="font-size:0.75rem;padding:0.3rem 0.8rem;">Remove Custom Avatar</button>
            <span style="font-size:0.75rem;color:#999;margin-left:0.5rem;">Reverts to Gravatar</span>
        </div>
    </div>

    <script>
    (function() {
        // ── Config ──
        const REST_URL = '<?php echo esc_js($rest_url); ?>';
        const NONCE = '<?php echo esc_js($nonce); ?>';

        // ── State ──
        let segmenter = null, camera = null;
        let avbBgEnabled = true, avbMirror = true;
        let currentBg = 0, backgrounds = [];
        let capturedDataURL = null;
        let editorOriginal = null;
        let cropActive = false, cropRect = {}, cropDragging = null, cropStart = {};

        const video = document.getElementById('avbVideo');
        const canvas = document.getElementById('avbCanvas');
        const ctx = canvas.getContext('2d');

        // ── Backgrounds ──
        const builtinBGs = [
            {name:'None', type:'none', css:'#333'},
            {name:'Green', type:'gradient', css:'linear-gradient(135deg, #2D5016 0%, #4A7C28 50%, #6B8E23 100%)'},
            {name:'Blue', type:'gradient', css:'linear-gradient(135deg, #89CFF0 0%, #4A90D9 50%, #2D5F8A 100%)'},
            {name:'Studio', type:'gradient', css:'radial-gradient(ellipse at center, #8899AA 0%, #556677 60%, #334455 100%)'},
            {name:'Warm', type:'gradient', css:'linear-gradient(180deg, #D4B896 0%, #C4A882 50%, #A08060 100%)'},
        ];

        function initBGs() {
            const bar = document.getElementById('avbBgBar');
            builtinBGs.forEach((bg, i) => {
                backgrounds[i] = bg;
                const d = document.createElement('div');
                d.className = 'avb-bg-opt' + (i === currentBg ? ' selected' : '');
                d.style.background = bg.type === 'none' ? bg.css : bg.css;
                d.title = bg.name;
                d.onclick = () => {
                    currentBg = i;
                    bar.querySelectorAll('.avb-bg-opt').forEach((e, j) => e.classList.toggle('selected', j === i));
                };
                bar.appendChild(d);
            });
        }

        function drawBG(tctx, w, h) {
            const bg = backgrounds[currentBg];
            if (!bg || bg.type === 'none') return false;
            const oc = document.createElement('canvas'); oc.width = w; oc.height = h;
            const o = oc.getContext('2d');
            const lm = bg.css.match(/linear-gradient\((\d+)deg,\s*(.+)\)/);
            const rm = bg.css.match(/radial-gradient\(.+?,\s*(.+)\)/);
            if (lm) {
                const a = parseInt(lm[1]) * Math.PI / 180, l = Math.max(w, h);
                const g = o.createLinearGradient(w/2-Math.cos(a)*l, h/2-Math.sin(a)*l, w/2+Math.cos(a)*l, h/2+Math.sin(a)*l);
                lm[2].split(/,\s*(?=#)/).forEach(s => { const p = s.trim().split(/\s+/); g.addColorStop(parseInt(p[1])/100, p[0]); });
                o.fillStyle = g;
            } else if (rm) {
                const g = o.createRadialGradient(w/2, h/2, 0, w/2, h/2, Math.max(w,h)/1.5);
                rm[1].split(/,\s*(?=#)/).forEach(s => { const p = s.trim().split(/\s+/); g.addColorStop(parseInt(p[1])/100, p[0]); });
                o.fillStyle = g;
            }
            o.fillRect(0, 0, w, h);
            tctx.drawImage(oc, 0, 0, w, h);
            return true;
        }

        // ── Camera & Segmentation ──
        function onResults(results) {
            const w = canvas.width, h = canvas.height;
            ctx.save(); ctx.clearRect(0, 0, w, h);
            if (avbMirror) { ctx.translate(w, 0); ctx.scale(-1, 1); }
            if (avbBgEnabled && backgrounds[currentBg]?.type !== 'none') {
                ctx.save();
                if (avbMirror) { ctx.scale(-1, 1); ctx.translate(-w, 0); }
                drawBG(ctx, w, h);
                if (avbMirror) { ctx.translate(w, 0); ctx.scale(-1, 1); }
                ctx.restore();
                ctx.globalCompositeOperation = 'destination-out';
                ctx.drawImage(results.segmentationMask, 0, 0, w, h);
                ctx.globalCompositeOperation = 'destination-atop';
                ctx.drawImage(results.image, 0, 0, w, h);
            } else {
                ctx.drawImage(results.image, 0, 0, w, h);
            }
            ctx.restore();
        }

        async function startCamera() {
            const loading = document.getElementById('avbLoading');
            loading.classList.remove('hidden');
            loading.textContent = 'Loading segmentation...';

            if (!segmenter) {
                segmenter = new SelfieSegmentation({
                    locateFile: f => 'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/' + f
                });
                segmenter.setOptions({ modelSelection: 1, selfieMode: false });
                segmenter.onResults(onResults);
            }

            loading.textContent = 'Starting camera...';
            camera = new Camera(video, {
                onFrame: async () => { if (segmenter) await segmenter.send({ image: video }); },
                width: 960, height: 720,
            });
            await camera.start();

            loading.classList.add('hidden');
            document.getElementById('avbCaptureBtn').disabled = false;
            document.getElementById('avbToggleBtn').textContent = '⏹ Stop Camera';
        }

        function stopCamera() {
            if (camera) { camera.stop(); camera = null; }
            if (video.srcObject) { video.srcObject.getTracks().forEach(t => t.stop()); video.srcObject = null; }
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            document.getElementById('avbCaptureBtn').disabled = true;
            document.getElementById('avbToggleBtn').textContent = '▶ Start Camera';
        }

        window.avbToggleCamera = function() {
            if (camera) stopCamera(); else startCamera();
        };

        // ── Capture ──
        function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

        window.avbCapture = async function() {
            document.getElementById('avbCaptureBtn').disabled = true;
            const overlay = document.getElementById('avbCountdown');
            const numEl = document.getElementById('avbCountNum');
            overlay.classList.add('active');

            for (let i = 3; i >= 1; i--) {
                numEl.textContent = i;
                numEl.style.animation = 'none'; void numEl.offsetHeight;
                numEl.style.animation = 'avb-pulse 1s ease-in-out';
                await sleep(1000);
            }
            overlay.classList.remove('active');

            const flash = document.getElementById('avbFlash');
            flash.classList.add('active');
            setTimeout(() => flash.classList.remove('active'), 200);

            capturedDataURL = canvas.toDataURL('image/png');
            document.getElementById('avbPreviewImg').src = capturedDataURL;
            document.getElementById('avbPreview').classList.add('active');
        };

        window.avbRetake = function() {
            document.getElementById('avbPreview').classList.remove('active');
            document.getElementById('avbCaptureBtn').disabled = false;
        };

        // ── Editor ──
        window.avbEditPhoto = function() {
            document.getElementById('avbPreview').classList.remove('active');
            stopCamera();

            const img = new Image();
            img.onload = () => {
                editorOriginal = img;
                avbResetSliders();
                avbRenderEditor();
                document.getElementById('avbEditor').classList.add('active');
            };
            img.src = capturedDataURL;
        };

        function avbResetSliders() {
            ['Bright', 'Contrast', 'Warmth', 'Exposure'].forEach(s => {
                document.getElementById('avbSlider' + s).value = 0;
                document.getElementById('avbVal' + s).textContent = '0';
            });
            cropActive = false;
            document.getElementById('avbCropBox').style.display = 'none';
        }

        window.avbResetEditor = function() { avbResetSliders(); avbRenderEditor(); };

        window.avbUpdateEditor = function() {
            ['Bright', 'Contrast', 'Warmth', 'Exposure'].forEach(s => {
                document.getElementById('avbVal' + s).textContent = document.getElementById('avbSlider' + s).value;
            });
            avbRenderEditor();
        };

        function avbRenderEditor() {
            if (!editorOriginal) return;
            const ec = document.getElementById('avbEditorCanvas');
            const ectx = ec.getContext('2d');
            ec.width = editorOriginal.width;
            ec.height = editorOriginal.height;

            const bright = parseInt(document.getElementById('avbSliderBright').value);
            const contrast = parseInt(document.getElementById('avbSliderContrast').value);
            const warmth = parseInt(document.getElementById('avbSliderWarmth').value);
            const exposure = parseInt(document.getElementById('avbSliderExposure').value);

            const bVal = 1 + bright / 100;
            const cVal = 1 + contrast / 100;
            const eVal = 1 + exposure / 50;

            ectx.filter = 'brightness(' + (bVal * eVal) + ') contrast(' + cVal + ')';
            ectx.drawImage(editorOriginal, 0, 0);
            ectx.filter = 'none';

            if (warmth !== 0) {
                const imageData = ectx.getImageData(0, 0, ec.width, ec.height);
                const data = imageData.data;
                const w = warmth / 100;
                for (let i = 0; i < data.length; i += 4) {
                    if (w > 0) { data[i] = Math.min(255, data[i] + w * 30); data[i+2] = Math.max(0, data[i+2] - w * 20); }
                    else { data[i] = Math.max(0, data[i] + w * 20); data[i+2] = Math.min(255, data[i+2] - w * 30); }
                }
                ectx.putImageData(imageData, 0, 0);
            }

            if (cropActive) avbPositionCrop();
        }

        // ── Crop ──
        window.avbToggleCrop = function() {
            cropActive = !cropActive;
            const box = document.getElementById('avbCropBox');
            if (cropActive) {
                const ec = document.getElementById('avbEditorCanvas');
                const rect = ec.getBoundingClientRect();
                cropRect = { x: rect.width * 0.2, y: rect.height * 0.15, w: rect.width * 0.6, h: rect.height * 0.7 };
                avbPositionCrop();
                box.style.display = 'block';
                avbInitCropDrag();
            } else {
                box.style.display = 'none';
            }
        };

        function avbPositionCrop() {
            const box = document.getElementById('avbCropBox');
            const ec = document.getElementById('avbEditorCanvas');
            const wrap = ec.parentElement;
            const ecRect = ec.getBoundingClientRect();
            const wrapRect = wrap.getBoundingClientRect();
            const offX = ecRect.left - wrapRect.left;
            const offY = ecRect.top - wrapRect.top;
            box.style.left = (offX + cropRect.x) + 'px';
            box.style.top = (offY + cropRect.y) + 'px';
            box.style.width = cropRect.w + 'px';
            box.style.height = cropRect.h + 'px';
        }

        function avbInitCropDrag() {
            const box = document.getElementById('avbCropBox');
            const ec = document.getElementById('avbEditorCanvas');

            function getPoint(e) {
                if (e.touches && e.touches.length) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
                return { x: e.clientX, y: e.clientY };
            }

            function onDown(e) {
                e.preventDefault();
                e.stopPropagation();
                const pos = e.target.dataset?.pos;
                const pt = getPoint(e);
                cropStart = { mx: pt.x, my: pt.y, ...cropRect };
                cropDragging = pos || 'move';
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                document.addEventListener('touchmove', onMove, { passive: false });
                document.addEventListener('touchend', onUp);
            }
            function onMove(e) {
                if (!cropDragging) return;
                e.preventDefault();
                const pt = getPoint(e);
                const dx = pt.x - cropStart.mx, dy = pt.y - cropStart.my;
                const rect = ec.getBoundingClientRect();
                if (cropDragging === 'move') {
                    cropRect.x = Math.max(0, Math.min(rect.width - cropRect.w, cropStart.x + dx));
                    cropRect.y = Math.max(0, Math.min(rect.height - cropRect.h, cropStart.y + dy));
                } else if (cropDragging === 'br') {
                    cropRect.w = Math.max(50, Math.min(rect.width - cropRect.x, cropStart.w + dx));
                    cropRect.h = Math.max(50, Math.min(rect.height - cropRect.y, cropStart.h + dy));
                } else if (cropDragging === 'tl') {
                    const nw = Math.max(50, cropStart.w - dx), nh = Math.max(50, cropStart.h - dy);
                    cropRect.x = cropStart.x + cropStart.w - nw; cropRect.y = cropStart.y + cropStart.h - nh;
                    cropRect.w = nw; cropRect.h = nh;
                } else if (cropDragging === 'tr') {
                    cropRect.w = Math.max(50, cropStart.w + dx);
                    const nh = Math.max(50, cropStart.h - dy); cropRect.y = cropStart.y + cropStart.h - nh; cropRect.h = nh;
                } else if (cropDragging === 'bl') {
                    const nw = Math.max(50, cropStart.w - dx); cropRect.x = cropStart.x + cropStart.w - nw; cropRect.w = nw;
                    cropRect.h = Math.max(50, cropStart.h + dy);
                }
                avbPositionCrop();
            }
            function onUp() {
                cropDragging = null;
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('touchend', onUp);
            }

            box.onmousedown = onDown;
            box.ontouchstart = onDown;
            box.querySelectorAll('.avb-crop-handle').forEach(h => { h.onmousedown = onDown; h.ontouchstart = onDown; });
        }

        // ── Save Avatar ──
        window.avbSetAvatar = async function() {
            const ec = document.getElementById('avbEditorCanvas');
            let saveCanvas = ec;

            if (cropActive) {
                const rect = ec.getBoundingClientRect();
                const scaleX = ec.width / rect.width, scaleY = ec.height / rect.height;
                const cx = Math.round(cropRect.x * scaleX), cy = Math.round(cropRect.y * scaleY);
                const cw = Math.round(cropRect.w * scaleX), ch = Math.round(cropRect.h * scaleY);
                saveCanvas = document.createElement('canvas');
                saveCanvas.width = cw; saveCanvas.height = ch;
                saveCanvas.getContext('2d').drawImage(ec, cx, cy, cw, ch, 0, 0, cw, ch);
            }

            const dataURL = saveCanvas.toDataURL('image/png');
            const status = document.getElementById('avbStatus');
            status.className = 'avb-status'; status.textContent = 'Saving avatar...';

            try {
                const resp = await fetch(REST_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({ image: dataURL })
                });
                const result = await resp.json();
                if (result.ok || result.avatar_url) {
                    status.className = 'avb-status success';
                    status.textContent = 'Avatar updated!';
                    document.getElementById('avbCurrentAvatar').src = result.avatar_url + '?t=' + Date.now();
                    document.getElementById('avbEditor').classList.remove('active');
                    cropActive = false;
                    document.getElementById('avbCropBox').style.display = 'none';
                    document.getElementById('avbEmailRow').style.display = 'block';
                } else {
                    status.className = 'avb-status error';
                    status.textContent = 'Error: ' + (result.message || 'Unknown error');
                }
            } catch(e) {
                status.className = 'avb-status error';
                status.textContent = 'Failed to save: ' + e.message;
            }
        };

        window.avbEmailAvatar = async function() {
            const status = document.getElementById('avbStatus');
            status.className = 'avb-status'; status.textContent = 'Sending email...';
            try {
                const resp = await fetch('<?php echo esc_js(rest_url('spp/v1/avatar/email')); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }
                });
                const result = await resp.json();
                if (result.ok) {
                    status.className = 'avb-status success';
                    status.textContent = result.message || 'Photo emailed!';
                } else {
                    status.className = 'avb-status error';
                    status.textContent = 'Email error: ' + (result.message || 'Unknown');
                }
            } catch(e) {
                status.className = 'avb-status error';
                status.textContent = 'Email failed: ' + e.message;
            }
        };

        window.avbCancelEdit = function() {
            document.getElementById('avbEditor').classList.remove('active');
            cropActive = false;
            document.getElementById('avbCropBox').style.display = 'none';
            startCamera();
        };

        window.avbRemoveAvatar = async function() {
            if (!confirm('Remove your custom avatar? This will revert to Gravatar.')) return;
            const status = document.getElementById('avbStatus');
            try {
                const resp = await fetch(REST_URL, {
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': NONCE }
                });
                const result = await resp.json();
                status.className = 'avb-status success';
                status.textContent = result.message || 'Avatar removed.';
                document.getElementById('avbCurrentAvatar').src = 'https://www.gravatar.com/avatar/?d=mp&s=200';
            } catch(e) {
                status.className = 'avb-status error';
                status.textContent = 'Error: ' + e.message;
            }
        };

        // ── Init ──
        initBGs();
        startCamera();
    })();
    </script>
    <?php
    return ob_get_clean();
});
