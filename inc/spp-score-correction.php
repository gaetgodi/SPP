<?php
/**
 * SPP Score Correction Tool
 *
 * Shortcode: [spp_score_correction]
 *
 * Admin-only tool for correcting game scores after results have been
 * published. Recalculates group rankings, RankCalc, ordinal ranks,
 * and updates all affected tables and user meta.
 *
 * Uses the same ranking logic as Create Results:
 *   - rankUsersWithTies(): score desc, current rank as tie-breaker
 *   - setCaclRank(): placement adjustment + score bonus
 *
 * Version: 1.4.1
 * Date:    2026-09-05
 *
 * Changes from 1.4.0:
 *   - Step 6: call spp_create_membership_table() directly instead of
 *     echo do_shortcode("[cmruncode name='Create membership table']")
 *     -- CM102 has been migrated to inc/spp-create-membership-table.php;
 *     this file is tracked code, so it's one of the callers updated to
 *     call the real function directly rather than round-tripping
 *     through Code Manager. No behavior change: same tables rebuilt,
 *     same net effect on Master/membership/usermeta.
 *
 * Changes from 1.3.0:
 *   - Step 7 (apply): if a pre-run usermeta snapshot exists for this
 *     event (usermeta_rank_pre_event_{$event_id}, written by
 *     spp-create-results.php v2.2.0+), rename it to
 *     usermeta_rank_pre_event_{$event_id}_invalidated_{timestamp}
 *     rather than leaving it in place. A score correction changes
 *     Master/usermeta legitimately (step 6's [cmruncode name='Create
 *     membership table']), so the pre-run snapshot no longer reflects
 *     a trustworthy restore point for a force=1 re-run of
 *     [spp_create_results]. A force run finds no valid snapshot after
 *     this and refuses, telling the operator a score correction has
 *     occurred since the snapshot was taken and usermeta must be
 *     reviewed manually before any re-run.
 *
 * Changes from 1.2.0:
 *   - Steps 3 and 4: split SET @r=0 and UPDATE into separate
 *     $wpdb->query() calls. Multi-statement SQL is silently
 *     truncated by wpdb, leaving @r=NULL and producing wrong
 *     ordinal ranks for everyone (confirmed: caused rank 12
 *     instead of 31 after a score correction).
 *   - Step 6: replaced direct Master UPDATE with
 *     [cmruncode name='Create membership table'] to properly
 *     refresh both membership and Master tables.
 *
 * Changes from 1.1.0:
 *   - Full width layout, no max-width constraint.
 *   - Larger table fonts to use available desktop space.
 *   - Shorter preview column headers.
 *   - Centered data columns, left-aligned player names.
 *   - Mobile responsive with smaller fonts at 600px.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_score_correction', 'spp_score_correction_shortcode' );

function spp_score_correction_shortcode() {
    if ( ! spp_is_admin_or_editor() ) {
        return '<p class="gl-error">You do not have permission to access this tool.</p>';
    }

    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();

    global $wpdb;

    // Find the most recent posted event from Results table
    $last_event_id = (int) $wpdb->get_var( "SELECT event_id FROM Results LIMIT 1" );
    $events = array();

    if ( $last_event_id ) {
        $table = "Schedules_Scores_{$last_event_id}";
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists ) {
            $occ = $wpdb->get_row( $wpdb->prepare(
                "SELECT title, event_date FROM {$wpdb->prefix}gl_event_occurrences WHERE id = %d", $last_event_id
            ), ARRAY_A );
            $label = $occ
                ? $occ['title'] . ' -- ' . date( 'M j, Y', strtotime( $occ['event_date'] ) ) . " (event $last_event_id)"
                : "Event $last_event_id";
            $events[] = array( 'id' => $last_event_id, 'label' => $label, 'table' => $table );
        }
    }

    ob_start();
    ?>
    <style>
        .sc-wrap { margin:20px 0; font-family:Arial,sans-serif; font-size:14px; }
        .sc-heading { font-size:1.2rem; font-weight:bold; color:#2c3e50; margin-bottom:16px; border-bottom:2px solid #3766AB; padding-bottom:8px; }
        .sc-row { margin-bottom:14px; }
        .sc-row label { display:block; font-weight:bold; font-size:0.9rem; color:#555; margin-bottom:4px; }
        .sc-row select, .sc-row input[type=number] { padding:8px 10px; font-size:0.95rem; border:1px solid #ccc; border-radius:4px; width:100%; max-width:600px; box-sizing:border-box; }
        .sc-btn { padding:10px 20px; border:none; border-radius:5px; font-size:0.95rem; cursor:pointer; color:#fff; margin-right:8px; }
        .sc-btn-preview { background:#3766AB; }
        .sc-btn-preview:hover { background:#2a5290; }
        .sc-btn-apply { background:#c0392b; }
        .sc-btn-apply:hover { background:#a93226; }
        .sc-btn:disabled { opacity:0.5; cursor:not-allowed; }
        .sc-preview { background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:16px; margin:16px 0; }
        .sc-preview h4 { margin:0 0 10px; color:#3766AB; }
        .sc-preview table { width:100%; border-collapse:collapse; font-size:0.9rem; margin-top:10px; }
        .sc-preview th { background:#3766AB; color:#fff !important; padding:6px 10px; text-align:center; font-size:0.85rem; }
        .sc-preview th:first-child { text-align:left; }
        .sc-preview td { padding:5px 10px; border-bottom:1px solid #dde7f3; text-align:center; }
        .sc-preview td:first-child { text-align:left; }
        .sc-preview tr:nth-child(even) { background:#e8f0fe; }
        .sc-changed { font-weight:bold; color:#c0392b; }
        .sc-same { color:#888; }
        .sc-msg { padding:12px 16px; border-radius:6px; margin:12px 0; font-size:0.95rem; }
        .sc-msg-ok { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .sc-msg-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .sc-group-table { margin-top:12px; }
        .sc-group-table table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        .sc-group-table th { background:#4a7c59; color:#fff !important; padding:6px 10px; text-align:center; }
        .sc-group-table th:first-child { text-align:left; }
        .sc-group-table td { padding:5px 10px; border-bottom:1px solid #e0e0e0; text-align:center; }
        .sc-group-table td:first-child { text-align:left; }
        .sc-group-table tr:nth-child(even) { background:#f7f7f7; }
        .sc-highlight { background:#fff3cd !important; }
        #sc-status { min-height:20px; margin-top:10px; }
        @media (max-width: 600px) {
            .sc-preview table, .sc-group-table table { font-size:0.78rem; }
            .sc-preview th, .sc-preview td, .sc-group-table th, .sc-group-table td { padding:3px 4px; }
            .sc-row select, .sc-row input[type=number] { max-width:100%; }
        }
    </style>

    <div class="sc-wrap">
        <div class="sc-heading">Score Correction Tool</div>

        <div class="sc-row">
            <label for="sc-event">Event</label>
            <select id="sc-event">
                <option value="">-- Select event --</option>
                <?php foreach ( $events as $ev ) : ?>
                    <option value="<?php echo esc_attr( $ev['id'] ); ?>"><?php echo esc_html( $ev['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sc-row" id="sc-player-row" style="display:none;">
            <label for="sc-player">Player</label>
            <select id="sc-player">
                <option value="">-- Select player --</option>
            </select>
        </div>

        <div id="sc-group-display"></div>

        <div class="sc-row" id="sc-game-row" style="display:none;">
            <label for="sc-game">Game</label>
            <select id="sc-game">
                <option value="">-- Select game --</option>
                <option value="1">Game 1</option>
                <option value="2">Game 2</option>
                <option value="3">Game 3</option>
                <option value="4">Game 4</option>
                <option value="5">Game 5</option>
            </select>
        </div>

        <div class="sc-row" id="sc-score-row" style="display:none;">
            <label for="sc-newscore">New Score</label>
            <input type="number" id="sc-newscore" min="0" max="20" value="">
        </div>

        <div class="sc-row" id="sc-actions" style="display:none;">
            <button type="button" class="sc-btn sc-btn-preview" id="sc-preview-btn" onclick="scPreview()">Preview Changes</button>
            <button type="button" class="sc-btn sc-btn-apply" id="sc-apply-btn" style="display:none;" onclick="scApply()">Apply Changes</button>
        </div>

        <div id="sc-preview-area"></div>
        <div id="sc-status"></div>
    </div>

    <script>
    (function() {
        var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce   = '<?php echo esc_js( wp_create_nonce( 'spp_score_correction' ) ); ?>';

        // Event changed -- load players
        document.getElementById('sc-event').addEventListener('change', function() {
            var eventId = this.value;
            document.getElementById('sc-player').innerHTML = '<option value="">-- Select player --</option>';
            document.getElementById('sc-player-row').style.display = 'none';
            document.getElementById('sc-group-display').innerHTML = '';
            document.getElementById('sc-game-row').style.display = 'none';
            document.getElementById('sc-score-row').style.display = 'none';
            document.getElementById('sc-actions').style.display = 'none';
            document.getElementById('sc-preview-area').innerHTML = '';
            document.getElementById('sc-apply-btn').style.display = 'none';
            document.getElementById('sc-status').innerHTML = '';

            if ( ! eventId ) return;

            var fd = new FormData();
            fd.append('action', 'spp_sc_load_players');
            fd.append('nonce', nonce);
            fd.append('event_id', eventId);

            fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if ( res.success ) {
                        var sel = document.getElementById('sc-player');
                        res.data.players.forEach(function(p) {
                            var opt = document.createElement('option');
                            opt.value = p.user_id;
                            opt.textContent = p.name + ' (rank ' + p.rank + ', group ' + p.group_id + ', score ' + p.score + ')';
                            opt.dataset.groupId = p.group_id;
                            sel.appendChild(opt);
                        });
                        document.getElementById('sc-player-row').style.display = 'block';
                    }
                });
        });

        // Player changed -- show group and game selector
        document.getElementById('sc-player').addEventListener('change', function() {
            var userId = this.value;
            var eventId = document.getElementById('sc-event').value;
            document.getElementById('sc-group-display').innerHTML = '';
            document.getElementById('sc-game-row').style.display = 'none';
            document.getElementById('sc-score-row').style.display = 'none';
            document.getElementById('sc-actions').style.display = 'none';
            document.getElementById('sc-preview-area').innerHTML = '';
            document.getElementById('sc-apply-btn').style.display = 'none';
            document.getElementById('sc-status').innerHTML = '';

            if ( ! userId ) return;

            var fd = new FormData();
            fd.append('action', 'spp_sc_load_group');
            fd.append('nonce', nonce);
            fd.append('event_id', eventId);
            fd.append('user_id', userId);

            fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if ( res.success ) {
                        document.getElementById('sc-group-display').innerHTML = res.data.html;
                        document.getElementById('sc-game-row').style.display = 'block';
                        document.getElementById('sc-score-row').style.display = 'block';
                        document.getElementById('sc-actions').style.display = 'block';
                    }
                });
        });

        // Preview
        window.scPreview = function() {
            var eventId  = document.getElementById('sc-event').value;
            var userId   = document.getElementById('sc-player').value;
            var gameNum  = document.getElementById('sc-game').value;
            var newScore = document.getElementById('sc-newscore').value;

            if ( !eventId || !userId || !gameNum || newScore === '' ) {
                document.getElementById('sc-status').innerHTML = '<div class="sc-msg sc-msg-err">Please fill in all fields.</div>';
                return;
            }

            document.getElementById('sc-preview-btn').disabled = true;
            document.getElementById('sc-preview-btn').textContent = 'Calculating...';
            document.getElementById('sc-status').innerHTML = '';

            var fd = new FormData();
            fd.append('action', 'spp_sc_preview');
            fd.append('nonce', nonce);
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('game_num', gameNum);
            fd.append('new_score', newScore);

            fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    document.getElementById('sc-preview-btn').disabled = false;
                    document.getElementById('sc-preview-btn').textContent = 'Preview Changes';
                    if ( res.success ) {
                        document.getElementById('sc-preview-area').innerHTML = res.data.html;
                        if ( res.data.has_changes ) {
                            document.getElementById('sc-apply-btn').style.display = 'inline-block';
                        } else {
                            document.getElementById('sc-apply-btn').style.display = 'none';
                        }
                    } else {
                        document.getElementById('sc-status').innerHTML = '<div class="sc-msg sc-msg-err">' + (res.data || 'Preview failed.') + '</div>';
                    }
                });
        };

        // Apply
        window.scApply = function() {
            if ( ! confirm('Apply these changes? This will update Schedules_Scores, Results_all, Results, Master, and user meta.') ) return;

            var eventId  = document.getElementById('sc-event').value;
            var userId   = document.getElementById('sc-player').value;
            var gameNum  = document.getElementById('sc-game').value;
            var newScore = document.getElementById('sc-newscore').value;

            document.getElementById('sc-apply-btn').disabled = true;
            document.getElementById('sc-apply-btn').textContent = 'Applying...';

            var fd = new FormData();
            fd.append('action', 'spp_sc_apply');
            fd.append('nonce', nonce);
            fd.append('event_id', eventId);
            fd.append('user_id', userId);
            fd.append('game_num', gameNum);
            fd.append('new_score', newScore);

            fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    document.getElementById('sc-apply-btn').disabled = false;
                    document.getElementById('sc-apply-btn').textContent = 'Apply Changes';
                    if ( res.success ) {
                        document.getElementById('sc-status').innerHTML = '<div class="sc-msg sc-msg-ok">' + res.data.message + '</div>';
                        document.getElementById('sc-apply-btn').style.display = 'none';
                        document.getElementById('sc-preview-area').innerHTML = res.data.html || '';
                    } else {
                        document.getElementById('sc-status').innerHTML = '<div class="sc-msg sc-msg-err">' + (res.data || 'Apply failed.') + '</div>';
                    }
                });
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ============================================================
// AJAX: Load players for an event
// ============================================================
add_action( 'wp_ajax_spp_sc_load_players', function() {
    if ( ! spp_is_admin_or_editor() ) wp_send_json_error( 'No permission' );
    if ( ! check_ajax_referer( 'spp_score_correction', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce' );

    global $wpdb;
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    if ( ! $event_id ) wp_send_json_error( 'No event ID' );

    $table = "Schedules_Scores_{$event_id}";
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) wp_send_json_error( 'Scores table not found' );

    $rows = $wpdb->get_results(
        "SELECT user_id, first_name, last_name, Rank, group_id,
                COALESCE(IF(Game1>=0,Game1,0),0) + COALESCE(IF(Game2>=0,Game2,0),0)
                + COALESCE(IF(Game3>=0,Game3,0),0) + COALESCE(IF(Game4>=0,Game4,0),0)
                + COALESCE(IF(Game5>=0,Game5,0),0) AS total_score
         FROM {$table}
         WHERE group_id != 99
         ORDER BY first_name ASC, last_name ASC",
        ARRAY_A
    );

    $players = array();
    foreach ( $rows as $r ) {
        $players[] = array(
            'user_id'  => (int) $r['user_id'],
            'name'     => trim( $r['first_name'] . ' ' . $r['last_name'] ),
            'rank'     => (int) $r['Rank'],
            'group_id' => (int) $r['group_id'],
            'score'    => (int) $r['total_score'],
        );
    }

    wp_send_json_success( array( 'players' => $players ) );
});

// ============================================================
// AJAX: Load group details for selected player
// ============================================================
add_action( 'wp_ajax_spp_sc_load_group', function() {
    if ( ! spp_is_admin_or_editor() ) wp_send_json_error( 'No permission' );
    if ( ! check_ajax_referer( 'spp_score_correction', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce' );

    global $wpdb;
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    $user_id  = (int) ( $_POST['user_id'] ?? 0 );
    if ( ! $event_id || ! $user_id ) wp_send_json_error( 'Missing parameters' );

    $table = "Schedules_Scores_{$event_id}";
    $group_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT group_id FROM {$table} WHERE user_id = %d", $user_id
    ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, last_name, RankPrime, Rank,
                Game1, Game2, Game3, Game4, Game5
         FROM {$table} WHERE group_id = %d ORDER BY Rank",
        $group_id
    ), ARRAY_A );

    $html = '<div class="sc-group-table">';
    $html .= '<strong>Group ' . $group_id . ' -- Current Scores:</strong>';
    $html .= '<table><thead><tr><th>Player</th><th>RankPrime</th><th>G1</th><th>G2</th><th>G3</th><th>G4</th><th>G5</th><th>Total</th></tr></thead><tbody>';

    foreach ( $rows as $r ) {
        $total = 0;
        for ( $g = 1; $g <= 5; $g++ ) {
            $v = $r["Game{$g}"];
            if ( $v !== null && (int) $v >= 0 ) $total += (int) $v;
        }
        $highlight = ( (int) $r['user_id'] === $user_id ) ? ' class="sc-highlight"' : '';
        $name = trim( $r['first_name'] . ' ' . $r['last_name'] );
        $html .= "<tr{$highlight}>";
        $html .= '<td>' . esc_html( $name ) . '</td>';
        $html .= '<td>' . esc_html( $r['RankPrime'] ) . '</td>';
        for ( $g = 1; $g <= 5; $g++ ) {
            $v = $r["Game{$g}"];
            $display = ( $v === null || $v === '' ) ? '--' : (int) $v;
            $html .= '<td>' . $display . '</td>';
        }
        $html .= '<td><strong>' . $total . '</strong></td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    wp_send_json_success( array( 'html' => $html ) );
});

// ============================================================
// Ranking helpers -- identical to Create Results
// ============================================================
function spp_sc_rank_users_with_ties( $scores, $currentRanks ) {
    $entries = array();
    foreach ( $scores as $user_id => $score ) {
        $entries[] = array(
            'user_id'      => $user_id,
            'score'        => $score,
            'current_rank' => isset( $currentRanks[ $user_id ] ) ? $currentRanks[ $user_id ] : 9999,
        );
    }

    usort( $entries, function( $a, $b ) {
        if ( $b['score'] !== $a['score'] ) {
            return $b['score'] - $a['score'];
        }
        return $a['current_rank'] - $b['current_rank'];
    } );

    $ranks = array();
    $rank  = 0;
    foreach ( $entries as $entry ) {
        $rank++;
        $ranks[ $entry['user_id'] ] = $rank;
    }

    return $ranks;
}

function spp_sc_calc_rank( $maxrk, $group_rank, $rank, $score, $bonus_max, $bonus_min ) {
    $f      = 1;
    $bonus  = 3;
    $win    = -3.0 * $f;
    $mid2nd = -1.8 * $f;
    $mid3rd =  0.3 * $f;
    $mid4th =  1.4 * $f;
    $lose   =  2.4 * $f;

    if ( $score >= $bonus_max ) {
        $win    = ( $win    - $bonus ) * $f;
        $mid2nd = ( $mid2nd - $bonus ) * $f;
        $mid3rd = ( $mid3rd - $bonus ) * $f;
        $mid4th = ( $mid4th - $bonus ) * $f;
        $lose   = ( $lose   - $bonus ) * $f;
    }
    if ( $score <= $bonus_min ) {
        $win    = ( $win    + $bonus ) * $f;
        $mid2nd = ( $mid2nd + $bonus ) * $f;
        $mid3rd = ( $mid3rd + $bonus ) * $f;
        $mid4th = ( $mid4th + $bonus ) * $f;
        $lose   = ( $lose   + $bonus ) * $f;
    }

    $adjustments = array(
        1 => array( 1 => $win ),
        2 => array( 1 => $win, 2 => $lose ),
        3 => array( 1 => $win, 2 => $mid2nd, 3 => $lose ),
        4 => array( 1 => $win, 2 => $mid2nd, 3 => $mid3rd, 4 => $lose ),
        5 => array( 1 => $win, 2 => $mid2nd, 3 => $mid3rd, 4 => $mid4th, 5 => $lose ),
    );

    if ( ! isset( $adjustments[ $maxrk ][ $group_rank ] ) ) return 9999;

    return max( 1, $rank + $adjustments[ $maxrk ][ $group_rank ] );
}

// ============================================================
// Core recalculation engine -- used by both preview and apply
// ============================================================
function spp_sc_recalculate( $event_id, $user_id, $game_num, $new_score ) {
    global $wpdb;

    $table    = "Schedules_Scores_{$event_id}";
    $group_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT group_id FROM {$table} WHERE user_id = %d", $user_id
    ) );

    if ( ! $group_id || $group_id == 99 ) {
        return array( 'error' => 'Player not found in a valid group.' );
    }

    // Get all group members with current scores
    $members = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, last_name, RankPrime,
                Game1, Game2, Game3, Game4, Game5
         FROM {$table} WHERE group_id = %d ORDER BY CAST(RankPrime AS UNSIGNED)",
        $group_id
    ), ARRAY_A );

    // Build old scores and current ranks
    $old_scores    = array();
    $new_scores    = array();
    $current_ranks = array();
    $game_col      = "Game{$game_num}";

    foreach ( $members as &$m ) {
        $uid  = (int) $m['user_id'];
        $rp   = (int) $m['RankPrime'];
        $current_ranks[ $uid ] = $rp;

        $old_total = 0;
        $new_total = 0;
        for ( $g = 1; $g <= 5; $g++ ) {
            $v = $m["Game{$g}"];
            $val = ( $v !== null && (int) $v >= 0 ) ? (int) $v : 0;
            $old_total += $val;

            if ( $uid === $user_id && $g === $game_num ) {
                $new_total += (int) $new_score;
                $m["Game{$g}_new"] = (int) $new_score;
            } else {
                $new_total += $val;
                $m["Game{$g}_new"] = $val;
            }
        }

        $old_scores[ $uid ] = $old_total;
        $new_scores[ $uid ] = $new_total;
    }
    unset( $m );

    // Determine bonus thresholds from the full event
    $maxscore = (int) $wpdb->get_var(
        "SELECT MAX(
            COALESCE(IF(Game1>=0,Game1,0),0) + COALESCE(IF(Game2>=0,Game2,0),0)
            + COALESCE(IF(Game3>=0,Game3,0),0) + COALESCE(IF(Game4>=0,Game4,0),0)
            + COALESCE(IF(Game5>=0,Game5,0),0)
        ) FROM {$table} WHERE group_id != 99"
    );
    // Also consider the new score
    if ( $new_scores[ $user_id ] > $maxscore ) $maxscore = $new_scores[ $user_id ];

    if ( $maxscore > 48 ) {
        $bonus_max = 56;
        $bonus_min = 28;
    } else {
        $bonus_max = 45;
        $bonus_min = 20;
    }

    // Old group rankings
    $old_group_ranks = spp_sc_rank_users_with_ties( $old_scores, $current_ranks );
    $new_group_ranks = spp_sc_rank_users_with_ties( $new_scores, $current_ranks );

    // maxrk = number of players who actually played (Game1 >= 0)
    $playing_count = 0;
    foreach ( $members as $m ) {
        $g1 = $m['Game1'];
        if ( $g1 === null || (int) $g1 >= 0 ) $playing_count++;
    }
    $maxrk = $playing_count;

    // Calculate old and new RankCalc for each group member
    $old_calcs = array();
    $new_calcs = array();
    foreach ( $members as $m ) {
        $uid = (int) $m['user_id'];
        $rp  = $current_ranks[ $uid ];

        // Check if player was NP/NS -- Game1 < 0 means NP (-1) or NS (-2)
        $g1 = $m['Game1'];
        if ( $g1 !== null && (int) $g1 < 0 ) {
            $old_calcs[ $uid ] = null;
            $new_calcs[ $uid ] = null;
            continue;
        }

        $old_calcs[ $uid ] = spp_sc_calc_rank( $maxrk, $old_group_ranks[ $uid ], $rp, $old_scores[ $uid ], $bonus_max, $bonus_min );
        $new_calcs[ $uid ] = spp_sc_calc_rank( $maxrk, $new_group_ranks[ $uid ], $rp, $new_scores[ $uid ], $bonus_max, $bonus_min );
    }

    // Get current Results_all data for this group
    $results_data = array();
    foreach ( $members as $m ) {
        $uid = (int) $m['user_id'];
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT Rank, RankPrev, RankCalc, RankOverride, Score
             FROM Results_all WHERE event_id = %d AND user_id = %d",
            $event_id, $uid
        ), ARRAY_A );
        $results_data[ $uid ] = $r;
    }

    return array(
        'group_id'        => $group_id,
        'members'         => $members,
        'old_scores'      => $old_scores,
        'new_scores'      => $new_scores,
        'old_group_ranks' => $old_group_ranks,
        'new_group_ranks' => $new_group_ranks,
        'old_calcs'       => $old_calcs,
        'new_calcs'       => $new_calcs,
        'results_data'    => $results_data,
        'bonus_max'       => $bonus_max,
        'bonus_min'       => $bonus_min,
        'game_col'        => $game_col,
        'game_num'        => $game_num,
        'new_score_val'   => (int) $new_score,
        'target_user'     => $user_id,
    );
}

// ============================================================
// AJAX: Preview changes
// ============================================================
add_action( 'wp_ajax_spp_sc_preview', function() {
    if ( ! spp_is_admin_or_editor() ) wp_send_json_error( 'No permission' );
    if ( ! check_ajax_referer( 'spp_score_correction', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce' );

    $event_id  = (int) ( $_POST['event_id'] ?? 0 );
    $user_id   = (int) ( $_POST['user_id'] ?? 0 );
    $game_num  = (int) ( $_POST['game_num'] ?? 0 );
    $new_score = (int) ( $_POST['new_score'] ?? -1 );

    if ( ! $event_id || ! $user_id || ! $game_num || $new_score < 0 ) {
        wp_send_json_error( 'Invalid parameters.' );
    }

    $result = spp_sc_recalculate( $event_id, $user_id, $game_num, $new_score );

    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }

    $has_changes = false;

    // Build preview HTML
    $html = '<div class="sc-preview">';
    $html .= '<h4>Preview -- Group ' . $result['group_id'] . '</h4>';
    $html .= '<table>';
    $html .= '<thead><tr><th>Player</th><th>Rank</th><th>Old</th><th>New</th><th>Old Grp</th><th>New Grp</th><th>Old Calc</th><th>New Calc</th><th>+/-</th></tr></thead>';
    $html .= '<tbody>';

    foreach ( $result['members'] as $m ) {
        $uid  = (int) $m['user_id'];
        $name = trim( $m['first_name'] . ' ' . $m['last_name'] );
        $rp   = (int) $m['RankPrime'];

        $old_score = $result['old_scores'][ $uid ];
        $new_score_val = $result['new_scores'][ $uid ];
        $old_gr = $result['old_group_ranks'][ $uid ] ?? '--';
        $new_gr = $result['new_group_ranks'][ $uid ] ?? '--';
        $old_rc = $result['old_calcs'][ $uid ];
        $new_rc = $result['new_calcs'][ $uid ];

        if ( $old_rc === null ) {
            $html .= '<tr><td>' . esc_html( $name ) . '</td><td>' . $rp . '</td>';
            $html .= '<td colspan="7" class="sc-same">NP/NS -- not affected</td></tr>';
            continue;
        }

        $score_class = ( $old_score !== $new_score_val ) ? 'sc-changed' : 'sc-same';
        $gr_class    = ( $old_gr !== $new_gr ) ? 'sc-changed' : 'sc-same';
        $rc_class    = ( number_format( $old_rc, 2 ) !== number_format( $new_rc, 2 ) ) ? 'sc-changed' : 'sc-same';

        if ( $old_score !== $new_score_val || $old_gr !== $new_gr || number_format( $old_rc, 2 ) !== number_format( $new_rc, 2 ) ) {
            $has_changes = true;
        }

        $diff = $new_rc - $old_rc;
        $diff_str = ( $diff == 0 ) ? '--' : ( ( $diff > 0 ? '+' : '' ) . number_format( $diff, 2 ) );

        $row_highlight = ( $uid === $result['target_user'] ) ? ' class="sc-highlight"' : '';

        $html .= "<tr{$row_highlight}>";
        $html .= '<td>' . esc_html( $name ) . '</td>';
        $html .= '<td>' . $rp . '</td>';
        $html .= "<td class=\"{$score_class}\">{$old_score}</td>";
        $html .= "<td class=\"{$score_class}\">{$new_score_val}</td>";
        $html .= "<td class=\"{$gr_class}\">{$old_gr}</td>";
        $html .= "<td class=\"{$gr_class}\">{$new_gr}</td>";
        $html .= "<td class=\"{$rc_class}\">" . number_format( $old_rc, 2 ) . "</td>";
        $html .= "<td class=\"{$rc_class}\">" . number_format( $new_rc, 2 ) . "</td>";
        $html .= "<td class=\"{$rc_class}\">{$diff_str}</td>";
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    if ( ! $has_changes ) {
        $html .= '<p class="sc-same" style="margin-top:10px;">No changes -- the new score produces the same results.</p>';
    } else {
        $html .= '<p style="margin-top:10px;color:#c0392b;font-weight:bold;">Click "Apply Changes" to commit. This will update Schedules_Scores, Results_all, Results, Master, and user meta.</p>';
    }

    $html .= '</div>';

    wp_send_json_success( array( 'html' => $html, 'has_changes' => $has_changes ) );
});

// ============================================================
// AJAX: Apply changes
// ============================================================
add_action( 'wp_ajax_spp_sc_apply', function() {
    if ( ! spp_is_admin_or_editor() ) wp_send_json_error( 'No permission' );
    if ( ! check_ajax_referer( 'spp_score_correction', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce' );

    global $wpdb;

    $event_id  = (int) ( $_POST['event_id'] ?? 0 );
    $user_id   = (int) ( $_POST['user_id'] ?? 0 );
    $game_num  = (int) ( $_POST['game_num'] ?? 0 );
    $new_score = (int) ( $_POST['new_score'] ?? -1 );

    if ( ! $event_id || ! $user_id || ! $game_num || $new_score < 0 ) {
        wp_send_json_error( 'Invalid parameters.' );
    }

    $result = spp_sc_recalculate( $event_id, $user_id, $game_num, $new_score );

    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }

    $table    = "Schedules_Scores_{$event_id}";
    $game_col = $result['game_col'];
    $changes  = array();

    // 1. Update the game score in Schedules_Scores
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET {$game_col} = %d WHERE user_id = %d",
        $result['new_score_val'], $user_id
    ) );

    // Update Score column (not generated in Schedules_Scores)
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET Score = 
            COALESCE(IF(Game1>=0,Game1,0),0) + COALESCE(IF(Game2>=0,Game2,0),0)
            + COALESCE(IF(Game3>=0,Game3,0),0) + COALESCE(IF(Game4>=0,Game4,0),0)
            + COALESCE(IF(Game5>=0,Game5,0),0)
         WHERE user_id = %d",
        $user_id
    ) );

    // 2. Update Results_all and Results for all affected group members
    foreach ( $result['members'] as $m ) {
        $uid    = (int) $m['user_id'];
        $new_rc = $result['new_calcs'][ $uid ];
        $new_sc = $result['new_scores'][ $uid ];

        if ( $new_rc === null ) continue; // NP/NS

        $old_data = $result['results_data'][ $uid ];
        $old_rc   = $old_data ? (float) $old_data['RankCalc'] : null;

        // Update Results_all
        $wpdb->query( $wpdb->prepare(
            "UPDATE Results_all SET RankCalc = %f, RankOverride = %f, Score = %d
             WHERE event_id = %d AND user_id = %d",
            $new_rc, $new_rc, $new_sc, $event_id, $uid
        ) );

        // Update Results (current event only)
        $wpdb->query( $wpdb->prepare(
            "UPDATE Results SET RankCalc = %f, RankOverride = %f, Score = %d
             WHERE user_id = %d",
            $new_rc, $new_rc, $new_sc, $uid
        ) );

        if ( $old_rc !== null && number_format( $old_rc, 2 ) !== number_format( $new_rc, 2 ) ) {
            $name = trim( $m['first_name'] . ' ' . $m['last_name'] );
            $changes[] = "{$name}: RankCalc " . number_format( $old_rc, 2 ) . " -> " . number_format( $new_rc, 2 );
        }
    }

    // 3. Re-rank the entire event in Results_all (ordinal Rank column)
    // NOTE: SET and UPDATE must be separate $wpdb->query() calls --
    // multi-statement SQL is silently truncated to the first statement
    // only, leaving @r=NULL and producing wrong ranks for everyone.
    $wpdb->query( "SET @r=0" );
    $wpdb->query(
        "UPDATE Results_all SET Rank = (@r:=@r+1)
         WHERE event_id = {$event_id}
         ORDER BY CAST(RankOverride AS DECIMAL(6,2)) ASC, RankPrev ASC"
    );

    // 4. Re-rank Results table (no re-sort needed -- RankOverride already
    // correct from step 2; just assign sequential ordinal integers)
    $wpdb->query( "SET @r=0" );
    $wpdb->query(
        "UPDATE Results SET Rank = (@r:=@r+1)
         ORDER BY CAST(RankOverride AS DECIMAL(6,2)) ASC, RankPrev ASC"
    );

    // 5. Update user meta for all players in Results
    $wpdb->query(
        "UPDATE {$wpdb->prefix}usermeta um
         JOIN Results r ON um.user_id = r.user_id
         SET um.meta_value = r.Rank
         WHERE um.meta_key = 'Rank'"
    );

    // 6. Refresh membership and Master tables
    spp_create_membership_table();

    // 7. Invalidate any pre-run usermeta snapshot for this event (v1.4.0).
    // spp-create-results.php's force=1 restore path relies on that snapshot
    // being an untouched pre-run state; step 6 above just changed Master/
    // usermeta legitimately, so the snapshot -- if one exists -- no longer
    // reflects a trustworthy restore point. Rename it (never drop -- keep
    // the data for manual review) so a later force=1 run finds nothing
    // valid and refuses.
    $snapshot_table = "usermeta_rank_pre_event_{$event_id}";
    $snapshot_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $snapshot_table ) );
    if ( $snapshot_exists ) {
        $invalidated_table = $snapshot_table . '_invalidated_' . time();
        $wpdb->query( "RENAME TABLE `{$snapshot_table}` TO `{$invalidated_table}`" );
        $msg_snapshot_note = " Pre-run snapshot {$snapshot_table} invalidated (renamed to {$invalidated_table}) -- a score correction has occurred since it was taken, so usermeta must be reviewed manually before any [spp_create_results force=\"1\"] re-run of this event.";
    } else {
        $msg_snapshot_note = '';
    }

    $msg = 'Score correction applied successfully.';
    if ( ! empty( $changes ) ) {
        $msg .= ' Changes: ' . implode( '; ', $changes ) . '.';
    }
    $msg .= ' Ordinal ranks recalculated. User meta and Master updated.';
    $msg .= $msg_snapshot_note;

    wp_send_json_success( array( 'message' => $msg ) );
});