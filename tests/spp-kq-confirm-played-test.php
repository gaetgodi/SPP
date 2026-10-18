<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * KQ confirmation before discarding played games (inc/spp-kq-fix.php 1.1.0,
 * inc/spp-kq-live.php 1.17.0, inc/spp-kq-screens.php 1.38.0): Reset Round
 * ("Play a round again") and Void Round ("Don't count a round").
 *
 * Every write goes to throwaway zzkqc_t_* tables (spp_kq_table_name /
 * spp_kq_end_store_table filters); a write firewall rejects anything else;
 * wp_mail() is a capture stub; cron registration is skipped. The fake
 * occurrence (990501) and players (9906xx) exist only in the zz tables.
 *
 * Run (live code):      php tests/spp-kq-confirm-played-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-kq-confirm-played-test.php
 */

$GLOBALS['kqc_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) { $GLOBALS['kqc_mail'][] = $subject; return true; }
define( 'SPP_KQ_NO_CRON_SCHEDULE', true );

$kqc_theme = getenv( 'SPP_TEST_THEME_DIR' );
if ( $kqc_theme ) {
    $GLOBALS['wp_filter'] = array( 'stylesheet_directory' => array( 10 => array( array( 'function' => fn() => $kqc_theme, 'accepted_args' => 1 ) ) ) );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
add_filter( 'pre_wp_mail', function () { return true; } );
if ( ! function_exists( 'spp_kq_run_guarded_round_action' ) ) { echo "ABORT: confirmation code not loaded\n"; exit( 1 ); }
echo 'Theme: ' . get_stylesheet_directory() . "\n";

global $wpdb;
$P = 'zzkqc_t_';
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

$GLOBALS['kqc_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    $t = '?';
    if ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    if ( strpos( $t, 'zzkqc_t_' ) === 0 ) return $q;
    $GLOBALS['kqc_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
    return "SELECT 'kqc_test_blocked_write'";
} );

$REAL = array( 'spp_kq_events', 'spp_kq_assignments', 'spp_kq_scores', 'spp_kq_history', 'spp_kq_checkins' );
function real_fp() {
    global $wpdb, $REAL;
    $fp = array();
    foreach ( $REAL as $t ) $fp[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}{$t}`", ARRAY_A )['Checksum'];
    return $fp;
}
$before = real_fp();

add_filter( 'spp_kq_table_name', function ( $name, $key ) use ( $P ) { return $P . $key; }, 10, 2 );
add_filter( 'spp_kq_end_store_table', function () use ( $P ) { return $P . 'options'; } );

function cleanup() {
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqc\\_t\\_%'" ) as $t ) $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
}
cleanup();
foreach ( $REAL as $t ) $wpdb->query( "CREATE TABLE `{$P}" . substr( $t, 7 ) . "` LIKE `{$wpdb->prefix}{$t}`" );
$wpdb->query( "CREATE TABLE `{$P}options` LIKE `{$wpdb->options}`" );
ok( spp_kq_scores_table() === $P . 'scores', 'tables redirected to zz copies' );

const OCC = 990501;
const COURTS = array( 'Aces', 'Kings', 'Queens' );

function phase() { $s = spp_kq_get_event_state( OCC ); return $s ? $s['phase'] . '/' . $s['current_round'] : 'none'; }
function set_phase( $p ) { global $wpdb, $P; $wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = %s WHERE occurrence_id = %d", $p, OCC ) ); }
function snapshot() {
    global $wpdb, $P;
    return array(
        $wpdb->get_row( $wpdb->prepare( "SELECT current_round, phase, courts_announced_at FROM `{$P}events` WHERE occurrence_id = %d", OCC ), ARRAY_A ),
        $wpdb->get_results( $wpdb->prepare( "SELECT round_number, court_name, red_score, black_score, cancelled FROM `{$P}scores` WHERE occurrence_id = %d ORDER BY round_number, court_name", OCC ), ARRAY_A ),
        $wpdb->get_results( $wpdb->prepare( "SELECT round_number, user_id, court_name, team_color FROM `{$P}assignments` WHERE occurrence_id = %d ORDER BY round_number, user_id", OCC ), ARRAY_A ),
    );
}
function play_round( $n, $ts ) {
    set_phase( 'in_play' );
    $e = array();
    foreach ( COURTS as $i => $c ) $e[ $c ] = array( 'red' => '11', 'black' => (string) ( 3 + $i ) );
    return spp_kq_save_scores_bulk( OCC, $n, $e, $GLOBALS['MOD'], $ts );
}
/** Event with rounds 1..$played played; round $played+1 set up (organizing). */
function fixture( $played ) {
    global $wpdb, $P;
    foreach ( array( 'events', 'assignments', 'scores', 'options' ) as $k ) $wpdb->query( "DELETE FROM `{$P}{$k}`" );
    $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}events` (occurrence_id, current_round, phase, courts_announced_at) VALUES (%d, 1, 'organizing', %d)", OCC, time() - 900 ) );
    $uid = 990601;
    foreach ( COURTS as $court ) {
        foreach ( array( 'red', 'red', 'black', 'black' ) as $color ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}assignments` (occurrence_id, round_number, user_id, court_name, team_color) VALUES (%d, 1, %d, %s, %s)", OCC, $uid++, $court, $color ) );
        }
        $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}scores` (occurrence_id, round_number, court_name, serving_team) VALUES (%d, 1, %s, 'red')", OCC, $court ) );
    }
    for ( $n = 1; $n <= $played; $n++ ) play_round( $n, 1000 * $n );
}
function act( $action, $target, $token = '' ) {
    $s = spp_kq_get_event_state( OCC );
    $f = array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'spp_kq_action' => $action, 'spp_kq_round' => (string) $s['current_round'] );
    $f[ $action === 'reset_round' ? 'spp_kq_reset_target' : 'spp_kq_target_round' ] = (string) $target;
    if ( $token !== '' ) $f['spp_kq_confirm_token'] = $token;
    $_POST = $f;
    $n = spp_kq_handle_post_actions( OCC, '2026-01-02', null );
    $_POST = array();
    return $n;
}
function confirm_of( $notice ) { return spp_kq_parse_confirm_notice( $notice ); }
/** Resubmit exactly what a page sent (stale page / double click). */
function resubmit( array $fields, $token ) {
    $_POST = array_merge( array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ) ), $fields, array( 'spp_kq_confirm_token' => $token ) );
    $n = spp_kq_handle_post_actions( OCC, '2026-01-02', null );
    $_POST = array();
    return $n;
}

