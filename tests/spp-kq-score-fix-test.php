<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * KQ Save All + corrections + Swap players + Fix a mistake
 * (inc/spp-kq-fix.php 1.0.0, inc/spp-kq-live.php 1.16.0,
 * inc/spp-kq-screens.php 1.37.0).
 *
 * Every write goes to throwaway zzkqf_t_* tables (KQ tables via the
 * spp_kq_table_name filter, end-state store via spp_kq_end_store_table).
 * A write firewall rejects anything else; wp_mail() is a capture stub;
 * the cron registration is skipped; the clock is spp_kq_end_now. The fake
 * occurrence (990301) and players (9903xx) exist only in the zz tables.
 * The real KQ tables are checksummed before and after.
 *
 * Run (live code):      php tests/spp-kq-score-fix-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-kq-score-fix-test.php
 */

$GLOBALS['kqf_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['kqf_mail'][] = $subject;
    return true;
}
define( 'SPP_KQ_NO_CRON_SCHEDULE', true );

$kqf_theme = getenv( 'SPP_TEST_THEME_DIR' );
if ( $kqf_theme ) {
    $GLOBALS['wp_filter'] = array( 'stylesheet_directory' => array( 10 => array( array( 'function' => fn() => $kqf_theme, 'accepted_args' => 1 ) ) ) );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
add_filter( 'pre_wp_mail', function () { return true; } );
if ( ! function_exists( 'spp_kq_save_scores_bulk' ) ) { echo "ABORT: spp-kq-fix.php not loaded\n"; exit( 1 ); }
echo 'Theme: ' . get_stylesheet_directory() . "\n";

global $wpdb;
$P = 'zzkqf_t_';
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

// ── Write firewall: only zzkqf_t_* ──────────────────────────────────────────
$GLOBALS['kqf_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    $t = '?';
    if ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    if ( strpos( $t, 'zzkqf_t_' ) === 0 ) return $q;
    $GLOBALS['kqf_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
    return "SELECT 'kqf_test_blocked_write'";
} );

$REAL = array( 'spp_kq_events', 'spp_kq_assignments', 'spp_kq_scores', 'spp_kq_history', 'spp_kq_checkins' );
function real_fp() {
    global $wpdb, $REAL;
    $fp = array();
    foreach ( $REAL as $t ) $fp[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}{$t}`", ARRAY_A )['Checksum'];
    $fp['end_state_rows'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'spp\\_kq\\_end\\_state\\_%'" );
    return $fp;
}
$before = real_fp();

add_filter( 'spp_kq_table_name', function ( $name, $key ) use ( $P ) { return $P . $key; }, 10, 2 );
add_filter( 'spp_kq_end_store_table', function () use ( $P ) { return $P . 'options'; } );
$GLOBALS['kqf_now'] = null;
add_filter( 'spp_kq_end_now', function ( $t ) { return $GLOBALS['kqf_now'] ?? $t; } );

function cleanup() {
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqf\\_t\\_%'" ) as $t ) $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
}
cleanup();
foreach ( $REAL as $t ) $wpdb->query( "CREATE TABLE `{$P}" . substr( $t, 7 ) . "` LIKE `{$wpdb->prefix}{$t}`" );
$wpdb->query( "CREATE TABLE `{$P}options` LIKE `{$wpdb->options}`" );
ok( spp_kq_scores_table() === $P . 'scores', 'tables redirected to zz copies' );

const OCC = 990301;
const COURTS = array( 'Aces', 'Kings', 'Queens' );

// ── Helpers ─────────────────────────────────────────────────────────────────
function fixture() {
    global $wpdb, $P;
    foreach ( array( 'events', 'assignments', 'scores', 'options' ) as $k ) $wpdb->query( "DELETE FROM `{$P}{$k}`" );
    $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}events` (occurrence_id, current_round, phase, courts_announced_at) VALUES (%d, 1, 'in_play', %d)", OCC, time() - 900 ) );
    $uid = 990401;
    foreach ( COURTS as $court ) {
        foreach ( array( 'red', 'red', 'black', 'black' ) as $color ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}assignments` (occurrence_id, round_number, user_id, court_name, team_color) VALUES (%d, 1, %d, %s, %s)", OCC, $uid++, $court, $color ) );
        }
        $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}scores` (occurrence_id, round_number, court_name, serving_team) VALUES (%d, 1, %s, 'red')", OCC, $court ) );
    }
}
function phase() { $s = spp_kq_get_event_state( OCC ); return $s ? $s['phase'] . '/' . $s['current_round'] : 'none'; }
function scores_of( $n ) {
    global $wpdb, $P;
    return $wpdb->get_results( $wpdb->prepare( "SELECT court_name, red_score, black_score, cancelled FROM `{$P}scores` WHERE occurrence_id = %d AND round_number = %d ORDER BY court_name", OCC, $n ), ARRAY_A );
}
function lineup_of( $n ) { return spp_kq_lineup_signature( spp_kq_get_round_assignments( OCC, $n ) ); }
function rounds_snapshot( $upto ) {
    $out = array();
    for ( $n = 1; $n <= $upto; $n++ ) $out[ $n ] = array( scores_of( $n ), lineup_of( $n ) );
    return $out;
}
function computed_after( $n ) { return spp_kq_lineup_signature( spp_kq_compute_round_after( OCC, $n )['next'] ); }
function set_in_play() { global $wpdb, $P; $wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'in_play' WHERE occurrence_id = %d", OCC ) ); }
function post_action( array $fields ) {
    $s = spp_kq_get_event_state( OCC );
    $_POST = array_merge( array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'spp_kq_round' => (string) $s['current_round'] ), $fields );
    $n = spp_kq_handle_post_actions( OCC, '2026-01-02', null );
    $_POST = array();
    return $n;
}
function players_on( $n, $court ) {
    global $wpdb, $P;
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM `{$P}assignments` WHERE occurrence_id = %d AND round_number = %d AND court_name = %s ORDER BY team_color, user_id", OCC, $n, $court ) ) );
}

// AJAX harness: run a wp_ajax_ handler, capture its JSON.
class KqfDie extends Exception {}
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new KqfDie(); }; } );
function ajax( $action, array $post ) {
    $_POST = $_REQUEST = $post;
    ob_start();
    try { do_action( 'wp_ajax_' . $action ); } catch ( KqfDie $e ) {}
    $out = ob_get_clean();
    $_POST = $_REQUEST = array();
    return json_decode( $out, true );
}

