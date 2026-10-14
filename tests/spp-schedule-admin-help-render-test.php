<?php
// Command line only: never run from a web request.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Schedule Admin help text (spp-schedule-admin.php 1.5.0): render test.
 *
 * Needs no WordPress and no database: WordPress functions and $wpdb are
 * stubbed with fixed fixtures, and nothing is ever submitted or written.
 * It renders the page from the working copy and from `git show 7cd527a:`
 * of the same file (the last version before the help text, fixed so the
 * check still passes once the help text is committed) in separate
 * processes, then checks:
 *   - the page renders for a permitted user and not for others
 *   - the help panel is present, collapsed, and absent for others
 *   - every form field name, hidden value and nonce action is identical
 *     between the two versions (the help text must not touch forms)
 *   - the only removed markup is the walk-in heading
 *
 * Run: php tests/spp-schedule-admin-help-render-test.php
 */

if ( ( $argv[1] ?? '' ) === '--render' ) {
    // Child process: render $argv[2] (a file) as $argv[3] ('allowed'|'denied').
    define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
    $GLOBALS['allowed'] = $argv[3] === 'allowed';
    function add_shortcode() {}
    function spp_is_admin_or_editor() { return $GLOBALS['allowed']; }
    function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
    function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
    function esc_js( $s ) { return addslashes( (string) $s ); }
    function esc_url( $s ) { return htmlspecialchars( (string) $s ); }
    function wp_nonce_field( $a, $n ) { echo "<input type=\"hidden\" name=\"$n\" value=\"NONCE[$a]\">"; }
    function wp_verify_nonce() { return false; }
    function sanitize_text_field( $s ) { return $s; }
    function get_option( $k, $d = false ) { return $k === 'spp_current_event' ? 169 : $d; }
    function current_time( $f ) { return '2026-10-13'; }
    function spp_cr_current_event() { return array( 'event' => 169 ); }
    function spp_nc_groups_for_event( $e ) { return array( 2 ); }
    $_SERVER['REQUEST_URI'] = '/schedule-admin/';
    class Stub_DB {
        public $prefix = 'wp_';
        function prepare( $q, ...$a ) { return $q; }
        function get_row( $q ) { return array( 'title' => 'Ladder', 'event_date' => '2026-10-13', 'event_time' => '17:00:00', 'convenor_email' => 'c@example.com' ); }
        function get_var( $q ) { return null; }
        function get_col( $q ) { return array(); }
        function get_results( $q, $o = null ) {
            if ( strpos( $q, 'FROM Schedules s' ) !== false ) {
                $rows = array();
                foreach ( array( 1 => array( 11, 12, 13, 14 ), 2 => array( 21, 22, 23, 24, 25 ) ) as $g => $us ) {
                    foreach ( $us as $u ) {
                        $rows[] = array( 'user_id' => $u, 'first_name' => "P$u", 'last_name' => 'T', 'Rank' => $u, 'group_id' => $g,
                            'Crt_ID' => $g, 'time_id' => 1, 'GP_name' => "Group $g", 'Crt_name' => "Court $g", 'T_desc' => '5:00', 'user_phone' => '555' );
                    }
                }
                return $rows;
            }
            if ( strpos( $q, 'FROM membership' ) !== false ) {
                return array( array( 'user_id' => 901, 'first_name' => 'Walk', 'last_name' => 'In', 'user_email' => 'w@example.com' ) );
            }
            return array();
        }
    }
    $wpdb = new Stub_DB();
    eval( '?>' . file_get_contents( $argv[2] ) );
    echo spp_schedule_admin_shortcode();
    exit;
}

$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spp_sa_help_' . getmypid();
mkdir( $tmp );
// Fixed base: the merge just before the help text (7775703). Not HEAD, which
// already contains the help text once it is committed.
$base = '7cd527a';
file_put_contents( "$tmp/old.php", shell_exec( 'git -C ' . escapeshellarg( $root ) . " show $base:inc/spp-schedule-admin.php" ) );
$new = "$root/inc/spp-schedule-admin.php";

function render( $file, $mode ) {
    return shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --render ' . escapeshellarg( $file ) . ' ' . $mode . ' 2>&1' );
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

$old_ok  = render( "$tmp/old.php", 'allowed' );
$new_ok  = render( $new, 'allowed' );
$new_no  = render( $new, 'denied' );

echo "Renders for a permitted user (admin or editor)\n";
ok( strpos( $new_ok, 'Schedule Admin &mdash;' ) !== false && strpos( $new_ok, 'Fatal' ) === false && strpos( $new_ok, 'Warning' ) === false, 'page renders without errors' );
ok( strpos( $new_ok, '<details class="sa-help">' ) !== false && strpos( $new_ok, '<details class="sa-help" open' ) === false, 'help panel present and collapsed' );
ok( strpos( $new_ok, 'How to use this page' ) !== false, 'panel has its title' );
foreach ( array( 'NP / NS', 'Mark NC / Clear NC', 'Suggested Replacement', 'Print Replacement Score Sheets / Clear List', 'Add a Walk-in Player' ) as $c ) {
    ok( strpos( $new_ok, "<strong>$c</strong>" ) !== false, "panel covers: $c" );
}
ok( strpos( $new_ok, '<h3>Add a Walk-in Player</h3>' ) !== false, 'walk-in has its heading' );
ok( strpos( $new_ok, 'up to the convenor' ) !== false && strpos( $new_ok, 'print replacement score sheets' ) !== false && strpos( $new_ok, 'mark the group NC instead' ) !== false, 'walk-in note: capacity, printing, NC' );
ok( substr_count( $new_ok, 'class="sa-note"' ) >= 3, 'always-visible notes present (' . substr_count( $new_ok, 'class="sa-note"' ) . ')' );
ok( strpos( $new_ok, 'Clear NC' ) !== false && strpos( $new_ok, 'Mark NC' ) !== false, 'NC group shows Clear NC, other group Mark NC' );

echo "Nothing extra for anyone else\n";
ok( strpos( $new_no, 'do not have permission' ) !== false, 'denied user gets the permission message' );
ok( strpos( $new_no, 'sa-help' ) === false && strpos( $new_no, 'sa-note' ) === false, 'denied user sees no help text' );

echo "Forms untouched\n";
preg_match_all( '/<(?:input|select|button|form)\b[^>]*>/i', $old_ok, $a );
preg_match_all( '/<(?:input|select|button|form)\b[^>]*>/i', $new_ok, $b );
ok( count( $a[0] ) > 20 && $a[0] === $b[0], 'every form, input, select and button tag identical (' . count( $a[0] ) . ' tags), incl. names and nonce fields' );
ok( substr_count( $old_ok, 'NONCE[spp_schedule_admin]' ) === substr_count( $new_ok, 'NONCE[spp_schedule_admin]' ), 'same number of nonce fields' );

echo "Only additions\n";
$strip = function ( $h ) { $h = preg_replace( '/<details class="sa-help">.*?<\/details>/s', '', $h ); $h = preg_replace( '/<p class="sa-note">.*?<\/p>/s', '', $h ); $h = preg_replace( '/\.sa-help[^\n]*\n|\.sa-note[^\n]*\n|\.sa-group > \.sa-note[^\n]*\n/', '', $h ); return preg_replace( '/\s+/', ' ', str_replace( 'Add a Walk-in Player', 'Add Walk-in Player', $h ) ); };
ok( $strip( $new_ok ) === preg_replace( '/\s+/', ' ', $old_ok ), 'after removing the help markup (and the heading rename) the page is byte-identical to the previous version' );

array_map( 'unlink', glob( "$tmp/*" ) ); rmdir( $tmp );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
