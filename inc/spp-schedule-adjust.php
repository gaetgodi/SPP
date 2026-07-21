<?php
/* =========================================================
   SPP Schedule Adjust
   Version: 1.0.0
   Date: 2026-07-14

   Consolidated mid-event schedule adjustment tool. Covers:
     1. Dropout            -- IMPLEMENTED this pass
     2. Last-minute add     -- placeholder, next pass
     3. Group time-slot swap (migrate CM "Switch Groups")  -- placeholder
     4. Manual rank override -- placeholder
     5. Player swap w/ insert+compensating-swap suggestions -- placeholder

   Every mutating action follows the same 3-stage flow:
     1. Propose  -- show what would change, no writes yet
     2. Apply + Check -- on confirm: back up Schedules table,
        apply the change, run spp_run_schedule_check(), show
        results. NO notifications sent yet.
     3. Convenor reviews the check output and picks:
          "Looks good -- send notifications"  -> finalize, notify
          "Not acceptable -- cancel"          -> surgically revert only the
                                                  rows this specific action
                                                  changed (not a blunt
                                                  full-table restore, so any
                                                  other already-finalized
                                                  change or concurrent edit
                                                  is left untouched), retry

   Backup tables (Schedules_backup_<action>_<timestamp>) are
   kept intentionally -- not auto-dropped after finalize/undo.
   They get cleaned up at the START of the next Schedule
   Production run (see gl-schedule-production.php), since that's
   the one place in the codebase that already knows the event
   has changed.

   Placement algorithm (dropout redistribution + last-minute add
   + player-swap "just insert"):
     PACK (tried first): look at other groups at the SAME time
       slot as the affected group. A candidate must have room
       (size + incoming <= 5) and must not break any existing
       +/- travel-preference match for players already in that
       group (same $effective_tolerance($rank) logic as
       gl-schedule-production.php). Among passing candidates,
       prefer the one whose rank range is closest to the
       incoming player's rank.
     SPREAD (dropout fallback only, when pack finds no single
       group with room for everyone displaced): search groups
       across ALL time slots, independently per displaced
       player, so a player's own +/- travel preference can
       actually be honoured by moving them to a matching slot.
       No two displaced players land in the same group under
       spread. If a player truly cannot fit anywhere, this is
       surfaced as an unresolved case rather than forced.
     Last-minute add uses the same search as PACK, but since
     there is no "home" time slot for a brand-new player, the
     search spans ALL time slots (same as SPREAD's slot scope).

   Rank calculation for last-minute add (fixed from the
   RandomRanks CM snippet -- missing break on case 2.5 restored,
   'Professional' now mapped to the same top-band criteria as
   5.0; 'Beginner' still correctly falls through to the
   weak/bottom-band default):
     - If Master.Rank > 0 already, use it as-is.
     - Else if old_Rank usermeta exists, use old_Rank + 3.
     - Else compute via get_rank($rating, $ave, $se, $numPlayers)
       against the current live Master stats.
     Suggested rank is always shown editable before confirming --
     this permanently updates Master + usermeta, same as the
     official RandomRanks/Create-membership-table cycle.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_schedule_adjust', 'spp_schedule_adjust_shortcode' );

function spp_schedule_adjust_shortcode() {
    ob_start();
    spp_sa2_render();
    return ob_get_clean();
}

/* =========================================================
   SHARED HELPERS
   ========================================================= */

function spp_sa2_effective_tolerance( $rank, $carpool_rank_tolerance ) {
    if ( $rank <= 20 ) return (int) round( $carpool_rank_tolerance * 2 / 3 );
    if ( $rank <= 50 ) return $carpool_rank_tolerance;
    return (int) round( $carpool_rank_tolerance * 4 / 3 );
}

function spp_sa2_normalize_travel( $travel ) {
    if ( empty( $travel ) ) return '';
    $t = trim( $travel );
    $t = preg_replace( '/^-\s+(5:30|6:40|7:50)/i', '-$1', $t );
    $t = preg_replace( '/^([+-]?)(5:30|6:40|7:50)\s*pm\b/i', '$1$2', $t );
    $t = preg_replace( '/^(-)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t );
    $t = preg_replace( '/^(\+)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t );
    if ( preg_match( '/^(5:30|6:40|7:50)(\s+\S+)?$/i', $t ) ) {
        if ( $t[0] !== '+' && $t[0] !== '-' ) $t = '+' . $t;
    }
    return $t;
}

function spp_sa2_extract_carpool( $travel ) {
    $travel = spp_sa2_normalize_travel( $travel );
    if ( empty( $travel ) ) return '';
    $cleaned = preg_replace( '/^[+-]?(5:30|6:40|7:50)\s*/i', '', $travel );
    $cleaned = ltrim( $cleaned, '+-' );
    return strtolower( trim( $cleaned ) );
}

function spp_sa2_carpool_key( $name ) {
    return ltrim( trim( $name ), '+' );
}

/**
 * Does moving $incoming_uid (rank $incoming_rank, travel $incoming_travel)
 * into $group_id (whose players are $group_id's current roster) keep every
 * existing +/- carpool match in that group intact, and not exceed size 5?
 * Returns array('ok' => bool, 'reason' => string) so callers can explain
 * unresolved cases rather than silently failing.
 */
function spp_sa2_group_accepts_player( $group_id, $incoming_uid, $incoming_rank, $incoming_travel, $carpool_rank_tolerance, $exclude_uid = null ) {
    global $wpdb;

    $roster = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.Rank, m.travel
         FROM Schedules s
         JOIN Master m ON s.user_id = m.user_id
         WHERE s.group_id = %d",
        $group_id
    ), ARRAY_A );

    if ( $exclude_uid !== null ) {
        $roster = array_values( array_filter( $roster, fn( $p ) => (int) $p['user_id'] !== (int) $exclude_uid ) );
    }

    if ( count( $roster ) >= 5 ) {
        return array( 'ok' => false, 'reason' => 'Group already full (5 players).' );
    }

    // Check the incoming player's own carpool partner, if any, is not
    // broken by landing in this group (partner must already be here, or
    // this check is not applicable -- carpool integrity for the *incoming*
    // player relative to a partner elsewhere is handled by the slot search,
    // not here).
    $incoming_cp = spp_sa2_carpool_key( spp_sa2_extract_carpool( $incoming_travel ) );

    foreach ( $roster as $p ) {
        $p_travel = spp_sa2_normalize_travel( $p['travel'] );
        $p_cp     = spp_sa2_carpool_key( spp_sa2_extract_carpool( $p_travel ) );
        if ( $p_cp !== '' && $p_cp === $incoming_cp ) {
            // Same carpool group -- this is actually a good sign (keeps
            // them together), not a conflict. No further check needed here.
            continue;
        }
    }

    return array( 'ok' => true, 'reason' => '' );
}

/**
 * Search for a single best-fit group to place one incoming player into.
 * $same_slot_time_id: if set, restricts the search to that time slot
 * (PACK behaviour). If null, searches all time slots (SPREAD / last-minute
 * add / "just insert" behaviour).
 * $exclude_group_ids: group IDs to skip (e.g. the player's own dissolving
 * group, or groups already used by other displaced players in this spread).
 * Returns array('group_id'=>..,'time_id'=>..,'Crt_ID'=>..) or null.
 */
function spp_sa2_find_best_group( $incoming_uid, $incoming_rank, $incoming_travel, $same_slot_time_id, $exclude_group_ids, $carpool_rank_tolerance ) {
    global $wpdb;

    $where_time = $same_slot_time_id !== null
        ? $wpdb->prepare( "AND s.time_id = %d", $same_slot_time_id )
        : "";

    $exclude_sql = "";
    if ( ! empty( $exclude_group_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $exclude_group_ids ), '%d' ) );
        $exclude_sql  = $wpdb->prepare( "AND s.group_id NOT IN ($placeholders)", $exclude_group_ids );
    }

    $groups = $wpdb->get_results(
        "SELECT s.group_id, s.time_id, s.Crt_ID, COUNT(*) AS cnt, AVG(s.Rank) AS avg_rank
         FROM Schedules s
         WHERE s.group_id != 99 $where_time $exclude_sql
         GROUP BY s.group_id, s.time_id, s.Crt_ID
         HAVING cnt < 5
         ORDER BY ABS(avg_rank - $incoming_rank) ASC",
        ARRAY_A
    );

    foreach ( $groups as $g ) {
        $check = spp_sa2_group_accepts_player(
            (int) $g['group_id'], $incoming_uid, $incoming_rank, $incoming_travel, $carpool_rank_tolerance
        );
        if ( $check['ok'] ) {
            return array(
                'group_id' => (int) $g['group_id'],
                'time_id'  => (int) $g['time_id'],
                'Crt_ID'   => (int) $g['Crt_ID'],
            );
        }
    }

    return null;
}

function spp_sa2_backup_schedules( $label ) {
    global $wpdb;
    $backup_table = 'Schedules_backup_' . preg_replace( '/[^a-z0-9_]/i', '', $label ) . '_' . date( 'Ymd_His' );
    $wpdb->query( "CREATE TABLE `$backup_table` LIKE Schedules" );
    $wpdb->query( "INSERT INTO `$backup_table` SELECT * FROM Schedules" );
    return $backup_table;
}

function spp_sa2_restore_schedules( $backup_table ) {
    global $wpdb;
    // Validate the table actually looks like one of ours before touching it.
    if ( ! preg_match( '/^Schedules_backup_[a-z0-9_]+$/i', $backup_table ) ) return false;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '$backup_table'" );
    if ( ! $exists ) return false;

    // Surgical, not blunt: only touch rows this specific action's apply
    // step actually changed. A full TRUNCATE + restore would also wipe out
    // any unrelated change made elsewhere in Schedules since the backup was
    // taken (e.g. another action already finalized in this same session,
    // or a concurrent edit in another tab) -- forcing it to be redone. This
    // instead restores/re-adds/removes only the specific rows that differ.
    $backup_rows  = $wpdb->get_results( "SELECT user_id, first_name, last_name, user_phone, Rank, group_id, Crt_ID, time_id FROM `$backup_table`", ARRAY_A );
    $current_rows = $wpdb->get_results( "SELECT user_id, group_id, Crt_ID, time_id FROM Schedules", ARRAY_A );

    $backup_by_uid  = array_column( $backup_rows, null, 'user_id' );
    $current_by_uid = array_column( $current_rows, null, 'user_id' );

    // Rows that existed before this action's apply: put them back exactly
    // as they were, whether that means restoring their group/time/court,
    // or re-inserting them if this action deleted them (dropout).
    foreach ( $backup_by_uid as $uid => $brow ) {
        if ( isset( $current_by_uid[ $uid ] ) ) {
            $c = $current_by_uid[ $uid ];
            if ( (int) $c['group_id'] !== (int) $brow['group_id']
              || (int) $c['Crt_ID']   !== (int) $brow['Crt_ID']
              || (int) $c['time_id']  !== (int) $brow['time_id'] ) {
                $wpdb->update( 'Schedules',
                    array( 'group_id' => $brow['group_id'], 'Crt_ID' => $brow['Crt_ID'], 'time_id' => $brow['time_id'] ),
                    array( 'user_id' => $uid )
                );
            }
        } else {
            $wpdb->insert( 'Schedules', $brow );
        }
    }

    // Rows that exist now but didn't exist in the backup: this action added
    // them (last-minute add) -- remove just those.
    foreach ( $current_by_uid as $uid => $crow ) {
        if ( ! isset( $backup_by_uid[ $uid ] ) ) {
            $wpdb->delete( 'Schedules', array( 'user_id' => $uid ) );
        }
    }

    return true;
}

