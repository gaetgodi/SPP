<?php
/**
 * SPP Player Score Entry
 *
 * Shortcode: [spp_score_entry]
 *
 * Lets a logged-in player enter their group's scores from their phone.
 * - Identifies the player's group from Schedules via user_id
 * - For each round, the player taps the LOSING team and enters
 *   their score; the system fills in all four players:
 *     Winners get the fixed winning score (15 for 5-player groups,
 *     20 for 4-player groups), both losers get the entered score.
 * - Any player in the group can enter scores; most recent entry wins.
 * - Available only while spp_schedule_published = 1.
 * - Paper score sheets + Score Scanner remain the verification layer.
 *
 * Version: 1.3.0
 * Date:    2026-06-16
 *
 * Changes from 1.2.0:
 *   - UX: tap the LOSING team instead of the winning team.
 *     More intuitive -- player taps a team and enters THAT
 *     team's score. JS flips to winner for the AJAX call.
 *
 * Changes from 1.1.0:
 *   - Reset button per round: clears scores (sets Game column to NULL),
 *     recomputes totals, resets UI state.
 *
 * Changes from 1.0.0:
 *   - Group selector at top: greyed out (their own group) for players,
 *     active dropdown for editors/admins to enter/correct any group.
 *   - Match status block: per-player running totals and rounds-entered
 *     count, refreshed automatically after each save.
 */

defined( 'ABSPATH' ) || exit;

// ── Shared pairings helper ───────────────────────────────────────────────────

function spp_se_pairings( int $count ) : array {
    if ( $count >= 5 ) {
        return array(
            array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>4),
            array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,4),'bye'=>3),
            array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(2,4),'bye'=>1),
            array('name'=>'Round 4','blue'=>array(0,4),'red'=>array(1,3),'bye'=>2),
            array('name'=>'Round 5','blue'=>array(1,2),'red'=>array(3,4),'bye'=>0),
        );
    }
    if ( $count >= 4 ) {
        return array(
            array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>-1),
            array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,3),'bye'=>-1),
            array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(1,2),'bye'=>-1),
        );
    }
    return array(
        array('name'=>'Round 1','blue'=>array(0),'red'=>array(1),'bye'=>2),
        array('name'=>'Round 2','blue'=>array(0),'red'=>array(2),'bye'=>1),
        array('name'=>'Round 3','blue'=>array(1),'red'=>array(2),'bye'=>0),
    );
}

// ── Status helper: per-player totals + rounds entered ────────────────────────

function spp_se_group_status( int $group_id ) : array {
    global $wpdb;

    $players = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, Game1, Game2, Game3, Game4, Game5
         FROM Schedules WHERE group_id = %d ORDER BY Rank",
        $group_id
    ), ARRAY_A );

    $count    = count( $players );
    $pairings = spp_se_pairings( $count );

    $totals = array();
    foreach ( $players as $p ) {
        $tot = 0;
        for ( $g = 1; $g <= 5; $g++ ) {
            $v = $p[ 'Game' . $g ];
            if ( $v !== null && $v !== '' && (int) $v >= 0 ) $tot += (int) $v;
        }
        $totals[] = array( 'name' => $p['first_name'], 'total' => $tot );
    }

    $entered = 0;
    foreach ( $pairings as $i => $r ) {
        $g    = 'Game' . ( $i + 1 );
        $pos  = $r['blue'][0];
        if ( isset( $players[ $pos ] ) ) {
            $v = $players[ $pos ][ $g ];
            if ( $v !== null && $v !== '' ) $entered++;
        }
    }

    return array(
        'totals'  => $totals,
        'entered' => $entered,
        'rounds'  => count( $pairings ),
    );
}

// ── Shortcode ────────────────────────────────────────────────────────────────

add_shortcode( 'spp_score_entry', 'spp_score_entry_shortcode' );