$mod = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$MOD = $GLOBALS['MOD'] = (int) ( $mod[0] ?? 0 );
ok( $MOD > 0, "moderator user found ($MOD)" );
wp_set_current_user( $MOD );

// ── 1. Play a round again, no scored later round ────────────────────────────
echo "\n1. Play Round 2 again (round 3 set up, not played)\n";
fixture( 2 );
ok( phase() === 'organizing/3', 'rounds 1-2 played, round 3 set up' );
$snap = snapshot();
$n = act( 'reset_round', 2 );
$c = confirm_of( $n );
ok( $c && $c['confirm'] === true && $c['changed'] === false, 'confirmation required, nothing done yet' );
ok( $c['message'] === 'Playing Round 2 again clears its 3 scores. Those games will no longer count.', 'wording: "' . $c['message'] . '"' );
ok( $c['fields'] === array( 'spp_kq_action' => 'reset_round', 'spp_kq_round' => '3', 'spp_kq_reset_target' => '2' ) && strlen( $c['token'] ) >= 20, 'carries the form fields and a token' );
ok( snapshot() === $snap, 'nothing changed' );
act( 'reset_round', 2 ); // asking again = Cancel then try again: still nothing
ok( snapshot() === $snap, 'cancel / asking again does nothing' );
$n = act( 'reset_round', 2, $c['token'] );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && phase() === 'organizing/2', 'Yes, do it: round 2 will be played again' );
ok( count( array_filter( snapshot()[1], fn( $r ) => (int) $r['round_number'] === 2 && $r['red_score'] !== null ) ) === 0 && count( array_filter( snapshot()[1], fn( $r ) => (int) $r['round_number'] === 3 ) ) === 0, 'round 2 scores cleared, round 3 removed' );
$after = snapshot();
$n = resubmit( $c['fields'], $c['token'] ); // double click / resubmitted page
ok( $n === '' && snapshot() === $after, 'the same confirmed request again does nothing' );

