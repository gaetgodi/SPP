<?php
/* =========================================================
   SPP Time Preferences
   Version: 1.0.0
   Date: 2026-10-23

   1.0.0 -- first version. A player's travel preference (Master.travel,
   normalised: "+5:30", "-7:50 Smith", ...) names a CLOCK TIME. These
   helpers match it to the active Times slot whose T_desc starts with
   that time, whatever its position or T_ID. Before this, Create
   Schedule, Schedule Check and Adjust Schedule treated 5:30/6:40/7:50 as
   the 1st/2nd/3rd active slot, so a week with a slot turned off either
   warned ("Undefined array key 2") or, with 5:30 off, sent "+5:30"
   players to 6:40.

   A preference whose slot is off this week:
     "-X"  -- automatically satisfied (nobody can be put at X).
     "+X"  -- can't be met and is ignored (the travel conflict report
              lists the players as information, not as a conflict).
   With 5:30, 6:40 and 7:50 all active every helper answers exactly as
   the old position-based code did (tests/spp-time-prefs-test.php).

   Used by: gl-schedule-production.php (2.4.0), spp-schedule-check.php
   (1.1.0), spp-schedule-adjust.php (1.3.0). Pure functions except
   spp_travel_conflict_report(), which reads (SELECT only) the schedule
   table it is given.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

// The three clock times a travel preference can name.
function spp_time_pref_times() : array {
    return array( '5:30', '6:40', '7:50' );
}

// Clock time => T_ID of the active slot with that time (the value exactly
// as $active_times holds it), or null when no active slot has it.
// $active_times: rows of "SELECT T_ID, T_desc FROM Times WHERE Active = 1".
function spp_time_pref_slots( array $active_times ) : array {
    $slots = array_fill_keys( spp_time_pref_times(), null );
    foreach ( $active_times as $t ) {
        $t = (array) $t;
        if ( ! preg_match( '/^\s*0?(\d{1,2}:\d{2})/', (string) ( $t['T_desc'] ?? '' ), $m ) ) continue;
        if ( array_key_exists( $m[1], $slots ) && $slots[ $m[1] ] === null ) $slots[ $m[1] ] = $t['T_ID'];
    }
    return $slots;
}

// A normalised travel string's preference: array( 'sign' => '+'|'-',
// 'time' => '5:30'|'6:40'|'7:50' ), or null when it has none. Same
// prefixes the old code matched ('/^\+5:30/i', '/^-7:50/i', ...).
function spp_time_pref( $travel ) : ?array {
    if ( ! is_string( $travel ) || ! preg_match( '/^([+-])(5:30|6:40|7:50)/', $travel, $m ) ) return null;
    return array( 'sign' => $m[1], 'time' => $m[2] );
}

// Is $time_id a slot this player wants to avoid ("-X" and the slot is X)?
function spp_time_pref_conflict( $travel, $time_id, array $slots ) : bool {
    $p = spp_time_pref( $travel );
    if ( ! $p || $p['sign'] !== '-' || $slots[ $p['time'] ] === null ) return false;
    return $time_id == $slots[ $p['time'] ];
}

// Create Schedule's violation score for a player at $time_id:
// 100 = at a slot they avoid, 50 = "+X" and not at X, 0 = fine.
function spp_time_pref_violation( $travel, $time_id, array $slots ) : int {
    $p = spp_time_pref( $travel );
    if ( ! $p || $slots[ $p['time'] ] === null ) return 0;
    $here = ( $time_id == $slots[ $p['time'] ] );
    if ( $p['sign'] === '-' ) return $here ? 100 : 0;
    return $here ? 0 : 50;
}

// Count "+" and "-" preferences per slot position (index into $time_ids),
// for Create Schedule's demand-driven group distribution.
function spp_time_pref_demand( array $travels, array $slots, array $time_ids ) : array {
    $plus  = array_fill( 0, count( $time_ids ), 0 );
    $minus = array_fill( 0, count( $time_ids ), 0 );
    foreach ( $travels as $travel ) {
        $p = spp_time_pref( $travel );
        if ( ! $p || $slots[ $p['time'] ] === null ) continue;
        $pos = array_search( $slots[ $p['time'] ], $time_ids, true );
        if ( $pos === false ) continue;
        if ( $p['sign'] === '+' ) $plus[ $pos ]++;
        else $minus[ $pos ]++;
    }
    return array( $plus, $minus );
}

// Create Schedule's single-player fix pass: the slot to move a violating
// player to, or null. As before only "-5:30" (first other slot), "-7:50"
// (last other slot) and "+5:30" are handled here.
function spp_time_pref_fix_target( $travel, array $slots, array $time_ids ) {
    $p = spp_time_pref( $travel );
    if ( ! $p ) return null;
    $slot = $slots[ $p['time'] ];
    if ( $slot === null ) return null;
    if ( $p['sign'] === '-' && ( $p['time'] === '5:30' || $p['time'] === '7:50' ) ) {
        $order = $p['time'] === '5:30' ? $time_ids : array_reverse( $time_ids );
        foreach ( $order as $tid ) {
            if ( $tid !== $slot ) return $tid;
        }
        return null;
    }
    if ( $p['sign'] === '+' && $p['time'] === '5:30' ) return $slot;
    return null;
}

/**
 * The "Travel Time Conflict Report" Create Schedule and Schedule Check
 * print at the end (the block both used to carry, verbatim). Reads
 * $Schedules only. With all three slots active the output is the same
 * as before. A slot that is off: "-X" prints the usual "no conflicts"
 * line; "+X" players are listed as information ("X not offered this
 * week"), not as a conflict needing a manual swap.
 */
