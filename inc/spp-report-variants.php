<?php
/* =========================================================
   Report Variants — DB Table + Lookup Helper
   Version: 1.0.0
   Date: 2026-09-06

   PURPOSE:
   Persists named "variants" of a registered report -- a saved subset
   + display order of that report's columns, plus a no-sort flag --
   created via the Report Generator admin screen
   (inc/spp-report-generator-admin.php) and consumed by the [spp_report]
   shortcode (inc/spp-reports.php) so a variant can be embedded with
   just [spp_report table="<variant_name>"], no attributes needed.

   Table creation follows this codebase's one existing dbDelta
   precedent (inc/passkey-server.php: version-gated via a wp_options
   row, dbDelta() on after_setup_theme) rather than inventing a new
   convention.

   columns is stored as JSON (wp_json_encode/json_decode), not PHP
   serialize() -- avoids any object-injection surface for a column
   that will eventually hold admin-entered data, and is the more
   conventional choice for a simple list of strings.

   variant_name is validated with sanitize_key() at write time (the
   admin screen) -- the exact same sanitizer the [spp_report] shortcode
   already applies to its own table="" attribute, so a saved name is
   guaranteed to match on lookup without a separate normalization
   scheme to keep in sync.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

define( 'SPP_REPORT_VARIANTS_DB_VERSION', '1.0.0' );

/**
 * Fully-qualified table name (helper so callers don't repeat
 * $wpdb->prefix . 'spp_report_variants' everywhere).
 */
function spp_report_variants_table() {
    global $wpdb;
    return $wpdb->prefix . 'spp_report_variants';
}

/**
 * Create/upgrade the spp_report_variants table if needed.
 * Idempotent -- checks the stored db version before doing anything,
 * same pattern as spp_passkey_create_table().
 */
function spp_report_variants_create_table() {
    global $wpdb;

    $installed_version = get_option( 'spp_report_variants_db_version', '0.0.0' );
    if ( version_compare( $installed_version, SPP_REPORT_VARIANTS_DB_VERSION, '>=' ) ) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $table           = spp_report_variants_table();

    $sql = "CREATE TABLE {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        variant_name VARCHAR(191)        NOT NULL,
        base_table   VARCHAR(191)        NOT NULL,
        columns      LONGTEXT            NOT NULL,
        no_sort      TINYINT(1)          NOT NULL DEFAULT 0,
        created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY   variant_name (variant_name)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'spp_report_variants_db_version', SPP_REPORT_VARIANTS_DB_VERSION );
}
add_action( 'after_setup_theme', 'spp_report_variants_create_table' );

/**
 * Fetch one variant by name.
 *
 * @param string $name Already expected to be sanitize_key()'d by the
 *                      caller (the shortcode already does this for
 *                      its table="" attribute before calling here).
 * @return array|null ['variant_name'=>, 'base_table'=>, 'columns'=>array,
 *                      'no_sort'=>bool] or null if no match / bad data.
 */
function spp_get_report_variant( $name ) {
    global $wpdb;

    if ( $name === '' ) return null;

    $table = spp_report_variants_table();
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE variant_name = %s", $name ),
        ARRAY_A
    );

    if ( ! $row ) return null;

    $columns = json_decode( $row['columns'], true );
    if ( ! is_array( $columns ) ) return null; // corrupt row -- treat as no variant rather than fatal

    return array(
        'variant_name' => $row['variant_name'],
        'base_table'   => $row['base_table'],
        'columns'      => $columns,
        'no_sort'      => (bool) $row['no_sort'],
    );
}

/**
 * Save a new variant. Caller (the admin screen) is responsible for
 * uniqueness validation against both this table AND the report
 * registry before calling this -- this function does one more
 * defensive uniqueness check of its own (the UNIQUE KEY also enforces
 * it at the DB level) but does not check the registry, since it has
 * no knowledge of it.
 *
 * @return true|WP_Error
 */
function spp_save_report_variant( $variant_name, $base_table, array $columns, $no_sort ) {
    global $wpdb;

    $variant_name = sanitize_key( $variant_name );
    if ( $variant_name === '' ) {
        return new WP_Error( 'spp_report_variant_invalid_name', 'Variant name cannot be empty.' );
    }

    $table = spp_report_variants_table();

    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE variant_name = %s", $variant_name ) );
    if ( $existing ) {
        return new WP_Error( 'spp_report_variant_exists', 'A variant with this name already exists.' );
    }

    $result = $wpdb->insert(
        $table,
        array(
            'variant_name' => $variant_name,
            'base_table'   => $base_table,
            'columns'      => wp_json_encode( array_values( $columns ) ),
            'no_sort'      => $no_sort ? 1 : 0,
        ),
        array( '%s', '%s', '%s', '%d' )
    );

    if ( $result === false ) {
        return new WP_Error( 'spp_report_variant_db_error', $wpdb->last_error ?: 'Unknown database error.' );
    }

    return true;
}
