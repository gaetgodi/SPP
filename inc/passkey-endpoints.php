<?php
/**
 * SPP Passkey Endpoints
 *
 * WordPress AJAX handlers bridging browser JavaScript to passkey-server.php.
 *
 * Unauthenticated endpoints (login flow):
 *   spp_passkey_auth_options   — return authentication challenge
 *   spp_passkey_verify_auth    — verify assertion, set WP auth cookie
 *
 * Authenticated endpoints (profile/registration flow):
 *   spp_passkey_reg_options    — return registration challenge
 *   spp_passkey_verify_reg     — verify attestation, store credential
 *   spp_passkey_list           — list user's registered passkeys
 *   spp_passkey_delete         — delete a passkey
 *   spp_passkey_rename         — rename a passkey
 *
 * All endpoints:
 *   - Verify nonce before processing
 *   - Return JSON via wp_send_json_success / wp_send_json_error
 *   - Log errors via error_log for server-side debugging
 *
 * Version: 1.0.0
 * Date:    2026-07-07
 */

defined( 'ABSPATH' ) || exit;

// ── Nonce actions ─────────────────────────────────────────────────────────────
define( 'SPP_PASSKEY_NONCE_AUTH', 'spp_passkey_auth' );
define( 'SPP_PASSKEY_NONCE_REG',  'spp_passkey_reg'  );
define( 'SPP_PASSKEY_NONCE_MGT',  'spp_passkey_mgt'  );
// ─────────────────────────────────────────────────────────────────────────────
// ── Bypass UM access restriction for passkey AJAX endpoints ──────────────────
// UM's template_redirect() runs at priority 1000 and redirects all
// unauthenticated admin-ajax.php requests to the login page because
// is_admin() returns false in the AJAX context. We remove UM's hook
// for our specific nopriv passkey actions only.
add_action( 'init', function() {
    if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) return;
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $passkey_actions = [
        'spp_passkey_auth_options',
        'spp_passkey_verify_auth',
    ];
    if ( in_array( $action, $passkey_actions, true ) ) {
        remove_action( 'template_redirect', array( UM()->access(), 'template_redirect' ), 1000 );
    }
}, 1 );
// ─────────────────────────────────────────────────────────────────────────────
// ============================================================================
// UNAUTHENTICATED ENDPOINTS — login flow
// ============================================================================

/**
 * Return authentication challenge options to the browser.
 * Called before the user is logged in.
 *
 * POST params:
 *   nonce    — spp_passkey_auth nonce
 *   username — (optional) WP username or email for non-discoverable flow
 */
add_action( 'wp_ajax_nopriv_spp_passkey_auth_options', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_AUTH, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $username = sanitize_text_field( $_POST['username'] ?? '' );
    $user_id  = 0;

    if ( $username ) {
        $user = get_user_by( 'login', $username )
             ?: get_user_by( 'email', $username );
        if ( $user ) {
            $user_id = $user->ID;
            // Verify user actually has passkeys before issuing challenge
            if ( ! spp_passkey_user_has_passkey( $user_id ) ) {
                wp_send_json_error( [
                    'message' => 'No passkeys registered for this account.',
                    'fallback' => true, // signal JS to show password form
                ] );
            }
        }
        // If user not found, issue a generic challenge anyway (don't leak
        // whether the username exists — timing-safe)
    }

    $options = spp_passkey_authentication_options( $user_id );
    wp_send_json_success( $options );
} );

/**
 * Verify an authentication assertion and log the user in.
 * Called after the browser completes the biometric/PIN prompt.
 *
 * POST params:
 *   nonce      — spp_passkey_auth nonce
 *   response   — JSON-encoded PublicKeyCredential from browser
 *   username   — (optional) used to resolve user_id for non-discoverable flow
 *   remember   — (optional) '1' to set persistent cookie
 */
add_action( 'wp_ajax_nopriv_spp_passkey_verify_auth', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_AUTH, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $response_json = stripslashes( $_POST['response'] ?? '' );
    if ( ! $response_json ) {
        wp_send_json_error( [ 'message' => 'No response data received.' ] );
    }

    $response = json_decode( $response_json, true );
    if ( ! is_array( $response ) ) {
        wp_send_json_error( [ 'message' => 'Invalid response format.' ] );
    }

    $username = sanitize_text_field( $_POST['username'] ?? '' );
    $remember = ( $_POST['remember'] ?? '' ) === '1';
    $user_id  = 0;

    if ( $username ) {
        $user = get_user_by( 'login', $username )
             ?: get_user_by( 'email', $username );
        if ( $user ) {
            $user_id = $user->ID;
        }
    }

    $result = spp_passkey_verify_authentication( $response, $user_id );

    if ( ! $result['success'] ) {
        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    $resolved_user_id = $result['user_id'];
    $user             = get_user_by( 'ID', $resolved_user_id );

    if ( ! $user ) {
        wp_send_json_error( [ 'message' => 'User not found.' ] );
    }

    // Log the user in
    wp_set_current_user( $resolved_user_id );
    wp_set_auth_cookie( $resolved_user_id, $remember );
    do_action( 'wp_login', $user->user_login, $user );

    // Return redirect URL — same logic as WP login
    $redirect_to = apply_filters( 'login_redirect', admin_url(), '', $user );
    if ( ! current_user_can( 'manage_options' ) ) {
        $redirect_to = home_url();
    }

    wp_send_json_success( [
        'message'     => 'Authenticated successfully.',
        'redirect_to' => $redirect_to,
    ] );
} );

// ============================================================================
// AUTHENTICATED ENDPOINTS — registration and management
// ============================================================================

