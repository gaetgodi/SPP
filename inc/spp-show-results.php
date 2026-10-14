<?php
/* =========================================================
   Show Results
   Version: 1.3.0
   Date: 2026-10-14

   Changes from 1.2.0:
   - SECURITY: login gate. The page "Show results by ladder event"
     (/show-results-by-ladder-event/, 1948) was fully public: every
     player's name, ranks and score for the latest event, served to
     logged-out visitors and listed in the Yoast sitemap. The results
     email links here (spp-apply-override-to-results-table.php 1.7.1).
     - spp_show_results() now prints "Please log in to view results."
       (same wording style as the score review grid's
       "Please log in to view scores.") with a login link that returns
       to the current page, and queries nothing, for logged-out
       visitors. This covers any page that embeds the shortcode.
     - Page 1948: logged-out visitors are redirected to the UM login
       page with redirect_to back to the page (the same pattern as
       functions.php's UM profile-page gate), with no-cache headers
       and DONOTCACHEPAGE. Logged-in members see the page as before.
     - Page 1948 is noindex (Yoast robots filter plus an X-Robots-Tag
       header on the redirect) and left out of the Yoast sitemap.
     - wp-cache-config.php's $cache_rejected_uri also lists
       /show-results-by-ladder-event/ (outside the repo).

   Changes from 1.1.0:
   - Added a "Status" column (Results_all.GroupStatus: "NC" for a
     Not Complete group, blank otherwise) after Scores. Only selected
     once Results_all has the column (spp_nc_table_has_status()).

   Changes from 1.0.0:
   - Added a "Calc (Shadow)" column (Results_all.RankCalc_Shadow)
     right after Calculated. Populated from event 162 on (the first
     event processed after RankOverride started defaulting to the
     shadow calc); blank for every earlier event, where no shadow
     value was ever archived. Calculated (plain RankCalc) is kept as
     is -- it's the only calc older events have. Column is only
     selected once Results_all has it (spp_results_all_has_shadow()).

   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Show results" (CM80)

   PURPOSE:
   Lists results from Results_all for a given $event_id (Rank,
   player, previous/calculated/override rank, group, score),
   sorted by Rank.

   CORRECTION to this migration's own task description: the
   "falls back to a WPDA app view when no event selected" behavior
   does NOT exist in the live code -- that entire branch
   ([wpda_app app_id=32] plus the "all weeks" unfiltered view) is
   wrapped in a PHP block comment in CM80 and has never run. The
   live code unconditionally does `WHERE event_id = $Event`
   regardless of whether $Event is set. This works in practice
   because this page's companion snippet (CM273 "Scores Events
   drop down", called first on the same page) always resolves
   $Event to a real value itself -- either the submitted selection
   or a default of the most recent event -- so $Event is never
   actually empty by the time this runs. Preserved exactly as the
   live behavior actually is; the dead fallback branch is not
   carried forward as active code.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Show results'] (CM80, now a transition
     shim around this function): the page "Show all results or
     results for a date" (menu-reachable via Main, open to any
     logged-in member -- confirmed via Ultimate Member menu-item
     audit). Also called internally by CM81 "Events" (itself
     orphaned, zero callers of its own -- not touched by this
     migration). Not touched by this migration -- both keep working
     via the shim.

   Changes from CM80: wrapped in a real function, spp_show_results(),
   instead of a bare top-level script. Dropped the dead
   "if (!session_status() == PHP_SESSION_ACTIVE) session_start()"
   guard, same pattern removed from every other migrated snippet.
   No mutation anywhere in this file (confirmed -- pure SELECT), so
   no wildcard/meta_key audit applies. No other behavior change.

   FIXED (2026-09-06): $subtitle repeated $Event a second time
   immediately under $title ("All results from Event 162" followed
   by "162 - Sorted by Rank") -- confirmed genuinely redundant, not a
   count that happened to coincide: $subtitle was never anything but
   $Event itself, no count of displayed rows exists anywhere in this
   function. Present since the original migration (git blame:
   b0f454c), carried forward faithfully from CM80 at the time --
   pre-existing, not introduced here. Changed to a plain, non-
   redundant label. $title is untouched -- it's the correctly-labeled
   instance of the event number.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_show_results() {
    global $wpdb, $Event;

    if ( ! is_user_logged_in() ) {
        $back = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
        echo '<p>Please log in to view results. <a href="' . esc_url( spp_show_results_login_url( $back ) ) . '">Log in</a></p>';
        return;
    }

    $sort  = "Rank";
    $table = "Results_all";

    $where    = "where event_id = $Event";
    $title    = "<br>All results from Event " . $Event;
    $subtitle = "Sorted by Rank";

    $shadow_col = ( function_exists( 'spp_results_all_has_shadow' ) && spp_results_all_has_shadow() )
        ? "RankCalc_Shadow as `Calc (Shadow)`,"
        : '';

    $status_col = ( function_exists( 'spp_nc_table_has_status' ) && spp_nc_table_has_status( $table ) )
        ? ", GroupStatus as Status"
        : '';

    $sql = "SELECT
        Rank,
        display_name as Player,
        RankPrev as Previous,
        RankCalc as Calculated,
        $shadow_col
        RankOverride as Override,
        event_id as Event,
        group_id as Groups,
        Score as Scores
        $status_col
        FROM $table
        $where order by $sort";
    $all = $wpdb->get_results( $sql, ARRAY_A );

    if ( empty( $all ) ) {
        echo "<br><h2>No results for that date</h2><br>";
    } else {
        $columns = array_keys( $all[0] );

        echo "<h2>$title</h2>";
        echo "<h2>$subtitle</h2>";
        echo "<table border='1'>";
        foreach ( $columns as $value ) {
            echo "<th>$value</th>";
        }
        foreach ( $all as $row ) {
            echo "<tr>";
            foreach ( $row as $val ) {
                echo "<td>$val</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }

    // Preserved verbatim from CM80, including the pre-existing typo:
    // this unsets $GLOBALS['$Event'] (a literal key containing a dollar
    // sign), not $GLOBALS['Event'] -- so it has never actually cleared
    // the real $Event global. Harmless no-op either way; not fixed here.
    unset( $GLOBALS['$Event'] );
    unset( $GLOBALS['$name'] );
}

/** Page "Show results by ladder event" (/show-results-by-ladder-event/). */
const SPP_SHOW_RESULTS_PAGE_ID = 1948;

