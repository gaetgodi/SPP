<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Create Schedule "changes not sent yet" message (gl-schedule-production.php
 * 2.3.0) and the per-group change labels in the Adjust Schedule queue
 * (spp-schedule-adjust.php 1.2.0).
 *
 * The message builder is pure and is rendered on made-up data. The data
 * gatherer reads real Schedules/Groups/Times/Courts/occurrences but gets its
 * queue from an in-memory pre_option filter. The queue recorder writes only
 * a throwaway option (zzsgpm_test_pending_notify, via the
 * spp_sa2_pending_notify_option filter). A write firewall refuses every
 * other write; wp_mail is a stub. The real queue option is compared before
 * and after.
 *
 * Run (live code):      php tests/spp-schedule-guard-pending-message-test.php
 * Run (other checkout): SPP_TEST_THEME_DIR=/path/to/worktree php tests/spp-schedule-guard-pending-message-test.php
 */

$GLOBALS['sgp_mail'] = 0;
function wp_mail() { $GLOBALS['sgp_mail']++; return true; }

$sgp_theme = getenv( 'SPP_TEST_THEME_DIR' );
if ( $sgp_theme ) {
    $GLOBALS['wp_filter'] = array( 'stylesheet_directory' => array( 10 => array( array( 'function' => fn() => $sgp_theme, 'accepted_args' => 1 ) ) ) );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
if ( ! function_exists( 'spp_schedule_guard_pending_message' ) ) { echo "ABORT: 2.3.0 code not loaded\n"; exit( 1 ); }
echo 'Theme: ' . get_stylesheet_directory() . "\n";

global $wpdb;
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

const OPT = 'zzsgpm_test_pending_notify';
$GLOBALS['sgp_blocked'] = array();
add_filter( 'query', function ( $q ) {
    global $wpdb;
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    if ( strpos( $q, $wpdb->options ) !== false && strpos( $q, OPT ) !== false ) return $q; // the throwaway option only
    $GLOBALS['sgp_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 140 );
    return "SELECT 'sgp_test_blocked_write'";
} );
$real_queue_before = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'spp_sa2_pending_notify'" );

$URL = 'https://example.test/adjust-schedule/';
function plain( $html ) { return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( str_replace( array( '<li>', '</p>' ), array( "\n- ", "\n" ), $html ) ), ENT_QUOTES ) ) ); }

$GROUPS = array(
    2 => array( 'name' => 'Group 02', 'time' => '6:40 pm', 'court' => 'Court 4', 'from_time' => '5:30 pm', 'from_court' => 'Court 2' ),
    3 => array( 'name' => 'Group 03', 'time' => '5:30 pm', 'court' => 'Court 1' ),
    5 => array( 'name' => 'Group 05', 'time' => '7:50 pm', 'court' => 'Court 3' ),
);
$QUEUE = array(
    'event'   => 315,
    'groups'  => array( 2, 3, 5 ),
    'moves'   => array( 2 => array( 'time_id' => 1, 'Crt_ID' => 2 ) ),
    'changes' => array( 2 => array( 'Move Group to Another Time Slot' ), 3 => array( 'Last-Minute Add' ), 5 => array( 'Dropout Adjustment', 'Player Swap' ) ),
);

// ── 1. Past event (date passed) ─────────────────────────────────────────────
echo "\n1. past event\n";
$past = spp_schedule_guard_pending_message( $QUEUE, $GROUPS, array( 'id' => 314, 'title' => 'Ladder', 'date' => '2026-10-12', 'played' => true, 'results_posted' => false ), $URL, 3 );
echo plain( $past ) . "\n";
ok( strpos( $past, "Next week's schedule can't be created yet." ) !== false, 'heading in the same style as the other guard' );
ok( strpos( $past, "Changes were made on Adjust Schedule (such as moving a group, a last-minute add or a drop-out) and the players haven't been told yet." ) !== false, 'says what this is' );
ok( strpos( $past, 'Ladder, Monday, October 12, 2026 (event 314), 3 group(s)' ) !== false, 'names the event and its date' );
ok( strpos( $past, 'Group 02: moved to Court 4 at 6:40 pm (was Court 2 at 5:30 pm).' ) !== false, 'move line' );
ok( strpos( $past, 'Group 03: a player added at the last minute.' ) !== false, 'last-minute add line' );
ok( strpos( $past, 'Group 05: a player dropped out; a player moved to a different group.' ) !== false, 'several changes on one group' );
ok( strpos( $past, 'What to do: choose Discard.' ) !== false && strpos( $past, 'it was on Monday, October 12, 2026' ) !== false && strpos( $past, 'would email players about a game that is over' ) !== false, 'past: advises Discard and says why' );
ok( strpos( $past, 'choose Send' ) === false, 'past: does not advise Send' );

// ── 2. Results already posted ───────────────────────────────────────────────
echo "\n2. results posted\n";
$posted = spp_schedule_guard_pending_message( $QUEUE, $GROUPS, array( 'id' => 315, 'title' => 'Ladder', 'date' => '2026-10-19', 'played' => true, 'results_posted' => true ), $URL, 3 );
ok( strpos( $posted, 'What to do: choose Discard.' ) !== false && strpos( $posted, 'its results are already posted' ) !== false, 'results posted counts as played' );