// ── 2. Play a round again, scored later rounds ──────────────────────────────
echo "\n2. Play Round 2 again with Round 3 played\n";
fixture( 3 );
ok( phase() === 'organizing/4', 'rounds 1-3 played, round 4 set up' );
$snap = snapshot();
$c = confirm_of( act( 'reset_round', 2 ) );
ok( $c && $c['message'] === 'Playing Round 2 again clears its 3 scores and also removes Round 3 and its 3 scores. Those games will no longer count.', 'wording: "' . $c['message'] . '"' );
ok( snapshot() === $snap, 'nothing changed before confirming' );
$wrong = confirm_of( act( 'reset_round', 3 ) )['token'];
$n = act( 'reset_round', 2, $wrong );
ok( confirm_of( $n ) && confirm_of( $n )['changed'] === true && snapshot() === $snap, 'a token for a different round is refused (new warning, nothing done)' );
$n = act( 'reset_round', 2, 'not-a-token' );
ok( confirm_of( $n ) && snapshot() === $snap, 'a made-up token is refused' );
// Stale: something changes between the warning and Yes.
$tok = $c['token'];
spp_kq_correct_score_with_followup( OCC, 3, 'Aces', 11, 9, $MOD ); // a correction: counts unchanged
$n = act( 'reset_round', 2, $tok );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && phase() === 'organizing/2', 'a correction in between (same counts) does not block it' );
fixture( 3 );
$tok = confirm_of( act( 'reset_round', 2 ) )['token'];
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}scores` SET red_score = NULL, black_score = NULL WHERE occurrence_id = %d AND round_number = 3 AND court_name = 'Queens'", OCC ) );
$snap = snapshot();
$n = act( 'reset_round', 2, $tok );
$c = confirm_of( $n );
ok( $c && $c['changed'] === true && strpos( $c['message'], 'Something changed since the warning, so nothing was done. Please check again: ' ) === 0 && strpos( $c['message'], 'Round 3 and its 2 scores' ) !== false, 'counts changed: rejected with the new warning ("' . $c['message'] . '")' );
ok( snapshot() === $snap, 'nothing changed' );
$n = act( 'reset_round', 2, $c['token'] );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && phase() === 'organizing/2', 'the new token works' );

// ── 3. Nothing scored affected: no friction ─────────────────────────────────
echo "\n3. no scores affected\n";
fixture( 2 );
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}scores` SET red_score = NULL, black_score = NULL WHERE occurrence_id = %d AND round_number = 2", OCC ) );
$n = act( 'reset_round', 2 );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && phase() === 'organizing/2', 'Play Round 2 again with no scores anywhere: done at once, no warning' );

