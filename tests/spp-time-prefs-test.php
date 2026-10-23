<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Travel preferences by clock time (inc/spp-time-prefs.php 1.0.0,
 * gl-schedule-production.php 2.4.0, spp-schedule-check.php 1.1.0,
 * spp-schedule-adjust.php 1.3.0).
 *
 * The OLD position-based code is taken verbatim from commit b68f566 (the
 * last version before this change) with `git show`, cut out at fixed
 * markers and eval'd, so "identical to the old code" is checked against
 * the real old lines, not a re-typed copy. Every travel string x slot is
 * run through old and new:
 *   - 5:30/6:40/7:50 active: every decision identical, report and Schedule
 *     Check output byte-identical.
 *   - 7:50 off (Monday 2026-10-26): identical for every preference that is
 *     not 7:50; "-7:50" satisfied, "+7:50" ignored, no warnings.
 *   - 5:30 off: "+5:30" ignored (not sent to 6:40), "+6:40" lands on 6:40.
 * Every PHP warning/notice raised by the code under test is a failure.
 *
 * Writes: one throwaway table zzstp_test_Schedules (for the report), behind
 * a write firewall that refuses everything else. Times is answered in
 * memory (derived table) for the Schedule Check runs; live Schedules,
 * Master and Times are only read. Checksums compared at the end.
 *
 * Run (live code):      php tests/spp-time-prefs-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-time-prefs-test.php
 */

$stp_theme = getenv( 'SPP_TEST_THEME_DIR' );
if ( $stp_theme ) {
    $GLOBALS['wp_filter'] = array( 'stylesheet_directory' => array( 10 => array( array( 'function' => fn() => $stp_theme, 'accepted_args' => 1 ) ) ) );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ! function_exists( 'spp_time_pref_slots' ) ) { echo "ABORT: spp-time-prefs.php not loaded\n"; exit( 1 ); }
echo 'Theme: ' . get_stylesheet_directory() . "\n";

global $wpdb;
const STP_BASE = 'b68f566';
$T = 'zzstp_test_Schedules';
$REAL = array();
foreach ( array( 'Schedules', 'Times', 'Master' ) as $t ) $REAL[ $t ] = $wpdb->get_var( "CHECKSUM TABLE $t", 1 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

// Write firewall: only zzstp_test_* tables.
$GLOBALS['stp_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    if ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?zzstp_test_\w+`?/i', $q )
      || preg_match( '/^\s*INSERT\s+INTO\s+`?zzstp_test_\w+`?/i', $q ) ) return $q;
    $GLOBALS['stp_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
    return "SELECT 'stp_test_blocked_write'";
}, 10 );

// Times answered in memory when $STP_TIMES is set (T_ID => [T_desc, Active]).
$GLOBALS['STP_TIMES'] = null;
add_filter( 'query', function ( $q ) {
    if ( $GLOBALS['STP_TIMES'] === null ) return $q;
    return preg_replace_callback( '/(\bFROM|\bJOIN|,)(\s+)`?Times`?(?![A-Za-z0-9_])(\s+(\w+))?/', function ( $m ) {
        $rows = array();
        foreach ( $GLOBALS['STP_TIMES'] as $id => $t ) $rows[] = sprintf( "SELECT %d AS T_ID, '%s' AS T_desc, %d AS Active", $id, $t[0], $t[1] );
        $next  = $m[4] ?? '';
        $alias = $next !== '' && ! preg_match( '/^(WHERE|ON|JOIN|LEFT|RIGHT|INNER|CROSS|ORDER|GROUP|LIMIT|HAVING|UNION|AS)$/i', $next );
        return $m[1] . $m[2] . '(' . implode( ' UNION ALL ', $rows ) . ')' . ( $alias ? $m[3] : ' AS Times' . ( $m[3] ?? '' ) );
    }, $q );
}, 5 );

// Warnings/notices from the code under test become failures.
function stp_run( callable $fn, &$err ) {
    $err = null;
    set_error_handler( function ( $no, $msg, $f, $l ) { throw new ErrorException( $msg, 0, $no, $f, $l ); } );
    try { return $fn(); } catch ( Throwable $e ) { $err = get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(); return null; }
    finally { restore_error_handler(); }
}

