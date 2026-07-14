<?php
/**
 * SPP Passkey Server
 *
 * Core WebAuthn registration and authentication logic using
 * web-auth/webauthn-framework v5.3.x.
 *
 * Handles:
 *   - DB table creation for credential storage
 *   - Challenge generation and storage (WP transients)
 *   - Credential registration (attestation verification)
 *   - Credential authentication (assertion verification)
 *   - Credential management (list, rename, delete)
 *
 * This file is required by functions.php and provides helper functions
 * consumed by passkey-endpoints.php (AJAX handlers) and
 * passkey-login.php (login form integration).
 *
 * Version: 1.0.0
 * Date:    2026-07-07
 */

defined( 'ABSPATH' ) || exit;

// ── Namespaces ────────────────────────────────────────────────────────────────
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Symfony\Component\Serializer\SerializerInterface;
// ─────────────────────────────────────────────────────────────────────────────

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'SPP_PASSKEY_DB_VERSION', '1.0.0' );
define( 'SPP_PASSKEY_TABLE',      $GLOBALS['wpdb']->prefix . 'spp_passkey_credentials' );
define( 'SPP_PASSKEY_RP_ID',      parse_url( home_url(), PHP_URL_HOST ) );
define( 'SPP_PASSKEY_RP_NAME',    get_bloginfo( 'name' ) );
define( 'SPP_PASSKEY_ORIGIN',     home_url() );
define( 'SPP_PASSKEY_TIMEOUT',    60000 ); // 60 seconds
// ─────────────────────────────────────────────────────────────────────────────

// ── DB table creation ─────────────────────────────────────────────────────────
/**
 * Create the passkey credentials table if it doesn't exist or needs updating.
 * Called on theme setup and can be called manually to upgrade.
 */
function spp_passkey_create_table(): void {
    global $wpdb;

    $installed_version = get_option( 'spp_passkey_db_version', '0.0.0' );
    if ( version_compare( $installed_version, SPP_PASSKEY_DB_VERSION, '>=' ) ) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $table           = SPP_PASSKEY_TABLE;

    $sql = "CREATE TABLE {$table} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       BIGINT(20) UNSIGNED NOT NULL,
        credential_id VARCHAR(512)        NOT NULL,
        public_key    LONGTEXT            NOT NULL,
        sign_count    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        device_name   VARCHAR(100)        DEFAULT NULL,
        created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used     DATETIME            DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY   credential_id (credential_id(191)),
        KEY          user_id (user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'spp_passkey_db_version', SPP_PASSKEY_DB_VERSION );
    error_log( 'SPP Passkey: credentials table created/updated to v' . SPP_PASSKEY_DB_VERSION );
}
add_action( 'after_setup_theme', 'spp_passkey_create_table' );
// ─────────────────────────────────────────────────────────────────────────────

// ── Serializer singleton ──────────────────────────────────────────────────────
/**
 * Get the WebAuthn serializer (expensive to construct, cache in static).
 */
function spp_passkey_serializer(): SerializerInterface {
    static $serializer = null;
    if ( $serializer === null ) {
        $attestation_manager = \Webauthn\AttestationStatement\AttestationStatementSupportManager::create();
        $factory             = new WebauthnSerializerFactory( $attestation_manager );
        $serializer          = $factory->create();
    }
    return $serializer;
}
// ─────────────────────────────────────────────────────────────────────────────

// ── CeremonyStepManager factory ───────────────────────────────────────────────
/**
 * Build a CeremonyStepManager configured for this site.
 * Used by both attestation and assertion validators.
 */
function spp_passkey_ceremony_manager(): \Webauthn\CeremonyStep\CeremonyStepManager {
    $factory = new CeremonyStepManagerFactory();
    $factory->setAllowedOrigins( [ SPP_PASSKEY_ORIGIN ] );
    return $factory->creationCeremony();
}

