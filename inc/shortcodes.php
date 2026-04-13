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
            // Check if Schedules table exists
            // -----------------------------------------------
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE 'Schedules'");

            $output .= '<div class="spp-dashboard-schedule">';

            if (!$table_exists) {
                $output .= '<p class="spp-dashboard-notice">No schedule published yet for this week.</p>';
            } else {
                // Query for current user in schedule
                $schedule = $wpdb->get_row($wpdb->prepare("
                    SELECT s.Rank, s.group_id, s.Crt_ID, s.time_id,
                           g.GP_name, c.Crt_name, t.T_desc
                    FROM Schedules s
                    JOIN Groups g ON s.group_id  = g.GP_ID
                    JOIN Courts c ON s.Crt_ID    = c.Crt_ID
                    JOIN Times  t ON s.time_id   = t.T_ID
                    WHERE s.user_id = %d
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