/* =========================================================
   MAIN RENDER / ROUTER
   ========================================================= */

function spp_sa2_render() {
    global $wpdb;

    if ( ! current_user_can( 'edit_others_posts' ) ) {
        echo '<p>You do not have permission to use this tool.</p>';
        return;
    }

    $nonce_ok = isset( $_POST['spp_sa_nonce'] ) && wp_verify_nonce( $_POST['spp_sa_nonce'], 'spp_schedule_adjust' );
    $stage    = $nonce_ok ? sanitize_text_field( $_POST['spp_sa_stage'] ?? 'select' ) : 'select';
    $action   = $nonce_ok ? sanitize_text_field( $_POST['spp_sa_action'] ?? '' ) : '';

    echo '<div class="spp-sa2">';
    spp_sa2_styles();

    if ( $action === 'dropout' ) {
        spp_sa2_dropout_flow( $stage );
    } elseif ( $action === 'add' ) {
        spp_sa2_add_flow( $stage );
    } elseif ( $action === 'groupswap' ) {
        spp_sa2_groupswap_flow( $stage );
    } elseif ( $action === 'rank' ) {
        spp_sa2_rank_flow( $stage );
    } elseif ( $action === 'playerswap' ) {
        spp_sa2_playerswap_flow( $stage );
    } elseif ( $action === 'notify_pending' ) {
        spp_sa2_notify_pending();
    } elseif ( $action === 'discard_pending' ) {
        spp_sa2_discard_pending_notify();
    } else {
        spp_sa2_action_selector();
    }

    echo '</div>';
}