// ── 3. Current / upcoming event ─────────────────────────────────────────────
echo "\n3. current event\n";
$cur = spp_schedule_guard_pending_message( $QUEUE, $GROUPS, array( 'id' => 315, 'title' => 'Ladder', 'date' => '2026-10-19', 'played' => false, 'results_posted' => false ), $URL, 3 );
echo plain( $cur ) . "\n";
ok( strpos( $cur, "What to do: choose Send.</strong> This event hasn't been played yet, so the players should hear about their changes before the schedule is rebuilt." ) !== false, 'current: advises Send' );
ok( strpos( $cur, 'choose Discard' ) === false && strpos( $cur, 'game that is over' ) === false, 'current: no Discard advice' );

// ── 4. Buttons, link, several groups ────────────────────────────────────────
echo "\n4. buttons and link\n";
foreach ( array( $past, $cur ) as $h ) {
    ok( strpos( $h, '<strong>Send Pending Notifications</strong> emails the players in the groups above.' ) !== false
     && strpos( $h, '<strong>Discard</strong> clears this list without emailing anyone.' ) !== false, 'says what each button does' );
}
ok( strpos( $cur, 'href="' . $URL . '"' ) !== false && strpos( $cur, 'Open Adjust Schedule' ) !== false, 'links to Adjust Schedule' );
ok( substr_count( $cur, '<li>Group ' ) === 3 && strpos( $cur, 'Group 02' ) < strpos( $cur, 'Group 03' ) && strpos( $cur, 'Group 03' ) < strpos( $cur, 'Group 05' ), 'one line per group, in queue order' );
$nolink = spp_schedule_guard_pending_message( $QUEUE, $GROUPS, array( 'id' => 315, 'date' => '2026-10-19', 'played' => false ), '', 3 );
ok( strpos( $nolink, 'page not found -- contact Gaetan' ) !== false && strpos( $nolink, '<a href' ) === false, 'missing page: note instead of a broken link' );
ok( strpos( $cur, '#c0392b' ) === false && strpos( $cur, 'gl-error' ) === false, 'no red' );
ok( strpos( $cur, 'Nothing has been changed.' ) !== false, 'says nothing was changed' );

// ── 5. Unknown and missing data ─────────────────────────────────────────────
echo "\n5. unknown / missing\n";
$q = array( 'groups' => array( 2, 3, 9, 5 ), 'moves' => array(), 'changes' => array( 2 => array( 'Last-Minute Add' ), 3 => array( 'Teleport Group' ) ) );
$mix = spp_schedule_guard_pending_message( $q, $GROUPS, array( 'id' => 315, 'date' => '2026-10-19', 'played' => false ), $URL, 4 );
echo plain( $mix ) . "\n";
ok( strpos( $mix, 'Group 02: a player added at the last minute.' ) !== false, 'known change still described' );
ok( strpos( $mix, 'Group 03: changed in a way this page can&#039;t describe (&quot;Teleport Group&quot;).' ) !== false, 'unknown action type said honestly' );
ok( strpos( $mix, 'Group 09: no longer in the schedule, so there is nothing to describe.' ) !== false, 'group gone from the schedule said honestly' );
ok( strpos( $mix, 'Group 05: changed (the details weren&#039;t recorded).' ) !== false, 'no label (queued before 1.2.0): said honestly' );
$q = array( 'groups' => array( 3, 5 ), 'moves' => array(), 'changes' => array() );
$fb = spp_schedule_guard_pending_message( $q, $GROUPS, array( 'id' => 315, 'date' => '2026-10-19', 'played' => false ), $URL, 2 );
echo plain( $fb ) . "\n";
ok( strpos( $fb, "2 group(s) are waiting. What changed for them wasn't recorded, so it can't be listed here" ) !== false && strpos( $fb, '<li>Group' ) === false, 'nothing describable: one short generic line, no guessing' );
ok( strpos( $fb, 'choose Send' ) !== false, 'advice still given' );
$moved_back = spp_schedule_guard_pending_message( array( 'groups' => array( 2 ), 'moves' => array( 2 => array( 'time_id' => 2, 'Crt_ID' => 4 ) ), 'changes' => array( 2 => array( 'Move Group to Another Time Slot' ) ) ),
    array( 2 => array( 'name' => 'Group 02', 'time' => '6:40 pm', 'court' => 'Court 4', 'from_time' => '6:40 pm', 'from_court' => 'Court 4' ) ), array( 'id' => 315, 'date' => '', 'played' => false ), $URL, 1 );
ok( strpos( $moved_back, 'Group 02: moved, then back to its original Court 4 at 6:40 pm.' ) !== false, 'moved and back again' );
ok( strpos( $moved_back, 'the current event (event 315)' ) !== false, 'no date known: says "the current event"' );