$mod = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$MOD = (int) ( $mod[0] ?? 0 );
ok( $MOD > 0, "moderator user found ($MOD)" );
wp_set_current_user( $MOD );
$NONCE = wp_create_nonce( 'spp_kq_live_action' );

// ── 1. Save All: valid, invalid and blank courts ────────────────────────────
echo "\n1. Save All mix\n";
fixture();
$r = spp_kq_save_scores_bulk( OCC, 1, array(
    'Aces'   => array( 'red' => '11', 'black' => '5' ),
    'Kings'  => array( 'red' => '11', 'black' => '11' ),
    'Queens' => array( 'red' => '', 'black' => '' ),
), $MOD, 1000 );
ok( $r['results']['Aces']['status'] === 'saved', 'valid court saved' );
ok( $r['results']['Kings']['status'] === 'problem' && strpos( $r['results']['Kings']['message'], "can't have 11" ) !== false, '11-11 not saved, calm message: "' . $r['results']['Kings']['message'] . '"' );
ok( $r['results']['Queens']['status'] === 'blank', 'blank court left alone' );
$sc = scores_of( 1 );
ok( (int) $sc[0]['red_score'] === 11 && $sc[1]['red_score'] === null && $sc[2]['red_score'] === null, 'only Aces stored' );
ok( $r['saved'] === 1 && $r['problems'] === 1 && $r['reported'] === 1 && $r['total'] === 3 && ! $r['advanced'] && phase() === 'in_play/1', 'counts right, round not advanced' );
foreach ( array(
    array( '7', '', "Enter both teams' scores." ),
    array( 'x', '3', 'whole numbers' ),
    array( '12', '3', 'from 0 to 11' ),
    array( '6', '6', "can't be equal" ),
    array( '-1', '3', 'whole numbers' ),
) as $c ) {
    $r = spp_kq_save_scores_bulk( OCC, 1, array( 'Kings' => array( 'red' => $c[0], 'black' => $c[1] ) ), $MOD, 1001 );
    ok( $r['results']['Kings']['status'] === 'problem' && strpos( $r['results']['Kings']['message'], $c[2] ) !== false && scores_of( 1 )[1]['red_score'] === null, "'{$c[0]}'/'{$c[1]}' not saved: \"{$r['results']['Kings']['message']}\"" );
}
$calm = true;
foreach ( array( '7|', 'x|3', '12|3', '6|6', '11|11' ) as $pair ) {
    list( $a, $b ) = explode( '|', $pair );
    $m = spp_kq_score_entry_problem( $a, $b );
    if ( $m === null || strpos( $m, '--' ) !== false || strpos( $m, '!' ) !== false || preg_match( '/\b(ERROR|INVALID|FAILED)\b/', $m ) ) $calm = false;
}
ok( $calm, 'problem messages are plain (no "--", "!", shouting)' );

