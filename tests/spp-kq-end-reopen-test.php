<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * KQ End Event typed confirmation + 60-minute Reopen + deferred
 * finalization (inc/spp-kq-end-reopen.php 1.1.0) and the doubleheader
 * inactivity rule (inc/spp-kq-format-ranking.php 1.6.0).
 *
 * Every write goes to throwaway zzkqe_t_* tables: the KQ tables and the
 * gl_events_v stand-in via the spp_kq_table_name filter, the end-state
 * store via spp_kq_end_store_table. A write firewall rejects any other
 * INSERT/UPDATE/DELETE/DDL (including wp_options and usermeta). spp_kq_*
 * usermeta is kept in memory (get/update/delete_user_metadata filters),
 * wp_mail() is a capture stub, the clock is spp_kq_end_now, and the cron
 * registration is skipped (SPP_KQ_NO_CRON_SCHEDULE). The fake occurrences
 * (9900xx) exist only in the zz tables. Real KQ tables, club_rating_event_log
 * and the real end-state rows are checksummed before and after.
 *
 * Run (live code):      php tests/spp-kq-end-reopen-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-kq-end-reopen-test.php
 */

$GLOBALS['kqe_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['kqe_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $message );
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
if ( ! function_exists( 'spp_kq_send_stall_alert' ) ) { echo "ABORT: end/reopen 1.1.0 code not loaded\n"; exit( 1 ); }
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

// ── In-memory spp_kq_* usermeta (never touches the usermeta table) ──────────
$GLOBALS['kqe_meta'] = array();
add_filter( 'get_user_metadata', function ( $check, $uid, $key, $single ) {
    if ( strpos( (string) $key, 'spp_kq_' ) !== 0 ) return $check;
    if ( ! isset( $GLOBALS['kqe_meta'][ $uid ][ $key ] ) ) {
        return $uid >= 990000 ? ( $single ? array( '' ) : array() ) : $check; // real users: read the real value
    }
    return array( $GLOBALS['kqe_meta'][ $uid ][ $key ] );
}, 10, 4 );
add_filter( 'update_user_metadata', function ( $check, $uid, $key, $value ) {
    if ( strpos( (string) $key, 'spp_kq_' ) !== 0 ) return $check;
    $GLOBALS['kqe_meta'][ $uid ][ $key ] = $value;
    return true;
}, 10, 4 );
add_filter( 'add_user_metadata', function ( $check, $uid, $key, $value ) {
    if ( strpos( (string) $key, 'spp_kq_' ) !== 0 ) return $check;
    $GLOBALS['kqe_meta'][ $uid ][ $key ] = $value;
    return true;
}, 10, 4 );
add_filter( 'delete_user_metadata', function ( $check, $uid, $key ) {
    if ( strpos( (string) $key, 'spp_kq_' ) !== 0 ) return $check;
    unset( $GLOBALS['kqe_meta'][ $uid ][ $key ] );
    return true;
}, 10, 3 );

$REAL_TABLES = array( 'spp_kq_events', 'spp_kq_assignments', 'spp_kq_scores', 'spp_kq_history', 'spp_kq_checkins' );
function kqe_real_fingerprint() {
    global $wpdb, $REAL_TABLES;
    $fp = array();
    foreach ( $REAL_TABLES as $t ) {
        $fp[ $t ] = $wpdb->get_row( "CHECKSUM TABLE `{$wpdb->prefix}{$t}`", ARRAY_A )['Checksum'];
    }
    $fp['end_state_rows'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'spp\\_kq\\_end\\_state\\_%'" );
    $fp['club_rating_event_log'] = $wpdb->get_row( 'CHECKSUM TABLE club_rating_event_log', ARRAY_A )['Checksum'];
    $fp['spp_kq_usermeta'] = $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(user_id, meta_key, meta_value))), 0)) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'spp\\_kq\\_%'" );
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
$wpdb->query( "CREATE TABLE `{$P}gl_events_v` (occurrence_id INT UNSIGNED NOT NULL PRIMARY KEY, eff_category_id INT NOT NULL, event_date DATE NOT NULL, cancelled TINYINT NOT NULL DEFAULT 0)" );
kqe_ok( spp_kq_events_table() === $P . 'events' && spp_kq_end_store_table() === $P . 'options', 'tables redirected to zz copies' );

