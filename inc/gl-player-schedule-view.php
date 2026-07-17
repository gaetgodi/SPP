<?php
/* =========================================================
   GL Player Schedule View
   Version: 1.6.0
   Date: 2026-07-17
   Based on: Player Schedule View 1.5

   Changes from 1.5:
   - Migrated from Code Manager snippet ("Player Schedule View")
     to a tracked theme file, wrapped in
     [spp_player_schedule_view] shortcode.
   - Fixed session_status() check: was "!session_status() ==
     PHP_SESSION_ACTIVE" (always false due to precedence, so
     session_start() never actually ran); now correctly checks
     "session_status() !== PHP_SESSION_ACTIVE".
   - Added a Travel column, visible to admin/editor only
     (spp_is_admin_or_editor()) -- lets convenors spot missing
     or mismatched carpool codes (e.g. same-surname players not
     actually linked) directly from the schedule view, without
     needing to open the membership editor separately. Same
     "gate one extra field behind a role check" approach already
     used for the P- preferred-player prefix handling elsewhere.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_player_schedule_view', 'spp_player_schedule_view_shortcode' );

function spp_player_schedule_view_shortcode() {
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

$published = $wpdb->get_var($wpdb->prepare("
    SELECT add_to_menu
    FROM {$wpdb->prefix}wpda_project_page
    WHERE page_sequence = %d
	AND page_id = 70
    AND add_to_menu = 'Yes'
    LIMIT 1
", $Event));

$is_admin_or_editor = spp_is_admin_or_editor();

if (!$is_admin_or_editor) {
    if (!$published) {
        echo "<p>The schedule for this week is not currently available.</p>";
        return;
    }
}

echo do_shortcode("[cmruncode name='Create View']");
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

    echo '<div class="spp-time-block">';
    echo '<div class="spp-time-header">' . esc_html($t_desc) . '</div>';
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
