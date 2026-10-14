<?php
// Command line only: these scripts write test data, so a web request must
// never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Schedule Admin walk-in guard (spp-schedule-admin.php 1.4.0) tests.
 *
 * Writes ONLY to a throwaway table, zzsa_t_Schedules (dropped at the end).
 * The real Schedules table is never read or written; the scratch-DB shim
 * refuses any other write target.
 *
 * Two ways to run:
 *   - on the server under WordPress:   php tests/spp-schedule-admin-walkin-guard-test.php
 *   - anywhere with a scratch MariaDB: SPP_TEST_DB=127.0.0.1:3399:zz_walkin php tests/...
 *     (host:port:database; database name must start with zz; uses a minimal
 *     $wpdb stand-in, no WordPress)
 */

$SRC = file_get_contents( dirname( __DIR__ ) . '/inc/spp-schedule-admin.php' );

if ( getenv( 'SPP_TEST_DB' ) ) {
    list( $h, $port, $db ) = explode( ':', getenv( 'SPP_TEST_DB' ) );
    if ( strpos( $db, 'zz' ) !== 0 ) { echo "ABORT: scratch database name must start with zz\n"; exit( 1 ); }
    class SA_Shim_DB {
        public $m;
        function __construct( $h, $port, $db ) { $this->m = new mysqli( $h, 'root', '', $db, (int) $port ); }
        function prepare( $q, ...$a ) { return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $q ), $a ); }
        function get_var( $q ) { $r = $this->m->query( $q ); $row = $r->fetch_row(); return $row ? $row[0] : null; }
        function query( $q ) {
            if ( ! preg_match( '/^\s*(SELECT|SHOW)\b/i', $q ) && strpos( $q, 'zzsa_t_Schedules' ) === false ) { echo "BLOCKED WRITE: $q\n"; exit( 1 ); }
            return $this->m->query( $q );
        }
    }
    $GLOBALS['wpdb'] = new SA_Shim_DB( $h, $port, $db );
} else {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'WP_USE_THEMES', false );
        require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
    }
}
global $wpdb;

// Load only the guard function from the module (the file itself needs WordPress).
// Under WordPress the theme has already defined it; redeclaring it would be fatal.
if ( ! preg_match( '/function spp_sa_walkin_block_message\(.*?\r?\n}\r?\n/s', $SRC, $m ) ) { echo "ABORT: guard function not found\n"; exit( 1 ); }
if ( function_exists( 'spp_sa_walkin_block_message' ) ) {
    echo "Using the already-loaded spp_sa_walkin_block_message() (theme loaded by WordPress)\n";
} else {
    eval( $m[0] );
}

$T = 'zzsa_t_Schedules';
$pass = 0; $fail = 0;
function sa_ok( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }
function sa_row( $uid, $gid, $g = array() ) {
    global $wpdb, $T;
    $cols = array( 'user_id' => $uid, 'group_id' => $gid, 'Game1' => 'NULL', 'Game2' => 'NULL', 'Game3' => 'NULL', 'Game4' => 'NULL', 'Game5' => 'NULL' );
    foreach ( $g as $k => $v ) $cols[ $k ] = $v;
    $wpdb->query( "INSERT INTO {$T} (" . implode( ',', array_keys( $cols ) ) . ") VALUES (" . implode( ',', $cols ) . ")" );
}

$wpdb->query( "DROP TABLE IF EXISTS {$T}" );
$wpdb->query( "CREATE TABLE {$T} (user_id INT PRIMARY KEY, group_id INT, Game1 INT NULL, Game2 INT NULL, Game3 INT NULL, Game4 INT NULL, Game5 INT NULL)" );

// group 1: four players, nothing entered (all NULL)
foreach ( array( 11, 12, 13, 14 ) as $u ) sa_row( $u, 1 );
// group 2: one score in one column (Game2 on one player)
sa_row( 21, 2, array( 'Game2' => 11 ) ); sa_row( 22, 2 ); sa_row( 23, 2 ); sa_row( 24, 2 );
// group 3: blank, but group 99 holds NP/NS rows with Game1 = -1 / -2
foreach ( array( 31, 32, 33, 34 ) as $u ) sa_row( $u, 3 );
sa_row( 91, 99, array( 'Game1' => -1, 'Game2' => 0, 'Game3' => 0, 'Game4' => 0, 'Game5' => 0 ) );
sa_row( 92, 99, array( 'Game1' => -2, 'Game2' => 0, 'Game3' => 0, 'Game4' => 0, 'Game5' => 0 ) );
// group 4: the only entry is a literal 0 (a 0-point loser score)
sa_row( 41, 4, array( 'Game1' => 0 ) ); sa_row( 42, 4 ); sa_row( 43, 4 ); sa_row( 44, 4 );
// group 5: apply-time case, starts blank
foreach ( array( 51, 52, 53, 54 ) as $u ) sa_row( $u, 5 );

