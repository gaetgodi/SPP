<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * KQ End Event typed confirmation + Reopen (inc/spp-kq-end-reopen.php).
 *
 * Every write goes to throwaway zzkqe_t_* tables: the KQ tables via the
 * spp_kq_table_name filter, the end-state store via spp_kq_end_store_table.
 * A write firewall rejects any other INSERT/UPDATE/DELETE/DDL (including
 * wp_options), wp_mail() is a capture stub, the clock is spp_kq_end_now,
 * and the hourly cron registration is skipped (SPP_KQ_NO_CRON_SCHEDULE).
 * The fake occurrence (990001) exists only in the zz tables and is dated
 * before SPP_KQ_CLUB_RATING_LAUNCH_DATE, so the deferred finalization's
 * own date guards make it a no-op. Real KQ tables are checksummed before
 * and after.
 *
 * Run (live code):      php tests/spp-kq-end-reopen-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-kq-end-reopen-test.php
 */

$GLOBALS['kqe_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['kqe_mail'][] = array( 'to' => $to, 'subject' => $subject );
    return true;
}
define( 'SPP_KQ_NO_CRON_SCHEDULE', true );

$kqe_theme = getenv( 'SPP_TEST_THEME_DIR' );
if ( $kqe_theme ) {
    $GLOBALS['wp_filter'] = array( 'stylesheet_directory' => array( 10 => array( array( 'function' => fn() => $kqe_theme, 'accepted_args' => 1 ) ) ) );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
add_filter( 'pre_wp_mail', function () { return true; } );
if ( ! function_exists( 'spp_kq_reopen_event' ) ) { echo "ABORT: end/reopen code not loaded\n"; exit( 1 ); }
echo 'Theme: ' . get_stylesheet_directory() . "\n";

global $wpdb;
$P = 'zzkqe_t_';
$pass = 0; $fail = 0;
function kqe_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

// ── Write firewall: only zzkqe_t_* may be written ───────────────────────────
$GLOBALS['kqe_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    $t = '?';
    if ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    if ( strpos( $t, 'zzkqe_t_' ) === 0 ) return $q;
    $GLOBALS['kqe_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
    return "SELECT 'kqe_test_blocked_write'";
} );

$REAL_TABLES = array( 'spp_kq_events', 'spp_kq_assignments', 'spp_kq_scores', 'spp_kq_history', 'spp_kq_checkins' );
function kqe_real_fingerprint() {
    global $wpdb, $REAL_TABLES;
    $fp = array();
    foreach ( $REAL_TABLES as $t ) {
        $fp[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}{$t}`", ARRAY_A )['Checksum'];
    }
    $fp['end_state_rows'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'spp\\_kq\\_end\\_state\\_%'" );
    $fp['club_rating_event_log'] = $wpdb->get_row( 'CHECKSUM TABLE club_rating_event_log', ARRAY_A )['Checksum'];
    return $fp;
}
$before = kqe_real_fingerprint();

// ── Redirect every KQ table + the store to zz tables ────────────────────────
add_filter( 'spp_kq_table_name', function ( $name, $key ) use ( $P ) { return $P . $key; }, 10, 2 );
add_filter( 'spp_kq_end_store_table', function () use ( $P ) { return $P . 'options'; } );
$GLOBALS['kqe_now'] = null;
add_filter( 'spp_kq_end_now', function ( $t ) { return $GLOBALS['kqe_now'] ?? $t; } );

function kqe_cleanup() {
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqe\\_t\\_%'" ) as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
    }
}
kqe_cleanup();
foreach ( $REAL_TABLES as $t ) {
    $key = substr( $t, strlen( 'spp_kq_' ) );
    $wpdb->query( "CREATE TABLE `{$P}{$key}` LIKE `{$wpdb->prefix}{$t}`" );
}
$wpdb->query( "CREATE TABLE `{$P}options` LIKE `{$wpdb->options}`" );
kqe_ok( spp_kq_events_table() === $P . 'events' && spp_kq_end_store_table() === $P . 'options', 'tables redirected to zz copies' );

const KQE_OCC  = 990001;
const KQE_DATE = '2026-01-02'; // before SPP_KQ_CLUB_RATING_LAUNCH_DATE: finalization is a no-op
$tz = wp_timezone();
$T_END      = ( new DateTimeImmutable( 'today 14:00', $tz ) )->getTimestamp();
$T_LATE     = ( new DateTimeImmutable( 'today 23:59:30', $tz ) )->getTimestamp();
$T_NEXTDAY  = ( new DateTimeImmutable( 'tomorrow 00:00:30', $tz ) )->getTimestamp();

function kqe_fixture() {
    global $wpdb, $P;
    foreach ( array( 'events', 'assignments', 'scores', 'options' ) as $k ) $wpdb->query( "DELETE FROM `{$P}{$k}`" );
    $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}events` (occurrence_id, current_round, phase, courts_announced_at) VALUES (%d, 3, 'organizing', %d)", KQE_OCC, time() - 600 ) );
    $uid = 900001;
    foreach ( array( 1, 2, 3 ) as $round ) {
        foreach ( array( 'Aces', 'Kings' ) as $ci => $court ) {
            foreach ( array( 'red', 'red', 'black', 'black' ) as $i => $color ) {
                $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}assignments` (occurrence_id, round_number, user_id, court_name, team_color) VALUES (%d, %d, %d, %s, %s)", KQE_OCC, $round, $uid + $ci * 4 + $i, $court, $color ) );
            }
            if ( $round < 3 ) {
                $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}scores` (occurrence_id, round_number, court_name, red_score, black_score, serving_team) VALUES (%d, %d, %s, 11, %d, 'red')", KQE_OCC, $round, $court, 5 + $ci ) );
            } else {
                $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}scores` (occurrence_id, round_number, court_name, serving_team) VALUES (%d, %d, %s, 'red')", KQE_OCC, $round, $court ) );
            }
        }
    }
}
function kqe_live_snapshot() {
    global $wpdb, $P;
    return array(
        'event'       => $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id, current_round, phase, round_duration_seconds, round_started_at, courts_announced_at FROM `{$P}events` WHERE occurrence_id = %d", KQE_OCC ), ARRAY_A ),
        'assignments' => $wpdb->get_results( "SELECT * FROM `{$P}assignments` ORDER BY id", ARRAY_A ),
        'scores'      => $wpdb->get_results( "SELECT occurrence_id, round_number, court_name, red_score, black_score, cancelled, serving_team, updated_by, client_ts FROM `{$P}scores` ORDER BY round_number, court_name", ARRAY_A ),
    );
}
function kqe_phase() { $s = spp_kq_get_event_state( KQE_OCC ); return $s ? $s['phase'] . '/' . $s['current_round'] : 'none'; }
function kqe_post( array $fields ) {
    $_POST = array_merge( array( 'spp_kq_nonce' => wp_create_nonce( 'spp_kq_live_action' ), 'spp_kq_round' => '3' ), $fields );
    $n = spp_kq_handle_post_actions( KQE_OCC, KQE_DATE, null );
    $_POST = array();
    return $n;
}
function kqe_end( $typed, $gen = '0' ) {
    $f = array( 'spp_kq_action' => 'end_event' );
    if ( $typed !== null ) $f['spp_kq_end_confirm'] = $typed;
    if ( $gen !== null ) $f['spp_kq_end_gen'] = $gen;
    return kqe_post( $f );
}
function kqe_reopen( $ended_at ) { return kqe_post( array( 'spp_kq_action' => 'reopen_event', 'spp_kq_ended_at' => (string) $ended_at ) ); }

$mod = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$MOD = (int) ( $mod[0] ?? 0 );
kqe_ok( $MOD > 0, "moderator user found ($MOD)" );
wp_set_current_user( $MOD );

// ── 1. Wrong or empty text never ends ───────────────────────────────────────
echo "\n1. wrong text\n";
kqe_fixture();
$orig = kqe_live_snapshot();
$GLOBALS['kqe_now'] = $T_END;
foreach ( array( 'missing field' => null, 'empty' => '', 'spaces' => '   ', 'ENDD' => 'ENDD', 'end now' => 'end now', 'E N D' => 'E N D', 'yes' => 'yes' ) as $label => $typed ) {
    $n = kqe_end( $typed );
    kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'not ended' ) !== false, "'$label' refused, still organizing/3" );
}
kqe_ok( spp_kq_end_state_get( KQE_OCC ) === null, 'nothing recorded' );
// Bad nonce
$_POST = array( 'spp_kq_nonce' => 'bad', 'spp_kq_action' => 'end_event', 'spp_kq_round' => '3', 'spp_kq_end_confirm' => 'END', 'spp_kq_end_gen' => '0' );
$n = spp_kq_handle_post_actions( KQE_OCC, KQE_DATE, null ); $_POST = array();
kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'Security' ) !== false, 'bad nonce refused' );

// ── 2. Non-moderator cannot end ─────────────────────────────────────────────
echo "\n2. non-moderator\n";
wp_set_current_user( 0 );
$n = kqe_end( 'END' );
kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'permission' ) !== false, 'logged-out user cannot end' );
wp_set_current_user( $MOD );

// ── 3. Correct text ends; nothing else written ──────────────────────────────
echo "\n3. correct text\n";
$n = kqe_end( "  end \n" );
kqe_ok( kqe_phase() === 'complete/3', "' end ' (lowercase, spaces) ends the event" );
kqe_ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ), 'success notice' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es && $es['status'] === 'ended' && (int) $es['round'] === 3 && (int) $es['ended_by'] === $MOD && (int) $es['ended_at'] === $T_END, 'store: ended, round 3, ended_by, ended_at' );
kqe_ok( $es['ended_day'] === wp_date( 'Y-m-d', $T_END ) && $es['event_date'] === KQE_DATE, 'store: ended_day is the local day, event_date kept' );
$after_end = kqe_live_snapshot();
kqe_ok( $after_end['assignments'] === $orig['assignments'] && $after_end['scores'] === $orig['scores'], 'End changed no assignment or score' );
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$P}history`" ) === 0, 'End sent no email and archived no history (deferred)' );

// ── 4. Double submit of End ─────────────────────────────────────────────────
echo "\n4. double submit End\n";
$n = kqe_end( 'END' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( kqe_phase() === 'complete/3' && $n === '' && count( $es['log'] ) === 1 && (int) $es['ended_at'] === $T_END, 'second End is a silent no-op, one log entry' );

// ── 5. Ended screen render ──────────────────────────────────────────────────
echo "\n5. ended panel\n";
$html = spp_kq_render_ended_panel( KQE_OCC );
kqe_ok( strpos( $html, 'Reopen event' ) !== false && strpos( $html, 'name="spp_kq_action" value="reopen_event"' ) !== false, 'same day: Reopen button shown' );
kqe_ok( strpos( $html, 'value="' . $T_END . '"' ) !== false && strpos( $html, 'spp_kq_nonce' ) !== false, 'Reopen form carries ended_at and nonce' );
kqe_ok( strpos( $html, 'Ended at ' . wp_date( 'g:ia', $T_END ) . ' by ' . esc_html( get_userdata( $MOD )->display_name ) ) !== false, 'shows ended at/by' );
$complete = spp_kq_render_complete_screen( KQE_OCC, 3 );
kqe_ok( strpos( $complete, 'Event complete.' ) !== false && strpos( $complete, 'Reopen event' ) !== false, 'Complete screen includes the panel' );
wp_set_current_user( 0 );
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'Reopen event' ) === false, 'no Reopen button for a logged-out user' );
wp_set_current_user( $MOD );
$GLOBALS['kqe_now'] = $T_NEXTDAY;
$html = spp_kq_render_ended_panel( KQE_OCC );
kqe_ok( strpos( $html, 'Reopen event' ) === false && strpos( $html, 'contact the administrator' ) !== false, 'next day: no button, contact-administrator message' );

// ── 6. Reopen refusals ──────────────────────────────────────────────────────
echo "\n6. reopen refusals\n";
$GLOBALS['kqe_now'] = $T_NEXTDAY;
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, 'reopen at 00:00:30 next day refused' );
$GLOBALS['kqe_now'] = $T_LATE;
wp_set_current_user( 0 );
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'permission' ) !== false, 'logged-out user cannot reopen' );
wp_set_current_user( $MOD );
$n = kqe_reopen( $T_END - 5 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'out of date' ) !== false, 'stale ended_at refused' );