// ---------------------------------------------------------------- old code
$root = get_stylesheet_directory();
$OLD  = array();
foreach ( array( 'prod' => 'inc/gl-schedule-production.php', 'adjust' => 'inc/spp-schedule-adjust.php', 'check' => 'inc/spp-schedule-check.php' ) as $k => $f ) {
    $OLD[ $k ] = (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . STP_BASE . ':' . $f );
}
// Text from $start (after $after, if given) through the end of $end, both included.
function stp_cut( $src, $start, $end, $after = null ) {
    $from = $after === null ? 0 : strpos( $src, $after );
    if ( $from === false ) throw new RuntimeException( "marker not found: $after" );
    $a = strpos( $src, $start, $from );
    if ( $a === false ) throw new RuntimeException( "marker not found: $start" );
    $b = strpos( $src, $end, $a );
    if ( $b === false ) throw new RuntimeException( "marker not found: $end" );
    return substr( $src, $a, $b + strlen( $end ) - $a );
}
$old = array();
$old['conflict'] = eval( 'return function ( $travel, $time_id, $time_ids ) { if ( empty( $travel ) ) return false; '
    . stp_cut( $OLD['prod'], '$t_pos = array_search($time_id, $time_ids);', 'return false;', '$has_travel_conflict = function' ) . ' };' );
$old['violation'] = eval( 'return function ( $travel, $time_id, $time_ids ) { if ( empty( $travel ) ) return 0; '
    . stp_cut( $OLD['prod'], '$t_pos = array_search($time_id, $time_ids);', 'return 0;', '$score_violation = function' ) . ' };' );
$old['demand'] = eval( 'return function ( $travels, $num_times ) { $plus_demand = array_fill(0, $num_times, 0); $minus_demand = array_fill(0, $num_times, 0);'
    . ' foreach ( $travels as $travel ) { if (empty($travel)) continue; '
    . stp_cut( $OLD['prod'], "if (preg_match('/^\\+5:30/i', \$travel)) \$plus_demand[0]++;", "\$minus_demand[2]++;" ) . ' } return array( $plus_demand, $minus_demand ); };' );
$old['fix'] = eval( 'return function ( $travel1, $time_ids ) { '
    . stp_cut( $OLD['prod'], '$target_tid = null;', '$target_tid = $time_ids[0];' . "\n        }", '$travel1    = $normalize_travel($tr1);' ) . ' return $target_tid; };' );
$old['p5'] = eval( 'return function ( $pp_travel, $time_ids ) { '
    . str_replace( 'continue;', 'return null;', stp_cut( $OLD['prod'], '$target_pos = null;', '$target_tid_p5 = $time_ids[$target_pos];' ) ) . ' return array( $target_pos, $target_tid_p5 ); };' );
$old['cm'] = eval( 'return function ( $cm_travel ) { '
    . stp_cut( $OLD['prod'], '$cm_target = null;', '$cm_target = 2;' ) . ' return $cm_target; };' );
$old['patterns'] = eval( stp_cut( $OLD['prod'], '$pref_patterns = array(', ');' ) . ' return $pref_patterns;' );
$old_report_src = stp_cut( $OLD['prod'], 'echo "<br><strong>Travel Time Conflict Report:</strong><br>";', "manual swap needed:<br>\";\n        foreach (\$violations_plus750 as \$v) echo \"&nbsp;&nbsp;&nbsp;Group {\$v['group_id']}: {\$v['first_name']} {\$v['last_name']}<br>\";\n    }" );
$old['report'] = eval( 'return function ( $Schedules ) { global $wpdb; ' . $old_report_src . ' };' );
eval( '?>' . str_replace(
    array( "add_shortcode( 'spp_schedule_check', 'spp_schedule_check_shortcode' );", 'function spp_schedule_check_shortcode', 'function spp_run_schedule_check' ),
    array( '', 'function old_spp_schedule_check_shortcode', 'function old_spp_run_schedule_check' ),
    $OLD['check'] ) );
