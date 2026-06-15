<?php
/* =========================================================
   Enter Scores Menu Visibility
   Version: 1.0.0
   Date: 2026-06-15

   Controls visibility of the /enter-scores/ menu item:
   - Admin, editor, ladder-cop, convenor: visible once
     schedule is published (no time gate).
   - Subscribers: visible only when spp_schedule_published = 1
     AND their group's start time has passed (event_date +
     Times.T_desc for the player's group).
   - Not logged in: hidden.
   ========================================================= */

add_filter('wp_nav_menu_objects', function($items) {
    global $wpdb;
    $prefix = $wpdb->prefix;

    $user = wp_get_current_user();
    $privileged_roles = array('administrator', 'editor', 'ladder-cop');
    $is_privileged = !empty(array_intersect($privileged_roles, (array) $user->roles));

    if (!$is_privileged && $user->ID > 0) {
        $is_convenor = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$prefix}gl_event_occurrences WHERE convenor_id = %d LIMIT 1",
            $user->ID
        ));
        if ($is_convenor) { $is_privileged = true; }
    }

    foreach ($items as $key => $item) {
        if (strpos($item->url, '/enter-scores') === false) continue;

        // Must be logged in
        if (!is_user_logged_in()) {
            unset($items[$key]);
            continue;
        }

        // Must have a published schedule
        if ((int) get_option('spp_schedule_published', 0) !== 1) {
            unset($items[$key]);
            continue;
        }

        // Privileged users: visible as soon as schedule is published
        if ($is_privileged) continue;

        // Subscribers: require their group's start time to have passed

        // Get player's group time from the schedule
        $player_time = $wpdb->get_row($wpdb->prepare(
            "SELECT t.T_desc
             FROM Schedules s
             JOIN Times t ON s.time_id = t.T_ID
             WHERE s.user_id = %d AND s.group_id != 99
             LIMIT 1",
            $user->ID
        ));
        if (!$player_time) { unset($items[$key]); continue; }

        // Get current event date
        $Event = (int) $wpdb->get_var(
            "SELECT page_sequence FROM {$prefix}wpda_project_page
             WHERE project_id = 29 AND page_id = 70"
        );
        if (!$Event) { unset($items[$key]); continue; }

        $occ = $wpdb->get_row($wpdb->prepare(
            "SELECT event_date FROM {$prefix}gl_event_occurrences WHERE id = %d",
            $Event
        ));
        if (!$occ) { unset($items[$key]); continue; }

        // Combine event date with group time: "2026-06-15 5:30 pm"
        $group_start = strtotime($occ->event_date . ' ' . $player_time->T_desc);
        $now = current_time('timestamp');

        if ($now < $group_start) {
            unset($items[$key]);
        }
    }

    return $items;
}, 10, 1);
?>