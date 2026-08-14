<?php
/* =========================================================
   GL Player Schedule View
   Version: 1.6.4
   Date: 2026-08-14
   Based on: Player Schedule View 1.5

   Changes from 1.6.3:
   - Added registrant counts: a page-total count above the
     convenor message, and a per-time-slot subtotal in each
     .spp-time-header. Both count rows in schedules_w with
     GP_name != 'Group 99' -- Group 99 (group_id/time_id/
     Crt_ID = 99) is the sentinel gl-schedule-production.php
     uses to shelve dropped/unscheduled registrants, and the
     groups query on this page already excludes it, so the
     counts use the same exclusion to stay consistent with what
     the page actually lists. Both counts query schedules_w
     (not Schedules directly) for the same reason -- this page
     already reads exclusively from that view.

   Changes from 1.6.2:
   - Guarded the `[cmruncode name='Create View']` call (CM254:
     DROP VIEW IF EXISTS schedules_w; CREATE VIEW schedules_w
     AS ...) behind an information_schema existence check
     instead of running it unconditionally on every render.
     schedules_w is a real SQL VIEW, not a materialized table --
     it always reflects the current Schedules/Times/Groups/Courts
     data live, so recreating it on every page load changed
     nothing and only added a DROP+CREATE DDL round-trip to the
     member-facing page hit hundreds of times per event night.
     Worse, DROP VIEW / CREATE VIEW is DDL: there is a window
     between the DROP and the CREATE where the view does not
     exist, and this call site is on the highest-traffic,
     highest-concurrency page in the theme -- any request that
     ran its SELECT against schedules_w during that window would
     have errored. This eliminates that race window, not just
     the redundant DDL churn. The two schedule-production call
     sites (gl-schedule-production.php, spp-schedule-production.php),
     which legitimately (re)build the view once per event, are
     unchanged.

   Changes from 1.6.1 (undocumented at the time, applied via
   sed directly on the server during a same-night fatal-error
   fix on 2026-07-17):
   - All functions and the shortcode renamed from
     gl_player_schedule_view_* / [gl_player_schedule_view] to
     spp_player_schedule_view_* / [spp_player_schedule_view].
     The gl-events PLUGIN has its own file with the exact same
     names, which caused a site-wide fatal error the moment
     this file loaded alongside it.

   Changes from 1.6.0:
   - Player-facing "is the schedule published" gate no longer
     queries wpda_project_page.add_to_menu (a WP Data Access
     Premium admin-page-config field being repurposed for this).
     Depending on it proved fragile: a WPDA reinstall on
     2026-07-17 left it in a state nothing ever flipped back to
     'Yes' on publish, so players saw "not currently available"
     all night on 2026-07-20 despite a successful publish --
     admin/editor accounts bypass this check entirely, which is
     why it went unnoticed until players reported it. Now uses
     get_option('spp_schedule_published') directly -- the same
     flag gl-schedule-production.php (sets to 0) and
     gl-publish-schedule.php (sets to 1) already control
     reliably elsewhere, with no third-party plugin dependency.
   - Added a Travel column, visible to admin/editor only
     (spp_is_admin_or_editor()) -- lets convenors spot missing
     or mismatched carpool codes (e.g. same-surname players not
     actually linked) directly from the schedule view, without
     needing to open the membership editor separately. Same
     "gate one extra field behind a role check" approach already
     used for the P- preferred-player prefix handling elsewhere.

   Changes from 1.5:
   - Migrated from Code Manager snippet ("Player Schedule View")
     to a tracked theme file.
   - Fixed session_status() check: was "!session_status() ==
     PHP_SESSION_ACTIVE" (always false due to precedence, so
     session_start() never actually ran); now correctly checks
     "session_status() !== PHP_SESSION_ACTIVE".
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_player_schedule_view', 'spp_player_schedule_view_shortcode' );

function spp_player_schedule_view_shortcode() {
    if ( spp_is_admin_or_editor() ) {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
    }

    ob_start();
    spp_player_schedule_view_run();
    return ob_get_clean();
}

function spp_player_schedule_view_run() {

if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

global $wpdb;

$Event = get_option('spp_current_event');
if (!$Event) {
    echo "<p>No schedule has been published yet.</p>";
    return;
}

// Player-facing gate uses our own spp_schedule_published option --
// not the WPDA-table add_to_menu field. That field belongs to a
// third-party plugin's internal admin page config, and depending on
// it for player-facing access proved fragile in practice (July 20
// 2026: a WP Data Access Premium reinstall left it in a state where
// nothing was ever flipping it back to 'Yes' on publish, so players
// saw "not currently available" all night despite a successful
// publish -- admin/editor accounts bypass this check entirely,
// which is why it went unnoticed until players reported it).
// spp_schedule_published is already the authoritative flag for this
// exact question elsewhere in the codebase (set to 0 by
// gl-schedule-production.php, 1 by gl-publish-schedule.php), so
// using it here directly removes the WPDA dependency altogether.
$published = (int) get_option( 'spp_schedule_published', 0 );

$is_admin_or_editor = spp_is_admin_or_editor();

if (!$is_admin_or_editor) {
    if (!$published) {
        echo "<p>The schedule for this week is not currently available.</p>";
        return;
    }
}

// schedules_w is a live VIEW (not materialized) over the current
// Schedules/Times/Groups/Courts tables -- it always reflects current
// data without being rebuilt. Only (re)create it if it's missing.
// Skipping the unconditional DROP+CREATE here also eliminates a race
// window where a concurrent member request could hit schedules_w
// mid-DROP (between the DROP and the CREATE) and error -- this is
// the highest-traffic, highest-concurrency page in the theme.
$schedules_w_exists = $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.VIEWS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules_w'"
);
if ( ! $schedules_w_exists ) {
    echo do_shortcode("[cmruncode name='Create View']");
}
$time_slots = $wpdb->get_results("SELECT T_ID, T_desc FROM Times WHERE Active = 1 ORDER BY T_ID");

// Get convenor name and phone dynamically from current event
$_conv_event_id = (int) get_option('spp_current_event', 0);
$_conv = $wpdb->get_row($wpdb->prepare(
    "SELECT m.first_name, m.user_phone
     FROM lX9c1_gl_event_occurrences o
     JOIN membership m ON o.convenor_id = m.user_id
     WHERE o.id = %d", $_conv_event_id
), ARRAY_A);
$_conv_name  = $_conv ? $_conv['first_name'] : 'the convenor';
$_conv_phone = $_conv ? $_conv['user_phone'] : '';
$_conv_msg   = $_conv_phone
    ? "Please contact {$_conv_name} directly at {$_conv_phone} to notify of any last-minute schedule issues."
    : "Please contact {$_conv_name} to notify of any last-minute schedule issues.";
echo '<p class="spp-sub-intro">' . esc_html($_conv_msg) . '</p>';

// Page-total registrant count -- schedules_w rows excluding the Group 99
// (dropped/unscheduled) sentinel, matching the exclusion the groups query
// below already applies, so this stays consistent with what's listed.
$total_registrants = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM schedules_w WHERE GP_name != 'Group 99'"
);
echo '<p class="spp-total-registrants">Total registrants: ' . esc_html($total_registrants) . '</p>';

foreach ($time_slots as $slot) {
    $t_id   = $slot->T_ID;
    $t_desc = $slot->T_desc;

    $groups = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT w.GP_name, w.Crt_name, w.t_ID "
        . "FROM schedules_w w "
        . "WHERE w.t_ID = %d AND w.GP_name != 'Group 99' "
        . "ORDER BY w.Crt_name",
        $t_id
    ));

    if (empty($groups)) continue;

    $slot_registrants = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM schedules_w WHERE t_ID = %d AND GP_name != 'Group 99'",
        $t_id
    ));

    echo '<div class="spp-time-block">';
    echo '<div class="spp-time-header">' . esc_html($t_desc)
       . ' <span class="spp-slot-count">(' . esc_html($slot_registrants) . ' registrants)</span></div>';
    echo '<div class="spp-groups-grid">';

    foreach ($groups as $group) {
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT w.full_name, w.user_phone, w.Travel, CAST(w.Rank AS UNSIGNED) AS Rank "
            . "FROM schedules_w w "
            . "WHERE w.t_ID = %d AND w.GP_name = %s "
            . "ORDER BY w.Rank",
            $t_id,
            $group->GP_name
        ));

        echo '<div class="spp-group-card">';
        echo '<div class="spp-group-header">';
        echo '<div class="spp-group-name">' . esc_html($group->GP_name) . '</div>';
        echo '<div class="spp-court-badge">' . esc_html($group->Crt_name) . '</div>';
        echo '</div>';

        echo '<div class="spp-player-list">';
        echo '<div class="spp-player-row spp-player-heading">';
        echo '<div class="spp-col-rank">Rank</div>';
        echo '<div class="spp-col-name">Name</div>';
        echo '<div class="spp-col-phone">Phone</div>';
        if ($is_admin_or_editor) {
            echo '<div class="spp-col-travel">Travel</div>';
        }
        echo '</div>';

        foreach ($players as $player) {
            $phone_clean = preg_replace('/\D/', '', $player->user_phone);
            echo '<div class="spp-player-row">';
            echo '<div class="spp-col-rank">' . esc_html($player->Rank) . '</div>';
            echo '<div class="spp-col-name">' . esc_html($player->full_name) . '</div>';
            echo '<div class="spp-col-phone"><a href="tel:' . esc_attr($phone_clean) . '">' . esc_html($player->user_phone) . '</a></div>';
            if ($is_admin_or_editor) {
                echo '<div class="spp-col-travel">' . esc_html($player->Travel ?: '—') . '</div>';
            }
            echo '</div>';
        }

        echo '</div>'; // spp-player-list
        echo '</div>'; // spp-group-card
    }

    echo '</div>'; // spp-groups-grid
    echo '</div>'; // spp-time-block
}
?>
<style>
/* ── Intro ────────────────────────────────────────────── */
.spp-sub-intro {
    font-size: 0.9em;
    color: #555 !important;
    margin-bottom: 16px;
    font-style: italic;
}
.spp-total-registrants {
    font-weight: bold;
    margin: 0 0 12px 0;
}

