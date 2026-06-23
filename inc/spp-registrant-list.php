<?php
/**
 * [spp_registrant_list] Shortcode
 * Displays a dynamic dropdown of active events with registrants,
 * and loads the registrant list via AJAX on selection.
 * Also provides a "Send to Group" section for bulk announcements.
 *
 * Parameters:
 *   filter="ace"   - optional partial case-insensitive title match
 *                    to limit events shown in dropdown
 *
 * Version: 3.0.0
 * Changes from 2.7.0:
 *   - Removed all RTEC and TEC references. Now reads exclusively
 *     from gl_event_occurrences and gl_registrations.
 *   - Withdrawn section added below waiting list, showing both
 *     'withdrawn' and 'late_withdrawn' with withdrawal date/time.
 *   - Individual email checkboxes on each row in confirmed/waiting
 *     lists, with "Email Selected" button.
 */

// ============================================================
// Enqueue scripts
// ============================================================
add_action( 'wp_enqueue_scripts', 'spp_registrant_list_enqueue' );
function spp_registrant_list_enqueue() {
    if ( ! is_user_logged_in() ) return;

    wp_enqueue_style(
        'spp-registrant-list',
        get_stylesheet_directory_uri() . '/css/spp-registrant-list.css',
        array(),
        '3.0.0'
    );

    wp_enqueue_script(
        'spp-registrant-list',
        get_stylesheet_directory_uri() . '/js/spp-registrant-list.js',
        array( 'jquery' ),
        '3.0.0',
        true
    );
    wp_localize_script(
        'spp-registrant-list',
        'sppRL',
        array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
    );
}

// ============================================================
// Helper: build compose form HTML
// ============================================================
function spp_build_compose_form( $type, $label, $emails, $subject_default, $btn_color = '' ) {
    $emails_json = esc_attr( json_encode( $emails ) );
    $subj_js     = esc_js( $subject_default );
    $bcc_list    = implode( ', ', $emails );
    $style       = $btn_color ? ' style="background-color:' . esc_attr( $btn_color ) . ';"' : '';
    $count       = count( $emails );

    $html  = '<div class="spp-registrant-actions">';
    $html .= '<button type="button" class="spp-email-all-btn"' . $style . ' '
           . "onclick=\"sppShowCompose('{$type}', {$emails_json}, '{$subj_js}')\">"
           . '&#9993; Email ' . esc_html( $label ) . ' (' . $count . ')</button>';
    $html .= ' <button type="button" class="spp-email-selected-btn"' . $style . ' '
           . "onclick=\"sppEmailSelected('{$type}', '{$subj_js}')\">"
           . '&#9993; Email Selected</button>';
    if ( $type === 'confirmed' ) {
        $html .= ' <button type="button" class="spp-csv-btn" onclick="sppExportCSV()">&#8681; Export to CSV</button>';
    }
    $html .= '</div>';

    $html .= '<div id="spp-compose-' . $type . '" class="spp-compose-form" style="display:none;">';
    $html .= '<div class="spp-compose-inner">';
    $html .= '<h4 class="spp-compose-title">&#9993; Email ' . esc_html( $label ) . '</h4>';

    $html .= '<label class="spp-compose-label">Subject</label>';
    $html .= '<input type="text" id="spp-subject-' . $type . '" class="spp-compose-subject" value="' . esc_attr( $subject_default ) . '">';

    $html .= '<label class="spp-compose-label">Recipients (BCC) <span class="spp-compose-hint">-- edit to add or remove addresses</span></label>';
    $html .= '<textarea id="spp-bcc-' . $type . '" class="spp-compose-bcc" rows="3">' . esc_textarea( $bcc_list ) . '</textarea>';

    $html .= '<label class="spp-compose-label">Message</label>';
    $html .= '<div class="spp-editor-toolbar">';
    $html .= '<button type="button" onclick="sppFormat(\'bold\')" title="Bold"><strong>B</strong></button>';
    $html .= '<button type="button" onclick="sppFormat(\'italic\')" title="Italic"><em>I</em></button>';
    $html .= '<button type="button" onclick="sppFormat(\'underline\')" title="Underline"><u>U</u></button>';
    $html .= '<span class="spp-toolbar-sep"></span>';
    $html .= '<button type="button" onclick="sppFormat(\'insertUnorderedList\')" title="Bullet list">&#8226; List</button>';
    $html .= '<button type="button" onclick="sppFormat(\'insertOrderedList\')" title="Numbered list">1. List</button>';
    $html .= '<span class="spp-toolbar-sep"></span>';
    $html .= '<button type="button" onclick="sppInsertLink(\'' . $type . '\')" title="Insert link">&#128279; Link</button>';
    $html .= '</div>';
    $html .= '<div id="spp-message-' . $type . '" class="spp-compose-message" contenteditable="true" '
           . 'data-placeholder="Type your message here..."></div>';

    $html .= '<div class="spp-compose-actions">';
    $html .= '<button type="button" class="spp-send-btn"' . $style . ' '
           . "onclick=\"sppSendEmail('{$type}')\">&#9993; Send Now</button>";
    $html .= " <button type=\"button\" class=\"spp-cancel-btn\" onclick=\"sppHideCompose('{$type}')\">Cancel</button>";
    $html .= '</div>';
    $html .= '<div id="spp-send-status-' . $type . '" class="spp-send-status"></div>';
    $html .= '</div></div>';

    return $html;
}