const KQE_OCC  = 990001;
const KQE_DATE = '2026-01-02'; // sandbox-style: before SPP_KQ_CLUB_RATING_LAUNCH_DATE, finalization is a no-op
const W = SPP_KQ_REOPEN_WINDOW_SECONDS;
$tz    = wp_timezone();
$T_END = ( new DateTimeImmutable( 'today 15:42', $tz ) )->getTimestamp();

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
function kqe_phase( $occ = KQE_OCC ) { $s = spp_kq_get_event_state( $occ ); return $s ? $s['phase'] . '/' . $s['current_round'] : 'none'; }
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
function kqe_mails_to_admin() { return array_values( array_filter( $GLOBALS['kqe_mail'], fn( $m ) => $m['to'] === get_option( 'admin_email' ) ) ); }

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
kqe_ok( str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && strpos( $n, 'reopen it until ' . wp_date( 'g:i a', $T_END + W ) ) !== false, 'success notice gives the reopen deadline (' . wp_date( 'g:i a', $T_END + W ) . ')' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es && $es['status'] === 'ended' && (int) $es['round'] === 3 && (int) $es['ended_by'] === $MOD && (int) $es['ended_at'] === $T_END, 'store: ended, round 3, ended_by, ended_at' );
kqe_ok( (int) $es['reopen_until'] === $T_END + 3600 && $es['event_date'] === KQE_DATE, 'store: reopen_until = ended_at + 60 min, event_date kept' );
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
$GLOBALS['kqe_now'] = $T_END + 60;
$html = spp_kq_render_ended_panel( KQE_OCC );
kqe_ok( strpos( $html, 'Reopen event' ) !== false && strpos( $html, 'name="spp_kq_action" value="reopen_event"' ) !== false, 'inside the window: Reopen button shown' );
kqe_ok( strpos( $html, 'You can reopen until ' . wp_date( 'g:i a', $T_END + W ) ) !== false, 'shows "You can reopen until ' . wp_date( 'g:i a', $T_END + W ) . '"' );
kqe_ok( strpos( $html, 'value="' . $T_END . '"' ) !== false && strpos( $html, 'spp_kq_nonce' ) !== false, 'Reopen form carries ended_at and nonce' );
kqe_ok( strpos( $html, 'Ended at ' . wp_date( 'g:i a', $T_END ) . ' by ' . esc_html( get_userdata( $MOD )->display_name ) ) !== false, 'shows ended at/by' );
kqe_ok( strpos( $html, '__kqEndedTimer' ) !== false, 'page reloads itself when the window closes' );
$complete = spp_kq_render_complete_screen( KQE_OCC, 3 );
kqe_ok( strpos( $complete, 'Event complete.' ) !== false && strpos( $complete, 'Reopen event' ) !== false, 'Complete screen includes the panel' );
wp_set_current_user( 0 );
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'Reopen event' ) === false, 'no Reopen button for a logged-out user' );
wp_set_current_user( $MOD );
$GLOBALS['kqe_now'] = $T_END + W + 1;
$html = spp_kq_render_ended_panel( KQE_OCC );
kqe_ok( strpos( $html, 'Reopen event' ) === false && strpos( $html, 'being processed' ) !== false, 'after the window: no button, "being processed"' );

// ── 6. Reopen refusals ──────────────────────────────────────────────────────
echo "\n6. reopen refusals\n";
foreach ( array( '60:01' => W + 1, '60:00' => W ) as $label => $off ) {
    $GLOBALS['kqe_now'] = $T_END + $off;
    $n = kqe_reopen( $T_END );
    kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, "reopen at $label refused (contact the administrator)" );
}
$GLOBALS['kqe_now'] = $T_END + W - 1;
wp_set_current_user( 0 );
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'permission' ) !== false, 'logged-out user cannot reopen' );
wp_set_current_user( $MOD );
$n = kqe_reopen( $T_END - 5 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'out of date' ) !== false, 'stale ended_at refused' );
kqe_ok( spp_kq_finalize_ended_event( KQE_OCC ) === null && spp_kq_end_state_get( KQE_OCC )['status'] === 'ended', 'finalizer does nothing at 59:59' );

