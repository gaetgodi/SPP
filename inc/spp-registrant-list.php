<?php
/**
 * [spp_registrant_list] Shortcode
 * Displays a dynamic dropdown of active events with registrants,
 * and loads the registrant list via AJAX on selection.
 *
 * Parameters:
 *   filter="ace"   - optional partial case-insensitive title match
 *                    to limit events shown in dropdown
 *
 * Access:
 *   - Not logged in : shows nothing
 *   - Logged in     : sees full name, full email, full phone for all registrants
 *
 * Version: 2.4.0
 * Changes from 2.3.0:
 *   - Dropdown now shows start time alongside date
 *   - Waiting list registrants shown as separate section below confirmed
 */

// ============================================================
// Enqueue scripts
// ============================================================
add_action( 'wp_enqueue_scripts', 'spp_registrant_list_enqueue' );
function spp_registrant_list_enqueue() {
    if ( ! is_user_logged_in() ) return;

    wp_enqueue_script(
        'spp-registrant-list',
        get_stylesheet_directory_uri() . '/js/spp-registrant-list.js',
        array( 'jquery' ),
        '2.2.0',
        true
    );
    wp_localize_script(
        'spp-registrant-list',
        'sppRL',
        array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
    );
}

// ============================================================
// Shortcode - renders the dropdown shell
// ============================================================
add_shortcode( 'spp_registrant_list', 'spp_registrant_list_shortcode' );
function spp_registrant_list_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '';

    $atts = shortcode_atts( array(
        'filter' => '',
    ), $atts, 'spp_registrant_list' );

    global $wpdb;
    $p = $wpdb->prefix;

    // Build filter clause
    $filter_sql = '';
    if ( ! empty( $atts['filter'] ) ) {
        $like = '%' . $wpdb->esc_like( $atts['filter'] ) . '%';
        $filter_sql = $wpdb->prepare( "AND p.post_title LIKE %s", $like );
    }

    // Fetch active events with confirmed registrants
    $events = $wpdb->get_results( "
        SELECT
            o.occurrence_id,
            o.post_id,
            o.start_date,
            o.end_date,
            p.post_title,
            COUNT(DISTINCT latest.user_id) AS reg_count,
            occ_counts.total_occs
        FROM {$p}tec_occurrences o
        JOIN {$p}posts p ON o.post_id = p.ID
        JOIN (
            SELECT event_id, user_id, status
            FROM {$p}rtec_entries e1
            WHERE id = (
                SELECT MAX(id) FROM {$p}rtec_entries e2
                WHERE e2.event_id = e1.event_id
                AND e2.user_id = e1.user_id
            )
        ) latest ON ( o.post_id = latest.event_id OR o.occurrence_id + 30000000 = latest.event_id )
            AND latest.status = 'confirmed'
        JOIN (
            SELECT post_id, COUNT(*) AS total_occs
            FROM {$p}tec_occurrences
            GROUP BY post_id
        ) occ_counts ON occ_counts.post_id = o.post_id
        WHERE o.end_date >= CURDATE()
        AND p.post_status = 'publish'
        AND p.ID NOT