eval( 'function old_spp_sa2_check_travel_for_new_time' . substr( stp_cut( $OLD['adjust'], '( $roster, $new_time_id, $time_ids ) {', "    return \$violations;\n}", 'function spp_sa2_check_travel_for_new_time' ), 0 ) );
eval( 'function old_spp_sa2_satisfying_positions' . stp_cut( $OLD['adjust'], '( $travel ) {', "    return array();\n}", 'function spp_sa2_satisfying_positions' ) );
ok( count( $old ) === 8 && function_exists( 'old_spp_run_schedule_check' ) && function_exists( 'old_spp_sa2_check_travel_for_new_time' ) && function_exists( 'old_spp_sa2_satisfying_positions' ),
    'old code loaded from ' . STP_BASE . ' (8 Create Schedule blocks, Schedule Check, 2 Adjust functions)' );

// ---------------------------------------------------------------- inputs
$RAW = array( '', 'Bob', 'carpool +Smith', '+5:30', '-5:30', '+6:40', '-6:40', '+7:50', '-7:50', '5:30', '6:40 pm', '7:50 Smith',
              '- 7:50', '- 5:30 Lee', '+6:40pm', '-5:30Jones', '+7:50 carpool Al', '+5:30 +Kim', '-6:40 Wu', '+5:30pm Ann' );
$TRAVELS = array_values( array_unique( array_map( 'spp_sa2_normalize_travel', $RAW ) ) );
function stp_times( array $rows ) { return array_map( fn( $r ) => (object) array( 'T_ID' => (string) $r[0], 'T_desc' => $r[1] ), $rows ); }
$SETUPS = array(
    '3 slots'   => stp_times( array( array( 1, '5:30 pm' ), array( 2, '6:40 pm' ), array( 3, '7:50 pm' ) ) ),
    '7:50 off'  => stp_times( array( array( 1, '5:30 pm' ), array( 2, '6:40 pm' ) ) ),
    '5:30 off'  => stp_times( array( array( 2, '6:40 pm' ), array( 3, '7:50 pm' ) ) ),
);
function stp_ids( $active ) { $ids = array_column( $active, 'T_ID' ); sort( $ids ); return $ids; }
// Slot ids to place a player at: every active one (string and int), and a non-slot.
function stp_places( $ids ) { $p = array(); foreach ( $ids as $i ) { $p[] = $i; $p[] = (int) $i; } $p[] = 99; return $p; }
$is750 = fn( $t ) => (bool) preg_match( '/^[+-]7:50/', $t );
$is530 = fn( $t ) => (bool) preg_match( '/^[+-]5:30/', $t );

echo "\nSlot map\n";
foreach ( $SETUPS as $name => $active ) {
    $slots = stp_run( fn() => spp_time_pref_slots( $active ), $err );
    ok( $err === null, "$name: built without a warning" );
    echo '     ' . json_encode( $slots ) . "\n";
}
$s3 = spp_time_pref_slots( $SETUPS['3 slots'] ); $ids3 = stp_ids( $SETUPS['3 slots'] );
ok( $s3['5:30'] === $ids3[0] && $s3['6:40'] === $ids3[1] && $s3['7:50'] === $ids3[2], '3 slots: 5:30/6:40/7:50 => exactly $time_ids[0]/[1]/[2] (same value and type)' );
$s2 = spp_time_pref_slots( $SETUPS['7:50 off'] );
$ids2 = stp_ids( $SETUPS['7:50 off'] );
ok( $s2 === array( '5:30' => '1', '6:40' => '2', '7:50' => null ), '7:50 off: 7:50 => null' );
$s5 = spp_time_pref_slots( $SETUPS['5:30 off'] );
ok( $s5 === array( '5:30' => null, '6:40' => '2', '7:50' => '3' ), '5:30 off: 5:30 => null, 6:40 => slot 2 (not the first active slot)' );
ok( spp_time_pref_slots( stp_times( array( array( 1, '05:30 PM' ), array( 9, '' ) ) ) ) === array( '5:30' => '1', '6:40' => null, '7:50' => null ), 'leading zero / empty T_desc handled' );

