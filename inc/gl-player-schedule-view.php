<?php
/* =========================================================
   GL Player Schedule View
   Version: 1.8.0
   Date: 2026-09-14
   Based on: Player Schedule View 1.5

   Changes from 1.7.0 (CSS-only -- desktop court-card cropping fix at
   4+ courts in one time slot; see .spp-groups-grid/.spp-group-card/
   .spp-col-name's own comments for the full root-cause writeup):
   - .spp-group-card's flex-basis raised from 260px to 380px (a real
     verified minimum, not a starting point flex-grow happened to
     inflate past) and given an explicit min-width floor -- up to 3
     courts per time slot still fits one row exactly as before; a 4th+
     now wraps to its own row instead of all 4 cramming onto one row
     at ~300px each.
   - .spp-col-name no longer ellipsis-truncates (overflow:hidden/
     text-overflow:ellipsis/white-space:nowrap) -- wraps to a second
     line instead, so a name is never cropped regardless of exact
     pixel math as the roster changes week to week. white-space is
     set explicitly (not just omitted) to override an unrelated,
     same-class-name collision from css/spp-registrant-list.css (a
     disabled/superseded feature whose CSS is still enqueued site-
     wide) -- found live while testing this fix, flagged separately,
     not touched here.
   - Mobile (<=600px) single-column behavior unchanged/unaffected.

   Changes from 1.6.5:
   - Dropped the schedules_w dependency entirely. Registrant
     counts, groups, and players now read Schedules/Groups/
     Courts directly. Root cause: schedules_w's underlying
     table is retargetable via CM254's `file` shortcode param,
     and the "Scores per game - choose date" admin feature
     (Code Manager snippet "Black for scores - colour") calls
     [cmruncode name='Create View' file="Schedules_Scores_{$Event}"]
     as a side effect of a read-only score-review lookup --
     defaulting to the most recent Schedules_Scores_ table even
     with no date picked. On 2026-08-16 that left schedules_w
     pointed at Schedules_Scores_160 (Aug 10) while
     spp_current_event was already 161 (Aug 17), and this page
     had no way to detect the mismatch since the header
     (gl_event_occurrences via spp_current_event) and the grid
     (schedules_w) were fully independent lookups. Schedules
     always carries the correct current event_id once production
     has run, so reading it directly removes this class of bug
     structurally instead of depending on schedules_w never being
     repointed by another feature. The existence-check +
     [cmruncode name='Create View'] call from 1.6.2/1.6.3 is
     removed here as dead code -- this page no longer reads
     schedules_w at all, so there's nothing left to guard.
     schedules_w itself is unchanged/still built by
     gl-schedule-production.php and spp-schedule-production.php;
     see "Black for scores - colour" for the fix that stops it
     from being repointed by score review.

   Changes from 1.6.4:
   - Added an event title + date header at the top of the page,
     reusing the .spp-time-header styling (blue band, centered,
     white text) so it reads as part of the same visual system
     as the per-slot headers below it rather than a separate
     ad-hoc style. Sourced from gl_event_occurrences.title /
     event_date via the convenor lookup query already run on
     this page (extended to also select title, event_date) --
     no new query added.

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

$time_slots = $wpdb->get_results("SELECT T_ID, T_desc FROM Times WHERE Active = 1 ORDER BY T_ID");

// Get event title/date + convenor name and phone dynamically from current event
$_conv_event_id = (int) get_option('spp_current_event', 0);
$_conv = $wpdb->get_row($wpdb->prepare(
    "SELECT o.title, o.event_date, m.first_name, m.user_phone
     FROM {$wpdb->prefix}gl_event_occurrences o
     JOIN membership m ON o.convenor_id = m.user_id
     WHERE o.id = %d", $_conv_event_id
), ARRAY_A);
$_conv_name  = $_conv ? $_conv['first_name'] : 'the convenor';
$_conv_phone = $_conv ? $_conv['user_phone'] : '';
$_conv_msg   = $_conv_phone
    ? "Please contact {$_conv_name} directly at {$_conv_phone} to notify of any last-minute schedule issues."
    : "Please contact {$_conv_name} to notify of any last-minute schedule issues.";

if ($_conv && !empty($_conv['title'])) {
    $_event_date_disp = !empty($_conv['event_date']) ? date('F j, Y', strtotime($_conv['event_date'])) : '';
    echo '<div class="spp-time-header spp-event-header">' . esc_html($_conv['title'])
       . ($_event_date_disp ? ' <span class="spp-slot-count">(' . esc_html($_event_date_disp) . ')</span>' : '')
       . '</div>';
}

echo '<p class="spp-sub-intro">' . esc_html($_conv_msg) . '</p>';

// Page-total registrant count -- Schedules rows excluding the Group 99
// (dropped/unscheduled) sentinel, matching the exclusion the groups query
// below already applies, so this stays consistent with what's listed.
// Reads Schedules/Groups directly (not schedules_w -- see 1.7.0 changelog).
$total_registrants = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM Schedules s
     JOIN Groups g ON s.group_id = g.GP_ID
     WHERE g.GP_name != 'Group 99'"
);
echo '<p class="spp-total-registrants">Total registrants: ' . esc_html($total_registrants) . '</p>';

foreach ($time_slots as $slot) {
    $t_id   = $slot->T_ID;
    $t_desc = $slot->T_desc;

    $groups = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT g.GP_name, c.Crt_name, s.time_id AS t_ID "
        . "FROM Schedules s "
        . "JOIN Groups g ON s.group_id = g.GP_ID "
        . "JOIN Courts c ON s.Crt_ID = c.Crt_ID "
        . "WHERE s.time_id = %d AND g.GP_name != 'Group 99' "
        . "ORDER BY c.Crt_name",
        $t_id
    ));

    if (empty($groups)) continue;

    $slot_registrants = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM Schedules s
         JOIN Groups g ON s.group_id = g.GP_ID
         WHERE s.time_id = %d AND g.GP_name != 'Group 99'",
        $t_id
    ));

    echo '<div class="spp-time-block">';
    echo '<div class="spp-time-header">' . esc_html($t_desc)
       . ' <span class="spp-slot-count">(' . esc_html($slot_registrants) . ' registrants)</span></div>';
    echo '<div class="spp-groups-grid">';

    foreach ($groups as $group) {
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT CONCAT(s.first_name, ' ', s.last_name) AS full_name, "
            . "s.user_phone, s.travel AS Travel, CAST(s.Rank AS UNSIGNED) AS Rank "
            . "FROM Schedules s "
            . "JOIN Groups g ON s.group_id = g.GP_ID "
            . "WHERE s.time_id = %d AND g.GP_name = %s "
            . "ORDER BY s.Rank",
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

/* ── Groups grid ──────────────────────────────────────────
   Root cause of the 4+-courts cropping bug: with flex-wrap +
   flex:1 1 260px (the old rule), the browser packs as many
   260px-BASIS cards onto a row as geometrically fit, THEN grows
   them to fill the row -- so how much each card actually ends up
   getting depends entirely on how many happened to fit at that
   260px starting point, not on what's actually comfortable. 3
   cards in this page's real content-width row grow to ~400px each
   (plenty of room, which is why up to 3 always looked fine); 4
   cards fit in that same row at ~300px each -- just enough to
   satisfy the wrap algorithm, not enough for a full name + phone +
   travel badge, so .spp-col-name's overflow:hidden/text-overflow:
   ellipsis kicks in and crops names.

   FIX: raise the floor from 260px to 380px -- a real minimum,
   verified live against this page's own longest real names
   ("Joanne M. McCracken", "Shelley T. Tackaberry") rather than an
   arbitrary starting point that only happens to look fine once
   flex-grow inflates it. min-width (not just flex-basis) makes it a
   hard floor: flex-basis alone is still just a "preferred" size the
   line-breaking algorithm uses to decide how many cards join a row,
   and gives no guarantee against flex-shrink compressing a card
   below it if a row's total basis ever comes out fractionally over
   the container width; min-width can't be shrunk past regardless.
   At this page's real content width (measured ~1240px, NOT the
   ~600px a screenshot can misleadingly suggest before Divi's own
   layout fully settles), 3 cards at 380px + gaps fit on one row as
   before -- unaffected, no regression -- while a 4th now pushes the
   whole row over that width and wraps to its own row instead of
   cramming in, getting the full row's width via the same
   flex-grow:1 every card already had.

   NOT CSS Grid: tried repeat(auto-fit, minmax(380px, 1fr)) first --
   functionally correct in isolation, but on THIS page's real DOM
   (a Divi Code module inside a flex-direction:column column/row
   structure) it triggered an unrelated Divi layout bug: a grid
   child's own intrinsic (shrink-to-fit) width contribution to an
   ancestor with flex-grow:0 computes far narrower than a flex
   child's does for the exact same content, collapsing the whole
   module from ~1240px down to ~600px -- confirmed by swapping
   display:grid back to flex on the live page and watching the
   ancestor's measured width jump from 601px to 1240px with no other
   change. Flexbox has no such interaction here, so it stays flex,
   just with a real minimum width instead of a starting point that
   gets grown away from. */
