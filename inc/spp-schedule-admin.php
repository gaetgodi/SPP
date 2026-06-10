<?php
/**
 * SPP Schedule Admin
 *
 * Shortcode: [spp_schedule_admin]
 *
 * Allows convenors/admins to manage the live schedule:
 *   - Remove a player (NP or NS) from their group
 *   - If group drops to 3, suggest a replacement from a 5-player group
 *   - Add a walk-in player to a group
 *   - Re-send personalized group emails to affected players + CC convenor
 *
 * Version: 1.1.0
 * Date:    2026-06-10
 *
 * Changes from 1.0.0:
 *   - Track modified groups in spp_modified_groups option (JSON array).
 *   - "Print Replacement Score Sheets" button appears when modified groups exist.
 *   - Replacement score sheets include [REVISED GROUP X] marker for scanner priority.
 *   - Modified groups cleared when a new schedule is run (via spp_schedule_admin_clear_modified).
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_schedule_admin', 'spp_schedule_admin_shortcode' );

function spp_schedule_admin_shortcode() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return '<p class="gl-error">You do not have permission to access this page.</p>';
    }

    global $wpdb;

    $message = '';
    $error   = '';
    $suggest = null; // suggested replacement player

    $occ_id     = (int) get_option( 'spp_current_event', 0 );
    $occ        = $wpdb->get_row( $wpdb->prepare(
        "SELECT o.title, o.event_date, o.event_time, m.user_email AS convenor_email
         FROM {$wpdb->prefix}gl_event_occurrences o
         LEFT JOIN membership m ON m.user_id = o.convenor_id
         WHERE o.id = %d",
        $occ_id
    ), ARRAY_A );

    $convenor_email = ( $occ && $occ['convenor_email'] ) ? $occ['convenor_email'] : 'abrooks@rogers.com';
    $event_date     = $occ ? date( 'F d, Y', strtotime( $occ['event_date'] ) ) : date( 'F d, Y' );

    // ── Handle actions ────────────────────────────────────────────────────────
    if ( isset( $_POST['spp_sa_nonce'] ) && wp_verify_nonce( $_POST['spp_sa_nonce'], 'spp_schedule_admin' ) ) {
        $action = sanitize_text_field( $_POST['spp_sa_action'] ?? '' );
        $modified_groups = array();

        switch ( $action ) {

            // -- Remove player (NP or NS) -------------------------------------
            case 'remove':
                $user_id   = intval( $_POST['spp_user_id'] ?? 0 );
                $penalty   = sanitize_text_field( $_POST['spp_penalty'] ?? 'np' );
                $game1_val = ( $penalty === 'ns' ) ? -2 : -1;

                if ( $user_id ) {
                    // Get player's group before removing
                    $player_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT group_id, Crt_ID, time_id FROM Schedules WHERE user_id = %d AND group_id != 99",
                        $user_id
                    ), ARRAY_A );

                    if ( $player_row ) {
                        $group_id = (int) $player_row['group_id'];

                        // Mark as NP/NS
                        $wpdb->query( $wpdb->prepare(
                            "UPDATE Schedules SET Game1 = %d, Game2 = 0, Game3 = 0, Game4 = 0, Game5 = 0, group_id = 99, Crt_ID = 99, time_id = 99 WHERE user_id = %d",
                            $game1_val, $user_id
                        ) );

                        // Count remaining players in group
                        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM Schedules WHERE group_id = %d",
                            $group_id
                        ) );

                        $pname = $wpdb->get_var( $wpdb->prepare(
                            "SELECT CONCAT(first_name, ' ', last_name) FROM Master WHERE user_id = %d",
                            $user_id
                        ) );
                        $label = ( $penalty === 'ns' ) ? 'NS (no-show)' : 'NP (not playing)';
                        $message = "Player {$pname} marked as {$label} and removed from Group {$group_id}.";

                        // Record modified group
                        $modified_groups[] = $group_id;

                        // Re-send email to remaining group
                        $send_result = spp_sa_send_group_email( $group_id, $convenor_email, $event_date, $occ );
                        if ( $send_result ) $message .= " Group email re-sent.";

                        // If group dropped to 3, suggest a replacement
                        if ( $remaining === 3 ) {
                            $suggest = spp_sa_suggest_replacement( $group_id );
                            if ( $suggest ) {
                                $message .= " Group {$group_id} now has 3 players — a replacement is suggested below.";
                            }
                        }
                    } else {
                        $error = 'Player not found in active schedule.';
                    }
                }
                break;

            // -- Move replacement into group ----------------------------------
            case 'move_replacement':
                $from_user_id = intval( $_POST['spp_from_user_id'] ?? 0 );
                $to_group_id  = intval( $_POST['spp_to_group_id'] ?? 0 );

                if ( $from_user_id && $to_group_id ) {
                    $from_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT group_id, Crt_ID, time_id FROM Schedules WHERE user_id = %d AND group_id != 99",
                        $from_user_id
                    ), ARRAY_A );
                    $to_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1",
                        $to_group_id
                    ), ARRAY_A );

                    if ( $from_row && $to_row ) {
                        $from_group_id = (int) $from_row['group_id'];

                        $wpdb->query( $wpdb->prepare(
                            "UPDATE Schedules SET group_id = %d, Crt_ID = %d, time_id = %d WHERE user_id = %d",
                            $to_group_id, $to_row['Crt_ID'], $to_row['time_id'], $from_user_id
                        ) );

                        $pname = $wpdb->get_var( $wpdb->prepare(
                            "SELECT CONCAT(first_name, ' ', last_name) FROM Master WHERE user_id = %d",
                            $from_user_id
                        ) );
                        $message = "Moved {$pname} from Group {$from_group_id} to Group {$to_group_id}.";

                        // Record both modified groups
                        $modified_groups[] = $to_group_id;
                        $modified_groups[] = $from_group_id;

                        // Re-send emails to both groups
                        spp_sa_send_group_email( $to_group_id,   $convenor_email, $event_date, $occ );
                        spp_sa_send_group_email( $from_group_id, $convenor_email, $event_date, $occ );
                        $message .= " Group emails re-sent to both groups.";
                    } else {
                        $error = 'Player or group not found.';
                    }
                }
                break;

            // -- Add walk-in player -------------------------------------------
            case 'add_walkin':
                $walkin_user_id = intval( $_POST['spp_walkin_user_id'] ?? 0 );
                $walkin_group   = intval( $_POST['spp_walkin_group'] ?? 0 );

                if ( $walkin_user_id && $walkin_group ) {
                    // Check not already in Schedules
                    $already = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM Schedules WHERE user_id = %d AND group_id != 99",
                        $walkin_user_id
                    ) );
                    if ( $already ) {
                        $error = 'This player is already in the schedule.';
                        break;
                    }

                    // Get group info
                    $group_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1",
                        $walkin_group
                    ), ARRAY_A );
                    if ( ! $group_row ) {
                        $error = 'Group not found.';
                        break;
                    }

                    // Assign rank = highest rank number in group (lowest-ranked player)
                    $max_rank_in_group = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT MAX(Rank) FROM Schedules WHERE group_id = %d",
                        $walkin_group
                    ) );
                    $walkin_rank = $max_rank_in_group;

                    // Get player details from membership
                    $member = $wpdb->get_row( $wpdb->prepare(
                        "SELECT first_name, last_name, user_phone, user_email FROM membership WHERE user_id = %d",
                        $walkin_user_id
                    ), ARRAY_A );
                    if ( ! $member ) {
                        $error = 'Player not found in membership table. Please add them via import first.';
                        break;
                    }

                    // Insert into Schedules
                    $wpdb->insert( 'Schedules', array(
                        'user_id'           => $walkin_user_id,
                        'group_id'          => $walkin_group,
                        'Crt_ID'            => $group_row['Crt_ID'],
                        'time_id'           => $group_row['time_id'],
                        'Rank'              => $walkin_rank,
                        'first_name'        => $member['first_name'],
                        'last_name'         => $member['last_name'],
                        'user_phone'        => $member['user_phone'],
                        'user_email'        => $member['user_email'],
                        'event_id'          => $occ_id,
                        'registration_date' => current_time( 'Y-m-d' ),
                        'RankPrime'         => $walkin_rank,
                    ) );

                    // Add to gl_registrations as confirmed
                    $existing_reg = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}gl_registrations WHERE user_id = %d AND occurrence_id = %d",
                        $walkin_user_id, $occ_id
                    ) );
                    if ( ! $existing_reg ) {
                        $now = current_time( 'mysql' );
                        $wpdb->insert( $wpdb->prefix . 'gl_registrations', array(
                            'occurrence_id' => $occ_id,
                            'user_id'       => $walkin_user_id,
                            'status'        => 'confirmed',
                            'registered_at' => $now,
                            'updated_at'    => $now,
                        ) );
                    }

                    $pname = trim( $member['first_name'] . ' ' . $member['last_name'] );
                    $message = "Walk-in {$pname} added to Group {$walkin_group} with rank {$walkin_rank}.";

                    // Record modified group
                    $modified_groups[] = $walkin_group;

                    // Re-send group email
                    spp_sa_send_group_email( $walkin_group, $convenor_email, $event_date, $occ );
                    $message .= " Group email re-sent.";
                }
                break;
        }
    }

    // ── Record modified groups ───────────────────────────────────────────────
    if ( ! empty( $modified_groups ) ) {
        $stored = json_decode( get_option( 'spp_modified_groups', '[]' ), true );
        if ( ! is_array( $stored ) ) $stored = array();
        foreach ( $modified_groups as $mgid ) {
            if ( ! in_array( $mgid, $stored ) ) {
                $stored[] = $mgid;
            }
        }
        update_option( 'spp_modified_groups', json_encode( $stored ) );
    }

    // Handle print replacement sheets action
    if ( isset( $_POST['spp_sa_nonce2'] ) && wp_verify_nonce( $_POST['spp_sa_nonce2'], 'spp_print_sheets' ) ) {
        $action2 = sanitize_text_field( $_POST['spp_sa_action2'] ?? '' );
        if ( $action2 === 'clear_modified' ) {
            delete_option( 'spp_modified_groups' );
            $message = 'Modified groups list cleared.';
        }
        if ( $action2 === 'print_sheets' ) {
            $stored_mods = json_decode( get_option( 'spp_modified_groups', '[]' ), true );
            if ( ! empty( $stored_mods ) ) {
                spp_sa_print_replacement_sheets( $stored_mods, $event_date );
                exit;
            }
        }
    }

    // ── Load current schedule ─────────────────────────────────────────────────────
    $groups_data = $wpdb->get_results(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.group_id,
                s.Crt_ID, s.time_id, g.GP_name, c.Crt_name, t.T_desc
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Courts c ON s.Crt_ID = c.Crt_ID
         JOIN Times t ON s.time_id = t.T_ID
         WHERE s.group_id != 99
         ORDER BY s.group_id, s.Rank",
        ARRAY_A
    );

    // Group players by group_id
    $groups = array();
    foreach ( $groups_data as $p ) {
        $groups[ $p['group_id'] ][] = $p;
    }

    // Load membership for walk-in search
    $all_members = $wpdb->get_results(
        "SELECT user_id, first_name, last_name, user_email
         FROM membership WHERE user_email != ''
         ORDER BY first_name ASC, last_name ASC",
        ARRAY_A
    );

    // Already scheduled user IDs
    $scheduled_ids = array_column( $groups_data, 'user_id' );

    ob_start();
    ?>
    <style>
        .sa-wrap { max-width:900px; margin:20px auto; font-family:Arial,sans-serif; font-size:14px; }
        .sa-wrap h2 { color:#2c3e50; border-bottom:2px solid #3766AB; padding-bottom:8px; }
        .sa-group { background:#fff; border:1px solid #ddd; border-radius:6px; margin-bottom:16px; overflow:hidden; }
        .sa-group-header { background:#2c3e50; color:#fff; padding:8px 14px; font-weight:bold; font-size:15px; display:flex; justify-content:space-between; align-items:center; }
        .sa-group-header span { font-size:12px; font-weight:normal; opacity:0.8; }
        .sa-group table { width:100%; border-collapse:collapse; }
        .sa-group th { background:#3766AB; color:#fff; padding:6px 10px; text-align:left; font-size:13px; }
        .sa-group td { padding:6px 10px; border-bottom:1px solid #eee; }
        .sa-group tr:last-child td { border-bottom:none; }
        .sa-group tr:nth-child(even) td { background:#f9f9f9; }
        .sa-btn { padding:4px 10px; border:none; border-radius:3px; cursor:pointer; font-size:12px; }
        .sa-btn-np  { background:#e67e22; color:#fff; }
        .sa-btn-ns  { background:#c0392b; color:#fff; }
        .sa-btn-np:hover { background:#ca6f1e; }
        .sa-btn-ns:hover { background:#a93226; }
        .sa-notice { padding:10px 16px; border-radius:4px; margin:12px 0; font-size:14px; }
        .sa-notice-success { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .sa-notice-error   { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .sa-notice-warning { background:#fff3cd; border:1px solid #ffc107; color:#856404; }
        .sa-suggest { background:#f0f7ff; border:1px solid #3766AB; border-radius:6px; padding:16px; margin:16px 0; }
        .sa-suggest h4 { color:#3766AB; margin:0 0 10px; }
        .sa-walkin { background:#f9f9f9; border:1px solid #ddd; border-radius:6px; padding:16px; margin:20px 0; }
        .sa-walkin h3 { color:#2c3e50; margin:0 0 12px; }
        .sa-field { margin:10px 0; }
        .sa-field label { display:block; font-weight:bold; margin-bottom:4px; font-size:13px; }
        .sa-field input[type=text] { padding:6px 10px; border:1px solid #ddd; border-radius:4px; width:280px; }
        .sa-field select { padding:6px 10px; border:1px solid #ddd; border-radius:4px; width:300px; }
        .sa-submit { padding:8px 20px; background:#3766AB; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:14px; margin-top:8px; }
        .sa-submit:hover { background:#2d5491; }
        .sa-group-count-3 .sa-group-header { background:#c0392b; }
        .sa-group-count-4 .sa-group-header { background:#2c3e50; }
        .sa-group-count-5 .sa-group-header { background:#27ae60; }
    </style>

    <div class="sa-wrap">
        <h2>Schedule Admin &mdash; <?php echo esc_html( $event_date ); ?></h2>

        <?php if ( $message ) : ?>
            <div class="sa-notice sa-notice-success"><?php echo esc_html( $message ); ?></div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="sa-notice sa-notice-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <?php
        // ── Modified groups banner ────────────────────────────────────────
        $stored_modified = json_decode( get_option( 'spp_modified_groups', '[]' ), true );
        if ( ! is_array( $stored_modified ) ) $stored_modified = array();
        if ( ! empty( $stored_modified ) ) :
            sort( $stored_modified );
        ?>
            <div class="sa-notice sa-notice-warning">
                <strong>Modified groups this session:</strong>
                Group<?php echo count($stored_modified) > 1 ? 's' : ''; ?>
                <?php echo esc_html( implode( ', ', $stored_modified ) ); ?>
                — replacement score sheets should be printed for these groups.
            </div>
            <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;">
                <form method="post" target="_blank">
                    <?php wp_nonce_field( 'spp_print_sheets', 'spp_sa_nonce2' ); ?>
                    <input type="hidden" name="spp_sa_action2" value="print_sheets">
                    <button type="submit" class="sa-submit" style="background:#27ae60;">
                        Print Replacement Score Sheets
                    </button>
                </form>
                <form method="post">
                    <?php wp_nonce_field( 'spp_print_sheets', 'spp_sa_nonce2' ); ?>
                    <input type="hidden" name="spp_sa_action2" value="clear_modified">
                    <button type="submit" class="sa-submit" style="background:#888;"
                            onclick="return confirm('Clear the modified groups list?')">
                        Clear List
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php // ── Suggested replacement ─────────────────────────────────── ?>
        <?php if ( $suggest ) : ?>
            <div class="sa-suggest">
                <h4>Suggested Replacement</h4>
                <p>
                    <strong><?php echo esc_html( $suggest['first_name'] . ' ' . $suggest['last_name'] ); ?></strong>
                    (Rank <?php echo esc_html( $suggest['Rank'] ); ?>,
                    currently in Group <?php echo esc_html( $suggest['group_id'] ); ?>)
                    is the closest-ranked available player from a 5-player group.
                </p>
                <form method="post">
                    <?php wp_nonce_field( 'spp_schedule_admin', 'spp_sa_nonce' ); ?>
                    <input type="hidden" name="spp_sa_action"    value="move_replacement">
                    <input type="hidden" name="spp_from_user_id" value="<?php echo esc_attr( $suggest['user_id'] ); ?>">
                    <input type="hidden" name="spp_to_group_id"  value="<?php echo esc_attr( $suggest['to_group'] ); ?>">
                    <button type="submit" class="sa-submit">
                        Move <?php echo esc_html( $suggest['first_name'] ); ?> to Group <?php echo esc_html( $suggest['to_group'] ); ?>
                    </button>
                    <a href="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" style="margin-left:12px;color:#888;font-size:13px;">Skip — I'll handle manually</a>
                </form>
            </div>
        <?php endif; ?>

        <?php // ── Group list ────────────────────────────────────────────── ?>
        <?php foreach ( $groups as $group_id => $players ) :
            $count     = count( $players );
            $p0        = $players[0];
            $css_count = 'sa-group-count-' . min( $count, 5 );
        ?>
            <div class="sa-group <?php echo esc_attr( $css_count ); ?>">
                <div class="sa-group-header">
                    <span><?php echo esc_html( $p0['GP_name'] ); ?> &mdash; <?php echo esc_html( $p0['Crt_name'] ); ?> &mdash; <?php echo esc_html( $p0['T_desc'] ); ?></span>
                    <span><?php echo $count; ?> player<?php echo $count !== 1 ? 's' : ''; ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $players as $p ) : ?>
                            <tr>
                                <td><?php echo esc_html( $p['Rank'] ); ?></td>
                                <td><?php echo esc_html( $p['first_name'] . ' ' . $p['last_name'] ); ?></td>
                                <td><?php echo esc_html( $p['user_phone'] ?? '' ); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'spp_schedule_admin', 'spp_sa_nonce' ); ?>
                                        <input type="hidden" name="spp_sa_action" value="remove">
                                        <input type="hidden" name="spp_user_id"   value="<?php echo esc_attr( $p['user_id'] ); ?>">
                                        <input type="hidden" name="spp_penalty"   value="np">
                                        <button type="submit" class="sa-btn sa-btn-np"
                                                onclick="return confirm('Mark <?php echo esc_js( $p['first_name'] ); ?> as NP (not playing)?')">
                                            NP
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline;margin-left:4px;">
                                        <?php wp_nonce_field( 'spp_schedule_admin', 'spp_sa_nonce' ); ?>
                                        <input type="hidden" name="spp_sa_action" value="remove">
                                        <input type="hidden" name="spp_user_id"   value="<?php echo esc_attr( $p['user_id'] ); ?>">
                                        <input type="hidden" name="spp_penalty"   value="ns">
                                        <button type="submit" class="sa-btn sa-btn-ns"
                                                onclick="return confirm('Mark <?php echo esc_js( $p['first_name'] ); ?> as NS (no-show)?')">
                                            NS
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <?php // ── Add walk-in player ────────────────────────────────────── ?>
        <div class="sa-walkin">
            <h3>Add Walk-in Player</h3>
            <form method="post">
                <?php wp_nonce_field( 'spp_schedule_admin', 'spp_sa_nonce' ); ?>
                <input type="hidden" name="spp_sa_action" value="add_walkin">

                <div class="sa-field">
                    <label>Search Member</label>
                    <input type="text" id="sa_walkin_search" placeholder="Type name to search..."
                           autocomplete="off">
                    <select name="spp_walkin_user_id" id="sa_walkin_user_id" required size="5"
                            style="display:block;margin-top:6px;width:340px;border:1px solid #ddd;border-radius:4px;padding:4px;">
                        <option value="">— Select member —</option>
                        <?php foreach ( $all_members as $m ) :
                            if ( in_array( $m['user_id'], $scheduled_ids ) ) continue;
                            $full = trim( $m['first_name'] . ' ' . $m['last_name'] );
                        ?>
                            <option value="<?php echo esc_attr( $m['user_id'] ); ?>"
                                    data-name="<?php echo esc_attr( strtolower( $full ) ); ?>">
                                <?php echo esc_html( $full ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label>Assign to Group</label>
                    <select name="spp_walkin_group" required>
                        <option value="">— Select group —</option>
                        <?php foreach ( $groups as $gid => $gplayers ) :
                            $p0 = $gplayers[0];
                        ?>
                            <option value="<?php echo esc_attr( $gid ); ?>">
                                <?php echo esc_html( $p0['GP_name'] . ' — ' . $p0['Crt_name'] . ' — ' . $p0['T_desc'] . ' (' . count($gplayers) . ' players)' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="sa-submit">Add Walk-in &amp; Send Group Email</button>
            </form>
        </div>

    </div><!-- .sa-wrap -->

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('sa_walkin_search');
        var select      = document.getElementById('sa_walkin_user_id');
        if ( searchInput && select ) {
            var allOptions = Array.from(select.options);
            searchInput.addEventListener('input', function() {
                var term = this.value.toLowerCase().trim();
                while (select.options.length > 1) select.remove(1);
                allOptions.forEach(function(opt) {
                    if (opt.value === '') return;
                    if (!term || opt.dataset.name.indexOf(term) !== -1) {
                        select.appendChild(opt.cloneNode(true));
                    }
                });
                if (select.options.length === 2) select.selectedIndex = 1;
            });
        }
    });
    </script>

    <?php
    return ob_get_clean();
}

// ── Helper: suggest replacement from 5-player group ──────────────────────────

function spp_sa_suggest_replacement( int $group_id ) {
    global $wpdb;

    // Get average rank of the 3-player group
    $group_avg = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(Rank) FROM Schedules WHERE group_id = %d",
        $group_id
    ) );

    // Find all 5-player groups
    $five_player_groups = $wpdb->get_col(
        "SELECT group_id FROM Schedules WHERE group_id != 99
         GROUP BY group_id HAVING COUNT(*) = 5"
    );

    if ( empty( $five_player_groups ) ) return null;

    // From each 5-player group, find the player closest in rank to group_avg
    $best_candidate = null;
    $best_diff      = PHP_INT_MAX;

    foreach ( $five_player_groups as $fgid ) {
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id, s.Rank, s.group_id, m.first_name, m.last_name
             FROM Schedules s
             JOIN Master m ON s.user_id = m.user_id
             WHERE s.group_id = %d
             ORDER BY ABS(s.Rank - %f) ASC
             LIMIT 1",
            $fgid, $group_avg
        ), ARRAY_A );

        if ( empty( $players ) ) continue;
        $p    = $players[0];
        $diff = abs( (float)$p['Rank'] - $group_avg );
        if ( $diff < $best_diff ) {
            $best_diff      = $diff;
            $best_candidate = $p;
            $best_candidate['to_group'] = $group_id;
        }
    }

    return $best_candidate;
}

// ── Helper: send updated group email ─────────────────────────────────────────

function spp_sa_send_group_email( int $group_id, string $convenor_email, string $event_date, $occ ) {
    global $wpdb;

    $players = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.user_phone, s.user_email,
                g.GP_name, c.Crt_name, t.T_desc
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Courts c ON s.Crt_ID = c.Crt_ID
         JOIN Times t  ON s.time_id = t.T_ID
         WHERE s.group_id = %d
         ORDER BY s.Rank",
        $group_id
    ), ARRAY_A );

    if ( empty( $players ) ) return false;

    $pairings_5 = array(
        array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>4),
        array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,4),'bye'=>3),
        array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(2,4),'bye'=>1),
        array('name'=>'Round 4','blue'=>array(0,4),'red'=>array(1,3),'bye'=>2),
        array('name'=>'Round 5','blue'=>array(1,2),'red'=>array(3,4),'bye'=>0),
    );
    $pairings_4 = array(
        array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>-1),
        array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,3),'bye'=>-1),
        array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(1,2),'bye'=>-1),
    );
    $pairings_3 = array(
        array('name'=>'Round 1','blue'=>array(0),'red'=>array(1),'bye'=>2),
        array('name'=>'Round 2','blue'=>array(0),'red'=>array(2),'bye'=>1),
        array('name'=>'Round 3','blue'=>array(1),'red'=>array(2),'bye'=>0),
    );

    $pairings = count($players) >= 5 ? $pairings_5 : ( count($players) >= 4 ? $pairings_4 : $pairings_3 );

    $logo_url     = 'https://pickleballstouffville.ca/wp-content/uploads/2024/03/SPP_Logo-96DPI-8X-3.52-480x211.png';
    $schedule_url = 'https://pickleballstouffville.ca/gl-player-schedule-view/';
    $subject      = "UPDATED - Stouffville Pickleball Players -- Ladder Schedule for {$event_date}";
    $headers      = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
        'Cc: ' . $convenor_email,
    );

    $p0      = $players[0];
    $sent    = 0;

    foreach ( $players as $member ) {
        $uid   = (int) $member['user_id'];
        $email = $member['user_email'];
        if ( ! $email ) continue;

        // Build group table HTML
        $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f5;">
<table width="620" cellpadding="0" cellspacing="0" style="margin:20px auto;background:#fdfae8;border:1px solid #ddd;font-family:Arial,sans-serif;font-size:14px;color:#333;">
  <tr><td style="padding:16px 24px;border-bottom:1px solid #ddd;">
    <table width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="160"><img src="' . $logo_url . '" width="160" alt="SPP Logo"></td>
      <td style="text-align:center;padding-left:10px;">
        <strong style="font-size:16px;">Stouffville Pickleball Players</strong><br>
        <span style="font-size:13px;color:#666;">Pickleball for everyone in the Stouffville area</span>
      </td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:16px 24px 8px 24px;">
    <p style="margin:0 0 8px 0;font-size:15px;font-weight:bold;color:#c0392b;">UPDATED Schedule — Your group has changed!</p>
    <div style="background:#2c3e50;color:#fff;padding:8px 12px;border-radius:6px 6px 0 0;font-weight:bold;font-size:15px;">
      ' . esc_html($p0['GP_name']) . ' &mdash; ' . esc_html($p0['Crt_name']) . ' &mdash; ' . esc_html($p0['T_desc']) . '
    </div>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;margin-bottom:12px;">
      <thead><tr style="background:#3766AB;">
        <td style="color:#fff;padding:5px 8px;font-weight:bold;width:40px;">Rank</td>
        <td style="color:#fff;padding:5px 8px;font-weight:bold;">Name</td>
        <td style="color:#fff;padding:5px 8px;font-weight:bold;">Phone</td>
      </tr></thead><tbody>';

        foreach ( $players as $i => $p ) {
            $is_me  = ( (int)$p['user_id'] === $uid );
            $me_tag = $is_me ? ' (me)' : '';
            $bg     = $is_me ? 'background:#e8f5e9;font-weight:bold;' : ( $i % 2 == 0 ? 'background:#f9f9f9;' : 'background:#fff;' );
            $html  .= '<tr style="' . $bg . '">
              <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['Rank']) . '</td>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['first_name'] . ' ' . $p['last_name']) . $me_tag . '</td>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['user_phone'] ?? '') . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        // Pairings table
        $my_pos = -1;
        foreach ( $players as $i => $p ) {
            if ( (int)$p['user_id'] === $uid ) { $my_pos = $i; break; }
        }

        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;">
          <thead><tr style="background:#3766AB;">
            <td style="color:#fff;padding:5px 8px;font-weight:bold;width:70px;">Round</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;background:#5b8dd9;">Blue Team</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;background:#c0607a;">Red Team</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;">Bye</td>
          </tr></thead><tbody>';

        foreach ( $pairings as $round ) {
            $blue_names = array_map( function($i) use ($players) { return isset($players[$i]) ? $players[$i]['first_name'] : ''; }, $round['blue'] );
            $red_names  = array_map( function($i) use ($players) { return isset($players[$i]) ? $players[$i]['first_name'] : ''; }, $round['red'] );
            $bye_name   = ( $round['bye'] >= 0 && isset($players[$round['bye']]) ) ? $players[$round['bye']]['first_name'] : '-';
            $i_am_blue  = in_array( $my_pos, $round['blue'] );
            $i_am_red   = in_array( $my_pos, $round['red'] );
            $i_have_bye = ( $round['bye'] === $my_pos );
            $blue_bg = $i_am_blue  ? 'background:#aed8e6;font-weight:bold;' : 'background:#daeef5;';
            $red_bg  = $i_am_red   ? 'background:#f4a7b5;font-weight:bold;' : 'background:#fce4ec;';
            $bye_bg  = $i_have_bye ? 'background:#c5e1a5;font-weight:bold;' : 'background:#f5f5f5;';
            $html .= '<tr>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($round['name']) . '</td>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $blue_bg . '">' . esc_html(implode(' / ', $blue_names)) . '</td>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $red_bg . '">' . esc_html(implode(' / ', $red_names)) . '</td>
              <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $bye_bg . '">' . esc_html($bye_name) . '</td>
            </tr>';
        }

        $html .= '</tbody></table></td></tr>
  <tr><td style="padding:16px 24px;border-top:1px solid #ddd;font-size:12px;color:#666;text-align:center;">
    We are on tonight unless you hear otherwise by 4pm!<br>
    <a href="' . $schedule_url . '" style="color:#3766AB;">View full schedule on the website</a>
  </td></tr>
</table></body></html>';

        wp_mail( $email, $subject, $html, $headers );
        $sent++;
    }

    return $sent > 0;
}

// ── Helper: print replacement score sheets ───────────────────────────────────

function spp_sa_print_replacement_sheets( array $group_ids, string $event_date ) {
    global $wpdb;

    $pairings_5 = array(
        array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>4),
        array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,4),'bye'=>3),
        array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(2,4),'bye'=>1),
        array('name'=>'Round 4','blue'=>array(0,4),'red'=>array(1,3),'bye'=>2),
        array('name'=>'Round 5','blue'=>array(1,2),'red'=>array(3,4),'bye'=>0),
    );
    $pairings_4 = array(
        array('name'=>'Round 1','blue'=>array(0,1),'red'=>array(2,3),'bye'=>-1),
        array('name'=>'Round 2','blue'=>array(0,2),'red'=>array(1,3),'bye'=>-1),
        array('name'=>'Round 3','blue'=>array(0,3),'red'=>array(1,2),'bye'=>-1),
    );
    $pairings_3 = array(
        array('name'=>'Round 1','blue'=>array(0),'red'=>array(1),'bye'=>2),
        array('name'=>'Round 2','blue'=>array(0),'red'=>array(2),'bye'=>1),
        array('name'=>'Round 3','blue'=>array(1),'red'=>array(2),'bye'=>0),
    );

    echo '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Replacement Score Sheets — ' . esc_html($event_date) . '</title>
<style>
    body { font-family: Arial, sans-serif; font-size: 13px; margin: 0; padding: 0; }
    .sheet { page-break-after: always; padding: 20px; max-width: 700px; margin: 0 auto; }
    .sheet:last-child { page-break-after: avoid; }
    .revised-banner {
        background: #c0392b; color: #fff; font-size: 18px; font-weight: bold;
        padding: 10px 16px; border-radius: 4px; margin-bottom: 12px;
        text-align: center; letter-spacing: 1px;
    }
    .group-header {
        background: #2c3e50; color: #fff; padding: 8px 14px;
        font-weight: bold; font-size: 15px; border-radius: 4px 4px 0 0;
    }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th { background: #3766AB; color: #fff; padding: 6px 10px; text-align: left; }
    td { padding: 6px 10px; border-bottom: 1px solid #ddd; }
    tr:nth-child(even) td { background: #f9f9f9; }
    .blue-header { background: #5b8dd9; color: #fff; }
    .red-header  { background: #c0607a; color: #fff; }
    .score-col { width: 60px; border: 1px solid #999; background: #fff; }
    .print-btn { margin: 20px; padding: 10px 24px; background: #3766AB; color: #fff;
                 border: none; border-radius: 4px; font-size: 15px; cursor: pointer; }
    @media print { .print-btn { display: none; } }
</style>
</head>
<body>';

    echo '<div style="text-align:center;margin:16px 0;">
        <button class="print-btn" onclick="window.print()">Print All Sheets</button>
    </div>';

    foreach ( $group_ids as $group_id ) {
        $group_id = (int) $group_id;

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.user_phone,
                    g.GP_name, c.Crt_name, t.T_desc
             FROM Schedules s
             JOIN Groups g ON s.group_id = g.GP_ID
             JOIN Courts c ON s.Crt_ID = c.Crt_ID
             JOIN Times t  ON s.time_id = t.T_ID
             WHERE s.group_id = %d
             ORDER BY s.Rank",
            $group_id
        ), ARRAY_A );

        if ( empty( $players ) ) continue;

        $count    = count( $players );
        $p0       = $players[0];
        $pairings = $count >= 5 ? $pairings_5 : ( $count >= 4 ? $pairings_4 : $pairings_3 );

        echo '<div class="sheet">';
        echo '<div class="revised-banner">[REVISED GROUP ' . $group_id . '] — ' . esc_html($event_date) . '</div>';
        echo '<div class="group-header">' . esc_html($p0['GP_name']) . ' &mdash; ' . esc_html($p0['Crt_name']) . ' &mdash; ' . esc_html($p0['T_desc']) . '</div>';

        // Player roster
        echo '<table>';
        echo '<thead><tr><th>Rank</th><th>Name</th><th>Phone</th></tr></thead><tbody>';
        foreach ( $players as $p ) {
            echo '<tr><td>' . esc_html($p['Rank']) . '</td><td>' . esc_html($p['first_name'] . ' ' . $p['last_name']) . '</td><td>' . esc_html($p['user_phone'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';

        // Pairings + score entry table
        echo '<table>';
        echo '<thead><tr>
            <th>Round</th>
            <th class="blue-header">Blue Team</th><th class="blue-header">Score</th>
            <th class="red-header">Red Team</th><th class="red-header">Score</th>
            <th>Bye</th>
        </tr></thead><tbody>';

        foreach ( $pairings as $round ) {
            $blue_names = array_map( function($i) use ($players) { return isset($players[$i]) ? $players[$i]['first_name'] : ''; }, $round['blue'] );
            $red_names  = array_map( function($i) use ($players) { return isset($players[$i]) ? $players[$i]['first_name'] : ''; }, $round['red'] );
            $bye_name   = ( $round['bye'] >= 0 && isset($players[$round['bye']]) ) ? $players[$round['bye']]['first_name'] : '-';
            echo '<tr>
                <td><strong>' . esc_html($round['name']) . '</strong></td>
                <td style="background:#daeef5;">' . esc_html(implode(' / ', $blue_names)) . '</td>
                <td class="score-col">&nbsp;</td>
                <td style="background:#fce4ec;">' . esc_html(implode(' / ', $red_names)) . '</td>
                <td class="score-col">&nbsp;</td>
                <td>' . esc_html($bye_name) . '</td>
            </tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // .sheet
    }

    echo '</body></html>';
}
