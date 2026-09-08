<?php
/* =========================================================
   Remove Inactive Ladder Users
   Version: 1.2.0
   Date: 2026-09-08
   Based on: Code Manager snippet "Remove ladder users who have
   not played this year" (CM176)

   Changes from 1.1.0:
   - BUG FIX (found via a fresh preview-list investigation, not a
     report of a bad removal -- the 2026-09-06 incident's 74 reverted
     users happened to make this visible before anyone was actually
     removed by it a second time): the selection query's cutoff,
     event_id > 30000760, compared every candidate's Results_all rows
     against a TEC/rtec-legacy event_id (+30000000-offset numbering
     scheme that stopped being used around event_id 30000895, ~Sept
     2025) while the live GL Events pipeline has used a completely
     different, disjoint, small sequential event_id space (currently
     around 160) ever since. No GL-era event_id can ever satisfy
     "> 30000760", so the query was blind to all play recorded after
     the migration -- it flagged 74 of 168 active ladder members (44%
     of the ladder), every single one of whom had played as recently
     as 2 weeks prior (verified against event 162, 2026-08-24).
   - FIXED: cutoff comparison replaced entirely -- no hardcoded event
     ids anywhere in the new query. A candidate now qualifies for
     removal only if they have zero Results_all rows with a non-NULL
     Score (NULL Score is a valid no-show/rank-decay state, not "no
     row" -- confirmed against live data: ~51% of all Results_all
     rows have NULL Score regardless of event or era, so row
     existence alone is not evidence of play) whose REAL resolved
     calendar date falls in the current year. "Real resolved date"
     uses the same gl_event_occurrences/event_date_lookup resolution
     spp_scores_events_dropdown() (CM273) already used correctly --
     now shared via spp_event_date_resolution_sql() (functions.php)
     instead of this file re-deriving its own version of the same
     problem, which is exactly how the 30000760 constant happened in
     the first place.
   - Preview (Stage 1) now shows each candidate's actual last-played
     date (most recent non-NULL-Score date, any year, same
     resolution) alongside Rank and full name, so the list is
     self-verifying at a glance -- pulled from Master.first_name/
     last_name instead of Results_all.display_name, which was NULL
     for most candidates anyway (it only populates when a row joins
     on the old cutoff condition, which is exactly the query being
     replaced here).
   - Re-verified against live data after the fix: of the 74 people
     the old query flagged, 67 clear immediately (real 2026 play);
     the remaining 7 -- Lydia Bogle, Gerry Funk, Henry St. Louis,
     Brian Mok, Kevin Tan, Nirmala Seshadri, Devi Dasan -- are
     confirmed genuine candidates (zero non-NULL Score anywhere in
     2026, and for six of them, zero non-NULL Score ever on record).
     19 more genuinely-inactive-in-2026 people the old query MISSED
     entirely (a false-negative side of the same bug: they have an
     old non-NULL Score from the legacy 2025 window, which alone
     satisfied the old ">30000760" check) are now correctly included.
     Full detail in this migration's own investigation notes, not
     duplicated here.
   - No change to the write/confirm loop's mechanics (Ladder=No,
     Rank archived to old_Rank with the existing delete-before-insert
     guard) -- only the selection query and preview display changed.

   Changes from 1.0.0:
   - SECURITY FIX (Tier 1 access-control audit): the 2026-09-06
     incident below already showed this function shouldn't run
     unconditionally -- but the fix at the time only added a
     POST-confirm stage, not an actual identity check. Ultimate
     Member's restriction on this page's Main menu link
     (administrator only, confirmed from this week's UM menu audit)
     only hides that link; the page and this shortcode were still
     reachable, and still run their SELECT/preview stage, for anyone
     at all. Fixed with spp_is_admin(), checked before the Master/
     Results_all query even runs -- not just before the write loop.
     A nonce (wp_nonce_field()/wp_verify_nonce(), action
     'spp_remove_inactive_ladder_users_action') added to the existing
     confirm form, required alongside 'sriu_confirmed' before the
     removal loop runs. Gate in the function body: confirmed fresh
     this function has no internal callers besides its own
     add_shortcode() closure.
   - No other behavior change -- same cutoff event, same selection
     query, same fields written once confirmed.

   PURPOSE:
   NOT read-only, despite the name -- for every Master-list player
   with zero non-NULL-Score results in the current calendar year
   (real resolved date, not a hardcoded event id -- see 1.2.0 above),
   this permanently removes them from the ladder: sets Ladder='No'
   and archives their current Rank to old_Rank usermeta (so it can be
   restored with a bias if they later rejoin, the same old_Rank
   mechanism CM82/spp_random_ranks() read). A bulk, automated version
   of CM82 "Remove user from Ladder"'s single-user action -- does not
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
    // Administrator only, per this page's Ultimate Member menu
    // restriction -- confirmed from this week's UM menu audit. Checked
    // before the SELECT below even runs, not just before the removal
    // loop -- the 2026-09-06 incident was a bare GET reaching this far
    // at all, not just the confirm step being skippable.
    if ( ! spp_is_admin() ) {
        echo '<p>You do not have permission to use this tool.</p>';
        return;
    }

    global $wpdb;

    $umetatable = $wpdb->prefix . 'usermeta';

    // Nonce required alongside 'sriu_confirmed' -- closes the CSRF gap
    // the existing confirm step didn't cover on its own. Invalid/missing
    // nonce is treated as not confirmed at all.
    $confirmed = isset( $_POST['sriu_confirmed'] ) && $_POST['sriu_confirmed'] === '1'
        && isset( $_POST['spp_remove_inactive_ladder_users_nonce'] )
        && wp_verify_nonce( $_POST['spp_remove_inactive_ladder_users_nonce'], 'spp_remove_inactive_ladder_users_action' );

    // Shared date-resolution logic (functions.php) -- same mechanism
    // spp_scores_events_dropdown() uses, so this query never has to
    // guess which event_id numbering epoch a row belongs to. See the
    // 1.2.0 changelog above for why that guess (a hardcoded cutoff
    // event id) was the bug.
    $date_resolution = spp_event_date_resolution_sql( 'r.event_id', 'sriu' );
    $date_expr       = $date_resolution['date_expr'];

    // No hardcoded event ids: a candidate qualifies only if NONE of
    // their Results_all rows have a non-NULL Score whose resolved
    // real date falls in the current calendar year. NULL Score is a
    // valid no-show/rank-decay state, not evidence of absence, so it
    // never counts as play here -- and it's never mistaken for "no
    // row at all" either, since row existence alone isn't play.
    $inactive = $wpdb->get_results( "
        SELECT m.user_id, m.Rank,
               CONCAT(m.first_name, ' ', m.last_name) AS full_name,
               MAX(CASE WHEN r.Score IS NOT NULL THEN {$date_expr} END) AS last_played_date
        FROM Master m
        LEFT JOIN Results_all r ON m.user_id = r.user_id
        {$date_resolution['join']}
        GROUP BY m.user_id
        HAVING SUM(CASE WHEN r.Score IS NOT NULL AND YEAR({$date_expr}) = YEAR(CURDATE())
                        THEN 1 ELSE 0 END) = 0
        ORDER BY Rank ASC
    ", ARRAY_A );

    // Formats a MySQL DATETIME (or NULL) into the self-verifying
    // "<date> (<n> ago)" / "Never" display used by both the preview
    // and the confirmed-removal log below, so what gets shown before
    // removal and what gets logged during it always agree.
    $format_last_played = function( $last_played_date ) {
        if ( empty( $last_played_date ) ) {
            return 'Never (no non-NULL Score on record)';
        }
        $ts = strtotime( $last_played_date );
        return date_i18n( 'F j, Y', $ts ) . ' (' . human_time_diff( $ts, current_time( 'timestamp' ) ) . ' ago)';
    };

    if ( ! $confirmed ) {
        echo '<div style="max-width:700px;margin:20px auto;font-family:Arial,sans-serif;">';
        if ( empty( $inactive ) ) {
            echo '<p>No players currently qualify for removal (none with zero non-NULL-Score results this year).</p>';
            return;
        }
        echo '<div style="background:#fdf3f2;border:2px solid #c0392b;border-radius:6px;padding:16px;margin:16px 0;">';
        echo '<p style="color:#c0392b;font-weight:bold;">This will remove ' . count( $inactive ) . ' player(s) from the ladder (Ladder=No, current Rank archived to old_Rank):</p>';
        echo '<table style="width:100%;max-width:100%;overflow-x:auto;border-collapse:collapse;font-size:0.9rem;">';
        echo '<tr style="text-align:left;border-bottom:2px solid #c0392b;"><th style="padding:4px 8px;">Rank</th><th style="padding:4px 8px;">Name</th><th style="padding:4px 8px;">Last Played</th></tr>';
        foreach ( $inactive as $value ) {
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:4px 8px;">' . esc_html( $value['Rank'] ) . '</td>';
            echo '<td style="padding:4px 8px;">' . esc_html( $value['full_name'] ) . '</td>';
            echo '<td style="padding:4px 8px;">' . esc_html( $format_last_played( $value['last_played_date'] ) ) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
        echo '<form method="post">';
        wp_nonce_field( 'spp_remove_inactive_ladder_users_action', 'spp_remove_inactive_ladder_users_nonce' );
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
        $display_name = $value['full_name'];
        $last_played  = $format_last_played( $value['last_played_date'] );

        echo "<br>$user_id $display_name $rank -- last played: $last_played";

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