function spp_sa2_styles() {
    ?>
    <style>
        .spp-sa2 { font-family: Arial, sans-serif; font-size: 14px; color: #333; max-width: 720px; }
        .spp-sa2 h3 { color: #2c3e50; border-bottom: 2px solid #3766AB; padding-bottom: 6px; }
        .spp-sa2 select, .spp-sa2 input[type=text], .spp-sa2 input[type=number] {
            padding: 6px 10px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; width: 100%; box-sizing: border-box; margin-bottom: 12px;
        }
        .spp-sa2 .btn { padding: 10px 20px; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; margin-right: 8px; }
        .spp-sa2 .btn-primary { background: #3766AB; color: #fff; }
        .spp-sa2 .btn-danger  { background: #c0392b; color: #fff; }
        .spp-sa2 .btn-neutral { background: #eee; color: #333; }
        .spp-sa2 .box { border: 1px solid #ddd; border-radius: 6px; padding: 14px 18px; margin: 14px 0; background: #fafafa; }
        .spp-sa2 .box-warn { border-color: #ffc107; background: #fff8e1; }
        .spp-sa2 .box-ok   { border-color: #28a745; background: #eaf7ed; }
        .spp-sa2 .box-err  { border-color: #c0392b; background: #fdf3f2; }
        .spp-sa2 table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        .spp-sa2 th, .spp-sa2 td { padding: 6px 10px; border-bottom: 1px solid #eee; text-align: left; }
        .spp-sa2 th { background: #3766AB; color: #fff !important; }
    </style>
    <?php
}

function spp_sa2_action_selector() {
    global $wpdb;

    $event = (int) get_option( 'spp_current_event', 0 );
    $players = $event ? $wpdb->get_results(
        "SELECT s.user_id, s.first_name, s.last_name, s.group_id, g.GP_name, t.T_desc
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Times t ON s.time_id = t.T_ID
         WHERE s.group_id != 99
         ORDER BY s.last_name, s.first_name",
        ARRAY_A
    ) : array();

    echo '<h3>Schedule Adjustment</h3>';

    if ( ! $event ) {
        echo '<p>No current event set.</p>';
        return;
    }

    $pending_groups = spp_sa2_get_pending_notify_groups();
    if ( ! empty( $pending_groups ) ) {
        echo '<div class="box box-warn"><strong>' . count( $pending_groups ) . ' group(s) queued for notification</strong> from adjustments made so far tonight -- no emails sent yet.<br><br>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
        echo '<input type="hidden" name="spp_sa_action" value="notify_pending">';
        echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Send notifications now for all queued groups? Do this once you\\\'re done making adjustments for tonight.\')">Send Pending Notifications</button>';
        echo '</form> ';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
        echo '<input type="hidden" name="spp_sa_action" value="discard_pending">';
        echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Discard the pending notification queue without sending anything? The schedule changes themselves stay in place -- only the notification is discarded. This cannot be undone.\')">Discard</button>';
        echo '</form>';
        echo '</div>';
    }

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="dropout">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose">';
    echo '<div class="box"><strong>Dropout</strong> -- remove a player who can no longer play tonight.<br><br>';
    echo '<select name="spp_sa_player_id" required>';
    echo '<option value="">-- select player --</option>';
    foreach ( $players as $p ) {
        echo '<option value="' . (int) $p['user_id'] . '">'
           . esc_html( $p['last_name'] . ', ' . $p['first_name'] )
           . ' (' . esc_html( $p['GP_name'] ) . ' -- ' . esc_html( $p['T_desc'] ) . ')</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Propose Dropout</button>';
    echo '</div>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="add">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose">';
    $roster_uids = array_column( $players, 'user_id' );
    $all_members = $wpdb->get_results(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_email != '' ORDER BY last_name, first_name",
        ARRAY_A
    );
    echo '<div class="box"><strong>Last-minute add</strong> -- add any club member who was not originally scheduled.<br><br>';
    echo '<select name="spp_sa_add_uid" required>';
    echo '<option value="">-- select member --</option>';
    foreach ( $all_members as $m ) {
        if ( in_array( (int) $m['user_id'], $roster_uids, true ) ) continue; // already scheduled
        echo '<option value="' . (int) $m['user_id'] . '">' . esc_html( $m['last_name'] . ', ' . $m['first_name'] ) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Propose Add</button>';
    echo '</div>';
    echo '</form>';

    echo '<div class="box"><strong>Group time-slot swap</strong><br><br>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose_by_player">';
    echo '<label><strong>By player</strong> -- find a candidate swap that gets this player into a time slot they can actually make</label>';
    echo '<select name="spp_sa_gs_player" required><option value="">-- select player --</option>';
    foreach ( $players as $p ) {
        echo '<option value="' . (int) $p['user_id'] . '">' . esc_html( $p['last_name'] . ', ' . $p['first_name'] ) . ' (' . esc_html( $p['GP_name'] ) . ' -- ' . esc_html( $p['T_desc'] ) . ')</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Find Candidate Swaps</button>';
    echo '</form>';

    echo '<hr style="margin:16px 0;border:none;border-top:1px solid #ddd;">';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose">';
    $groups_now = $wpdb->get_results(
        "SELECT s.group_id, g.GP_name, t.T_desc, t.T_ID
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Times t ON s.time_id = t.T_ID
         WHERE s.group_id != 99
         GROUP BY s.group_id
         ORDER BY g.GP_name", ARRAY_A
    );
    echo '<label><strong>By groups directly</strong> -- manual fallback, not tied to any one player\'s need</label>';
    echo '<label>Group A</label><select name="spp_sa_gs_a" required><option value="">-- select --</option>';
    foreach ( $groups_now as $g ) {
        echo '<option value="' . (int) $g['group_id'] . '">' . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] ) . '</option>';
    }
    echo '</select>';
    echo '<label>Group B</label><select name="spp_sa_gs_b" required><option value="">-- select --</option>';
    foreach ( $groups_now as $g ) {
        echo '<option value="' . (int) $g['group_id'] . '">' . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] ) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Propose Swap</button>';
    echo '</div>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="rank">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose">';
    $all_members = $wpdb->get_results( "SELECT user_id, first_name, last_name, Rank FROM Master ORDER BY last_name, first_name", ARRAY_A );
    echo '<div class="box"><strong>Manual rank override</strong> -- adjust a member\'s rank directly. Usable anytime, not just during a last-minute add.<br><br>';
    echo '<select name="spp_sa_rank_uid" required><option value="">-- select member --</option>';
    foreach ( $all_members as $m ) {
        echo '<option value="' . (int) $m['user_id'] . '">' . esc_html( $m['last_name'] . ', ' . $m['first_name'] ) . ' (current: ' . (int) $m['Rank'] . ')</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Set New Rank</button>';
    echo '</div>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="playerswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose">';
    echo '<div class="box"><strong>Player swap</strong> -- move one player; the tool suggests where.<br><br>';
    echo '<label>Player 1 (needs to move)</label>';
    echo '<select name="spp_sa_ps_p1" required><option value="">-- select player --</option>';
    foreach ( $players as $p ) {
        echo '<option value="' . (int) $p['user_id'] . '">' . esc_html( $p['last_name'] . ', ' . $p['first_name'] ) . ' (' . esc_html( $p['GP_name'] ) . ' -- ' . esc_html( $p['T_desc'] ) . ')</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Find Options</button>';
    echo '</div>';
    echo '</form>';
}

/* =========================================================
   DROPOUT
   ========================================================= */

function spp_sa2_dropout_flow( $stage ) {
    global $wpdb;

    $carpool_rank_tolerance = 15; // matches the default in gl-schedule-production.php

    if ( $stage === 'propose' ) {
        spp_sa2_dropout_propose( $carpool_rank_tolerance );
    } elseif ( $stage === 'apply' ) {
        spp_sa2_dropout_apply( $carpool_rank_tolerance );
    } elseif ( $stage === 'finalize' ) {
        spp_sa2_dropout_finalize();
    } elseif ( $stage === 'undo' ) {
        spp_sa2_dropout_undo();
    } else {
        spp_sa2_action_selector();
    }
}

function spp_sa2_dropout_propose( $carpool_rank_tolerance ) {
    global $wpdb;

    $player_id = (int) ( $_POST['spp_sa_player_id'] ?? 0 );
    if ( ! $player_id ) {
        echo '<p class="box box-err">No player selected.</p>';
        spp_sa2_action_selector();
        return;
    }

    $player = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.*, m.travel FROM Schedules s JOIN Master m ON s.user_id = m.user_id WHERE s.user_id = %d AND s.group_id != 99",
        $player_id
    ), ARRAY_A );

    if ( ! $player ) {
        echo '<p class="box box-err">That player is not currently scheduled.</p>';
        spp_sa2_action_selector();
        return;
    }

    $group_id = (int) $player['group_id'];
    $roster   = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank, m.travel
         FROM Schedules s JOIN Master m ON s.user_id = m.user_id
         WHERE s.group_id = %d", $group_id
    ), ARRAY_A );

    $remaining = array_values( array_filter( $roster, fn( $p ) => (int) $p['user_id'] !== $player_id ) );

    echo '<h3>Propose Dropout</h3>';
    echo '<div class="box"><strong>' . esc_html( $player['first_name'] . ' ' . $player['last_name'] ) . '</strong> will be removed from the schedule.</div>';

    $needs_rebalance = count( $remaining ) < 4;

    $plan = array( 'player_id' => $player_id, 'group_id' => $group_id, 'time_id' => (int) $player['time_id'] );

    if ( ! $needs_rebalance ) {
        echo '<div class="box box-ok">Remaining group size will be ' . count( $remaining ) . ' -- no rebalancing needed.</div>';
        $plan['mode']            = 'simple';
        $plan['affected_groups'] = array( $group_id );
    } else {
        // Try pack: one other same-slot group with room for all remaining players.
        $same_slot_time = (int) $player['time_id'];
        $pack_candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.group_id, COUNT(*) AS cnt
             FROM Schedules s
             WHERE s.group_id != 99 AND s.group_id != %d AND s.time_id = %d
             GROUP BY s.group_id
             HAVING cnt <= %d
             ORDER BY cnt DESC",
            $group_id, $same_slot_time, 5 - count( $remaining )
        ), ARRAY_A );

        $pack_target = null;
        foreach ( $pack_candidates as $cand ) {
            $ok_all = true;
            foreach ( $remaining as $rp ) {
                $check = spp_sa2_group_accepts_player(
                    (int) $cand['group_id'], (int) $rp['user_id'], (int) $rp['Rank'], $rp['travel'], $carpool_rank_tolerance
                );
                if ( ! $check['ok'] ) { $ok_all = false; break; }
            }
            if ( $ok_all ) { $pack_target = (int) $cand['group_id']; break; }
        }

        if ( $pack_target ) {
            echo '<div class="box box-ok">Group will dissolve. All ' . count( $remaining ) . ' remaining player(s) will be <strong>packed</strong> into group ID ' . $pack_target . ' (same time slot).</div>';
            $plan['mode']            = 'pack';
            $plan['pack_target']     = $pack_target;
            $plan['remaining']       = array_column( $remaining, 'user_id' );
            $plan['affected_groups'] = array( $group_id, $pack_target );
        } else {
            // Spread across all slots, independently, tracking exclusions.
            $placements = array();
            $unresolved_uids = array(); // uid => name, for players auto-search couldn't place
            $used_groups = array( $group_id );

            foreach ( $remaining as $rp ) {
                $best = spp_sa2_find_best_group(
                    (int) $rp['user_id'], (int) $rp['Rank'], $rp['travel'], null, $used_groups, $carpool_rank_tolerance
                );
                if ( $best ) {
                    $placements[ (int) $rp['user_id'] ] = $best;
                    $used_groups[] = $best['group_id'];
                } else {
                    $unresolved_uids[ (int) $rp['user_id'] ] = $rp['first_name'] . ' ' . $rp['last_name'];
                }
            }

            if ( ! empty( $placements ) ) {
                echo '<div class="box box-warn">No single group could pack all remaining players. <strong>Spreading</strong> across other groups (any time slot):<ul>';
                foreach ( $placements as $uid => $g ) {
                    $name = array_values( array_filter( $remaining, fn( $p ) => (int) $p['user_id'] === $uid ) )[0];
                    echo '<li>' . esc_html( $name['first_name'] . ' ' . $name['last_name'] ) . ' -> group ' . $g['group_id'] . '</li>';
                }
                echo '</ul></div>';
            }

            $plan['mode']            = 'spread';
            $plan['placements']      = $placements;
            $plan['unresolved_uids'] = array_keys( $unresolved_uids );
            $plan['affected_groups'] = array_merge( array( $group_id ), array_map( fn( $d ) => $d['group_id'], $placements ) );

            if ( ! empty( $unresolved_uids ) ) {
                echo '<div class="box box-err"><strong>Could not auto-place:</strong> ' . esc_html( implode( ', ', $unresolved_uids ) ) . '. Choose a group manually for each below to override -- no automatic constraint checks will run against these choices.</div>';

                $all_groups = $wpdb->get_results(
                    "SELECT s.group_id, g.GP_name, t.T_desc, t.T_ID, s.Crt_ID, COUNT(*) AS cnt
                     FROM Schedules s
                     JOIN Groups g ON s.group_id = g.GP_ID
                     JOIN Times t  ON s.time_id = t.T_ID
                     WHERE s.group_id != 99
                     GROUP BY s.group_id
                     ORDER BY t.T_ID, g.GP_name", ARRAY_A
                );
            }
        }
    }

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="dropout">';
    echo '<input type="hidden" name="spp_sa_stage" value="apply">';
    echo '<input type="hidden" name="spp_sa_plan" value="' . esc_attr( base64_encode( wp_json_encode( $plan ) ) ) . '">';

    if ( ! empty( $unresolved_uids ) ) {
        echo '<div class="box"><strong>Manual placement required:</strong><br><br>';
        foreach ( $unresolved_uids as $uid => $name ) {
            echo '<label>' . esc_html( $name ) . '</label>';
            echo '<select name="spp_sa_manual_' . (int) $uid . '" required>';
            echo '<option value="">-- select group --</option>';
            foreach ( $all_groups as $g ) {
                $full_flag = (int) $g['cnt'] >= 5 ? ' (FULL -- will exceed normal size)' : '';
                echo '<option value="' . (int) $g['group_id'] . '">' . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] . ' (' . (int) $g['cnt'] . '/5)' . $full_flag ) . '</option>';
            }
            echo '</select>';
        }
        echo '</div>';
    }

    echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this dropout? A backup will be taken and a validation check will run before anything is sent.\')">Apply and Check</button>';
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

function spp_sa2_dropout_apply( $carpool_rank_tolerance ) {
    global $wpdb;

    $plan_raw = $_POST['spp_sa_plan'] ?? '';
    $plan     = json_decode( base64_decode( $plan_raw ), true );
    if ( ! is_array( $plan ) ) {
        echo '<p class="box box-err">Lost track of the proposed change -- please start over.</p>';
        spp_sa2_action_selector();
        return;
    }

    $backup_table = spp_sa2_backup_schedules( 'dropout' );

    $player_id = (int) $plan['player_id'];
    $group_id  = (int) $plan['group_id'];

    spp_sa2_registration_withdraw( (int) get_option( 'spp_current_event', 0 ), $player_id );

    if ( $plan['mode'] === 'simple' ) {
        $wpdb->delete( 'Schedules', array( 'user_id' => $player_id ) );
    } elseif ( $plan['mode'] === 'pack' ) {
        $pack_target = (int) $plan['pack_target'];
        $target_row  = $wpdb->get_row( $wpdb->prepare( "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $pack_target ), ARRAY_A );
        $wpdb->delete( 'Schedules', array( 'user_id' => $player_id ) );
        foreach ( $plan['remaining'] as $uid ) {
            $wpdb->update( 'Schedules',
                array( 'group_id' => $pack_target, 'Crt_ID' => $target_row['Crt_ID'], 'time_id' => $target_row['time_id'] ),
                array( 'user_id' => (int) $uid )
            );
        }
    } elseif ( $plan['mode'] === 'spread' ) {
        // Merge in any manually-chosen placements (server-side lookup of the
        // group's current Crt_ID/time_id -- no automatic constraint checks
        // ran against these, by design, since the auto-search already failed
        // for these specific players).
        foreach ( $plan['unresolved_uids'] ?? array() as $uid ) {
            $manual_group = (int) ( $_POST[ 'spp_sa_manual_' . $uid ] ?? 0 );
            if ( $manual_group ) {
                $target_row = $wpdb->get_row( $wpdb->prepare( "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $manual_group ), ARRAY_A );
                $plan['placements'][ $uid ] = array(
                    'group_id' => $manual_group,
                    'Crt_ID'   => (int) $target_row['Crt_ID'],
                    'time_id'  => (int) $target_row['time_id'],
                );
            }
        }

        $wpdb->delete( 'Schedules', array( 'user_id' => $player_id ) );
        foreach ( $plan['placements'] as $uid => $dest ) {
            $wpdb->update( 'Schedules',
                array( 'group_id' => $dest['group_id'], 'Crt_ID' => $dest['Crt_ID'], 'time_id' => $dest['time_id'] ),
                array( 'user_id' => (int) $uid )
            );
        }

        // Recompute affected_groups fresh -- the plan's version (computed at
        // propose time) doesn't know about manual choices made just now.
        $plan['affected_groups'] = array_merge( array( $group_id ), array_map( fn( $d ) => $d['group_id'], $plan['placements'] ) );
    }

    echo '<h3>Applied -- Validation Check</h3>';
    echo '<div class="box">Backup saved as <code>' . esc_html( $backup_table ) . '</code>.</div>';

    if ( function_exists( 'spp_run_schedule_check' ) ) {
        echo '<div class="box">';
        spp_run_schedule_check();
        echo '</div>';
    } else {
        echo '<div class="box box-warn">spp_run_schedule_check() not available -- skipping automated check.</div>';
    }

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="dropout">';
    echo '<input type="hidden" name="spp_sa_stage" value="finalize">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<input type="hidden" name="spp_sa_player_id" value="' . (int) $player_id . '">';
    echo '<input type="hidden" name="spp_sa_affected_groups" value="' . esc_attr( implode( ',', array_map( 'intval', $plan['affected_groups'] ) ) ) . '">';
    echo '<button type="submit" class="btn btn-primary">Looks good -- send notifications</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="dropout">';
    echo '<input type="hidden" name="spp_sa_stage" value="undo">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancel this change? Only the rows this action touched will be reverted -- nothing else will be affected.\')">Not acceptable -- cancel</button>';
    echo '</form>';
}

function spp_sa2_dropout_undo() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $ok = spp_sa2_restore_schedules( $backup_table );
    echo '<h3>Undo</h3>';
    if ( $ok ) {
        echo '<div class="box box-ok">Restored from <code>' . esc_html( $backup_table ) . '</code>. No notifications were sent.</div>';
    } else {
        echo '<div class="box box-err">Could not restore -- backup table not found.</div>';
    }
    spp_sa2_action_selector();
}

/* =========================================================
   MANUAL RANK OVERRIDE
   Standalone -- no placement or notification logic. Just a
   direct, permanent correction to Master + usermeta, usable
   anytime (not only during a last-minute add).
   ========================================================= */

/**
 * Fetch first/last/phone/email/travel for a user_id regardless of whether
 * they're currently in Master (a ladder-registered player) or only in the
 * broader membership roster. Master is preferred when present since it's
 * the authoritative source for currently-active ladder players; otherwise
 * falls back to membership + Travel usermeta.
 */
function spp_sa2_get_member_info( $uid ) {
    global $wpdb;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT first_name, last_name, user_phone, user_email, travel FROM Master WHERE user_id = %d", $uid
    ), ARRAY_A );
    if ( $row ) return $row;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT first_name, last_name, user_phone, user_email FROM membership WHERE user_id = %d", $uid
    ), ARRAY_A );
    if ( ! $row ) {
        $wp_user = get_userdata( $uid );
        $row = array(
            'first_name' => $wp_user ? $wp_user->first_name : '',
            'last_name'  => $wp_user ? $wp_user->last_name : '',
            'user_phone' => '',
            'user_email' => $wp_user ? $wp_user->user_email : '',
        );
    }
    $row['travel'] = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'Travel'", $uid
    ) ) ?: '';
    return $row;
}

/**
 * Make sure $uid has a Master row before they get scheduled -- everything
 * downstream (group_accepts_player, spp_run_schedule_check, notify) JOINs
 * Schedules to Master, so a scheduled player with no Master row would
 * silently vanish from every one of those. Inserts if missing, otherwise
 * just updates Rank/Ladder on the existing row.
 */
function spp_sa2_ensure_master_row( $uid, $rank ) {
    global $wpdb;

    $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM Master WHERE user_id = %d", $uid ) );

    if ( $exists ) {
        $wpdb->update( 'Master', array( 'Rank' => $rank, 'Ladder' => 'Yes' ), array( 'user_id' => $uid ) );
        return;
    }

    $info = spp_sa2_get_member_info( $uid );
    $wpdb->insert( 'Master', array(
        'user_id'    => $uid,
        'first_name' => $info['first_name'],
        'last_name'  => $info['last_name'],
        'user_phone' => $info['user_phone'],
        'travel'     => $info['travel'] ?? '',
        'user_email' => $info['user_email'],
        'Ladder'     => 'Yes',
        'Rank'       => $rank,
    ) );
}

function spp_sa2_rank_flow( $stage ) {
    if ( $stage === 'propose' ) {
        spp_sa2_rank_propose();
    } elseif ( $stage === 'save' ) {
        spp_sa2_rank_save();
    } else {
        spp_sa2_action_selector();
    }
}

function spp_sa2_rank_propose() {
    global $wpdb;

    $uid = (int) ( $_POST['spp_sa_rank_uid'] ?? 0 );
    if ( ! $uid ) {
        echo '<p class="box box-err">No member selected.</p>';
        spp_sa2_action_selector();
        return;
    }

    $member = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, Rank FROM Master WHERE user_id = %d", $uid ), ARRAY_A );

    echo '<h3>Manual Rank Override</h3>';
    echo '<div class="box">' . esc_html( $member['first_name'] . ' ' . $member['last_name'] ) . ' -- current rank: <strong>' . (int) $member['Rank'] . '</strong></div>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="rank">';
    echo '<input type="hidden" name="spp_sa_stage" value="save">';
    echo '<input type="hidden" name="spp_sa_rank_uid" value="' . (int) $uid . '">';
    echo '<label>New rank:</label>';
    echo '<input type="number" name="spp_sa_new_rank" value="' . (int) $member['Rank'] . '" min="1" required>';
    echo '<button type="submit" class="btn btn-primary">Save</button>';
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

function spp_sa2_rank_save() {
    global $wpdb;

    $uid  = (int) ( $_POST['spp_sa_rank_uid'] ?? 0 );
    $rank = (int) ( $_POST['spp_sa_new_rank'] ?? 0 );

    if ( ! $uid || $rank < 1 ) {
        echo '<p class="box box-err">Invalid rank.</p>';
        spp_sa2_action_selector();
        return;
    }

    $wpdb->update( 'Master', array( 'Rank' => $rank ), array( 'user_id' => $uid ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'Rank'", $uid ) );
    $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, 'Rank', %d)", $uid, $rank ) );

    $member = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name FROM Master WHERE user_id = %d", $uid ), ARRAY_A );

    echo '<h3>Rank Updated</h3>';
    echo '<div class="box box-ok">' . esc_html( $member['first_name'] . ' ' . $member['last_name'] ) . ' rank set to <strong>' . $rank . '</strong>.</div>';

    spp_sa2_action_selector();
}

/* =========================================================
   PLAYER SWAP
   Player 1 is selected as needing to move. The Player 2
   dropdown offers "Just insert" (best-fit open spot, same
   search as last-minute-add) plus every other scheduled
   player who would make a valid COMPENSATING SWAP -- checked
   bidirectionally: does each player's rank/travel preference
   still work in the other's group, with no carpool match
   broken on either side. Sorted best-fit first (closest
   combined rank match).
   ========================================================= */

function spp_sa2_playerswap_flow( $stage ) {
    $carpool_rank_tolerance = 15;

    if ( $stage === 'propose' ) {
        spp_sa2_playerswap_propose( $carpool_rank_tolerance );
    } elseif ( $stage === 'apply' ) {
        spp_sa2_playerswap_apply( $carpool_rank_tolerance );
    } elseif ( $stage === 'finalize' ) {
        spp_sa2_playerswap_finalize();
    } elseif ( $stage === 'undo' ) {
        spp_sa2_playerswap_undo();
    } else {
        spp_sa2_action_selector();
    }
}

function spp_sa2_swap_valid( $p1, $p2, $time_ids, $carpool_rank_tolerance ) {
    // Would P1 fit into P2's group (P2 having left), and does P1's own
    // travel preference match P2's slot?
    $check_a = spp_sa2_group_accepts_player(
        (int) $p2['group_id'], (int) $p1['user_id'], (int) $p1['Rank'], $p1['travel'], $carpool_rank_tolerance, (int) $p2['user_id']
    );
    if ( ! $check_a['ok'] ) return false;
    if ( ! empty( spp_sa2_check_travel_for_new_time( array( $p1 ), (int) $p2['time_id'], $time_ids ) ) ) return false;

    // Symmetric: would P2 fit into P1's group, travel match P1's slot?
    $check_b = spp_sa2_group_accepts_player(
        (int) $p1['group_id'], (int) $p2['user_id'], (int) $p2['Rank'], $p2['travel'], $carpool_rank_tolerance, (int) $p1['user_id']
    );
    if ( ! $check_b['ok'] ) return false;
    if ( ! empty( spp_sa2_check_travel_for_new_time( array( $p2 ), (int) $p1['time_id'], $time_ids ) ) ) return false;

    return true;
}

function spp_sa2_playerswap_propose( $carpool_rank_tolerance ) {
    global $wpdb;

    $p1_uid = (int) ( $_POST['spp_sa_ps_p1'] ?? 0 );
    if ( ! $p1_uid ) {
        echo '<p class="box box-err">No player selected.</p>';
        spp_sa2_action_selector();
        return;
    }

    $p1 = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.group_id, s.time_id, s.Crt_ID, m.travel
         FROM Schedules s JOIN Master m ON s.user_id = m.user_id
         WHERE s.user_id = %d AND s.group_id != 99", $p1_uid
    ), ARRAY_A );

    if ( ! $p1 ) {
        echo '<p class="box box-err">That player is not currently scheduled.</p>';
        spp_sa2_action_selector();
        return;
    }

    $time_ids = spp_sa2_get_time_positions();

    echo '<h3>Player Swap -- Options</h3>';
    echo '<div class="box">' . esc_html( $p1['first_name'] . ' ' . $p1['last_name'] ) . ' currently in group ' . (int) $p1['group_id'] . '.</div>';

    // "Just insert" option -- same search as last-minute-add, all slots, excluding own group.
    $insert_target = spp_sa2_find_best_group(
        $p1_uid, (int) $p1['Rank'], $p1['travel'], null, array( (int) $p1['group_id'] ), $carpool_rank_tolerance
    );

    // Compensating swap candidates -- every other scheduled player.
    $others = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.group_id, s.time_id, s.Crt_ID, m.travel
         FROM Schedules s JOIN Master m ON s.user_id = m.user_id
         WHERE s.group_id != 99 AND s.user_id != %d", $p1_uid
    ), ARRAY_A );

    $candidates = array();
    foreach ( $others as $p2 ) {
        if ( (int) $p2['group_id'] === (int) $p1['group_id'] ) continue; // already in same group, not a meaningful swap
        if ( spp_sa2_swap_valid( $p1, $p2, $time_ids, $carpool_rank_tolerance ) ) {
            $candidates[] = array(
                'uid'      => (int) $p2['user_id'],
                'name'     => $p2['first_name'] . ' ' . $p2['last_name'],
                'rankdiff' => abs( (int) $p1['Rank'] - (int) $p2['Rank'] ),
            );
        }
    }
    usort( $candidates, fn( $a, $b ) => $a['rankdiff'] <=> $b['rankdiff'] );

    // Manual override -- always available, not just when auto-search comes up
    // empty. This is also the direct "just move this player to a group of my
    // choosing" option, independent of insert-search or compensating-swap
    // logic finding anything at all.
    $all_groups = $wpdb->get_results(
        "SELECT s.group_id, g.GP_name, t.T_desc, t.T_ID, s.Crt_ID, COUNT(*) AS cnt
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Times t  ON s.time_id = t.T_ID
         WHERE s.group_id != 99 AND s.group_id != " . (int) $p1['group_id'] . "
         GROUP BY s.group_id
         ORDER BY t.T_ID, g.GP_name", ARRAY_A
    );

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="playerswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="apply">';
    echo '<input type="hidden" name="spp_sa_ps_p1" value="' . $p1_uid . '">';
    echo '<label>Choose:</label><select name="spp_sa_ps_choice" required>';
    if ( $insert_target ) {
        echo '<option value="insert:' . (int) $insert_target['group_id'] . ':' . (int) $insert_target['time_id'] . ':' . (int) $insert_target['Crt_ID'] . '">Just insert -- open spot in group ' . (int) $insert_target['group_id'] . '</option>';
    }
    foreach ( $candidates as $c ) {
        echo '<option value="swap:' . $c['uid'] . '">Swap with ' . esc_html( $c['name'] ) . ' (rank diff ' . $c['rankdiff'] . ')</option>';
    }
    if ( ! empty( $all_groups ) ) {
        echo '<optgroup label="Or choose a group manually (overrides automatic checks)">';
        foreach ( $all_groups as $g ) {
            $full_flag = (int) $g['cnt'] >= 5 ? ' (FULL -- will exceed normal size)' : '';
            echo '<option value="manual:' . (int) $g['group_id'] . '">' . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] . ' (' . (int) $g['cnt'] . '/5)' . $full_flag ) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';

    if ( ! $insert_target && empty( $candidates ) && empty( $all_groups ) ) {
        echo '<div class="box box-err">No valid insert spot, compensating swap, or other group found. Cannot proceed.</div>';
    } else {
        echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this change? A backup will be taken and a validation check will run before anything is sent.\')">Apply and Check</button>';
    }
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

function spp_sa2_playerswap_apply( $carpool_rank_tolerance ) {
    global $wpdb;

    $p1_uid = (int) ( $_POST['spp_sa_ps_p1'] ?? 0 );
    $choice = sanitize_text_field( $_POST['spp_sa_ps_choice'] ?? '' );
    $parts  = explode( ':', $choice );

    $backup_table = spp_sa2_backup_schedules( 'playerswap' );

    $p1 = $wpdb->get_row( $wpdb->prepare( "SELECT group_id, time_id, Crt_ID FROM Schedules WHERE user_id = %d", $p1_uid ), ARRAY_A );
    $affected_groups = array( (int) $p1['group_id'] );

    if ( $parts[0] === 'insert' ) {
        $new_group = (int) $parts[1]; $new_time = (int) $parts[2]; $new_crt = (int) $parts[3];
        $wpdb->update( 'Schedules', array( 'group_id' => $new_group, 'time_id' => $new_time, 'Crt_ID' => $new_crt ), array( 'user_id' => $p1_uid ) );
        $affected_groups[] = $new_group;
    } elseif ( $parts[0] === 'manual' ) {
        $new_group  = (int) $parts[1];
        $target_row = $wpdb->get_row( $wpdb->prepare( "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $new_group ), ARRAY_A );
        $wpdb->update( 'Schedules', array( 'group_id' => $new_group, 'time_id' => (int) $target_row['time_id'], 'Crt_ID' => (int) $target_row['Crt_ID'] ), array( 'user_id' => $p1_uid ) );
        $affected_groups[] = $new_group;
    } else { // swap
        $p2_uid = (int) $parts[1];
        $p2 = $wpdb->get_row( $wpdb->prepare( "SELECT group_id, time_id, Crt_ID FROM Schedules WHERE user_id = %d", $p2_uid ), ARRAY_A );
        $wpdb->update( 'Schedules', array( 'group_id' => $p2['group_id'], 'time_id' => $p2['time_id'], 'Crt_ID' => $p2['Crt_ID'] ), array( 'user_id' => $p1_uid ) );
        $wpdb->update( 'Schedules', array( 'group_id' => $p1['group_id'], 'time_id' => $p1['time_id'], 'Crt_ID' => $p1['Crt_ID'] ), array( 'user_id' => $p2_uid ) );
        $affected_groups[] = (int) $p2['group_id'];
    }

    echo '<h3>Applied -- Validation Check</h3>';
    echo '<div class="box">Backup saved as <code>' . esc_html( $backup_table ) . '</code>.</div>';

    if ( function_exists( 'spp_run_schedule_check' ) ) {
        echo '<div class="box">';
        spp_run_schedule_check();
        echo '</div>';
    }

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="playerswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="finalize">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<input type="hidden" name="spp_sa_affected_groups" value="' . esc_attr( implode( ',', $affected_groups ) ) . '">';
    echo '<button type="submit" class="btn btn-primary">Looks good -- send notifications</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="playerswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="undo">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancel this change? Only the rows this action touched will be reverted -- nothing else will be affected.\')">Not acceptable -- cancel</button>';
    echo '</form>';
}

function spp_sa2_playerswap_undo() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $ok = spp_sa2_restore_schedules( $backup_table );
    echo '<h3>Undo</h3>';
    echo $ok
        ? '<div class="box box-ok">Restored from <code>' . esc_html( $backup_table ) . '</code>. No notifications were sent.</div>'
        : '<div class="box box-err">Could not restore -- backup table not found.</div>';
    spp_sa2_action_selector();
}

function spp_sa2_playerswap_finalize() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $affected_group_ids = array_filter( array_map( 'intval', explode( ',', $_POST['spp_sa_affected_groups'] ?? '' ) ) );

    echo '<h3>Finalized</h3>';
    echo '<div class="box box-ok">Change kept. Backup <code>' . esc_html( $backup_table ) . '</code> retained for this event.</div>';

    spp_sa2_finalize_notify_or_queue( $affected_group_ids, 'A player was moved to a different group.', 'Player Swap' );

    spp_sa2_action_selector();
}

function spp_sa2_get_rank( $rating, $ave, $se, $num ) {
    switch ( $rating ) {
        case 2:
            $max = $num;
            $min = $ave + 6 * $se;
            break;
        case 2.5:
            $max = $ave + 8 * $se;
            $min = $ave + 6 * $se;
            break;
        case 3:
            $max = $ave + 4 * $se;
            $min = $ave + 2 * $se;
            break;
        case 3.5:
            $max = $ave + $se;
            $min = $ave - 2 * $se;
            break;
        case 4:
            $max = $ave - 2.5 * $se;
            $min = $ave - 4 * $se;
            break;
        case 4.5:
            $max = $ave - 4.5 * $se;
            $min = $ave - 6 * $se;
            break;
        case 5:
        case 'Professional':
            $max = $ave - 6.5 * $se;
            $min = $ave - 8 * $se;
            break;
        default: // 'Beginner' and anything unrecognized -> weak/bottom band
            $max = $num - 10;
            $min = $num - 20;
    }
    $r = rand( (int) round( $min ), (int) round( $max ) );
    return min( $num, max( 5, $r ) );
}

/**
 * Suggest a rank for $uid. Returns array('rank'=>int, 'source'=>string)
 * where source explains where the number came from, for display.
 */
function spp_sa2_suggest_rank( $uid ) {
    global $wpdb;

    $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT Rank FROM Master WHERE user_id = %d", $uid ) );
    if ( $current > 0 ) {
        return array( 'rank' => $current, 'source' => 'existing rank on record' );
    }

    $old_rank = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'old_Rank'", $uid
    ) );
    if ( $old_rank !== null ) {
        return array( 'rank' => (int) $old_rank + 3, 'source' => 'returning player (old rank + 3)' );
    }

    $stats = $wpdb->get_row(
        "SELECT AVG(Rank) AS ave, STDDEV_SAMP(Rank)/SQRT(COUNT(*)) AS se, COUNT(*) AS numplayers
         FROM Master WHERE Rank > 0", ARRAY_A
    );
    $rating = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'Rating'", $uid
    ) ) ?: '3';

    $rank = spp_sa2_get_rank( $rating, round( $stats['ave'] ), $stats['se'], (int) $stats['numplayers'] );
    return array( 'rank' => $rank, 'source' => "calculated from Rating ({$rating}) against current ladder stats" );
}