// ---------------------------------------------------------------- decisions
// $same: compare old and new for this travel? $want: expected new value when not compared.
function stp_compare( $label, $active, $old_fn, $new_fn, $same, $want ) {
    $ids = stp_ids( $active ); $slots = spp_time_pref_slots( $active );
    global $TRAVELS;
    $n = 0; $bad = array(); $warn = array();
    foreach ( $TRAVELS as $t ) {
        foreach ( stp_places( $ids ) as $at ) {
            $new = stp_run( fn() => $new_fn( $t, $at, $slots, $ids ), $e ); if ( $e ) $warn[] = "$t@$at $e";
            if ( $same( $t ) ) {
                $o = stp_run( fn() => $old_fn( $t, $at, $ids ), $e2 ); if ( $e2 ) $bad[] = "old warned $t@$at: $e2";
                if ( $o !== $new ) $bad[] = "$t@" . var_export( $at, true ) . ': old ' . var_export( $o, true ) . ' new ' . var_export( $new, true );
            } elseif ( $want !== null ) {
                $w = $want( $t, $at, $slots );
                if ( $w !== $new ) $bad[] = "$t@" . var_export( $at, true ) . ': want ' . var_export( $w, true ) . ' new ' . var_export( $new, true );
            }
            $n++;
        }
    }
    ok( empty( $bad ) && empty( $warn ), "$label ($n cases)" . ( $bad ? ': ' . implode( ' | ', array_slice( $bad, 0, 3 ) ) : '' ) . ( $warn ? ' WARN: ' . implode( ' | ', array_slice( $warn, 0, 3 ) ) : '' ) );
}
$all  = fn( $t ) => true;
$no750 = fn( $t ) => ! preg_match( '/^[+-]7:50/', $t );

$checks = array(
    'conflict (-X at X)' => array( $old['conflict'], fn( $t, $at, $s, $ids ) => spp_time_pref_conflict( $t, $at, $s ),
        fn( $t, $at, $s ) => false ),
    'violation score' => array( $old['violation'], fn( $t, $at, $s, $ids ) => spp_time_pref_violation( $t, $at, $s ),
        fn( $t, $at, $s ) => 0 ),
    'fix-pass target' => array( fn( $t, $at, $ids ) => ( $old['fix'] )( $t, $ids ), fn( $t, $at, $s, $ids ) => spp_time_pref_fix_target( $t, $s, $ids ),
        fn( $t, $at, $s ) => null ),
    'Phase 5 target slot' => array( function ( $t, $at, $ids ) use ( $old ) { $r = ( $old['p5'] )( $t, $ids ); return $r === null ? null : $r[1]; },
        function ( $t, $at, $s, $ids ) { $p = spp_time_pref( $t ); return ( ! $p || $p['sign'] !== '+' ) ? null : $s[ $p['time'] ]; },
        fn( $t, $at, $s ) => null ),
    'Adjust: travel warnings for a move' => array(
        fn( $t, $at, $ids ) => old_spp_sa2_check_travel_for_new_time( array( array( 'travel' => $t, 'first_name' => 'A', 'last_name' => 'B' ) ), $at, $ids ),
        fn( $t, $at, $s, $ids ) => spp_sa2_check_travel_for_new_time( array( array( 'travel' => $t, 'first_name' => 'A', 'last_name' => 'B' ) ), $at, $s ),
        fn( $t, $at, $s ) => array() ),
    'Adjust: slots that satisfy (swap by player)' => array(
        function ( $t, $at, $ids ) { $p = old_spp_sa2_satisfying_positions( $t ); return $p ? array_map( fn( $i ) => $ids[ $i ], $p ) : null; },
        fn( $t, $at, $s, $ids ) => spp_sa2_satisfying_time_ids( $t, $s, $ids ),
        null ),
);

