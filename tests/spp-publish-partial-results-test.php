<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Publish Partial Results -- guard, failure and rollback tests.
 *
 * Every table, option and lock the run touches is pointed at throwaway
 * zzppr_test_* names via spp_ppr_config() overrides; the real Glicko
 * engine is only ever called with $dry_run = true. Cleans up at start
 * and end. The shortcode tests only reach paths that stop before
 * spp_ppr_run() (role, nonce, missing event) or are read-only (preview).
 *
 * Run: php tests/spp-publish-partial-results-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ! function_exists( 'spp_ppr_run' ) ) require __DIR__ . '/../inc/spp-publish-partial-results.php';

global $wpdb;
$P = 'zzppr_test_';
$pass = 0; $fail = 0;
function ppr_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

function ppr_cleanup() {
    global $wpdb, $P;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzppr\\_test\\_%'" ) as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
    }
    foreach ( array( 'flag_pub', 'flag_posted', 'log' ) as $o ) delete_option( $P . $o );
}

$REAL = array(
    'published' => get_option( 'spp_schedule_published' ), 'posted' => get_option( 'spp_results_posted' ),
    'log' => get_option( 'spp_partial_publish_log', 'absent' ),
    'state' => $wpdb->get_var( 'CHECKSUM TABLE club_rating_state', 1 ),
    'ledger' => $wpdb->get_var( 'CHECKSUM TABLE club_rating_event_log', 1 ),
    'sched' => $wpdb->get_var( 'CHECKSUM TABLE Schedules', 1 ),
    'ss168' => spp_ppr_table_exists( 'Schedules_Scores_168' ),
);

function ppr_fixture( int $event = 168 ) {
    global $wpdb, $P;
    ppr_cleanup();
    // Schedules copy keeps the generated Score column, like the real one.
    $wpdb->query( "CREATE TABLE {$P}Schedules LIKE Schedules" );
    $wpdb->query( "INSERT INTO {$P}Schedules (Sequence, registration_date, Rank, user_id, first_name, last_name, user_phone, user_email, event_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id, travel, RankPrime)
                   SELECT Sequence, registration_date, Rank, user_id, first_name, last_name, '', '', event_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id, travel, RankPrime FROM Schedules" );
    if ( $event !== 168 ) $wpdb->query( "UPDATE {$P}Schedules SET event_id = $event" );
    $wpdb->query( "CREATE TABLE {$P}Results (event_id INT) ENGINE=InnoDB" );
    $wpdb->query( "INSERT INTO {$P}Results VALUES (167)" );
    $wpdb->query( "CREATE TABLE {$P}state LIKE club_rating_state" );
    $wpdb->query( "INSERT INTO {$P}state SELECT * FROM club_rating_state" );
    $wpdb->query( "CREATE TABLE {$P}ledger LIKE club_rating_event_log" );
    $wpdb->query( "INSERT INTO {$P}ledger SELECT * FROM club_rating_event_log" );
    // The real ledger holds event 168's rows since the 2026-10-11 run; the
    // fixture plays an event that has NOT been rated yet.
    $wpdb->query( "DELETE FROM {$P}ledger WHERE event_id = 168" );
    $wpdb->query( "CREATE TABLE {$P}usermeta LIKE {$wpdb->prefix}usermeta" );
    $wpdb->query( "INSERT INTO {$P}usermeta SELECT * FROM {$wpdb->prefix}usermeta WHERE meta_key IN ('spp_glicko_rating','spp_glicko_rating_games')" );
    update_option( $P . 'flag_pub', 1 );
    update_option( $P . 'flag_posted', 0 );
}

$RATER_CALLS = 0;
// Fake rater: writes consistent state + ledger + usermeta into the test tables,
// then optionally misbehaves. Mirrors the shape of spp_crt_process_event_ratings().
function ppr_fake_rater( string $mode ) {
    return function( $games, $rank, $event, $source, $dry ) use ( $mode ) {
        global $wpdb, $P, $RATER_CALLS;
        $RATER_CALLS++;
        if ( $mode === 'abort' ) return array( 'aborted' => true, 'aborted_users' => array( 1 ) );
        $players = array();
        foreach ( $games as $g ) foreach ( array_merge( $g['team1'], $g['team2'] ) as $u ) $players[ $u ] = ( $players[ $u ] ?? 0 ) + 1;
        foreach ( $players as $u => $n ) {
            $st = $wpdb->get_row( "SELECT * FROM {$P}state WHERE user_id = $u", ARRAY_A );
            $wpdb->query( "UPDATE {$P}state SET mu = mu + 1, games_played = games_played + $n WHERE user_id = $u" );
            $after_mu = $st['mu'] + 1 + ( $mode === 'inconsistent' ? 50 : 0 );
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$P}ledger (event_id,user_id,source,mu_before,rd_before,games_before,last_event_before,mu_after,rd_after,games_after) VALUES (%d,%d,'ladder',%f,%f,%d,%d,%f,%f,%d)",
                $event, $u, $st['mu'], $st['rd'], $st['games_played'], $st['last_event'], $after_mu, $st['rd'], $st['games_played'] + $n ) );
            $wpdb->query( "UPDATE {$P}usermeta SET meta_value = '9.99' WHERE user_id = $u AND meta_key = 'spp_glicko_rating'" );
        }
        if ( $mode === 'throw' ) throw new RuntimeException( 'simulated failure mid-update' );
        return array( 'aborted' => false, 'usermeta_written' => true );
    };
}