function spp_score_entry_shortcode() {
    global $wpdb;

    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to enter scores.</p>';
    }

    if ( (int) get_option( 'spp_schedule_published', 0 ) !== 1 ) {
        return '<p>Score entry is not open — no schedule is currently published.</p>';
    }

    $user_id  = get_current_user_id();
    $is_admin = current_user_can( 'edit_posts' );

    $all_groups = $wpdb->get_results(
        "SELECT s.group_id, g.GP_name, c.Crt_name, t.T_desc, COUNT(*) as players
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Courts c ON s.Crt_ID = c.Crt_ID
         JOIN Times t  ON s.time_id = t.T_ID
         WHERE s.group_id != 99
         GROUP BY s.group_id, g.GP_name, c.Crt_name, t.T_desc
         ORDER BY s.group_id",
        ARRAY_A
    );

    $me = $wpdb->get_row( $wpdb->prepare(
        "SELECT group_id FROM Schedules WHERE user_id = %d AND group_id != 99",
        $user_id
    ), ARRAY_A );
    $own_group = $me ? (int) $me['group_id'] : 0;

    if ( $is_admin && isset( $_GET['se_group'] ) && intval( $_GET['se_group'] ) > 0 ) {
        $group_id = intval( $_GET['se_group'] );
    } elseif ( $own_group ) {
        $group_id = $own_group;
    } elseif ( $is_admin && ! empty( $all_groups ) ) {
        $group_id = (int) $all_groups[0]['group_id'];
    } else {
        return '<p>You are not in tonight\'s schedule, so there are no scores for you to enter.</p>';
    }

    $players = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank,
                s.Game1, s.Game2, s.Game3, s.Game4, s.Game5,
                g.GP_name, c.Crt_name, t.T_desc
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Courts c ON s.Crt_ID = c.Crt_ID
         JOIN Times t  ON s.time_id = t.T_ID
         WHERE s.group_id = %d
         ORDER BY s.Rank",
        $group_id
    ), ARRAY_A );

    if ( empty( $players ) ) {
        return '<p>Could not load the group.</p>';
    }

    $count     = count( $players );
    $pairings  = spp_se_pairings( $count );
    $win_score = ( $count >= 5 ) ? 15 : 20;
    $p0        = $players[0];
    $status    = spp_se_group_status( $group_id );

    ob_start();
    ?>
    <style>
        .se-wrap { max-width:560px; margin:10px auto; font-family:Arial,sans-serif; font-size:15px; }
        .se-group-select { margin-bottom:10px; text-align:center; }
        .se-group-select select { padding:8px 12px; font-size:15px; border:1px solid #bbb; border-radius:6px; max-width:100%; }
        .se-group-select select:disabled { background:#eee; color:#555; }
        .se-header { background:#2c3e50; color:#fff; padding:10px 14px; border-radius:6px; font-weight:bold; text-align:center; margin-bottom:6px; }
        .se-sub { text-align:center; color:#666; font-size:13px; margin-bottom:10px; }
        .se-status { background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:10px 12px; margin-bottom:14px; }
        .se-status-title { font-weight:bold; color:#3766AB; font-size:14px; margin-bottom:6px; }
        .se-status-progress { font-size:13px; color:#555; margin-bottom:6px; }
        .se-status table { width:100%; border-collapse:collapse; font-size:14px; }
        .se-status td { padding:3px 6px; border-bottom:1px solid #dde7f3; }
        .se-status td:last-child { text-align:right; font-weight:bold; }
        .se-round { background:#fff; border:1px solid #ddd; border-radius:8px; margin-bottom:14px; padding:12px; }
        .se-round-title { font-weight:bold; font-size:16px; margin-bottom:8px; color:#2c3e50; }
        .se-teams { display:flex; gap:8px; margin-bottom:10px; }
        .se-team { flex:1; border-radius:6px; padding:10px 8px; text-align:center; cursor:pointer; border:3px solid transparent; user-select:none; }
        .se-team-blue { background:#daeef5; }
        .se-team-red  { background:#fce4ec; }
        .se-team.se-loser { border-color:#c0392b; }
        .se-team .se-team-label { font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#666; }
        .se-team .se-team-names { font-size:14px; margin-top:2px; }
        .se-team .se-lose-tag { display:none; color:#c0392b; font-size:12px; font-weight:bold; }
        .se-team.se-loser .se-lose-tag { display:block; }
        .se-team .se-win-tag { display:none; color:#27ae60; font-size:12px; font-weight:bold; }
        .se-team.se-auto-winner .se-win-tag { display:block; }
        .se-bye { font-size:13px; color:#888; margin-bottom:8px; }
        .se-score-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .se-score-row label { font-size:14px; }
        .se-score-input { width:70px; padding:8px; font-size:18px; text-align:center; border:1px solid #bbb; border-radius:6px; }
        .se-save-btn { padding:10px 18px; background:#3766AB; color:#fff; border:none; border-radius:6px; font-size:15px; cursor:pointer; }
        .se-save-btn:disabled { background:#aaa; }
        .se-reset-btn { padding:10px 14px; background:#dc3545; color:#fff; border:none; border-radius:6px; font-size:13px; cursor:pointer; }
        .se-saved { font-size:13px; color:#27ae60; font-weight:bold; margin-left:8px; }
        .se-current { font-size:13px; color:#555; margin-top:6px; }
        .se-msg { padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:14px; display:none; }
        .se-msg-ok  { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .se-msg-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
    </style>

    <div class="se-wrap">

        <div class="se-group-select">
            <select id="se_group_select" <?php echo $is_admin ? '' : 'disabled'; ?>>
                <?php foreach ( $all_groups as $g ) : ?>
                    <option value="<?php echo esc_attr( $g['group_id'] ); ?>" <?php selected( (int) $g['group_id'], $group_id ); ?>>
                        <?php echo esc_html( $g['GP_name'] . ' -- ' . $g['Crt_name'] . ' -- ' . $g['T_desc'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="se-header">
            <?php echo esc_html( $p0['GP_name'] . ' -- ' . $p0['Crt_name'] . ' -- ' . $p0['T_desc'] ); ?>
        </div>
        <div class="se-sub">
            Tap the losing team and enter their score.
            Winners automatically get <?php echo $win_score; ?>.
        </div>

        <div class="se-status" id="se_status">
            <div class="se-status-title">Match Status</div>
            <div class="se-status-progress" id="se_status_progress">
                Rounds entered: <?php echo $status['entered']; ?> of <?php echo $status['rounds']; ?>
            </div>
            <table id="se_status_table">
                <?php foreach ( $status['totals'] as $t ) : ?>
                    <tr><td><?php echo esc_html( $t['name'] ); ?></td><td><?php echo esc_html( $t['total'] ); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="se-msg" id="se_msg"></div>

        <?php foreach ( $pairings as $rnd_idx => $round ) :
            $rnd_no     = $rnd_idx + 1;
            $blue_names = array();
            $red_names  = array();
            foreach ( $round['blue'] as $i ) { if ( isset( $players[ $i ] ) ) $blue_names[] = $players[ $i ]['first_name']; }
            foreach ( $round['red']  as $i ) { if ( isset( $players[ $i ] ) ) $red_names[]  = $players[ $i ]['first_name']; }
            $bye_name = ( $round['bye'] >= 0 && isset( $players[ $round['bye'] ] ) ) ? $players[ $round['bye'] ]['first_name'] : '';

            $game_col   = 'Game' . $rnd_no;
            $blue_score = isset( $players[ $round['blue'][0] ] ) ? $players[ $round['blue'][0] ][ $game_col ] : null;
            $red_score  = isset( $players[ $round['red'][0] ] )  ? $players[ $round['red'][0] ][ $game_col ]  : null;
            $has_scores = ( $blue_score !== null && $blue_score !== '' && $red_score !== null && $red_score !== '' );
        ?>
            <div class="se-round" data-round="<?php echo $rnd_no; ?>">
                <div class="se-round-title"><?php echo esc_html( $round['name'] ); ?></div>
                <?php if ( $bye_name ) : ?>
                    <div class="se-bye">Bye: <?php echo esc_html( $bye_name ); ?></div>
                <?php endif; ?>
                <div class="se-teams">
                    <div class="se-team se-team-blue" data-team="blue">
                        <div class="se-team-label">Blue</div>
                        <div class="se-team-names"><?php echo esc_html( implode( ' / ', $blue_names ) ); ?></div>
                        <div class="se-lose-tag">LOST</div>
                        <div class="se-win-tag">WINNER</div>
                    </div>
                    <div class="se-team se-team-red" data-team="red">
                        <div class="se-team-label">Red</div>
                        <div class="se-team-names"><?php echo esc_html( implode( ' / ', $red_names ) ); ?></div>
                        <div class="se-lose-tag">LOST</div>
                        <div class="se-win-tag">WINNER</div>
                    </div>
                </div>
                <div class="se-score-row">
                    <label>Their score:</label>
                    <input type="number" class="se-score-input" min="0" max="<?php echo $win_score - 1; ?>" inputmode="numeric" pattern="[0-9]*">
                    <button type="button" class="se-save-btn" disabled>Save</button>
                    <button type="button" class="se-reset-btn">Reset</button>
                    <span class="se-saved" style="display:none;">Saved</span>
                </div>
                <?php if ( $has_scores ) : ?>
                    <div class="se-current">
                        Current: Blue <?php echo esc_html( $blue_score ); ?> -- Red <?php echo esc_html( $red_score ); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ajaxurl  = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce    = '<?php echo esc_js( wp_create_nonce( 'spp_score_entry' ) ); ?>';
        var groupId  = <?php echo (int) $group_id; ?>;
        var msg      = document.getElementById('se_msg');
        var groupSel = document.getElementById('se_group_select');

        if ( groupSel && ! groupSel.disabled ) {
            groupSel.addEventListener('change', function() {
                var url = new URL(window.location.href);
                url.searchParams.set('se_group', this.value);
                window.location.href = url.toString();
            });
        }

        function showMsg(text, ok) {
            msg.textContent = text;
            msg.className = 'se-msg ' + (ok ? 'se-msg-ok' : 'se-msg-err');
            msg.style.display = 'block';
            setTimeout(function(){ msg.style.display = 'none'; }, 4000);
        }

        function updateStatus(status) {
            if (!status) return;
            document.getElementById('se_status_progress').textContent =
                'Rounds entered: ' + status.entered + ' of ' + status.rounds;
            var table = document.getElementById('se_status_table');
            table.innerHTML = '';
            status.totals.forEach(function(t) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td></td><td></td>';
                tr.children[0].textContent = t.name;
                tr.children[1].textContent = t.total;
                table.appendChild(tr);
            });
        }

        document.querySelectorAll('.se-round').forEach(function(roundEl) {
            var loser    = null;
            var teams    = roundEl.querySelectorAll('.se-team');
            var input    = roundEl.querySelector('.se-score-input');
            var saveBtn  = roundEl.querySelector('.se-save-btn');
            var resetBtn = roundEl.querySelector('.se-reset-btn');
            var savedTag = roundEl.querySelector('.se-saved');

            function updateSaveState() {
                saveBtn.disabled = !(loser && input.value !== '' && parseInt(input.value) >= 0);
            }

            teams.forEach(function(teamEl) {
                teamEl.addEventListener('click', function() {
                    teams.forEach(function(t){
                        t.classList.remove('se-loser');
                        t.classList.remove('se-auto-winner');
                    });
                    teamEl.classList.add('se-loser');
                    // Mark the other team as winner
                    teams.forEach(function(t){
                        if (t !== teamEl) t.classList.add('se-auto-winner');
                    });
                    loser = teamEl.dataset.team;
                    updateSaveState();
                });
            });

            input.addEventListener('input', updateSaveState);

            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                saveBtn.textContent = '...';

                // Flip: user selected the loser, AJAX expects the winner
                var winner = (loser === 'blue') ? 'red' : 'blue';

                var data = new FormData();
                data.append('action', 'spp_player_score_entry');
                data.append('nonce', nonce);
                data.append('round', roundEl.dataset.round);
                data.append('winner', winner);
                data.append('loser_score', input.value);
                data.append('group_id', groupId);

                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        saveBtn.textContent = 'Save';
                        saveBtn.disabled = false;
                        if (res.success) {
                            savedTag.style.display = 'inline';
                            showMsg(res.data.message, true);
                            var cur = roundEl.querySelector('.se-current');
                            if (!cur) {
                                cur = document.createElement('div');
                                cur.className = 'se-current';
                                roundEl.appendChild(cur);
                            }
                            cur.textContent = 'Current: Blue ' + res.data.blue + ' -- Red ' + res.data.red;
                            updateStatus(res.data.status);
                        } else {
                            showMsg(res.data || 'Save failed', false);
                        }
                    })
                    .catch(function() {
                        saveBtn.textContent = 'Save';
                        saveBtn.disabled = false;
                        showMsg('Network error -- try again', false);
                    });
            });

            resetBtn.addEventListener('click', function() {
                if (!confirm('Clear scores for this round?')) return;
                resetBtn.disabled = true;
                resetBtn.textContent = '...';

                var data = new FormData();
                data.append('action', 'spp_player_score_entry');
                data.append('nonce', nonce);
                data.append('round', roundEl.dataset.round);
                data.append('winner', 'reset');
                data.append('loser_score', '0');
                data.append('group_id', groupId);

                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        resetBtn.textContent = 'Reset';
                        resetBtn.disabled = false;
                        if (res.success) {
                            savedTag.style.display = 'none';
                            showMsg(res.data.message, true);
                            var cur = roundEl.querySelector('.se-current');
                            if (cur) cur.textContent = '';
                            teams.forEach(function(t){
                                t.classList.remove('se-loser');
                                t.classList.remove('se-auto-winner');
                            });
                            input.value = '';
                            loser = null;
                            saveBtn.disabled = true;
                            updateStatus(res.data.status);
                        } else {
                            showMsg(res.data || 'Reset failed', false);
                        }
                    })
                    .catch(function() {
                        resetBtn.textContent = 'Reset';
                        resetBtn.disabled = false;
                        showMsg('Network error', false);
                    });
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// ── AJAX: save or reset one round's scores ───────────────────────────────────

add_action( 'wp_ajax_spp_player_score_entry', function() {
    global $wpdb;

    if ( ! check_ajax_referer( 'spp_score_entry', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }
    if ( (int) get_option( 'spp_schedule_published', 0 ) !== 1 ) {
        wp_send_json_error( 'Score entry is closed.' );
    }

    $user_id     = get_current_user_id();
    $is_admin    = current_user_can( 'edit_posts' );
    $round       = intval( $_POST['round'] ?? 0 );
    $winner      = sanitize_text_field( $_POST['winner'] ?? '' );
    $loser_score = intval( $_POST['loser_score'] ?? -1 );
    $req_group   = intval( $_POST['group_id'] ?? 0 );

    if ( $round < 1 || $round > 5 || ! in_array( $winner, array( 'blue', 'red', 'reset' ), true ) || $loser_score < 0 ) {
        wp_send_json_error( 'Invalid input.' );
    }

    $me = $wpdb->get_row( $wpdb->prepare(
        "SELECT group_id FROM Schedules WHERE user_id = %d AND group_id != 99",
        $user_id
    ), ARRAY_A );
    $own_group = $me ? (int) $me['group_id'] : 0;

    if ( $is_admin && $req_group > 0 ) {
        $group_id = $req_group;
    } elseif ( $own_group ) {
        $group_id = $own_group;
    } else {
        wp_send_json_error( 'You are not in the schedule.' );
    }

    $players = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id FROM Schedules WHERE group_id = %d ORDER BY Rank",
        $group_id
    ), ARRAY_A );

    if ( empty( $players ) ) {
        wp_send_json_error( 'Group not found.' );
    }

    $count     = count( $players );
    $pairings  = spp_se_pairings( $count );
    $win_score = ( $count >= 5 ) ? 15 : 20;

    if ( $round > count( $pairings ) ) {
        wp_send_json_error( 'Invalid round for this group.' );
    }

    $r        = $pairings[ $round - 1 ];
    $game_col = 'Game' . $round;

    // ── Handle reset ─────────────────────────────────────────────────────────
    if ( $winner === 'reset' ) {
        foreach ( array_merge( $r['blue'], $r['red'] ) as $pos ) {
            if ( ! isset( $players[ $pos ] ) ) continue;
            $uid = (int) $players[ $pos ]['user_id'];
            $wpdb->query( $wpdb->prepare(
                "UPDATE Schedules SET {$game_col} = NULL WHERE user_id = %d",
                $uid
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE Schedules
                 SET Score = COALESCE(IF(Game1>=0,Game1,0),0) + COALESCE(IF(Game2>=0,Game2,0),0)
                           + COALESCE(IF(Game3>=0,Game3,0),0) + COALESCE(IF(Game4>=0,Game4,0),0)
                           + COALESCE(IF(Game5>=0,Game5,0),0)
                 WHERE user_id = %d",
                $uid
            ) );
        }
        wp_send_json_success( array(
            'message' => "Round {$round} cleared.",
            'blue'    => '',
            'red'     => '',
            'status'  => spp_se_group_status( $group_id ),
        ) );
    }

    // ── Handle score entry ───────────────────────────────────────────────────
    if ( $loser_score >= $win_score ) {
        wp_send_json_error( "Loser score must be less than {$win_score}." );
    }

    $blue_score = ( $winner === 'blue' ) ? $win_score : $loser_score;
    $red_score  = ( $winner === 'red' )  ? $win_score : $loser_score;

    $set_score = function( $positions, $score ) use ( $wpdb, $players, $game_col ) {
        foreach ( $positions as $pos ) {
            if ( ! isset( $players[ $pos ] ) ) continue;
            $uid = (int) $players[ $pos ]['user_id'];
            $wpdb->query( $wpdb->prepare(
                "UPDATE Schedules SET {$game_col} = %d WHERE user_id = %d",
                $score, $uid
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE Schedules
                 SET Score = COALESCE(IF(Game1>=0,Game1,0),0) + COALESCE(IF(Game2>=0,Game2,0),0)
                           + COALESCE(IF(Game3>=0,Game3,0),0) + COALESCE(IF(Game4>=0,Game4,0),0)
                           + COALESCE(IF(Game5>=0,Game5,0),0)
                 WHERE user_id = %d",
                $uid
            ) );
        }
    };

    $set_score( $r['blue'], $blue_score );
    $set_score( $r['red'],  $red_score );

    wp_send_json_success( array(
        'message' => "Round {$round} saved: Blue {$blue_score} -- Red {$red_score}.",
        'blue'    => $blue_score,
        'red'     => $red_score,
        'status'  => spp_se_group_status( $group_id ),
    ) );
});