<?php
/* =========================================================
   Ace/Queen of the Courts — Pre-Round-1 Check-in
   Version: 1.0.0
   Date: 2026-09-14

   PURPOSE:
   Tracks which confirmed registrants have actually shown up, pre-
   Round-1 only, via spp_kq_checkins (inc/spp-kq-schema.php 1.4.0) --
   row existence means "checked in," absence means "not yet arrived."
   Deliberately separate from gl_registrations (still the sole source
   of "confirmed to attend," owned by inc/spp-kq-roster.php's add/
   remove): a player can be confirmed but not checked in.

   FLOW (see spp_kq_render_start_screen(), inc/spp-kq-screens.php):
     1. Every confirmed registrant starts unchecked. A facilitator
        marks each as they arrive ('checkin_mark'/'checkin_unmark' in
        spp_kq_handle_post_actions(), inc/spp-kq-screens.php) --
        reversible any number of times, in any order, interleaved
        freely with Roster Adjust (?kq_view=roster) adding a late
        arrival or removing someone.
     2. The Start screen's own "Cannot start" validation (4-16,
        multiple of 4) is evaluated against the CHECKED-IN count, not
        the raw confirmed count -- see that function.
     3. At the moment "Start Round 1 Draw" is actually pressed (the
        'start_round1' case), every confirmed registrant who is NOT
        checked in is withdrawn via spp_kq_roster_remove() -- the
        exact same call Roster Adjust's own Remove button uses -- then
        spp_kq_transition_start_round1() runs completely unchanged,
        re-validating spp_kq_confirmed_count() as it always has. No
        new validation logic was added to that function: reconciling
        registrants to check-in reality BEFORE calling it is enough,
        since confirmed_count naturally reflects the reconciliation.
        If that leaves an invalid count, the existing "Cannot start"
        error comes back exactly as before, describing the new
        (post-reconciliation) count.

   This is a real behavior change worth knowing about: check-in is now
   effectively mandatory to start an event with anyone at all -- a
   facilitator who checks nobody in and presses Start will have every
   confirmed registrant withdrawn (checked-in count 0), not just get
   the old bare-count screen back. Confirmed as the intended behavior
   for this build (see the conversation this was built from).

   Cleared (spp_kq_clear_checkins()) by Full Reset and by Reset
   Event's full-clear-to-not_started branch (inc/spp-kq-live.php) --
   starting over means re-doing check-in too. NOT cleared by Reset
   Event's other branch (undo Start Play only, stays in 'organizing')
   -- that branch is well past check-in already.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * user_ids currently checked in for this occurrence.
 */
function spp_kq_get_checked_in_user_ids( int $occurrence_id ) : array {
    global $wpdb;
    $table = spp_kq_checkins_table();
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$table} WHERE occurrence_id = %d",
        $occurrence_id
    ) ) );
}

/**
 * Mark one user checked in (row upsert) or not (row delete). Does NOT
 * validate that $user_id is actually a confirmed registrant -- callers
 * (spp_kq_handle_post_actions()) check that first, same discipline as
 * every other write in this feature.
 */
function spp_kq_set_checked_in( int $occurrence_id, int $user_id, bool $checked_in ) : void {
    global $wpdb;
    $table = spp_kq_checkins_table();

    if ( $checked_in ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (occurrence_id, user_id, checked_in_at) VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE checked_in_at = VALUES(checked_in_at)",
            $occurrence_id, $user_id, current_time( 'mysql' )
        ) );
    } else {
        $wpdb->delete( $table, array( 'occurrence_id' => $occurrence_id, 'user_id' => $user_id ) );
    }
}

/**
 * Wipe all check-in state for an occurrence -- called by Full Reset and
 * by Reset Event's full-clear-to-not_started branch (inc/spp-kq-live.php),
 * same "starting over" boundary spp_kq_assignments/spp_kq_scores already
 * use in those two places.
 */
function spp_kq_clear_checkins( int $occurrence_id ) : void {
    global $wpdb;
    $wpdb->delete( spp_kq_checkins_table(), array( 'occurrence_id' => $occurrence_id ) );
}

/**
 * Confirmed registrants for this occurrence, named, for the check-in
 * list -- same membership join/name convention as
 * spp_kq_render_roster_screen()'s own "Currently Registered" list
 * (inc/spp-kq-roster.php).
 *
 * @return array List of ['user_id'=>int, 'name'=>string].
 */
function spp_kq_get_confirmed_registrants_named( int $occurrence_id ) : array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.user_id, m.first_name, m.last_name
         FROM {$wpdb->prefix}gl_registrations r
         LEFT JOIN membership m ON m.user_id = r.user_id
         WHERE r.occurrence_id = %d AND r.status = 'confirmed'
         ORDER BY m.first_name ASC, m.last_name ASC",
        $occurrence_id
    ), ARRAY_A );

    return array_map( fn( $r ) => array(
        'user_id' => (int) $r['user_id'],
        'name'    => spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ),
    ), $rows );
}