echo "\n3 slots active: every decision identical to " . STP_BASE . "\n";
foreach ( $checks as $label => $c ) stp_compare( $label, $SETUPS['3 slots'], $c[0], $c[1], $all, null );
$cm_same = true;
foreach ( $TRAVELS as $t ) { $o = ( $old['cm'] )( $t ); $p = spp_time_pref( $t ); $n = ( $p && $p['sign'] === '+' ) ? $p['time'] : null;
    foreach ( $TRAVELS as $u ) { $o2 = ( $old['cm'] )( $u ); $p2 = spp_time_pref( $u ); $n2 = ( $p2 && $p2['sign'] === '+' ) ? $p2['time'] : null;
        if ( ( $o === $o2 ) !== ( $n === $n2 ) ) $cm_same = false; } }
ok( $cm_same, 'Phase 5 carpool "all want the same slot": same answer for every pair of travel strings' );
$pats_same = true;
$new_pats  = eval( stp_cut( file_get_contents( "$root/inc/gl-schedule-production.php" ), '$pref_patterns = array(', ');' ) . ' return $pref_patterns;' );
foreach ( $old['patterns'] as $i => $op ) {
    if ( $ids3[ $op['pos'] ] !== $s3[ $new_pats[ $i ]['time'] ] || $op['pattern'] !== $new_pats[ $i ]['pattern'] || $op['neg_pattern'] !== $new_pats[ $i ]['neg_pattern'] || $op['time_label'] !== $new_pats[ $i ]['time_label'] ) $pats_same = false;
}
ok( $pats_same && count( $new_pats ) === 3, 'Pass 1.5: each + pattern targets the same slot as before ($time_ids[pos] === $pref_slots[time]), same patterns and labels' );
$demand_same = true;
foreach ( $TRAVELS as $a ) foreach ( $TRAVELS as $b ) {
    $tr = array( $a, $b, $a );
    if ( ( $old['demand'] )( $tr, 3 ) !== spp_time_pref_demand( $tr, $s3, $ids3 ) ) $demand_same = false;
}
ok( $demand_same, 'demand count (+/- per slot) identical for every pair of travel strings' );

echo "\n7:50 off (Monday): identical for everyone without a 7:50 preference\n";
foreach ( $checks as $label => $c ) {
    if ( $label === 'Adjust: slots that satisfy (swap by player)' ) continue;
    stp_compare( $label, $SETUPS['7:50 off'], $c[0], $c[1], $no750, $c[2] );
}
// Swap by player: the old "-5:30 => positions 1, 2" (6:40 or 7:50) also touched 7:50 and
// warned with 7:50 off, so -5:30 is checked against the right answer, not the old one.
$c = $checks['Adjust: slots that satisfy (swap by player)'];
stp_compare( 'Adjust: slots that satisfy (swap by player), -5:30 excluded', $SETUPS['7:50 off'], $c[0], $c[1], fn( $t ) => $no750( $t ) && ! preg_match( '/^-5:30/', $t ),
    function ( $t, $at, $s ) { $p = spp_time_pref( $t ); return $p['sign'] === '+' ? array() : ( $p['time'] === '5:30' ? array( '2' ) : array( '1', '2' ) ); } );
stp_run( fn() => old_spp_sa2_satisfying_positions( '-5:30' ) && array_map( fn( $i ) => $ids2[ $i ], old_spp_sa2_satisfying_positions( '-5:30' ) ), $e_old );
ok( spp_sa2_satisfying_time_ids( '-5:30', $s2, $ids2 ) === array( '2' ) && $e_old !== null,
    'Swap by player, -5:30: searches 6:40 only (old code looked up a 7:50 slot that is off and warned: ' . $e_old . ')' );
$d = stp_run( fn() => spp_time_pref_demand( array( '+7:50', '-7:50', '+5:30', '-6:40' ), $s2, $ids2 ), $err );
ok( $err === null && $d === array( array( 1, 0 ), array( 0, 1 ) ), 'demand: +7:50 / -7:50 not counted, others as before, no warning: ' . json_encode( $d ) );
$demand_same = true;
foreach ( $TRAVELS as $a ) foreach ( $TRAVELS as $b ) {
    if ( $is750( $a ) || $is750( $b ) ) continue;
    if ( ( $old['demand'] )( array( $a, $b ), 2 ) !== spp_time_pref_demand( array( $a, $b ), $s2, $ids2 ) ) $demand_same = false;
}
ok( $demand_same, 'demand: identical to old for every pair without 7:50' );
stp_run( fn() => ( $old['violation'] )( '+7:50', '1', $ids2 ), $e_old );
stp_run( fn() => ( $old['demand'] )( array( '+7:50' ), 2 ), $e_old2 );
ok( $e_old === null && ( $old['violation'] )( '+7:50', '1', $ids2 ) === 50 && $e_old2 !== null,
    'for comparison, old code: +7:50 scored a permanent 50 violation, demand count warned (' . $e_old2 . ')' );

