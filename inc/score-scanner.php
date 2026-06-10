<?php
// -------------------------------------------------------
// SCORE SCANNER -- AJAX HANDLERS + ENQUEUE
// Version: 1.4
// Changes from 1.3:
// - REVISED GROUP priority: sheets marked [REVISED GROUP X]
//   are detected and given automatic priority over any other
//   sheet for that group. Revised group IDs passed from PHP
//   via sppScanner.modifiedGroups localized variable.
// - Scan prompt updated to detect [REVISED GROUP X] marker
//   and set revised:true on all players in that group.
// - Save handler: if revised=true, clear existing scores for
//   that group before saving new scores.
// Changes from 1.2:
// - Prompt updated to handle Total column on handwritten
//   sheets regardless of capitalization (Total, TOTAL, Tot)
// - Added orphan score validation: any score in a round
//   that does not match another score is likely misread
// Changes from 1.2:
// - Prompt updated to explicitly identify and skip the
//   Total column (different background color from rounds)
// - Score validation rule added: each round in a 4-player
//   group produces exactly 2 score values (winners same,
//   losers same) -- used to detect column misidentification
// Changes from 1.0:
// - max_tokens increased to 16000 for larger PDFs
// - Prompt updated to discard duplicate group entries
//   where all rounds are null/bye (total = 0)
// - Prompt updated to handle X (NP) and XX (NS) codes
// - NP (x) and NS (xx) codes supported in $sv function
// -------------------------------------------------------