// ── 7. Reopen at 59:59 restores the exact pre-End state ─────────────────────
echo "\n7. reopen at 59:59\n";
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'organizing/3' && str_starts_with( $n, SPP_KQ_NOTICE_OK_PREFIX ) && strpos( $n, 'Round 3' ) !== false, 'reopened at 59:59, back to organizing/3' );
kqe_ok( kqe_live_snapshot() === $orig, 'events row (all columns but updated_at), assignments and scores identical to before End' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es['status'] === 'reopened' && (int) $es['reopened_by'] === $MOD && (int) $es['reopened_at'] === $T_END + W - 1, 'store: reopened by/at recorded' );
kqe_ok( array_column( $es['log'], 'action' ) === array( 'end', 'reopen' ), 'log: end, reopen' );
$ov = spp_kq_render_overview_screen( KQE_OCC, 3, time() - 600 );
kqe_ok( strpos( $ov, 'name="spp_kq_end_confirm"' ) !== false, 'Overview offers End again' );
kqe_ok( strpos( $ov, 'Please take a photo' ) === false, 'photo form not on the reopened screen' );
$GLOBALS['kqe_now'] = $T_END + 2 * W;
kqe_ok( spp_kq_finalize_due_events() === array( 'finalized' => array(), 'alerted' => array() ), 'a reopened event is never finalized by the sweep' );

// ── 8. Double reopen ────────────────────────────────────────────────────────
echo "\n8. double reopen\n";
$GLOBALS['kqe_now'] = $T_END + W - 1;
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'organizing/3' && $n === '' && count( spp_kq_end_state_get( KQE_OCC )['log'] ) === 2, 'second Reopen is a silent no-op' );

// ── 9. Stale End resubmit; End again restarts the 60 minutes ────────────────
echo "\n9. stale End resubmit\n";
$n = kqe_end( 'END', '0' );
kqe_ok( kqe_phase() === 'organizing/3' && strpos( $n, 'out of date' ) !== false, 'End with the pre-Reopen gen (0) refused' );
$n = kqe_end( 'END', null );
kqe_ok( kqe_phase() === 'organizing/3', 'End without gen refused' );
$T2 = $T_END + W + 300;
$GLOBALS['kqe_now'] = $T2;
$n = kqe_end( 'End', '1' );
kqe_ok( kqe_phase() === 'complete/3', 'End with current gen (1) ends again' );
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( $es['status'] === 'ended' && (int) $es['ended_at'] === $T2 && (int) $es['reopen_until'] === $T2 + W && array_column( $es['log'], 'action' ) === array( 'end', 'reopen', 'end' ), 'second End: new 60-minute window, log end, reopen, end' );
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'out of date' ) !== false, 'old Reopen page (first ended_at) cannot undo the second End' );
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'Reopened earlier at' ) !== false, 'panel mentions the earlier reopen' );