echo "\n5:30 off\n";
$ids5 = stp_ids( $SETUPS['5:30 off'] );
ok( spp_time_pref_violation( '+5:30', '2', $s5 ) === 0 && spp_time_pref_violation( '+5:30', '3', $s5 ) === 0, '+5:30: no violation anywhere (ignored)' );
ok( spp_time_pref_fix_target( '+5:30', $s5, $ids5 ) === null, '+5:30: fix pass does not move them' );
ok( ( function () use ( $s5 ) { $p = spp_time_pref( '+5:30' ); return $s5[ $p['time'] ]; } )() === null, '+5:30: Pass 1.5 / Phase 5 have no target slot (skipped)' );
ok( ( $old['fix'] )( '+5:30', $ids5 ) === '2', 'for comparison, old code sent +5:30 to slot 2 (6:40)' );
ok( spp_time_pref_violation( '+6:40', '2', $s5 ) === 0 && spp_time_pref_violation( '+6:40', '3', $s5 ) === 50, '+6:40: satisfied at 6:40 (slot 2), violation at 7:50' );
ok( $s5['6:40'] === '2', '+6:40: Pass 1.5 / Phase 5 target is slot 2 (6:40)' );
ok( spp_time_pref_conflict( '-5:30', '2', $s5 ) === false && spp_time_pref_conflict( '-7:50', '3', $s5 ) === true, '-5:30 satisfied (6:40 is not 5:30), -7:50 still a conflict at 7:50' );
ok( spp_time_pref_demand( array( '+5:30', '+6:40', '-7:50' ), $s5, $ids5 ) === array( array( 1, 0 ), array( 0, 1 ) ), 'demand: +5:30 not counted, +6:40 at 6:40, -7:50 at 7:50' );
ok( spp_sa2_satisfying_time_ids( '+5:30', $s5, $ids5 ) === array() && spp_sa2_satisfying_time_ids( '+6:40', $s5, $ids5 ) === array( '2' ), 'Adjust: +5:30 nothing to search for, +6:40 searches slot 2' );
ok( spp_sa2_check_travel_for_new_time( array( array( 'travel' => '+5:30', 'first_name' => 'A', 'last_name' => 'B' ) ), 3, $s5 ) === array(), 'Adjust: +5:30 never a warning' );

