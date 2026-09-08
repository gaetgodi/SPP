<?php
/* =========================================================
   GL Ladder Events Dropdown
   Version: 1.1.0
   Date: 2026-09-07
   Based on: Code Manager snippet "GL Ladder Events Dropdown" (CM275)

   Changes from 1.0.0:
   - SECURITY FIX (Tier 1 access-control audit follow-up): this file's
     own PBEvent POST had no nonce -- flagged as a residual gap when
     spp_create_schedule (gl-schedule-production.php 2.0.8) was gated,
     since spp_run_schedule_production() has no form of its own; the
     actual "run production for the selected event" trigger IS this
     file's PBEvent submission. Closed with the same pattern as every
     other Tier-1 fix: a nonce (wp_nonce_field()/wp_verify_nonce(),
     action 'spp_gl_ladder_events_dropdown_action') added to both
     spp_full_form_for_ladder() and spp_short_form_for_ladder()'s
     forms, required alongside 'PBEvent' before $GLOBALS['Event'] is
     set. Invalid/missing nonce is treated as no selection at all --
     falls through to the same 'else { return; }' branch a plain GET
     already takes, consistent with the fall-back-to-safe-state
     convention used everywhere else today. Not a role check (this
     file was never in scope for one -- it does no mutation itself,
     only resolves a selection into globals for other shortcodes to
     read), purely CSRF closure.
   - No other behavior change.

   PURPOSE:
   Builds the list of upcoming ladder events (from gl_event_occurrences
   / gl_registrations) into $all, renders either the full form (with
   carpool rank tolerance) or the short form depending on
   $show_tolerance, and on POST resolves the selected event into
   $GLOBALS['Event'] / $GLOBALS['name'] (and, for the full form,
   $GLOBALS['carpool_rank_tolerance']) for consumption by other page
   elements (e.g. [spp_create_schedule]) rendered later in the same
   request.

   $Event, $name, and $all remain real globals here, matching CM275's
   own behavior exactly (including resetting $Event/$name to 0/''
   on every call) -- this broader inter-snippet global-passing
   mechanism is left untouched, per the same precedent established
   during CM279's migration.

   EXPLICIT PARAMETER TO THE FORM FUNCTIONS: CM275 called its two
   child snippets via [cmruncode name='Full form for ladder'] /
   [cmruncode name='Short form for ladder'], which relied on $all
   being visible as `global $all;` because cmruncode shares the
   caller's scope. Now that this function calls
   spp_full_form_for_ladder() / spp_short_form_for_ladder() directly
   as real functions, $all is passed as an explicit parameter
   instead of relying on global scope -- same discipline as CM279's
   migration.

   CALLED FROM (as of this migration):
     Via [cmruncode name='GL Ladder Events Dropdown'] /
     [cmruncode name='GL Ladder Events Dropdown' show_tolerance='1']
     (CM275, now a transition shim around this function): page
     20010224 "GL Create Schedule", with show_tolerance="1" -- the
     only live caller found. The bare (show_tolerance unset/false)
     path has no confirmed live page caller today but is preserved
     for parity with the original snippet's behavior.

   Changes from CM275: wrapped in a real function,
   spp_gl_ladder_events_dropdown( bool $show_tolerance = false ),
   instead of a bare top-level script reading
   $wpda_shortcode_args['show_tolerance']. The shim passes that
   value in as the parameter. Calls spp_full_form_for_ladder( $all )
   / spp_short_form_for_ladder( $all ) directly instead of going
   back out through [cmruncode]. No mutation of any DB table in this
   file (only reads gl_event_occurrences / gl_registrations), so no
   wildcard/meta_key audit applies.

   CORRECTION (2026-09-05, same day): this file originally rewrote
   CM275's "if (!session_status() == PHP_SESSION_ACTIVE) session_start()"
   guard into a working "if (session_status() !== PHP_SESSION_ACTIVE)"
   check -- an undisclosed behavior change (the original is always a
   no-op due to operator precedence). Reverted: dropped the dead guard
   entirely instead, matching the pattern already removed from every
   other snippet migrated tonight. $_SESSION is never read anywhere in
   this snippet regardless, so dropping it is a true no-op.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_gl_ladder_events_dropdown( bool $show_tolerance = false ) {

    global $wpdb, $Event, $name, $all;

    $name  = '';
    $Event = 0;

    // Events up to coming Monday 23:59:59.
    $date = date( 'Y-m-d', strtotime( 'this Monday' ) ) . ' 23:59:59';

    $sql = "
        SELECT
            o.id                                          AS EventNumber,
            o.title                                       AS EventName,
            CONCAT( o.event_date, ' ', o.event_time )     AS EventDate,
            COUNT( CASE WHEN r.status = 'confirmed' THEN 1 END ) AS Registrations
        FROM {$wpdb->prefix}gl_event_occurrences o
        LEFT JOIN {$wpdb->prefix}gl_registrations r ON r.occurrence_id = o.id
        WHERE o.cancelled = 0
        AND o.series_id = 1
        AND CONCAT( o.event_date, ' ', o.event_time ) <= '$date'
        AND o.event_date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )
        GROUP BY o.id, o.title, o.event_date, o.event_time
        ORDER BY o.event_date DESC
    ";

    $all = $wpdb->get_results( $sql, ARRAY_A );

    if ( $show_tolerance ) {
        spp_full_form_for_ladder( $all );
    } else {
        spp_short_form_for_ladder( $all );
    }

    // ── Handle POST ───────────────────────────────────────────────────
    // Nonce required alongside 'PBEvent' -- this form had none before.
    // Invalid/missing nonce is treated exactly like no selection: falls
    // through to the plain 'else { return; }' branch below, same as a
    // bare GET.
    $nonce_ok = isset( $_POST['spp_gl_ladder_events_dropdown_nonce'] )
        && wp_verify_nonce( $_POST['spp_gl_ladder_events_dropdown_nonce'], 'spp_gl_ladder_events_dropdown_action' );

    if ( $nonce_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['PBEvent'] ) ) {

        $Event = intval( $_POST['PBEvent'] );

        foreach ( $all as $row ) {
            if ( (int) $row['EventNumber'] === $Event ) {
                $name          = $row['EventName'] . ' ' . $row['EventDate'];
                $registrations = $row['Registrations'];
                break;
            }
        }

        $GLOBALS['Event'] = $Event;
        $GLOBALS['name']  = $name;

        if ( $show_tolerance ) {
            $GLOBALS['carpool_rank_tolerance'] = isset( $_POST['carpool_rank_tolerance'] )
                ? (int) $_POST['carpool_rank_tolerance']
                : 15;
        }

        echo "<br />Event: $Event $name";
        echo "<br />There are $registrations Registrants<br>";
        echo "<script>document.getElementById('dropform').style.display='none';</script>";

    } else {
        return;
    }
}

add_shortcode( 'spp_gl_ladder_events_dropdown', function( $atts ) {
    $atts = shortcode_atts( array(
        'show_tolerance' => '0',
    ), $atts, 'spp_gl_ladder_events_dropdown' );
    ob_start();
    spp_gl_ladder_events_dropdown( (bool) intval( $atts['show_tolerance'] ) );
    return ob_get_clean();
} );
