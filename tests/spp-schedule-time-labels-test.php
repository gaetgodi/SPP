<?php
// Command line only: never run from a web request.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Create Schedule time-slot labels (gl-schedule-production.php 2.3.1).
 *
 * Needs no WordPress and no database: the file is loaded with ABSPATH and
 * add_shortcode() stubbed (it only defines functions at load time), and
 * spp_schedule_time_labels() is fed made-up Times rows. Every PHP warning,
 * notice or deprecation is turned into an exception, so a label lookup on
 * a missing slot fails the check instead of scrolling past.
 *
 * Checks, with 1, 2, 3 and 4 active slots (rows as $wpdb returns them,
 * objects with string T_IDs): one label per active slot, in order, with
 * the old '5:30pm' style text; every label lookup the progress lines make
 * for an active slot works without a warning; and the old hard-coded
 * three-entry array does warn with two slots (the 2026-10-19 bug).
 *
 * Run (live code):      php tests/spp-schedule-time-labels-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-schedule-time-labels-test.php
 */

define( 'ABSPATH', __DIR__ );
function add_shortcode() {}
$dir  = getenv( 'SPP_TEST_THEME_DIR' ) ?: dirname( __DIR__ );
$file = "$dir/inc/gl-schedule-production.php";
echo "File: $file\n";

set_error_handler( function ( $no, $msg, $f, $line ) { throw new ErrorException( $msg, 0, $no, $f, $line ); } );
require $file;
if ( ! function_exists( 'spp_schedule_time_labels' ) ) { echo "ABORT: 2.3.1 code not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
// Runs $fn; returns its result, or the warning text if it raised one.
function no_warning( callable $fn, &$err ) {
    $err = null;
    try { return $fn(); } catch ( Throwable $e ) { $err = get_class( $e ) . ': ' . $e->getMessage(); return null; }
}
function times( array $rows ) {
    return array_map( function ( $r ) { return (object) array( 'T_ID' => (string) $r[0], 'T_desc' => $r[1] ); }, $rows );
}

$cases = array(
    '1 active slot (5:30 only)'          => array( times( array( array( 1, '5:30 pm' ) ) ), array( 1 => '5:30pm' ) ),
    '2 active slots (7:50 off)'          => array( times( array( array( 1, '5:30 pm' ), array( 2, '6:40 pm' ) ) ), array( 1 => '5:30pm', 2 => '6:40pm' ) ),
    '3 active slots'                     => array( times( array( array( 1, '5:30 pm' ), array( 2, '6:40 pm' ), array( 3, '7:50 pm' ) ) ), array( 1 => '5:30pm', 2 => '6:40pm', 3 => '7:50pm' ) ),
    '2 active slots (5:30 off)'          => array( times( array( array( 2, '6:40 pm' ), array( 3, '7:50 pm' ) ) ), array( 2 => '6:40pm', 3 => '7:50pm' ) ),
    '4 active slots, one with no T_desc' => array( times( array( array( 1, '5:30 pm' ), array( 2, '6:40 pm' ), array( 3, '7:50 pm' ), array( 4, '' ) ) ), array( 1 => '5:30pm', 2 => '6:40pm', 3 => '7:50pm', 4 => 'slot 4' ) ),
);

foreach ( $cases as $name => list( $active_times, $want ) ) {
    echo "$name\n";
    $labels = no_warning( fn() => spp_schedule_time_labels( $active_times ), $err );
    ok( $err === null, 'labels built without a warning' . ( $err ? " ($err)" : '' ) );
    ok( $labels === $want, 'one label per active slot, in order: ' . json_encode( $labels ) );
    // The lookups the progress lines make: $time_labels[$tid] for any active slot, string or int id.
    $time_ids = array_column( $active_times, 'T_ID' );
    $out = no_warning( function () use ( $labels, $time_ids ) {
        $s = '';
        foreach ( $time_ids as $a ) foreach ( $time_ids as $b ) $s .= "({$labels[$a]}→{$labels[(int) $b]})";
        return $s;
    }, $err );
    ok( $err === null && substr_count( $out, '→' ) === count( $time_ids ) ** 2 && strpos( $out, '()' ) === false, 'every slot-to-slot label lookup works, none empty' . ( $err ? " ($err)" : '' ) );
}

echo "Rows as arrays (ARRAY_A) work too\n";
ok( spp_schedule_time_labels( array( array( 'T_ID' => '2', 'T_desc' => '6:40 PM' ) ) ) === array( 2 => '6:40PM' ), 'array rows, upper-case PM kept' );

echo "The call site uses it\n";
$src = file_get_contents( $file );
ok( strpos( $src, '$time_labels = spp_schedule_time_labels($active_times);' ) !== false, 'Create Schedule builds $time_labels from the active slots' );
ok( strpos( $src, "\$time_ids[2] => '7:50pm'" ) === false, 'the hard-coded three-slot array is gone' );
ok( strpos( $src, 'SELECT T_ID, T_desc FROM Times WHERE Active = 1 ORDER BY T_ID' ) !== false, 'active-times query selects T_desc' );

echo "Old code, for comparison\n";
$time_ids = array( '1', '2' );
no_warning( fn() => array( $time_ids[0] => '5:30pm', $time_ids[1] => '6:40pm', $time_ids[2] => '7:50pm' ), $err );
ok( $err !== null && strpos( $err, 'Undefined array key 2' ) !== false, 'the old hard-coded array warns with two slots: ' . $err );

restore_error_handler();
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