.spp-groups-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* ── Group card ───────────────────────────────────────── */
.spp-group-card {
    flex: 1 1 380px;
    min-width: 380px;
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
    /* Wrap instead of ellipsis-truncate (was overflow:hidden +
       text-overflow:ellipsis + white-space:nowrap): the 380px card
       minimum above (see .spp-groups-grid's own comment) comfortably
       covers this roster's real names in the common case, but a
       long name + a wide travel badge on the SAME row can still
       come up a few pixels short even at that width -- that's a
       per-row content combination, not something a single fixed
       card-width budget can guarantee against for every possible
       name/travel-code length as the roster changes week to week.
       Wrapping to a second line (row height grows, align-items:
       center on .spp-player-row keeps it looking intentional against
       the still-single-line rank/phone/travel columns) means a name
       is simply never cropped, full stop, regardless of exact
       pixel math -- the actual bug being fixed here, guaranteed
       rather than budgeted for.
       white-space is set explicitly to `normal` (not just omitted)
       for a real reason found while testing this fix live: an
       entirely unrelated stylesheet, css/spp-registrant-list.css
       (a disabled/superseded feature -- see functions.php's own
       "Superseded by gl-events plugin" comment on that PHP file --
       whose CSS is still enqueued site-wide regardless), happens to
       define its own `.spp-col-name { white-space: nowrap; }` under
       the exact same class name. Simply removing nowrap from THIS
       rule left that collision free to keep applying unopposed
       (per-property cascade: with no competing declaration from this
       rule, the other stylesheet's nowrap just wins by default) --
       confirmed via document.styleSheets that this is genuinely a
       different file, not a duplicate rule in this one. Explicitly
       overriding it here is the targeted fix for this page; the
       orphaned stylesheet itself is a separate, pre-existing issue
       flagged in this fix's own report, not touched here. */
    flex: 1 1 auto;
    min-width: 0;
    overflow-wrap: break-word;
    white-space: normal;
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
        min-width: 0;
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