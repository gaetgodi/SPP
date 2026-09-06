<?php
/* =========================================================
   Report Generator — Admin Screen
   Version: 1.0.0
   Date: 2026-09-06

   PURPOSE:
   wp-admin screen (under Pages) for building a [spp_report] variant
   interactively: pick a registered report, choose which columns to
   show and in what order, optionally disable sorting, preview the
   real rendered table right there, then either copy a one-off
   shortcode (columns=/no_sort= attributes, no DB write) or save it as
   a named variant (writes to spp_report_variants via
   spp_save_report_variant(), inc/spp-report-variants.php) so it can be
   embedded later with just [spp_report table="<variant_name>"].

   No JS/AJAX -- plain GET to pick a report, plain nonce-protected POST
   for the column/order/no-sort form, matching this project's
   established philosophy (same reasoning already documented in
   inc/spp-report-table.php). Column reordering is numbered order
   inputs, not drag-and-drop -- simplest robust option, zero new JS
   dependency.

   ACCESS CONTROL -- administrator-only, deliberately NOT
   spp_is_admin_or_editor() (that helper is admin-OR-editor, the wrong
   gate for a screen that can create new public-facing report
   configurations). Two layers, per this project's own established
   "never trust the capability parameter alone" convention:
     1. add_submenu_page()'s own capability: 'manage_options' -- hides
        the menu item from non-administrators, including editors.
     2. The real, load-bearing check at the top of the render
        callback: an explicit role check (in_array('administrator', ...)),
        not current_user_can('manage_options') -- same technique
        functions.php's spp_is_admin_or_editor()/spp_is_ladder_admin()
        already use, just narrowed to administrator only, so this
        screen doesn't depend on 'manage_options' being reachable only
        via the admin role (confirmed true today by inspecting
        blog-roles.php's spp_sync_blog_author_caps(), which never
        grants manage_options to any custom role -- but the internal
        check doesn't need to depend on that staying true).
   Both layers agree today: manage_options is held only by
   administrators on this site, so there's no path where a user sees
   the menu item but fails the internal check, or vice versa.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=page',
        'Report Generator',
        'Report Generator',
        'manage_options', // layer 1: hides the menu item from non-admins
        'spp-report-generator',
        'spp_render_report_generator_page'
    );
} );

function spp_render_report_generator_page() {
    // Layer 2: the real gate. Deliberately not spp_is_admin_or_editor()
    // (admin-OR-editor is the wrong scope here) and deliberately an
    // explicit role check rather than current_user_can('manage_options')
    // again, so this doesn't just re-check the same capability the menu
    // registration already gated on.
    $roles = (array) wp_get_current_user()->roles;
    if ( ! in_array( 'administrator', $roles, true ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $registry = $GLOBALS['spp_report_registry'] ?? array();

    echo '<div class="wrap"><h1>Report Generator</h1>';

    // -- Step 1: choose a report (plain GET) ---------------------------------
    $selected_report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
    if ( isset( $_POST['report'] ) ) {
        // A POST submission (preview/generate/save) carries the report
        // selection forward too, takes priority over the GET one.
        $selected_report = sanitize_key( wp_unslash( $_POST['report'] ) );
    }

    ?>
    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="spp-report-generator">
        <label for="spp_rg_report"><strong>Report:</strong></label>
        <select name="report" id="spp_rg_report">
            <option value="">-- select a report --</option>
            <?php foreach ( $registry as $key => $fn ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_report, $key ); ?>>
                    <?php echo esc_html( $key ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Load</button>
    </form>
    <?php

    if ( $selected_report === '' || ! isset( $registry[ $selected_report ] ) || ! function_exists( $registry[ $selected_report ] ) ) {
        if ( $selected_report !== '' ) {
            echo '<p style="color:#c0392b;">Unknown report.</p>';
        }
        echo '</div>';
        return;
    }

    $definition = call_user_func( $registry[ $selected_report ] );
    if ( ! is_array( $definition ) || ! isset( $definition['columns'], $definition['rows'] ) ) {
        echo '<p style="color:#c0392b;">Report misconfigured.</p></div>';
        return;
    }
    $full_columns = $definition['columns'];
    $rows         = $definition['rows'];

    // -- Sticky form state: from a just-submitted POST, else all columns
    //    included in their original order, no_sort unchecked. --------------
    $action  = '';
    $include = array(); // key => true
    $order   = array(); // key => int
    $no_sort = false;
    $variant_name_input = '';
    $messages = array(); // ['type'=>'error'|'success', 'text'=>string]

    $is_post_for_this_report = ( $_SERVER['REQUEST_METHOD'] === 'POST' && $selected_report !== '' );

    if ( $is_post_for_this_report ) {
        check_admin_referer( 'spp_report_generator', 'spp_report_generator_nonce' );

        $action  = isset( $_POST['spp_action'] ) ? sanitize_key( wp_unslash( $_POST['spp_action'] ) ) : '';
        $no_sort = isset( $_POST['no_sort'] ) && $_POST['no_sort'] === '1';
        $variant_name_input = isset( $_POST['variant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['variant_name'] ) ) : '';

        $posted_include = isset( $_POST['col_include'] ) && is_array( $_POST['col_include'] ) ? wp_unslash( $_POST['col_include'] ) : array();
        $posted_order   = isset( $_POST['col_order'] ) && is_array( $_POST['col_order'] ) ? wp_unslash( $_POST['col_order'] ) : array();

        foreach ( $full_columns as $i => $col ) {
            $key = $col['key'];
            $include[ $key ] = isset( $posted_include[ $key ] );
            $order[ $key ]   = isset( $posted_order[ $key ] ) ? (int) $posted_order[ $key ] : ( $i + 1 );
        }
    } else {
        foreach ( $full_columns as $i => $col ) {
            $key = $col['key'];
            $include[ $key ] = true;
            $order[ $key ]   = $i + 1;
        }
    }

    // -- Build the effective, ordered key list from the current form state --
    $selected_keys = array();
    foreach ( $full_columns as $col ) {
        if ( ! empty( $include[ $col['key'] ] ) ) {
            $selected_keys[] = $col['key'];
        }
    }
    usort( $selected_keys, function( $a, $b ) use ( $order ) {
        return ( $order[ $a ] ?? 0 ) <=> ( $order[ $b ] ?? 0 );
    } );

    // -- Handle the two real actions (generate / save) -- preview always
    //    happens below regardless of which button was pressed. -------------
    if ( $is_post_for_this_report && $action === 'save' ) {
        $variant_key = sanitize_key( $variant_name_input );
        if ( $variant_key === '' ) {
            $messages[] = array( 'type' => 'error', 'text' => 'Enter a variant name.' );
        } elseif ( isset( $registry[ $variant_key ] ) ) {
            $messages[] = array( 'type' => 'error', 'text' => "\"{$variant_key}\" is already a registered report name -- choose a different variant name." );
        } elseif ( spp_get_report_variant( $variant_key ) ) {
            $messages[] = array( 'type' => 'error', 'text' => "A variant named \"{$variant_key}\" already exists -- choose a different name." );
        } elseif ( empty( $selected_keys ) ) {
            $messages[] = array( 'type' => 'error', 'text' => 'Select at least one column before saving.' );
        } else {
            $result = spp_save_report_variant( $variant_key, $selected_report, $selected_keys, $no_sort );
            if ( is_wp_error( $result ) ) {
                $messages[] = array( 'type' => 'error', 'text' => $result->get_error_message() );
            } else {
                $messages[] = array(
                    'type' => 'success',
                    'text' => 'Variant saved. Embed it with: <code>[spp_report table="' . esc_html( $variant_key ) . '"]</code>',
                );
            }
        }
    }

    $generated_shortcode = '';
    if ( $is_post_for_this_report && $action === 'generate' ) {
        if ( empty( $selected_keys ) ) {
            $messages[] = array( 'type' => 'error', 'text' => 'Select at least one column before generating a shortcode.' );
        } else {
            $generated_shortcode = '[spp_report table="' . $selected_report . '" columns="' . implode( ',', $selected_keys ) . '" no_sort="' . ( $no_sort ? '1' : '0' ) . '"]';
        }
    }

    // -- Messages -------------------------------------------------------------
    foreach ( $messages as $m ) {
        $color = $m['type'] === 'error' ? '#c0392b' : '#1e8449';
        $bg    = $m['type'] === 'error' ? '#fdf3f2' : '#eafaf1';
        echo '<div style="background:' . $bg . ';border:1px solid ' . $color . ';color:' . $color . ';padding:10px 14px;border-radius:6px;margin-bottom:14px;">' . $m['text'] . '</div>';
    }

    if ( $generated_shortcode !== '' ) {
        echo '<div style="margin-bottom:14px;">';
        echo '<label for="spp_rg_shortcode_out"><strong>Shortcode:</strong></label><br>';
        echo '<textarea id="spp_rg_shortcode_out" readonly rows="2" style="width:100%;max-width:700px;font-family:monospace;" onclick="this.select()">' . esc_textarea( $generated_shortcode ) . '</textarea>';
        echo '</div>';
    }

    // -- Column selection form --------------------------------------------
    ?>
    <form method="post" style="max-width:700px;">
        <?php wp_nonce_field( 'spp_report_generator', 'spp_report_generator_nonce' ); ?>
        <input type="hidden" name="report" value="<?php echo esc_attr( $selected_report ); ?>">

        <table class="widefat" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:60px;">Show</th>
                    <th>Column</th>
                    <th style="width:100px;">Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $full_columns as $col ) :
                    $key = $col['key'];
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="col_include[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $include[ $key ] ) ); ?>>
                        </td>
                        <td><?php echo esc_html( $col['label'] ?? $key ); ?></td>
                        <td>
                            <input type="number" name="col_order[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $order[ $key ] ?? 1 ); ?>" style="width:70px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <label>
                <input type="checkbox" name="no_sort" value="1" <?php checked( $no_sort ); ?>>
                Disable column-header sorting for this report
            </label>
        </p>

        <p>
            <button type="submit" name="spp_action" value="preview" class="button">Update Preview</button>
            <button type="submit" name="spp_action" value="generate" class="button">Generate Shortcode</button>
        </p>

        <hr>

        <p>
            <label for="spp_rg_variant_name"><strong>Save as a new report</strong></label><br>
            <input type="text" id="spp_rg_variant_name" name="variant_name" value="<?php echo esc_attr( $variant_name_input ); ?>" placeholder="variant_name" style="width:250px;">
            <button type="submit" name="spp_action" value="save" class="button button-primary">Save as New Report</button>
        </p>
    </form>

    <h2>Preview</h2>
    <?php
    $preview_columns = spp_report_filter_columns( $full_columns, $selected_keys );
    if ( empty( $preview_columns ) ) {
        $preview_columns = $full_columns; // nothing selected yet -- show everything rather than an empty table
    }
    if ( $no_sort ) {
        $preview_columns = array_map( function( $col ) {
            $col['sortable'] = false;
            return $col;
        }, $preview_columns );
    }
    $preview_keys = array_column( $preview_columns, 'key' );
    $preview_default_sort = in_array( 'Rank', $preview_keys, true ) ? 'Rank' : ( $preview_keys[0] ?? '' );

    spp_render_report_table( $preview_columns, $rows, array(
        'id'               => 'preview',
        'default_sort'     => $preview_default_sort,
        'default_dir'      => 'asc',
        'per_page_options' => array( 10, 25, 50 ),
        'default_per_page' => 10,
    ) );

    echo '</div>';
}