function ppr_cfg( string $mode = 'ok', array $extra = array() ) {
    global $P;
    return spp_ppr_config( array_merge( array(
        'schedules' => "{$P}Schedules", 'scores_prefix' => "{$P}SS_", 'backup_prefix' => "{$P}bk_",
        'state_table' => "{$P}state", 'ledger_table' => "{$P}ledger", 'usermeta_table' => "{$P}usermeta",
        'results_table' => "{$P}Results", 'flag_published' => "{$P}flag_pub", 'flag_posted' => "{$P}flag_posted",
        'log_option' => "{$P}log", 'lock_name' => "{$P}lock", 'require_ladder' => false,
        'rate' => ppr_fake_rater( $mode ),
    ), $extra ) );
}

function ppr_sums() {
    global $wpdb, $P;
    return array(
        $wpdb->get_var( "CHECKSUM TABLE {$P}state", 1 ),
        $wpdb->get_var( "CHECKSUM TABLE {$P}ledger", 1 ),
        $wpdb->get_var( "CHECKSUM TABLE {$P}usermeta", 1 ),
    );
}
function ppr_flags() { global $P; return array( (int) get_option( $P . 'flag_pub' ), (int) get_option( $P . 'flag_posted' ) ); }

echo "A. inspect guards\n";
ppr_fixture();
$c = spp_ppr_config(); // real config, read-only calls only
ppr_ok( ! empty( spp_ppr_inspect( 0, $c )['errors'] ), 'event 0 refused' );
ppr_ok( ! empty( spp_ppr_inspect( 999999999, $c )['errors'] ), 'nonexistent event refused' );
ppr_ok( preg_grep( '/not a ladder/', spp_ppr_inspect( 311, $c )['errors'] ) == true, 'Ace event 311 refused as not ladder' );
ppr_ok( preg_grep( '/no rows for event/', spp_ppr_inspect( 169, ppr_cfg() )['errors'] ) == true, 'Schedules holding another event refused' );
$wpdb->query( "UPDATE {$P}Results SET event_id = 168" );
ppr_ok( preg_grep( '/already stamped/', spp_ppr_inspect( 168, ppr_cfg() )['errors'] ) == true, 'Results already stamped with the event refused' );
$wpdb->query( "UPDATE {$P}Schedules SET event_id = 169 WHERE user_id = 1" );
$wpdb->query( "UPDATE {$P}Results SET event_id = 167" );
ppr_ok( preg_grep( '/mixes events/', spp_ppr_inspect( 168, ppr_cfg() )['errors'] ) == true, 'mixed-event Schedules refused' );
$i = spp_ppr_inspect( 168, $c );
ppr_ok( empty( $i['errors'] ) && count( $i['build']['games'] ) === 21 && count( $i['build']['players'] ) === 33 && $i['build']['unreconstructed'] === 0,
    'real event 168 inspects clean: 21 games, 33 players, 0 unreconstructed' );

