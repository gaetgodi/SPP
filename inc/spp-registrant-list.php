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
 * Version: 2.1.0
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
        '2.1.0',
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
    // Uses rtec_entries with latest-entry-per-user subquery (same pattern as spp_event_registrations)
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
        WHERE o.end_date >= NOW()
        AND p.post_status = 'publish'
        {$filter_sql}
        GROUP BY o.occurrence_id
        HAVING reg_count > 0
        ORDER BY total_occs DESC, o.start_date ASC
    " );

    if ( empty( $events ) ) {
        return '<p class="spp-registrant-none">No active events with registrants found.</p>';
    }

    // Split into recurring and one-off
    $recurring = array();
    $oneoff    = array();
    foreach ( $events as $ev ) {
        if ( (int) $ev->total_occs > 1 ) {
            $recurring[] = $ev;
        } else {
            $oneoff[] = $ev;
        }
    }

    // Build dropdown
    $out  = '<div class="spp-registrant-list-wrap">';
    $out .= '<div class="spp-registrant-dropdown-wrap">';
    $out .= '<label for="spp-event-select" class="spp-registrant-label">Select Event:</label>';
    $out .= '<select id="spp-event-select" class="spp-registrant-select">';
    $out .= '<option value="">-- Choose an event --</option>';

    if ( ! empty( $recurring ) ) {
        $out .= '<optgroup label="Recurring Events">';
        foreach ( $recurring as $ev ) {
            $rtec_event_id = $ev->occurrence_id + 30000000;
            $label = esc_html( $ev->post_title )
                   . ' - ' . date( 'M j, Y', strtotime( $ev->start_date ) )
                   . ' (' . (int) $ev->reg_count . ' registered)';
            $out .= '<option value="' . $rtec_event_id . '"'
                  . ' data-title="' . esc_attr( $ev->post_title ) . '"'
                  . ' data-date="' . esc_attr( date( 'l, F j, Y g:i a', strtotime( $ev->start_date ) ) ) . '">'
                  . $label
                  . '</option>';
        }
        $out .= '</optgroup>';
    }

    if ( ! empty( $oneoff ) ) {
        $out .= '<optgroup label="Single Events">';
        foreach ( $oneoff as $ev ) {
            $rtec_event_id = $ev->occurrence_id + 30000000;
            $label = esc_html( $ev->post_title )
                   . ' - ' . date( 'M j, Y', strtotime( $ev->start_date ) )
                   . ' (' . (int) $ev->reg_count . ' registered)';
            $out .= '<option value="' . $rtec_event_id . '"'
                  . ' data-title="' . esc_attr( $ev->post_title ) . '"'
                  . ' data-date="' . esc_attr( date( 'l, F j, Y g:i a', strtotime( $ev->start_date ) ) ) . '">'
                  . $label
                  . '</option>';
        }
        $out .= '</optgroup>';
    }

    $out .= '</select>';
    $out .= '</div>';
    $out .= '<div id="spp-registrant-results" class="spp-registrant-results"></div>';
    $out .= '</div>';

    return $out;
}

// ============================================================
// AJAX handler - returns registrant list HTML
// ============================================================
add_action( 'wp_ajax_spp_get_registrants', 'spp_get_registrants_ajax' );
function spp_get_registrants_ajax() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }

    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
    if ( ! $event_id ) {
        wp_send_json_error( 'No event ID' );
    }

    $title   = isset( $_POST['event_title'] ) ? sanitize_text_field( $_POST['event_title'] ) : '';
    $date    = isset( $_POST['event_date'] )  ? sanitize_text_field( $_POST['event_date'] )  : '';
    $subject = rawurlencode( $title . ' - ' . $date );

    global $wpdb;
    $p = $wpdb->prefix;

    // Get confirmed registrants using latest-entry-per-user pattern
    $registrants = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            e1.id,
            u.display_name,
            m.user_email,
            m.user_phone,
            e1.registration_date
         FROM {$p}rtec_entries e1
         JOIN {$p}users u ON u.ID = e1.user_id
         JOIN membership m ON m.user_id = e1.user_id
         WHERE e1.event_id = %d
         AND e1.status = 'confirmed'
         AND e1.id = (
             SELECT MAX(id) FROM {$p}rtec_entries e2
             WHERE e2.event_id = e1.event_id
             AND e2.user_id = e1.user_id
         )
         ORDER BY e1.id ASC",
        $event_id
    ) );

    if ( empty( $registrants ) ) {
        wp_send_json_success( array( 'html' => '<p class="spp-registrant-none">No registrants yet.</p>' ) );
    }

    // Build BCC mailto for Email All button
    $all_emails = array_map( function( $r ) { return $r->user_email; }, $registrants );
    $bcc        = rawurlencode( implode( ',', $all_emails ) );
    $mailto_all = 'mailto:pb@pickleballstouffville.ca?bcc=' . $bcc . '&subject=' . $subject;

    $html  = '<div class="spp-registrant-header">';
    $html .= '<h3 class="spp-registrant-title">' . esc_html( $title ) . '</h3>';
    $html .= '<p class="spp-registrant-date">' . esc_html( $date ) . '</p>';
    $html .= '</div>';

    $html .= '<div class="spp-registrant-actions">';
    $html .= '<a href="' . esc_attr( $mailto_all ) . '" class="spp-email-all-btn">';
    $html .= '&#9993; Email All ' . count( $registrants ) . ' Registrants (BCC)';
    $html .= '</a>';
    $html .= '</div>';

    $html .= '<table class="spp-registrant-table">';
    $html .= '<thead><tr>';
    $html .= '<th>#</th><th>Name</th><th>Email</th><th>Phone</th>';
    $html .= '</tr></thead><tbody>';

    foreach ( $registrants as $i => $r ) {
        $email_link = ! empty( $r->user_email )
            ? '<a href="mailto:' . esc_attr( $r->user_email )
              . '?subject=' . $subject . '"'
              . ' class="spp-contact-link spp-email-link">'
              . esc_html( $r->user_email ) . '</a>'
            : '&mdash;';

        $phone_link = ! empty( $r->user_phone )
            ? '<a href="tel:+1' . esc_attr( preg_replace( '/\D/', '', $r->user_phone ) ) . '"'
              . ' class="spp-contact-link spp-phone-link">'
              . esc_html( $r->user_phone ) . '</a>'
            : '&mdash;';

        $row_class = ( $i % 2 === 0 ) ? 'spp-row-even' : 'spp-row-odd';
        $html .= '<tr class="' . $row_class . '">';
        $html .= '<td class="spp-col-num">'   . ( $i + 1 ) . '</td>';
        $html .= '<td class="spp-col-name">'  . esc_html( $r->display_name ) . '</td>';
        $html .= '<td class="spp-col-email">' . $email_link . '</td>';
        $html .= '<td class="spp-col-phone">' . $phone_link . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $html .= '<p class="spp-registrant-count">'
           . count( $registrants ) . ' confirmed registrant'
           . ( count( $registrants ) !== 1 ? 's' : '' )
           . '</p>';

    wp_send_json_success( array( 'html' => $html ) );
}