function spp_passkey_assertion_ceremony_manager(): \Webauthn\CeremonyStep\CeremonyStepManager {
    $factory = new CeremonyStepManagerFactory();
    $factory->setAllowedOrigins( [ SPP_PASSKEY_ORIGIN ] );
    return $factory->requestCeremony();
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Challenge helpers ─────────────────────────────────────────────────────────
/**
 * Generate a cryptographically random challenge, store as WP transient,
 * and return it as a base64url-encoded string.
 *
 * @param string $context  'registration' or 'authentication'
 * @param int    $user_id  WP user ID (0 for pre-auth authentication)
 * @return string  base64url-encoded challenge
 */
function spp_passkey_generate_challenge( string $context, int $user_id = 0 ): string {
    $challenge    = random_bytes( 32 );
    $challenge_b64 = spp_passkey_base64url_encode( $challenge );

    $transient_key = 'spp_passkey_challenge_' . $context . '_' . ( $user_id ?: session_id() );
    set_transient( $transient_key, $challenge_b64, 120 ); // 2 minute TTL

    return $challenge_b64;
}

/**
 * Retrieve and consume (delete) a stored challenge.
 * One-time use — deletes on retrieval to prevent replay attacks.
 *
 * @param string $context  'registration' or 'authentication'
 * @param int    $user_id  WP user ID
 * @return string|null  base64url-encoded challenge or null if not found/expired
 */
function spp_passkey_consume_challenge( string $context, int $user_id = 0 ): ?string {
    $transient_key = 'spp_passkey_challenge_' . $context . '_' . ( $user_id ?: session_id() );
    $challenge     = get_transient( $transient_key );
    delete_transient( $transient_key ); // consume immediately
    return $challenge ?: null;
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Registration ──────────────────────────────────────────────────────────────
/**
 * Build PublicKeyCredentialCreationOptions for a user.
 * Sent to the browser to initiate passkey registration.
 *
 * @param  WP_User $user
 * @return array  JSON-serializable options array
 */
function spp_passkey_registration_options( WP_User $user ): array {
    $challenge = spp_passkey_generate_challenge( 'registration', $user->ID );

    $rp   = PublicKeyCredentialRpEntity::create( SPP_PASSKEY_RP_NAME, SPP_PASSKEY_RP_ID );
    $user_entity = PublicKeyCredentialUserEntity::create(
        $user->user_login,
        spp_passkey_base64url_encode( (string) $user->ID ),
        $user->display_name
    );

    // Supported algorithms: ES256 (-7) and RS256 (-257)
    $params = [
        PublicKeyCredentialParameters::create( 'public-key', -7   ),  // ES256
        PublicKeyCredentialParameters::create( 'public-key', -257 ),  // RS256
    ];

    // Exclude already-registered credentials to prevent duplicates
    $existing     = spp_passkey_get_user_credentials( $user->ID );
    $exclude_list = array_map( function( $row ) {
        return PublicKeyCredentialDescriptor::create(
            'public-key',
            spp_passkey_base64url_decode( $row->credential_id )
        );
    }, $existing );

    $options = PublicKeyCredentialCreationOptions::create(
        $rp,
        $user_entity,
        spp_passkey_base64url_decode( $challenge ),
        $params,
    );

    // Serialize to array for JSON response
    $serializer = spp_passkey_serializer();
    $json       = $serializer->serialize( $options, 'json' );
    $arr        = json_decode( $json, true );

    // Add exclude credentials and timeout (not always in serialized output)
    if ( ! empty( $exclude_list ) ) {
        $arr['excludeCredentials'] = array_map( function( $desc ) use ( $serializer ) {
            return json_decode( $serializer->serialize( $desc, 'json' ), true );
        }, $exclude_list );
    }
    $arr['timeout']                       = SPP_PASSKEY_TIMEOUT;
    $arr['authenticatorSelection']        = [
        'residentKey'        => 'preferred',
        'userVerification'   => 'preferred',
    ];

    return $arr;
}

/**
 * Verify a registration response from the browser and store the credential.
 *
 * @param  int    $user_id
 * @param  array  $response  Decoded JSON from the browser
 * @param  string $device_name  Optional user-provided name for the device
 * @return array  ['success' => bool, 'message' => string]
 */
function spp_passkey_verify_registration( int $user_id, array $response, string $device_name = '' ): array {
    global $wpdb;

    try {
        $challenge_b64 = spp_passkey_consume_challenge( 'registration', $user_id );
        if ( ! $challenge_b64 ) {
            return [ 'success' => false, 'message' => 'Challenge expired or not found. Please try again.' ];
        }

        $serializer = spp_passkey_serializer();

        // Deserialize the PublicKeyCredential from the browser response
        $public_key_credential = $serializer->deserialize(
            json_encode( $response ),
            PublicKeyCredential::class,
            'json'
        );

        if ( ! $public_key_credential->response instanceof \Webauthn\AuthenticatorAttestationResponse ) {
            return [ 'success' => false, 'message' => 'Invalid response type.' ];
        }

        // Rebuild the creation options with the stored challenge for verification
        $user = get_user_by( 'ID', $user_id );
        $rp   = PublicKeyCredentialRpEntity::create( SPP_PASSKEY_RP_NAME, SPP_PASSKEY_RP_ID );
        $user_entity = PublicKeyCredentialUserEntity::create(
            $user->user_login,
            spp_passkey_base64url_encode( (string) $user_id ),
            $user->display_name
        );
        $params = [
            PublicKeyCredentialParameters::create( 'public-key', -7   ),
            PublicKeyCredentialParameters::create( 'public-key', -257 ),
        ];
        $options = PublicKeyCredentialCreationOptions::create(
            $rp,
            $user_entity,
            spp_passkey_base64url_decode( $challenge_b64 ),
            $params,
        );

        // Verify the attestation
        $manager         = spp_passkey_ceremony_manager();
        $validator       = AuthenticatorAttestationResponseValidator::create( $manager );
        $credential_record = $validator->check(
            $public_key_credential->response,
            $options,
            SPP_PASSKEY_RP_ID
        );

        // Store the credential
        $credential_id_b64 = spp_passkey_base64url_encode( $credential_record->publicKeyCredentialId );
        $public_key_json   = $serializer->serialize( $credential_record, 'json' );

        $device_name = sanitize_text_field( $device_name ) ?: spp_passkey_guess_device_name();

        $inserted = $wpdb->insert(
            SPP_PASSKEY_TABLE,
            [
                'user_id'       => $user_id,
                'credential_id' => $credential_id_b64,
                'public_key'    => $public_key_json,
                'sign_count'    => $credential_record->counter,
                'device_name'   => $device_name,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'message' => 'Failed to save credential: ' . $wpdb->last_error ];
        }

        return [ 'success' => true, 'message' => 'Passkey registered successfully.' ];

    } catch ( \Throwable $e ) {
        error_log( 'SPP Passkey registration error: ' . $e->getMessage() );
        return [ 'success' => false, 'message' => 'Registration failed: ' . $e->getMessage() ];
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Authentication ────────────────────────────────────────────────────────────
/**
 * Build PublicKeyCredentialRequestOptions.
 * Sent to the browser to initiate passkey authentication.
 * If $user_id is provided, only that user's credentials are included.
 * If 0, all credentials on the site are allowed (discoverable credential flow).
 *
 * @param  int $user_id  0 for usernameless/discoverable flow
 * @return array  JSON-serializable options array
 */
function spp_passkey_authentication_options( int $user_id = 0 ): array {
    $challenge = spp_passkey_generate_challenge( 'authentication', $user_id );

    $allow_list = [];
    if ( $user_id > 0 ) {
        $credentials = spp_passkey_get_user_credentials( $user_id );
        $allow_list  = array_map( function( $row ) {
            return [
                'type' => 'public-key',
                'id'   => $row->credential_id,
            ];
        }, $credentials );
    }

    $options = [
        'challenge'        => $challenge,
        'timeout'          => SPP_PASSKEY_TIMEOUT,
        'rpId'             => SPP_PASSKEY_RP_ID,
        'userVerification' => 'preferred',
    ];

    if ( ! empty( $allow_list ) ) {
        $options['allowCredentials'] = $allow_list;
    }

    return $options;
}

/**
 * Verify an authentication assertion from the browser and log the user in.
 *
 * @param  array    $response   Decoded JSON from the browser
 * @param  int      $user_id    WP user ID (0 for discoverable flow)
 * @return array    ['success' => bool, 'message' => string, 'user_id' => int]
 */
function spp_passkey_verify_authentication( array $response, int $user_id = 0 ): array {
    global $wpdb;

    try {
        $challenge_b64 = spp_passkey_consume_challenge( 'authentication', $user_id );
        if ( ! $challenge_b64 ) {
            return [ 'success' => false, 'message' => 'Challenge expired or not found. Please try again.' ];
        }

        $serializer = spp_passkey_serializer();

        // Deserialize the PublicKeyCredential from the browser
        $public_key_credential = $serializer->deserialize(
            json_encode( $response ),
            PublicKeyCredential::class,
            'json'
        );

        if ( ! $public_key_credential->response instanceof \Webauthn\AuthenticatorAssertionResponse ) {
            return [ 'success' => false, 'message' => 'Invalid response type.' ];
        }

        // Find the credential in our DB by credential ID
        // PublicKeyCredential exposes rawId (binary) not id (base64url string)
        $credential_id_b64 = spp_passkey_base64url_encode( $public_key_credential->rawId );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . SPP_PASSKEY_TABLE . " WHERE credential_id = %s",
            $credential_id_b64
        ) );

        if ( ! $row ) {
            return [ 'success' => false, 'message' => 'Credential not found.' ];
        }

        // If user_id was provided, verify it matches the stored credential
        if ( $user_id > 0 && (int) $row->user_id !== $user_id ) {
            return [ 'success' => false, 'message' => 'Credential does not belong to this user.' ];
        }

        $resolved_user_id = (int) $row->user_id;

        // Deserialize stored CredentialRecord
        $credential_record = $serializer->deserialize(
            $row->public_key,
            CredentialRecord::class,
            'json'
        );

        // Build request options with stored challenge for verification
        $options = PublicKeyCredentialRequestOptions::create(
            spp_passkey_base64url_decode( $challenge_b64 )
        );

        // Verify the assertion
        $manager   = spp_passkey_assertion_ceremony_manager();
        $validator = AuthenticatorAssertionResponseValidator::create( $manager );
        $updated_record = $validator->check(
            $credential_record,
            $public_key_credential->response,
            $options,
            SPP_PASSKEY_RP_ID,
            null // userHandle — null for single-device flow
        );

        // Update sign count and last_used in DB
        $wpdb->update(
            SPP_PASSKEY_TABLE,
            [
                'sign_count' => $updated_record->counter,
                'last_used'  => current_time( 'mysql' ),
            ],
            [ 'id' => $row->id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        // Also update stored public key JSON (counter changed)
        $wpdb->update(
            SPP_PASSKEY_TABLE,
            [ 'public_key' => $serializer->serialize( $updated_record, 'json' ) ],
            [ 'id' => $row->id ],
            [ '%s' ],
            [ '%d' ]
        );

        return [
            'success' => true,
            'message' => 'Authentication successful.',
            'user_id' => $resolved_user_id,
        ];

    } catch ( \Throwable $e ) {
        error_log( 'SPP Passkey authentication error: ' . $e->getMessage() );
        return [ 'success' => false, 'message' => 'Authentication failed: ' . $e->getMessage() ];
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Credential management ─────────────────────────────────────────────────────
/**
 * Get all passkey credentials for a user.
 *
 * @param  int $user_id
 * @return array  Array of DB row objects
 */
function spp_passkey_get_user_credentials( int $user_id ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, credential_id, device_name, sign_count, created_at, last_used
         FROM " . SPP_PASSKEY_TABLE . "
         WHERE user_id = %d
         ORDER BY created_at ASC",
        $user_id
    ) ) ?: [];
}

/**
 * Delete a specific credential by ID, verifying ownership.
 *
 * @param  int $credential_db_id  Row ID in spp_passkey_credentials
 * @param  int $user_id           Must match the credential's user_id
 * @return bool
 */
function spp_passkey_delete_credential( int $credential_db_id, int $user_id ): bool {
    global $wpdb;
    $deleted = $wpdb->delete(
        SPP_PASSKEY_TABLE,
        [ 'id' => $credential_db_id, 'user_id' => $user_id ],
        [ '%d', '%d' ]
    );
    return (bool) $deleted;
}

/**
 * Rename a credential's device name.
 *
 * @param  int    $credential_db_id
 * @param  int    $user_id
 * @param  string $device_name
 * @return bool
 */
function spp_passkey_rename_credential( int $credential_db_id, int $user_id, string $device_name ): bool {
    global $wpdb;
    $updated = $wpdb->update(
        SPP_PASSKEY_TABLE,
        [ 'device_name' => sanitize_text_field( $device_name ) ],
        [ 'id' => $credential_db_id, 'user_id' => $user_id ],
        [ '%s' ],
        [ '%d', '%d' ]
    );
    return $updated !== false;
}

/**
 * Check if a user has any passkeys registered.
 *
 * @param  int $user_id
 * @return bool
 */
function spp_passkey_user_has_passkey( int $user_id ): bool {
    global $wpdb;
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . SPP_PASSKEY_TABLE . " WHERE user_id = %d",
        $user_id
    ) );
    return $count > 0;
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Utility functions ─────────────────────────────────────────────────────────
/**
 * Base64url encode (no padding, URL-safe).
 */
function spp_passkey_base64url_encode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Base64url decode (handles padded and unpadded input).
 */
function spp_passkey_base64url_decode( string $data ): string {
    $padded = strtr( $data, '-_', '+/' );
    $pad    = strlen( $padded ) % 4;
    if ( $pad ) {
        $padded .= str_repeat( '=', 4 - $pad );
    }
    return base64_decode( $padded );
}

/**
 * Guess a human-readable device name from the User-Agent string.
 * Used as a fallback when the user doesn't provide a name.
 */
function spp_passkey_guess_device_name(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ( str_contains( $ua, 'iPhone' ) )  return 'iPhone';
    if ( str_contains( $ua, 'iPad' ) )    return 'iPad';
    if ( str_contains( $ua, 'Android' ) ) return 'Android device';
    if ( str_contains( $ua, 'Mac' ) )     return 'Mac';
    if ( str_contains( $ua, 'Windows' ) ) return 'Windows PC';
    if ( str_contains( $ua, 'Linux' ) )   return 'Linux device';
    return 'Unknown device';
}
// ─────────────────────────────────────────────────────────────────────────────
