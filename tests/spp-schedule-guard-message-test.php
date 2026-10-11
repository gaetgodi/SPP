<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Create Schedule guard message (gl-schedule-production.php 2.2.2) tests.
 *
 * Writes NOTHING: the two flags are overridden in memory (pre_option_*),
 * the zero-score case rewrites the guard's own COUNT query in memory, and
 * a 'query' filter throws on any statement that isn't SELECT/SHOW, so a
 * guard that failed to stop would abort at its first write. Only the
 * BLOCKING cases are executed -- the guard condition itself is unchanged
 * from 2.2.1 (checked by diff), and a non-blocking case would start a
 * real production run.
 *
 * Run: php tests/spp-schedule-guard-message-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}

global $wpdb;
$pass = 0; $fail = 0;
function sgm_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

class SGM_Write_Blocked extends RuntimeException {}
$WRITES = 0;
add_filter( 'query', function( $q ) {
    global $WRITES;
    if ( preg_match( '/^\s*(SELECT|SHOW)\b/i', $q ) ) return $q;
    $WRITES++;
    throw new SGM_Write_Blocked( 'write blocked by test: ' . substr( trim( $q ), 0, 80 ) );
} );

$FLAGS = array( 'pub' => 1, 'posted' => 0 );
add_filter( 'pre_option_spp_schedule_published', function() use ( &$FLAGS ) { return $FLAGS['pub']; } );
add_filter( 'pre_option_spp_results_posted',     function() use ( &$FLAGS ) { return $FLAGS['posted']; } );
$ZERO_SCORES = false;
add_filter( 'query', function( $q ) use ( &$ZERO_SCORES ) {
    return ( $ZERO_SCORES && strpos( $q, 'COUNT(*) FROM Schedules' ) !== false && strpos( $q, 'Game1 IS NOT NULL OR Game2' ) !== false ) ? 'SELECT 0' : $q;
}, 5 );

function sgm_run( string $fn ) : string {
    $_POST = array(); $_GET = array();
    $GLOBALS['Event'] = 169;
    ob_start();
    try { $fn(); } catch ( SGM_Write_Blocked $e ) { echo "\n[[BLOCKED-WRITE]] " . $e->getMessage(); }
    return ob_get_clean();
}

$event = (int) $wpdb->get_var( "SELECT event_id FROM Schedules WHERE group_id != 99 LIMIT 1" );
$occ   = $wpdb->get_row( $wpdb->prepare( "SELECT event_date FROM {$wpdb->prefix}gl_event_occurrences WHERE id = %d", $event ), ARRAY_A );
$date  = date( 'F j, Y', strtotime( $occ['event_date'] ) );
$adm   = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' LIMIT 1" );
$edi   = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%\"editor\"%' LIMIT 1" );
$sub   = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%subscriber%' AND meta_value NOT LIKE '%editor%' AND meta_value NOT LIKE '%administrator%' LIMIT 1" );
echo "Schedules holds event $event ($date); admin $adm, editor $edi, subscriber $sub\n";

foreach ( array( 'administrator' => $adm, 'editor' => $edi ) as $role => $uid ) {
    echo "1. published=1, results_posted=0, scores entered -- $role\n";
    wp_set_current_user( $uid );
    $html = sgm_run( 'spp_run_schedule_production_locked' );
    sgm_ok( strpos( $html, "Next week's schedule can't be created yet" ) !== false, 'blocked with the new message' );
    sgm_ok( strpos( $html, '[[BLOCKED-WRITE]]' ) === false && $GLOBALS['WRITES'] === 0, 'stopped before any write' );
    sgm_ok( strpos( $html, "(event $event)" ) !== false && strpos( $html, $date ) !== false, "shows event $event and $date" );
    $p1 = strpos( $html, 'create-results-for-override' ); $p2 = strpos( $html, 'gl-publish-results-after-overrides' );
    sgm_ok( $p1 !== false && $p2 !== false && $p1 < $p2, 'normal path: Step 1 Create Results link, then Step 2 Publish Results link' );
    sgm_ok( strpos( $html, 'Never do Step 2 on its own' ) !== false, 'says to do them in that order' );
    sgm_ok( preg_match( '#href="https://pickleballstouffville\.ca/publish-partial-results/\?ppr_event=' . $event . '"#', $html ) === 1, "partial option links to the page with ppr_event=$event" );
    sgm_ok( strpos( $html, 'Nothing changes until you confirm on the next screen' ) !== false, 'says nothing changes until confirmed' );
    if ( $role === 'editor' ) $EDITOR_HTML = $html;
}