// ── 6. Real data path (read-only, queue injected in memory) ─────────────────
echo "\n6. data gatherer\n";
$real_gids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT group_id FROM Schedules WHERE group_id != 99 ORDER BY group_id LIMIT 2" ) );
$cur_event = (int) get_option( 'spp_current_event', 0 );
if ( count( $real_gids ) === 2 ) {
    $inj = array( 'event' => $cur_event, 'groups' => array( $real_gids[0], $real_gids[1], 999 ),
        'moves' => array( $real_gids[0] => array( 'time_id' => 1, 'Crt_ID' => 1 ) ),
        'changes' => array( $real_gids[0] => array( 'Move Group to Another Time Slot' ), $real_gids[1] => array( 'Last-Minute Add' ) ) );
    add_filter( 'pre_option_spp_sa2_pending_notify', $f = fn() => $inj );
    $args = spp_schedule_guard_pending_data( 3 );
    remove_filter( 'pre_option_spp_sa2_pending_notify', $f );
    $html = spp_schedule_guard_pending_message( ...$args );
    echo plain( $html ) . "\n";
    $gname = $wpdb->get_var( $wpdb->prepare( "SELECT GP_name FROM Groups WHERE GP_ID = %d", $real_gids[1] ) );
    ok( strpos( $html, esc_html( $gname . ': a player added at the last minute.' ) ) !== false, "real group name used ($gname)" );
    ok( isset( $args[1][ $real_gids[0] ]['from_time'] ) && $args[1][ $real_gids[0] ]['from_time'] !== '', 'move: original slot looked up from Times/Courts' );
    ok( strpos( $html, 'Group 999: no longer in the schedule' ) !== false, 'a group id not in Schedules is reported as gone' );
    $occ = $wpdb->get_row( $wpdb->prepare( "SELECT title, event_date FROM {$wpdb->prefix}gl_event_occurrences WHERE id = %d", $cur_event ), ARRAY_A );
    $expect_played = get_option( 'spp_results_posted', 0 ) || substr( $occ['event_date'], 0, 10 ) < wp_date( 'Y-m-d' );
    ok( $args[2]['id'] === $cur_event && $args[2]['date'] === substr( $occ['event_date'], 0, 10 ) && $args[2]['played'] === (bool) $expect_played, "event $cur_event, {$args[2]['date']}, played=" . var_export( $args[2]['played'], true ) );
    ok( $args[3] !== '' && strpos( $args[3], 'adjust-schedule' ) !== false, 'Adjust Schedule page URL found: ' . $args[3] );
} else {
    ok( false, 'Schedules has fewer than 2 groups -- data gatherer not exercised' );
}

// ── 7. The queue now records what changed (throwaway option) ────────────────
echo "\n7. queue records the action label\n";
add_filter( 'spp_sa2_pending_notify_option', $of = fn() => OPT );
delete_option( OPT );
spp_sa2_queue_pending_notify( array( 4, 6 ), array(), 'Last-Minute Add' );
spp_sa2_queue_pending_notify( array( 4 ), array( 4 => array( 'time_id' => 2, 'Crt_ID' => 3 ) ), 'Move Group to Another Time Slot' );
spp_sa2_queue_pending_notify( array( 4 ), array(), 'Last-Minute Add' );
$q = spp_sa2_get_pending_notify_queue();
ok( $q['groups'] === array( 4, 6 ) && $q['changes'] === array( 4 => array( 'Last-Minute Add', 'Move Group to Another Time Slot' ), 6 => array( 'Last-Minute Add' ) ) && isset( $q['moves'][4] ), 'labels kept per group, deduped; groups and moves as before' );
ok( spp_sa2_get_pending_notify_groups() === array( 4, 6 ), 'the guard\'s own check reads the same groups' );
update_option( OPT, array( 'event' => $cur_event, 'groups' => array( 7 ), 'moves' => array() ) ); // a pre-1.2.0 queue
ok( spp_sa2_get_pending_notify_groups() === array( 7 ) && ! isset( spp_sa2_get_pending_notify_queue()['changes'] ), 'an old queue without labels still reads fine' );
spp_sa2_clear_pending_notify();
ok( spp_sa2_get_pending_notify_queue() === null, 'empty after clear' );
remove_filter( 'spp_sa2_pending_notify_option', $of );

// ── 8. Guard condition unchanged ────────────────────────────────────────────
echo "\n8. guard unchanged\n";
$src = file_get_contents( get_stylesheet_directory() . '/inc/gl-schedule-production.php' );
ok( substr_count( $src, "\$pending_check = spp_sa2_get_pending_notify_groups();\n        if ( ! empty( \$pending_check ) ) {" ) === 1, 'still fires on a non-empty queue, same check' );
ok( strpos( $src, 'echo spp_schedule_guard_pending_message( ...spp_schedule_guard_pending_data( count( $pending_check ) ) );' ) !== false && strpos( $src, 'Cannot produce a new schedule --' ) === false, 'old one-line text replaced by the new message' );

echo "\ncleanup\n";
delete_option( OPT );
ok( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'spp_sa2_pending_notify'" ) === $real_queue_before, 'real queue option untouched' );
ok( empty( $GLOBALS['sgp_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['sgp_blocked'] ? ': ' . implode( ' | ', $GLOBALS['sgp_blocked'] ) : '' ) );
ok( $GLOBALS['sgp_mail'] === 0, 'no email' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