// ── 7. Reopen same day restores the exact pre-End state ─────────────────────
echo "\n7. reopen same day\n";
$GLOBALS['kqe_now'] = $T_LATE; // 23:59:30, same day
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'organizing/3' && str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && strpos( $n, 'Round 3' ) !== false, 'reopened at 23:59:30, back to organizing/3' );
kqe_ok( kqe_live_snapshot() === $orig, 'events row (all columns but updated_at), assignments and scores identical to before End' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es['status'] === 'reopened' && (int) $es['reopened_by'] === $MOD && (int) $es['reopened_at'] === $T_LATE, 'store: reopened by/at recorded' );
kqe_ok( array_column( $es['log'], 'action' ) === array( 'end', 'reopen' ), 'log: end, reopen' );
$ov = spp_kq_render_overview_screen( KQE_OCC, 3, time() - 600 );
kqe_ok( strpos( $ov, 'name="spp_kq_end_confirm"' ) !== false, 'Overview offers End again' );
kqe_ok( strpos( $ov, 'spp_photo_submit' ) === false && strpos( $ov, 'Please take a photo' ) === false, 'photo form not on the reopened screen' );

// ── 8. Double reopen ────────────────────────────────────────────────────────
echo "\n8. double reopen\n";
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'organizing/3' && $n === '' && count( spp_kq_end_state_get( KQE_OCC )['log'] ) === 2, 'second Reopen is a silent no-op' );