// Enqueue JS and pass PHP vars to it
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'spp-score-scanner',
        get_stylesheet_directory_uri() . '/js/score-scanner.js',
        ['jquery'],
        '1.4.0',
        true
    );
    // Pass modified groups to JS so scanner UI can flag them
    $modified_groups = json_decode( get_option( 'spp_modified_groups', '[]' ), true );
    if ( ! is_array( $modified_groups ) ) $modified_groups = [];

    wp_localize_script('spp-score-scanner', 'sppScanner', [
        'ajaxurl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('spp_score_scan'),
        'event'          => get_option('spp_current_event'),
        'modifiedGroups' => $modified_groups,
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
- name (the name written on the sheet -- use the handwritten name if the printed name is crossed out)
- rnd1 through rnd5 (the score in each round column -- use null if blank, "bye" if it says bye)
- substitution: true if the printed name was crossed out and replaced with a handwritten name
- revised: true if the sheet has a [REVISED GROUP X] banner at the top (where X is the group number)
- warning: any note if something is unclear

Return ONLY a JSON object with this structure:
{
  "players": [
    {"group":"Group 07","court":"Court 1","time_slot":"5:30","rank":47,"name":"Robin Lawrence","rnd1":18,"rnd2":20,"rnd3":20,"rnd4":null,"rnd5":null,"substitution":false,"revised":false,"warning":""},
    ...
  ],
  "warnings": []
}

REVISED SHEET PRIORITY:
- Some replacement score sheets will have a prominent red banner at the top reading "[REVISED GROUP X]" where X is the group number.
- If you see this banner on a sheet, set revised:true for ALL players on that sheet.
- A revised sheet for a group ALWAYS takes priority over any other sheet for that group.
- If you have both a revised sheet and an original sheet for the same group, use ONLY the revised sheet data. Discard the original entirely.

CRITICAL COLUMN IDENTIFICATION:
- The score sheet columns are: Group, Court, Rank, Name, TOTAL, Rnd1, Rnd2, Rnd3, Rnd4, Rnd5, Phone
- The TOTAL column appears immediately after the Name column and has a DIFFERENT background color from the round columns
- You MUST skip the TOTAL column entirely -- never use its value as a round score
- The round columns (Rnd1-Rnd5) come AFTER the Total column
- If the Total column is empty (not filled in by players), the first numeric value after the name is still Rnd1, not Total
- The column header row clearly labels each column -- use the headers to identify columns, do not rely on position alone
- On HANDWRITTEN score sheets, the first column after the player name may be labeled "Total", "TOTAL", "Tot", "total" or any capitalization or abbreviation -- skip it regardless of how it is written
- On handwritten sheets, the round columns will be labeled "Round 1", "Rnd 1", "R1", "Rd1" or similar -- these are the only columns to extract

SCORE VALIDATION:
- Each group has 4 or 5 players playing round-robin
- In each round, 2 players play against 2 players -- so each round produces exactly 2 score values (winners get the same score, losers get the same score) plus "bye" entries
- If you see more than 2 distinct numeric scores in a single round column for a group, you have likely misidentified a column -- recheck
- A group of 4 players plays 3 rounds (each player gets 1 bye). A group of 5 players plays 5 rounds (each player gets 1 bye)
- Use this to validate your column identification -- if round scores do not follow this pattern, you likely included the Total column by mistake
- ORPHAN SCORE CHECK: after extracting all scores for a group, check each round column -- if any score value appears only once (not matched by another player in the same round), it is likely a misread. Add a warning for any orphan score so it can be reviewed manually.
- Example: if round 2 scores for a group are 20, 18, 20, bye -- the 18 is an orphan (appears only once). It may be a misread of 20, 12, or another value. Flag it with a warning.

Important:
- "bye" cells contain no score
- Some cells may be blank (player did not play that round)
- Crossed out names mean a substitute played -- use the handwritten replacement name and set substitution to true
- Ranks are integers
- Scores are integers or "bye" or null
- If the same group appears more than once (e.g. from both a printed sheet and a handwritten sheet), use ONLY the version with actual numeric scores. Discard any duplicate entry where all rounds are null/bye and the total is 0 -- do not include these blank entries in the output at all.
- Do not include any player row where every round is null or "bye" and the total score is 0. These are players with no scores recorded and should be omitted entirely.
- If a player has "X" or "x" written through their score box or next to their name (but not "XX"), set rnd1 to "x" and all other rounds to null. Do not omit these players -- they need a penalty applied.
- If a player has "XX" or "xx" written through their score box or next to their name, set rnd1 to "xx" and all other rounds to null. Do not omit these players -- they need a no-show penalty applied.
- X and XX may appear on the printed schedule sheet next to the player name, or written in the score columns on handwritten sheets.'
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
            'max_tokens' => 16000,
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
        preg_match('/\d+:\d+/', $t['T_desc'], $m);
        if ($m) $time_map[$m[0]] = $t['T_ID'];
    }

    // ── REVISED GROUP PRIORITY ────────────────────────────────────────────────
    // If any player has revised:true, clear all existing scores for that group
    // before saving, so revised sheet wins over any previously scanned sheet.
    $revised_groups_cleared = [];
    foreach ( $players as $p ) {
        if ( ! empty( $p['revised'] ) && $p['revised'] ) {
            $group_key = strtolower( trim( $p['group'] ) );
            if ( isset( $group_map[ $group_key ] ) && ! in_array( $group_map[ $group_key ], $revised_groups_cleared ) ) {
                $gid = $group_map[ $group_key ];
                $wpdb->query( $wpdb->prepare(
                    "UPDATE Schedules SET Game1=NULL, Game2=NULL, Game3=NULL, Game4=NULL, Game5=NULL WHERE group_id = %d",
                    $gid
                ) );
                $revised_groups_cleared[] = $gid;
            }
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    $saved  = 0;
    $errors = [];

    foreach ($players as $p) {
        $rank    = intval($p['rank']);
        $is_sub  = !empty($p['substitution']);
        $name    = sanitize_text_field($p['name']);

        $name_parts = explode(' ', $name);
        $last_name  = end($name_parts);
        $first_name = reset($name_parts);

        $user_id = null;

        if ($is_sub) {
            // Substitution: match by last name only (no rank)
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
        } else {
            // Normal player: rank + last name
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM Schedules WHERE Rank = %d AND last_name LIKE %s",
                $rank,
                '%' . $wpdb->esc_like($last_name) . '%'
            ));
            // Rank + first name fallback
            if (!$user_id) {
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM Schedules WHERE Rank = %d AND first_name LIKE %s",
                    $rank,
                    '%' . $wpdb->esc_like($first_name) . '%'
                ));
            }
            // Rank only as last resort
            if (!$user_id) {
                $user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM Schedules WHERE Rank = %d",
                    $rank
                ));
            }
        }

        if (!$user_id) {
            $errors[] = "Could not match: rank {$rank} / {$name}";
            continue;
        }

        $sv = function($v) {
            $v = strtolower(trim((string)$v));
            if ($v === 'x')  return -1;  // NP: not played, +1.6 penalty
            if ($v === 'xx') return -2;  // NS: no-show, +2.6 penalty
            return ($v === 'bye' || $v === '' || $v === 'null') ? null : intval($v);
        };

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
        else $errors[] = "DB error for rank {$rank} / {$name}";
    }

    // Report revised groups that were cleared
    if ( ! empty( $revised_groups_cleared ) ) {
        $errors[] = 'INFO: Revised sheet priority applied — cleared scores for group ID(s): ' . implode( ', ', $revised_groups_cleared );
    }

    wp_send_json_success(['saved' => $saved, 'errors' => $errors]);
});

// AJAX: Clear all scores
add_action('wp_ajax_spp_clear_scores', function() {
    global $wpdb;
    if (!check_ajax_referer('spp_score_scan', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }
    if (!current_user_can('editor') && !current_user_can('administrator')) {
        wp_send_json_error('Insufficient permissions');
    }
    $wpdb->query("UPDATE Schedules SET Game1=NULL, Game2=NULL, Game3=NULL, Game4=NULL, Game5=NULL");
    wp_send_json_success(['message' => 'All scores cleared.']);
});