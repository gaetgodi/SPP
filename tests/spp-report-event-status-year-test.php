<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GL Event Status report (spp-reports.php 1.17.0) tests: current calendar
 * year in wp_timezone(), newest first, cancelled left out, no "today
 * onward" cut.
 *
 * Writes NOTHING: a 'query' filter throws on anything that isn't
 * SELECT/SHOW, and the report's gl_event_occurrences / gl_registrations
 * are swapped in memory for derived tables of fixture rows (no table is
 * created). A last section reads the live occurrences unchanged.
 *
 * Run: php tests/spp-report-event-status-year-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}

global $wpdb;
$pass = 0; $fail = 0;
function esy_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

$WRITES = 0;
add_filter( 'query', function( $q ) {
    global $WRITES;
    if ( preg_match( '/^\s*(SELECT|SHOW)\b/i', $q ) ) return $q;
    $WRITES++;
    throw new RuntimeException( 'write blocked by test: ' . substr( trim( $q ), 0, 80 ) );
} );

// -- Fixtures: id => [date, time, title, cancelled] --------------------------
$FIX = array(
    1 => array( '2025-12-31', '19:00:00', 'ZZ Dec 31 last year', 0 ),
    2 => array( '2026-01-01', '09:00:00', 'ZZ Jan 1',            0 ),
    3 => array( '2026-03-15', '09:00:00', 'ZZ Mar 15 morning',   0 ),
    4 => array( '2026-03-15', '18:00:00', 'ZZ Mar 15 evening',   0 ),
    5 => array( '2026-06-04', '18:00:00', 'ZZ Cancelled',        1 ),
    6 => array( '2026-12-31', '18:00:00', 'ZZ Dec 31',           0 ),
    7 => array( '2027-01-01', '10:00:00', 'ZZ Jan 1 next year',  0 ),
    8 => array( '2027-12-31', '10:00:00', 'ZZ Dec 31 next year', 0 ),
);
// 120 more 2026 events so the list is longer than any old page size (100).
for ( $i = 0; $i < 120; $i++ ) {
    $FIX[ 100 + $i ] = array( date( 'Y-m-d', strtotime( '2026-04-01 +' . $i . ' days' ) ), '12:00:00', 'ZZ Bulk ' . $i, 0 );
}
$USE_FIXTURES = true;

$occ_sql = array();
foreach ( $FIX as $id => $f ) {
    $occ_sql[] = $wpdb->prepare( 'SELECT %d AS id, %s AS event_date, %s AS event_time, %s AS title, NULL AS category_id, 16 AS capacity, %d AS cancelled', $id, $f[0], $f[1], $f[2], $f[3] );
}
$occ_derived = '(' . implode( ' UNION ALL ', $occ_sql ) . ')';
$reg_derived = "(SELECT 2 AS occurrence_id, 11 AS user_id, 'confirmed' AS status
                 UNION ALL SELECT 2, 12, 'confirmed' UNION ALL SELECT 2, 13, 'waiting'
                 UNION ALL SELECT 6, 11, 'withdrawn')";
add_filter( 'query', function( $q ) use ( &$USE_FIXTURES, $occ_derived, $reg_derived, $wpdb ) {
    if ( ! $USE_FIXTURES || strpos( $q, 'COUNT( DISTINCT CASE WHEN r.status' ) === false ) return $q;
    $q = str_replace( "{$wpdb->prefix}gl_event_occurrences o", "{$occ_derived} o", $q );
    return str_replace( "{$wpdb->prefix}gl_registrations r", "{$reg_derived} r", $q );
}, 5 );

function esy_events( $rows ) { return array_column( $rows, 'Event' ); }
function esy_run( $now ) {
    $out = spp_report_event_status( new DateTimeImmutable( $now ) );
    return $out['rows'];
}

echo "Year bounds (wp_timezone = " . wp_timezone_string() . ")\n";
esy_ok( wp_timezone_string() === 'America/Toronto', 'site timezone is America/Toronto' );
esy_ok( spp_report_event_status_year_bounds( new DateTimeImmutable( '2026-12-31 23:30', wp_timezone() ) ) === array( '2026-01-01', '2026-12-31' ), 'Dec 31 23:30 Toronto -> 2026' );
esy_ok( spp_report_event_status_year_bounds( new DateTimeImmutable( '2027-01-01 00:05', wp_timezone() ) ) === array( '2027-01-01', '2027-12-31' ), 'Jan 1 00:05 Toronto -> 2027 (rolls over)' );
esy_ok( spp_report_event_status_year_bounds( new DateTimeImmutable( '2027-01-01 03:00 UTC' ) ) === array( '2026-01-01', '2026-12-31' ), '2027-01-01 03:00 UTC is still Dec 31 in Toronto -> 2026' );
$cur = (int) wp_date( 'Y' );
esy_ok( spp_report_event_status_year_bounds() === array( "$cur-01-01", "$cur-12-31" ), "no argument -> current year $cur" );

