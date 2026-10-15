<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Apply Override final-Rank tie-break -- must be numeric (Apply Override
 * 1.7.2 / Score Correction 1.6.1).
 *
 * RankPrev is a string column that mixes padded ('004', absent players)
 * and unpadded ('10', players who played) values. Before 1.7.2 a tie on
 * RankOverride was broken by RankPrev as TEXT, so '10' beat '4' and '098'
 * lost to '99'. Each case below builds a throwaway Results table, runs the
 * real spp_ao_archive_results() into a throwaway Results_all, and checks
 * the ordinal Rank. Score Correction's re-rank statement (same ordering)
 * is replayed on a throwaway copy too.
 *
 * All writes go to zzat_t_* tables (write firewall). No email, no real
 * table touched (checksums compared).
 *
 * Run: php tests/spp-ao-rank-tiebreak-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ! function_exists( 'spp_ao_archive_results' ) ) { echo "ABORT: spp_ao_archive_results not loaded\n"; exit( 1 ); }
$ao_file = ( new ReflectionFunction( 'spp_ao_archive_results' ) )->getFileName();
preg_match( '/Version:\s*([\d.]+)/', file_get_contents( $ao_file ), $vm );
echo "Apply Override rank tie-break tests -- code under test: {$ao_file} (v" . ( $vm[1] ?? '?' ) . ")\n";

global $wpdb;
$pass = 0; $fail = 0;
function at_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

