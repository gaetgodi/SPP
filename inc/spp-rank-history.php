<?php
/* =========================================================
   Rank History
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Show rank change" (CM272),
   version 1.6

   PURPOSE:
   Player-facing narrative rank history covering the last 8 events
   in Results_all. Subscribers see only their own history; ladder
   admins (spp_is_ladder_admin()) get a player dropdown to look up
   anyone. Entirely read-only -- confirmed by direct inspection, no
   INSERT/UPDATE/DELETE anywhere in this file.

   TEC DEPENDENCY -- investigated before migrating, per explicit
   instruction not to assume:
   This file LEFT JOINs {$wpdb->prefix}tec_occurrences (via
   r.event_id = o.occurrence_id + 30000000, the TEC-era +30000000
   ID-offset hack used elsewhere in this codebase) purely to read
   historical event dates for old TEC-era Results_all rows, blended
   via COALESCE with a small custom event_date_lookup table (50
   rows -- a hand-built GL-era date backfill). This is reconciling
   real HISTORICAL DATA, not depending on TEC being active:
     - The Events Calendar plugin is fully uninstalled on this site
       (confirmed via `wp plugin list` -- not present at all, only
       gl-events is active).
     - tec_occurrences is a plain leftover DATA TABLE, confirmed to
       still exist with its historical rows intact.
     - Zero calls to any TEC plugin PHP function anywhere in this
       file -- only raw SQL against a static table.
   Conclusion: case (b) from the migration brief -- this already
   degrades gracefully with TEC gone, and needs to stay exactly as
   it is so old TEC-era rank history keeps rendering correctly.
   Nothing here is dead code to remove.

   BUG-PATTERN CHECK (per explicit instruction): searched for the
   wildcard-collision and dead-branch-overwrite patterns found
   three times earlier tonight (CM252, CM120, CM279, CM66-flagged).
   None apply here -- this file touches no usermeta at all and
   performs zero mutation of any kind, so that whole class of bug
   cannot occur in this snippet.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Show rank change'] (CM272, now a
     transition shim around this function): the page "Rank history
     over last 8 events" (menu-reachable via Main). Not touched by
     this migration -- keeps working via the shim.

   Changes from CM272: wrapped in a real function, spp_rank_history(),
   instead of a bare top-level script. Dropped the dead
   "if (session_status() !== PHP_SESSION_ACTIVE) session_start()"
   guard -- same no-op pattern removed from every other migrated
   snippet, $_SESSION never read anywhere in this file. No other
   behavior change: identical queries, identical gap-detection and
   narrative logic, identical output.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_rank_history() {
    global $wpdb;

    $prefix       = $wpdb->prefix;
    $current_user = wp_get_current_user();
    $is_admin     = spp_is_ladder_admin();

    /* ---------------------------------------------------------
       1. Determine which user_id to show
       --------------------------------------------------------- */
    $selected_user_id = null;
    $selected_name    = '';

    if ( $is_admin ) {
        $selected_user_id = isset( $_POST['rank_history_user'] ) ? (int) $_POST['rank_history_user'] : null;

        $players = $wpdb->get_results( "
            SELECT user_id, CONCAT(first_name, ' ', last_name) AS display_name
            FROM Master
            WHERE Rank IS NOT NULL AND Rank != 0
            ORDER BY last_name, first_name
        ", ARRAY_A );

        echo '<form method="post" style="margin-bottom:20px;">';
        echo '<label for="rank_history_user"><strong>Select player:</strong></label> ';
        echo '<select name="rank_history_user" id="rank_history_user" style="margin:0 8px;padding:4px 8px;">';
        echo '<option value="">-- Choose a player --</option>';
        foreach ( $players as $p ) {
            $sel = ( $selected_user_id == $p['user_id'] ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $p['user_id'] ) . '" ' . $sel . '>' . esc_html( $p['display_name'] ) . '</option>';
        }
        echo '</select>';
        echo '<input type="submit" value="Show History" style="padding:4px 16px;background:#3766AB;color:white;border:none;border-radius:4px;cursor:pointer;margin-top:8px;display:block;">';
        echo '</form>';

        if ( ! $selected_user_id ) {
            echo '<p>Please select a player to view their rank history.</p>';
            return;
        }

        $selected_name = $wpdb->get_var( $wpdb->prepare( "
            SELECT CONCAT(first_name, ' ', last_name) FROM Master WHERE user_id = %d
        ", $selected_user_id ) );

    } else {
        $selected_user_id = $current_user->ID;
        $selected_name    = $current_user->first_name . ' ' . $current_user->last_name;

        $in_master = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM Master WHERE user_id = %d
        ", $selected_user_id ) );

        if ( ! $in_master ) {
            echo '<p>You are not currently registered as a ladder player.</p>';
            return;
        }
    }

    /* ---------------------------------------------------------
       2. Get last 8 events from Results_all, ordered by DATE
       (COALESCE tec_occurrences / event_date_lookup) so that
       recent GL-era events with small IDs are not hidden behind
       older TEC events with large IDs.
       --------------------------------------------------------- */
    $last_8_events = $wpdb->get_col( "
        SELECT ra.event_id
        FROM (
            SELECT DISTINCT r.event_id,
                COALESCE(o.start_date, edl.event_date) AS sort_date
            FROM Results_all r
            LEFT JOIN {$prefix}tec_occurrences o ON r.event_id = o.occurrence_id + 30000000
            LEFT JOIN event_date_lookup edl ON edl.event_id = r.event_id
        ) ra
        ORDER BY ra.sort_date DESC
        LIMIT 8
    " );

    if ( empty( $last_8_events ) ) {
        echo '<p>No historical results found.</p>';
        return;
    }

    $event_placeholders = implode( ',', array_fill( 0, count( $last_8_events ), '%d' ) );

    /* ---------------------------------------------------------
       3. Get this player's results for those events
       --------------------------------------------------------- */
    $player_results = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            r.event_id,
            r.Rank,
            CAST(r.RankPrev AS DECIMAL(8,2))     AS RankPrev,
            r.RankCalc,
            r.RankOverride,
            r.Score,
            r.group_id,
            DATE_FORMAT(
                COALESCE(o.start_date, edl.event_date),
                '%%b %%d, %%Y'
            ) AS event_date
        FROM Results_all r
        LEFT JOIN {$prefix}tec_occurrences o ON r.event_id = o.occurrence_id + 30000000
        LEFT JOIN event_date_lookup edl ON edl.event_id = r.event_id
        WHERE r.user_id = %d
        AND r.event_id IN ($event_placeholders)
        ORDER BY COALESCE(o.start_date, edl.event_date) ASC
    ", array_merge( [ $selected_user_id ], $last_8_events ) ), ARRAY_A );

    if ( empty( $player_results ) ) {
        echo '<p>' . esc_html( $selected_name ) . ' has no results in the last 8 events.</p>';
        return;
    }

    // Reverse for display (newest first) but keep original for gap detection
    $player_results_asc  = $player_results;
    $player_results_desc = array_reverse( $player_results );

    /* ---------------------------------------------------------
       4. Styles
       --------------------------------------------------------- */
    echo '<style>
    .rh-block { border:1px solid var(--spp-border-color,#e0e0e0); border-radius:8px; padding:14px; margin-bottom:14px; background:var(--spp-bg-white,#fff); }
    .rh-gap-block { border:1px solid #b0bec5; border-radius:8px; padding:12px 16px; margin-bottom:14px; background:#f5f5f5; font-size:0.88rem; color:#455a64; }
    .rh-gap-title { font-weight:700; margin-bottom:6px; color:#37474f; }
    .rh-gap-inserted { color:#c62828; }
    .rh-gap-removed { color:#2e7d32; }
    .rh-gap-players { margin-top:8px; }
    .rh-gap-players summary { cursor:pointer; color:#3766AB; font-size:0.85rem; margin-top:4px; }
    .rh-gap-players table { width:100%; border-collapse:collapse; margin-top:6px; font-size:0.82rem; }
    .rh-gap-players th { background:#3766AB; color:white !important; padding:3px 8px; text-align:left; }
    .rh-gap-players td { padding:3px 8px; border-bottom:1px solid #eee; }
    .rh-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
    .rh-date { font-weight:700; font-size:0.95rem; }
    .rh-change { font-size:1rem; font-weight:700; }
    .rh-narrative { margin:0 0 10px 1.2em; padding:0; }
    .rh-narrative li { margin-bottom:3px; font-size:0.9rem; color:var(--spp-text,#2c2c2c); }
    .rh-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .rh-table { width:100%; border-collapse:collapse; font-size:0.78rem; min-width:0; }
    .rh-table th { background:#3766AB; color:white !important; padding:4px 6px; text-align:center; white-space:nowrap; }
    .rh-table th:first-child { text-align:left; }
    .rh-table td { padding:3px 6px; border-bottom:1px solid #eee; text-align:center; }
    .rh-table td:first-child { text-align:left; }
    .rh-self { background:#e8f5e9 !important; font-weight:700; }
    .rh-override { background:#ffccbc !important; }
    @media (max-width:600px) {
        .rh-header { flex-direction:column; }
        .rh-table { font-size:0.72rem; }
        .rh-table th, .rh-table td { padding:3px 4px; }
    }
    </style>';

    /* ---------------------------------------------------------
       5. Render — newest first, with gap blocks between events
       --------------------------------------------------------- */
    echo '<h2 style="color:var(--spp-primary,#00897B);margin-top:16px;">Rank History -- ' . esc_html( $selected_name ) . '</h2>';
    echo '<p style="color:var(--spp-text-subtle,#555);margin-bottom:16px;font-size:0.9rem;">Last ' . count( $player_results_desc ) . ' event(s). Final published ranks. Orange cell = manual override applied.</p>';

    foreach ( $player_results_desc as $i => $event ) {
        $event_id   = $event['event_id'];
        $rank_now   = (int) $event['Rank'];
        $rank_prev  = (float) $event['RankPrev'];
        $rank_calc  = (float) $event['RankCalc'];
        $rank_over  = (float) $event['RankOverride'];
        $score      = $event['Score'];
        $group_id   = $event['group_id'];
        $event_date = $event['event_date'] ?? $event_id;
        $played     = ! is_null( $score ) && ! is_null( $group_id );
        $change     = $rank_now - (int) round( $rank_prev );

        // -------------------------------------------------------
        // GAP DETECTION: compare this event's RankPrev to the
        // previous event's final Rank (in ascending order)
        // -------------------------------------------------------
        $asc_index = array_search( $event, $player_results_asc );
        if ( $asc_index > 0 ) {
            $prev_event      = $player_results_asc[ $asc_index - 1 ];
            $prev_final_rank = (int) $prev_event['Rank'];
            $prev_date       = $prev_event['event_date'] ?? $prev_event['event_id'];
            $gap             = (int) round( $rank_prev ) - $prev_final_rank;

            if ( abs( $gap ) > 1 ) {
                $gap_abs = abs( $gap );

                echo '<div class="rh-gap-block">';
                echo '<div class="rh-gap-title">Between ' . esc_html( $prev_date ) . ' and ' . esc_html( $event_date ) . '</div>';

                if ( $gap > 0 ) {
                    echo '<p class="rh-gap-inserted">Your rank moved from ' . $prev_final_rank . ' to ' . (int) round( $rank_prev ) . ' (down ' . $gap_abs . ') outside of play.</p>';
                    $inserted = $wpdb->get_results( $wpdb->prepare( "
                        SELECT r2.display_name, r2.Rank
                        FROM Results_all r2
                        WHERE r2.event_id = %d
                        AND r2.Rank < %d
                        AND r2.user_id NOT IN (
                            SELECT user_id FROM Results_all
                            WHERE event_id = %d AND Rank < %d
                        )
                        ORDER BY r2.Rank ASC
                    ", $event_id, (int) round( $rank_prev ), $prev_event['event_id'], $prev_final_rank ), ARRAY_A );

                    if ( ! empty( $inserted ) ) {
                        echo '<details class="rh-gap-players">';
                        echo '<summary>' . count( $inserted ) . ' player(s) were placed above you -- click to see who</summary>';
                        echo '<table><thead><tr><th>Rank</th><th>Name</th></tr></thead><tbody>';
                        foreach ( $inserted as $ins ) {
                            echo '<tr><td>' . (int) $ins['Rank'] . '</td><td>' . esc_html( $ins['display_name'] ) . '</td></tr>';
                        }
                        echo '</tbody></table></details>';
                    } else {
                        echo '<p style="font-size:0.82rem;color:#666;">This may be due to a rank reset or adjustment at the start of a new season.</p>';
                    }
                } else {
                    echo '<p class="rh-gap-removed">Your rank improved from ' . $prev_final_rank . ' to ' . (int) round( $rank_prev ) . ' (up ' . $gap_abs . ') outside of play.</p>';
                    $removed = $wpdb->get_results( $wpdb->prepare( "
                        SELECT r1.display_name, r1.Rank
                        FROM Results_all r1
                        WHERE r1.event_id = %d
                        AND r1.Rank < %d
                        AND r1.user_id NOT IN (
                            SELECT user_id FROM Results_all
                            WHERE event_id = %d AND Rank < %d
                        )
                        ORDER BY r1.Rank ASC
                    ", $prev_event['event_id'], $prev_final_rank, $event_id, (int) round( $rank_prev ) ), ARRAY_A );

                    if ( ! empty( $removed ) ) {
                        echo '<details class="rh-gap-players">';
                        echo '<summary>' . count( $removed ) . ' player(s) who were above you are no longer active -- click to see who</summary>';
                        echo '<table><thead><tr><th>Rank</th><th>Name</th></tr></thead><tbody>';
                        foreach ( $removed as $rem ) {
                            echo '<tr><td>' . (int) $rem['Rank'] . '</td><td>' . esc_html( $rem['display_name'] ) . '</td></tr>';
                        }
                        echo '</tbody></table></details>';
                    } else {
                        echo '<p style="font-size:0.82rem;color:#666;">This may be due to a rank reset or adjustment at the start of a new season.</p>';
                    }
                }

                echo '</div>'; // rh-gap-block
            }
        }

        // -------------------------------------------------------
        // EVENT BLOCK
        // -------------------------------------------------------
        if ( $change < 0 )     { $arrow = 'up';   $color = '#2e7d32'; $direction = 'up ' . abs( $change ); }
        elseif ( $change > 0 ) { $arrow = 'down'; $color = '#c62828'; $direction = 'down ' . $change; }
        else                   { $arrow = '';     $color = '#555';   $direction = 'unchanged'; }

        $override_note = '';
        if ( $played && abs( $rank_calc - $rank_over ) >= 0.5 ) {
            $override_note = ' <span style="color:#e65100;font-size:0.8rem;font-weight:normal;">(override: calc ' . number_format( $rank_calc, 1 ) . ' to ' . number_format( $rank_over, 1 ) . ')</span>';
        }

        // Neighbours +-6 ranks
        $low  = max( 1, $rank_now - 6 );
        $high = $rank_now + 6;

        $neighbours = $wpdb->get_results( $wpdb->prepare( "
            SELECT
                r.Rank,
                CAST(r.RankPrev AS DECIMAL(8,2)) AS RankPrev,
                r.RankCalc,
                r.RankOverride,
                r.Score,
                r.group_id,
                r.display_name
            FROM Results_all r
            WHERE r.event_id = %d
            AND r.Rank BETWEEN %d AND %d
            AND r.user_id != %d
            ORDER BY r.Rank ASC
        ", $event_id, $low, $high, $selected_user_id ), ARRAY_A );

        // Narrative
        $narrative = [];
        if ( ! $played ) {
            $narrative[] = 'You did not play this week (penalty applied).';
        } else {
            $narrative[] = 'You played in Group ' . $group_id . ' and scored ' . $score . '.';
        }

        foreach ( $neighbours as $n ) {
            $n_rank_now  = (int) $n['Rank'];
            $n_rank_prev = (float) $n['RankPrev'];
            $n_name      = $n['display_name'];

            if ( $n_rank_prev > $rank_prev && $n_rank_now < $rank_now ) {
                $narrative[] = esc_html( $n_name ) . ' rose from rank ' . (int) round( $n_rank_prev ) . ' to ' . $n_rank_now . ', moving above you.';
            } elseif ( $n_rank_prev < $rank_prev && $n_rank_now > $rank_now ) {
                $narrative[] = esc_html( $n_name ) . ' dropped from rank ' . (int) round( $n_rank_prev ) . ' to ' . $n_rank_now . ', falling below you.';
            } elseif ( $n_rank_prev == 0 && $n_rank_now < $rank_now ) {
                $narrative[] = esc_html( $n_name ) . ' was newly ranked at ' . $n_rank_now . ', ahead of you.';
            }
        }

        // Render event block
        $arrow_display = $change < 0 ? 'Rank ' . (int) round( $rank_prev ) . ' to ' . $rank_now . ' (up ' . abs( $change ) . ')' :
                        ( $change > 0 ? 'Rank ' . (int) round( $rank_prev ) . ' to ' . $rank_now . ' (down ' . $change . ')' :
                        'Rank ' . $rank_now . ' (unchanged)' );

        echo '<div class="rh-block">';
        echo '<div class="rh-header">';
        echo '<span class="rh-date">' . esc_html( $event_date ) . '</span>';
        echo '<span class="rh-change" style="color:' . $color . ';">' . $arrow_display . $override_note . '</span>';
        echo '</div>';

        echo '<ul class="rh-narrative">';
        foreach ( $narrative as $point ) {
            echo '<li>' . $point . '</li>';
        }
        echo '</ul>';

        // Supporting table
        $all_rows   = $neighbours;
        $all_rows[] = [
            'display_name' => '>> ' . $selected_name,
            'RankPrev'     => $rank_prev,
            'Rank'         => $rank_now,
            'RankCalc'     => $rank_calc,
            'RankOverride' => $rank_over,
            'Score'        => $score,
            'group_id'     => $group_id,
            'is_self'      => true,
        ];
        usort( $all_rows, function( $a, $b ) { return (int) $a['Rank'] - (int) $b['Rank']; } );

        echo '<div class="rh-scroll">';
        echo '<table class="rh-table">';
        echo '<thead><tr>';
        echo '<th>Name</th><th>Prev</th><th>Rank</th><th>Calc</th><th>Override</th><th>Score</th><th>Grp</th>';
        echo '</tr></thead><tbody>';

        foreach ( $all_rows as $row ) {
            $is_self    = ! empty( $row['is_self'] );
            $row_class  = $is_self ? 'rh-self' : '';
            $r_calc     = isset( $row['RankCalc'] )     ? number_format( (float) $row['RankCalc'], 2 )     : '-';
            $r_over     = isset( $row['RankOverride'] ) ? number_format( (float) $row['RankOverride'], 2 ) : '-';
            $over_class = ( ! $is_self && isset( $row['RankCalc'], $row['RankOverride'] ) &&
                           abs( (float) $row['RankCalc'] - (float) $row['RankOverride'] ) >= 0.5 )
                           ? 'rh-override' : '';

            echo '<tr class="' . $row_class . '">';
            echo '<td>' . esc_html( $row['display_name'] ) . '</td>';
            echo '<td>' . (int) round( (float) $row['RankPrev'] ) . '</td>';
            echo '<td>' . (int) $row['Rank'] . '</td>';
            echo '<td>' . $r_calc . '</td>';
            echo '<td class="' . $over_class . '">' . $r_over . '</td>';
            echo '<td>' . ( is_null( $row['Score'] )    ? '-' : $row['Score'] ) . '</td>';
            echo '<td>' . ( is_null( $row['group_id'] ) ? '-' : $row['group_id'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // rh-scroll
        echo '</div>'; // rh-block
    }

    /* ---------------------------------------------------------
       Documentation block
       --------------------------------------------------------- */
    echo '
    <details style="margin-top:24px;border:1px solid var(--spp-border-color,#e0e0e0);border-radius:8px;background:var(--spp-bg-white,#fff);">
    <summary style="padding:14px 16px;cursor:pointer;font-weight:700;font-size:0.95rem;color:#3766AB;list-style:none;">
      &#128203; How are rankings calculated? (click to expand)
    </summary>
    <div style="padding:0 16px 16px 16px;font-size:0.88rem;color:#333;line-height:1.6;">

      <p>After each ladder event, every player\'s rank is recalculated based on their performance within their group. Here is exactly how it works.</p>

      <h4 style="margin:12px 0 4px 0;color:#2c3e50;">Group format and placement adjustments</h4>
      <p>All groups play to a maximum total of 60 points, regardless of group size:</p>
      <ul style="margin:4px 0 8px 1.2em;padding:0;">
        <li><strong>Groups of 4</strong> play 3 rounds to 20 points each (3 x 20 = 60)</li>
        <li><strong>Groups of 5</strong> play 4 rounds to 15 points each (4 x 15 = 60)</li>
      </ul>
      <p>Your rank moves up or down based on where you finished in your group:</p>
      <table style="border-collapse:collapse;width:100%;margin-bottom:8px;font-size:0.85rem;">
        <thead><tr style="background:#3766AB;">
          <th style="padding:4px 10px;text-align:left;color:white !important;">Placement</th>
          <th style="padding:4px 10px;text-align:center;color:white !important;">4-player group</th>
          <th style="padding:4px 10px;text-align:center;color:white !important;">5-player group</th>
        </tr></thead>
        <tbody>
          <tr style="background:#f9f9f9;"><td style="padding:4px 10px;">1st</td><td style="padding:4px 10px;text-align:center;">-3.0</td><td style="padding:4px 10px;text-align:center;">-3.0</td></tr>
          <tr><td style="padding:4px 10px;">2nd</td><td style="padding:4px 10px;text-align:center;">-1.8</td><td style="padding:4px 10px;text-align:center;">-1.8</td></tr>
          <tr style="background:#f9f9f9;"><td style="padding:4px 10px;">3rd</td><td style="padding:4px 10px;text-align:center;">+0.3</td><td style="padding:4px 10px;text-align:center;">+0.3</td></tr>
          <tr><td style="padding:4px 10px;">4th</td><td style="padding:4px 10px;text-align:center;">+2.4</td><td style="padding:4px 10px;text-align:center;">+1.4</td></tr>
          <tr style="background:#f9f9f9;"><td style="padding:4px 10px;">5th</td><td style="padding:4px 10px;text-align:center;">-</td><td style="padding:4px 10px;text-align:center;">+2.4</td></tr>
          <tr><td style="padding:4px 10px;">NP (not played)</td><td style="padding:4px 10px;text-align:center;" colspan="2">+1.6 -- scheduled but did not play</td></tr>
          <tr style="background:#f9f9f9;"><td style="padding:4px 10px;">NS (no-show)</td><td style="padding:4px 10px;text-align:center;" colspan="2">+2.6 -- absent without prior notification</td></tr>
        </tbody>
      </table>
      <p style="font-size:0.82rem;color:#666;">A negative number means your rank improves (moves up). A positive number means your rank drops (moves down). Rank 1 is the top player.</p>

      <h4 style="margin:12px 0 4px 0;color:#2c3e50;">Score bonus / penalty (+-3)</h4>
      <p>Since the maximum total score is always 60, the bonus and penalty thresholds are the same for everyone regardless of group size:</p>
      <ul style="margin:4px 0 8px 1.2em;padding:0;">
        <li><strong>High score bonus</strong> (score 56 or more out of 60): all placement adjustments shift an additional -3, rewarding an exceptional performance regardless of where you finished in your group.</li>
        <li><strong>Low score penalty</strong> (score 28 or less out of 60): all placement adjustments shift an additional +3.</li>
      </ul>
      <p style="font-size:0.82rem;color:#666;">Example: finishing 1st normally gives -3.0. With a high score bonus it becomes -6.0 -- a stronger improvement. Finishing last normally gives +2.4; with a high score bonus it becomes -0.6 -- your great score actually moves you up despite finishing last in your group.</p>

      <h4 style="margin:12px 0 4px 0;color:#2c3e50;">Tied scores</h4>
      <p>If two players in the same group score the same, the player with the better (lower) current rank wins the tie and receives the better placement.</p>

      <h4 style="margin:12px 0 4px 0;color:#2c3e50;">Moderator overrides</h4>
      <p>After each event, the moderator reviews the calculated ranks and may apply manual overrides to fine-tune placements -- for example when a new player\'s true skill level becomes clearer after their first game, or when an exceptional performance warrants a larger adjustment. Override cells are highlighted in <span style="background:#ffccbc;padding:1px 6px;border-radius:3px;">orange</span> in the tables above.</p>

      <h4 style="margin:12px 0 4px 0;color:#2c3e50;">Between-event rank changes</h4>
      <p>Your rank may shift between events even if you did not play. This happens when new players join the ladder and are inserted at a rank above yours, or when players become inactive and are removed. These adjustments are shown as grey separator blocks in your history above.</p>

      <p style="margin-top:12px;font-size:0.82rem;color:#666;">Questions or concerns about a specific result? Please <a href="/contact-us/" style="color:#3766AB;">contact us</a> -- we are happy to walk through any calculation with you.</p>

    </div>
    </details>
    ';
}

add_shortcode( 'spp_rank_history', function( $atts ) {
    ob_start();
    spp_rank_history();
    return ob_get_clean();
} );