// ── 2. Double submit ────────────────────────────────────────────────────────
echo "\n2. double submit\n";
$batch = array( 'Aces' => array( 'red' => '11', 'black' => '5' ), 'Kings' => array( 'red' => '7', 'black' => '11' ) );
$r1 = spp_kq_save_scores_bulk( OCC, 1, $batch, $MOD, 2000 );
$r2 = spp_kq_save_scores_bulk( OCC, 1, $batch, $MOD, 2000 ); // same click delivered twice
ok( $r1['saved'] === 2 && $r2['saved'] === 2 && $r2['problems'] === 0, 'both deliveries report saved, no problems' );
ok( (int) scores_of( 1 )[1]['black_score'] === 11 && phase() === 'in_play/1', 'stored once, still in play' );

// ── 3. Last court advances the round, exactly once ──────────────────────────
echo "\n3. last-court advance\n";
$last = array( 'Queens' => array( 'red' => '4', 'black' => '11' ) );
$r = spp_kq_save_scores_bulk( OCC, 1, $batch + $last, $MOD, 3000 );
ok( $r['advanced'] && $r['reported'] === 3 && phase() === 'organizing/2', 'filling the last court advances to round 2' );
ok( count( spp_kq_get_round_assignments( OCC, 2 ) ) === 12 && lineup_of( 2 ) === computed_after( 1 ), 'round 2 set up from round 1' );
$r = spp_kq_save_scores_bulk( OCC, 1, $batch + $last, $MOD, 3001 );
ok( $r['saved'] === 3 && $r['problems'] === 0 && phase() === 'organizing/2', 'repeat after the advance: reported as saved, no second advance' );
$r = spp_kq_save_scores_bulk( OCC, 1, array( 'Aces' => array( 'red' => '11', 'black' => '9' ) ), $MOD, 3002 );
ok( $r['results']['Aces']['status'] === 'problem' && strpos( $r['results']['Aces']['message'], 'use Fix a mistake' ) !== false && (int) scores_of( 1 )[0]['black_score'] === 5, 'a different score after the round finished: not saved, points to Fix a mistake' );
// Overwrites in the same batch land before the advance
fixture();
spp_kq_save_scores_bulk( OCC, 1, array( 'Aces' => array( 'red' => '11', 'black' => '5' ), 'Kings' => array( 'red' => '11', 'black' => '3' ) ), $MOD, 4000 );
$r = spp_kq_save_scores_bulk( OCC, 1, array( 'Queens' => array( 'red' => '11', 'black' => '2' ), 'Aces' => array( 'red' => '5', 'black' => '11' ) ), $MOD, 4001 );
ok( $r['advanced'] && (int) scores_of( 1 )[0]['black_score'] === 11 && lineup_of( 2 ) === computed_after( 1 ), 'overwrite in the same batch is saved before the round advances' );

