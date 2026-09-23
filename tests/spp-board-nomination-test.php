<?php
// Standalone checks; no WordPress connection and no real email is sent.
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class SppBnResponse extends Exception {
    public function __construct( $success, $data ) { $this->success = $success; $this->data = $data; }
}
function add_action( ...$args ) {}
function add_shortcode( ...$args ) {}
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n]+/', ' ', strip_tags( $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function is_email( $s ) { return filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function check_ajax_referer( ...$args ) { return $GLOBALS['nonce_ok']; }
function wp_unslash( $v ) { return $v; }
function wp_get_current_user() { return (object) array( 'ID' => 12, 'user_login' => 'actual-member', 'user_email' => 'member@example.org' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_transient( $key ) { return $GLOBALS['duplicate']; }
function set_transient( ...$args ) { $GLOBALS['cached'] = true; }
function wp_date( ...$args ) { return 'September 23, 2026 at 10:00:00 am EDT'; }
function wp_mail( ...$args ) { $GLOBALS['mail'][] = $args; return $GLOBALS['mail_ok']; }
function wp_send_json_error( $data, $status = 200 ) { throw new SppBnResponse( false, $data ); }
function wp_send_json_success( $data ) { throw new SppBnResponse( true, $data ); }
require __DIR__ . '/../inc/spp-board-nomination.php';
function spp_bn_test_assert( $ok, $label ) {
    if ( ! $ok ) throw new RuntimeException( $label );
    echo "PASS: $label\n";
}
function spp_bn_test_run() {
    try { spp_bn_submit(); } catch ( SppBnResponse $r ) { return $r; }
    throw new RuntimeException( 'Missing JSON response' );
}
$valid = array( 'first_name' => 'Jane', 'last_name' => "O'Brien", 'mobile' => '555-123-4567', 'home' => '',
    'address' => '10 Main Street', 'town' => 'Stouffville', 'email' => 'jane@example.org',
    'qualifications' => "Board experience\nEvent planning", 'contribution' => 'Help members & organize events.' );
spp_bn_test_assert( ! is_wp_error( spp_bn_validate( $valid ) ), 'Valid nomination with one phone' );
foreach ( array( array( 'first_name', '' ), array( 'email', 'invalid' ), array( 'town', array() ), array( 'contribution', str_repeat( 'x', 10001 ) ), array( 'mobile', '' ) ) as $case ) {
    $input = $valid; $input[ $case[0] ] = $case[1];
    spp_bn_test_assert( is_wp_error( spp_bn_validate( $input ) ), 'Reject invalid ' . $case[0] );
}
$_POST = $valid + array( 'nonce' => 'test', 'signature' => 'forged', 'date' => '1999-01-01' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$logged_in = false; $nonce_ok = true; $duplicate = false; $mail_ok = true; $mail = array(); $cached = false;
spp_bn_test_assert( ! spp_bn_test_run()->success && count( $mail ) === 0, 'Logged-out requests cannot email' );
$logged_in = true; $nonce_ok = false;
spp_bn_test_assert( ! spp_bn_test_run()->success && count( $mail ) === 0, 'Invalid nonce cannot email' );
$nonce_ok = true; $_SERVER['REQUEST_METHOD'] = 'GET';
spp_bn_test_assert( ! spp_bn_test_run()->success && count( $mail ) === 0, 'GET cannot email' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $mail_ok = false;
spp_bn_test_assert( ! spp_bn_test_run()->success && ! $cached, 'Mail failure is reported and permits retry' );
$mail_ok = true;
spp_bn_test_assert( spp_bn_test_run()->success && $cached, 'Mail acceptance succeeds' );
$sent = end( $mail );
spp_bn_test_assert( $sent[0] === 'board@pickleballstouffville.ca', 'Board recipient' );
spp_bn_test_assert( strpos( $sent[2], 'actual-member' ) !== false && strpos( $sent[2], 'forged' ) === false && strpos( $sent[2], '1999-01-01' ) === false, 'Identity and date cannot be forged by POST' );
spp_bn_test_assert( strpos( $sent[2], '&amp;' ) !== false, 'Email content is escaped' );
$count = count( $mail ); $duplicate = true;
spp_bn_test_assert( spp_bn_test_run()->success && count( $mail ) === $count, 'Recent duplicate does not email again' );