// ── 10. Deferred finalization: not before 60 min, exactly once after ────────
echo "\n10. finalization\n";
kqe_ok( spp_kq_end_finalize_pending( KQE_OCC ), 'finalization pending' );
$GLOBALS['kqe_now'] = $T2 + W - 1;
$r = spp_kq_finalize_due_events();
kqe_ok( $r['finalized'] === array() && spp_kq_end_state_get( KQE_OCC )['status'] === 'ended', 'sweep at 59:59 does nothing' );
$GLOBALS['kqe_now'] = $T2 + W;
$r = spp_kq_finalize_due_events();
$es = spp_kq_end_state_get( KQE_OCC );
kqe_ok( array_keys( $r['finalized'] ) === array( KQE_OCC ) && $es['status'] === 'finalized' && (int) $es['finalized_at'] === $T2 + W, 'sweep at 60:00 finalizes it' );
kqe_ok( end( $es['log'] )['action'] === 'finalize' && count( array_filter( $es['log'], fn( $e ) => $e['action'] === 'finalize' ) ) === 1, 'log: one finalize' );
$GLOBALS['kqe_now'] = $T2 + W + 300;
kqe_ok( spp_kq_finalize_due_events() === array( 'finalized' => array(), 'alerted' => array() ) && spp_kq_finalize_ended_event( KQE_OCC ) === null, 'second sweep and a direct call are harmless' );
kqe_ok( count( spp_kq_end_state_get( KQE_OCC )['log'] ) === 4, 'still one finalize in the log' );
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$P}history`" ) === 0, 'sandbox-dated event: finalization sent no email, archived nothing' );
$GLOBALS['kqe_now'] = $T2 + 10; // even "back inside the window", a finalized event can't be reopened
$n = kqe_reopen( $T2 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, 'finalized event cannot be reopened' );
$GLOBALS['kqe_now'] = $T2 + W + 300;
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'Results were processed at' ) !== false, 'panel: results were processed' );

// ── 11. Stalled run: one alert email, never retried ─────────────────────────
echo "\n11. stalled finalization\n";
$es['status'] = 'finalizing'; $es['finalize_started_at'] = $T2 + W; unset( $es['stall_alerted_at'] );
spp_kq_end_state_put( KQE_OCC, $es );
$GLOBALS['kqe_mail'] = array();
$GLOBALS['kqe_now'] = $T2 + W + 899;
$r = spp_kq_finalize_due_events();
kqe_ok( $r['alerted'] === array() && count( $GLOBALS['kqe_mail'] ) === 0, 'stuck 14:59: no alert yet' );
kqe_ok( strpos( spp_kq_render_ended_panel( KQE_OCC ), 'being processed now' ) !== false, 'panel: being processed now' );
$GLOBALS['kqe_now'] = $T2 + W + 900;
$r = spp_kq_finalize_due_events();
$m = kqe_mails_to_admin();
kqe_ok( $r['alerted'] === array( KQE_OCC ) && count( $GLOBALS['kqe_mail'] ) === 1 && count( $m ) === 1, 'stuck 15:00: one alert to admin_email (' . get_option( 'admin_email' ) . ')' );
kqe_ok( strpos( $m[0]['subject'], (string) KQE_OCC ) !== false && strpos( $m[0]['body'], '"status": "finalizing"' ) !== false && strpos( $m[0]['body'], 'NOT be retried' ) !== false && strpos( $m[0]['body'], 'club_rating_event_log' ) !== false, 'alert names the event, shows the state and what to check' );
$GLOBALS['kqe_now'] = $T2 + W + 3000;
spp_kq_finalize_due_events(); spp_kq_finalize_due_events();
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 1, 'later sweeps: no second alert' );
kqe_ok( spp_kq_end_state_get( KQE_OCC )['status'] === 'finalizing', 'run not retried (still finalizing)' );
kqe_ok( spp_kq_send_stall_alert( KQE_OCC ) === false, 'direct second alert call refused' );
$GLOBALS['kqe_mail'] = array();

// ── 12. 1.0.0-style row and legacy event ────────────────────────────────────
echo "\n12. legacy\n";
kqe_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'complete' WHERE occurrence_id = %d", KQE_OCC ) );
$n = kqe_reopen( 0 );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, 'event ended before this feature (no record) cannot be reopened' );
kqe_ok( spp_kq_render_ended_panel( KQE_OCC ) === '', 'no panel for it' );
kqe_ok( spp_kq_finalize_due_events()['finalized'] === array(), 'sweep ignores it' );
spp_kq_end_state_put( KQE_OCC, array( 'status' => 'ended', 'round' => 3, 'event_date' => KQE_DATE, 'ended_at' => $T_END, 'ended_by' => $MOD, 'ended_day' => wp_date( 'Y-m-d', $T_END ), 'log' => array() ) );
$GLOBALS['kqe_now'] = $T_END + 10;
$n = kqe_reopen( $T_END );
kqe_ok( kqe_phase() === 'complete/3' && strpos( $n, 'contact the administrator' ) !== false, '1.0.0 row (no reopen_until) cannot be reopened' );
$GLOBALS['kqe_now'] = $T_END + 5 * W;
kqe_ok( spp_kq_finalize_due_events()['finalized'] === array() && spp_kq_end_state_get( KQE_OCC )['status'] === 'ended', '1.0.0 row is never auto-finalized' );

// ── 13. Lock held elsewhere: End waits, then gives up without ending ────────
echo "\n13. lock\n";
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

// ── 14. Confirmation UI render ──────────────────────────────────────────────
echo "\n14. confirmation UI\n";
kqe_fixture();
$ui = spp_kq_render_end_confirm( KQE_OCC, 3 );
kqe_ok( strpos( $ui, '<details class="kq-end-confirm"' ) !== false && strpos( $ui, 'End Event&hellip;' ) !== false, 'collapsed End Event control' );
kqe_ok( strpos( $ui, 'name="spp_kq_action" value="end_event"' ) !== false && strpos( $ui, 'name="spp_kq_end_confirm"' ) !== false && strpos( $ui, 'name="spp_kq_end_gen" value="0"' ) !== false && strpos( $ui, 'name="spp_kq_nonce"' ) !== false, 'form: action, typed box, gen, nonce' );
kqe_ok( strpos( $ui, 'Type <strong>END</strong> to confirm' ) !== false, 'asks to type END' );
kqe_ok( strpos( $ui, 'Round 3 is set up but not played' ) !== false, 'unfinished round shown' );
kqe_ok( strpos( $ui, 'You can reopen it for 60 minutes' ) !== false && strpos( $ui, 'Scores can no longer be corrected' ) !== false && strpos( $ui, 'midnight' ) === false, 'warns about corrections, says 60 minutes (no "midnight")' );
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}scores` SET red_score = 11, black_score = 3 WHERE occurrence_id = %d AND round_number = 3 AND court_name = 'Aces'", KQE_OCC ) );
$ui = spp_kq_render_end_confirm( KQE_OCC, 3 );
kqe_ok( strpos( $ui, 'Round 3: 1 of 2 courts have no score' ) !== false, 'partly scored round: missing count shown' );
$ov = spp_kq_render_overview_screen( KQE_OCC, 3, time() - 600 );
kqe_ok( strpos( $ov, 'kq-end-confirm' ) !== false && strpos( $ov, "confirm('End the event now?" ) === false, 'Overview uses the new control, old one-click confirm gone' );