// ── 4. Correction of round 1, round 2 not started ───────────────────────────
echo "\n4. correction, next round not started\n";
$ann_before = spp_kq_get_event_state( OCC )['courts_announced_at'];
$r2_before  = lineup_of( 2 );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Aces', 6, 11, $MOD ); // same winner
ok( $c['success'] && $c['followup_kind'] === 'same' && lineup_of( 2 ) === $r2_before && spp_kq_get_event_state( OCC )['courts_announced_at'] === $ann_before, 'same winner: round 2 left as is ("' . $c['followup']['message'] . '")' );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Aces', 11, 6, $MOD ); // winner flips
ok( $c['success'] && $c['followup_kind'] === 'rebuilt', 'winner flips: round 2 rebuilt' );
ok( strpos( $c['message'], 'saved as Red 11' ) !== false && strpos( $c['message'], "Round 2 hadn't started, so it was set up again" ) !== false, 'one calm line: "' . $c['message'] . '"' );
ok( lineup_of( 2 ) !== $r2_before && lineup_of( 2 ) === computed_after( 1 ) && phase() === 'organizing/2', 'round 2 now matches the corrected round 1' );
ok( count( array_filter( scores_of( 2 ), fn( $s ) => $s['red_score'] !== null ) ) === 0, 'round 2 still has no scores' );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Aces', 11, 6, $MOD );
ok( $c['success'] && $c['followup_kind'] === 'none', 'saving the same value again: nothing else happens' );

// ── 5. Round 2 started (in play) but no score: not rebuilt ──────────────────
echo "\n5. next round in play, no score\n";
set_in_play();
$r2_before = lineup_of( 2 );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Kings', 3, 11, $MOD ); // flips Kings
ok( $c['success'] && $c['followup_kind'] === 'in_play' && lineup_of( 2 ) === $r2_before && phase() === 'in_play/2', 'in play: not rebuilt automatically' );
ok( strpos( $c['message'], 'Set up Round 2 again' ) !== false, 'message offers Set up again: "' . $c['followup']['message'] . '"' );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Kings', 11, 3, $MOD ); // back

// ── 6. Round 2 played: never modified ───────────────────────────────────────
echo "\n6. next round played\n";
spp_kq_save_scores_bulk( OCC, 2, array( 'Aces' => array( 'red' => '11', 'black' => '8' ), 'Kings' => array( 'red' => '2', 'black' => '11' ), 'Queens' => array( 'red' => '11', 'black' => '10' ) ), $MOD, 6000 );
ok( phase() === 'organizing/3', 'round 2 played, round 3 set up' );
$snap = rounds_snapshot( 3 );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Queens', 9, 11, $MOD ); // flips Queens
ok( $c['success'] && $c['followup_kind'] === 'played_differs', 'courts would differ: reported, not changed' );
ok( strpos( $c['message'], 'Round 2 was played with courts set from the earlier scores. If different players actually played, use Swap players on Round 2 to put in the players who actually played.' ) !== false, 'exact guidance text' );
$after = rounds_snapshot( 3 );
ok( $after[2] === $snap[2] && $after[3] === $snap[3], 'round 2 (played) and round 3 untouched' );
ok( (int) scores_of( 1 )[2]['red_score'] === 9, 'the corrected round 1 score is saved' );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Queens', 10, 11, $MOD ); // same winner as now
ok( $c['success'] && $c['followup_kind'] === 'played_differs', 'still differs from what was played (round 2 came from the original Queens result)' );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Queens', 11, 2, $MOD ); // back to the original winner
ok( $c['success'] && $c['followup_kind'] === 'played_same' && strpos( $c['message'], 'Later rounds are not affected.' ) !== false, 'courts would not differ: "Later rounds are not affected."' );
ok( rounds_snapshot( 3 )[2] === $snap[2] && rounds_snapshot( 3 )[3] === $snap[3], 'rounds 2 and 3 still untouched' );