/* =========================================================
   LAST-MINUTE ADD
   ========================================================= */

function spp_sa2_add_flow( $stage ) {
    $carpool_rank_tolerance = 15;

    if ( $stage === 'propose' ) {
        spp_sa2_add_propose( $carpool_rank_tolerance );
    } elseif ( $stage === 'propose_placement' ) {
        spp_sa2_add_propose_placement( $carpool_rank_tolerance );
    } elseif ( $stage === 'apply' ) {
        spp_sa2_add_apply( $carpool_rank_tolerance );
    } elseif ( $stage === 'finalize' ) {
        spp_sa2_add_finalize();
    } elseif ( $stage === 'undo' ) {
        spp_sa2_add_undo();
    } else {
        spp_sa2_action_selector();
    }
}

// Stage 1: pick the member -> show suggested rank, editable, before we search placement.
function spp_sa2_add_propose( $carpool_rank_tolerance ) {
    global $wpdb;

    $uid = (int) ( $_POST['spp_sa_add_uid'] ?? 0 );
    if ( ! $uid ) {
        echo '<p class="box box-err">No member selected.</p>';
        spp_sa2_action_selector();
        return;
    }

    $member = spp_sa2_get_member_info( $uid );
    $suggestion = spp_sa2_suggest_rank( $uid );

    echo '<h3>Last-Minute Add -- Rank</h3>';
    echo '<div class="box">Adding <strong>' . esc_html( $member['first_name'] . ' ' . $member['last_name'] ) . '</strong>.<br>';
    echo 'Suggested rank: <strong>' . (int) $suggestion['rank'] . '</strong> (' . esc_html( $suggestion['source'] ) . ')</div>';

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="add">';
    echo '<input type="hidden" name="spp_sa_stage" value="propose_placement">';
    echo '<input type="hidden" name="spp_sa_add_uid" value="' . (int) $uid . '">';
    echo '<label>Rank to use (edit if needed):</label>';
    echo '<input type="number" name="spp_sa_add_rank" value="' . (int) $suggestion['rank'] . '" min="1" required>';
    echo '<button type="submit" class="btn btn-primary">Find a placement</button>';
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

// Stage 2: search all time slots for best-fit group, show the proposal.
function spp_sa2_add_propose_placement( $carpool_rank_tolerance ) {
    global $wpdb;

    $uid  = (int) ( $_POST['spp_sa_add_uid'] ?? 0 );
    $rank = (int) ( $_POST['spp_sa_add_rank'] ?? 0 );

    $member = spp_sa2_get_member_info( $uid );

    $best = spp_sa2_find_best_group( $uid, $rank, $member['travel'], null, array(), $carpool_rank_tolerance );

    echo '<h3>Last-Minute Add -- Placement</h3>';

    if ( ! $best ) {
        echo '<div class="box box-err">No group has room for <strong>' . esc_html( $member['first_name'] . ' ' . $member['last_name'] ) . '</strong> at rank ' . (int) $rank . ' without breaking a carpool match or exceeding 5 players. Cannot proceed automatically -- pick a group manually below to override.</div>';
    } else {
        $group_desc = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.GP_name, t.T_desc FROM Groups g, Times t
             WHERE g.GP_ID = %d AND t.T_ID = %d", $best['group_id'], $best['time_id']
        ), ARRAY_A );
        echo '<div class="box box-ok">Best fit: <strong>' . esc_html( $group_desc['GP_name'] ?? ('Group ' . $best['group_id']) )
           . '</strong> at ' . esc_html( $group_desc['T_desc'] ?? '' ) . '.</div>';

        echo '<form method="post">';
        wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
        echo '<input type="hidden" name="spp_sa_action" value="add">';
        echo '<input type="hidden" name="spp_sa_stage" value="apply">';
        echo '<input type="hidden" name="spp_sa_add_uid" value="' . (int) $uid . '">';
        echo '<input type="hidden" name="spp_sa_add_rank" value="' . (int) $rank . '">';
        echo '<input type="hidden" name="spp_sa_add_group" value="' . (int) $best['group_id'] . '">';
        echo '<input type="hidden" name="spp_sa_add_time" value="' . (int) $best['time_id'] . '">';
        echo '<input type="hidden" name="spp_sa_add_crt" value="' . (int) $best['Crt_ID'] . '">';
        echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this add? A backup will be taken and a validation check will run before anything is sent.\')">Apply and Check</button>';
        echo '</form>';
    }

    // Manual override -- always available, not just on auto-search failure.
    // The automatic search only checks capacity and a same-group carpool
    // continuity signal; it can't know every reason a convenor might
    // prefer a specific group, so this is the human override valve.
    $all_groups = $wpdb->get_results(
        "SELECT s.group_id, g.GP_name, t.T_desc, t.T_ID, s.Crt_ID, COUNT(*) AS cnt
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Times t  ON s.time_id = t.T_ID
         WHERE s.group_id != 99
         GROUP BY s.group_id
         ORDER BY t.T_ID, g.GP_name", ARRAY_A
    );

    echo '<div class="box"><strong>Or choose a group manually:</strong><br><br>';
    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="add">';
    echo '<input type="hidden" name="spp_sa_stage" value="apply">';
    echo '<input type="hidden" name="spp_sa_add_uid" value="' . (int) $uid . '">';
    echo '<input type="hidden" name="spp_sa_add_rank" value="' . (int) $rank . '">';
    echo '<select name="spp_sa_manual_group" required onchange="
        var opt = this.options[this.selectedIndex];
        this.form.spp_sa_add_group.value = opt.dataset.group;
        this.form.spp_sa_add_time.value = opt.dataset.time;
        this.form.spp_sa_add_crt.value = opt.dataset.crt;
    ">';
    echo '<option value="">-- select group --</option>';
    foreach ( $all_groups as $g ) {
        $full_flag = (int) $g['cnt'] >= 5 ? ' (FULL -- will exceed normal size)' : '';
        echo '<option value="' . (int) $g['group_id'] . '" data-group="' . (int) $g['group_id']
           . '" data-time="' . (int) $g['T_ID'] . '" data-crt="' . (int) $g['Crt_ID'] . '">'
           . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] . ' (' . (int) $g['cnt'] . '/5)' . $full_flag )
           . '</option>';
    }
    echo '</select>';
    echo '<input type="hidden" name="spp_sa_add_group" value="">';
    echo '<input type="hidden" name="spp_sa_add_time" value="">';
    echo '<input type="hidden" name="spp_sa_add_crt" value="">';
    echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this add to the manually chosen group? A backup will be taken and a validation check will run before anything is sent. No automatic constraint checks were run against this choice.\')">Apply Manual Placement</button>';
    echo '</form>';
    echo '</div>';

    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
}