// ── 9. Stale End page resubmitted after Reopen ──────────────────────────────
echo "\n9. stale End resubmit\n";
$n = kqe_end( 'END', '0' );
kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'out of date' ) !== false, 'End with the pre-Reopen gen (0) refused' );
$n = kqe_end( 'END', null );
kqe_ok( kqe_phase() === 'organizing/3', 'End without gen refused' );
$GLOBALS['kqe_now'] = $T_LATE + 10;
$n = kqe_end( 'End', '1' );
kqe_ok( kqe_phase() === 'complete/3', 'End with current gen (1) ends again' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es['status'] === 'ended' && (int) $es['ended_at'] === $T_LATE + 10 && array_column( $es['log'], 'action' ) === array( 'end', 'reopen', 'end' ), 'second End recorded; log end, reopen, end' );
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'out of date' ) !== false, 'old Reopen page (first ended_at) cannot undo the second End' );
$html = spp_kq_render_ended_panel( KQE_OCC );
kqe_ok( strpos( $html, 'Reopened earlier at' ) !== false, 'panel mentions the earlier reopen' );

// ── 10. Deferred finalization ───────────────────────────────────────────────
echo "\n10. finalization\n";
kqe_ok( spp_kq_end_finalize_pending( KQE_OCC ), 'finalization pending' );
$GLOBALS['kqe_now'] = $T_LATE + 15;
kqe_ok( spp_kq_finalize_ended_event( KQE_OCC ) === null && spp_kq_end_state_get( KQE_OCC )['status'] === 'ended', 'same day: finalizer does nothing' );
$GLOBALS['kqe_now'] = $T_NEXTDAY;
$done = spp_kq_finalize_due_events();
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( array_keys( $done ) === array( KQE_OCC ) && $es['status'] === 'finalized' && (int) $es['finalized_at'] === $T_NEXTDAY, 'next day: sweep finalizes it' );
kqe_ok( end( $es['log'] )['action'] === 'finalize', 'log: finalize appended' );
kqe_ok( spp_kq_finalize_ended_event( KQE_OCC ) === null && spp_kq_finalize_due_events() === array(), 'finalizes only once' );
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 0, 'pre-launch fixture: no email' );
$GLOBALS['kqe_now'] = $T_LATE; // even "back in time", a finalized event can't be reopened
$n = kqe_reopen( $T_LATE + 10 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, 'finalized event cannot be reopened' );
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'can no longer be reopened' ) !== false, 'panel says it is processed' );