// ── 7. Rebuild refuses played rounds ────────────────────────────────────────
echo "\n7. rebuild never touches a played round\n";
$snap = rounds_snapshot( 3 );
$n = post_action( array( 'spp_kq_action' => 'rebuild_round', 'spp_kq_target_round' => '2', 'spp_kq_expected_announced' => (string) spp_kq_get_event_state( OCC )['courts_announced_at'] ) );
ok( strpos( $n, 'Round 2 already has scores, so it stays as played' ) !== false && rounds_snapshot( 3 ) === $snap && phase() === 'organizing/3', 'Rebuild Round 2 refused: "' . $n . '"' );
$n = post_action( array( 'spp_kq_action' => 'rebuild_round', 'spp_kq_target_round' => '3', 'spp_kq_expected_announced' => (string) spp_kq_get_event_state( OCC )['courts_announced_at'] ) );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && strpos( $n, 'Round 3 has been set up again' ) !== false && rounds_snapshot( 2 ) === array_slice( $snap, 0, 2, true ), 'Rebuild Round 3 (no scores) works, rounds 1-2 untouched' );

// ── 8. Swap in a played round, next round unplayed ──────────────────────────
echo "\n8. swap in played round 2, round 3 not started\n";
$snap  = rounds_snapshot( 3 );
$a     = players_on( 2, 'Aces' )[0];   // a red player on Aces
$b     = players_on( 2, 'Queens' )[0]; // a red player on Queens
$n = post_action( array( 'spp_kq_action' => 'past_swap_positions', 'spp_kq_swap_target_round' => '2', 'spp_kq_swap_pos_user_a' => (string) $a, 'spp_kq_swap_pos_user_b' => (string) $b ) );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && strpos( $n, 'Players swapped in Round 2. The scores stay the same.' ) !== false, 'notice: "' . substr( $n, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) . '"' );
ok( in_array( $b, players_on( 2, 'Aces' ), true ) && in_array( $a, players_on( 2, 'Queens' ), true ), 'the two players traded places in round 2' );
ok( scores_of( 2 ) === $snap[2][0] && rounds_snapshot( 1 ) === array_slice( $snap, 0, 1, true ), 'round 2 scores and round 1 untouched' );
ok( strpos( $n, "Round 3 hadn't started, so it was set up again" ) !== false && lineup_of( 3 ) === computed_after( 2 ) && lineup_of( 3 ) !== $snap[3][1], 'round 3 rebuilt from the swapped round 2' );

// ── 9. Swap in round 1 while round 2 is played: round 2 untouched ───────────
echo "\n9. swap in round 1, round 2 played\n";
$snap = rounds_snapshot( 3 );
$a    = players_on( 1, 'Kings' )[0];
$b    = players_on( 1, 'Queens' )[2];
$n = post_action( array( 'spp_kq_action' => 'past_swap_positions', 'spp_kq_swap_target_round' => '1', 'spp_kq_swap_pos_user_a' => (string) $a, 'spp_kq_swap_pos_user_b' => (string) $b ) );
$after = rounds_snapshot( 3 );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && $after[2] === $snap[2] && $after[3] === $snap[3], 'round 2 (played) and round 3 untouched' );
ok( strpos( $n, 'use Swap players on Round 2' ) !== false || strpos( $n, 'Later rounds are not affected.' ) !== false, 'says what to do: "' . substr( $n, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) . '"' );
ok( scores_of( 1 ) === $snap[1][0], 'round 1 scores unchanged by the swap' );

// ── 10. Swap in the current round (no later round) ──────────────────────────
echo "\n10. swap in the current round\n";
$a = players_on( 3, 'Aces' )[0];
$b = players_on( 3, 'Kings' )[0];
$n = post_action( array( 'spp_kq_action' => 'past_swap_positions', 'spp_kq_swap_target_round' => '3', 'spp_kq_swap_pos_user_a' => (string) $a, 'spp_kq_swap_pos_user_b' => (string) $b ) );
ok( substr( $n, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) === 'Players swapped in Round 3.', 'current round: "' . substr( $n, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) . '"' );

