<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Schedule Adjust -- "Move group to another time slot" tests.
 *
 * 2026-10-18: self-contained. The fixture no longer copies this week's live
 * Schedules (which made 9 checks depend on the live layout and publication
 * state): it is a fixed synthetic layout of real player ids from the
 * permanent Schedules_Scores_169 (so membership has their emails, read-only)
 * under a fake event 990169, with spp_current_event / spp_schedule_published
 * answered in memory. Code paths that read the table "Schedules" by name
 * (notify, the page) are pointed at the throwaway copy by an in-memory query
 * rewrite. A write firewall refuses every write except zzsamg_test_* tables
 * and the throwaway queue option.
 *
 * Every write goes to throwaway zzsamg_test_* tables (the synthetic
 * fixture below) and a throwaway queue option via the
 * spp_sa2_pending_notify_option filter. wp_mail() is replaced by a capture
 * stub defined BEFORE WordPress loads (so FluentSMTP's pluggable wp_mail is
 * never defined) -- the test aborts if the stub is not the one in use.
 * The live Schedules table is neither used nor written; its checksum, the
 * real queue option and the list of real backup tables are compared at the
 * end.
 *
 * Run: php tests/spp-schedule-adjust-movegroup-test.php
 */

$GLOBALS['mg_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['mg_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $message );
    return true;
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
add_filter( 'pre_wp_mail', function () { return true; } ); // second guard, never reached by the stub
if ( ! function_exists( 'spp_sa2_movegroup_state' ) ) { echo "ABORT: movegroup code not loaded\n"; exit( 1 ); }

global $wpdb;
$T = 'zzsamg_test_Schedules';
$OPT = 'zzsamg_test_pending_notify';
const MG_EVENT = 990169;
add_filter( 'spp_sa2_pending_notify_option', function () use ( $OPT ) { return $OPT; } );

// In-memory state the feature reads (never written): a fixed fake event, published.
add_filter( 'pre_option_spp_current_event', fn() => MG_EVENT );
add_filter( 'pre_option_spp_schedule_published', fn() => 1 );

// Reads of the live table "Schedules" go to the throwaway copy while $MG_REDIRECT is on.
$GLOBALS['MG_REDIRECT'] = false;
add_filter( 'query', function ( $q ) {
    if ( ! $GLOBALS['MG_REDIRECT'] ) return $q;
    return preg_replace( '/(?<![A-Za-z0-9_\\\\])`?Schedules`?(?![A-Za-z0-9_\\\\])/', '`zzsamg_test_Schedules`', $q );
}, 5 );