// ============================================================
// Helper: render a registrant table with optional checkboxes
// ============================================================
function spp_render_registrant_table( $rows, $extra_class = '', $show_rating = false, $show_checkbox = false, $show_withdrawn_date = false ) {
    $class = 'spp-registrant-table' . ( $extra_class ? ' ' . $extra_class : '' );
    $html  = '<table class="' . $class . '">';
    $html .= '<thead><tr>';
    if ( $show_checkbox ) $html .= '<th class="spp-col-check"><input type="checkbox" onclick="sppToggleAll(this)" title="Select all"></th>';
    $html .= '<th>#</th><th>Name</th><th>Email</th>';
    if ( $show_rating ) $html .= '<th>Rating</th>';
    elseif ( $show_withdrawn_date ) $html .= '<th>Withdrawn</th>';
    else $html .= '<th>Phone</th>';
    $html .= '</tr></thead><tbody>';

    foreach ( $rows as $i => $r ) {
        $row_class = ( $i % 2 === 0 ) ? 'spp-row-even' : 'spp-row-odd';
        $email = ! empty( $r->user_email ) ? $r->user_email : '';
        $html .= '<tr class="' . $row_class . '">';
        if ( $show_checkbox ) {
            $html .= '<td class="spp-col-check"><input type="checkbox" class="spp-email-check" value="' . esc_attr( $email ) . '"></td>';
        }
        $html .= '<td class="spp-col-num">'   . ( $i + 1 ) . '</td>';
        $name  = isset( $r->display_name ) ? $r->display_name
               : ( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
        $html .= '<td class="spp-col-name">'  . esc_html( trim( $name ) ) . '</td>';
        $html .= '<td class="spp-col-email">' . ( $email ? esc_html( $email ) : '&mdash;' ) . '</td>';
        if ( $show_rating ) {
            $html .= '<td class="spp-col-rating">' . ( ! empty( $r->Rating ) ? esc_html( $r->Rating ) : '&mdash;' ) . '</td>';
        } elseif ( $show_withdrawn_date ) {
            $wd = ! empty( $r->withdrawn_at ) ? date( 'M j, g:ia', strtotime( $r->withdrawn_at ) ) : '&mdash;';
            $html .= '<td class="spp-col-withdrawn">' . $wd . '</td>';
        } else {
            $html .= '<td class="spp-col-phone">' . ( ! empty( $r->user_phone ) ? esc_html( $r->user_phone ) : '&mdash;' ) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

// ============================================================
// Shortcode - renders the dropdown shell + send to group section
// ============================================================
add_shortcode( 'spp_registrant_list', 'spp_registrant_list_shortcode' );
function spp_registrant_list_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '';

    $atts = shortcode_atts( array(
        'filter' => '',
    ), $atts, 'spp_registrant_list' );

    global $wpdb;
    $p = $wpdb->prefix;

    // ── Build event dropdown from gl_event_occurrences + gl_registrations ─────
    $filter_sql = '';
    if ( ! empty( $atts['filter'] ) ) {
        $like = '%' . $wpdb->esc_like( $atts['filter'] ) . '%';
        $filter_sql = $wpdb->prepare( "AND o.title LIKE %s", $like );
    }

    $events = $wpdb->get_results( "
        SELECT
            o.id AS occurrence_id,
            o.series_id,
            o.title,
            o.event_date,
            o.event_time,
            COUNT(DISTINCT r.user_id) AS reg_count,
            s_counts.total_occs
        FROM {$p}gl_event_occurrences o
        JOIN {$p}gl_registrations r ON r.occurrence_id = o.id AND r.status = 'confirmed'
        JOIN (
            SELECT series_id, COUNT(*) AS total_occs
            FROM {$p}gl_event_occurrences
            GROUP BY series_id
        ) s_counts ON s_counts.series_id = o.series_id
        WHERE o.event_date >= CURDATE()
        AND o.cancelled = 0
        {$filter_sql}
        GROUP BY o.id
        HAVING reg_count > 0
        ORDER BY s_counts.total_occs DESC, o.event_date ASC, o.event_time ASC
    " );

    $recurring = array();
    $oneoff    = array();
    foreach ( (array) $events as $ev ) {
        if ( (int) $ev->total_occs > 1 ) $recurring[] = $ev;
        else $oneoff[] = $ev;
    }

    // ── Event dropdown ─────────────────────────────────────
    $out  = '<div class="spp-registrant-list-wrap">';
    $out .= '<div class="spp-section-heading">Event Registrants</div>';
    $out .= '<div class="spp-registrant-dropdown-wrap">';
    $out .= '<label for="spp-event-select" class="spp-registrant-label">Select Event:</label>';
    $out .= '<select id="spp-event-select" class="spp-registrant-select">';
    $out .= '<option value="">-- Choose an event --</option>';

    if ( ! empty( $recurring ) ) {
        $out .= '<optgroup label="Recurring Events">';
        foreach ( $recurring as $ev ) {
            $dt = $ev->event_date . ' ' . $ev->event_time;
            $label = esc_html( $ev->title )
                   . ' - ' . date( 'M j, Y g:i a', strtotime( $dt ) )
                   . ' (' . (int) $ev->reg_count . ' registered)';
            $out .= '<option value="' . (int) $ev->occurrence_id . '"'
                  . ' data-title="' . esc_attr( $ev->title ) . '"'
                  . ' data-date="' . esc_attr( date( 'l, F j, Y g:i a', strtotime( $dt ) ) ) . '">'
                  . $label . '</option>';
        }
        $out .= '</optgroup>';
    }

    if ( ! empty( $oneoff ) ) {
        $out .= '<optgroup label="Single Events">';
        foreach ( $oneoff as $ev ) {
            $dt = $ev->event_date . ' ' . $ev->event_time;
            $label = esc_html( $ev->title )
                   . ' - ' . date( 'M j, Y g:i a', strtotime( $dt ) )
                   . ' (' . (int) $ev->reg_count . ' registered)';
            $out .= '<option value="' . (int) $ev->occurrence_id . '"'
                  . ' data-title="' . esc_attr( $ev->title ) . '"'
                  . ' data-date="' . esc_attr( date( 'l, F j, Y g:i a', strtotime( $dt ) ) ) . '">'
                  . $label . '</option>';
        }
        $out .= '</optgroup>';
    }

    $out .= '</select>';
    $out .= '</div>';
    $out .= '<div id="spp-registrant-results" class="spp-registrant-results"></div>';

    // ── Send to Group section ──────────────────────────────
    $out .= '<div class="spp-group-section">';
    $out .= '<div class="spp-section-heading">Send to Group</div>';
    $out .= '<div class="spp-registrant-dropdown-wrap">';
    $out .= '<label for="spp-group-select" class="spp-registrant-label">Select Group:</label>';
    $out .= '<select id="spp-group-select" class="spp-registrant-select" onchange="sppGroupChanged(this.value)">';
    $out .= '<option value="">-- Choose a group --</option>';
    $out .= '<option value="ladder">All Ladder Players (Master list)</option>';
    $out .= '<option value="members">All Members (Membership list)</option>';
    $out .= '</select>';
    $out .= '</div>';

    // Filter panel
    $out .= '<div id="spp-group-filters" class="spp-group-filters" style="display:none;">';
    $out .= '<div class="spp-filter-row">';

    $out .= '<div class="spp-filter-field">';
    $out .= '<label class="spp-filter-label">Rating</label>';
    $out .= '<select id="spp-filter-rating" class="spp-filter-select">';
    $out .= '<option value="">Any rating</option>';
    $out .= '<option value="beginner">Beginner</option>';
    $out .= '<option value="2.0">2.0 and below</option>';
    $out .= '<option value="2.5">2.5</option>';
    $out .= '<option value="3.0">3.0</option>';
    $out .= '<option value="3.5">3.5</option>';
    $out .= '<option value="4.0">4.0</option>';
    $out .= '<option value="4.5">4.5 and above</option>';
    $out .= '</select>';
    $out .= '</div>';

    $out .= '<div class="spp-filter-field" id="spp-ladder-filter-wrap">';
    $out .= '<label class="spp-filter-label">Ladder</label>';
    $out .= '<select id="spp-filter-ladder" class="spp-filter-select">';
    $out .= '<option value="">Any</option>';
    $out .= '<option value="yes">Ladder players only</option>';
    $out .= '<option value="no">Non-ladder only</option>';
    $out .= '</select>';
    $out .= '</div>';

    $out .= '</div>';

    $out .= '<div class="spp-filter-actions">';
    $out .= '<button type="button" class="spp-filter-apply-btn" onclick="sppApplyGroupFilter()">Apply Filter</button>';
    $out .= ' <button type="button" class="spp-filter-clear-btn" onclick="sppClearGroupFilter()">Clear</button>';
    $out .= '</div>';
    $out .= '</div>';

    $out .= '<div id="spp-group-results" class="spp-registrant-results"></div>';
    $out .= '</div>';
    $out .= '</div>';

    return $out;
}

// ============================================================
// AJAX handler - returns registrant list HTML for event
// ============================================================
add_action( 'wp_ajax_spp_get_registrants', 'spp_get_registrants_ajax' );
function spp_get_registrants_ajax() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in' );

    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
    if ( ! $event_id ) wp_send_json_error( 'No event ID' );

    $title           = isset( $_POST['event_title'] ) ? sanitize_text_field( $_POST['event_title'] ) : '';
    $date            = isset( $_POST['event_date'] )  ? sanitize_text_field( $_POST['event_date'] )  : '';
    $subject_default = $title . ' - ' . $date;

    global $wpdb;
    $p = $wpdb->prefix;

    // ── Confirmed registrants ────────────────────────────────────────────────
    $registrants = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.user_id, m.first_name, m.last_name, m.user_email, m.user_phone, r.registered_at
         FROM {$p}gl_registrations r
         JOIN membership m ON m.user_id = r.user_id
         WHERE r.occurrence_id = %d AND r.status = 'confirmed'
         ORDER BY m.last_name, m.first_name",
        $event_id
    ) );

    // ── Waiting list ─────────────────────────────────────────────────────────
    $waiting = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.user_id, m.first_name, m.last_name, m.user_email, m.user_phone, r.registered_at
         FROM {$p}gl_registrations r
         JOIN membership m ON m.user_id = r.user_id
         WHERE r.occurrence_id = %d AND r.status = 'waiting'
         ORDER BY r.registered_at ASC",
        $event_id
    ) );

    // ── Withdrawn ────────────────────────────────────────────────────────────
    $withdrawn = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.user_id, m.first_name, m.last_name, m.user_email, m.user_phone,
                r.status, r.updated_at AS withdrawn_at
         FROM {$p}gl_registrations r
         JOIN membership m ON m.user_id = r.user_id
         WHERE r.occurrence_id = %d AND r.status IN ('withdrawn', 'late_withdrawn')
         ORDER BY r.updated_at DESC",
        $event_id
    ) );

    if ( empty( $registrants ) && empty( $waiting ) && empty( $withdrawn ) ) {
        wp_send_json_success( array( 'html' => '<p class="spp-registrant-none">No registrants yet.</p>' ) );
    }

    $confirmed_emails = array_map( fn($r) => $r->user_email, $registrants );
    $waiting_emails   = array_map( fn($r) => $r->user_email, $waiting );

    $html  = '<div class="spp-registrant-header">';
    $html .= '<h3 class="spp-registrant-title">' . esc_html( $title ) . '</h3>';
    $html .= '<p class="spp-registrant-date">' . esc_html( $date ) . '</p>';
    $html .= '</div>';

    // ── Confirmed section ────────────────────────────────────────────────────
    if ( ! empty( $registrants ) ) {
        $html .= spp_build_compose_form( 'confirmed', 'All ' . count( $registrants ) . ' Confirmed Registrants', $confirmed_emails, $subject_default );
        $html .= spp_render_registrant_table( $registrants, '', false, true );
        $html .= '<p class="spp-registrant-count">' . count( $registrants ) . ' confirmed registrant' . ( count( $registrants ) !== 1 ? 's' : '' ) . '</p>';
    }

    // ── Waiting section ──────────────────────────────────────────────────────
    if ( ! empty( $waiting ) ) {
        $html .= '<div class="spp-waiting-header" style="margin-top:24px;">';
        $html .= '<h4 class="spp-waiting-title" style="color:#c0392b;margin-bottom:6px;">Waiting List</h4>';
        $html .= '</div>';
        $html .= spp_build_compose_form( 'waiting', 'All ' . count( $waiting ) . ' Waiting', $waiting_emails, $subject_default, '#c0392b' );
        $html .= spp_render_registrant_table( $waiting, 'spp-waiting-table', false, true );
        $html .= '<p class="spp-registrant-count" style="color:#c0392b;">' . count( $waiting ) . ' on waiting list</p>';
    }

    // ── Withdrawn section ────────────────────────────────────────────────────
    if ( ! empty( $withdrawn ) ) {
        $html .= '<div class="spp-withdrawn-header" style="margin-top:24px;">';
        $html .= '<h4 class="spp-withdrawn-title" style="color:#888;margin-bottom:6px;">Withdrawn (' . count( $withdrawn ) . ')</h4>';
        $html .= '</div>';
        $html .= spp_render_registrant_table( $withdrawn, 'spp-withdrawn-table', false, false, true );
    }

    wp_send_json_success( array( 'html' => $html ) );
}

