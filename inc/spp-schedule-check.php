<?php
/**
 * SPP Schedule Validation Report
 * Version: 1.1.0
 * Date:    2026-10-23
 *
 * Changes from 1.0.0 (unversioned until now):
 *   - Works with any number of active time slots. It stopped with
 *     "Expected 3 active time slots, found 2." whenever a slot was off
 *     (every week since 7:50 was turned off on 2026-10-19), so Adjust
 *     Schedule's automatic check after each change showed only that
 *     line. Now only "no active slot at all" stops it.
 *   - Slot labels come from the active Times rows
 *     (spp_schedule_time_labels(), Create Schedule 2.3.1), not three
 *     hard-coded entries.
 *   - Travel preferences are matched to the slot with that clock time
 *     (spp_time_pref_slots(), inc/spp-time-prefs.php); the Travel Time
 *     Conflict Report is the shared spp_travel_conflict_report(), so it
 *     no longer assumes time_id 1/2/3 = 5:30/6:40/7:50 and lists a "+X"
 *     preference for a slot that is off as information. With all three
 *     slots active the report reads as before. Still read-only.
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
 *   time slots: the active rows of Times (T_desc gives the clock time)
 *   group_id 99 = excluded / bench
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_schedule_check', 'spp_schedule_check_shortcode' );

function spp_schedule_check_shortcode() {
    if ( spp_is_admin_or_editor() ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
    }

    ob_start();
    spp_run_schedule_check();
    return ob_get_clean();
}

function spp_run_schedule_check() {
    global $wpdb;

    if ( ! spp_is_admin_or_editor() ) {
        echo '<p class="gl-error">You do not have permission to use this tool.</p>';
        return;
    }

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
    // EVENT IDENTIFICATION (confirms which event is being checked)
    // -------------------------------------------------------
    $occ = $wpdb->get_row( $wpdb->prepare(
        "SELECT title, event_date FROM {$wpdb->prefix}gl_event_occurrences WHERE id = %d",
        $event
    ), ARRAY_A );

    $event_label = $occ
        ? esc_html( $occ['title'] ) . ' — ' . esc_html( date( 'F j, Y', strtotime( $occ['event_date'] ) ) )
        : 'Event ' . $event . ' (title/date not found in gl_event_occurrences)';

    // -------------------------------------------------------
    // TIME SLOTS
    // -------------------------------------------------------
    $active_times = $wpdb->get_results( "SELECT T_ID, T_desc FROM Times WHERE Active = 1 ORDER BY T_ID" );
    $time_ids     = array_column( $active_times, 'T_ID' );
    sort( $time_ids );

    if ( count( $time_ids ) < 1 ) {
        echo '<p class="gl-error">No active time slots in Times.</p>';
        return;
    }

    $time_labels = spp_schedule_time_labels( $active_times );
    $pref_slots  = spp_time_pref_slots( $active_times );

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
    echo '<p><strong>Schedule Validation Report</strong> — ' . $event_label . ' (event ID ' . esc_html( $event ) . ') — ' . esc_html( $schedule_rows ) . ' player(s) on the schedule</p>';

    // -------------------------------------------------------
    // PUBLISH / RESULTS RISK WARNING
    // -------------------------------------------------------
    if ( get_option( 'spp_schedule_published', 0 ) && ! get_option( 'spp_results_posted', 0 ) ) {
        $scores_entered = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $Schedules
             WHERE group_id != 99
               AND (Game1 IS NOT NULL OR Game2 IS NOT NULL OR Game3 IS NOT NULL
                    OR Game4 IS NOT NULL OR Game5 IS NOT NULL)"
        );
        if ( $scores_entered > 0 ) {
            echo '<p style="color:#c0392b;font-weight:bold;">⚠ This schedule is published, results have not been posted, '
               . 'and ' . $scores_entered . ' player(s) already have scores entered. Publish results before running '
               . 'Schedule Production again — a new run would be blocked, but manual edits here could still conflict '
               . 'with unpublished score data.</p>';
        } else {
            echo '<p style="color:#c0392b;font-weight:bold;">⚠ This schedule is published and results have not been '
               . 'posted yet, though no scores have been entered against it.</p>';
        }
    }

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
    spp_travel_conflict_report( $Schedules, $pref_slots );

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