// Write firewall: only zzsamg_test_* tables and the throwaway option.
$GLOBALS['mg_blocked'] = array();
add_filter( 'query', function ( $q ) use ( $OPT ) {
    global $wpdb;
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    $t = '?';
    if ( preg_match( '/^\s*RENAME\s+TABLE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    elseif ( preg_match( '/^\s*TRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?/i', $q, $m ) ) $t = $m[1];
    if ( strpos( $t, 'zzsamg_test_' ) === 0 ) return $q;
    if ( $t === $wpdb->options && strpos( $q, $OPT ) !== false ) return $q;
    $GLOBALS['mg_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
    return "SELECT 'mg_test_blocked_write'";
}, 10 );
$pass = 0; $fail = 0;
function mg_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

function mg_cleanup() {
    global $wpdb, $OPT;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzsamg\\_test\\_%'" ) as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
    }
    delete_option( $OPT );
}
function mg_real_backups() {
    global $wpdb;
    return $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'Schedules\\_backup\\_%' ORDER BY table_name" );
}
/**
 * Fixed layout, independent of the live week (time_id/Crt_ID):
 *   5:30 pm  -- group 1 Court 1, group 2 Court 2
 *   6:40 pm  -- group 3 Court 1, group 4 Court 2, group 5 Court 3 (Court 4 free)
 *   7:50 pm  -- group 6 Court 1, alone in the last slot (5 players)
 *   group 99 -- one not-playing row
 * Players: the first 25 (by user_id) of the permanent Schedules_Scores_169,
 * scores cleared, event_id = MG_EVENT.
 */
function mg_fixture() {
    global $wpdb, $T;
    $redirect = $GLOBALS['MG_REDIRECT'];
    $GLOBALS['MG_REDIRECT'] = false;
    mg_cleanup();
    $wpdb->query( "CREATE TABLE `$T` LIKE Schedules" ); // keeps the generated Score column
    $layout = array( array( 1, 1, 1, 4 ), array( 2, 1, 2, 4 ), array( 3, 2, 1, 4 ), array( 4, 2, 2, 4 ), array( 5, 2, 3, 4 ), array( 6, 3, 1, 5 ) ); // group, time, court, players
    $players = $wpdb->get_results( "SELECT Sequence, registration_date, Rank, user_id, first_name, last_name, user_phone, user_email, travel, RankPrime FROM Schedules_Scores_169 WHERE group_id != 99 ORDER BY user_id LIMIT 25", ARRAY_A );
    $i = 0;
    foreach ( $layout as $l ) {
        for ( $k = 0; $k < $l[3]; $k++ ) {
            $wpdb->insert( $T, $players[ $i++ ] + array( 'event_id' => MG_EVENT, 'group_id' => $l[0], 'time_id' => $l[1], 'Crt_ID' => $l[2] ) );
        }
    }
    $wpdb->insert( $T, array( 'user_id' => 999999901, 'first_name' => 'Zz', 'last_name' => 'Notplaying', 'event_id' => MG_EVENT, 'group_id' => 99, 'Crt_ID' => 99, 'time_id' => 99 ) );
    $GLOBALS['MG_REDIRECT'] = $redirect;
}
function mg_rows() {
    global $wpdb, $T;
    return $wpdb->get_results( "SELECT user_id, first_name, last_name, Rank, event_id, group_id, Crt_ID, time_id, Game1 FROM `$T` ORDER BY user_id", OBJECT_K );
}
function mg_collisions() {
    global $wpdb, $T;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT time_id, Crt_ID FROM `$T` WHERE group_id != 99 GROUP BY time_id, Crt_ID HAVING COUNT(DISTINCT group_id) > 1) x" );
}

$E = MG_EVENT;
$REAL = array(
    'sched'   => $wpdb->get_var( 'CHECKSUM TABLE Schedules', 1 ),
    'queue'   => get_option( 'spp_sa2_pending_notify', 'absent' ),
    'backups' => mg_real_backups(),
    'count'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM Schedules' ),
);
echo "Fake event $E; live Schedules rows (left alone): {$REAL['count']}\n";

// Fixture groups are looked up, not assumed: the one group alone in the last slot, and a first-slot group.
mg_fixture();
mg_ok( count( mg_rows() ) === 26, 'fixture: 25 players in 6 groups + 1 not-playing row, built from Schedules_Scores_169' );
$GLOBALS['MG_REDIRECT'] = true;
$last_slot = (int) $wpdb->get_var( "SELECT MAX(time_id) FROM `$T` WHERE group_id != 99" );
$G  = (int) $wpdb->get_var( "SELECT MIN(group_id) FROM `$T` WHERE time_id = $last_slot AND group_id != 99" );
$G1 = (int) $wpdb->get_var( "SELECT MIN(group_id) FROM `$T` WHERE time_id = 1 AND group_id != 99" );
$G_from = $wpdb->get_row( "SELECT time_id, Crt_ID FROM `$T` WHERE group_id = $G LIMIT 1", ARRAY_A );
$G_uids = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM `$T` WHERE group_id = $G ORDER BY user_id" ) );
echo "Moving group $G (time {$G_from['time_id']}, court {$G_from['Crt_ID']}) to slot 2; other test group $G1\n";

echo "\n[group list / group 99]\n";
// (the group 99 row is part of the fixture)
$ids = array_map( 'intval', array_column( spp_sa2_movegroup_groups( $E, $T ), 'group_id' ) );
mg_ok( ! in_array( 99, $ids, true ), 'group 99 not offered in the group list' );
mg_ok( count( $ids ) === (int) $wpdb->get_var( "SELECT COUNT(DISTINCT group_id) FROM `$T` WHERE group_id != 99" ), 'every other group offered (' . count( $ids ) . ')' );
$r = spp_sa2_movegroup_state( 99, 2, $T, $E );
mg_ok( isset( $r['error'] ), 'group 99 refused: ' . ( $r['error'] ?? '' ) );

echo "\n[refusals]\n";
mg_fixture();
$before = mg_rows();
$r = spp_sa2_movegroup_state( 4242, 2, $T, $E );
mg_ok( isset( $r['error'] ) && strpos( $r['error'], 'not in the schedule' ) !== false, 'non-existent group refused: ' . ( $r['error'] ?? '' ) );
$r = spp_sa2_movegroup_state( $G, 2, $T, $E + 1000 );
mg_ok( isset( $r['error'] ) && strpos( $r['error'], 'not in the schedule' ) !== false, 'group not in the current event refused' );
$r = spp_sa2_movegroup_state( $G, $G_from['time_id'], $T, $E );
mg_ok( isset( $r['error'] ) && strpos( $r['error'], 'already in' ) !== false, 'same slot refused: ' . strip_tags( $r['error'] ?? '' ) );
$r = spp_sa2_movegroup_state( $G, 99, $T, $E );
mg_ok( isset( $r['error'] ), 'inactive/unknown target slot refused' );
$r = spp_sa2_movegroup_move_rows( $G, $G_from['time_id'], 4, $T, $E );
mg_ok( isset( $r['error'] ) && mg_rows() == $before, 'move_rows refuses same slot and writes nothing' );

$one = (int) $wpdb->get_var( "SELECT user_id FROM `$T` WHERE group_id = $G1 LIMIT 1" );
$wpdb->query( "UPDATE `$T` SET Crt_ID = 4 WHERE user_id = $one" );
$r = spp_sa2_movegroup_state( $G1, 2, $T, $E );
mg_ok( isset( $r['error'] ) && strpos( $r['error'], 'split' ) !== false, 'group split across courts refused' );

mg_fixture();
// Fill slot 2's last free court with the first-slot group, then try to move G there.
$wpdb->query( "UPDATE `$T` SET time_id = 2, Crt_ID = 4 WHERE group_id = $G1" );
$before = mg_rows();
$r = spp_sa2_movegroup_state( $G, 2, $T, $E );
mg_ok( isset( $r['error'] ) && strpos( $r['error'], 'No active court is free' ) !== false && strpos( $r['error'], 'Court 1, Court 2, Court 3, Court 4' ) !== false,
       'no free court refused, naming taken courts: ' . ( $r['error'] ?? '' ) );
$r = spp_sa2_movegroup_move_rows( $G, 2, 4, $T, $E );
mg_ok( isset( $r['error'] ) && mg_rows() == $before, 'move_rows refuses when no court is free and writes nothing' );

echo "\n[successful move]\n";
mg_fixture();
$before = mg_rows();
$state = spp_sa2_movegroup_state( $G, 2, $T, $E );
mg_ok( ! isset( $state['error'] ) && array_keys( $state['free'] ) === array( 4 ), 'slot 2 offers only the free court: ' . implode( ',', $state['free'] ?? array() ) );
$r = spp_sa2_movegroup_move_rows( $G, 2, 1, $T, $E );
mg_ok( isset( $r['error'] ) && mg_rows() == $before, 'an occupied court is refused and nothing is written' );

$backup = spp_sa2_backup_schedules( 'movegroup', $T );
mg_ok( strpos( $backup, $T . '_backup_movegroup_' ) === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$backup`" ) === count( $before ), "backup taken first: $backup" );
$moved = spp_sa2_movegroup_move_rows( $G, 2, 4, $T, $E );
mg_ok( ! isset( $moved['error'] ), 'move applied' );
$after = mg_rows();
mg_ok( array_keys( $after ) === array_keys( $before ), 'same ' . count( $after ) . ' players/rows as before, nothing lost or added' );
$changed = array(); $wrong = 0;
foreach ( $before as $uid => $b ) {
    $a = $after[ $uid ];
    if ( (int) $b->group_id === $G ) {
        if ( (int) $a->time_id !== 2 || (int) $a->Crt_ID !== 4 || (int) $a->group_id !== $G ) $wrong++;
        $b2 = clone $b; $b2->time_id = $a->time_id; $b2->Crt_ID = $a->Crt_ID;
        if ( $b2 != $a ) $wrong++;
        $changed[] = $uid;
    } elseif ( $b != $a ) {
        $wrong++;
    }
}
mg_ok( $wrong === 0 && count( $changed ) === count( $G_uids ), 'only group ' . $G . '\'s ' . count( $changed ) . ' rows changed, only time_id/Crt_ID, group_id kept' );
mg_ok( mg_collisions() === 0, 'no court collision in any slot after the move' );
// The feature only builds the note from a successful move (spp_sa2_movegroup_apply()).
$note = isset( $moved['error'] ) ? '(move failed: no note)' : spp_sa2_movegroup_change_note( $moved );
mg_ok( strpos( $note, 'now play at 6:40 pm on Court 4' ) !== false && strpos( $note, 'originally sent' ) !== false, 'change note: ' . $note );

echo "\n[cancel]\n";
mg_ok( spp_sa2_restore_schedules( 'Schedules_backup_movegroup_20990101_000000', $T ) === false, 'restore refuses a backup that does not belong to this table' );
mg_ok( spp_sa2_restore_schedules( $backup, $T ) === true, 'restore ran' );
mg_ok( mg_rows() == $before, 'cancel put every row back exactly as before' );
mg_ok( mg_collisions() === 0, 'no court collision after cancel' );

echo "\n[notification queue]\n";
spp_sa2_movegroup_move_rows( $G, 2, 4, $T, $E );
$published = (int) get_option( 'spp_schedule_published', 0 );
ob_start();
spp_sa2_finalize_notify_or_queue( array( $G ), $note, 'Move Group to Another Time Slot', array( $G => $G_from ) );
$out = ob_get_clean();
if ( $published ) {
    $q = get_option( $OPT );
    mg_ok( count( $GLOBALS['mg_mail'] ) === 0, 'finalize on a published schedule sent nothing' );
    mg_ok( strpos( $out, 'queued for notification' ) !== false && strpos( $out, 'Send Pending Notifications' ) !== false, 'finalize says it was queued, send with Send Pending Notifications' );
    mg_ok( is_array( $q ) && (int) $q['event'] === $E && $q['groups'] === array( $G ), 'queue holds only group ' . $G );
    mg_ok( $q['moves'] == array( $G => array( 'time_id' => (int) $G_from['time_id'], 'Crt_ID' => (int) $G_from['Crt_ID'] ) ), 'queue remembers the original slot/court' );
    $queued_uids = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM `$T` WHERE group_id IN (" . implode( ',', $q['groups'] ) . ") ORDER BY user_id" ) );
    mg_ok( $queued_uids === $G_uids, 'queued players are exactly the moved group\'s ' . count( $G_uids ) . ' players' );
    spp_sa2_queue_pending_notify( array( $G ), array( $G => array( 'time_id' => 2, 'Crt_ID' => 4 ) ) );
    $q = get_option( $OPT );
    mg_ok( $q['groups'] === array( $G ) && (int) $q['moves'][ $G ]['time_id'] === (int) $G_from['time_id'], 'second move of the same group keeps the first original slot, no duplicate' );
} else {
    mg_ok( false, 'schedule is not published -- queue path not exercised' ); // can't happen: published is answered in memory
}

echo "\n[email content -- stubbed, nothing sent]\n";
// Fresh fixture: group G back in its own slot (7:50 pm, Court 1). "from" is set to slot 2 /
// Court 4 so the note fires as if G had been moved here from there.
mg_fixture();
$GLOBALS['mg_mail'] = array();
$res = spp_sa2_notify( array( $G ), 'test', 'Move Group to Another Time Slot', array( $G => array( 'time_id' => 2, 'Crt_ID' => 4 ) ) );
$live_emails = $wpdb->get_col( $wpdb->prepare( "SELECT m.user_email FROM Schedules s JOIN membership m ON m.user_id = s.user_id WHERE s.group_id = %d AND m.user_email != ''", $G ) );
$to = array_column( $GLOBALS['mg_mail'], 'to' );
$conv = spp_sa2_get_convenor_email();
mg_ok( $res['published'] && (int) $res['sent'] === count( $live_emails ), 'one email per player in the group (' . (int) $res['sent'] . ')' );
mg_ok( count( $to ) === count( $live_emails ) + 1 && ! array_diff( $to, array_merge( $live_emails, array( $conv ) ) ), 'recipients are only that group plus the convenor (' . $conv . ')' );
$player_bodies = array_filter( $GLOBALS['mg_mail'], function ( $m ) use ( $conv ) { return $m['to'] !== $conv; } );
$all_have_note = ! empty( $player_bodies );
foreach ( $player_bodies as $m ) if ( strpos( $m['body'], 'Your time has changed.' ) === false || strpos( $m['body'], 'not 6:40 pm on Court 4 as in the schedule you were originally sent' ) === false ) $all_have_note = false;
mg_ok( $all_have_note, 'every player email states the new time/court and that it changed from the one originally sent' );
$GLOBALS['mg_mail'] = array();
$res = spp_sa2_notify( array( $G ), 'test', 'x', array( $G => array( 'time_id' => (int) $G_from['time_id'], 'Crt_ID' => (int) $G_from['Crt_ID'] ) ) );
mg_ok( strpos( $GLOBALS['mg_mail'][0]['body'] ?? '', 'Your time has changed' ) === false, 'no "time changed" line when the group is back where it was' );
$GLOBALS['mg_mail'] = array();
spp_sa2_notify( array( $G ), 'test', 'x' );
mg_ok( strpos( $GLOBALS['mg_mail'][0]['body'] ?? '', 'Your time has changed' ) === false, 'existing callers (no moves map) unchanged' );
$GLOBALS['mg_mail'] = array();

echo "\n[page render on the fixture -- propose only]\n";
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );
$_POST = array();
$sel = spp_schedule_adjust_shortcode();
mg_ok( strpos( $sel, 'Move group to another time slot' ) !== false, 'selector shows the new action' );
preg_match( '/name="spp_sa_mg_group".*?<\/select>/s', $sel, $mgsel );
mg_ok( ! empty( $mgsel ) && strpos( $mgsel[0], 'value="99"' ) === false, 'group 99 not in the page\'s group list' );
$_POST = array( 'spp_sa_nonce' => wp_create_nonce( 'spp_schedule_adjust' ), 'spp_sa_action' => 'movegroup', 'spp_sa_stage' => 'propose', 'spp_sa_mg_group' => $G, 'spp_sa_mg_time' => 2 );
$_SERVER['REQUEST_URI'] = '/adjust-schedule/';
$prop = spp_schedule_adjust_shortcode();
$roster_names = $wpdb->get_col( "SELECT last_name FROM Schedules WHERE group_id = $G" );
$has_all = true; foreach ( $roster_names as $n ) if ( strpos( $prop, esc_html( $n ) ) === false ) $has_all = false;
mg_ok( strpos( $prop, 'Propose Group Move' ) !== false && $has_all && strpos( $prop, '6:40 pm' ) !== false && strpos( $prop, '7:50 pm' ) !== false, 'propose shows group, players, current and new times' );
mg_ok( (bool) preg_match( '/<option value="4" selected>Court 4<\/option>/', $prop ) && strpos( $prop, 'taken: Court 1, Court 2, Court 3' ) !== false, 'propose preselects Court 4 and names the taken courts' );
$_POST['spp_sa_mg_time'] = (int) $G_from['time_id'];
mg_ok( strpos( spp_schedule_adjust_shortcode(), 'already in the' ) !== false, 'propose on the same slot shows the refusal' );
wp_set_current_user( 0 );
$_POST = array( 'spp_sa_nonce' => 'x', 'spp_sa_action' => 'movegroup', 'spp_sa_stage' => 'apply' );
mg_ok( strpos( spp_schedule_adjust_shortcode(), 'do not have permission' ) !== false, 'logged-out visitor refused' );
$_POST = array();

echo "\n[live data untouched]\n";
$GLOBALS['MG_REDIRECT'] = false;
mg_cleanup();
mg_ok( $wpdb->get_var( 'CHECKSUM TABLE Schedules', 1 ) === $REAL['sched'], 'live Schedules checksum unchanged' );
mg_ok( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM Schedules' ) === $REAL['count'], "live Schedules still {$REAL['count']} rows" );
mg_ok( get_option( 'spp_sa2_pending_notify', 'absent' ) === $REAL['queue'], 'real notification queue unchanged' );
mg_ok( mg_real_backups() === $REAL['backups'], 'no new Schedules_backup_* table' );
mg_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzsamg\\_test\\_%'" ) === 0, 'throwaway tables dropped' );
mg_ok( count( $GLOBALS['mg_mail'] ) === 0, 'no mail left over' );
mg_ok( empty( $GLOBALS['mg_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['mg_blocked'] ? ': ' . implode( ' | ', $GLOBALS['mg_blocked'] ) : '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
