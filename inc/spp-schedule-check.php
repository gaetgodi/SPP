<?php
/**
 * SPP Schedule Validation Report
 * ---------------------------------------------------------
 * Standalone re-validation of the current Schedules table state.
 * Lifted from inc/gl-schedule-production.php (Carpool Adjacency
 * Report + Travel Time Conflict Report, ~lines 2122-2280) so it
 * can be re-run on demand after manual schedule edits, without
 * re-running schedule production itself.
 *
 * Shortcode: [spp_schedule_check]
 *
 * Reference facts:
 *   time_id 1 = 5:30pm, 2 = 6:40pm, 3 = 7:50pm
 *   group_id 99 = excluded / bench
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_schedule_check', 'spp_schedule_check_shortcode' );

function spp_schedule_check_shortcode() {
    ob_start();
    spp_run_schedule_check();
    return ob_get_clean();
}

function spp_run_schedule_check() {
    global $wpdb;

    $Schedules = "Schedules";
    $Master    = "Master";

    $event = (int) get_option( 'spp_current_event', 0 );
    if ( ! $event ) {
        echo '<p class="gl-error">No current event set (spp_current_event option is empty). Nothing to validate.</p>';
        return;
    }

    $schedule_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $Schedules WHERE group_id != 99" );
    if ( $schedule_rows === 0 ) {
        echo '<p class="gl-error">No schedule rows found in the Schedules table. Run Schedule Production first.</p>';
        return;
    }

    // -------------------------------------------------------
    // TIME SLOTS
    // -------------------------------------------------------
    $active_times = $wpdb->get_results( "SELECT T_ID FROM Times WHERE Active = 1 ORDER BY T_ID" );
    $time_ids     = array_column( $active_times, 'T_ID' );
    sort( $time_ids );

    if ( count( $time_ids ) < 3 ) {
        echo '<p class="gl-error">Expected 3 active time slots, found ' . count( $time_ids ) . '.</p>';
        return;
    }

    $time_labels = [
        $time_ids[0] => '5:30pm',
        $time_ids[1] => '6:40pm',
        $time_ids[2] => '7:50pm',
    ];

    // -------------------------------------------------------
    // HELPER FUNCTIONS (lifted verbatim from schedule production)
    // -------------------------------------------------------

    $normalize_travel = function( $travel ) {
        if ( empty( $travel ) ) return '';
        $t = trim( $travel );
        $t = preg_replace( '/^-\s+(5:30|6:40|7:50)/i', '-$1', $t );
        $t = preg_replace( '/^([+-]?)(5:30|6:40|7:50)\s*pm\b/i', '$1$2', $t );
        $t = preg_replace( '/^(-)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t );
        $t = preg_replace( '/^(\+)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t );
        if ( preg_match( '/^(5:30|6:40|7:50)(\s+\S+)?$/i', $t ) ) {
            if ( $t[0] !== '+' && $t[0] !== '-' ) {
                $t = '+' . $t;
            }
        }
        return $t;
    };

    $extract_carpool = function( $travel ) use ( $normalize_travel ) {
        $travel = $normalize_travel( $travel );
        if ( empty( $travel ) ) return '';
        $cleaned = preg_replace( '/^[+-]?(5:30|6:40|7:50)\s*/i', '', $travel );
        $cleaned = ltrim( $cleaned, '+-' );
        return strtolower( trim( $cleaned ) );
    };

    $is_priority_carpool = function( $travel ) use ( $normalize_travel ) {
        $travel = $normalize_travel( $travel );
        if ( empty( $travel ) ) return false;
        $cleaned = preg_replace( '/^[+-]?(5:30|6:40|7:50)\s*/i', '', $travel );
        return $travel[0] === '+' && ! empty( trim( $cleaned, '+-' ) );
    };

    $carpool_key = function( $name ) {
        return ltrim( trim( $name ), '+' );
    };

    $carpool_score = function() use ( $wpdb, $Schedules, $normalize_travel, $Master, $time_ids, $extract_carpool, $carpool_key, $is_priority_carpool ) {
        $players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, m.travel
             FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id
             WHERE s.group_id != 99",
            ARRAY_A
        );
        $carpool_groups = [];
        foreach ( $players as $p ) {
            $travel = $normalize_travel( $p['travel'] );
            $cp = $carpool_key( $extract_carpool( $travel ) );
            if ( ! empty( $cp ) ) {
                $carpool_groups[ $cp ][] = [ 'time_id' => $p['time_id'], 'priority' => $is_priority_carpool( $travel ) ];
            }
        }
        $score = 0;
        foreach ( $carpool_groups as $cp => $members ) {
            if ( count( $members ) < 2 ) continue;
            $is_pri = array_reduce( $members, fn( $c, $m ) => $c || $m['priority'], false );
            for ( $i = 0; $i < count( $members ); $i++ ) {
                for ( $j = $i + 1; $j < count( $members ); $j++ ) {
                    $pos1 = array_search( $members[ $i ]['time_id'], $time_ids );
                    $pos2 = array_search( $members[ $j ]['time_id'], $time_ids );
                    $gap = abs( $pos1 - $pos2 );
                    if ( $is_pri && $gap > 0 ) $score += 40;
                    elseif ( ! $is_pri && $gap > 1 ) $score += 20;
                }
            }
        }
        return $score;
    };

    echo '<div class="spp-schedule-check">';
    echo '<p><strong>Schedule Validation Report</strong> — event ' . esc_html( $event ) . '</p>';

    // -------------------------------------------------------
    // CARPOOL ADJACENCY REPORT
    // -------------------------------------------------------
    echo "<br><strong>Carpool Adjacency Report:</strong><br>";

    $adj_players = $wpdb->get_results(
        "SELECT s.user_id, s.group_id, s.time_id, m.travel, m.first_name, m.last_name
         FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id
         WHERE s.group_id != 99",
        ARRAY_A
    );
    $adj_cp_groups = array();
    foreach ( $adj_players as $p ) {
        $travel = $normalize_travel( $p['travel'] );
        $cp = $carpool_key( $extract_carpool( $travel ) );
        if ( ! empty( $cp ) ) {
            $adj_cp_groups[ $cp ][] = $p;
        }
    }

    $same_slot_pairs   = 0;
    $adjacent_pairs    = 0;
    $separated_pairs   = 0;
    $adjacent_details  = array();
    $separated_details = array();

    foreach ( $adj_cp_groups as $cp => $members ) {
        if ( count( $members ) < 2 ) continue;
        for ( $i = 0; $i < count( $members ); $i++ ) {
            for ( $j = $i + 1; $j < count( $members ); $j++ ) {
                $pos1 = array_search( (int) $members[ $i ]['time_id'], $time_ids );
                $pos2 = array_search( (int) $members[ $j ]['time_id'], $time_ids );
                $gap = abs( $pos1 - $pos2 );
                $n1 = $members[ $i ]['first_name'] . ' ' . $members[ $i ]['last_name'];
                $n2 = $members[ $j ]['first_name'] . ' ' . $members[ $j ]['last_name'];
                $t1 = $time_labels[ (int) $members[ $i ]['time_id'] ] ?? $members[ $i ]['time_id'];
                $t2 = $time_labels[ (int) $members[ $j ]['time_id'] ] ?? $members[ $j ]['time_id'];
                if ( $gap === 0 ) {
                    $same_slot_pairs++;
                } elseif ( $gap === 1 ) {
                    $adjacent_pairs++;
                    $adjacent_details[] = "$n1 ($t1) / $n2 ($t2) [carpool: $cp]";
                } else {
                    $separated_pairs++;
                    $separated_details[] = "$n1 ($t1) / $n2 ($t2) [carpool: $cp]";
                }
            }
        }
    }

    $total_pairs = $same_slot_pairs + $adjacent_pairs + $separated_pairs;
    echo "Carpool pairs: $total_pairs total -- $same_slot_pairs same slot, $adjacent_pairs adjacent, $separated_pairs separated.<br>";

    if ( ! empty( $adjacent_details ) ) {
        echo "Adjacent (could be improved):<br>";
        foreach ( $adjacent_details as $d ) echo "&nbsp;&nbsp;&nbsp;$d<br>";
    }
    if ( ! empty( $separated_details ) ) {
        echo "Separated (needs manual fix):<br>";
        foreach ( $separated_details as $d ) echo "&nbsp;&nbsp;&nbsp;$d<br>";
    }
    if ( $adjacent_pairs === 0 && $separated_pairs === 0 ) {
        echo "All carpool partners are in the same time slot.<br>";
    }

    // -------------------------------------------------------
    // TRAVEL TIME CONFLICT REPORT
    // -------------------------------------------------------
    echo "<br><strong>Travel Time Conflict Report:</strong><br>";

    $remaining_530 = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id = 1 AND group_id != 99
        AND travel REGEXP '^-[ ]?5:30'
    " );
    $remaining_750 = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id = 3 AND group_id != 99
        AND travel REGEXP '^-[ ]?7:50'
    " );
    $remaining_plus530 = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id != 1 AND group_id != 99
        AND (travel LIKE '+5:30%' OR travel REGEXP '^[+]?5:30[^0-9]')
    " );

    if ( $remaining_530 === 0 ) echo "✓ No -5:30 conflicts remaining.<br>";
    else echo "⚠ $remaining_530 group(s) still have -5:30 players at 5:30pm — manual swap needed.<br>";

    if ( $remaining_750 === 0 ) echo "✓ No -7:50 conflicts remaining.<br>";
    else echo "⚠ $remaining_750 group(s) still have -7:50 players at 7:50pm — manual swap needed.<br>";

    $remaining_plus640 = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id != 2 AND group_id != 99
        AND travel LIKE '+6:40%'
    " );
    $remaining_plus750 = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id != 3 AND group_id != 99
        AND travel LIKE '+7:50%'
    " );

    if ( $remaining_plus530 === 0 ) echo "✓ All +5:30 players are at 5:30pm.<br>";
    else echo "⚠ $remaining_plus530 group(s) still have +5:30 players not at 5:30pm — manual swap needed.<br>";

    if ( $remaining_plus640 === 0 ) echo "✓ All +6:40 players are at 6:40pm.<br>";
    else echo "⚠ $remaining_plus640 group(s) still have +6:40 players not at 6:40pm — manual swap needed.<br>";

    if ( $remaining_plus750 === 0 ) echo "✓ All +7:50 players are at 7:50pm.<br>";
    else echo "⚠ $remaining_plus750 group(s) still have +7:50 players not at 7:50pm — manual swap needed.<br>";

    // CARPOOL REPORT (priority-aware score check)
    $cp_score = $carpool_score();
    if ( $cp_score === 0 ) {
        echo "✓ All carpool partners are in the same or adjacent time slots.<br>";
    } else {
        $cp_players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, m.travel, m.first_name, m.last_name
             FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id
             WHERE s.group_id != 99",
            ARRAY_A
        );
        $carpool_groups = [];
        foreach ( $cp_players as $p ) {
            $travel = $normalize_travel( $p['travel'] );
            $cp = $carpool_key( $extract_carpool( $travel ) );
            if ( ! empty( $cp ) ) {
                $carpool_groups[ $cp ][] = [
                    'name'     => $p['first_name'] . ' ' . $p['last_name'],
                    'time_id'  => $p['time_id'],
                    'priority' => $is_priority_carpool( $travel ),
                ];
            }
        }
        $issues = [];
        foreach ( $carpool_groups as $cp => $members ) {
            if ( count( $members ) < 2 ) continue;
            $is_pri = array_reduce( $members, fn( $c, $m ) => $c || $m['priority'], false );
            for ( $i = 0; $i < count( $members ); $i++ ) {
                for ( $j = $i + 1; $j < count( $members ); $j++ ) {
                    $pos1 = array_search( $members[ $i ]['time_id'], $time_ids );
                    $pos2 = array_search( $members[ $j ]['time_id'], $time_ids );
                    $gap  = abs( $pos1 - $pos2 );
                    $t1   = $time_labels[ $members[ $i ]['time_id'] ] ?? $members[ $i ]['time_id'];
                    $t2   = $time_labels[ $members[ $j ]['time_id'] ] ?? $members[ $j ]['time_id'];
                    if ( $is_pri && $gap > 0 ) {
                        $issues[] = "⚠ {$members[$i]['name']} ({$t1}) and {$members[$j]['name']} ({$t2}) carpool together but are in different time slots — manual adjustment recommended.<br>";
                    } elseif ( ! $is_pri && $gap > 1 ) {
                        $issues[] = "⚠ {$members[$i]['name']} ({$t1}) and {$members[$j]['name']} ({$t2}) carpool together but are 2 time slots apart — manual adjustment recommended.<br>";
                    }
                }
            }
        }
        if ( ! empty( $issues ) ) {
            echo "⚠ Some carpool partners could not be fully scheduled together:<br>";
            foreach ( $issues as $issue ) echo "&nbsp;&nbsp;&nbsp;" . $issue;
        } else {
            echo "✓ All carpool partners are in the same or adjacent time slots.<br>";
        }
    }

    echo '</div>';
}