// ── 11. Permissions + AJAX endpoints ────────────────────────────────────────
echo "\n11. permissions\n";
set_in_play();
$entries = wp_json_encode( array( 'Aces' => array( 'red' => '11', 'black' => '1' ) ) );
wp_set_current_user( 0 );
$j = ajax( 'spp_kq_submit_scores_bulk', array( 'nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'occ' => OCC, 'round' => 3, 'entries' => $entries, 'client_ts' => 7000 ) );
ok( $j && $j['success'] === false && $j['data'] === 'Not authorized' && scores_of( 3 )[0]['red_score'] === null, 'logged out: Save All refused' );
$j = ajax( 'spp_kq_correct_score', array( 'nonce' => 'x', 'occ' => OCC, 'round' => 1, 'court_name' => 'Aces', 'red_score' => 1, 'black_score' => 11 ) );
ok( $j && $j['success'] === false && $j['data'] === 'Not authorized', 'logged out: correction refused' );
$j = ajax( 'spp_kq_live_action', array( 'spp_kq_nonce' => 'x', 'occ' => OCC, 'spp_kq_action' => 'past_swap_positions' ) );
ok( $j && $j['success'] === false && $j['data'] === 'Not authorized', 'logged out: swap refused' );
$j = ajax( 'spp_kq_render_scoreboard_fragment', array( 'nonce' => 'x', 'occ' => OCC, 'kq_view' => 'fix' ) );
ok( $j && $j['success'] === false, 'logged out: Fix a mistake screen refused' );
wp_set_current_user( $MOD );
$NONCE = wp_create_nonce( 'spp_kq_live_action' );
$j = ajax( 'spp_kq_submit_scores_bulk', array( 'nonce' => $NONCE, 'occ' => OCC, 'round' => 3, 'entries' => $entries, 'client_ts' => 7001 ) );
ok( $j && $j['success'] === true && $j['data']['results']['Aces']['status'] === 'saved' && (int) scores_of( 3 )[0]['red_score'] === 11, 'moderator: Save All via AJAX works' );
$j = ajax( 'spp_kq_correct_score', array( 'nonce' => $NONCE, 'occ' => OCC, 'round' => 1, 'court_name' => 'Aces', 'red_score' => 11, 'black_score' => 6 ) );
ok( $j && $j['success'] === true && isset( $j['data']['message'] ), 'moderator: correction via AJAX returns the one-line result' );
$j = ajax( 'spp_kq_render_scoreboard_fragment', array( 'nonce' => $NONCE, 'occ' => OCC, 'kq_view' => 'fix' ) );
ok( $j && $j['success'] && strpos( $j['data']['html'], 'Fix a mistake' ) !== false && strpos( $j['data']['html'], 'Back to game' ) !== false, 'moderator: Fix a mistake overlay renders' );
// (spp_kq_live_action needs a real GL occurrence, so call its handler directly)
$n = post_action( array( 'spp_kq_action' => 'void_round', 'spp_kq_target_round' => '1' ) );
ok( strpos( $n, "Round 1 can't be voided" ) !== false && ! str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ), 'refusals come back as plain text: "' . $n . '"' );

// ── 12. Render: Fix a mistake, In-Play, Overview, Scoreboard ────────────────
echo "\n12. render\n";
$html = spp_kq_render_fix_screen( OCC );
foreach ( array( 'Correct a score', 'Swap players in a round', 'Set up Round 3 again', 'Play a round again', "Don't count a round" ) as $h ) {
    ok( strpos( $html, $h ) !== false, "Fix screen section: $h" );
}
ok( substr_count( $html, 'Use this when' ) + substr_count( $html, 'Use this only when' ) === 5, 'each section has a "Use this when" line' );
ok( strpos( $html, 'Not available: Round 3 already has scores, so it stays as played.' ) !== false, 'set up again: explains why not available' );
ok( strpos( $html, 'Available between rounds.' ) !== false, 'play again: in play, explains when' );
ok( strpos( $html, 'kq-btn-danger' ) === false && strpos( $html, 'kq-notice-err' ) === false, 'no red on the Fix screen' );
ok( preg_match( '/<div class="kq-past-swap-round" data-round="2"[^>]*>/', $html ) && strpos( $html, 'name="spp_kq_action" value="past_swap_positions"' ) !== false, 'swap picker present for round 2' );
ok( strpos( $html, 'data-round="1" data-court="Aces"' ) !== false, 'correction row for round 1 present' );
$ip = spp_kq_render_in_play_screen( OCC, 3, null, null );
ok( strpos( $ip, 'id="kq-save-all-btn"' ) !== false && substr_count( $ip, 'kq-save-score-btn' ) >= 3 && strpos( $ip, 'kq-court-msg' ) !== false, 'In-Play: Save All + per-court Save + in-place messages' );
ok( strpos( $ip, 'kq-fix-link' ) !== false, 'In-Play: Fix a mistake link' );
global $wpdb;
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'organizing' WHERE occurrence_id = %d", OCC ) );
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}scores` SET red_score = NULL, black_score = NULL WHERE occurrence_id = %d AND round_number = 3", OCC ) );
ok( strpos( spp_kq_render_overview_screen( OCC, 3, time() - 600 ), 'kq-fix-link' ) !== false, 'Overview: Fix a mistake link' );
$sb = spp_kq_render_full_scoreboard_screen( OCC );
ok( strpos( $sb, 'kq-fix-link' ) !== false && preg_match( '/name="spp_kq_target_round" class="kq-round-action-select">\s*<option value="3">/', $sb ) && strpos( $sb, '<option value="2">Round 2 (from round 1)' ) === false, 'Scoreboard: Fix link; Rebuild only offers unplayed round 3' );

// ── 13. Ended events ────────────────────────────────────────────────────────
echo "\n13. ended\n";
$T = ( new DateTimeImmutable( 'today 15:42', wp_timezone() ) )->getTimestamp();
$GLOBALS['kqf_now'] = $T;
$_POST = array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'spp_kq_action' => 'end_event', 'spp_kq_round' => '3', 'spp_kq_end_confirm' => 'END', 'spp_kq_end_gen' => '0' );
spp_kq_handle_post_actions( OCC, '2026-01-02', null ); $_POST = array();
ok( phase() === 'complete/3', 'event ended (zz tables)' );
$GLOBALS['kqf_now'] = $T + 60;
$note = html_entity_decode( spp_kq_render_ended_fix_note( OCC ), ENT_QUOTES );
ok( strpos( $note, "Scores can't be changed while the event is ended. To fix one, reopen the event (until " . wp_date( 'g:i a', $T + 3600 ) . '), then use Fix a mistake.' ) !== false, 'inside the window: points to Reopen' );
ok( strpos( spp_kq_render_complete_screen( OCC, 3 ), 'reopen the event (until' ) !== false, 'Complete screen shows it' );
$fx = spp_kq_render_fix_screen( OCC );
ok( strpos( $fx, 'reopen the event' ) !== false && strpos( $fx, 'class="kq-court-card kq-fix-correct-row"' ) === false && strpos( $fx, 'Swap players in a round' ) === false, 'Fix a mistake while ended: only the note' . ( strpos( $fx, 'reopen the event' ) === false ? ' :: ' . strip_tags( $fx ) : '' ) );
$c = spp_kq_correct_score_with_followup( OCC, 1, 'Aces', 3, 11, $MOD );
ok( ! $c['success'] && (int) scores_of( 1 )[0]['red_score'] === 11, 'correction refused while ended' );
$GLOBALS['kqf_now'] = $T + 3601;
ok( strpos( spp_kq_render_ended_fix_note( OCC ), 'contact the administrator' ) !== false, 'after the window: contact the administrator' );
ok( strpos( spp_kq_render_full_scoreboard_screen( OCC ), 'contact the administrator' ) !== false, 'ended scoreboard shows the note' );

// ── Cleanup ─────────────────────────────────────────────────────────────────
echo "\ncleanup\n";
cleanup();
ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqf\\_t\\_%'" ) === 0, 'all zzkqf_t_* tables dropped' );
$after = real_fp();
foreach ( $before as $k => $v ) ok( $after[ $k ] === $v, "real {$k} unchanged" );
ok( empty( $GLOBALS['kqf_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['kqf_blocked'] ? ': ' . implode( ' | ', $GLOBALS['kqf_blocked'] ) : '' ) );
ok( empty( $GLOBALS['kqf_mail'] ), 'no email' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