// ── 15. Cancel Event still finalizes at once (sandbox date: no-op) ──────────
echo "\n15. cancel\n";
kqe_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}events` SET phase = 'in_play' WHERE occurrence_id = %d", KQE_OCC ) );
$n = kqe_post( array( 'spp_kq_action' => 'cancel_event' ) );
kqe_ok( kqe_phase() === 'cancelled/3' && $n === '' && spp_kq_end_state_get( KQE_OCC ) === null, 'cancel unchanged: cancelled, no end-state record' );

// ── 16. Doubleheader: the first session finalizing early charges nobody ─────
echo "\n16. doubleheader\n";
// Ace 9:00 (A) and Ace 10:30 (B) on the same post-launch date; a Queen
// session (Q) the same day and an Ace session (X) the next day must not
// interfere. P1 plays A only, P2 is a walk-in added to B AFTER A
// finalized (never a confirmed B registrant when A ran), P3 plays
// neither, P4 plays both. All start active with average 4.0.
const DH_A = 990010; const DH_B = 990011; const DH_Q = 990012; const DH_X = 990013;
$DH_DATE = '2026-10-23';
foreach ( array( 'events', 'history', 'options', 'gl_events_v' ) as $k ) $wpdb->query( "DELETE FROM `{$P}{$k}`" );
foreach ( array( array( DH_A, 2, $DH_DATE ), array( DH_B, 2, $DH_DATE ), array( DH_Q, 3, $DH_DATE ), array( DH_X, 2, '2026-10-24' ) ) as $v ) {
    $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}gl_events_v` (occurrence_id, eff_category_id, event_date, cancelled) VALUES (%d, %d, %s, 0)", $v[0], $v[1], $v[2] ) );
}
$PL = array( 'P1' => 990101, 'P2' => 990102, 'P3' => 990103, 'P4' => 990104 );
add_filter( 'spp_kq_format_population', function ( $uids, $source ) use ( $PL ) { return $source === 'ace' ? array_values( $PL ) : $uids; }, 10, 2 );
foreach ( $PL as $uid ) {
    $GLOBALS['kqe_meta'][ $uid ] = array( 'spp_kq_ace_avg' => 4.0, 'spp_kq_ace_misses' => 0, 'spp_kq_ace_active' => 1 );
}
function dh_history( $occ, array $uids ) {
    global $wpdb, $P, $DH_DATE;
    // One game on Kings: two filler ids so a court has four players.
    $team = array( 'red', 'red', 'black', 'black' );
    $all  = array_slice( array_merge( $uids, array( 990190, 990191, 990192 ) ), 0, 4 );
    foreach ( $all as $i => $uid ) {
        $wpdb->query( $wpdb->prepare( "INSERT INTO `{$P}history` (occurrence_id, round_number, court_name, team_color, user_id, red_score, black_score, event_date, source) VALUES (%d, 1, 'Kings', %s, %d, 11, 7, %s, 'ace')", $occ, $team[ $i ], $uid, $DH_DATE ) );
    }
}
function dh_m( $p, $k ) { global $PL; return $GLOBALS['kqe_meta'][ $PL[ $p ] ][ 'spp_kq_ace_' . $k ] ?? null; }
function dh_state( $occ, $phase, $status ) {
    global $wpdb, $P;
    $wpdb->query( $wpdb->prepare( "REPLACE INTO `{$P}events` (occurrence_id, current_round, phase) VALUES (%d, 2, %s)", $occ, $phase ) );
    if ( $status ) spp_kq_end_state_put( $occ, array( 'status' => $status, 'round' => 2, 'event_date' => '2026-10-23', 'ended_at' => 1, 'reopen_until' => 2, 'log' => array() ) );
}