// ============================================================
// AJAX handler - returns group email list with filters
// ============================================================
add_action( 'wp_ajax_spp_get_group_emails', 'spp_get_group_emails_ajax' );
function spp_get_group_emails_ajax() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in' );

    $group  = isset( $_POST['group'] )  ? sanitize_text_field( $_POST['group'] )  : '';
    $rating = isset( $_POST['rating'] ) ? sanitize_text_field( $_POST['rating'] ) : '';
    $ladder = isset( $_POST['ladder'] ) ? sanitize_text_field( $_POST['ladder'] ) : '';

    if ( ! in_array( $group, array( 'ladder', 'members' ) ) ) wp_send_json_error( 'Invalid group' );

    global $wpdb;

    $where = array( "user_email != ''" );

    if ( ! empty( $rating ) ) {
        if ( $rating === 'beginner' ) {
            $where[] = "Rating = 'Beginner'";
        } elseif ( $rating === '2.0' ) {
            $where[] = "(LOWER(Rating) != 'beginner' AND CAST(Rating AS DECIMAL(3,1)) <= 2.0)";
        } elseif ( $rating === '4.5' ) {
            $where[] = "(LOWER(Rating) != 'beginner' AND CAST(Rating AS DECIMAL(3,1)) >= 4.5)";
        } else {
            $val = (float) $rating;
            $where[] = $wpdb->prepare( "(LOWER(Rating) != 'beginner' AND CAST(Rating AS DECIMAL(3,1)) = %f)", $val );
        }
    }

    if ( $group === 'ladder' ) {
        $table = 'Master';
        $label = 'All Ladder Players';
    } else {
        $table = 'membership';
        $label = 'All Members';
        if ( $ladder === 'yes' ) {
            $where[] = "Ladder = 'Yes'";
        } elseif ( $ladder === 'no' ) {
            $where[] = "(Ladder IS NULL OR Ladder != 'Yes')";
        }
    }

    $where_sql = 'WHERE ' . implode( ' AND ', $where );
    $rows = $wpdb->get_results( "SELECT first_name, last_name, user_email, Rating FROM {$table} {$where_sql} ORDER BY last_name, first_name" );

    $filter_desc = array();
    if ( ! empty( $rating ) ) {
        $rating_labels = array(
            'beginner' => 'Beginner',
            '2.0'      => '2.0 and below',
            '2.5'      => '2.5',
            '3.0'      => '3.0',
            '3.5'      => '3.5',
            '4.0'      => '4.0',
            '4.5'      => '4.5 and above',
        );
        $filter_desc[] = 'Rating: ' . ( $rating_labels[ $rating ] ?? $rating );
    }
    if ( ! empty( $ladder ) && $group === 'members' ) {
        $filter_desc[] = 'Ladder: ' . ( $ladder === 'yes' ? 'Yes only' : 'Non-ladder only' );
    }

    if ( empty( $rows ) ) {
        wp_send_json_success( array( 'html' => '<p class="spp-registrant-none">No members match the selected filters.</p>' ) );
    }

    $emails     = array_map( fn($r) => $r->user_email, $rows );
    $type_label = $label . ( ! empty( $filter_desc ) ? ' (' . implode( ', ', $filter_desc ) . ')' : '' );

    $html  = '<div class="spp-registrant-header">';
    $html .= '<h3 class="spp-registrant-title">' . esc_html( $label ) . '</h3>';
    if ( ! empty( $filter_desc ) ) {
        $html .= '<p class="spp-registrant-date">Filters: ' . esc_html( implode( ' | ', $filter_desc ) ) . '</p>';
    }
    $html .= '<p class="spp-registrant-date">' . count( $emails ) . ' recipients</p>';
    $html .= '</div>';

    $html .= spp_build_compose_form( 'group_' . $group, esc_html( $type_label ), $emails, 'SPP Announcement' );
    $html .= spp_render_registrant_table( $rows, '', true );
    $html .= '<p class="spp-registrant-count">' . count( $emails ) . ' recipient' . ( count( $emails ) !== 1 ? 's' : '' ) . '</p>';

    wp_send_json_success( array( 'html' => $html ) );
}