function spp_travel_conflict_report( string $Schedules, array $slots ) : void {
    global $wpdb;
    $match = array(
        '-5:30' => "travel REGEXP '^-[ ]?5:30'",
        '-7:50' => "travel REGEXP '^-[ ]?7:50'",
        '+5:30' => "(travel LIKE '+5:30%' OR travel REGEXP '^[+]?5:30[^0-9]')",
        '+6:40' => "travel LIKE '+6:40%'",
        '+7:50' => "travel LIKE '+7:50%'",
    );
    $find = function ( $pref, $slot_sql ) use ( $wpdb, $Schedules, $match ) {
        return $wpdb->get_results( "
        SELECT group_id, first_name, last_name FROM $Schedules
        WHERE " . ( $slot_sql !== '' ? "$slot_sql AND " : '' ) . "group_id != 99
        AND {$match[ $pref ]}
        ORDER BY group_id
    ", ARRAY_A );
    };
    $list = function ( $rows, $suffix = '' ) {
        foreach ( $rows as $v ) echo "&nbsp;&nbsp;&nbsp;Group {$v['group_id']}: {$v['first_name']} {$v['last_name']}$suffix<br>";
    };

    echo "<br><strong>Travel Time Conflict Report:</strong><br>";

    foreach ( array( '5:30', '7:50' ) as $time ) {
        $slot = $slots[ $time ];
        $rows = $slot === null ? array() : $find( "-$time", 'time_id = ' . (int) $slot );
        $n    = count( array_unique( array_column( $rows, 'group_id' ) ) );
        if ( $n === 0 ) echo "✓ No -$time conflicts remaining.<br>";
        else {
            echo "⚠ $n group(s) still have -$time players at {$time}pm — manual swap needed:<br>";
            $list( $rows );
        }
    }

    foreach ( array( '5:30', '6:40', '7:50' ) as $time ) {
        $slot = $slots[ $time ];
        if ( $slot === null ) {
            $rows = $find( "+$time", '' );
            if ( empty( $rows ) ) echo "✓ All +$time players are at {$time}pm.<br>";
            else {
                echo "ℹ " . count( $rows ) . " player(s) asked for +$time, but $time is not offered this week (preference ignored):<br>";
                $list( $rows, " — $time not offered this week" );
            }
            continue;
        }
        $rows = $find( "+$time", 'time_id != ' . (int) $slot );
        $n    = count( array_unique( array_column( $rows, 'group_id' ) ) );
        if ( $n === 0 ) echo "✓ All +$time players are at {$time}pm.<br>";
        else {
            echo "⚠ $n group(s) still have +$time players not at {$time}pm — manual swap needed:<br>";
            $list( $rows );
        }
    }
}