// ---------------------------------------------------------------- report
echo "\nTravel Time Conflict Report (Create Schedule and Schedule Check)\n";
$wpdb->query( "DROP TABLE IF EXISTS $T" );
$wpdb->query( "CREATE TABLE $T (group_id INT, first_name VARCHAR(30), last_name VARCHAR(30), time_id INT, travel VARCHAR(100))" );
$rows = array(
    array( 1, 'Ann', 'Avoids', 1, '-5:30' ), array( 1, 'Bo', 'Plain', 1, '' ), array( 2, 'Cy', 'Wants', 2, '+5:30 Lee' ), array( 2, 'Di', 'Bare', 2, '5:30 car' ),
    array( 3, 'Ed', 'Late', 3, '-7:50' ), array( 3, 'Flo', 'Mid', 3, '+6:40' ), array( 4, 'Gus', 'Ok', 2, '+6:40' ), array( 5, 'Hal', 'Wants750', 1, '+7:50' ),
    array( 99, 'Ivy', 'Bench', 99, '+5:30' ), array( 6, 'Jo', 'Spaced', 1, '- 5:30' ), array( 6, 'Kim', 'Ok750', 3, '+7:50 x' ),
);
foreach ( $rows as $r ) $wpdb->insert( $T, array_combine( array( 'group_id', 'first_name', 'last_name', 'time_id', 'travel' ), $r ) );
ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $T" ) === count( $rows ), 'throwaway table with ' . count( $rows ) . ' rows' );
$render = function ( callable $fn ) { ob_start(); try { $fn(); } finally { $o = ob_get_clean(); } return $o; };
$old_out = stp_run( fn() => $render( fn() => ( $old['report'] )( $T ) ), $e1 );
$new_out = stp_run( fn() => $render( fn() => spp_travel_conflict_report( $T, $s3 ) ), $e2 );
ok( $e1 === null && $e2 === null && $old_out === $new_out && strpos( $new_out, 'manual swap needed' ) !== false, '3 slots: output byte-identical to the old block (' . strlen( $new_out ) . ' bytes, with conflicts in it)' );
// Same rows without any 7:50 preference or 7:50 slot.
$wpdb->query( "DROP TABLE IF EXISTS $T" );
$wpdb->query( "CREATE TABLE $T (group_id INT, first_name VARCHAR(30), last_name VARCHAR(30), time_id INT, travel VARCHAR(100))" );
foreach ( $rows as $r ) if ( strpos( $r[4], '7:50' ) === false && $r[3] !== 3 ) $wpdb->insert( $T, array_combine( array( 'group_id', 'first_name', 'last_name', 'time_id', 'travel' ), $r ) );
$old_out = $render( fn() => ( $old['report'] )( $T ) );
$new_out = stp_run( fn() => $render( fn() => spp_travel_conflict_report( $T, $s2 ) ), $e2 );
ok( $e2 === null && $old_out === $new_out, '7:50 off, nobody with a 7:50 preference: output identical to the old block' );
$wpdb->insert( $T, array( 'group_id' => 5, 'first_name' => 'Hal', 'last_name' => 'Wants750', 'time_id' => 1, 'travel' => '+7:50' ) );
$wpdb->insert( $T, array( 'group_id' => 1, 'first_name' => 'Ed', 'last_name' => 'Late', 'time_id' => 2, 'travel' => '-7:50' ) );
$new_out = stp_run( fn() => $render( fn() => spp_travel_conflict_report( $T, $s2 ) ), $e2 );
$old_out = $render( fn() => ( $old['report'] )( $T ) );
ok( $e2 === null && strpos( $new_out, 'Group 5: Hal Wants750 — 7:50 not offered this week' ) !== false && strpos( $new_out, 'still have +7:50' ) === false
    && strpos( $new_out, '✓ No -7:50 conflicts remaining.' ) !== false, '7:50 off: +7:50 player listed as information ("7:50 not offered this week"), not a manual swap; -7:50 satisfied' );
ok( strpos( $old_out, 'still have +7:50 players not at 7:50pm — manual swap needed' ) !== false, 'for comparison, old block called it a conflict needing a manual swap' );
ok( str_replace( strstr( $new_out, 'ℹ' ), '', $new_out ) === str_replace( strstr( $old_out, '⚠ 1 group(s) still have +7:50' ), '', $old_out ), 'everything before the +7:50 lines identical to the old block' );
$new_out = stp_run( fn() => $render( fn() => spp_travel_conflict_report( $T, $s5 ) ), $e2 );
ok( $e2 === null && strpos( $new_out, 'Cy Wants — 5:30 not offered this week' ) !== false && strpos( $new_out, 'Group 99' ) === false && strpos( $new_out, '✓ No -5:30 conflicts remaining.' ) !== false,
    '5:30 off: +5:30 players listed as information (bench excluded), -5:30 satisfied' );
$wpdb->query( "DROP TABLE IF EXISTS $T" );