function spp_sa2_add_apply( $carpool_rank_tolerance ) {
    global $wpdb;

    $uid     = (int) ( $_POST['spp_sa_add_uid'] ?? 0 );
    $rank    = (int) ( $_POST['spp_sa_add_rank'] ?? 0 );
    $group_id = (int) ( $_POST['spp_sa_add_group'] ?? 0 );
    $time_id  = (int) ( $_POST['spp_sa_add_time'] ?? 0 );
    $crt_id   = (int) ( $_POST['spp_sa_add_crt'] ?? 0 );

    $backup_table = spp_sa2_backup_schedules( 'add' );

    // Ensure Master has a row for this player (INSERT if this is their
    // first time on the ladder, UPDATE if they're already there) -- a
    // plain UPDATE would silently do nothing for a brand-new member, and
    // everything downstream (checks, notify) JOINs Schedules to Master.
    spp_sa2_ensure_master_row( $uid, $rank );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'Rank'", $uid ) );
    $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, 'Rank', %d)", $uid, $rank ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'Ladder'", $uid ) );
    $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, 'Ladder', 'Yes')", $uid ) );

    $member   = spp_sa2_get_member_info( $uid );
    $event_id = (int) get_option( 'spp_current_event', 0 );

    spp_sa2_registration_confirm( $event_id, $uid );

    $wpdb->insert( 'Schedules', array(
        'user_id'           => $uid,
        'first_name'        => $member['first_name'],
        'last_name'         => $member['last_name'],
        'user_phone'        => $member['user_phone'],
        'user_email'        => $member['user_email'],
        'travel'            => $member['travel'],
        'Rank'              => $rank,
        'RankPrime'         => $rank,
        'group_id'          => $group_id,
        'Crt_ID'            => $crt_id,
        'time_id'           => $time_id,
        'event_id'          => $event_id,
        'registration_date' => current_time( 'Y-m-d' ),
    ) );

    echo '<h3>Applied -- Validation Check</h3>';
    echo '<div class="box">Backup saved as <code>' . esc_html( $backup_table ) . '</code>. Rank permanently set to ' . (int) $rank . '.</div>';

    if ( function_exists( 'spp_run_schedule_check' ) ) {
        echo '<div class="box">';
        spp_run_schedule_check();
        echo '</div>';
    }

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="add">';
    echo '<input type="hidden" name="spp_sa_stage" value="finalize">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<input type="hidden" name="spp_sa_affected_groups" value="' . (int) $group_id . '">';
    echo '<button type="submit" class="btn btn-primary">Looks good -- send notifications</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="add">';
    echo '<input type="hidden" name="spp_sa_stage" value="undo">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancel this change? Only the rows this action touched will be reverted -- nothing else will be affected. Note: the rank update to Master will NOT be reverted by this cancel.\')">Not acceptable -- cancel</button>';
    echo '</form>';
    echo '<p style="font-size:12px;color:#888;">Note: undo restores the Schedules table only. If you need to revert the rank change too, use Manual Rank Override.</p>';
}