// ── 11. Legacy ended event (no record) ──────────────────────────────────────
echo "\n11. legacy\n";
kqe_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'complete' WHERE occurrence_id = %d", KQE_OCC ) );
$n = kqe_reopen( 0 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, 'event ended before this feature cannot be reopened' );
kqe_ok( spp_kq_render_ended_panel( KQE_OCC ) === '', 'no panel for it' );

// ── 12. Lock held elsewhere: End waits, then gives up without ending ────────
echo "\n12. lock\n";
kqe_fixture();
$GLOBALS['kqe_now'] = $T_END;
$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$got = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( spp_kq_end_lock_name() ) . "', 0)" )->fetch_row()[0];
kqe_ok( $got === '1', 'second connection holds the end/reopen lock' );
$t0 = microtime( true );
$n = kqe_end( 'END' );
kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'busy' ) !== false && microtime( true ) - $t0 >= 9, 'End refused while the lock is held (waited ~10s)' );
$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( spp_kq_end_lock_name() ) . "')" );
$other->close();
$n = kqe_end( 'END' );
kqe_ok( kqe_phase() === 'complete/3', 'End works once the lock is free' );

// ── 13. Confirmation UI render ──────────────────────────────────────────────
echo "\n13. confirmation UI\n";
kqe_fixture();
$ui = spp_kq_render_end_confirm( KQE_OCC, 3 );
kqe_ok( strpos( $ui, '<details class="kq-end-confirm"' ) !== false && strpos( $ui, 'End Event&hellip;' ) !== false, 'collapsed End Event control' );
kqe_ok( strpos( $ui, 'name="spp_kq_action" value="end_event"' ) !== false && strpos( $ui, 'name="spp_kq_end_confirm"' ) !== false && strpos( $ui, 'name="spp_kq_end_gen" value="0"' ) !== false && strpos( $ui, 'name="spp_kq_nonce"' ) !== false, 'form: action, typed box, gen, nonce' );
kqe_ok( strpos( $ui, 'Type <strong>END</strong> to confirm' ) !== false, 'asks to type END' );
kqe_ok( strpos( $ui, 'Round 3 is set up but not played' ) !== false, 'unfinished round shown' );
kqe_ok( strpos( $ui, 'reopen it until midnight tonight' ) !== false && strpos( $ui, 'Scores can no longer be corrected' ) !== false, 'warns about corrections and mentions Reopen' );
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}scores` SET red_score = 11, black_score = 3 WHERE occurrence_id = %d AND round_number = 3 AND court_name = 'Aces'", KQE_OCC ) );
$ui = spp_kq_render_end_confirm( KQE_OCC, 3 );
kqe_ok( strpos( $ui, 'Round 3: 1 of 2 courts have no score' ) !== false, 'partly scored round: missing count shown' );
$ov = spp_kq_render_overview_screen( KQE_OCC, 3, time() - 600 );
kqe_ok( strpos( $ov, 'kq-end-confirm' ) !== false && strpos( $ov, "confirm('End the event now?" ) === false, 'Overview uses the new control, old one-click confirm gone' );

// ── 14. Cancel Event still finalizes at once (pre-launch: no-op) ────────────
echo "\n14. cancel\n";
kqe_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'in_play' WHERE occurrence_id = %d", KQE_OCC ) );
$n = kqe_post( array( 'spp_kq_action' => 'cancel_event' ) );
kqe_ok( kqe_phase() === 'cancelled/3' && $n === '' && spp_kq_end_state_get( KQE_OCC ) === null, 'cancel unchanged: cancelled, no end-state record' );

// ── Cleanup + real data untouched ───────────────────────────────────────────
echo "\ncleanup\n";
kqe_cleanup();
kqe_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqe\\_t\\_%'" ) === 0, 'all zzkqe_t_* tables dropped' );
$after = kqe_real_fingerprint();
foreach ( $before as $k => $v ) kqe_ok( $after[ $k ] === $v, "real {$k} unchanged" );
kqe_ok( empty( $GLOBALS['kqe_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['kqe_blocked'] ? ': ' . implode( ' | ', $GLOBALS['kqe_blocked'] ) : '' ) );
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 0, 'no email at all' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
