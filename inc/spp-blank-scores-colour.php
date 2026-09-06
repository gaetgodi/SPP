<?php
/* =========================================================
   Blank Scores Colour Grid
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Blank for scores - colour" (CM194)

   PURPOSE:
   Renders the blank, colour-coded score-entry grid used for paper
   score sheets -- one card per group, laid out from Schedules_layout
   column config, reading rows from a caller-supplied $file (usually
   the schedules_w view, sometimes a specific date-stamped snapshot
   table) filtered by a caller-supplied $time/$player clause.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Blank for scores - colour'] (CM194, now a
     transition shim around this function): 6 pages call it by name,
     several passing file=/time= arguments (see below). Not touched
     by this migration -- all keep working via the shim.

   Changes from CM194:
   - Wrapped in a real function, spp_blank_scores_colour(), instead
     of a bare top-level script.
   - Calls spp_create_view() directly instead of
     echo do_shortcode("[cmruncode name='Create View']") -- CM254
     has already been migrated. Called bare (no arguments), exactly
     matching the original: CM194 reads its own $file/$time/$player
     from $wpda_shortcode_args for its OWN query below, but never
     forwarded $file to the Create View call either before or after
     this migration -- Create View always rebuilds its own default
     (Schedules -> schedules_w) here, unchanged behavior.
   - No mutation anywhere in this file (confirmed -- pure SELECT
     plus HTML rendering), so no wildcard/meta_key audit applies.
   - No other behavior change.

   FOUND WHILE AUDITING REAL CALLERS (not fixed -- not this file's
   bug, flagging for visibility): "Create colourful table from
   schedules - 7:30" (page 20005537) and "Paper Results - 6:00"
   (page 2830) call this with file="schedules_vvv". That table/view
   does not exist on this database (confirmed: SHOW TABLES and
   information_schema.TABLES both return nothing for it) -- these
   two pages have been silently returning zero rows / a possible SQL
   error every time they run, independent of anything in this
   migration. This is a broken caller, not a bug in
   spp_blank_scores_colour() itself, which faithfully queries
   whatever $file it's given.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_blank_scores_colour() {
    global $wpdb, $wpda_shortcode_args;

    $prefix = $wpdb->prefix;

    if ( isset( $wpda_shortcode_args['time'] ) ) {
        $time = $wpda_shortcode_args['time'];
        $file = $wpda_shortcode_args['file'];
    } else {
        $time = 'where time_id > 0';
        $file = 'schedules_w';
    }

    if ( isset( $wpda_shortcode_args['player'] ) ) {
        $player = $wpda_shortcode_args['player'];
    } else {
        $player = "and user_id <> ''";
    }

    spp_create_view();

    $all_property = array();
    $all_cols     = array();
    $all_class    = array();

    $sql    = "SELECT COLUMN_NAME,Description,Visible, OrdOrder, cssClass FROM Schedules_layout Where Visible = 'Yes' order by OrdOrder";
    $result = $wpdb->get_results( $sql, ARRAY_A );

    $columnArr = array_column( $result, 'Description' );
    $cols      = array_column( $result, 'COLUMN_NAME' );
    $class     = array_column( $result, 'cssClass' );

    $sql     = "select app_title from " . $prefix . "wpda_app where app_id = 4";
    $result1 = $wpdb->get_results( $sql, ARRAY_A );
    $name    = $result1[0]['app_title'];

    echo "<center><h1 class=\"spp-schedule-title\">$name</h1></center>";
    echo '<div class="spp-print-button" style="text-align:center;margin:10px 0 15px 0;"><button onclick="window.print()" style="padding:8px 24px;background:#3766AB;color:white;border:none;border-radius:4px;font-size:14px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">Print this page</button></div>';

    echo '<table class="styled-table">
       <thead class="data-heading">
		';
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

    $sql    = "SELECT group_concat('s.',l.COLUMN_NAME separator ',') as f FROM Schedules_layout l
where Visible = 'Yes' order by ordOrder";
    $result = $wpdb->get_results( $sql, ARRAY_A );
    $fields = $result[0]['f'];

    $sql = "SELECT * FROM $file s $time $player order by Crt_name, Rank";
    $all = $wpdb->get_results( $sql, ARRAY_A );

    $sum_rows = 0;
    $first    = 1;
    $color    = "first";
    $str      = "";

    for ( $x = 0; $x < count( $all ); $x++ ) {

        $some = array();
        foreach ( $cols as $field ) {
            $fld = $all[ $x ][ $field ];
            array_push( $some, $fld );
        }

        $gpname_ = $all[ $x ]['GP_name'];
        if ( $first ) {
            $gpname = $gpname_;
            $first  = 0;
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
            $gpname = $gpname_;
            $rows   = substr_count( $str, "<tr" );
            if ( $rows == 5 ) {
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

    $rows = substr_count( $str, "<tr" );
    if ( $rows == 5 ) {
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
