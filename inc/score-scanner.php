<?php
// -------------------------------------------------------
// SCORE SCANNER — AJAX HANDLERS + ENQUEUE
// -------------------------------------------------------

// Enqueue JS and pass PHP vars to it
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'spp-score-scanner',
        get_stylesheet_directory_uri() . '/js/score-scanner.js',
        ['jquery'],
        '1.0.0',
        true
    );
    wp_localize_script('spp-score-scanner', 'sppScanner', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('spp_score_scan'),
        'event'   => get_option('spp_current_event')
    ]);
});

// AJAX: Scan scores
add_action('wp_ajax_spp_scan_scores', function() {
    if (!check_ajax_referer('spp_score_scan', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }
    if (!current_user_can('editor') && !current_user_can('administrator')) {
        wp_send_json_error('Insufficient permissions');
    }
    if (empty($_FILES['files'])) {
        wp_send_json_error('No files uploaded');
    }

    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : get_option('anthropic_api_key');
    if (!$api_key) {
        wp_send_json_error('Anthropic API key not configured');
    }

    $content = [];
    $content[] = [
        'type' => 'text',
        'text' => 'These are scanned score sheets from a pickleball ladder tournament. Each page shows one time slot. For each player, extract:
- group (e.g. "Group 07")
- court (e.g. "Court 1")
- time_slot (the time shown at the top of the page e.g. "5:30", "6:40", "7:50")
- rank (the number in the Rank column)
- name (the name written on the sheet — use the handwritten name if the printed name is crossed out)
- rnd1 through rnd5 (the score in each round column — use null if blank, "bye" if it says bye)
- substitution: true if the printed name was crossed out and replaced with a handwritten name
- warning: any note if something is unclear

Return ONLY a JSON object with this structure:
{
  "players": [
    {"group":"Group 07","court":"Court 1","time_slot":"5:30","rank":47,"name":"Robin Lawrence","rnd1":18,"rnd2":20,"rnd3":20,"rnd4":null,"rnd5":null,"substitution":false,"warning":""},
    ...
  ],
  "warnings": []
}

Important:
- "bye" cells contain no score
- Some cells may be blank (player did not play that round)
- Crossed out names mean a substitute played — use the handwritten replacement name and set substitution to true
- Ranks are integers
- Scores are integers or "bye" or null'
    ];

    $files      = $_FILES['files'];
    $file_count = count($files['name']);

    for ($i = 0; $i < $file_count; $i++) {
        $tmp  = $files['tmp_name'][$i];
        $type = $files['type'][$i];
        $name = $files['name'][$i];

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf') {
            $img_path = sys_get_temp_dir() . '/spp_score_' . $i . '_%d.jpg';
            exec("/usr/bin/convert -density 150 -quality 85 " . escapeshellarg($tmp) . " " . escapeshellarg($img_path) . " 2>&1");
            $page = 0;
            while (file_exists(str_replace('%d', $page, $img_path))) {
                $img_file  = str_replace('%d', $page, $img_path);
                $content[] = [
                    'type'   => 'image',
                    'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode(file_get_contents($img_file))]
                ];
                unlink($img_file);
                $page++;
            }
        } else {
            $content[] = [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => $type, 'data' => base64_encode(file_get_contents($tmp))]
            ];
        }
    }

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 120,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => json_encode([
            'model'      => 'claude-opus-4-5',
            'max_tokens' => 8192,
            'messages'   => [['role' => 'user', 'content' => $content]]
        ])
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('API error: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['content'][0]['text'])) {
        wp_send_json_error('No response from Claude');
    }

    $text   = preg_replace('/^```json\s*/m', '', $body['content'][0]['text']);
    $text   = preg_replace('/^```\s*/m', '', $text);
    $parsed = json_decode(trim($text), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Could not parse response: ' . substr($text, 0, 200));
    }

    wp_send_json_success($parsed);
});