/* ── Time block ───────────────────────────────────────── */
.spp-time-block {
    margin-bottom: 20px;
}
.spp-time-header {
    background: #3766AB;
    color: #ffffff !important;
    padding: 10px 16px;
    margin: 0 0 10px 0;
    border-radius: 6px;
    font-size: 1.2em;
    font-weight: bold;
    text-align: center;
}
.spp-slot-count {
    font-size: 0.75em;
    font-weight: normal;
    opacity: 0.85;
}

/* ── Groups grid ──────────────────────────────────────── */
.spp-groups-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* ── Group card ───────────────────────────────────────── */
.spp-group-card {
    flex: 1 1 260px;
    border: 1px solid #d0d0d0;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}

/* ── Group header ─────────────────────────────────────── */
.spp-group-header {
    background: #2c3e50;
    padding: 8px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.spp-group-name {
    font-weight: bold;
    font-size: 0.95em;
    color: #ffffff !important;
}
.spp-court-badge {
    background: #3766AB;
    color: #ffffff !important;
    font-size: 0.78em;
    padding: 2px 8px;
    border-radius: 10px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* ── Player rows ──────────────────────────────────────── */
.spp-player-list {
    padding: 0;
}
.spp-player-row {
    display: flex;
    align-items: center;
    padding: 6px 10px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
    line-height: 1.5;
    gap: 6px;
}
.spp-player-row:last-child {
    border-bottom: none;
}
.spp-player-row:nth-child(even) {
    background: #f9f9f9;
}
.spp-player-heading {
    background: #f0f0f0;
    font-weight: bold;
    font-size: 12px;
    color: #333 !important;
    padding: 4px 10px;
}

/* ── Columns ──────────────────────────────────────────── */
.spp-col-rank {
    flex: 0 0 36px;
    text-align: center;
    font-weight: bold;
    color: #3766AB !important;
}
.spp-player-heading .spp-col-rank {
    color: #333 !important;
}
.spp-col-name {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #000000 !important;
}
.spp-col-phone {
    flex: 0 0 auto;
    white-space: nowrap;
    font-size: 0.9em;
}
.spp-col-phone a {
    color: #3766AB !important;
    text-decoration: none;
}
.spp-col-phone a:hover {
    text-decoration: underline;
}

/* ── Travel column (admin/editor only) ───────────────────── */
.spp-col-travel {
    flex: 0 0 auto;
    white-space: nowrap;
    font-size: 0.85em;
    color: #7a5b00 !important;
    background: #fff3cd;
    padding: 2px 8px;
    border-radius: 4px;
}
.spp-player-heading .spp-col-travel {
    background: transparent;
    color: #333 !important;
    padding: 0;
}

/* ── Mobile: single column ────────────────────────────── */
@media (max-width: 600px) {
    .spp-groups-grid {
        flex-direction: column;
        gap: 8px;
    }
    .spp-group-card {
        flex: 1 1 100%;
    }
    .spp-time-header {
        font-size: 1.1em;
        padding: 8px 12px;
    }
    .spp-player-row {
        flex-wrap: wrap;
        padding: 6px 8px;
    }
    .spp-col-rank {
        flex: 0 0 28px;
    }
    .spp-col-name {
        flex: 1 1 auto;
        white-space: normal;
    }
    .spp-col-phone {
        flex: 1 1 100%;
        text-align: right;
        font-size: 0.85em;
        margin-top: 2px;
    }
    .spp-player-heading .spp-col-phone {
        flex: 0 0 auto;
        margin-top: 0;
    }
    .spp-col-travel {
        flex: 1 1 100%;
        text-align: right;
        margin-top: 2px;
    }
    .spp-player-heading .spp-col-travel {
        flex: 0 0 auto;
        margin-top: 0;
    }
}
</style>
<?php
} // end spp_player_schedule_view_run()