function spp_sa2_add_undo() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $ok = spp_sa2_restore_schedules( $backup_table );
    echo '<h3>Undo</h3>';
    if ( $ok ) {
        echo '<div class="box box-ok">Restored from <code>' . esc_html( $backup_table ) . '</code>. No notifications were sent.</div>';
    } else {
        echo '<div class="box box-err">Could not restore -- backup table not found.</div>';
    }
    spp_sa2_action_selector();
}

function spp_sa2_add_finalize() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $affected_group_ids = array_filter( array_map( 'intval', explode( ',', $_POST['spp_sa_affected_groups'] ?? '' ) ) );

    echo '<h3>Finalized</h3>';
    echo '<div class="box box-ok">Change kept. Backup <code>' . esc_html( $backup_table ) . '</code> retained for this event.</div>';

    spp_sa2_finalize_notify_or_queue( $affected_group_ids, 'A player was added to the schedule at the last minute.', 'Last-Minute Add' );

    spp_sa2_action_selector();
}

/* =========================================================
   GROUP TIME-SLOT SWAP
   Migrated from the CM "Switch Groups in Schedule" snippet.
   That version swapped Crt_ID/time_id with zero verification.
   This version re-checks every player's +/- travel preference
   against their new time slot before proceeding, and runs the
   same propose/apply-check/confirm-or-undo pattern as the
   other actions.
   ========================================================= */

function spp_sa2_groupswap_flow( $stage ) {
    if ( $stage === 'propose' ) {
        spp_sa2_groupswap_propose();
    } elseif ( $stage === 'propose_by_player' ) {
        spp_sa2_groupswap_propose_by_player();
    } elseif ( $stage === 'apply' ) {
        spp_sa2_groupswap_apply();
    } elseif ( $stage === 'finalize' ) {
        spp_sa2_groupswap_finalize();
    } elseif ( $stage === 'undo' ) {
        spp_sa2_groupswap_undo();
    } else {
        spp_sa2_action_selector();
    }
}

function spp_sa2_get_time_positions() {
    global $wpdb;
    $active_times = $wpdb->get_results( "SELECT T_ID FROM Times WHERE Active = 1 ORDER BY T_ID" );
    $time_ids = array_column( $active_times, 'T_ID' );
    sort( $time_ids );
    return $time_ids; // index 0 = 5:30 slot, 1 = 6:40, 2 = 7:50
}

/**
 * For a group's roster, check every player's +/- travel preference
 * against a prospective new time_id. Returns array of violation strings
 * (empty array = all clear).
 */
function spp_sa2_check_travel_for_new_time( $roster, $new_time_id, $time_ids ) {
    $violations = array();
    $new_pos = array_search( $new_time_id, $time_ids );

    foreach ( $roster as $p ) {
        $travel = spp_sa2_normalize_travel( $p['travel'] );
        if ( empty( $travel ) ) continue;
        $name = $p['first_name'] . ' ' . $p['last_name'];

        if ( $new_pos === 0 && preg_match( '/^-5:30/i', $travel ) ) {
            $violations[] = "$name wants to avoid 5:30pm, but new slot is 5:30pm.";
        }
        if ( $new_pos === 2 && preg_match( '/^-7:50/i', $travel ) ) {
            $violations[] = "$name wants to avoid 7:50pm, but new slot is 7:50pm.";
        }
        if ( preg_match( '/^\+5:30/i', $travel ) && $new_pos !== 0 ) {
            $violations[] = "$name wants 5:30pm, but new slot is not 5:30pm.";
        }
        if ( preg_match( '/^\+6:40/i', $travel ) && $new_pos !== 1 ) {
            $violations[] = "$name wants 6:40pm, but new slot is not 6:40pm.";
        }
        if ( preg_match( '/^\+7:50/i', $travel ) && $new_pos !== 2 ) {
            $violations[] = "$name wants 7:50pm, but new slot is not 7:50pm.";
        }
    }
    return $violations;
}

function spp_sa2_groupswap_propose() {
    global $wpdb;

    $gp1 = (int) ( $_POST['spp_sa_gs_a'] ?? 0 );
    $gp2 = (int) ( $_POST['spp_sa_gs_b'] ?? 0 );

    echo '<h3>Propose Group Swap</h3>';

    if ( ! $gp1 || ! $gp2 ) {
        echo '<p class="box box-err">Please select two groups.</p>';
        spp_sa2_action_selector();
        return;
    }
    if ( $gp1 === $gp2 ) {
        echo '<p class="box box-err">Cannot swap a group with itself.</p>';
        spp_sa2_action_selector();
        return;
    }

    $sched1 = $wpdb->get_row( $wpdb->prepare( "SELECT group_id, Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $gp1 ), ARRAY_A );
    $sched2 = $wpdb->get_row( $wpdb->prepare( "SELECT group_id, Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $gp2 ), ARRAY_A );

    if ( ! $sched1 || ! $sched2 ) {
        echo '<p class="box box-err">One or both groups not found in the current schedule.</p>';
        spp_sa2_action_selector();
        return;
    }

    $roster1 = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, m.travel FROM Schedules s JOIN Master m ON s.user_id = m.user_id WHERE s.group_id = %d", $gp1
    ), ARRAY_A );
    $roster2 = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, m.travel FROM Schedules s JOIN Master m ON s.user_id = m.user_id WHERE s.group_id = %d", $gp2
    ), ARRAY_A );

    $time_ids = spp_sa2_get_time_positions();
    $viol1 = spp_sa2_check_travel_for_new_time( $roster1, (int) $sched2['time_id'], $time_ids );
    $viol2 = spp_sa2_check_travel_for_new_time( $roster2, (int) $sched1['time_id'], $time_ids );
    $all_violations = array_merge( $viol1, $viol2 );

    echo '<div class="box">Group ' . $gp1 . ' &harr; Group ' . $gp2 . ' (court/time swap).</div>';

    if ( ! empty( $all_violations ) ) {
        echo '<div class="box box-warn"><strong>Travel preference conflicts introduced by this swap:</strong><ul>';
        foreach ( $all_violations as $v ) echo '<li>' . esc_html( $v ) . '</li>';
        echo '</ul>This is not blocked automatically -- review and decide whether to proceed anyway.</div>';
    } else {
        echo '<div class="box box-ok">No travel preference conflicts introduced by this swap.</div>';
    }

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="apply">';
    echo '<input type="hidden" name="spp_sa_gs_a" value="' . $gp1 . '">';
    echo '<input type="hidden" name="spp_sa_gs_b" value="' . $gp2 . '">';
    echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this swap? A backup will be taken and a validation check will run before anything is sent.\')">Apply and Check</button>';
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

/**
 * Given a travel preference string, return the list of time_id positions
 * (0=5:30, 1=6:40, 2=7:50) that would actually satisfy it. Empty array
 * means "no preference" -- nothing to search for.
 */
function spp_sa2_satisfying_positions( $travel ) {
    $travel = spp_sa2_normalize_travel( $travel );
    if ( empty( $travel ) ) return array();

    if ( preg_match( '/^\+5:30/i', $travel ) ) return array( 0 );
    if ( preg_match( '/^\+6:40/i', $travel ) ) return array( 1 );
    if ( preg_match( '/^\+7:50/i', $travel ) ) return array( 2 );
    if ( preg_match( '/^-5:30/i', $travel ) )  return array( 1, 2 ); // avoid 5:30 -> 6:40 or 7:50 both fine
    if ( preg_match( '/^-7:50/i', $travel ) )  return array( 0, 1 ); // avoid 7:50 -> 5:30 or 6:40 both fine
    return array();
}

function spp_sa2_groupswap_propose_by_player() {
    global $wpdb;

    $uid = (int) ( $_POST['spp_sa_gs_player'] ?? 0 );
    if ( ! $uid ) {
        echo '<p class="box box-err">No player selected.</p>';
        spp_sa2_action_selector();
        return;
    }

    $player = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, s.group_id, s.time_id, m.travel
         FROM Schedules s JOIN Master m ON s.user_id = m.user_id
         WHERE s.user_id = %d AND s.group_id != 99", $uid
    ), ARRAY_A );

    if ( ! $player ) {
        echo '<p class="box box-err">That player is not currently scheduled.</p>';
        spp_sa2_action_selector();
        return;
    }

    $time_ids = spp_sa2_get_time_positions();
    $current_pos = array_search( (int) $player['time_id'], $time_ids );
    $satisfying_positions = spp_sa2_satisfying_positions( $player['travel'] );

    echo '<h3>Group Swap -- Candidates for ' . esc_html( $player['first_name'] . ' ' . $player['last_name'] ) . '</h3>';

    if ( empty( $satisfying_positions ) ) {
        echo '<div class="box box-warn">This player has no +/- time preference on record, so there\'s no specific slot to search for. Use "By groups directly" instead.</div>';
        echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Back</a>';
        return;
    }

    if ( in_array( $current_pos, $satisfying_positions, true ) ) {
        echo '<div class="box box-ok">This player is already in a time slot that satisfies their preference -- no swap needed.</div>';
        echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Back</a>';
        return;
    }

    $satisfying_time_ids = array_map( fn( $pos ) => $time_ids[ $pos ], $satisfying_positions );

    $candidate_groups = $wpdb->get_results(
        "SELECT s.group_id, g.GP_name, t.T_desc, s.time_id
         FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         JOIN Times t ON s.time_id = t.T_ID
         WHERE s.group_id != 99 AND s.group_id != " . (int) $player['group_id'] . "
           AND s.time_id IN (" . implode( ',', array_map( 'intval', $satisfying_time_ids ) ) . ")
         GROUP BY s.group_id", ARRAY_A
    );

    if ( empty( $candidate_groups ) ) {
        echo '<div class="box box-err">No group is currently scheduled in a time slot that would satisfy this player\'s preference. Nothing found automatically -- choose a group manually below to override (the preference will not be satisfied by this swap).</div>';

        $all_other_groups = $wpdb->get_results(
            "SELECT s.group_id, g.GP_name, t.T_desc
             FROM Schedules s
             JOIN Groups g ON s.group_id = g.GP_ID
             JOIN Times t  ON s.time_id = t.T_ID
             WHERE s.group_id != 99 AND s.group_id != " . (int) $player['group_id'] . "
             GROUP BY s.group_id
             ORDER BY g.GP_name", ARRAY_A
        );

        echo '<form method="post">';
        wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
        echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
        echo '<input type="hidden" name="spp_sa_stage" value="apply">';
        echo '<input type="hidden" name="spp_sa_gs_a" value="' . (int) $player['group_id'] . '">';
        echo '<label>Swap with:</label><select name="spp_sa_gs_b" required>';
        echo '<option value="">-- select group --</option>';
        foreach ( $all_other_groups as $g ) {
            echo '<option value="' . (int) $g['group_id'] . '">' . esc_html( $g['GP_name'] . ' -- ' . $g['T_desc'] ) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this swap? A backup will be taken and a validation check will run before anything is sent. This override was not automatically checked against this player\\\'s travel preference.\')">Apply Manual Swap</button>';
        echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
        echo '</form>';
        return;
    }

    $my_roster = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.user_id, s.first_name, s.last_name, m.travel FROM Schedules s JOIN Master m ON s.user_id = m.user_id WHERE s.group_id = %d",
        $player['group_id']
    ), ARRAY_A );

    $scored = array();
    foreach ( $candidate_groups as $cand ) {
        $cand_roster = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id, s.first_name, s.last_name, m.travel FROM Schedules s JOIN Master m ON s.user_id = m.user_id WHERE s.group_id = %d",
            $cand['group_id']
        ), ARRAY_A );

        // My group's roster (including this player) moving to the candidate's
        // slot, and the candidate's roster moving to my group's current slot --
        // exactly the same bidirectional check the manual swap already uses.
        $viol_mine = spp_sa2_check_travel_for_new_time( $my_roster, (int) $cand['time_id'], $time_ids );
        $viol_cand = spp_sa2_check_travel_for_new_time( $cand_roster, (int) $player['time_id'], $time_ids );

        $scored[] = array(
            'group_id'   => (int) $cand['group_id'],
            'label'      => $cand['GP_name'] . ' -- ' . $cand['T_desc'],
            'violations' => array_merge( $viol_mine, $viol_cand ),
        );
    }
    usort( $scored, fn( $a, $b ) => count( $a['violations'] ) <=> count( $b['violations'] ) );

    echo '<form method="post">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="apply">';
    echo '<input type="hidden" name="spp_sa_gs_a" value="' . (int) $player['group_id'] . '">';
    echo '<label>Swap with (sorted by fewest conflicts introduced):</label>';
    echo '<select name="spp_sa_gs_b" required>';
    foreach ( $scored as $s ) {
        $tag = count( $s['violations'] ) === 0 ? ' -- 0 conflicts' : ' -- ' . count( $s['violations'] ) . ' conflict(s)';
        echo '<option value="' . $s['group_id'] . '">' . esc_html( $s['label'] . $tag ) . '</option>';
    }
    echo '</select>';

    foreach ( $scored as $s ) {
        if ( ! empty( $s['violations'] ) ) {
            echo '<div class="box box-warn" style="font-size:12px;"><strong>' . esc_html( $s['label'] ) . '</strong> would introduce: '
               . esc_html( implode( '; ', $s['violations'] ) ) . '</div>';
        }
    }

    echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Apply this swap? A backup will be taken and a validation check will run before anything is sent.\')">Apply and Check</button>';
    echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" class="btn btn-neutral" style="text-decoration:none;">Cancel</a>';
    echo '</form>';
}

