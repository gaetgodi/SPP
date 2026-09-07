<?php
/* =========================================================
   Report Variants — DB Table + Lookup Helper
   Version: 1.1.0
   Date: 2026-09-07

   Changes from 1.0.0:
   - Added a per_page VARCHAR(20) NOT NULL DEFAULT 'All' column
     (dbDelta ADD COLUMN via the usual db-version-option gate) --
     rows-per-page is now a variant-configurable setting, same
     treatment as columns/no_sort. spp_get_report_variant() and
     spp_save_report_variant() both grew a per_page parameter/return
     key; see inc/spp-reports.php 1.3.0 for spp_report_per_page_choices()
     / spp_report_sanitize_per_page(), the canonical enum + validator
     both this file and the shortcode handler use.
   - Added spp_get_report_variants_for_base( $base_table ): every saved
     variant for one base report, full config (not just names) -- feeds
     the Report Generator admin screen's combined "Default + existing
     variants" selector (inc/spp-report-generator-admin.php 2.0.0) and
     its always-visible-shortcode exact-match check.
   - Added spp_next_report_variant_name( $base_table ): the next
     "{base_table}-variant-{N}" name for a base report, N = one more
     than the highest existing N for that base_table (MAX, not COUNT --
     a deleted variant's number is never reused). Saving a variant from
     the admin screen is now always auto-named; there is no more manual
     variant-name text field there.
   - Added spp_delete_report_variant( $variant_name ): the admin
     screen's new delete action.

   PURPOSE:
   Persists named "variants" of a registered report -- a saved subset
   + display order of that report's columns, a no-sort flag, and a
   per-page setting -- created via the Report Generator admin screen
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

define( 'SPP_REPORT_VARIANTS_DB_VERSION', '1.1.0' );

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
 * same pattern as spp_passkey_create_table(). dbDelta() diffs the
 * CREATE TABLE statement against the live schema, so bumping
 * SPP_REPORT_VARIANTS_DB_VERSION and re-running this on an existing
 * install ADDs the new per_page column (with its DEFAULT 'All'
 * backfilling every existing row) rather than recreating the table.
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
        per_page     VARCHAR(20)         NOT NULL DEFAULT 'All',
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
 *                      'no_sort'=>bool, 'per_page'=>string] or null if
 *                      no match / bad data.
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
        // isset() fallback covers a row read mid-migration (old schema,
        // column not added yet) rather than a fresh SELECT * miss.
        'per_page'     => isset( $row['per_page'] ) && $row['per_page'] !== '' ? $row['per_page'] : 'All',
    );
}

/**
 * Fetch every saved variant for one base report, full config (not just
 * names) -- used by the Report Generator admin screen's combined
 * "Default + existing variants" selector, both to populate it and to
 * check whether the form's current settings exactly match one of them
 * (inc/spp-report-generator-admin.php's always-visible shortcode).
 *
 * @return array List of ['variant_name'=>, 'base_table'=>,
 *               'columns'=>array, 'no_sort'=>bool, 'per_page'=>string],
 *               ordered by variant_name. A corrupt row (bad JSON) is
 *               silently skipped, same degrade-not-break stance as
 *               spp_get_report_variant().
 */
function spp_get_report_variants_for_base( $base_table ) {
    global $wpdb;

    $table  = spp_report_variants_table();
    $rows   = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE base_table = %s ORDER BY variant_name ASC", $base_table ),
        ARRAY_A
    );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $columns = json_decode( $row['columns'], true );
        if ( ! is_array( $columns ) ) continue;
        $out[] = array(
            'variant_name' => $row['variant_name'],
            'base_table'   => $row['base_table'],
            'columns'      => $columns,
            'no_sort'      => (bool) $row['no_sort'],
            'per_page'     => isset( $row['per_page'] ) && $row['per_page'] !== '' ? $row['per_page'] : 'All',
        );
    }
    return $out;
}

/**
 * The next "{base_table}-variant-{N}" name for a base report. N is one
 * more than the highest existing N among that base_table's variants
 * (MAX, not COUNT) so a deleted variant's number is never reused --
 * e.g. deleting membership-variant-2 out of {1,2,3} still produces
 * membership-variant-4 next, not a re-issued 2.
 */
function spp_next_report_variant_name( $base_table ) {
    global $wpdb;

    $table  = spp_report_variants_table();
    $prefix = $base_table . '-variant-';
    $like   = $wpdb->esc_like( $prefix ) . '%';
    $names  = $wpdb->get_col( $wpdb->prepare(
        "SELECT variant_name FROM {$table} WHERE base_table = %s AND variant_name LIKE %s",
        $base_table, $like
    ) );

    $max = 0;
    foreach ( (array) $names as $n ) {
        if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d+)$/', $n, $m ) ) {
            $max = max( $max, (int) $m[1] );
        }
    }
    return $prefix . ( $max + 1 );
}

/**
 * Save a new variant. Caller (the admin screen) is responsible for
 * uniqueness validation against both this table AND the report
 * registry before calling this -- this function does one more
 * defensive uniqueness check of its own (the UNIQUE KEY also enforces
 * it at the DB level) but does not check the registry, since it has
 * no knowledge of it. In practice the admin screen only ever passes an
 * auto-generated name from spp_next_report_variant_name(), which can't
 * collide with a registry key.
 *
 * @return true|WP_Error
 */
function spp_save_report_variant( $variant_name, $base_table, array $columns, $no_sort, $per_page = 'All' ) {
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

    // spp_report_sanitize_per_page() lives in inc/spp-reports.php, which
    // loads before this file is ever *called* (only its function
    // *definitions* load earlier in the require_once chain) -- see that
    // file's own per_page notes.
    $per_page = function_exists( 'spp_report_sanitize_per_page' ) ? spp_report_sanitize_per_page( $per_page ) : 'All';

    $result = $wpdb->insert(
        $table,
        array(
            'variant_name' => $variant_name,
            'base_table'   => $base_table,
            'columns'      => wp_json_encode( array_values( $columns ) ),
            'no_sort'      => $no_sort ? 1 : 0,
            'per_page'     => $per_page,
        ),
        array( '%s', '%s', '%s', '%d', '%s' )
    );

    if ( $result === false ) {
        return new WP_Error( 'spp_report_variant_db_error', $wpdb->last_error ?: 'Unknown database error.' );
    }

    return true;
}

/**
 * Delete a variant by name. Used by the admin screen's delete action
 * only -- the [spp_report] shortcode never deletes anything.
 *
 * @return true|WP_Error
 */
function spp_delete_report_variant( $variant_name ) {
    global $wpdb;

    $variant_name = sanitize_key( $variant_name );
    if ( $variant_name === '' ) {
        return new WP_Error( 'spp_report_variant_invalid_name', 'Variant name cannot be empty.' );
    }

    $table  = spp_report_variants_table();
    $result = $wpdb->delete( $table, array( 'variant_name' => $variant_name ), array( '%s' ) );

    if ( $result === false ) {
        return new WP_Error( 'spp_report_variant_db_error', $wpdb->last_error ?: 'Unknown database error.' );
    }
    if ( $result === 0 ) {
        return new WP_Error( 'spp_report_variant_not_found', 'No such variant.' );
    }

    return true;
}