// ---------------------------------------------------------------- Schedule Check page
echo "\nSchedule Check on the live Schedules (read-only), Times in memory\n";
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );
$GLOBALS['STP_TIMES'] = array( 1 => array( '5:30 pm', 1 ), 2 => array( '6:40 pm', 1 ), 3 => array( '7:50 pm', 1 ) );
$o = stp_run( fn() => $render( 'old_spp_run_schedule_check' ), $e1 );
$n = stp_run( fn() => $render( 'spp_run_schedule_check' ), $e2 );
ok( $e1 === null && $e2 === null && $o === $n && strpos( $n, 'Travel Time Conflict Report' ) !== false, '3 slots: page output byte-identical to the old Schedule Check (' . strlen( $n ) . ' bytes)' . ( $e2 ? " $e2" : '' ) );
$GLOBALS['STP_TIMES'][3][1] = 0;
$o = stp_run( fn() => $render( 'old_spp_run_schedule_check' ), $e1 );
$n = stp_run( fn() => $render( 'spp_run_schedule_check' ), $e2 );
ok( strpos( $o, 'Expected 3 active time slots, found 2' ) !== false, 'for comparison, the old Schedule Check stopped: "Expected 3 active time slots, found 2."' );
ok( $e2 === null && strpos( $n, 'Expected 3' ) === false && strpos( $n, 'Travel Time Conflict Report' ) !== false && strpos( $n, 'Carpool Adjacency Report' ) !== false, '7:50 off: full report, no warnings' . ( $e2 ? " $e2" : '' ) );
$GLOBALS['STP_TIMES'] = array( 1 => array( '5:30 pm', 0 ), 2 => array( '6:40 pm', 1 ), 3 => array( '7:50 pm', 1 ) );
$n = stp_run( fn() => $render( 'spp_run_schedule_check' ), $e2 );
ok( $e2 === null && strpos( $n, 'Travel Time Conflict Report' ) !== false, '5:30 off: full report, no warnings' . ( $e2 ? " $e2" : '' ) );
$GLOBALS['STP_TIMES'] = null;
wp_set_current_user( 0 );

// ---------------------------------------------------------------- code checks
echo "\nCode no longer maps preferences to positions (comments ignored)\n";
// A file's PHP code without its comments (the changelogs name the old code).
function stp_code( $file ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $file ) ) as $tok ) {
        if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) continue;
        $out .= is_array( $tok ) ? $tok[1] : $tok;
    }
    return $out;
}
$src = stp_code( "$root/inc/gl-schedule-production.php" );
preg_match_all( '/\$time_ids\[[^\]]*\]/', $src, $m );
ok( $m[0] === array( '$time_ids[min($pos1, $pos2) + 1]' ), 'Create Schedule: only $time_ids[...] left is the carpool middle slot: ' . implode( ', ', $m[0] ) );
ok( ! preg_match( "/'pos' =>|\\\$t_pos|\\\$target_pos|\\\$cm_target = [0-9]/", $src ), 'Create Schedule: no slot-position preference code left' );
ok( substr_count( $src, 'spp_travel_conflict_report($Schedules, $pref_slots);' ) === 1 && strpos( $src, 'WHERE time_id = 1 AND' ) === false, 'Create Schedule: report is the shared function, no hard-coded time_id 1/2/3' );
$chk = stp_code( "$root/inc/spp-schedule-check.php" );
ok( strpos( $chk, 'Expected 3 active' ) === false && strpos( $chk, "'5:30pm'" ) === false && strpos( $chk, 'spp_travel_conflict_report( $Schedules, $pref_slots );' ) !== false, 'Schedule Check: no 3-slot stop, labels and report shared' );
$adj = stp_code( "$root/inc/spp-schedule-adjust.php" );
ok( strpos( $adj, 'new_pos' ) === false && strpos( $adj, 'spp_sa2_satisfying_positions' ) === false && strpos( $adj, '$time_ids[ $pos ]' ) === false, 'Adjust: no slot-position preference code left' );

// ---------------------------------------------------------------- live untouched
echo "\nLive data untouched\n";
ok( ! $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzstp\\_test\\_%'" ), 'throwaway table dropped' );
$same = true;
foreach ( $REAL as $t => $sum ) if ( $wpdb->get_var( "CHECKSUM TABLE $t", 1 ) !== $sum ) $same = false;
ok( $same, 'live Schedules, Times and Master checksums unchanged' );
$blocked = $GLOBALS['stp_blocked'];
ok( empty( $blocked ), 'firewall blocked no writes' . ( $blocked ? ': ' . implode( ' | ', $blocked ) : '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