/** Login URL (UM login page, or wp-login.php without UM) that returns to $back. */
function spp_show_results_login_url( string $back ) : string {
    $login = function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : '';
    if ( ! $login ) {
        return wp_login_url( $back );
    }
    return add_query_arg( 'redirect_to', rawurlencode( $back ), $login );
}

// Page-level gate: logged-out visitors go to the login page and come back here.
add_action( 'template_redirect', function() {
    if ( ! is_page( SPP_SHOW_RESULTS_PAGE_ID ) ) return;

    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    if ( is_user_logged_in() ) return;

    $back = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
    nocache_headers();
    header( 'X-Robots-Tag: noindex, nofollow', true );
    wp_safe_redirect( spp_show_results_login_url( $back ) );
    exit;
}, 1 );

// Not indexed, not in the sitemap: it was public until 2026-10-14.
add_filter( 'wpseo_robots', function( $robots ) {
    return is_page( SPP_SHOW_RESULTS_PAGE_ID ) ? 'noindex, nofollow' : $robots;
} );
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', function( $ids ) {
    $ids   = is_array( $ids ) ? $ids : array();
    $ids[] = SPP_SHOW_RESULTS_PAGE_ID;
    return $ids;
} );

add_shortcode( 'spp_show_results', function( $atts ) {
    ob_start();
    spp_show_results();
    return ob_get_clean();
} );
