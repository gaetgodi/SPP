<?php
/* =========================================================
   Remove Inactive Ladder Users
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Remove ladder users who have
   not played this year" (CM176)

   PURPOSE:
   NOT read-only, despite the name -- for every Master-list player
   with zero scored results since a fixed cutoff event
   ($lowevent = 30000760, a TEC-era event ID), this permanently
   removes them from the ladder: sets Ladder='No' and archives
   their current Rank to old_Rank usermeta (so it can be restored
   with a bias if they later rejoin, the same old_Rank mechanism
   CM82/spp_random_ranks() read). A bulk, automated version of
   CM82 "Remove user from Ladder"'s single-user action -- does not
   call CM82, has its own independent copy of the same logic.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Remove ladder users who have not played
     this year'] (CM176, now a transition shim around this
     function): the page "Remove ladder players who have not
     played this year" (menu-reachable via Main). Not touched by
     this migration -- keeps working via the shim.

   Changes from CM176:
   - Wrapped in a real function, spp_remove_inactive_ladder_users(),
     instead of a bare top-level script.
   - Narrowed the Ladder-meta delete from
     "meta_key LIKE '%Ladder%'" to an exact match on 'Ladder' --
     audited fresh: 'Ladder' is the only usermeta key matching that
     wildcard site-wide, so this is a no-op change in practice
     today, done for consistency with the exact-match discipline
     used everywhere else this session.
   - FIXED (by explicit decision, not silently): the old_Rank
     INSERT had no DELETE-before-INSERT guard -- same shape as the
     CM120 old_Rank fix. A player removed, rejoining, playing
     briefly without accumulating results, then auto-removed again
     by this same routine would have ended up with two 'old_Rank'
     usermeta rows. Confirmed dormant against live data before
     fixing (0 of the 70 currently-affected users have an existing
     old_Rank row today), but fixed on the same principle as CM120.
     Added a DELETE FROM usermeta WHERE meta_key='old_Rank' before
     the INSERT.
   - No other behavior change, including the unclosed "<pre>" tag
     at the top (kept verbatim -- it does change rendering, and
     "faithful translation" means not silently dropping that):
     same cutoff event ID (30000760),
     same selection query (Master LEFT JOIN Results_all HAVING no
     scored results), same fields written.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_remove_inactive_ladder_users() {
    global $wpdb;

    $umetatable = $wpdb->prefix . 'usermeta';
    $lowevent   = 30000760;

    echo '<pre>';

    $inactive = $wpdb->get_results( $wpdb->prepare( "
        SELECT m.user_id, m.Rank, r.display_name
        FROM Master m
        LEFT JOIN Results_all r ON m.user_id = r.user_id
            AND r.event_id > %d
        GROUP BY m.user_id
        HAVING COUNT(r.Score) = 0
        ORDER BY Rank ASC
    ", $lowevent ), ARRAY_A );

    foreach ( $inactive as $value ) {
        $user_id      = (int) $value['user_id'];
        $rank         = $value['Rank'];
        $display_name = $value['display_name'];

        echo "<br>$user_id $display_name $rank";

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Ladder'", $user_id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Ladder','No')", $user_id ) );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $user_id ) );

        // Fix: clear any existing old_Rank before archiving, so a
        // second removal never leaves two old_Rank rows.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='old_Rank'", $user_id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'old_Rank',%s)", $user_id, $rank ) );
    }
}