echo "No scores -> walk-in allowed\n";
sa_ok( spp_sa_walkin_block_message( 1, $T ) === '', 'group with all-NULL games accepts' );

echo "One score in one column -> refused\n";
$msg = spp_sa_walkin_block_message( 2, $T );
sa_ok( $msg !== '', 'group with one entered score refuses' );

echo "A lone 0 counts as entered (same as Create Schedule guard / Create Results 1b)\n";
sa_ok( spp_sa_walkin_block_message( 4, $T ) !== '', 'group whose only entry is 0 refuses' );

echo "Blank scores -> accepted\n";
sa_ok( spp_sa_walkin_block_message( 3, $T ) === '', 'blank group accepts even though group 99 rows hold -1/-2' );

echo "Group 99\n";
sa_ok( spp_sa_walkin_block_message( 99, $T ) === '', 'group 99 is ignored (never refused by the score guard)' );
sa_ok( spp_sa_walkin_block_message( 3, $T ) === '', 'group 99 NP/NS values never count toward another group' );
sa_ok( spp_sa_walkin_block_message( 999, $T ) === '', 'nonexistent group: guard silent (existing "Group not found" check handles it)' );

echo "Apply-time re-check (score entered after the form was opened)\n";
sa_ok( spp_sa_walkin_block_message( 5, $T ) === '', 'form opened: group 5 accepts' );
$wpdb->query( "UPDATE {$T} SET Game1 = 15 WHERE user_id IN (51,52)" );
sa_ok( spp_sa_walkin_block_message( 5, $T ) !== '', 'same group refuses once a score lands before submit' );

echo "Message text\n";
sa_ok( strpos( $msg, 'Scores have already been entered for Group 2' ) === 0, 'starts with: scores already entered for this group' );
sa_ok( strpos( $msg, "a walk-in player can't be added now" ) !== false, "says a walk-in can't be added now" );
sa_ok( strpos( $msg, 'If a player left partway through, mark the group NC (Not Complete) instead' ) !== false, 'tells editor to mark the group NC' );
sa_ok( strpos( $msg, 'Mark NC button on this page' ) !== false && strpos( $SRC, 'Mark NC' ) !== false, 'names the on-page Mark NC control' );
sa_ok( strpos( $msg, 'keeps the games that were played' ) !== false && strpos( $msg, 'counts them toward ratings' ) !== false && strpos( $msg, "doesn't change anyone's ranking" ) !== false, 'explains what NC does' );
sa_ok( ! preg_match( '/Game\d|group_id|SQL|NULL/', $msg ), 'no technical jargon' );

echo "Server-side placement in the add_walkin case\n";
$case_start = strpos( $SRC, "case 'add_walkin':" );
$case_end   = strpos( $SRC, 'Record modified groups', $case_start );
$case       = substr( $SRC, $case_start, $case_end - $case_start );
$g  = strpos( $case, 'spp_sa_walkin_block_message(' );
$i1 = strpos( $case, "\$wpdb->insert( 'Schedules'" );
$i2 = strpos( $case, "\$wpdb->prefix . 'gl_registrations', array(" );
sa_ok( $g !== false && $i1 !== false && $g < $i1, 'guard runs before the Schedules insert' );
sa_ok( $i2 !== false && $g < $i2, 'guard runs before the gl_registrations insert' );
sa_ok( preg_match( '/spp_sa_walkin_block_message\(.*?\$error = \$block;\s*break;/s', $case ) === 1, 'refusal sets $error and breaks (nothing written)' );
sa_ok( strpos( $SRC, "wp_verify_nonce( \$_POST['spp_sa_nonce']" ) !== false && strpos( $SRC, 'spp_is_admin_or_editor()' ) !== false, 'nonce and admin/editor gate still present' );
sa_ok( substr_count( $SRC, "\$wpdb->insert( 'Schedules'" ) === 1, 'only one Schedules insert on the page (guard covers every walk-in write)' );

$wpdb->query( "DROP TABLE IF EXISTS {$T}" );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
