<?php
/* =========================================================
   Shared Report Table Edit AJAX Handler
   Version: 1.1.0
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

    // The column definition is the trusted source for validation/storage type.
    // Supported types are intentionally small and explicit.
    $edit_type = isset( $editable_column['edit_type'] )
        ? sanitize_key( $editable_column['edit_type'] )
        : 'text';

    $nullable = ! empty( $editable_column['edit_nullable'] );
    $raw_value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

    $db_value = null;
    $db_format = '%s';

    switch ( $edit_type ) {
        case 'integer':
            if ( $raw_value === '' && $nullable ) {
                $db_value  = null;
                $db_format = '%s';
            } elseif ( filter_var( $raw_value, FILTER_VALIDATE_INT ) === false ) {
                wp_send_json_error(
                    array( 'message' => 'Please enter a whole number.' ),
                    400
                );
            } else {
                $db_value  = (int) $raw_value;
                $db_format = '%d';
            }
            break;

        case 'decimal':
            if ( $raw_value === '' && $nullable ) {
                $db_value  = null;
                $db_format = '%s';
            } elseif ( ! is_numeric( $raw_value ) ) {
                wp_send_json_error(
                    array( 'message' => 'Please enter a valid number.' ),
                    400
                );
            } else {
                $db_value  = (float) $raw_value;
                $db_format = '%f';
            }
            break;

        case 'text':
        default:
            if ( $raw_value === '' && ! $nullable ) {
                wp_send_json_error(
                    array( 'message' => 'This field cannot be blank.' ),
                    400
                );
            }
            $db_value  = sanitize_text_field( $raw_value );
            $db_format = '%s';
            break;
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
        array( $column => $db_value ),
        array( $key_column => $key ),
        array( $db_format ),
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
            'value'   => ( $db_value === null ? '' : (string) $db_value ),
            'updated' => (int) $updated,
        )
    );
}
