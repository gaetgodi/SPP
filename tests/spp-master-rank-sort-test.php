<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Numeric sorts on Master.Rank / membership.Rank (2026-10-15).
 *
 * Master.Rank and membership.Rank are VARCHAR(3). The membership rebuild
 * pads them ('007'), but Schedule Adjust's rank override and last-minute
 * add write a plain integer ('7') until the next rebuild -- and as text
 * '7' sorts after '069', '98' after '100'. The four sorts below now use
 * CAST(m.Rank AS UNSIGNED) then user_id:
 *   gl-schedule-production.php        Phase 3 candidates, ScheduleTest snapshot
 *   spp-assign-ranks-to-registered-players.php   registrant table
 *   spp-remove-inactive-ladder-users.php         inactive list
 * Those functions are wired to the real tables, so each ORDER BY clause is
 * read from the theme's own source file and run, unchanged, against a
 * throwaway table (aliases m, p, r all point at it). The test also writes
 * a rank the way Schedule Adjust does ($wpdb->update with an int) to show
 * what lands in a VARCHAR(3).
 *
 * All writes go to zzmr_t_* tables (write firewall). No real table touched.
 *
 * Run: php tests/spp-master-rank-sort-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
global $wpdb;
$dir = get_stylesheet_directory() . '/inc/';
echo "Master.Rank sort tests -- theme: {$dir}\n";

$pass = 0; $fail = 0;
function mr_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

$GLOBALS['mr_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    if ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    else $t = '?';
    global $wpdb;
    if ( strpos( $t, 'zzmr_t_' ) === 0 || $t === $wpdb->options ) return $q;
    $GLOBALS['mr_blocked'][] = substr( $q, 0, 140 );
    return "SELECT 'blocked'";
} );
$fp = function () use ( $wpdb ) { $o = array(); foreach ( array( 'Master', 'membership', 'Masterlist2026' ) as $t ) $o[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `$t`", ARRAY_A )['Checksum']; return $o; };
$before = $fp();

// The ORDER BY clause that sorts on m.Rank in a source file (the $n-th one).
function mr_clause( $file, $n = 0 ) {
    preg_match_all( '/ORDER BY ((?:CAST\(m\.Rank AS UNSIGNED\)(?: ASC)?, \w+\.user_id(?: ASC)?)|m\.Rank|Rank ASC)/', file_get_contents( $file ), $m );
    return $m[1][ $n ] ?? null;
}
// Runs a clause against zzmr_t_Master; 'Rank ASC' (remove-inactive's old
// bare form) resolves to the selected m.Rank, as in the real query.
function mr_order( $clause ) {
    global $wpdb;
    return array_map( 'intval', $wpdb->get_col( "SELECT m.user_id, m.Rank AS Rank FROM zzmr_t_Master m JOIN zzmr_t_Master p ON p.user_id = m.user_id JOIN zzmr_t_Master r ON r.user_id = m.user_id ORDER BY {$clause}" ) );
}
function mr_load( array $rows ) {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS zzmr_t_Master" );
    $wpdb->query( "CREATE TABLE zzmr_t_Master AS SELECT * FROM Master WHERE 1 = 0" );
    foreach ( $rows as $uid => $rank ) $wpdb->insert( 'zzmr_t_Master', array( 'user_id' => $uid, 'Rank' => $rank, 'user_email' => '' ) );
}

$sites = array(
    'Create Schedule: Phase 3 candidates' => array( $dir . 'gl-schedule-production.php', 0 ),
    'Create Schedule: ScheduleTest snapshot' => array( $dir . 'gl-schedule-production.php', 1 ),
    'Assign Ranks: registrant table'      => array( $dir . 'spp-assign-ranks-to-registered-players.php', 0 ),
    'Remove Inactive: inactive list'      => array( $dir . 'spp-remove-inactive-ladder-users.php', 0 ),
);

echo "1. What Schedule Adjust writes\n";
mr_load( array( 1 => '005' ) );
$wpdb->update( 'zzmr_t_Master', array( 'Rank' => 7 ), array( 'user_id' => 1 ) );
mr_ok( $wpdb->get_var( "SELECT Rank FROM zzmr_t_Master WHERE user_id = 1" ) === '7', "an int rank written like spp_sa2_rank_save() lands unpadded: '7'" );

echo "2. Mixed padded/unpadded: '7' vs '010', '069'; '98' vs '100'; NULL; exact tie\n";
// user => rank. Numeric order: 30 (NULL), 10 ('005'), 11 ('7'), 12 ('010'), 15 ('012'), 16 ('012'), 13 ('069'), 14 ('98'), 17 ('100')
$mixed = array( 17 => '100', 14 => '98', 13 => '069', 16 => '012', 12 => '010', 11 => '7', 15 => '012', 10 => '005', 30 => null );
$want  = array( 30, 10, 11, 12, 15, 16, 13, 14, 17 );
foreach ( $sites as $label => $s ) {
    $clause = mr_clause( $s[0], $s[1] );
    if ( ! $clause ) { mr_ok( false, "{$label}: ORDER BY on m.Rank found in source" ); continue; }
    mr_load( $mixed );
    $got = mr_order( $clause );
    mr_ok( $got === $want, "{$label} [ORDER BY {$clause}]: " . implode( ',', $got ) );
}

echo "3. Normal case (all padded, no ties): unchanged\n";
$padded = array( 23 => '100', 21 => '007', 22 => '012', 24 => '098' );
foreach ( $sites as $label => $s ) {
    $clause = mr_clause( $s[0], $s[1] );
    if ( ! $clause ) continue;
    mr_load( $padded );
    $got = mr_order( $clause );
    mr_ok( $got === array( 21, 22, 24, 23 ), "{$label}: " . implode( ',', $got ) );
}

$wpdb->query( "DROP TABLE IF EXISTS zzmr_t_Master" );
mr_ok( $before === $fp(), 'real Master/membership/Masterlist unchanged' );
mr_ok( empty( $GLOBALS['mr_blocked'] ), 'no write to a non-test table attempted' . ( $GLOBALS['mr_blocked'] ? ': ' . $GLOBALS['mr_blocked'][0] : '' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