echo "2. missing page fallback\n";
wp_set_current_user( $adm );
add_filter( 'spp_ppr_page_slug', $nopage = fn() => 'zz-no-such-page-' . wp_generate_password( 6, false ) );
$html = sgm_run( 'spp_run_schedule_production_locked' );
sgm_ok( strpos( $html, 'page is missing -- please contact Gaetan' ) !== false && strpos( $html, 'ppr_event=' ) === false, 'note instead of a broken link' );
remove_filter( 'spp_ppr_page_slug', $nopage );

echo "3. subscriber / logged-out see no option and no event data\n";
foreach ( array( "subscriber $sub" => $sub, 'logged-out' => 0 ) as $label => $uid ) {
    wp_set_current_user( $uid );
    $html = sgm_run( 'spp_run_schedule_production' );
    sgm_ok( strpos( $html, 'do not have permission' ) !== false && strpos( $html, 'ppr_event' ) === false
         && strpos( $html, "event $event" ) === false && strpos( $html, 'Publish partial' ) === false, "$label: permission message only" );
}
wp_set_current_user( $adm );
$opt = spp_schedule_guard_scores_message( $event, 5 );
wp_set_current_user( $sub );
$sub_opt = spp_schedule_guard_scores_message( $event, 5 );
sgm_ok( strpos( $opt, 'Publish partial results' ) !== false && strpos( $sub_opt, 'Publish partial results' ) === false && strpos( $sub_opt, 'ppr_event' ) === false,
    'message builder itself omits option B for a non-admin/editor' );

echo "4. published=1, results_posted=0, NO scores -- unchanged bypass-confirm path\n";
wp_set_current_user( $adm );
$ZERO_SCORES = true;
$html = sgm_run( 'spp_run_schedule_production_locked' );
$ZERO_SCORES = false;
sgm_ok( strpos( $html, 'though no scores have been entered against it' ) !== false && strpos( $html, 'spp_force_reschedule' ) !== false, 'bypass confirm form shown (unchanged)' );
sgm_ok( strpos( $html, "can't be created yet" ) === false && $GLOBALS['WRITES'] === 0, 'no new message, no write' );

echo "5. guard condition unchanged from main\n";
$theme = dirname( __DIR__ );
$cond  = "if (get_option('spp_schedule_published', 0) && !get_option('spp_results_posted', 0)) {";
$src   = file_get_contents( "$theme/inc/gl-schedule-production.php" );
sgm_ok( substr_count( $src, $cond ) === 1, 'condition line present exactly once, byte-identical' );
$truth = array();
foreach ( array( array( 0, 0 ), array( 0, 1 ), array( 1, 0 ), array( 1, 1 ) ) as $c ) {
    $FLAGS = array( 'pub' => $c[0], 'posted' => $c[1] );
    $truth[] = ( get_option( 'spp_schedule_published', 0 ) && ! get_option( 'spp_results_posted', 0 ) ) ? 'block' : 'pass';
}
sgm_ok( $truth === array( 'pass', 'pass', 'block', 'pass' ), 'condition truth table: blocks only for published=1 + results_posted=0' );

wp_set_current_user( 0 );
echo "\n$pass passed, $fail failed\n";
if ( getenv( 'SGM_DUMP' ) && isset( $EDITOR_HTML ) ) file_put_contents( getenv( 'SGM_DUMP' ), $EDITOR_HTML );
exit( $fail ? 1 : 0 );