/**
 * Return registration challenge options to the browser.
 * User must be logged in.
 *
 * POST params:
 *   nonce — spp_passkey_reg nonce
 */
add_action( 'wp_ajax_spp_passkey_reg_options', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_REG, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $user    = wp_get_current_user();
    $options = spp_passkey_registration_options( $user );
    wp_send_json_success( $options );
} );

/**
 * Verify a registration attestation and store the new passkey.
 * User must be logged in.
 *
 * POST params:
 *   nonce       — spp_passkey_reg nonce
 *   response    — JSON-encoded PublicKeyCredential from browser
 *   device_name — (optional) user-provided label for this passkey
 */
add_action( 'wp_ajax_spp_passkey_verify_reg', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_REG, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $response_json = stripslashes( $_POST['response'] ?? '' );
    if ( ! $response_json ) {
        wp_send_json_error( [ 'message' => 'No response data received.' ] );
    }

    $response    = json_decode( $response_json, true );
    if ( ! is_array( $response ) ) {
        wp_send_json_error( [ 'message' => 'Invalid response format.' ] );
    }

    $device_name = sanitize_text_field( $_POST['device_name'] ?? '' );
    $user_id     = get_current_user_id();

    $result = spp_passkey_verify_registration( $user_id, $response, $device_name );

    if ( $result['success'] ) {
        // Return updated credential list so the UI can refresh
        $credentials = spp_passkey_get_user_credentials( $user_id );
        wp_send_json_success( [
            'message'     => $result['message'],
            'credentials' => spp_passkey_format_credentials( $credentials ),
        ] );
    } else {
        wp_send_json_error( [ 'message' => $result['message'] ] );
    }
} );

/**
 * List all passkeys for the current user.
 *
 * POST params:
 *   nonce — spp_passkey_mgt nonce
 */
add_action( 'wp_ajax_spp_passkey_list', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_MGT, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $user_id     = get_current_user_id();
    $credentials = spp_passkey_get_user_credentials( $user_id );
    wp_send_json_success( [
        'credentials' => spp_passkey_format_credentials( $credentials ),
    ] );
} );

/**
 * Delete a passkey by its DB row ID.
 * Verifies ownership before deleting.
 * Prevents deletion of the last passkey (user would be locked out).
 *
 * POST params:
 *   nonce         — spp_passkey_mgt nonce
 *   credential_id — DB row ID (integer) of the credential to delete
 */
add_action( 'wp_ajax_spp_passkey_delete', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_MGT, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $credential_db_id = intval( $_POST['credential_id'] ?? 0 );
    if ( ! $credential_db_id ) {
        wp_send_json_error( [ 'message' => 'Invalid credential ID.' ] );
    }

    $user_id     = get_current_user_id();
    $credentials = spp_passkey_get_user_credentials( $user_id );

    // Prevent deleting the last passkey
    if ( count( $credentials ) <= 1 ) {
        wp_send_json_error( [
            'message' => 'Cannot delete your only passkey. Add another passkey first.',
        ] );
    }

    $deleted = spp_passkey_delete_credential( $credential_db_id, $user_id );

    if ( $deleted ) {
        $updated_credentials = spp_passkey_get_user_credentials( $user_id );
        wp_send_json_success( [
            'message'     => 'Passkey deleted.',
            'credentials' => spp_passkey_format_credentials( $updated_credentials ),
        ] );
    } else {
        wp_send_json_error( [ 'message' => 'Failed to delete passkey.' ] );
    }
} );

/**
 * Rename a passkey.
 *
 * POST params:
 *   nonce         — spp_passkey_mgt nonce
 *   credential_id — DB row ID of the credential to rename
 *   device_name   — new name (max 100 chars)
 */
add_action( 'wp_ajax_spp_passkey_rename', function() {
    if ( ! check_ajax_referer( SPP_PASSKEY_NONCE_MGT, 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
    }

    $credential_db_id = intval( $_POST['credential_id'] ?? 0 );
    $device_name      = sanitize_text_field( $_POST['device_name'] ?? '' );

    if ( ! $credential_db_id ) {
        wp_send_json_error( [ 'message' => 'Invalid credential ID.' ] );
    }
    if ( ! $device_name ) {
        wp_send_json_error( [ 'message' => 'Device name cannot be empty.' ] );
    }

    $user_id = get_current_user_id();
    $renamed = spp_passkey_rename_credential( $credential_db_id, $user_id, $device_name );

    if ( $renamed ) {
        $updated_credentials = spp_passkey_get_user_credentials( $user_id );
        wp_send_json_success( [
            'message'     => 'Passkey renamed.',
            'credentials' => spp_passkey_format_credentials( $updated_credentials ),
        ] );
    } else {
        wp_send_json_error( [ 'message' => 'Failed to rename passkey.' ] );
    }
} );

// ============================================================================
// HELPER
// ============================================================================

/**
 * Format credential DB rows for JSON output to the browser.
 * Strips the raw public_key blob — clients only need display fields.
 *
 * @param  array $credentials  Raw DB rows from spp_passkey_get_user_credentials()
 * @return array
 */
function spp_passkey_format_credentials( array $credentials ): array {
    return array_map( function( $row ) {
        return [
            'id'          => (int) $row->id,
            'device_name' => $row->device_name ?: 'Unnamed device',
            'created_at'  => wp_date( 'F j, Y', strtotime( $row->created_at ) ),
            'last_used'   => $row->last_used
                             ? wp_date( 'F j, Y g:i a', strtotime( $row->last_used ) )
                             : 'Never',
            'sign_count'  => (int) $row->sign_count,
        ];
    }, $credentials );
}
