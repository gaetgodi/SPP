<?php
/* =========================================================
   Schedule Before/After Comparison
   Version: 1.0.0
   Date: 2026-09-06
   Based on: Code Manager snippet "Schedule Before After Comparison"
   (CM264)

   PURPOSE:
   Read-only diagnostic report for the current event's schedule
   production run. Shows, per active time slot, a side-by-side
   comparison of each player's Group/Court assignment BEFORE the
   travel-time preference swap phase (from the `SchedulesPreTravel`
   snapshot table gl-schedule-production.php/spp-schedule-production.php
   write early in the pipeline) versus AFTER it (the live `Schedules`
   table, via the already-migrated schedules_w view). Rows are
   highlighted when a player changed group/court within the same time
   slot, or moved to/from a different time slot entirely. Includes a
   print button and print-specific styling (one time slot per printed
   page, landscape).

   CALLED FROM (as of this migration):
     Via [cmruncode name='Schedule Before After Comparison'] (CM264,
     now a transition shim around this function): the page
     "Schedule Before-After" (ID 20009753) -- the only caller, and a
     genuinely menu-reachable one (Main menu side-nav -> Editor ->
     Schedules -> "Schedule Before-After"). Confirmed fresh against
     production tonight: no other page or Code Manager snippet
     references it, directly or by name.

   Changes from CM264:
   - Wrapped in a real function, spp_schedule_before_after_comparison(),
     instead of a bare top-level script. No parameters -- none of the
     original code read anything from $wpda_shortcode_args.
   - Dropped the dead "if (!session_status() == PHP_SESSION_ACTIVE)
     session_start()" guard -- always a no-op due to operator
     precedence (the same pattern already removed from every other
     snippet migrated so far), and $_SESSION was never read anywhere
     in this snippet regardless.
   - No other behavior change. In particular, deliberately NOT
     touched:
     - This snippet builds its own `schedules_pre_travel_w` view with
       an inline DROP VIEW/CREATE VIEW, rather than calling the
       already-migrated spp_create_view() (CM254). Traced this before
       writing: spp_create_view() would change the result shape (it
       adds Game1-5/Score columns this report doesn't use) and, more
       importantly, has no way to express this view's
       `WHERE group_id != 99` filter (spp_create_view() takes no WHERE
       clause at all) -- consolidating the two would silently change
       which rows appear on the "before" side. Left as its own
       independent view, same as CM264 always built it.
     - spp_create_view()'s DROP+CREATE race-window guard (see that
       file's header) was considered and deliberately not ported here:
       that guard protects OTHER concurrent callers reading a
       long-lived, shared view (schedules_w) while it's mid-rebuild.
       `schedules_pre_travel_w` has exactly one reader anywhere in the
       codebase -- this function, which creates it and immediately
       queries it in the same request -- so there is no concurrent
       reader for a race window to affect. Confirmed via a fresh grep
       of the theme, mu-plugins, and every Code Manager snippet body
       tonight: nothing else references `schedules_pre_travel_w` or
       `SchedulesPreTravel` by name except the two schedule-production
       pipeline files (which only ever write the table, not the view).
     - Known pre-existing limitation, preserved as-is (not one of the
       bug shapes this migration pass checks for, and fixing it would
       change output): the before/after diff keys players by
       full_name (`$before_keys[$row->full_name] = ...`). Two players
       who share an exact first+last name would collide in that map,
       and one of the pair could show an incorrect changed/departed/
       arrived flag. No such collision exists in current data, and
       CM264 has always had this behavior.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_schedule_before_after_comparison() {
    global $wpdb;

    $Event = get_option( 'spp_current_event' );
    if ( ! $Event ) {
        echo '<p>No schedule has been run yet.</p>';
        return;
    }

    $wpdb->query( 'DROP VIEW IF EXISTS schedules_pre_travel_w' );
    $wpdb->query(
        'CREATE ALGORITHM=UNDEFINED VIEW schedules_pre_travel_w AS '
        . "SELECT s.Sequence, CAST(s.Rank AS UNSIGNED) AS Rank, s.user_id, "
        . "s.first_name, s.last_name, CONCAT(s.first_name, ' ', s.last_name) AS full_name, "
        . 's.user_phone, s.user_email, s.travel AS Travel, '
        . 't.T_desc, t.T_ID AS t_ID, g.GP_name, c.Crt_name '
        . 'FROM SchedulesPreTravel s '
        . 'JOIN Times t ON s.time_id = t.T_ID '
        . 'JOIN Groups g ON s.group_id = g.GP_ID '
        . 'JOIN Courts c ON s.Crt_ID = c.Crt_ID '
        . 'WHERE s.group_id != 99 '
        . 'ORDER BY s.Sequence ASC'
    );

    $time_slots = $wpdb->get_results( 'SELECT T_ID, T_desc FROM Times WHERE Active = 1 ORDER BY T_ID' );

    echo '<button class="spp-print-button" onclick="window.print()">Print this page</button>';
    echo '<div class="spp-legend">
        <strong>Legend:</strong>
        <span class="spp-legend-item spp-legend-changed">Group or court changed within same time slot</span>
        <span class="spp-legend-item spp-legend-departed">Moved to a different time slot</span>
        <span class="spp-legend-item spp-legend-arrived">Moved in from a different time slot</span>
    </div>';

    foreach ( $time_slots as $slot ) {
        $t_id   = $slot->T_ID;
        $t_desc = $slot->T_desc;

        $before = $wpdb->get_results( $wpdb->prepare(
            'SELECT GP_name, Crt_name, Rank, full_name, Travel '
            . 'FROM schedules_pre_travel_w '
            . 'WHERE t_ID = %d '
            . 'ORDER BY GP_name, Rank',
            $t_id
        ) );

        $after = $wpdb->get_results( $wpdb->prepare(
            'SELECT w.GP_name, w.Crt_name, CAST(w.Rank AS UNSIGNED) AS Rank, w.full_name, w.Travel '
            . 'FROM schedules_w w '
            . "WHERE w.t_ID = %d AND w.GP_name != 'Group 99' "
            . 'ORDER BY w.GP_name, w.Rank',
            $t_id
        ) );

        $before_keys = array();
        foreach ( $before as $row ) {
            $before_keys[ $row->full_name ] = $row->GP_name . '|' . $row->Crt_name;
        }
        $after_keys = array();
        foreach ( $after as $row ) {
            $after_keys[ $row->full_name ] = $row->GP_name . '|' . $row->Crt_name;
        }
        ?>
        <div class="spp-comparison-block">
            <h2 class="spp-time-header"><?php echo esc_html( $t_desc ); ?></h2>
            <div class="spp-comparison-tables">

                <div class="spp-comparison-side">
                    <h3 class="spp-side-header spp-before-header">Before Travel Adjustments</h3>
                    <table class="spp-comparison-table">
                        <colgroup>
                            <col class="col-group"><col class="col-court"><col class="col-rank"><col class="col-name"><col class="col-travel">
                        </colgroup>
                        <thead>
                            <tr><th>Group</th><th>Court</th><th>Rank</th><th>Name</th><th>Travel</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $before as $row ) :
                                $changed  = isset( $after_keys[ $row->full_name ] ) && $after_keys[ $row->full_name ] !== $before_keys[ $row->full_name ];
                                $departed = ! isset( $after_keys[ $row->full_name ] );
                            ?>
                            <tr class="<?php echo $changed ? 'spp-changed' : ( $departed ? 'spp-departed' : '' ); ?>">
                                <td><?php echo esc_html( $row->GP_name ); ?></td>
                                <td><?php echo esc_html( $row->Crt_name ); ?></td>
                                <td><?php echo esc_html( $row->Rank ); ?></td>
                                <td><?php echo esc_html( $row->full_name ); ?></td>
                                <td><?php echo esc_html( $row->Travel ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="spp-comparison-side">
                    <h3 class="spp-side-header spp-after-header">After Travel Adjustments</h3>
                    <table class="spp-comparison-table">
                        <colgroup>
                            <col class="col-group"><col class="col-court"><col class="col-rank"><col class="col-name"><col class="col-travel">
                        </colgroup>
                        <thead>
                            <tr><th>Group</th><th>Court</th><th>Rank</th><th>Name</th><th>Travel</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $after as $row ) :
                                $changed = isset( $before_keys[ $row->full_name ] ) && $before_keys[ $row->full_name ] !== $after_keys[ $row->full_name ];
                                $arrived = ! isset( $before_keys[ $row->full_name ] );
                            ?>
                            <tr class="<?php echo $changed ? 'spp-changed' : ( $arrived ? 'spp-arrived' : '' ); ?>">
                                <td><?php echo esc_html( $row->GP_name ); ?></td>
                                <td><?php echo esc_html( $row->Crt_name ); ?></td>
                                <td><?php echo esc_html( $row->Rank ); ?></td>
                                <td><?php echo esc_html( $row->full_name ); ?></td>
                                <td><?php echo esc_html( $row->Travel ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
        <?php
    }
    ?>
    <style>
    .spp-comparison-block { margin: 6px 0; font-family: Arial, sans-serif; }
    .spp-time-header { background: #000000; color: #ffffff; padding: 4px 12px; margin: 0 0 4px 0; border-radius: 6px; font-size: 1.3em; letter-spacing: 0.04em; text-align: center; line-height: 1.1; }
    .spp-side-header { padding: 3px 10px; margin: 0 0 3px 0; border-radius: 4px; font-size: 1em; text-transform: uppercase; letter-spacing: 0.06em; color: #ffffff; line-height: 1.1; }
    .spp-before-header { background: #c0392b; }
    .spp-after-header { background: #1e8449; }
    .spp-comparison-tables { display: flex; gap: 12px; align-items: flex-start; }
    .spp-comparison-side { flex: 1; }
    .spp-comparison-table { width: 100%; border-collapse: collapse; font-size: 0.82em; table-layout: fixed; line-height: 1.1; }
    .spp-comparison-table col.col-group { width: 18%; }
    .spp-comparison-table col.col-court { width: 14%; }
    .spp-comparison-table col.col-rank { width: 11%; }
    .spp-comparison-table col.col-name { width: 33%; }
    .spp-comparison-table col.col-travel { width: 24%; }
    .spp-comparison-table th { background: #000000; color: #ffffff; padding: 2px 4px; text-align: left; white-space: nowrap; overflow: hidden; line-height: 1.1; }
    .spp-comparison-table td { padding: 1px 4px; border-bottom: 1px solid #ddd; white-space: nowrap; overflow: hidden; line-height: 1.1; }
    .spp-comparison-table tr:nth-child(even) td { background: #e8e8e8; }
    .spp-comparison-table tr.spp-changed td { background: #fff3cd; font-weight: bold; color: #7d4e00; }
    .spp-comparison-table tr.spp-departed td { background: #fde8e8; color: #922b21; font-style: italic; }
    .spp-comparison-table tr.spp-arrived td { background: #e9f7ef; color: #1e8449; font-weight: bold; }
    .spp-print-button { margin-bottom: 8px; padding: 6px 16px; background: #3766AB; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
    .spp-print-button:hover { background: #2a4f8a; }
    .spp-legend { margin-bottom: 8px; font-family: Arial, sans-serif; font-size: 0.9em; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .spp-legend-item { padding: 3px 8px; border-radius: 4px; font-weight: bold; }
    .spp-legend-changed { background: #fff3cd; color: #7d4e00; }
    .spp-legend-departed { background: #fde8e8; color: #922b21; font-style: italic; }
    .spp-legend-arrived { background: #e9f7ef; color: #1e8449; }
    @media print {
        @page { size: landscape; margin: 1cm; }
        .spp-print-button { display: none !important; }
        .spp-legend { display: none !important; }
        .spp-comparison-tables { flex-direction: row; }
        .spp-comparison-block { page-break-after: always; }
        .spp-comparison-table { font-size: 0.75em; line-height: 1.1; }
        .spp-comparison-table th { padding: 2px 4px; white-space: nowrap; line-height: 1.1; }
        .spp-comparison-table td { padding: 1px 4px; white-space: nowrap; line-height: 1.1; }
        .spp-time-header { font-size: 1em; padding: 4px 10px; }
        .spp-side-header { font-size: 0.8em; padding: 3px 6px; }
        .spp-comparison-block h2, .spp-comparison-block h3 { margin-bottom: 2px; }
    }
    </style>
    <?php
}

add_shortcode( 'spp_schedule_before_after_comparison', function( $atts ) {
    ob_start();
    spp_schedule_before_after_comparison();
    return ob_get_clean();
} );