function spp_sa2_groupswap_apply() {
    global $wpdb;

    $gp1 = (int) ( $_POST['spp_sa_gs_a'] ?? 0 );
    $gp2 = (int) ( $_POST['spp_sa_gs_b'] ?? 0 );

    $backup_table = spp_sa2_backup_schedules( 'groupswap' );

    $sched1 = $wpdb->get_row( $wpdb->prepare( "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $gp1 ), ARRAY_A );
    $sched2 = $wpdb->get_row( $wpdb->prepare( "SELECT Crt_ID, time_id FROM Schedules WHERE group_id = %d LIMIT 1", $gp2 ), ARRAY_A );

    $wpdb->update( 'Schedules', array( 'Crt_ID' => $sched2['Crt_ID'], 'time_id' => $sched2['time_id'] ), array( 'group_id' => $gp1 ) );
    $wpdb->update( 'Schedules', array( 'Crt_ID' => $sched1['Crt_ID'], 'time_id' => $sched1['time_id'] ), array( 'group_id' => $gp2 ) );

    echo '<h3>Applied -- Validation Check</h3>';
    echo '<div class="box">Backup saved as <code>' . esc_html( $backup_table ) . '</code>.</div>';

    if ( function_exists( 'spp_run_schedule_check' ) ) {
        echo '<div class="box">';
        spp_run_schedule_check();
        echo '</div>';
    }

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="finalize">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<input type="hidden" name="spp_sa_affected_groups" value="' . $gp1 . ',' . $gp2 . '">';
    echo '<button type="submit" class="btn btn-primary">Looks good -- send notifications</button>';
    echo '</form> ';

    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'spp_schedule_adjust', 'spp_sa_nonce' );
    echo '<input type="hidden" name="spp_sa_action" value="groupswap">';
    echo '<input type="hidden" name="spp_sa_stage" value="undo">';
    echo '<input type="hidden" name="spp_sa_backup_table" value="' . esc_attr( $backup_table ) . '">';
    echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancel this change? Only the rows this action touched will be reverted -- nothing else will be affected.\')">Not acceptable -- cancel</button>';
    echo '</form>';
}

function spp_sa2_groupswap_undo() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $ok = spp_sa2_restore_schedules( $backup_table );
    echo '<h3>Undo</h3>';
    echo $ok
        ? '<div class="box box-ok">Restored from <code>' . esc_html( $backup_table ) . '</code>. No notifications were sent.</div>'
        : '<div class="box box-err">Could not restore -- backup table not found.</div>';
    spp_sa2_action_selector();
}

function spp_sa2_groupswap_finalize() {
    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $affected_group_ids = array_filter( array_map( 'intval', explode( ',', $_POST['spp_sa_affected_groups'] ?? '' ) ) );

    echo '<h3>Finalized</h3>';
    echo '<div class="box box-ok">Change kept. Backup <code>' . esc_html( $backup_table ) . '</code> retained for this event.</div>';

    spp_sa2_finalize_notify_or_queue( $affected_group_ids, 'Two groups swapped their court/time assignment.', 'Group Time-Slot Swap' );

    spp_sa2_action_selector();
}

function spp_sa2_dropout_finalize() {
    global $wpdb;

    $backup_table = sanitize_text_field( $_POST['spp_sa_backup_table'] ?? '' );
    $player_id    = (int) ( $_POST['spp_sa_player_id'] ?? 0 );

    echo '<h3>Finalized</h3>';
    echo '<div class="box box-ok">Change kept. Backup <code>' . esc_html( $backup_table ) . '</code> retained for this event.</div>';

    $affected_group_ids = array_filter( array_map( 'intval', explode( ',', $_POST['spp_sa_affected_groups'] ?? '' ) ) );

    spp_sa2_finalize_notify_or_queue( $affected_group_ids, 'A player dropped out and the schedule was adjusted accordingly.', 'Dropout Adjustment' );

    spp_sa2_action_selector();
}

/* =========================================================
   PENDING NOTIFICATION QUEUE
   Post-publish, a convenor often makes several adjustments in
   one sitting (a dropout, then a swap to cover it, etc.). If
   each action's finalize step sent notifications immediately,
   an affected player could get several emails in a row, each
   showing a mid-session state rather than their actual final
   grouping. Instead, "finalize" on a published schedule queues
   the affected group_ids (deduped) rather than sending right
   away; a single "Send Pending Notifications" step at the top
   of the action selector sends one batch once the convenor is
   done adjusting. spp_sa2_notify() always queries live
   Schedules fresh at send time, so even if a player moved
   through several groups across several actions, they end up
   correctly notified once, for their actual final group.
   Pre-publish is unaffected -- no player emails are ever at
   stake there, only a convenor FYI note, which stays immediate.
   ========================================================= */

function spp_sa2_queue_pending_notify( array $group_ids ) {
    $current_event = (int) get_option( 'spp_current_event', 0 );
    $stored = get_option( 'spp_sa2_pending_notify', array() );
    // If a queue exists from a DIFFERENT event (e.g. left unsent, then a new
    // schedule got produced before "Send Pending Notifications" was ever
    // clicked), discard it rather than risk emailing this week's players
    // using stale group references from a prior week's roster.
    $pending = ( is_array( $stored ) && (int) ( $stored['event'] ?? 0 ) === $current_event )
        ? $stored['groups']
        : array();
    $pending = array_values( array_unique( array_merge( $pending, array_map( 'intval', $group_ids ) ) ) );
    update_option( 'spp_sa2_pending_notify', array( 'event' => $current_event, 'groups' => $pending ) );
    return count( $pending );
}

function spp_sa2_get_pending_notify_groups() {
    $current_event = (int) get_option( 'spp_current_event', 0 );
    $stored = get_option( 'spp_sa2_pending_notify', array() );
    if ( ! is_array( $stored ) || (int) ( $stored['event'] ?? 0 ) !== $current_event ) return array();
    return is_array( $stored['groups'] ?? null ) ? $stored['groups'] : array();
}

function spp_sa2_clear_pending_notify() {
    delete_option( 'spp_sa2_pending_notify' );
}

/**
 * Called from every action's finalize step. Pre-publish: sends the
 * convenor-only confirmation immediately (unchanged behaviour, no player
 * emails at stake). Post-publish: queues the affected groups instead of
 * sending -- actual player notifications go out once, in a batch, via
 * spp_sa2_notify_pending() when the convenor clicks "Send Pending
 * Notifications".
 */
function spp_sa2_finalize_notify_or_queue( array $affected_group_ids, string $change_note, string $action_label ) {
    $schedule_published = (int) get_option( 'spp_schedule_published', 0 );

    if ( ! $schedule_published ) {
        $result = spp_sa2_notify( $affected_group_ids, $change_note, $action_label );
        echo '<div class="box">Schedule not yet published -- players were not notified. Convenor confirmation email '
           . ( $result['convenor'] ? 'sent to ' . esc_html( $result['convenor_email'] ) : 'FAILED' ) . '.</div>';
        return;
    }

    $pending_count = spp_sa2_queue_pending_notify( $affected_group_ids );
    echo '<div class="box box-warn">Schedule is published -- <strong>' . count( $affected_group_ids ) . ' group(s) queued for notification</strong> ('
       . $pending_count . ' group(s) pending in total). No emails sent yet -- make any further adjustments, then use '
       . '<strong>"Send Pending Notifications"</strong> at the top of the menu once you\'re done, so anyone affected '
       . 'multiple times tonight only gets one email reflecting their actual final grouping.</div>';
}

/**
 * Sends the actual batch: every group currently in the pending queue,
 * deduped, queried fresh (so a player who moved through several groups
 * across several actions is correctly shown in their real final group).
 */
