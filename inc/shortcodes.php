<?php
/* =========================================================
   SPP CUSTOM SHORTCODES
   Site: pickleballstouffville.ca (staging)
   
   Shortcodes:
   - [spp_dashboard]  — home page dashboard widget
   ========================================================= */

/* =========================================================
   [spp_dashboard]
   Home page dashboard — shows schedule and rank for ladder
   members, welcome message for guests and non-ladder members.
   ========================================================= */
function spp_dashboard_shortcode() {
    global $wpdb;

    $output = '<div class="spp-dashboard">';

    if (!is_user_logged_in()) {
        // -----------------------------------------------
        // Logged out — welcome message
        // -----------------------------------------------
        $output .= '
        <div class="spp-dashboard-welcome">
            <h2>Welcome to Stouffville Pickleball Players</h2>
            <p>We are a pickleball club based in Stouffville, Ontario with over 400 active members.</p>
            <p>Please <a href="/login/">login</a> or 
            <a href="https://www.pickleballcanada.org" target="_blank">register with Pickleball Canada</a> 
            to access member features.</p>
        </div>';

    } else {
        // -----------------------------------------------
        // Logged in — get user data
        // -----------------------------------------------
        $current_user = wp_get_current_user();
        $user_id      = $current_user->ID;
        $first_name   = $current_user->first_name;
        $last_name    = $current_user->last_name;

        $output .= '<div class="spp-dashboard-header">';
        $output .= '<h2>Welcome back, ' . esc_html($first_name) . ' ' . esc_html($last_name) . '!</h2>';
        $output .= '</div>';

        // Check ladder eligibility
        $ladder       = get_user_meta($user_id, 'Ladder',   true);
        $yr_end_dt    = get_user_meta($user_id, 'YrEndDt',  true);
        $rank         = get_user_meta($user_id, 'Rank',     true);
        $current_year = date('Y');

        $is_ladder  = ($ladder === 'Yes');
        $is_current = (!empty($yr_end_dt) && $yr_end_dt >= $current_year . '-12-31');

        if ($is_ladder && $is_current) {
            // -----------------------------------------------
            // Active ladder member — show rank
            // -----------------------------------------------
            $output .= '<div class="spp-dashboard-rank">';
            $output .= '<p>Your current ladder rank: <strong>';
            $output .= ($rank > 0) ? esc_html($rank) : 'To be determined';
            $output .= '</strong></p>';
            $output .= '</div>';

            // -----------------------------------------------
            // Check if a schedule has been published
            // -----------------------------------------------
            $event = get_option('spp_current_event');

            $output .= '<div class="spp-dashboard-schedule">';

            if (!$event) {
                $output .= '<p class="spp-dashboard-notice">No schedule published yet for this week.</p>';
            } else {
                // Query for current user in schedules_w view
                $schedule = $wpdb->get_row($wpdb->prepare("
                    SELECT w.Rank, w.GP_name, w.Crt_name, w.T_desc
                    FROM schedules_w w
                    WHERE w.user_id = %d
                    LIMIT 1
                ", $user_id));

                if (!$schedule) {
                    $output .= '<p class="spp-dashboard-notice">You are not on this week\'s schedule.</p>';
                } else {
                    $output .= '<h3>This Week\'s Schedule</h3>';
                    $output .= '<table class="spp-dashboard-table">';
                    $output .= '<tr><th>Group</th><td>'       . esc_html($schedule->GP_name)  . '</td></tr>';
                    $output .= '<tr><th>Court</th><td>'       . esc_html($schedule->Crt_name) . '</td></tr>';
                    $output .= '<tr><th>Time</th><td>'        . esc_html($schedule->T_desc)   . '</td></tr>';
                    $output .= '<tr><th>Ladder Rank</th><td>' . esc_html($schedule->Rank)     . '</td></tr>';
                    $output .= '</table>';
                }
            }

            $output .= '</div>';

        } elseif ($is_ladder && !$is_current) {
            // -----------------------------------------------
            // Ladder member but expired membership
            // -----------------------------------------------
            $output .= '<p class="spp-dashboard-notice">Your membership has expired. Please renew with 
                <a href="https://www.pickleballcanada.org" target="_blank">Pickleball Canada</a> 
                to access ladder features.</p>';
        }
        // Non-ladder members see only the welcome message — nothing extra needed
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('spp_dashboard', 'spp_dashboard_shortcode');

/* =========================================================
   SYNC EMAIL CHANGE TO USERMETA
   When a user updates their email via UM Account form,
   also update the user_email meta key in usermeta.
   ========================================================= */
add_action('um_user_edit_profile', function($args) {
    if (!isset($args['user_email'])) return;
    
    $user_id = get_current_user_id();
    if (!$user_id) return;
    
    update_user_meta($user_id, 'user_email', sanitize_email($args['user_email']));
}, 10, 1);

// Also hook into standard WordPress email change
add_action('profile_update', function($user_id, $old_user_data) {
    $user = get_userdata($user_id);
    if (!$user) return;
    
    if ($user->user_email !== $old_user_data->user_email) {
        update_user_meta($user_id, 'user_email', $user->user_email);
    }
}, 10, 2);
/* =========================================================
   [spp_pending_posts]
   Pending posts list for blog_moderators.
   Shows pending posts with edit/publish/delete links.
   ========================================================= */
function spp_pending_posts_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="/login/">login</a> to access this page.</p>';
    }

    if ( ! current_user_can( 'publish_posts' ) ) {
        return '<p>You do not have permission to view this page.</p>';
    }

    $posts = get_posts( [
        'post_status'    => 'pending',
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    if ( empty( $posts ) ) {
        return '<div class="spp-pending-posts"><p>No posts pending review.</p></div>';
    }

    $output = '<div class="spp-pending-posts">';
    $output .= '<h3>Posts Pending Review</h3>';
    $output .= '<table class="spp-dashboard-table">';
    $output .= '<tr><th>Title</th><th>Author</th><th>Date</th><th>Actions</th></tr>';

    foreach ( $posts as $post ) {
        $author   = get_userdata( $post->post_author );
        $edit_url = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
        $output  .= '<tr>';
        $output  .= '<td>' . esc_html( $post->post_title ) . '</td>';
        $output  .= '<td>' . esc_html( $author->display_name ) . '</td>';
        $output  .= '<td>' . get_the_date( 'M j, Y', $post ) . '</td>';
        $output  .= '<td><a href="' . esc_url( $edit_url ) . '">Review</a></td>';
        $output  .= '</tr>';
    }

    $output .= '</table>';
    $output .= '</div>';

    return $output;
}

add_shortcode( 'spp_pending_posts', 'spp_pending_posts_shortcode' );

add_shortcode('spp_events', function($atts) {
    $atts = shortcode_atts(['category' => ''], $atts);
    $cat_slug = $atts['category'];

    // Fall back to query detection if no category passed
    if (!$cat_slug) {
        global $wp_query;
        if (!empty($wp_query->query['tribe_events_cat'])) {
            $cat_slug = $wp_query->query['tribe_events_cat'];
        } elseif (tribe_is_event_category()) {
            $obj = get_queried_object();
            if ($obj && isset($obj->slug)) {
                $cat_slug = $obj->slug;
            }
        }
    }

    if ($cat_slug) {
        return do_shortcode('[tribe_events view="list" category="' . esc_attr($cat_slug) . '"]');
    }
    return do_shortcode('[tribe_events view="list"]');
});
/* =========================================================
   [spp_event_registrations]
   Shows all upcoming events with registration counts.
   ========================================================= */
   add_shortcode('spp_event_registrations', function() {
    global $wpdb;
    $p = $wpdb->prefix;

    $rows = $wpdb->get_results("
        SELECT
            o.occurrence_id,
            o.post_id,
            o.start_date,
            p.post_title,
            MIN(t.name) AS category,
            pm_max.meta_value AS max_registrations,
            pm_limit.meta_value AS limit_registrations,
            COUNT(DISTINCT CASE WHEN latest.status = 'confirmed'    THEN latest.user_id END) AS confirmed,
            COUNT(DISTINCT CASE WHEN latest.status = 'waiting'      THEN latest.user_id END) AS waiting,
            COUNT(DISTINCT CASE WHEN latest.status = 'pending'      THEN latest.user_id END) AS pending,
            COUNT(DISTINCT CASE WHEN latest.status = 'unregistered' THEN latest.user_id END) AS unregistered
        FROM {$p}tec_occurrences o
        JOIN {$p}posts p ON o.post_id = p.ID
        JOIN {$p}term_relationships tr ON o.post_id = tr.object_id
        JOIN {$p}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'tribe_events_cat'
        JOIN {$p}terms t ON tt.term_id = t.term_id
        LEFT JOIN {$p}postmeta pm_max   ON o.post_id = pm_max.post_id   AND pm_max.meta_key   = '_RTECmaxRegistrations'
        LEFT JOIN {$p}postmeta pm_limit ON o.post_id = pm_limit.post_id AND pm_limit.meta_key = '_RTEClimitRegistrations'
        LEFT JOIN (
            SELECT event_id, user_id, status
            FROM {$p}rtec_entries e1
            WHERE id = (
                SELECT MAX(id) FROM {$p}rtec_entries e2
                WHERE e2.event_id = e1.event_id
                AND e2.user_id = e1.user_id
            )
        ) latest ON (o.post_id = latest.event_id OR o.occurrence_id + 30000000 = latest.event_id)
        WHERE o.start_date >= NOW()
        AND o.start_date <= DATE_ADD(NOW(), INTERVAL 5 WEEK)
        AND p.post_status = 'publish'
        GROUP BY o.occurrence_id
        ORDER BY o.start_date ASC
    ");

    // Build unique category list
    $categories = [];
    foreach ($rows as $row) {
        $categories[$row->category] = $row->category;
    }
    ksort($categories);

    $out  = '<div class="spp-event-registrations">';

    // Dropdown
    $out .= '<div style="margin-bottom:1rem;">';
    $out .= '<label for="spp-cat-filter" style="font-weight:600; margin-right:0.5rem;">Category:</label>';
    $out .= '<select id="spp-cat-filter" onchange="sppFilterEvents(this.value)">';
    $out .= '<option value="">All</option>';
    foreach ($categories as $cat) {
        $out .= '<option value="' . esc_attr($cat) . '">' . esc_html($cat) . '</option>';
    }
    $out .= '</select>';
    $out .= '</div>';

    $out .= '<table class="spp-dashboard-table" id="spp-events-table">';
    $out .= '<thead><tr>';
    $out .= '<th>Date</th><th>Event</th>';
    $out .= '<th>Confirmed</th><th>Max</th><th>Queue</th><th>?</th><th>Dropped</th>';
    $out .= '</tr></thead><tbody>';

    if (empty($rows)) {
        $out .= '<tr><td colspan="7" style="text-align:center;font-style:italic;">No upcoming events found.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $date         = date('M j, Y g:i a', strtotime($row->start_date));
            $limit        = ($row->limit_registrations == '1');
            $capacity     = $limit ? (int)$row->max_registrations : '∞';
            $confirmed    = (int)$row->confirmed;
            $waiting      = (int)$row->waiting;
            $pending      = (int)$row->pending;
            $unregistered = (int)$row->unregistered;
            $full         = $limit && $confirmed >= (int)$row->max_registrations;
            $row_class    = $full ? 'spp-event-full' : '';

            $out .= '<tr class="' . $row_class . '" data-category="' . esc_attr($row->category) . '">';
            $out .= '<td>' . esc_html($date) . '</td>';
            $out .= '<td>' . esc_html($row->post_title) . '</td>';
            $out .= '<td>' . $confirmed . '</td>';
            $out .= '<td>' . $capacity . '</td>';
            $out .= '<td>' . $waiting . '</td>';
            $out .= '<td>' . $pending . '</td>';
            $out .= '<td>' . $unregistered . '</td>';
            $out .= '</tr>';
        }
    }

    $out .= '</tbody></table></div>';

    $out .= '<script>
    function sppFilterEvents(cat) {
        var rows = document.querySelectorAll("#spp-events-table tbody tr");
        rows.forEach(function(row) {
            if (!cat || row.getAttribute("data-category") === cat) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
    }
    </script>';

    return $out;
});