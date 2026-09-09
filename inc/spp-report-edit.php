<?php
/* =========================================================
   Shared Report Table Edit AJAX Handler
   Version: 1.0.0
   Date: 2026-09-09

   Secure, generic persistence for editable report-table cells.

   The browser supplies only the report, record key, column and
   value. The server resolves the trusted report configuration,
   verifies permissions and editable-column status, and performs
   the update.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_spp_save_report_cell', 'spp_ajax_save_report_cell' );

function spp_ajax_save_report_cell() {

    if ( ! is_user_logged_in() ) {
        wp_send_json_error(
            array( 'message' => 'You must be logged in to edit this report.' ),
            403
        );
    }

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        wp_send_json_error(
            array( 'message' => 'You do not have permission to edit this report.' ),
            403
        );
    }

    check_ajax_referer( 'spp_report_edit', 'nonce' );

    $report = isset( $_POST['report'] )
        ? sanitize_key( wp_unslash( $_POST['report'] ) )
        : '';

    $key = isset( $_POST['key'] )
        ? sanitize_text_field( wp_unslash( $_POST['key'] ) )
        : '';

    $column = isset( $_POST['column'] )
        ? sanitize_text_field( wp_unslash( $_POST['column'] ) )
        : '';

    $value = isset( $_POST['value'] )
        ? sanitize_text_field( wp_unslash( $_POST['value'] ) )
        : '';

    if ( $report === '' || $key === '' || $column === '' ) {
        wp_send_json_error(
            array( 'message' => 'Missing report, record key, or column.' ),
            400
        );
    }

    $registry = $GLOBALS['spp_report_registry'] ?? array();

    // A saved variant resolves to its base report. This keeps editing
    // generic for future report variants.
    $variant = function_exists( 'spp_get_report_variant' )
        ? spp_get_report_variant( $report )
        : false;

    $base_report = $variant
        ? sanitize_key( $variant['base_table'] )
        : $report;

    if (
        ! isset( $registry[ $base_report ] )
        || ! function_exists( $registry[ $base_report ] )
    ) {
        wp_send_json_error(
            array( 'message' => 'Unknown report.' ),
            400
        );
    }

    $definition = call_user_func( $registry[ $base_report ] );

    if ( ! is_array( $definition ) || empty( $definition['columns'] ) ) {
        wp_send_json_error(
            array( 'message' => 'Invalid report definition.' ),
            500
        );
    }

    $edit = isset( $definition['edit'] ) && is_array( $definition['edit'] )
        ? $definition['edit']
        : array();

    $table = isset( $edit['table'] ) ? (string) $edit['table'] : '';
    $key_column = isset( $edit['key_column'] ) ? (string) $edit['key_column'] : '';

    // These identifiers come from PHP configuration, but validate them
    // before passing them to wpdb.
    if (
        ! preg_match( '/^[A-Za-z0-9_]+$/', $table )
        || ! preg_match( '/^[A-Za-z0-9_]+$/', $key_column )
    ) {
        wp_send_json_error(
            array( 'message' => 'Invalid report edit configuration.' ),
            500
        );
    }

    // The requested column must exist in this report and explicitly opt
    // into editing. This is what supports multiple editable columns.
    $editable_column = null;

    foreach ( $definition['columns'] as $col ) {
        if ( isset( $col['key'] ) && (string) $col['key'] === $column ) {
            $editable_column = $col;
            break;
        }
    }

    if (
        ! is_array( $editable_column )
        || empty( $editable_column['editable'] )
        || ! preg_match( '/^[A-Za-z0-9_]+$/', $column )
    ) {
        wp_send_json_error(
            array( 'message' => 'That column is not editable.' ),
            400
        );
    }

    // Never allow the unique key itself to be changed through this endpoint.
    if ( $column === $key_column ) {
        wp_send_json_error(
            array( 'message' => 'The record key cannot be edited.' ),
            400
        );
    }

    global $wpdb;

    // Existing report definitions use literal table names such as Results.
    // Preserve that convention; do not automatically add $wpdb->prefix.
    $updated = $wpdb->update(
        $table,
        array( $column => $value ),
        array( $key_column => $key ),
        array( '%s' ),
        array( '%s' )
    );

    if ( $updated === false ) {
        wp_send_json_error(
            array( 'message' => 'The database update failed.' ),
            500
        );
    }

    // 0 means the value was already the stored value; that is still a
    // successful operation from the UI's perspective.
    wp_send_json_success(
        array(
            'message' => 'Saved.',
            'value'   => $value,
            'updated' => (int) $updated,
        )
    );
}
