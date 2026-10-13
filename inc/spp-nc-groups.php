<?php
/* =========================================================
   NC (Not Complete) group status
   Version: 1.0.0
   Date: 2026-10-13

   POLICY: a group whose session was not completed is marked NC.
   - The scores that were actually entered still count toward the
     club (Glicko) rating: they reach Schedules_Scores_{event} as
     usual and the rating engine is not changed. Nothing is inferred
     for games that were never entered.
   - The group's play does not feed the ranking calculation: every
     player in it gets RankCalc = RankCalc_Shadow = RankPrev (and so
     RankOverride defaults to RankPrev too), with no NP/NS penalty.
   - Their final ordinal Rank still moves -- it is the index of the
     overall sort (Create Results Steps 10/11, Apply Override), so
     other players' movement still shifts them. They are not frozen.

   WHERE THINGS LIVE:
   - Before Create Results: the option spp_nc_groups =
     array( 'event' => <event_id>, 'groups' => int[] ), set and
     cleared per group on the GL Schedule Admin page
     (spp-schedule-admin.php). Keyed by event, so a list left over
     from an earlier week is simply ignored -- nothing needs to clear
     it when the next schedule is produced. Marking NC never touches
     Schedules: scores, group_id, Crt_ID and time_id stay as they are
     (unlike NP/NS, which move the player to group 99).
   - After Create Results: a GroupStatus column ('NC' or NULL) on
     Results_{event}/Results (always -- those are rebuilt each run)
     and on Results_all (once the hand-run ALTER below exists). Apply
     Override copies it through; rank history, show-results and the
     results report read it to explain why a result did not move.

   Results_all needs (run by hand; the code works before and after):
     ALTER TABLE Results_all ADD COLUMN GroupStatus VARCHAR(4) NULL DEFAULT NULL;
   ========================================================= */

defined( 'ABSPATH' ) || exit;

define( 'SPP_NC_STATUS', 'NC' );

// Filterable so tests can point at a throwaway option.
function spp_nc_option_name() : string {
    return (string) apply_filters( 'spp_nc_groups_option', 'spp_nc_groups' );
}

// Group ids marked NC for $event, sorted. Empty when the stored list
// belongs to a different event.
function spp_nc_groups_for_event( int $event ) : array {
    $v = get_option( spp_nc_option_name(), array() );
    if ( $event <= 0 || ! is_array( $v ) || (int) ( $v['event'] ?? 0 ) !== $event ) {
        return array();
    }
    $groups = array();
    foreach ( (array) ( $v['groups'] ?? array() ) as $g ) {
        $g = (int) $g;
        if ( $g > 0 && $g !== 99 ) $groups[ $g ] = $g;
    }
    sort( $groups );
    return array_values( $groups );
}

// Mark ($nc = true) or clear one group for $event. Returns the new list.
function spp_nc_set_group( int $event, int $group_id, bool $nc ) : array {
    $groups = spp_nc_groups_for_event( $event );
    if ( $nc ) {
        $groups[] = $group_id;
    } else {
        $groups = array_diff( $groups, array( $group_id ) );
    }
    $groups = array_values( array_unique( array_map( 'intval', $groups ) ) );
    sort( $groups );
    if ( empty( $groups ) ) {
        delete_option( spp_nc_option_name() );
    } else {
        update_option( spp_nc_option_name(), array( 'event' => $event, 'groups' => $groups ), false );
    }
    return $groups;
}

// True when $table has the GroupStatus column. Not cached: Results is
// rebuilt during a request, and tests swap tables in and out.
function spp_nc_table_has_status( string $table ) : bool {
    global $wpdb;
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) return false;
    return (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'GroupStatus'" );
}

// Number of NC rows in $table (0 when the column doesn't exist yet).
function spp_nc_count_rows( string $table ) : int {
    global $wpdb;
    if ( ! spp_nc_table_has_status( $table ) ) return 0;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE GroupStatus = 'NC'" );
}

// Is ($event, $group_id) NC according to the published results? Reads
// Results_all when it has the column, else Results when it holds $event.
function spp_nc_group_is_nc_in_results( int $event, int $group_id ) : bool {
    global $wpdb;
    if ( spp_nc_table_has_status( 'Results_all' ) ) {
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM Results_all WHERE event_id = %d AND group_id = %d AND GroupStatus = 'NC'",
            $event, $group_id
        ) );
    }
    if ( spp_nc_table_has_status( 'Results' ) ) {
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM Results WHERE event_id = %d AND group_id = %d AND GroupStatus = 'NC'",
            $event, $group_id
        ) );
    }
    return false;
}