echo "B. happy path (fake rater)\n";
ppr_fixture();
$before = ppr_sums();
$r = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( $r['ok'], 'run ok' );
ppr_ok( spp_ppr_table_exists( "{$P}SS_168" ) && spp_ppr_tables_match( "{$P}SS_168", "{$P}Schedules" ), 'scores table created and matches' );
ppr_ok( (int) $wpdb->get_var( "SELECT SUM((Game1 IS NULL)+(Game2 IS NULL)+(Game3 IS NULL)+(Game4 IS NULL)+(Game5 IS NULL)) FROM {$P}SS_168" )
     === (int) $wpdb->get_var( "SELECT SUM((Game1 IS NULL)+(Game2 IS NULL)+(Game3 IS NULL)+(Game4 IS NULL)+(Game5 IS NULL)) FROM Schedules" ), 'blanks still NULL' );
ppr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}ledger WHERE event_id = 168" ) === 33, '33 ledger rows' );
ppr_ok( ppr_flags() === array( 0, 1 ), 'test flags set published=0 posted=1' );
ppr_ok( count( $r['backups'] ) === 4 && array_product( array_map( 'spp_ppr_table_exists', $r['backups'] ) ), '4 backup tables exist' );
ppr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r['backups'][0]}" ) === 47, 'Schedules snapshot has 47 rows' );
$log = get_option( "{$P}log" );
ppr_ok( is_array( $log ) && count( $log ) === 1 && $log[0]['ok'] === true && $log[0]['event'] === 168, 'run logged' );

echo "C. re-submit after success\n";
$RATER_CALLS = 0; $mid = ppr_sums();
$r2 = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( $r2['ok'] && $RATER_CALLS === 0 && ppr_sums() === $mid, 'second run: ok, rater not called, rating tables unchanged' );
ppr_ok( preg_grep( '/already exists and matches/', $r2['log'] ) == true && preg_grep( '/already applied/', $r2['log'] ) == true, 'second run reports kept/skipped' );

echo "D. existing scores table that differs\n";
ppr_fixture();
$wpdb->query( "CREATE TABLE {$P}SS_168 SELECT * FROM {$P}Schedules" );
$wpdb->query( "UPDATE {$P}SS_168 SET Game1 = 99 WHERE user_id = 3181" );
$RATER_CALLS = 0; $before = ppr_sums();
$r = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( ! $r['ok'] && $RATER_CALLS === 0 && ppr_sums() === $before && ppr_flags() === array( 1, 0 ), 'stops, no rating, flags untouched' );
ppr_ok( (int) $wpdb->get_var( "SELECT Game1 FROM {$P}SS_168 WHERE user_id = 3181" ) === 99, 'differing table not overwritten' );

foreach ( array( 'throw' => 'rater throws mid-update', 'inconsistent' => 'verification mismatch', 'abort' => 'engine abort' ) as $mode => $label ) {
    echo "E. rollback: $label\n";
    ppr_fixture();
    $before = ppr_sums();
    $r = spp_ppr_run( 168, ppr_cfg( $mode ) );
    ppr_ok( ! $r['ok'], 'run reports failure' );
    ppr_ok( ppr_sums() === $before, 'state, ledger, usermeta identical to before (rolled back)' );
    ppr_ok( ppr_flags() === array( 1, 0 ), 'flags untouched' );
    ppr_ok( preg_grep( '/rolled back/', $r['log'] ) == true, 'log says rolled back' );
    ppr_ok( spp_ppr_table_exists( "{$P}SS_168" ), 'scores table kept (scores still preserved)' );
}

echo "F. zero-score event\n";
ppr_fixture();
$wpdb->query( "UPDATE {$P}Schedules SET Game1=NULL, Game2=NULL, Game3=NULL, Game4=NULL, Game5=NULL" );
$RATER_CALLS = 0; $before = ppr_sums();
$r = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( $r['ok'] && $RATER_CALLS === 0 && ppr_sums() === $before, 'ok, rater not called, ratings unchanged' );
ppr_ok( ! spp_ppr_table_exists( "{$P}SS_168" ), 'no scores table created' );
ppr_ok( ppr_flags() === array( 0, 1 ), 'flags set' );