// ============================================================
// AJAX handler - sends HTML email via wp_mail / Fluent SMTP
// ============================================================
add_action( 'wp_ajax_spp_send_registrant_email', 'spp_send_registrant_email_ajax' );
function spp_send_registrant_email_ajax() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in' );

    $subject = isset( $_POST['subject'] ) ? sanitize_text_field( $_POST['subject'] )    : '';
    $message = isset( $_POST['message'] ) ? wp_kses_post( $_POST['message'] )            : '';
    $bcc_raw = isset( $_POST['bcc'] )     ? sanitize_textarea_field( $_POST['bcc'] )     : '';

    if ( empty( $subject ) || empty( $message ) || empty( $bcc_raw ) ) {
        wp_send_json_error( 'Missing subject, message, or recipients.' );
    }

    $emails = array_filter( array_map( 'sanitize_email',
        preg_split( '/[\s,]+/', $bcc_raw )
    ) );

    if ( empty( $emails ) ) wp_send_json_error( 'No valid email addresses.' );

    $current_user = wp_get_current_user();
    $sender_name  = $current_user->display_name ?: 'SPP Convenor';

    $html_body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;max-width:620px;margin:0 auto;">'
               . '<p>' . $message . '</p>'
               . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
               . '<p style="font-size:12px;color:#888;">&mdash; ' . esc_html( $sender_name ) . '<br>Stouffville Pickleball Players</p>'
               . '</body></html>';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    $sent = $failed = 0;
    foreach ( $emails as $email ) {
        $ok = wp_mail( $email, $subject, $html_body, $headers );
        if ( $ok ) $sent++; else $failed++;
    }

    if ( $failed === 0 ) {
        wp_send_json_success( array( 'message' => "\xe2\x9c\x93 Email sent to {$sent} recipient" . ( $sent !== 1 ? 's' : '' ) . '.' ) );
    } else {
        wp_send_json_success( array( 'message' => "\xe2\x9a\xa0 Sent to {$sent}, failed for {$failed} recipient" . ( $failed !== 1 ? 's' : '' ) . '.' ) );
    }
}