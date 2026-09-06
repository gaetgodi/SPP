<?php
/* =========================================================
   Score Review Grid
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Black for scores - colour" (CM215)

   PURPOSE:
   Renders the score-review grid for a selected ladder event --
   either the live current-event Schedules table (while that event
   is the current one and its schedule is published) or a historical
   Schedules_Scores_{$Event} snapshot for a past event, filtered to
   exclude NP/NS players (Game1 < 0) from display while still using
   their original scheduled group size for the colour scheme.

   This is the caller already deeply audited during the CM254
   migration: it targets an ISOLATED view name
   (schedules_w_score_review), never the shared schedules_w, which is
   what makes spp_create_view()'s same-table guard safe in general
   (a naive "view already exists" check would have been wrong here,
   since this caller reuses that one view name across different
   source tables depending on which event is selected).

   CALLED FROM (as of this migration):
     Via [cmruncode name='Black for scores - colour'] (CM215, now a
     transition shim around this function): the pages "Create
     colourful table from schedules show scores - 6:00"
     (menu-reachable via Main, open to any logged-in member) and
     "...7:30" (orphan). Not touched by this migration -- both keep
     working via the shim.

   Changes from CM215:
   - Wrapped in a real function, spp_score_review_grid(), instead of
     a bare top-level script.
   - Calls spp_create_view( $table, 'schedules_w_score_review' )
     directly instead of
     echo do_shortcode("[cmruncode name='Create View' file='$table'
     view_name='schedules_w_score_review']") -- CM254 has already
     been migrated. The $table current-vs-historical selection logic
     above this call is completely unchanged.
   - No mutation anywhere in this file (confirmed -- pure SELECT
     plus HTML rendering), so no wildcard/meta_key audit applies.
   - No other behavior change, including the defensive
     unset($wpda_shortcode_args['file']) / $_SESSION cleanup lines
     right after the Create View call -- kept exactly as-is even
     though this specific call site no longer goes through
     $wpda_shortcode_args itself, since other [cmruncode] calls
     elsewhere on the same page render still could, and this cleanup
     protects whatever runs after it from inheriting a stale 'file'
     key.

   UPDATE (2026-09-06) -- real shortcode registered: removed the
   $wpda_shortcode_args['time_id'] read entirely (was:
   `if (isset($wpda_shortcode_args['time_id'])) { $time = ...; }
   else { $time = 'where time_id > 0'; }`). Traced and confirmed dead
   twice over before removing, not assumed: (1) no live caller ever
   passes a `time_id` attribute -- the one caller that passes anything
   passes `time="7:30"`, a different key, so isset(...['time_id']) was
   always false in practice; (2) even if it were set, $time gets
   unconditionally overwritten two lines later before ever being used
   in a query. No shortcode attribute needed for this function.
   The separate unset($wpda_shortcode_args['file']) defensive cleanup
   below is UNTOUCHED -- it protects other, still-unmigrated
   [cmruncode] calls that may render later on the same page, which is
   outside tonight's scope.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_score_review_grid() {
    global $wpdb, $Event, $name, $wpda_shortcode_args;

    if ( ! $Event ) {
        echo '<p>Please select a ladder event above to view scores.</p>';
        return;
    }

    $prefix = $wpdb->prefix;

    $current_event = (int) get_option( 'spp_current_event', 0 );
    $schedule_live = (int) get_option( 'spp_schedule_published', 0 ) === 1;
    $table         = ( $Event === $current_event && $schedule_live ) ? 'Schedules' : "Schedules_Scores_{$Event}";

    $result = $wpdb->query( "select * from $table" );
    if ( ! $result ) {
        echo "<br><h1>Results do not exist for this date $table</h1><br>";
        return 0;
    }

    $file = 'schedules_w_score_review';
    $time = 'where t_ID > 0 and (Game1 >= 0 OR Game1 IS NULL)';

    // Create view for scoring tables
    spp_create_view( $table, 'schedules_w_score_review' );
    unset( $wpda_shortcode_args['file'] );
    if ( isset( $_SESSION['wpda_shortcode_args'] ) ) {
        unset( $_SESSION['wpda_shortcode_args']['file'] );
    }

    // ── Pre-load original group sizes from unfiltered table ──────────────
    // NP/NS players (Game1 < 0) are excluded from display but the group
    // colour scheme must reflect the original scheduled group size.
    $group_sizes = [];
    $size_rows   = $wpdb->get_results(
        "SELECT group_id, COUNT(*) as cnt FROM {$table} GROUP BY group_id",
        ARRAY_A
    );
    foreach ( $size_rows as $sr ) {
        $group_sizes[ (int) $sr['group_id'] ] = (int) $sr['cnt'];
    }

    $all_property = array();
    $all_cols     = array();
    $all_class    = array();

    $sql    = "SELECT COLUMN_NAME, Description, Visible, OrdOrder, cssClass FROM Schedules_layout WHERE Visible = 'Yes' ORDER BY OrdOrder";
    $result = $wpdb->get_results( $sql, ARRAY_A );

    $columnArr = array_column( $result, 'Description' );
    $cols      = array_column( $result, 'COLUMN_NAME' );
    $class     = array_column( $result, 'cssClass' );

    $name       = trim( $name );
    $namelength = strlen( $name );
    $name       = substr( $name, 0, ( $namelength - 8 ) );
    echo "<center><H1>$name</H1></center>";

    echo '<table class="styled-table"><thead class="data-heading">';
    for ( $x = 0; $x < count( $columnArr ); $x++ ) {
        $some = $columnArr[ $x ];
        $col  = $cols[ $x ];
        $cl   = $class[ $x ];
        echo '<td class=' . $some . '>' . $some . '</td>';
        array_push( $all_property, $some );
        array_push( $all_cols, $col );
        array_push( $all_class, $cl );
    }
    echo '</thead>';

    $sql    = "SELECT group_concat('s.',l.COLUMN_NAME separator ',') as f FROM Schedules_layout l WHERE Visible = 'Yes' ORDER BY ordOrder";
    $result = $wpdb->get_results( $sql, ARRAY_A );
    $fields = $result[0]['f'];

    $sql = "SELECT * FROM $file $time ORDER BY t_ID, Crt_name, Rank";
    $all = $wpdb->get_results( $sql, ARRAY_A );

    $sum_rows      = 0;
    $first         = 1;
    $color         = "first";
    $str           = "";
    $current_gp_id = null;

    for ( $x = 0; $x < count( $all ); $x++ ) {

        $some = array();
        foreach ( $cols as $field ) {
            $fld = $all[ $x ][ $field ];
            array_push( $some, $fld );
        }

        $gpname_   = $all[ $x ]['GP_name'];
        $gp_id_cur = (int) $all[ $x ]['group_id'];

        if ( $first ) {
            $gpname        = $gpname_;
            $current_gp_id = $gp_id_cur;
            $first         = 0;
        }

        if ( $gpname_ == $gpname ) {
            $str .= "<tr class=$color>";
            for ( $y = 0; $y < count( $some ); $y++ ) {
                $some_y = $some[ $y ];
                $sr     = $x - $sum_rows;
                $some_c = "$all_class[$y]-$sr";
                $str   .= "<td class=$some_c>$some_y </td>";
            }
            $str .= "</tr>";
        } else {
            // Flush the completed group
            $gpname = $gpname_;
            $rows   = substr_count( $str, "<tr" );

            // Use original scheduled group size for colour scheme
            $original_size = isset( $group_sizes[ $current_gp_id ] ) ? $group_sizes[ $current_gp_id ] : $rows;
            $current_gp_id = $gp_id_cur;

            if ( $original_size == 5 ) {
                $search     = array( "Game1", "Game2", "Game3", "Game4", "Game5" );
                $replace    = array( "GameA51", "GameB52", "GameC53", "GameD54", "GameE55" );
                $new_string = str_replace( $search, $replace, $str );
                echo $new_string;
            } else {
                $search     = array( "Game1", "Game2", "Game3", "Game4", "Game5" );
                $replace    = array( "GameA41", "GameB42", "GameC43", "GameD44", "GameE45" );
                $new_string = str_replace( $search, $replace, $str );
                echo $new_string;
            }

            $sum_rows += $rows;
            echo '<tr class=blank></tr>';
            if ( $color == "first" ) { $color = "second"; } else { $color = "first"; }
            $str = "";
            $x--;
        }
    }

    // Flush the final group
    $rows          = substr_count( $str, "<tr" );
    $original_size = isset( $group_sizes[ $current_gp_id ] ) ? $group_sizes[ $current_gp_id ] : $rows;

    if ( $original_size == 5 ) {
        $search     = array( "Game1", "Game2", "Game3", "Game4", "Game5" );
        $replace    = array( "GameA51", "GameB52", "GameC53", "GameD54", "GameE55" );
        $new_string = str_replace( $search, $replace, $str );
        echo $new_string;
    } else {
        $search     = array( "Game1", "Game2", "Game3", "Game4", "Game5" );
        $replace    = array( "GameA41", "GameB42", "GameC43", "GameD44", "GameE45" );
        $new_string = str_replace( $search, $replace, $str );
        echo $new_string;
    }

    echo "</table>";
}

add_shortcode( 'spp_score_review_grid', function( $atts ) {
    ob_start();
    spp_score_review_grid();
    return ob_get_clean();
} );