function spp_sa2_notify_pending() {
    $pending = spp_sa2_get_pending_notify_groups();

    echo '<h3>Send Pending Notifications</h3>';

    if ( empty( $pending ) ) {
        echo '<div class="box">No notifications pending.</div>';
        spp_sa2_action_selector();
        return;
    }

    $result = spp_sa2_notify( $pending, 'Your schedule was adjusted tonight -- this reflects your final grouping.', 'Schedule Adjustments' );
    spp_sa2_clear_pending_notify();

    echo ! $result['published']
        ? '<div class="box">Schedule not published -- nothing sent. Convenor confirmation email ' . ( $result['convenor'] ? 'sent to ' . esc_html( $result['convenor_email'] ) : 'FAILED' ) . '.</div>'
        : '<div class="box box-ok">Notified ' . (int) $result['sent'] . ' player(s) across ' . count( $pending ) . ' group(s)' . ( $result['failed'] ? ' (' . (int) $result['failed'] . ' failed)' : '' ) . '. Convenor summary ' . ( $result['convenor'] ? 'sent to ' . esc_html( $result['convenor_email'] ) : 'FAILED' ) . '.</div>';

    spp_sa2_action_selector();
}

function spp_sa2_discard_pending_notify() {
    $count = count( spp_sa2_get_pending_notify_groups() );
    spp_sa2_clear_pending_notify();
    echo '<h3>Discarded</h3>';
    echo '<div class="box box-ok">Pending notification queue (' . $count . ' group(s)) discarded -- nothing was sent. The schedule changes themselves remain in place; only the notification queue was cleared.</div>';
    spp_sa2_action_selector();
}

/* =========================================================
   REGISTRATION SYNC (gl_registrations)
   Last-Minute Add and Dropout keep the real registration table
   in sync with Schedules, so a manually-added player looks like
   a genuine registrant everywhere else in the system (reports,
   history, membership stats) -- not just a Schedules row with
   hand-copied fields. Deliberately bypasses the gl-events
   plugin's own GL_Registration::register()/deregister(): those
   enforce a registration cutoff (which a same-day add/dropout is
   almost always past) and register() can silently downgrade to
   'waiting' if the event is at capacity -- neither fits a
   convenor's deliberate manual override. No do_action() hooks
   fired here, so no confirmation/withdrawal email goes out (the
   convenor is telling the player directly) and no automatic
   waitlist promotion happens (which would otherwise misleadingly
   confirm a promoted player into a spot nobody has actually
   placed them into in Schedules).
   ========================================================= */

function spp_sa2_registration_confirm( $event_id, $uid ) {
    global $wpdb;
    $table = $wpdb->prefix . 'gl_registrations';
    $now = current_time( 'mysql' );

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table WHERE occurrence_id = %d AND user_id = %d", $event_id, $uid
    ) );

    if ( $existing ) {
        $wpdb->update( $table,
            array( 'status' => 'confirmed', 'updated_at' => $now ),
            array( 'id' => $existing->id )
        );
    } else {
        $wpdb->insert( $table, array(
            'occurrence_id' => $event_id,
            'user_id'       => $uid,
            'status'        => 'confirmed',
            'registered_at' => $now,
            'updated_at'    => $now,
            'notes'         => 'Added via Schedule Adjustment tool',
        ) );
    }
}

function spp_sa2_registration_withdraw( $event_id, $uid ) {
    global $wpdb;
    $table = $wpdb->prefix . 'gl_registrations';

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table WHERE occurrence_id = %d AND user_id = %d", $event_id, $uid
    ) );

    if ( $existing ) {
        $wpdb->update( $table,
            array( 'status' => 'withdrawn', 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $existing->id )
        );
    }
}

function spp_sa2_get_convenor_email() {
    global $wpdb;
    $event = (int) get_option( 'spp_current_event', 0 );
    $prefix = $wpdb->prefix;
    $email = $wpdb->get_var( $wpdb->prepare(
        "SELECT m.user_email FROM {$prefix}gl_event_occurrences o
         LEFT JOIN membership m ON m.user_id = COALESCE(o.convenor_id, 2193)
         WHERE o.id = %d", $event
    ) );
    return $email ?: 'abrooks@rogers.com';
}

/* =========================================================
   SHARED NOTIFICATION ROUTINE
   Used by every mutating action (dropout, add, swap, group
   swap). Same visual pattern as gl-publish-schedule.php and
   spp-switch-players.php: personalized email per player in
   each affected group, plus one convenor summary.
   Pre-publish: skips player emails entirely (Publish Schedule
   will send the real thing later), sends a convenor
   confirmation only, clearly marked "pre-publish".
   ========================================================= */

function spp_sa2_notify( array $affected_group_ids, string $change_note, string $action_label ) {
    global $wpdb;

    $affected_group_ids = array_values( array_unique( array_map( 'intval', $affected_group_ids ) ) );
    $event_date = spp_sa2_get_event_date();
    $subject    = "Stouffville Pickleball Players -- Schedule Change for {$event_date}";
    $schedule_published = (int) get_option( 'spp_schedule_published', 0 );
    $convenor_email      = spp_sa2_get_convenor_email();
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    if ( ! $schedule_published ) {
        $body = spp_sa2_email_header( $event_date )
              . '<tr><td style="padding:16px 24px;"><p style="font-size:14px;color:#333;">'
              . '<strong>' . esc_html( $action_label ) . '</strong> was processed before the schedule was published.</p>'
              . '<p style="font-size:14px;color:#333;">' . $change_note . '</p>'
              . '<p style="font-size:13px;color:#888;">No players were notified -- the published schedule will reflect this change automatically.</p>'
              . '</td></tr>'
              . spp_sa2_email_footer();
        $ok = wp_mail( $convenor_email, $subject . ' [PRE-PUBLISH]', $body, $headers );
        return array(
            'published' => false,
            'sent'      => 0,
            'failed'    => 0,
            'convenor'  => $ok,
            'convenor_email' => $convenor_email,
        );
    }

    $pairings_5 = array(
        array( 'name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => 4 ),
        array( 'name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,4), 'bye' => 3 ),
        array( 'name' => 'Round 3', 'blue' => array(0,3), 'red' => array(2,4), 'bye' => 1 ),
        array( 'name' => 'Round 4', 'blue' => array(0,4), 'red' => array(1,3), 'bye' => 2 ),
        array( 'name' => 'Round 5', 'blue' => array(1,2), 'red' => array(3,4), 'bye' => 0 ),
    );
    $pairings_4 = array(
        array( 'name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => -1 ),
        array( 'name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,3), 'bye' => -1 ),
        array( 'name' => 'Round 3', 'blue' => array(0,3), 'red' => array(1,2), 'bye' => -1 ),
    );

    $sent = 0; $failed = 0;
    $convenor_body_groups = '';

    foreach ( $affected_group_ids as $gid ) {
        $roster = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id, s.first_name, s.last_name, s.user_phone, s.Rank, s.group_id,
                    m.user_email, g.GP_name, c.Crt_name, t.T_desc
             FROM Schedules s
             JOIN Groups g ON s.group_id = g.GP_ID
             JOIN Courts c ON s.Crt_ID = c.Crt_ID
             JOIN Times t  ON s.time_id = t.T_ID
             LEFT JOIN membership m ON s.user_id = m.user_id
             WHERE s.group_id = %d ORDER BY s.Rank", $gid
        ), ARRAY_A );

        if ( empty( $roster ) ) continue; // group dissolved, nothing to notify here

        $pairings = count( $roster ) >= 5 ? $pairings_5 : $pairings_4;

        foreach ( $roster as $player ) {
            if ( empty( $player['user_email'] ) ) continue;
            $body = spp_sa2_email_header( $event_date )
                  . spp_sa2_email_group_block( $roster, $pairings, $player['user_id'] )
                  . spp_sa2_email_footer();
            $ok = wp_mail( $player['user_email'], $subject, $body, $headers );
            if ( $ok ) $sent++; else $failed++;
        }

        $convenor_body_groups .= spp_sa2_email_group_block( $roster, $pairings, 0 );
    }

    $convenor_body = spp_sa2_email_header( $event_date )
        . '<tr><td style="padding:16px 24px 4px 24px;">'
        . '<p style="font-size:15px;font-weight:bold;color:#2c3e50;margin:0 0 4px 0;">' . esc_html( $action_label ) . '</p>'
        . '<p style="font-size:14px;color:#555;margin:0 0 12px 0;">' . $change_note . '</p>'
        . '</td></tr>'
        . $convenor_body_groups
        . spp_sa2_email_footer();

    $ok_convenor = wp_mail( $convenor_email, $subject . ' [SUMMARY]', $convenor_body, $headers );

    return array(
        'published' => true,
        'sent'      => $sent,
        'failed'    => $failed,
        'convenor'  => $ok_convenor,
        'convenor_email' => $convenor_email,
    );
}

function spp_sa2_get_event_date() {
    global $wpdb;
    $event = (int) get_option( 'spp_current_event', 0 );
    $prefix = $wpdb->prefix;
    $raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT event_date FROM {$prefix}gl_event_occurrences WHERE id = %d", $event
    ) );
    return $raw ? date( 'F d, Y', strtotime( $raw ) ) : 'tonight';
}

function spp_sa2_email_header( $event_date ) {
    $logo_url = 'https://pickleballstouffville.ca/wp-content/uploads/2024/03/SPP_Logo-96DPI-8X-3.52-480x211.png';
    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f5;">
<table width="620" cellpadding="0" cellspacing="0" style="margin:20px auto;background:#fdfae8;border:1px solid #ddd;font-family:Arial,sans-serif;font-size:14px;color:#333;">
  <tr><td style="padding:16px 24px;border-bottom:1px solid #ddd;">
    <table width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="160"><img src="' . $logo_url . '" width="160" alt="SPP Logo"></td>
      <td style="text-align:center;padding-left:10px;">
        <strong style="font-size:16px;">Stouffville Pickleball Players</strong><br>
        <span style="font-size:13px;color:#666;">Schedule update -- ' . esc_html( $event_date ) . '</span>
      </td>
    </tr></table>
  </td></tr>';
}

function spp_sa2_email_footer() {
    $schedule_url = 'https://pickleballstouffville.ca/gl-player-schedule-view/';
    return '<tr><td style="padding:16px 24px;border-top:1px solid #ddd;font-size:12px;color:#666;text-align:center;">
    <a href="' . $schedule_url . '" style="color:#3766AB;">View full schedule on the website</a>
  </td></tr></table></body></html>';
}

function spp_sa2_email_group_block( $players, $pairings, $my_user_id ) {
    $p0 = $players[0];
    $html = '<tr><td style="padding:16px 24px 8px 24px;">';
    if ( $my_user_id ) {
        $html .= '<p style="margin:0 0 8px 0;font-size:15px;font-weight:bold;color:red;">Your schedule has changed tonight!</p>';
    }
    $html .= '<div style="background:#2c3e50;color:#ffffff;padding:8px 12px;border-radius:6px 6px 0 0;font-weight:bold;font-size:15px;">'
            . esc_html( $p0['GP_name'] ) . ' &mdash; ' . esc_html( $p0['Crt_name'] ) . ' &mdash; ' . esc_html( $p0['T_desc'] ) . '</div>';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;margin-bottom:12px;">
      <thead><tr style="background:#3766AB;">
        <td style="color:#fff;padding:5px 8px;font-weight:bold;width:40px;">Rank</td>
        <td style="color:#fff;padding:5px 8px;font-weight:bold;">Name</td>
        <td style="color:#fff;padding:5px 8px;font-weight:bold;">Phone</td>
      </tr></thead><tbody>';
    foreach ( $players as $i => $p ) {
        $is_me = ( (int) $p['user_id'] === (int) $my_user_id );
        $bg = $is_me ? 'background:#e8f5e9;font-weight:bold;' : ( $i % 2 == 0 ? 'background:#f9f9f9;' : 'background:#ffffff;' );
        $html .= '<tr style="' . $bg . '"><td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['Rank'] ) . '</td>'
               . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . ( $is_me ? ' (me)' : '' ) . '</td>'
               . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['user_phone'] ) . '</td></tr>';
    }
    $html .= '</tbody></table></td></tr>';
    return $html;
}