$GLOBALS['at_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    if ( preg_match( '/^\s*RENAME\s+TABLE\s+`?(\w+)`?\s+TO\s+`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1], $m[2] );
    elseif ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1] );
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1] );
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1] );
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1] );
    elseif ( preg_match( '/^\s*(?:ALTER|TRUNCATE)\s+TABLE\s+`?(\w+)`?/i', $q, $m ) ) $t = array( $m[1] );
    else $t = array( '?' );
    global $wpdb;
    foreach ( $t as $x ) { if ( strpos( $x, 'zzat_t_' ) === 0 || $x === $wpdb->options ) continue; $GLOBALS['at_blocked'][] = substr( $q, 0, 140 ); return "SELECT 'blocked'"; }
    return $q;
} );
function at_fp() {
    global $wpdb; $o = array();
    foreach ( array( 'Results', 'Results_all', 'Master', 'club_rating_state', 'club_rating_event_log' ) as $t ) $o[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `$t`", ARRAY_A )['Checksum'];
    $o['tables'] = md5( implode( ',', $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT LIKE 'zzat\\_t\\_%' ORDER BY 1" ) ) );
    return $o;
}
function at_drop() { global $wpdb; foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzat\\_t\\_%'" ) as $t ) $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }

// rows: [user_id, RankOverride, RankPrev]; inserted in the given order.
// Returns user_ids in final Rank order after the real archive step.
function at_archive( array $rows ) {
    global $wpdb;
    at_drop();
    $wpdb->query( "CREATE TABLE zzat_t_Results AS SELECT * FROM Results WHERE 1 = 0" );
    $wpdb->query( "CREATE TABLE zzat_t_Results_all LIKE Results_all" );
    foreach ( $rows as $r ) {
        $wpdb->insert( 'zzat_t_Results', array( 'Rank' => 0, 'user_id' => $r[0], 'RankOverride' => $r[1], 'RankCalc' => $r[1], 'RankCalc_Shadow' => $r[1], 'RankPrev' => $r[2], 'event_id' => 990001, 'display_name' => 'u' . $r[0] ) );
    }
    ob_start();
    spp_ao_archive_results( 990001, array( 'results' => 'zzat_t_Results', 'results_all' => 'zzat_t_Results_all', 'tmp' => 'zzat_t_tmp' ) );
    ob_end_clean();
    return array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM zzat_t_Results_all ORDER BY Rank" ) );
}

$before = at_fp();

echo "1. Two-way tie, unpadded '10' vs '4'\n";
$o = at_archive( array( array( 1, 4.00, '10' ), array( 2, 4.00, '4' ), array( 3, 2.00, '1' ) ) );
at_ok( $o === array( 3, 2, 1 ), "prev 4 ranks above prev 10 on a 4.00 tie (got " . implode( ',', $o ) . ")" );

echo "2. Padded vs unpadded (absent '004' vs played '10'; '098' vs '99'; '099' vs '100')\n";
$o = at_archive( array( array( 11, 4.00, '10' ), array( 12, 4.00, '004' ) ) );
at_ok( $o === array( 12, 11 ), "'004' above '10' (got " . implode( ',', $o ) . ")" );
$o = at_archive( array( array( 21, 99.99, '99' ), array( 22, 99.99, '098' ) ) );
at_ok( $o === array( 22, 21 ), "'098' above '99' (got " . implode( ',', $o ) . ")" );
$o = at_archive( array( array( 31, 99.99, '100' ), array( 32, 99.99, '099' ), array( 33, 99.99, '101' ) ) );
at_ok( $o === array( 32, 31, 33 ), "'099' above '100' above '101' (got " . implode( ',', $o ) . ")" );

$o = at_archive( array( array( 35, 99.99, '100' ), array( 36, 99.99, '121' ), array( 37, 99.99, '99' ) ) );
at_ok( $o === array( 37, 35, 36 ), "unpadded '99' above '100' and '121' -- the capped-99.99 pattern in the audit (got " . implode( ',', $o ) . ")" );

echo "3. Three-way tie, mixed forms ('10', '004', '9')\n";
$o = at_archive( array( array( 41, 7.00, '10' ), array( 42, 7.00, '004' ), array( 43, 7.00, '9' ), array( 44, 6.00, '20' ), array( 45, 8.00, '1' ) ) );
at_ok( $o === array( 44, 42, 43, 41, 45 ), "6.00 first, then prev 4, 9, 10, then 8.00 (got " . implode( ',', $o ) . ")" );

echo "4. Exact tie (same RankOverride, same RankPrev) -> user_id, whatever the row order\n";
$o1 = at_archive( array( array( 58, 5.00, '7' ), array( 51, 5.00, '7' ) ) );
$o2 = at_archive( array( array( 51, 5.00, '7' ), array( 58, 5.00, '7' ) ) );
at_ok( $o1 === array( 51, 58 ) && $o2 === array( 51, 58 ), "user 51 before 58 in both insert orders (got " . implode( ',', $o1 ) . " / " . implode( ',', $o2 ) . ")" );

echo "5. No ties (unchanged behaviour)\n";
$o = at_archive( array( array( 61, 3.00, '10' ), array( 62, 1.50, '004' ), array( 63, 2.00, '9' ), array( 64, -1.00, '2' ) ) );
at_ok( $o === array( 64, 62, 63, 61 ), "pure RankOverride order (got " . implode( ',', $o ) . ")" );

echo "6. Score Correction's re-rank statement, same rule\n";
if ( function_exists( 'spp_rank_ordinal_order_sql' ) ) {
    at_archive( array( array( 71, 4.00, '10' ), array( 72, 4.00, '4' ), array( 73, 4.00, '004' ) ) );
    $wpdb->query( "UPDATE zzat_t_Results SET Rank = 0" );
    $wpdb->query( "SET @r=0" );
    $wpdb->query( "UPDATE zzat_t_Results SET Rank = (@r:=@r+1) ORDER BY " . spp_rank_ordinal_order_sql() );
    $o = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM zzat_t_Results ORDER BY Rank" ) );
    at_ok( $o === array( 72, 73, 71 ), "UPDATE ... ORDER BY spp_rank_ordinal_order_sql(): prev 4 (user 72), 004 (user 73), 10 (got " . implode( ',', $o ) . ")" );
    $sc = file_get_contents( dirname( $ao_file ) . '/spp-score-correction.php' );
    at_ok( substr_count( $sc, 'ORDER BY " . spp_rank_ordinal_order_sql()' ) === 2 && strpos( $sc, 'RankPrev ASC"' ) === false, 'Score Correction uses the shared ordering in both re-rank statements' );
} else {
    at_ok( false, 'spp_rank_ordinal_order_sql() exists (Score Correction shares the rule)' );
}

at_drop();
at_ok( $before === at_fp(), 'real tables and table list unchanged' );
at_ok( empty( $GLOBALS['at_blocked'] ), 'no write to a non-test table attempted' . ( $GLOBALS['at_blocked'] ? ': ' . $GLOBALS['at_blocked'][0] : '' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
