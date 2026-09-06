<?php
/* =========================================================
   Show Results
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
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_show_results() {
    global $wpdb, $Event;

    $sort  = "Rank";
    $table = "Results_all";

    $where    = "where event_id = $Event";
    $title    = "<br>All results from Event " . $Event;
    $subtitle = $Event . " - Sorted by Rank";

    $sql = "SELECT
        Rank,
        display_name as Player,
        RankPrev as Previous,
        RankCalc as Calculated,
        RankOverride as Override,
        event_id as Event,
        group_id as Groups,
        Score as Scores
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