// Sibling detection
dh_state( DH_A, 'complete', 'finalizing' );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array( DH_B ), 'B not started yet: unfinished (Q other format, X other day ignored)' );
dh_state( DH_B, 'in_play', null );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array( DH_B ), 'B in play: unfinished' );
dh_state( DH_B, 'complete', 'ended' );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array( DH_B ), 'B ended, inside its 60 minutes: unfinished' );
dh_state( DH_B, 'complete', 'finalizing' );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array( DH_B ), 'B finalizing: unfinished' );
dh_state( DH_B, 'complete', 'finalized' );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array(), 'B finalized: finished' );
dh_state( DH_B, 'cancelled', null );
$wpdb->query( "DELETE FROM `{$P}options`" );
kqe_ok( spp_kq_get_unfinished_same_day_siblings( DH_A, 'ace', $DH_DATE ) === array(), 'B cancelled: finished' );

// Step 1: A finalizes ~60 min after its end, while B has not started.
$wpdb->query( "DELETE FROM `{$P}events`" ); $wpdb->query( "DELETE FROM `{$P}options`" );
dh_state( DH_A, 'complete', 'finalizing' );
dh_history( DH_A, array( $PL['P1'], $PL['P4'] ) );
$r = spp_kq_update_format_rankings( DH_A, $DH_DATE );
kqe_ok( $r['updated'] === true && (float) dh_m( 'P1', 'avg' ) !== 4.0 && (float) dh_m( 'P4', 'avg' ) !== 4.0, "A's pass: A's players' averages updated" );
kqe_ok( (int) dh_m( 'P2', 'misses' ) === 0 && (float) dh_m( 'P2', 'avg' ) === 4.0 && dh_m( 'P2', 'last_miss_date' ) === null, "A's pass: walk-in P2 (plays B later) not charged, average untouched" );
kqe_ok( (int) dh_m( 'P3', 'misses' ) === 0 && (float) dh_m( 'P3', 'avg' ) === 4.0, "A's pass: P3 not charged yet (B unfinished)" );
$p1_after_a = (float) dh_m( 'P1', 'avg' );
$p4_after_a = (float) dh_m( 'P4', 'avg' );
spp_kq_end_state_put( DH_A, array( 'status' => 'finalized', 'round' => 2, 'event_date' => $DH_DATE, 'ended_at' => 1, 'reopen_until' => 2, 'log' => array() ) );