// AJAX: Save scores
add_action('wp_ajax_spp_save_scores', function() {
    global $wpdb;

    if (!check_ajax_referer('spp_score_scan', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }
    if (!current_user_can('editor') && !current_user_can('administrator')) {
        wp_send_json_error('Insufficient permissions');
    }

    $players = json_decode(stripslashes($_POST['players']), true);
    if (empty($players)) {
        wp_send_json_error('No player data received');
    }

    // Build lookup maps for courts, groups and times
    $court_map = [];
    foreach ($wpdb->get_results("SELECT Crt_ID, Crt_name FROM Courts", ARRAY_A) as $c) {
        $court_map[strtolower(trim($c['Crt_name']))] = $c['Crt_ID'];
    }

    $group_map = [];
    foreach ($wpdb->get_results("SELECT GP_ID, GP_name FROM Groups", ARRAY_A) as $g) {
        $group_map[strtolower(trim($g['GP_name']))] = $g['GP_ID'];
    }

    $time_map = [];
    foreach ($wpdb->get_results("SELECT T_ID, T_desc FROM Times WHERE Active = 1", ARRAY_A) as $t) {
        // Index by time portion only e.g. "5:30", "6:40", "7:50"
        preg_match('/\d+:\d+/', $t['T_desc'], $m);
        if ($m) $time_map[$m[0]] = $t['T_ID'];
    }

    $saved  = 0;
    $errors = [];

    foreach ($players as $p) {
        $rank   = intval($p['rank']);
        $is_sub = !empty($p['substitution']);

        // --- Match player ---
        if ($is_sub) {
            // Substitution: fuzzy match by name
            $name       = sanitize_text_field($p['name']);
            $name_parts = explode(' ', $name);
            $last_name  = end($name_parts);
            $first_name = reset($name_parts);

            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM Schedules WHERE last_name LIKE %s",
                '%' . $wpdb->esc_like($last_name) . '%'
            ));
            if (!$user_id) {
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM Schedules WHERE first_name LIKE %s",
                    '%' . $wpdb->esc_like($first_name) . '%'
                ));
            }
            if (!$user_id) {
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM Schedules WHERE CONCAT(first_name, ' ', last_name) LIKE %s",
                    '%' . $wpdb->esc_like($name) . '%'
                ));
            }
        } else {
            // Normal player: match by rank
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM Schedules WHERE Rank = %d", $rank
            ));
        }

        if (!$user_id) {
            $errors[] = "Could not match: rank {$rank} / {$p['name']}";
            continue;
        }

        $sv = function($v) { return ($v === 'bye' || $v === null || $v === '') ? null : intval($v); };

        // Build update array — always update scores
        $update_data   = [
            'Game1' => $sv($p['rnd1']),
            'Game2' => $sv($p['rnd2']),
            'Game3' => $sv($p['rnd3']),
            'Game4' => $sv($p['rnd4']),
            'Game5' => $sv($p['rnd5']),
        ];
        $update_format = ['%d', '%d', '%d', '%d', '%d'];

        // For substitutions: also update group_id, Crt_ID and time_id
        if ($is_sub) {
            $group_key = strtolower(trim($p['group']));
            $court_key = strtolower(trim($p['court']));
            $time_key  = isset($p['time_slot']) ? trim($p['time_slot']) : '';

            if (isset($group_map[$group_key])) {
                $update_data['group_id'] = $group_map[$group_key];
                $update_format[] = '%d';
            }
            if (isset($court_map[$court_key])) {
                $update_data['Crt_ID'] = $court_map[$court_key];
                $update_format[] = '%d';
            }
            if ($time_key && isset($time_map[$time_key])) {
                $update_data['time_id'] = $time_map[$time_key];
                $update_format[] = '%d';
            }
        }

        $result = $wpdb->update(
            'Schedules',
            $update_data,
            ['user_id' => $user_id],
            $update_format,
            ['%d']
        );

        if ($result !== false) $saved++;
        else $errors[] = "DB error for rank {$rank} / {$p['name']}";
    }

    wp_send_json_success(['saved' => $saved, 'errors' => $errors]);
});