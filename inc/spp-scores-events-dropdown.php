<?php
/* =========================================================
   Scores Events Dropdown
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Scores Events drop down" (CM273)

   PURPOSE:
   Renders the event-selector dropdown used on the scores-viewing
   pages. Builds its list from every Schedules_Scores_<event_id>
   table that exists (via information_schema), joined against
   gl_event_occurrences / event_date_lookup for a display date, plus
   (when the current schedule is published) the live current event
   prepended at the top. Gates future/current events to privileged
   users (administrator/editor/ladder-cop, or the event's own
   convenor) -- non-privileged users only see past events. On POST,
   resolves the selected event into $GLOBALS['Event'] /
   $GLOBALS['name'] for consumption by other page elements rendered
   after this one.

   CONFIRMED INDEPENDENT of the CM275/CM270/CM271 ("Ladder Events
   drop down" / full+short form) chain: this snippet builds its own
   event list entirely from Schedules_Scores_* / event_date_lookup /
   the live-event option, never reads or writes $all, and has its
   own two page callers (1948, 20006331) with zero overlap with
   page 20010224 ("GL Create Schedule", CM275's only caller).
   Verified fresh this migration, not assumed, per instruction.

   No usermeta or wildcard meta_key queries anywhere in this file --
   no wildcard audit applies. No INSERT/UPDATE/DELETE anywhere in
   this file either -- purely a SELECT + display + $GLOBALS-set
   snippet, same as CM275. $Event and $name are kept as real globals
   here, matching the original's behavior exactly, for consumption
   by other page elements rendered later in the same request.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Scores Events drop down'] (CM273, now a
     transition shim around this function): pages 1948 and
     20006331 -- both confirmed live callers, no theme-file caller.

   Changes from CM273: wrapped in a real function,
   spp_scores_events_dropdown(), instead of a bare top-level script.
   The dead $prefix-only-used-locally pattern is preserved as-is
   (still just a local convenience var for $wpdb->prefix). No other
   behavior change.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_scores_events_dropdown() {

    if ( session_status() !== PHP_SESSION_ACTIVE ) {
        session_start();
    }

    global $wpdb, $Event, $name;
    $prefix = $wpdb->prefix;
    $Event  = 0;
    $name   = '';

    // Role check: privileged users see future/current events
    $user = wp_get_current_user();
    $privileged_roles = array( 'administrator', 'editor', 'ladder-cop' );
    $is_privileged = ! empty( array_intersect( $privileged_roles, (array) $user->roles ) );

    if ( ! $is_privileged && $user->ID > 0 ) {
        $is_convenor = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$prefix}gl_event_occurrences WHERE convenor_id = %d LIMIT 1",
            $user->ID
        ) );
        if ( $is_convenor ) { $is_privileged = true; }
    }

    // ── Prepend live current event when schedule is published ─────────
    $current_event_id = (int) get_option( 'spp_current_event', 0 );
    $schedule_live     = (int) get_option( 'spp_schedule_published', 0 ) === 1;
    $live_event_entry  = null;

    if ( $current_event_id && $schedule_live ) {
        $live_occ = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_date, event_time FROM {$prefix}gl_event_occurrences WHERE id = %d",
            $current_event_id
        ), ARRAY_A );

        if ( $live_occ ) {
            $live_start = $live_occ['event_date'] . ' ' . $live_occ['event_time'];
            $now        = current_time( 'mysql' );
            $show       = $is_privileged || $now >= $live_start;

            if ( $show ) {
                $live_event_entry = array(
                    'event_id'           => $current_event_id,
                    'start_date'         => $live_start,
                    'event_date_display' => date_format( date_create( $live_start ), 'F d, Y g:i A' ) . ' (Live)',
                );
            }
        }
    }
    // ────────────────────────────────────────────────────────────────

    // Get all events that have a Schedules_Scores_ table
    $events = $wpdb->get_results( "
        SELECT
            CAST(REPLACE(t.table_name, 'Schedules_Scores_', '') AS UNSIGNED) AS event_id,
            COALESCE(
                CONCAT(geo.event_date, ' ', geo.event_time),
                edl.event_date
            ) AS start_date,
            COALESCE(
                DATE_FORMAT(CONCAT(geo.event_date, ' ', geo.event_time), '%M %d, %Y %l:%i %p'),
                DATE_FORMAT(edl.event_date, '%M %d, %Y %l:%i %p')
            ) AS event_date_display
        FROM information_schema.tables t
        LEFT JOIN {$prefix}gl_event_occurrences geo
            ON CAST(REPLACE(t.table_name, 'Schedules_Scores_', '') AS UNSIGNED) = geo.id
        LEFT JOIN event_date_lookup edl
            ON CAST(REPLACE(t.table_name, 'Schedules_Scores_', '') AS UNSIGNED) = edl.event_id
        WHERE t.table_schema = DATABASE()
          AND t.table_name REGEXP '^Schedules_Scores_[0-9]+$'
        ORDER BY COALESCE(CONCAT(geo.event_date, ' ', geo.event_time), edl.event_date) DESC
    ", ARRAY_A );

    // Prepend live event if not already in the snapshot list
    if ( $live_event_entry ) {
        $existing_ids = array_column( $events, 'event_id' );
        if ( ! in_array( $current_event_id, $existing_ids ) ) {
            array_unshift( $events, $live_event_entry );
        }
    }

    if ( empty( $events ) ) {
        echo '<p>No scored events found.</p>';
        return;
    }

    // Filter future/current events for non-privileged users
    if ( ! $is_privileged ) {
        $now = current_time( 'mysql' );
        $events = array_filter( $events, function( $e ) use ( $now ) {
            return ! empty( $e['start_date'] ) && $e['start_date'] < $now;
        } );
        $events = array_values( $events );
    }

    if ( empty( $events ) ) {
        echo '<p>No scored events available.</p>';
        return;
    }

    // Handle POST selection or default to most recent event
    if ( isset( $_POST['scores_event'] ) && intval( $_POST['scores_event'] ) > 0 ) {
        $Event = intval( $_POST['scores_event'] );
        foreach ( $events as $e ) {
            if ( (int) $e['event_id'] === $Event ) {
                $name = 'Ladder ' . $e['event_date_display'];
                break;
            }
        }
    } else {
        $Event = (int) $events[0]['event_id'];
        $name  = 'Ladder ' . $events[0]['event_date_display'];
    }

    // Render dropdown
    echo '<form method="post" style="margin-bottom:20px;">';
    echo '<label for="scores_event"><strong>Select ladder event:</strong></label> ';
    echo '<select name="scores_event" id="scores_event" style="margin:0 8px;padding:4px 8px;min-width:220px;">';
    foreach ( $events as $e ) {
        $eid   = (int) $e['event_id'];
        $label = $e['event_date_display'] ?? 'Event ' . $eid;
        $sel   = ( $Event === $eid ) ? 'selected' : '';
        echo '<option value="' . esc_attr( $eid ) . '" ' . $sel . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
    echo '<input type="submit" value="Show Scores" style="padding:4px 16px;background:#3766AB;color:white;border:none;border-radius:4px;cursor:pointer;margin:4px;">';
    echo '</form>';
    if ( $Event ) {
        echo '<p style="color:#555;font-size:0.9rem;">Showing scores for: <strong>' . esc_html( $name ) . '</strong></p>';
    }
}