// ── 4. Don't count a round, without and with a scored later round ───────────
echo "\n4. Don't count a round\n";
fixture( 2 );
set_phase( 'in_play' );
spp_kq_save_scores_bulk( OCC, 3, array( 'Aces' => array( 'red' => '11', 'black' => '1' ) ), $MOD, 9000 );
$snap = snapshot();
$c = confirm_of( act( 'void_round', 3 ) );
ok( $c && $c['message'] === "Not counting Round 3 stops counting its 1 score. Those games will no longer count, and this can't be undone.", 'current round only: "' . $c['message'] . '"' );
ok( snapshot() === $snap, 'nothing changed before confirming' );
$c = confirm_of( act( 'void_round', 2 ) );
ok( $c && $c['message'] === "Not counting Round 2 stops counting its 3 scores and also Round 3 and its 1 score. Those games will no longer count, and this can't be undone.", 'with a scored later round: "' . $c['message'] . '"' );
// Stale: a score arrives in round 3 between the warning and Yes.
spp_kq_save_scores_bulk( OCC, 3, array( 'Kings' => array( 'red' => '11', 'black' => '2' ) ), $MOD, 9001 );
$snap = snapshot();
$n = act( 'void_round', 2, $c['token'] );
$c2 = confirm_of( $n );
ok( $c2 && $c2['changed'] === true && strpos( $c2['message'], 'Round 3 and its 2 scores' ) !== false && snapshot() === $snap, 'a score arrived: rejected, new warning shows 2 scores' );
// The round advances between the warning and Yes.
$tok = $c2['token'];
spp_kq_save_scores_bulk( OCC, 3, array( 'Queens' => array( 'red' => '11', 'black' => '4' ) ), $MOD, 9002 );
ok( phase() === 'organizing/4', 'round 3 finished, round 4 set up' );
$snap = snapshot();
$n = resubmit( $c2['fields'], $tok );
ok( snapshot() === $snap && confirm_of( $n ) && confirm_of( $n )['changed'] === true, 'round advanced since the warning: the old confirmation does nothing, new warning shown' );
$c = confirm_of( act( 'void_round', 2 ) );
ok( $c && strpos( $c['message'], 'Round 3 and its 3 scores' ) !== false, 'asking again shows the up-to-date counts' );
$n = act( 'void_round', 2, $c['token'] );
ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ), 'Yes, do it: done' );
$counted = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$P}scores` WHERE occurrence_id = %d AND round_number >= 2 AND cancelled = 0", OCC ) );
ok( (int) $counted === 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$P}scores` WHERE occurrence_id = %d AND round_number = 1 AND cancelled = 0 AND red_score IS NOT NULL", OCC ) ) === 3, 'rounds 2-4 no longer count, round 1 untouched' );
$after = snapshot();
ok( resubmit( $c['fields'], $c['token'] ) === '' && snapshot() === $after, 'double submit: nothing more happens' );

// ── 5. The lock-side check on its own ───────────────────────────────────────
echo "\n5. re-check inside the transition\n";
fixture( 3 );
$snap = snapshot();
$r = spp_kq_transition_reset_round( OCC, 4, 2, array( 2 => 3, 3 => 2, 4 => 0 ) );
ok( empty( $r['won'] ) && ! empty( $r['stale'] ) && snapshot() === $snap, 'reset: counts differ under the lock -> stale, nothing done' );
$r = spp_kq_transition_void_round( OCC, 2, $MOD, array( 2 => 3, 3 => 3 ) );
ok( empty( $r['won'] ) && ! empty( $r['stale'] ) && snapshot() === $snap, 'void: missing round 4 in the expected counts -> stale, nothing done' );
$r = spp_kq_transition_void_round( OCC, 3, $MOD );
ok( $r['won'], 'without expected counts the transition behaves as before' );

// ── 6. Logged out, and other tools unaffected ───────────────────────────────
echo "\n6. permissions\n";
class KqcDie extends Exception {}
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new KqcDie(); }; } );
fixture( 3 );
$snap = snapshot();
wp_set_current_user( 0 );
$_POST = $_REQUEST = array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'occ' => OCC, 'spp_kq_action' => 'reset_round', 'spp_kq_round' => '4', 'spp_kq_reset_target' => '2' );
ob_start();
try { do_action( 'wp_ajax_spp_kq_live_action' ); } catch ( KqcDie $e ) {}
$j = json_decode( ob_get_clean(), true );
$_POST = $_REQUEST = array();
ok( $j && $j['success'] === false && $j['data'] === 'Not authorized' && snapshot() === $snap, 'logged out: refused, nothing done' );
ok( strpos( spp_kq_live_shortcode(), 'Please log in' ) !== false, 'logged out: the page itself still asks to log in' );
wp_set_current_user( $MOD );
$n = act( 'rebuild_round', 0 );
ok( confirm_of( $n ) === null, 'other actions never return a confirmation' );