echo "G. lock held elsewhere (concurrent submit)\n";
ppr_fixture();
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->get_var( "SELECT GET_LOCK('{$P}lock', 0)" );
$RATER_CALLS = 0;
$r = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( ! $r['ok'] && $RATER_CALLS === 0 && ! spp_ppr_table_exists( "{$P}SS_168" ) && ppr_flags() === array( 1, 0 ), 'refused, nothing touched' );
$other->get_var( "SELECT RELEASE_LOCK('{$P}lock')" );
$other->close();
$r = spp_ppr_run( 168, ppr_cfg( 'ok' ) );
ppr_ok( $r['ok'], 'runs once the lock is free' );

echo "H. real rating engine, dry run only\n";
$i = spp_ppr_inspect( 168, spp_ppr_config() );
$d = spp_crt_process_event_ratings( $i['build']['games'], $i['build']['rank_by_user'], 168, 'ladder', true );
ppr_ok( ! $d['aborted'] && count( $d['dry_run_rows'] ) === 33, 'dry run: not aborted, 33 players' );
$max = 0; foreach ( $d['dry_run_rows'] as $row ) $max = max( $max, abs( $row['mu_after'] - $row['mu_before'] ) );
ppr_ok( $max < 15, sprintf( 'dry run: largest mu change %.1f (small)', $max ) );

echo "I. shortcode guard paths (real config; none reach spp_ppr_run)\n";
$sub = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%subscriber%' LIMIT 1" );
$adm = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' LIMIT 1" );
$_GET = array(); $_POST = array();
wp_set_current_user( 0 );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'do not have permission' ) !== false, 'logged-out refused' );
wp_set_current_user( $sub );
$_POST = array( 'spp_ppr_run' => '1', 'ppr_event' => '168', 'spp_ppr_nonce' => 'x' );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'do not have permission' ) !== false, "subscriber (user $sub) refused, even with a POST" );
wp_set_current_user( $adm );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'expired or is invalid' ) !== false, 'admin with bad nonce refused' );
$_POST = array( 'spp_ppr_run' => '1', 'ppr_event' => '168', 'spp_ppr_nonce' => wp_create_nonce( 'spp_ppr_run_167' ) );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'expired or is invalid' ) !== false, 'nonce for a different event refused' );
$_POST = array( 'spp_ppr_run' => '1', 'spp_ppr_nonce' => 'x' );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'No event id' ) !== false, 'POST without event refused' );
$_POST = array();
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'Enter the event id' ) !== false, 'no event: picker only' );
$_GET = array( 'ppr_event' => '999999999' );
ppr_ok( strpos( spp_publish_partial_results_shortcode(), 'Cannot run' ) !== false, 'missing event: Cannot run, no form' );
$_GET = array( 'ppr_event' => '168' );
$html = spp_publish_partial_results_shortcode();
ppr_ok( strpos( $html, 'spp_ppr_nonce' ) !== false && strpos( $html, 'Ladder rankings are <strong>NOT</strong> changed' ) !== false
     && strpos( $html, 'results email is sent' ) !== false && strpos( $html, 'next schedule can be created' ) !== false, 'preview for 168: plain-language confirm wording + nonce form' );
$_GET = array();
wp_set_current_user( 0 );

ppr_cleanup();
echo "J. production untouched by this test\n";
ppr_ok( get_option( 'spp_schedule_published' ) === $REAL['published'] && get_option( 'spp_results_posted' ) === $REAL['posted'], 'real flags unchanged' );
ppr_ok( get_option( 'spp_partial_publish_log', 'absent' ) === $REAL['log'], 'real log option unchanged' );
ppr_ok( $wpdb->get_var( 'CHECKSUM TABLE club_rating_state', 1 ) === $REAL['state'] && $wpdb->get_var( 'CHECKSUM TABLE club_rating_event_log', 1 ) === $REAL['ledger'], 'real rating tables unchanged' );
ppr_ok( $wpdb->get_var( 'CHECKSUM TABLE Schedules', 1 ) === $REAL['sched'] && spp_ppr_table_exists( 'Schedules_Scores_168' ) === $REAL['ss168'], 'real Schedules unchanged, no Schedules_Scores_168' );
ppr_ok( ! $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzppr\\_test\\_%'" ), 'all throwaway tables dropped' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
