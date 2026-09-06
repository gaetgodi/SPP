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

   UPDATE (2026-09-06) -- INCIDENT: this function had zero request-
   gating -- it ran its full removal loop on every single render,
   including a bare GET page view. A verification page-load during
   an unrelated migration session triggered it for real, flipping
   Ladder='No' for 74 real active-ladder members in one shot (the
   entire then-current backlog of zero-scored-since-cutoff players).
   Reverted separately (74 usermeta rows restored; see the site's own
   incident record, not duplicated here).

   FIXED: added the same POST-confirm gate used by
   spp_apply_override_to_results_table() -- Stage 1 (no $_POST) now
   only shows a preview of who WOULD be removed (read-only, same
   SELECT, no writes) plus a confirm button; the removal loop only
   runs when $_POST['sriu_confirmed'] === '1', matching this
   codebase's standing propose-then-apply convention for every other
   mutating admin tool. No other behavior change -- same cutoff
   event, same selection query, same fields written, once confirmed.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_remove_inactive_ladder_users() {
    global $wpdb;

    $umetatable = $wpdb->prefix . 'usermeta';
    $lowevent   = 30000760;

    $confirmed = isset( $_POST['sriu_confirmed'] ) && $_POST['sriu_confirmed'] === '1';

    $inactive = $wpdb->get_results( $wpdb->prepare( "
        SELECT m.user_id, m.Rank, r.display_name
        FROM Master m
        LEFT JOIN Results_all r ON m.user_id = r.user_id
            AND r.event_id > %d
        GROUP BY m.user_id
        HAVING COUNT(r.Score) = 0
        ORDER BY Rank ASC
    ", $lowevent ), ARRAY_A );

    if ( ! $confirmed ) {
        echo '<div style="max-width:600px;margin:20px auto;font-family:Arial,sans-serif;">';
        if ( empty( $inactive ) ) {
            echo '<p>No players currently qualify for removal (none with zero scored results since the cutoff event).</p>';
            return;
        }
        echo '<div style="background:#fdf3f2;border:2px solid #c0392b;border-radius:6px;padding:16px;margin:16px 0;">';
        echo '<p style="color:#c0392b;font-weight:bold;">This will remove ' . count( $inactive ) . ' player(s) from the ladder (Ladder=No, current Rank archived to old_Rank):</p>';
        echo '<ul>';
        foreach ( $inactive as $value ) {
            echo '<li>' . esc_html( $value['user_id'] . ' ' . $value['display_name'] . ' (Rank ' . $value['Rank'] . ')' ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '<form method="post">';
        echo '<input type="hidden" name="sriu_confirmed" value="1">';
        echo '<button type="submit" style="padding:10px 24px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;">Yes, Remove These Players</button>';
        echo ' <a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '" style="margin-left:12px;color:#888;">Cancel</a>';
        echo '</form>';
        echo '</div>';
        return;
    }

    echo '<pre>';

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

add_shortcode( 'spp_remove_inactive_ladder_users', function( $atts ) {
    ob_start();
    spp_remove_inactive_ladder_users();
    return ob_get_clean();
} );