// ── 7. Render ───────────────────────────────────────────────────────────────
echo "\n7. render\n";
$c = confirm_of( act( 'reset_round', 2 ) );
$form = spp_kq_render_confirm_form( $c );
ok( strpos( $form, 'kq-warn kq-confirm-panel' ) !== false && strpos( $form, 'kq-notice-err' ) === false && strpos( $form, 'kq-btn-danger' ) === false, 'warning is the calm amber box, no red' );
ok( strpos( $form, 'Playing Round 2 again clears its 3 scores and also removes Round 3 and its 3 scores. Those games will no longer count.' ) !== false, 'shows the exact wording' );
ok( strpos( $form, 'Cancel' ) < strpos( $form, 'Yes, do it' ) && preg_match( '/kq-confirm-cancel"[^>]*autofocus/', $form ), 'Cancel comes first and is focused' );
ok( strpos( $form, 'name="spp_kq_confirm_token" value="' . $c['token'] . '"' ) !== false && strpos( $form, 'name="spp_kq_reset_target" value="2"' ) !== false && strpos( $form, 'spp_kq_nonce' ) !== false, 'no-JS form carries token, fields, nonce' );
$hdr = spp_kq_render_occurrence_header( array( 'event_date' => '2026-01-02', 'eff_event_time' => null, 'eff_title' => 'Test' ), act( 'reset_round', 2 ) );
ok( strpos( $hdr, 'kq-confirm-panel' ) !== false && strpos( $hdr, 'KQCONFIRM' ) === false && strpos( $hdr, 'kq-notice-err' ) === false, 'page header renders the panel, never the raw marker' );
$js = spp_kq_confirm_script();
ok( strpos( $js, "'Yes, do it'" ) !== false && strpos( $js, 'cancel.focus()' ) !== false && strpos( $js, 'yes.disabled = true' ) !== false, 'panel script: Yes, do it / Cancel focused / one click' );
$fx = spp_kq_render_fix_screen( OCC );
ok( strpos( $fx, 'window.SppKqConfirm' ) !== false && strpos( $fx, 'data-confirm-template="' ) === false && strpos( $fx, 'SppKqConfirm.show(form, res.data, post)' ) !== false, 'Fix a mistake: uses the panel, no browser confirm for these two' );
$ov = spp_kq_render_overview_screen( OCC, 4, time() - 600 );
ok( strpos( $ov, "confirm('Reset round" ) === false && strpos( $ov, 'kq-reset-round-form' ) !== false, 'Overview Reset Round: no browser confirm (panel instead)' );
$sb = spp_kq_render_full_scoreboard_screen( OCC );
ok( strpos( $sb, 'window.SppKqConfirm' ) !== false && strpos( $sb, "kind !== 'void'" ) !== false, 'Full Scoreboard Void: panel instead of confirm()' );

// ── Cleanup ─────────────────────────────────────────────────────────────────
echo "\ncleanup\n";
cleanup();
ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqc\\_t\\_%'" ) === 0, 'all zzkqc_t_* tables dropped' );
$after = real_fp();
foreach ( $before as $k => $v ) ok( $after[ $k ] === $v, "real {$k} unchanged" );
ok( empty( $GLOBALS['kqc_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['kqc_blocked'] ? ': ' . implode( ' | ', $GLOBALS['kqc_blocked'] ) : '' ) );
ok( empty( $GLOBALS['kqc_mail'] ), 'no email' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