echo "\nReport rows, now = 2026-12-31 23:30 Toronto\n";
$rows = esy_run( '2026-12-31 23:30 America/Toronto' );
$ev   = esy_events( $rows );
esy_ok( count( $rows ) === 124, '124 rows: 4 named non-cancelled 2026 + 120 bulk (got ' . count( $rows ) . ')' );
esy_ok( in_array( 'ZZ Dec 31', $ev, true ) && in_array( 'ZZ Jan 1', $ev, true ), 'Jan 1 and Dec 31 of 2026 included' );
esy_ok( ! in_array( 'ZZ Dec 31 last year', $ev, true ), 'Dec 31 2025 excluded' );
esy_ok( ! in_array( 'ZZ Jan 1 next year', $ev, true ) && ! in_array( 'ZZ Dec 31 next year', $ev, true ), '2027 events excluded' );
esy_ok( ! in_array( 'ZZ Cancelled', $ev, true ), 'cancelled event excluded' );
esy_ok( $ev[0] === 'ZZ Dec 31' && end( $ev ) === 'ZZ Jan 1', 'newest first: Dec 31 at top, Jan 1 at bottom' );
$dates = array_column( $rows, 'Date' );
$sorted = $dates; rsort( $sorted, SORT_STRING );
esy_ok( $dates === $sorted, 'every row in date+time descending order' );
$i3 = array_search( 'ZZ Mar 15 morning', $ev, true ); $i4 = array_search( 'ZZ Mar 15 evening', $ev, true );
esy_ok( $i4 !== false && $i3 !== false && $i4 < $i3, 'same day: 18:00 before 09:00' );
esy_ok( $rows[ array_search( 'ZZ Jan 1', $ev, true ) ]['Confirmed'] === 2 && $rows[ array_search( 'ZZ Jan 1', $ev, true ) ]['Waiting'] === 1, 'counts still tallied (2 confirmed, 1 waiting)' );
esy_ok( array_keys( $rows[0] ) === array( 'Date', 'Event', 'Category', 'Confirmed', 'Capacity', 'Waiting', 'Withdrawn' ), 'columns unchanged' );

echo "\nOld 'today onward' cut no longer applies\n";
$rows = esy_run( '2026-07-01 12:00 America/Toronto' );
$ev   = esy_events( $rows );
esy_ok( in_array( 'ZZ Jan 1', $ev, true ) && in_array( 'ZZ Mar 15 morning', $ev, true ), 'past events of the year shown mid-year' );
esy_ok( count( $rows ) === 124, 'same 124 rows mid-year' );
$rows = esy_run( '2027-01-01 00:05 America/Toronto' );
esy_ok( esy_events( $rows ) === array( 'ZZ Dec 31 next year', 'ZZ Jan 1 next year' ), 'on Jan 1 2027: only 2027 events, newest first' );

echo "\nRendered page (event_status-variant-1, as an admin)\n";
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$_GET = array();
$html = do_shortcode( '[spp_report table="event_status-variant-1"]' );
preg_match_all( '/<tr\b/i', preg_replace( '/<thead.*?<\/thead>/is', '', $html ), $m );
$expect = ( $cur === 2026 ) ? 124 : null;
esy_ok( $expect === null || count( $m[0] ) === $expect, 'all ' . $expect . ' rows rendered on one page (got ' . count( $m[0] ) . ')' );
esy_ok( preg_match( '/<option value="All"[^>]*selected/', $html ) === 1, '"All" is the selected rows-per-page choice' );
preg_match_all( '/<tr\b.*?<\/tr>/is', preg_replace( '/<thead.*?<\/thead>/is', '', $html ), $trs );
$first = trim( preg_replace( '/\s+/', ' ', strip_tags( $trs[0][0] ?? '' ) ) );
$last  = trim( preg_replace( '/\s+/', ' ', strip_tags( end( $trs[0] ) ?: '' ) ) );
esy_ok( $cur !== 2026 || ( strpos( $first, '2026-12-31 18:00 ZZ Dec 31' ) === 0 && strpos( $last, '2026-01-01 09:00 ZZ Jan 1' ) === 0 ), "rendered newest first (top: $first | bottom: $last)" );

echo "\nLive data (read-only)\n";
$USE_FIXTURES = false;
$live = spp_report_event_status();
list( $ys, $ye ) = spp_report_event_status_year_bounds();
$db_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gl_event_occurrences WHERE cancelled = 0 AND event_date BETWEEN %s AND %s", $ys, $ye ) );
esy_ok( count( $live['rows'] ) === $db_n, "live rows = non-cancelled $cur occurrences ($db_n)" );
$ld = array_column( $live['rows'], 'Date' ); $ls = $ld; rsort( $ls, SORT_STRING );
esy_ok( $ld === $ls, 'live rows newest first' );
esy_ok( count( array_filter( $ld, fn( $d ) => substr( $d, 0, 4 ) !== (string) $cur ) ) === 0, "live rows all in $cur" );
echo "  (live rows for $cur: " . count( $live['rows'] ) . ")\n";

esy_ok( $WRITES === 0, 'no write statements attempted' );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