// Step 2: B is played (P2 walk-in, P4), ends and finalizes.
dh_state( DH_B, 'complete', 'finalizing' );
dh_history( DH_B, array( $PL['P2'], $PL['P4'] ) );
$r = spp_kq_update_format_rankings( DH_B, $DH_DATE );
kqe_ok( (int) dh_m( 'P3', 'misses' ) === 1 && (float) dh_m( 'P3', 'avg' ) < 4.0 && dh_m( 'P3', 'last_miss_date' ) === $DH_DATE, "B's pass (last of the day): P3 charged exactly one miss" );
kqe_ok( (int) dh_m( 'P1', 'misses' ) === 0 && (float) dh_m( 'P1', 'avg' ) === $p1_after_a, "B's pass: P1 (played A) excused, average not touched again" );
kqe_ok( (int) dh_m( 'P2', 'misses' ) === 0 && (float) dh_m( 'P2', 'avg' ) !== 4.0, "B's pass: walk-in P2 credited for B, no miss" );
kqe_ok( (int) dh_m( 'P4', 'misses' ) === 0 && (float) dh_m( 'P4', 'avg' ) !== $p4_after_a, "B's pass: P4 (both) gets B's update, no miss" );
$p3 = (float) dh_m( 'P3', 'avg' );
spp_kq_apply_format_inactivity( 'ace', array( $PL['P1'] => true, $PL['P2'] => true, $PL['P4'] => true ), $DH_DATE );
kqe_ok( (int) dh_m( 'P3', 'misses' ) === 1 && (float) dh_m( 'P3', 'avg' ) === $p3, 'a second pass the same day charges no second miss' );

// Reverse order: B finalizes while A is still inside its 60 minutes.
foreach ( $PL as $uid ) $GLOBALS['kqe_meta'][ $uid ] = array( 'spp_kq_ace_avg' => 4.0, 'spp_kq_ace_misses' => 0, 'spp_kq_ace_active' => 1 );
$wpdb->query( "DELETE FROM `{$P}events`" ); $wpdb->query( "DELETE FROM `{$P}options`" ); $wpdb->query( "DELETE FROM `{$P}history`" );
dh_state( DH_A, 'complete', 'ended' );
dh_state( DH_B, 'complete', 'finalizing' );
dh_history( DH_B, array( $PL['P2'], $PL['P4'] ) );
spp_kq_update_format_rankings( DH_B, $DH_DATE );
kqe_ok( (int) dh_m( 'P1', 'misses' ) === 0 && (int) dh_m( 'P3', 'misses' ) === 0, 'B first while A still ended (not finalized): nobody charged' );
dh_state( DH_B, 'complete', 'finalized' );
dh_state( DH_A, 'complete', 'finalizing' );
dh_history( DH_A, array( $PL['P1'], $PL['P4'] ) );
spp_kq_update_format_rankings( DH_A, $DH_DATE );
kqe_ok( (int) dh_m( 'P3', 'misses' ) === 1 && (int) dh_m( 'P1', 'misses' ) === 0 && (int) dh_m( 'P2', 'misses' ) === 0 && (int) dh_m( 'P4', 'misses' ) === 0, 'A last: only P3 charged, once' );

// Single session that day: charged by its own pass as before.
foreach ( $PL as $uid ) $GLOBALS['kqe_meta'][ $uid ] = array( 'spp_kq_ace_avg' => 4.0, 'spp_kq_ace_misses' => 0, 'spp_kq_ace_active' => 1 );
$wpdb->query( "DELETE FROM `{$P}events`" ); $wpdb->query( "DELETE FROM `{$P}options`" ); $wpdb->query( "DELETE FROM `{$P}history`" );
$wpdb->query( $wpdb->prepare( "UPDATE `{$P}gl_events_v` SET cancelled = 1 WHERE occurrence_id = %d", DH_B ) );
dh_state( DH_A, 'complete', 'finalizing' );
dh_history( DH_A, array( $PL['P1'], $PL['P4'] ) );
spp_kq_update_format_rankings( DH_A, $DH_DATE );
kqe_ok( (int) dh_m( 'P2', 'misses' ) === 1 && (int) dh_m( 'P3', 'misses' ) === 1 && (int) dh_m( 'P1', 'misses' ) === 0, 'only session that day (B cancelled in GL): absentees charged at once, as before' );

// ── Cleanup + real data untouched ───────────────────────────────────────────
echo "\ncleanup\n";
kqe_cleanup();
kqe_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzkqe\\_t\\_%'" ) === 0, 'all zzkqe_t_* tables dropped' );
$after = kqe_real_fingerprint();
foreach ( $before as $k => $v ) kqe_ok( $after[ $k ] === $v, "real {$k} unchanged" );
kqe_ok( empty( $GLOBALS['kqe_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['kqe_blocked'] ? ': ' . implode( ' | ', $GLOBALS['kqe_blocked'] ) : '' ) );
kqe_ok( count( $GLOBALS['kqe_mail'] ) === 0, 'no other email' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
