<?php
/* =========================================================
   Ace/Queen of the Courts — Screens
   Version: 1.8.0
   Date: 2026-09-14

   Changes from 1.7.0:
   - New 30-minutes-before-start access gate on [spp_kq_live] itself
     (spp_kq_event_screens_open()/spp_kq_render_too_early_notice(),
     wired into spp_kq_live_shortcode() before
     spp_kq_handle_post_actions() runs) -- previously the shortcode
     gated only on spp_kq_can_facilitate() (is_user_logged_in()), so a
     facilitator could open check-in/roster-adjust/anything for an
     event days or weeks out (the event picker itself lists the next 8
     upcoming occurrences regardless of date). This is a NEW, separate,
     earlier/broader rule -- the existing Start-Round-1 date-only gate
     ($can_start_today in spp_kq_render_start_screen(), re-checked in
     this file's own 'start_round1' POST case) is completely unchanged
     and still applies on top of this one. Administrator-exempt,
     matching that existing gate's own spp_is_admin() exemption
     exactly. See this file's own "30-minutes-before-start access gate"
     section header for the full writeup, including the timezone
     reasoning (confirmed against this site's real WP timezone_string,
     not assumed).

   Changes from 1.6.0 (Check-in + mid-event roster swap + court
   cancellation -- see the conversation this was built from for the
   full spec; most of the actual mechanics live in
   inc/spp-kq-live.php/inc/spp-kq-checkin.php/inc/spp-kq-roster.php, see
   each of those files' own changelogs):
   - spp_kq_render_start_screen() is now a check-in screen: lists every
     confirmed registrant with a toggle check-in button
     (spp_kq_get_confirmed_registrants_named()/
     spp_kq_get_checked_in_user_ids(), inc/spp-kq-checkin.php), and its
     "Cannot start" validation now runs against the CHECKED-IN count,
     not the raw confirmed count.
   - spp_kq_handle_post_actions(): 'start_round1' now reconciles
     check-in reality (withdraws anyone confirmed-but-not-checked-in)
     BEFORE calling spp_kq_transition_start_round1(), which is
     otherwise unchanged. New 'checkin_mark'/'checkin_unmark' cases.
     'start_play' now surfaces spp_kq_transition_start_play()'s own
     error (new in that function -- the understaffed-court guard) to
     the facilitator instead of discarding it. New 'roster_swap'/
     'roster_fill_slot'/'cancel_court' cases, thin wrappers over
     spp_kq_swap_player()/spp_kq_fill_open_slot()/spp_kq_cancel_court()
     (inc/spp-kq-live.php).
   - spp_kq_render_overview_screen()/spp_kq_render_in_play_screen(): a
     cancelled court now shows a badge instead of team lists/score
     inputs (the in-play screen's own JS wiring loop guards against a
     card with no score inputs to wire up); an understaffed court (a
     just-cancelled court's replacement slots re-created empty, see
     inc/spp-kq-live.php's own changelog) shows a "needs players"
     notice on the Overview screen and blocks the Start Play button
     entirely, both pointing at Roster Adjust.
   - spp_kq_styles(): new .kq-btn-small/.kq-roster-list/
     .kq-checkin-list/.kq-swap-row/.kq-swap-form/.kq-swap-select rules
     -- kq-btn-small and kq-roster-list existed in markup before this
     pass with no rule of their own (harmless fallback to default
     sizing/a bare list); given real definitions now that more screens
     use them.

   Changes from 1.5.0:
   - CSS FIX (spp_kq_styles()): scoreboard cards (.kq-court-grid/
     .kq-court-card, shared by the live Full Scoreboard screen and
     [spp_kq_event_detail]) stayed narrow at desktop widths, wrapping a
     "Red: <names> — <score>" line onto two lines. Root cause: .kq-wrap's
     max-width was a flat 560px with no responsive variance at all -- the
     only thing bounding the grid's available width on any viewport,
     phone through desktop alike, so a wide screen never gave the grid
     any more room than a narrow one did. Added two min-width-only media
     queries (600px, 900px) that widen .kq-wrap and raise
     .kq-court-grid's own minmax() floor in step, so cards get wider (not
     more numerous) as real screen width increases -- 2 columns per round
     still fits at every tier, only each column's own width grows
     (~274px base to ~444px at 900px+). min-width-only by construction:
     nothing here can affect a viewport narrower than 600px, so the
     already-correct phone rendering is untouched. CSS-only -- no
     PHP/data logic changed.

   Changes from 1.4.0:
   - spp_kq_render_full_scoreboard_screen()'s round/court markup moved
     into a new shared function, spp_kq_render_scoreboard_markup()
     (inc/spp-kq-history.php) -- this function is now just fetch (via
     spp_kq_get_full_scoreboard()) and hand off to that renderer.
     Confirmed byte-identical output for the live screen before/after.
     Done to let the new historical Event Detail view
     ([spp_kq_event_detail], same file) reuse the exact same layout
     against archived data (spp_kq_get_history_scoreboard()) instead of
     duplicating this markup.

   Changes from 1.3.0:
   - UX FIX (Full Reset investigated, confirmed working correctly at the
     DB level via marker-value testing -- see that investigation): a
     successful Full Reset rendered byte-identical to a silent failure,
     since this dispatcher's own convention was '' on success, plain
     text only on error, and Full Reset itself has nothing to visibly
     clear when the occurrence never progressed past 'not_started'.
     Added SPP_KQ_NOTICE_OK_PREFIX, a minimal opt-in marker a case can
     prefix its return with to have spp_kq_render_occurrence_header()
     style it kq-notice-ok (this file's own existing success-color CSS,
     already used by the in-play score-submission JS) instead of the
     default kq-notice-err -- 'full_reset' is the only case using it so
     far. No other case's returned text changes, so no other action's
     rendering changes at all.

   Changes from 1.2.0:
   - Replaced the Start screen's "fix your roster" link -- previously
     gl-registration-admin/?gl_reg_occ_id=X, the generic GL Events
     plugin tool gated to administrator/editor/convenor
     (GL_Roles::can_view_registrants()) -- with a link to a new
     ?kq_view=roster screen (spp_kq_render_roster_screen(),
     inc/spp-kq-roster.php), gated only by this shortcode's own
     spp_kq_can_facilitate() (any logged-in member), matching KQ's own
     access model instead of borrowing a stricter one. Two new
     spp_kq_handle_post_actions() cases, 'roster_add'/'roster_remove',
     same plain nonce-POST-and-rerender convention as every other
     action here. gl-registration-admin.php and every other gl-events
     plugin file are untouched -- this is a standalone replacement for
     KQ's use case only, not a fix to the shared tool.

   Changes from 1.1.0:
   - BUG FIX: 'cancel_event' in spp_kq_handle_post_actions() below now
     also calls spp_kq_finalize_event_history_and_recap()
     (inc/spp-kq-history.php), same call pattern as 'end_event' --
     immediately after spp_kq_maybe_publish_to_club_ratings(), only once
     $r['won'] confirms the transition actually happened. 1.1.0
     deliberately left this out of 'cancel_event', on the assumption a
     cancelled event had nothing worth archiving -- wrong: any round
     that had already reported before the cancellation is real data
     (spp_kq_transition_cancel_event() only ever discards the CURRENT
     round's unreported courts; earlier rounds are untouched), and
     Club Rating publish already treated it that way. Both transitions
     now trigger archiving/recap identically -- see
     inc/spp-kq-history.php's own header, which no longer describes this
     as an intentional asymmetry.

   Changes from 1.0.0:
   - Added the Full Scoreboard screen (spp_kq_render_full_scoreboard_screen(),
     spp_kq_render_scoreboard_link()): a ?kq_view=scoreboard flag layered
     on top of the phase switch below, not a new phase of its own --
     purely additive/read-only, no schema change, no interaction with
     spp_kq_events.phase/current_round or the movement algorithm at all.
     Shows every completed round/court/player/score for the occurrence
     so far, via spp_kq_get_full_scoreboard() (inc/spp-kq-history.php).
   - 'end_event' in spp_kq_handle_post_actions() below now also calls
     spp_kq_finalize_event_history_and_recap() (inc/spp-kq-history.php)
     immediately after spp_kq_maybe_publish_to_club_ratings(), same
     trigger point, only once $r['won'] confirms the transition actually
     happened -- archives spp_kq_history and sends each participant a
     recap email.

   PURPOSE:
   The [spp_kq_live] shortcode and its seven screens, plus the one
   AJAX action (the card draw). Everything here is UI/dispatch on top
   of inc/spp-kq-live.php's gate + transition mechanics and
   inc/spp-kq-movement.php's pure algorithm -- no game logic lives in
   this file.

   Stage 2 (final part) of a staged build (see conversation). Screen
   selection is driven entirely by spp_kq_events.phase/current_round
   plus, for 'organizing' specifically, whether round 1's draw is
   still incomplete:

     not_started                              -> Start screen
     organizing, round 1, unclaimed slots > 0  -> Draw screen
     organizing, otherwise                     -> Overview screen
     in_play                                   -> In-Play placeholder
     complete                                  -> Complete screen
     cancelled                                 -> Cancelled screen

   ACCESS: spp_kq_can_facilitate() (inc/spp-kq-live.php) is the only
   gate, checked once at the top of the shortcode and again in the
   AJAX handler -- nowhere else in this file re-derives or narrows it.

   ACTIONS: Start Round 1 Draw / Start Play / End Event / Cancel Event
   are plain nonce-protected POST forms with no redirect afterward
   (same convention as spp-schedule-adjust.php, registration-admin.php,
   spp-remove-inactive-ladder-users.php elsewhere in this codebase) --
   the page just re-renders whatever screen the new state calls for.
   The card draw is the one AJAX action, matching spp-score-entry.php's
   convention for many-small-taps-with-live-feedback interactions.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in marker prefix a spp_kq_handle_post_actions() case can put on its
 * returned string to have spp_kq_render_occurrence_header() style it as a
 * success notice (kq-notice-ok) instead of the dispatcher's longstanding
 * default (kq-notice-err, historically used for every notice since this
 * dispatcher's only convention was "'' on success, plain text on
 * failure"). See that function's own comment for the full reasoning.
 */
const SPP_KQ_NOTICE_OK_PREFIX = "\u{2713} "; // "✓ "

// =============================================================
// Small read helpers specific to rendering (mechanics live in
// spp-kq-live.php; these just shape data for display).
// =============================================================

function spp_kq_get_occurrence_summary( int $occurrence_id ) : ?array {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT occurrence_id, eff_title, event_date, eff_event_time, category_name
         FROM {$view} WHERE occurrence_id = %d",
        $occurrence_id
    ), ARRAY_A );
    return $row ?: null;
}

// =============================================================
// 30-minutes-before-start access gate (1.2.0). Separate, earlier/
// broader rule from the existing Start-Round-1 date-only gate
// ($can_start_today in spp_kq_render_start_screen(), re-checked
// server-side in spp_kq_handle_post_actions()'s 'start_round1' case)
// -- that one is unchanged and still applies on top of this one, once
// a facilitator is past this gate. This one blocks EVERYTHING
// reachable for an occurrence (check-in, roster-adjust, live play,
// POST actions included -- checked in spp_kq_live_shortcode() before
// spp_kq_handle_post_actions() runs at all, not just before the
// phase-screen switch), not just Start Round 1 itself.
//
// TIMEZONE: this site's WP timezone is confirmed correctly set to a
// real IANA zone (timezone_string = 'America/Toronto', not a static
// gmt_offset), so current_time() is DST-aware. strtotime() on a plain
// 'event_date event_time' string is interpreted under PHP's own
// default timezone, which WordPress sets to UTC -- current_time()'s
// values live in that exact same "local wall-clock time, readable as
// if it were UTC" domain (a long-documented WP quirk), so the two are
// directly comparable with no further conversion. Confirmed, not
// assumed: this is the identical convention spp_kq_render_picker_row()/
// spp_kq_render_occurrence_header() already use to display
// eff_event_time correctly (date_i18n(strtotime($eff_event_time))),
// and spp_kq_render_start_screen()'s own current_time('Y-m-d') ===
// $event_date check already relies on for the existing gate.
// =============================================================

/**
 * Real scheduled start datetime for an occurrence, as a timestamp in
 * the current_time()-compatible domain described above. Returns null
 * if $event_time is missing -- gl-events' own schema allows
 * eff_event_time to be NULL for a truly standalone occurrence with no
 * series and no time of its own (event_occurrences.event_time is
 * nullable; only event_series.event_time is NOT NULL) -- confirmed
 * via direct query that zero real KQ (category 2/3) occurrences hit
 * this today, but callers must not treat null as "open" regardless --
 * see spp_kq_event_screens_open()'s own fail-closed default.
 */
function spp_kq_get_occurrence_start_timestamp( string $event_date, ?string $event_time ) : ?int {
    if ( ! $event_date || ! $event_time ) {
        return null;
    }
    $ts = strtotime( $event_date . ' ' . $event_time );
    return ( $ts !== false ) ? $ts : null;
}

/**
 * Whether [spp_kq_live]'s screens are open for this occurrence right
 * now -- actual scheduled start time minus a 30-minute early-access
 * window, current server clock. Administrator exemption is checked by
 * the caller (spp_kq_live_shortcode()), not here -- same "gate belongs
 * to the caller" pattern the existing Start-Round-1 checks already
 * use, so spp_is_admin() is asserted in exactly one place per gate.
 *
 * FAILS CLOSED: an occurrence whose start time can't be determined at
 * all (see spp_kq_get_occurrence_start_timestamp()) is NOT open --
 * there is nothing real to gate against otherwise, and silently
 * allowing access on missing data would defeat the point of this gate.
 */
function spp_kq_event_screens_open( string $event_date, ?string $event_time ) : bool {
    $start_ts = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
    if ( $start_ts === null ) {
        return false;
    }
    return current_time( 'timestamp' ) >= ( $start_ts - 30 * MINUTE_IN_SECONDS );
}

/**
 * "Not open yet" notice for a too-early access attempt -- states the
 * actual computed opening time (start minus 30 minutes) so a
 * facilitator knows exactly when to come back, rather than a generic
 * "try again later." Own small wrapper (not the full kq-wrap styles)
 * since nothing else on this response needs them.
 */
function spp_kq_render_too_early_notice( string $event_date, ?string $event_time ) : string {
    $start_ts = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
    if ( $start_ts === null ) {
        return '<div class="kq-wrap"><p class="kq-hint">This event\'s scheduled time isn\'t set yet -- please check back closer to the event, or contact an administrator.</p></div>';
    }
    $opens_at  = $start_ts - 30 * MINUTE_IN_SECONDS;
    $when      = date_i18n( 'g:ia', $opens_at ) . ' on ' . date_i18n( 'l, F j', $opens_at );
    return '<div class="kq-wrap"><p class="kq-hint">This event doesn\'t open until ' . esc_html( $when ) . '.</p></div>';
}

function spp_kq_count_unclaimed( int $occurrence_id, int $round_number ) : int {
    global $wpdb;
    $table = spp_kq_assignments_table();
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND user_id IS NULL",
        $occurrence_id, $round_number
    ) );
}

function spp_kq_player_name( ?string $first, ?string $last, int $user_id ) : string {
    $name = trim( ( $first ?? '' ) . ' ' . ( $last ?? '' ) );
    return $name !== '' ? $name : "Member #{$user_id}";
}

/**
 * Confirmed registrants for this occurrence not yet drawn in round 1,
 * named via membership (this codebase's established convention --
 * see gl-registrant-list.php / registration-admin.php -- not WP
 * display_name).
 */
function spp_kq_get_not_yet_drawn( int $occurrence_id ) : array {
    global $wpdb;

    $confirmed_ids = spp_kq_confirmed_user_ids( $occurrence_id );
    if ( empty( $confirmed_ids ) ) {
        return array();
    }

    $table = spp_kq_assignments_table();
    $drawn_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$table} WHERE occurrence_id = %d AND round_number = 1 AND user_id IS NOT NULL",
        $occurrence_id
    ) ) );

    $remaining_ids = array_values( array_diff( $confirmed_ids, $drawn_ids ) );
    if ( empty( $remaining_ids ) ) {
        return array();
    }

    $placeholders = implode( ',', array_fill( 0, count( $remaining_ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_id IN ({$placeholders})",
        $remaining_ids
    ), ARRAY_A );

    $by_id = array();
    foreach ( $rows as $r ) {
        $by_id[ (int) $r['user_id'] ] = spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] );
    }

    $out = array();
    foreach ( $remaining_ids as $uid ) {
        $out[] = array( 'user_id' => $uid, 'name' => $by_id[ $uid ] ?? "Member #{$uid}" );
    }
    return $out;
}

/**
 * Round 1's reveal state for the draw screen: every (court, color)
 * slot, always exactly 2 entries per color, each either a player name
 * (already drawn) or null (still face-down). Used both for the
 * initial server render and to let a mid-draw page reload show
 * exactly where things stand.
 */
function spp_kq_get_draw_reveal_state( int $occurrence_id, array $courts_order ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, a.user_id, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = 1
         ORDER BY a.court_name, a.team_color, a.id",
        $occurrence_id
    ), ARRAY_A );

    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array( null, null ), 'black' => array( null, null ) );
    }
    $cursor = array_fill_keys( $courts_order, array( 'red' => 0, 'black' => 0 ) );

    foreach ( $rows as $r ) {
        $court = $r['court_name']; $color = $r['team_color'];
        if ( ! isset( $cursor[ $court ][ $color ] ) || $cursor[ $court ][ $color ] > 1 ) {
            continue; // defensive -- should never happen with exactly 2 slots/color
        }
        $idx = $cursor[ $court ][ $color ]++;
        $name = $r['user_id'] !== null ? spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ) : null;
        $out[ $court ][ $color ][ $idx ] = $name;
    }
    return $out;
}

/**
 * One round's full court/team roster (names only, all slots assumed
 * filled -- used by the Overview screen, which only ever shows once
 * round 1's draw is complete, or a round > 1 whose assignments were
 * all written atomically by the movement algorithm).
 */
function spp_kq_get_round_court_view( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array(), 'black' => array() );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.user_id IS NOT NULL
         ORDER BY a.court_name, a.team_color",
        $occurrence_id, $round_number
    ), ARRAY_A );

    foreach ( $rows as $r ) {
        if ( ! isset( $out[ $r['court_name'] ] ) ) continue;
        $out[ $r['court_name'] ][ $r['team_color'] ][] = spp_kq_player_name( $r['first_name'], $r['last_name'], 0 );
    }
    return $out;
}

/**
 * Final-round Aces winner, for the Complete screen. Returns null if
 * Aces never reported a score this round (End Event can fire from
 * 'organizing' before any play happens at all -- see this feature's
 * own transition docs) rather than guessing.
 */
function spp_kq_get_final_winner_names( int $occurrence_id, int $round_number ) : ?string {
    global $wpdb;
    $scores_table = spp_kq_scores_table();
    $assignments_table = spp_kq_assignments_table();

    $score = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d AND court_name = 'Aces'",
        $occurrence_id, $round_number
    ), ARRAY_A );

    if ( ! $score || $score['red_score'] === null || $score['black_score'] === null ) {
        return null;
    }

    $winning_color = ( (int) $score['red_score'] > (int) $score['black_score'] ) ? 'red' : 'black';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT m.first_name, m.last_name FROM {$assignments_table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.court_name = 'Aces' AND a.team_color = %s",
        $occurrence_id, $round_number, $winning_color
    ), ARRAY_A );

    if ( empty( $rows ) ) return null;

    $names = array_map( fn( $r ) => spp_kq_player_name( $r['first_name'], $r['last_name'], 0 ), $rows );
    return implode( ' & ', $names );
}

/**
 * Cancelled-event summary for the Cancelled screen: which courts kept
 * a real result (with the actual score, so a facilitator can confirm
 * "yes, that's the score that counted") vs. which were discarded.
 * Reconstructed purely from what's left after cancellation -- courts
 * still present in spp_kq_scores for that round were kept (a row only
 * survives Cancel Event if it was already fully reported); the rest
 * of the event's fixed court set (spp_kq_determine_courts_order(),
 * which still resolves correctly even when round 1 itself was wiped
 * -- see that function's own fallback) were discarded.
 */
function spp_kq_get_cancellation_summary( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $scores_table = spp_kq_scores_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );

    $kept_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT court_name, red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d",
        $occurrence_id, $round_number
    ), ARRAY_A );

    $kept = array();
    foreach ( $kept_rows as $r ) {
        $kept[ $r['court_name'] ] = array( 'red' => (int) $r['red_score'], 'black' => (int) $r['black_score'] );
    }

    $discarded = array_values( array_diff( $courts_order, array_keys( $kept ) ) );

    return array( 'kept' => $kept, 'discarded' => $discarded );
}

// =============================================================
// Shared CSS (embedded once per render -- same convention as
// spp-score-entry.php)
// =============================================================

function spp_kq_styles() : string {
    return '<style>
        .kq-wrap { max-width:560px; margin:10px auto; font-family:Arial,sans-serif; font-size:15px; line-height:1.4; color:#222; }
        .kq-back a { color:#3766AB; text-decoration:none; font-size:14px; }
        .kq-heading { margin:6px 0 2px; font-size:20px; }
        .kq-subheading { margin:0 0 14px; color:#666; font-size:14px; }
        .kq-hint { margin:0 0 14px; color:#666; font-size:14px; font-style:italic; }
        .kq-caveat { margin:0 0 14px; padding:8px 12px; background:#f7f7f7; border-left:3px solid #999; color:#555; font-size:13px; }
        .kq-meta { color:#555; margin-bottom:14px; }
        .kq-round-label { font-weight:bold; font-size:16px; margin:0 0 12px; color:#2c3e50; }
        .kq-round-label-tight { font-weight:bold; font-size:16px; margin:0 0 2px; color:#2c3e50; }
        .kq-notice { padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:14px; }
        .kq-notice-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .kq-notice-ok { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .kq-status { background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:14px; color:#2c3e50; }
        .kq-score-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:14px; }
        .kq-score-row label { font-size:13px; color:#555; }
        .kq-score-input { display:block; width:70px; padding:8px; font-size:18px; text-align:center; border:1px solid #bbb; border-radius:6px; margin-top:4px; }
        .kq-saved { font-size:13px; color:#27ae60; font-weight:bold; }
        .kq-warn { background:#fff8e1; border:1px solid #e67e22; border-radius:6px; padding:12px 14px; color:#7a4a00; }
        .kq-warn a { color:#3766AB; }
        .kq-btn { padding:10px 20px; border:none; border-radius:6px; font-size:15px; cursor:pointer; }
        .kq-btn-primary { background:#3766AB; color:#fff; }
        .kq-btn-secondary { background:#888; color:#fff; }
        .kq-btn-danger { background:#c0392b; color:#fff; }
        .kq-action-row { display:flex; gap:10px; margin-top:16px; }
        .kq-action-row-right { justify-content:flex-end; }
        .kq-inline-form { display:inline-block; }
        .kq-picker-list { display:flex; flex-direction:column; gap:8px; }
        .kq-picker-row { display:flex; align-items:center; gap:4px; padding:10px 10px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#222; flex-wrap:wrap; }
        .kq-picker-row:hover { background:#f5f8fc; }
        /* flex-shrink:0 (via 1 0 auto) matters here: title is the only
           item whose text can wrap across multiple words, so a plain
           flex-shrink:1 dumped nearly the entire deficit onto it alone --
           date/regcount/status are white-space:nowrap, so their own
           automatic minimum is already their full natural width, never
           really shrinking. Letting the whole ROW wrap onto a second
           line under real pressure (flex-wrap:wrap on the row, still
           active) degrades far more gracefully than wrapping mid-title.
           Every other measurement on this row (padding, gap, badge/status
           padding) is trimmed as tight as still reads comfortably, purely
           to buy title the room it needs within the fixed 560px .kq-wrap
           max-width -- the longest real titles ("Queen of the Courts...")
           were still a couple dozen px over budget even with 1 0 auto alone. */
        .kq-picker-title { font-weight:bold; flex:1 0 auto; }
        .kq-picker-date { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-regcount { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-status { font-size:12px; font-weight:bold; padding:2px 6px; border-radius:12px; white-space:nowrap; }
        .kq-picker-row--test { background:#faf7ff; border-color:#d8cdf0; }
        .kq-picker-row--test:hover { background:#f3edfc; }
        .kq-picker-badge { font-size:10px; font-weight:bold; padding:1px 5px; border-radius:9px; white-space:nowrap; letter-spacing:.02em; }
        .kq-picker-badge--test { background:#e8def8; color:#6b3fa0; }
        .kq-picker-section-heading { font-size:15px; margin:22px 0 4px; color:#555; }
        .kq-status-not-started { background:#eee; color:#666; }
        .kq-status-organizing { background:#fff3cd; color:#8a6100; }
        .kq-status-in_play { background:#d4edda; color:#155724; }
        .kq-status-complete { background:#dde7f3; color:#2c3e50; }
        .kq-status-cancelled { background:#f8d7da; color:#721c24; }
        .kq-court-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:10px; }
        .kq-court-card { border:1px solid #ddd; border-radius:8px; padding:12px 14px; background:#fff; }
        .kq-court-name { font-weight:bold; font-size:16px; margin-bottom:6px; color:#2c3e50; }
        .kq-team { font-size:14px; margin-bottom:2px; }
        .kq-team-red { color:#c0392b; }
        .kq-team-black { color:#222; }
        .kq-draw-progress { color:#555; font-size:14px; margin:0 0 16px; }
        .kq-draw-columns { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
        .kq-draw-col { flex:1 1 220px; }
        .kq-draw-col h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        #kq-not-drawn-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
        .kq-player-btn { width:100%; padding:10px; font-size:15px; border:2px solid #3766AB; background:#fff; color:#3766AB; border-radius:6px; cursor:pointer; text-align:left; }
        .kq-player-btn.kq-selected { background:#3766AB; color:#fff; }
        #kq-card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(56px,1fr)); gap:8px; }
        .kq-card { aspect-ratio:2/3; background:#2c3e50; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; cursor:pointer; user-select:none; }
        .kq-card:hover { background:#3f5a80; }
        .kq-draw-revealed h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        .kq-slot { color:#999; }
        .kq-slot.kq-slot-filled { color:inherit; font-weight:bold; }
        .kq-cancel-summary { display:flex; gap:20px; flex-wrap:wrap; }
        /* !important: the theme own content-area h3 color rule outranks any
           class-based selector here (confirmed by testing -- even a doubled
           .kq-wrap prefix did not win), and the whole point of these two is the
           green/red semantic distinction, so it cannot just inherit the theme
           default the way every other heading in this feature harmlessly does. */
        .kq-kept h3 { color:#155724 !important; font-size:14px; margin:0 0 6px; }
        .kq-discarded h3 { color:#721c24 !important; font-size:14px; margin:0 0 6px; }
        .kq-cancel-summary ul { margin:0; padding-left:18px; font-size:14px; }
        .kq-full-reset-row { margin-top:28px; padding-top:14px; border-top:1px dashed #ccc; text-align:right; }
        .kq-full-reset-row .kq-btn { font-size:13px; padding:6px 14px; opacity:.85; }
        /* 1.1.0: check-in list, roster list, and the live swap screen
           (inc/spp-kq-checkin.php / inc/spp-kq-roster.php) all share
           this small-button/list vocabulary -- kq-btn-small and
           kq-roster-list existed in markup before this pass but had no
           rule of their own (harmless -- they just fell back to a
           normal-size button / a bare list); added here rather than
           left undefined now that more screens rely on them. */
        .kq-btn-small { padding:6px 12px; font-size:13px; }
        .kq-roster-list, .kq-checkin-list { list-style:none; margin:0 0 16px; padding:0; display:flex; flex-direction:column; gap:6px; }
        .kq-roster-list li, .kq-checkin-list li { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border:1px solid #eee; border-radius:6px; }
        .kq-checkin-list .kq-btn { width:100%; text-align:left; }
        .kq-swap-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:6px 0; border-bottom:1px solid #f0f0f0; }
        .kq-swap-row:last-of-type { border-bottom:none; }
        .kq-swap-form { display:flex; align-items:center; gap:6px; margin-top:4px; flex-basis:100%; }
        .kq-swap-select { padding:5px 8px; border:1px solid #ddd; border-radius:4px; max-width:220px; }
        /* Responsive scoreboard card width -- min-width media queries only
           (never max-width), so nothing here can ever affect a viewport
           narrower than 600px: the existing, already-correct phone
           rendering (a bare .kq-wrap max-width:560px above, unconditional
           until now) is untouched below that point.
           ROOT CAUSE this fixes: the .kq-wrap max-width was a flat 560px
           with no responsive variance at all -- the ONLY thing bounding
           the kq-court-grid available width, on every viewport, phone
           through desktop alike. A wide desktop screen never gave the
           grid any more room than a phone did, so auto-fit column math
           (and therefore each card actual rendered width) was capped
           just as low on a 1920px screen as on a 400px one -- narrow
           enough that a line like "Red: (names) dash (score)" wrapped
           onto a second line. Cards were never actually a single column
           at desktop width in the auto-fit sense; the whole .kq-wrap
           widget itself just never grew past 560px, so however many
           columns the grid picked, each one stayed just as cramped as
           on a phone.
           FIX: let .kq-wrap grow at wider viewports, and raise the
           kq-court-grid own minmax() floor to match, so each column
           gets meaningfully wider -- not more numerous -- as more real
           screen width becomes available. 2 courts per round (the common
           case) still fits exactly 2 columns at every tier below
           (2 times new-minimum plus gap stays under the new .kq-wrap
           max-width at each step), so the existing 2-column-per-round
           grouping is unchanged; only the width available to each
           column grows. */
        @media (min-width:600px) {
            .kq-wrap { max-width:760px; }
            .kq-court-grid { grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); }
        }
        @media (min-width:900px) {
            .kq-wrap { max-width:900px; }
            .kq-court-grid { grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); }
        }
    </style>';
}

function spp_kq_status_label( ?string $phase, int $round ) : array {
    if ( ! $phase || $phase === 'not_started' ) {
        return array( 'label' => 'Not started', 'class' => 'not-started' );
    }
    $map = array(
        'organizing' => "Round {$round} \u{00b7} Organizing",
        'in_play'    => "Round {$round} \u{00b7} In Play",
        'complete'   => 'Complete',
        'cancelled'  => 'Cancelled',
    );
    return array( 'label' => $map[ $phase ] ?? $phase, 'class' => $phase );
}

// =============================================================
// Screen 1: Event picker
// =============================================================

/**
 * One picker row -- shared by both the upcoming list and the practice
 * sandbox list below, so the two can never drift apart in what they
 * show. $is_test adds the PRACTICE badge and a distinct row class;
 * everything else (title, date, registrant count, phase status) is
 * identical either way.
 */
function spp_kq_render_picker_row( array $r, bool $is_test = false ) : string {
    $status = spp_kq_status_label( $r['phase'] ?? null, (int) ( $r['current_round'] ?? 0 ) );
    $date_str = date_i18n( 'M j', strtotime( $r['event_date'] ) );
    $time_str = $r['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $r['eff_event_time'] ) ) : '';
    // Same GL_Registration-backed count the Start screen shows
    // ("N confirmed registrants") -- spp_kq_confirmed_count()
    // (inc/spp-kq-live.php), not a new query.
    $reg_count = spp_kq_confirmed_count( (int) $r['occurrence_id'] );

    ob_start();
    ?>
    <a class="kq-picker-row<?php echo $is_test ? ' kq-picker-row--test' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'occ', $r['occurrence_id'] ) ); ?>">
        <?php if ( $is_test ) : ?>
            <span class="kq-picker-badge kq-picker-badge--test">PRACTICE</span>
        <?php endif; ?>
        <span class="kq-picker-title"><?php echo esc_html( $r['eff_title'] ); ?></span>
        <span class="kq-picker-date"><?php echo esc_html( trim( $date_str . ' ' . $time_str ) ); ?></span>
        <span class="kq-picker-regcount"><?php echo esc_html( $reg_count ); ?> confirmed</span>
        <span class="kq-picker-status kq-status-<?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

function spp_kq_render_event_picker() : string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $events_table = spp_kq_events_table();

    // Next 8 upcoming occurrences only, combined across Ace and Queen
    // (eff_category_id IN (2,3) already pools both) -- same chronological
    // order as before, just truncated so this list doesn't grow unbounded
    // as far-future occurrences get scheduled.
    $rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date >= CURDATE()
         ORDER BY v.event_date ASC, v.eff_event_time ASC
         LIMIT 8",
        ARRAY_A
    );

    // Practice / Test Sandbox: the 4 most recent PAST occurrences,
    // auto-selected by date (never hand-picked), same category pooling
    // and cancelled=0 filter as the upcoming list above. event_date <
    // CURDATE() is a live, self-maintaining boundary -- not a frozen
    // literal date -- so this set naturally rolls forward on its own as
    // today's date advances, the same way the upcoming list already
    // does via event_date >= CURDATE(). Safe to offer to any logged-in
    // member with zero risk regardless of what happens to it: this
    // feature didn't exist before this week, so no past occurrence has
    // ever had any spp_kq_* rows of its own to begin with.
    $test_rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date < CURDATE()
         ORDER BY v.event_date DESC, v.eff_event_time DESC
         LIMIT 4",
        ARRAY_A
    );

    ob_start();
    echo spp_kq_styles();
    ?>
    <div class="kq-wrap">
        <h2 class="kq-heading">Ace / Queen of the Courts &mdash; Live</h2>
        <p class="kq-caveat">Actions here affect real, live event data &mdash; please be careful.</p>
        <?php if ( empty( $rows ) ) : ?>
            <p>No upcoming Ace or Queen occurrences found.</p>
        <?php else : ?>
            <div class="kq-picker-list">
                <?php foreach ( $rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $test_rows ) ) : ?>
            <h3 class="kq-picker-section-heading">Practice / Test Sandbox</h3>
            <p class="kq-hint">These are past, real events &mdash; safe to experiment on freely, since this tool didn't exist yet when they happened. Any logged-in member can use them. If a real score gets entered while testing, an administrator will need to clean it up (Reset only works before any score exists) before the next person tries.</p>
            <div class="kq-picker-list">
                <?php foreach ( $test_rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r, true ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// Screens 2-7: occurrence-specific, share one header
// =============================================================

function spp_kq_render_occurrence_header( array $occurrence, string $notice = '' ) : string {
    $date_str = date_i18n( 'l, F j', strtotime( $occurrence['event_date'] ) );
    $time_str = $occurrence['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $occurrence['eff_event_time'] ) ) : '';

    // Every other notice returned by spp_kq_handle_post_actions() today is
    // an error string (this dispatcher's longstanding convention: '' on
    // success, plain text on failure) -- rendered kq-notice-err
    // unconditionally, unchanged. SPP_KQ_NOTICE_OK_PREFIX is the one
    // opt-in exception: a case that wants to confirm a real success (so
    // far, only 'full_reset' -- see that case's own comment) prefixes its
    // returned string with this exact marker; stripped here before
    // display, rendered kq-notice-ok instead (already-defined CSS, same
    // class this file's own AJAX score-submission JS already uses for
    // success feedback -- reused for consistency, not invented fresh).
    // No other existing notice text starts with this marker, so every
    // other action's rendering is unchanged, byte for byte.
    $is_success = str_starts_with( $notice, SPP_KQ_NOTICE_OK_PREFIX );
    $display_notice = $is_success ? substr( $notice, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) : $notice;

    ob_start();
    ?>
    <p class="kq-back"><a href="<?php echo esc_url( remove_query_arg( 'occ' ) ); ?>">&larr; All events</a></p>
    <h2 class="kq-heading"><?php echo esc_html( $occurrence['eff_title'] ); ?></h2>
    <p class="kq-subheading"><?php echo esc_html( $date_str ) . ( $time_str ? ' &middot; ' . esc_html( $time_str ) : '' ); ?></p>
    <?php if ( $notice ) : ?>
        <div class="kq-notice <?php echo $is_success ? 'kq-notice-ok' : 'kq-notice-err'; ?>"><?php echo esc_html( $display_notice ); ?></div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Screen 2: Start screen (not_started)
 *
 * @param string $event_date The occurrence's real event date ('Y-m-d'),
 *   from spp_kq_get_occurrence_summary() -- passed in by the caller rather
 *   than re-queried here.
 */
/**
 * Screen 2: Start / Check-in. Every confirmed registrant (gl_registrations),
 * each toggleable checked-in/not -- see inc/spp-kq-checkin.php's own
 * header for the full flow. The "Cannot start" validation below is
 * evaluated against the CHECKED-IN count, not the raw confirmed count
 * (1.1.0) -- at the moment "Start Round 1 Draw" is pressed,
 * spp_kq_handle_post_actions()'s 'start_round1' case withdraws anyone
 * still unchecked (spp_kq_roster_remove(), same call Roster Adjust's own
 * Remove button uses) BEFORE calling spp_kq_transition_start_round1(),
 * so that function's own confirmed-count check (unchanged) already
 * evaluates the post-reconciliation reality.
 */
function spp_kq_render_start_screen( int $occurrence_id, string $event_date ) : string {
    $confirmed        = spp_kq_get_confirmed_registrants_named( $occurrence_id );
    $checked_in_ids    = spp_kq_get_checked_in_user_ids( $occurrence_id );
    $confirmed_count   = count( $confirmed );
    $checked_in_count  = count( $checked_in_ids );
    $valid             = ( $checked_in_count >= 4 && $checked_in_count <= 16 && $checked_in_count % 4 === 0 );

    // Day-of restriction: this feature has no access gate beyond
    // is_user_logged_in() (see spp_kq_can_facilitate()), so this is the one
    // guard against an event being started on the wrong day by mistake --
    // block rather than invent a workaround, same convention as the
    // non-multiple-of-4 headcount case above. Administrators are exempt,
    // for testing (spp_is_admin() -- same helper this codebase already uses
    // for administrator-only exceptions elsewhere). Ordinary members,
    // editors included, stay restricted to the actual event day.
    $is_event_day    = ( current_time( 'Y-m-d' ) === $event_date );
    $can_start_today = $is_event_day || spp_is_admin();

    ob_start();
    ?>
    <p class="kq-meta">
        <?php echo esc_html( $checked_in_count ); ?> of <?php echo esc_html( $confirmed_count ); ?> confirmed registrant<?php echo $confirmed_count === 1 ? '' : 's'; ?> checked in
    </p>
    <p class="kq-hint">Mark each player checked in as they arrive. Anyone still not checked in when you start Round 1 is removed from the registrant list.</p>
    <p class="kq-hint"><a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> &mdash; add a late arrival or remove someone, any time before starting.</p>

    <?php if ( empty( $confirmed ) ) : ?>
        <p class="kq-hint">No confirmed registrants yet.</p>
    <?php else : ?>
        <ul class="kq-checkin-list">
            <?php foreach ( $confirmed as $p ) :
                $is_in = in_array( $p['user_id'], $checked_in_ids, true );
            ?>
                <li>
                    <form method="post" class="kq-inline-form" style="width:100%;">
                        <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                        <input type="hidden" name="spp_kq_action" value="<?php echo $is_in ? 'checkin_unmark' : 'checkin_mark'; ?>">
                        <input type="hidden" name="spp_kq_checkin_user_id" value="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="submit" class="kq-btn <?php echo $is_in ? 'kq-btn-primary' : 'kq-btn-secondary'; ?>">
                            <?php echo esc_html( $p['name'] ); ?><?php echo $is_in ? ' -- Checked in ✓' : ' -- Check in'; ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! $valid ) : ?>
        <p class="kq-warn">
            Cannot start: <?php echo esc_html( $checked_in_count ); ?> checked in &mdash; need a multiple of 4,
            between 4 and 16. Adjust the roster via
            <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> first.
        </p>
    <?php elseif ( ! $can_start_today ) : ?>
        <p class="kq-warn">
            This event is scheduled for <?php echo esc_html( date_i18n( 'l, F j', strtotime( $event_date ) ) ); ?> &mdash; come back on the day to start it.
        </p>
    <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_round1">
            <button type="submit" class="kq-btn kq-btn-primary">Start Round 1 Draw</button>
        </form>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Screen 3: Draw screen (organizing, round 1, unclaimed slots remain) */
function spp_kq_render_draw_screen( int $occurrence_id ) : string {
    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $not_drawn    = spp_kq_get_not_yet_drawn( $occurrence_id );
    $reveal       = spp_kq_get_draw_reveal_state( $occurrence_id, $courts_order );
    $unclaimed    = spp_kq_count_unclaimed( $occurrence_id, 1 );
    $total_slots  = count( $courts_order ) * 4;
    $drawn_so_far = $total_slots - $unclaimed;

    ob_start();
    ?>
    <p class="kq-round-label-tight">Round 1 &mdash; Draw in progress</p>
    <p class="kq-hint">Tap a player's name, then tap any face-down card to reveal their court.</p>
    <p class="kq-draw-progress" id="kq-draw-progress">
        <span id="kq-draw-count"><?php echo esc_html( "{$drawn_so_far} of {$total_slots} drawn" ); ?></span>
    </p>
    <div class="kq-notice kq-notice-err" id="kq-draw-error" style="display:none;"></div>

    <div class="kq-draw-columns">
        <div class="kq-draw-col">
            <h3>Not yet drawn</h3>
            <ul id="kq-not-drawn-list">
                <?php foreach ( $not_drawn as $p ) : ?>
                    <li data-uid="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="button" class="kq-player-btn" data-uid="<?php echo esc_attr( $p['user_id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="kq-draw-col">
            <h3>Tap a card</h3>
            <div id="kq-card-grid">
                <?php for ( $i = 0; $i < $unclaimed; $i++ ) : ?>
                    <div class="kq-card">&#127165;</div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="kq-draw-revealed">
        <h3>Drawn so far</h3>
        <div class="kq-court-grid" id="kq-revealed-courts">
            <?php foreach ( $courts_order as $court ) : ?>
                <div class="kq-court-card">
                    <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                    <div class="kq-team kq-team-red">Red:
                        <?php foreach ( $reveal[ $court ]['red'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="red"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="kq-team kq-team-black">Black:
                        <?php foreach ( $reveal[ $court ]['black'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="black"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kq-action-row kq-action-row-right">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Clear this draw and start over? Any cards already drawn will be discarded -- players will need to draw again from scratch.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="1">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
    </div>

    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ     = <?php echo (int) $occurrence_id; ?>;
        var selectedUid = null;
        var errorBox = document.getElementById( 'kq-draw-error' );

        function showError( msg ) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
            setTimeout( function() { errorBox.style.display = 'none'; }, 4000 );
        }

        function selectPlayer( btn ) {
            document.querySelectorAll( '.kq-player-btn' ).forEach( function( b ) { b.classList.remove( 'kq-selected' ); } );
            btn.classList.add( 'kq-selected' );
            selectedUid = parseInt( btn.dataset.uid, 10 );
        }

        document.getElementById( 'kq-not-drawn-list' ).addEventListener( 'click', function( e ) {
            var btn = e.target.closest( '.kq-player-btn' );
            if ( btn ) selectPlayer( btn );
        } );

        document.getElementById( 'kq-card-grid' ).addEventListener( 'click', function( e ) {
            var card = e.target.closest( '.kq-card' );
            if ( ! card ) return;
            if ( ! selectedUid ) {
                showError( 'Select a player first, then tap a card.' );
                return;
            }

            var data = new FormData();
            data.append( 'action', 'spp_kq_draw_card' );
            data.append( 'nonce', nonce );
            data.append( 'occ', occ );
            data.append( 'user_id', selectedUid );

            fetch( ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    if ( ! res.success ) {
                        showError( res.data || 'Draw failed.' );
                        return;
                    }
                    var d = res.data;

                    // Remove the drawn player from the list.
                    var li = document.querySelector( '#kq-not-drawn-list li[data-uid="' + selectedUid + '"]' );
                    var playerName = li ? li.querySelector( '.kq-player-btn' ).textContent : '';
                    if ( li ) li.remove();
                    selectedUid = null;

                    // Remove one card from the grid.
                    card.remove();

                    // Fill the first still-empty slot for this court/color.
                    var slots = document.querySelectorAll( '.kq-slot[data-court="' + d.court_name + '"][data-color="' + d.team_color + '"]' );
                    for ( var i = 0; i < slots.length; i++ ) {
                        if ( ! slots[ i ].classList.contains( 'kq-slot-filled' ) ) {
                            slots[ i ].textContent = playerName;
                            slots[ i ].classList.add( 'kq-slot-filled' );
                            break;
                        }
                    }

                    // Update the progress counter.
                    var remaining = document.querySelectorAll( '#kq-card-grid .kq-card' ).length;
                    var total = remaining + document.querySelectorAll( '.kq-slot-filled' ).length;
                    document.getElementById( 'kq-draw-count' ).textContent = ( total - remaining ) + ' of ' + total + ' drawn';

                    if ( d.draw_complete ) {
                        window.location.reload();
                    }
                } )
                .catch( function() { showError( 'Network error -- try again.' ); } );
        } );
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Screen 4: Overview screen (organizing, draw complete or round > 1)
 *
 * End Event is only offered once at least one real score exists
 * somewhere for this occurrence -- "declare a winner" makes no sense
 * before a single game has been played (round 1's overview, right
 * after the draw). When zero scores exist yet, this screen offers only
 * Start Play -- deliberately NOT a Reset button here either: a
 * completed draw with Start Play not yet tapped has nothing to undo
 * (Reset Event only ever appears on the Draw screen, for a genuinely
 * incomplete draw, or the In-Play screen, to undo Start Play itself --
 * see spp_kq_transition_reset_event()'s own docblock).
 */
function spp_kq_render_overview_screen( int $occurrence_id, int $round ) : string {
    $courts_data   = spp_kq_get_round_court_view( $occurrence_id, $round );
    $scores_exist  = spp_kq_has_any_recorded_score( $occurrence_id );
    // 1.1.0: cancelled courts get a badge instead of team lists;
    // understaffed courts (a court re-created empty after a
    // cancellation, not yet re-staffed -- see
    // spp_kq_transition_advance_round()'s own docblock,
    // inc/spp-kq-live.php) block Start Play until fixed via Roster
    // Adjust's live swap screen.
    $cancelled     = spp_kq_get_cancelled_courts( $occurrence_id, $round );
    $understaffed  = spp_kq_get_understaffed_courts( $occurrence_id, $round );

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; Ready to play</p>
    <div class="kq-court-grid">
        <?php foreach ( $courts_data as $court => $teams ) : ?>
            <div class="kq-court-card">
                <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                <?php if ( in_array( $court, $cancelled, true ) ) : ?>
                    <p class="kq-hint">Cancelled for this round.</p>
                <?php elseif ( in_array( $court, $understaffed, true ) ) : ?>
                    <p class="kq-hint">Needs players &mdash; <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a>.</p>
                <?php else : ?>
                    <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                    <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="kq-hint">Swap a player, or cancel a court's game for this round, via <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a>.</p>
    <?php if ( ! empty( $understaffed ) ) : ?>
        <p class="kq-warn">Cannot start play: <?php echo esc_html( implode( ', ', $understaffed ) ); ?> still need players.</p>
    <?php elseif ( $scores_exist ) : ?>
        <p class="kq-hint">Start Play opens score entry for every court; End Event closes the day for good &mdash; no more rounds.</p>
    <?php else : ?>
        <p class="kq-hint">Start Play opens score entry for every court.</p>
    <?php endif; ?>
    <div class="kq-action-row">
        <?php if ( empty( $understaffed ) ) : ?>
        <form method="post" class="kq-inline-form">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_play">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-primary">Start Play</button>
        </form>
        <?php endif; ?>
        <?php if ( $scores_exist ) : ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('End the event now? This closes the day — no more rounds.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="end_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">End Event</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Screen 5: In-Play -- every court's score-entry view (Stage 3;
 * broadened 2026-09 -- see spp_kq_submit_court_score()'s own section
 * header in inc/spp-kq-live.php for the access-model change this is
 * part of). No per-user assignment check here anymore: every court in
 * the round is shown, to every logged-in facilitator, each with its
 * own score inputs. The AJAX submit handler below independently
 * validates the same way (real court for this occurrence/round) --
 * the two must never diverge, same discipline as everywhere else in
 * this codebase.
 */
function spp_kq_render_in_play_screen( int $occurrence_id, int $round ) : string {
    $progress     = spp_kq_get_round_progress( $occurrence_id, $round );
    // Reset is only offered once, before anything real has happened this
    // round (by the state machine, only possible in round 1) -- once any
    // court anywhere has reported, only Cancel Event remains available.
    $scores_exist = spp_kq_has_any_recorded_score( $occurrence_id );

    $court_view = spp_kq_get_round_court_view( $occurrence_id, $round );
    // 1.1.0: a cancelled court gets a badge and no score inputs at all --
    // see spp_kq_get_round_progress()'s own "not counted anywhere" note.
    $cancelled  = spp_kq_get_cancelled_courts( $occurrence_id, $round );

    $scores_by_court = array();
    foreach ( spp_kq_get_round_scores( $occurrence_id, $round ) as $s ) {
        $scores_by_court[ $s['court_name'] ] = $s;
    }

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; In Play</p>

    <div class="kq-status" id="kq-status">
        <span id="kq-status-progress"><?php echo esc_html( "{$progress['reported']} of {$progress['total']} courts reported" ); ?></span>
    </div>

    <p class="kq-hint">Enter both teams' real scores for any court &mdash; play continues until someone wins by a point, so an equal score is treated as a mistake to fix.</p>
    <p class="kq-hint">A player leaves mid-round or a court can't continue? <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> -- swap in a replacement or cancel that court's game.</p>

    <div class="kq-msg kq-notice" id="kq-score-msg" style="display:none;"></div>

    <?php foreach ( $court_view as $court => $teams ) :
        $current = $scores_by_court[ $court ] ?? array( 'red_score' => null, 'black_score' => null );
        $is_cancelled = in_array( $court, $cancelled, true );
    ?>
        <div class="kq-court-card" data-court="<?php echo esc_attr( $court ); ?>">
            <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
            <?php if ( $is_cancelled ) : ?>
                <p class="kq-hint">Cancelled for this round &mdash; no score to enter.</p>
            <?php else : ?>
                <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>

                <div class="kq-score-row">
                    <label>Red score<br>
                        <input type="number" class="kq-score-input kq-court-red-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                               value="<?php echo esc_attr( $current['red_score'] ?? '' ); ?>">
                    </label>
                    <label>Black score<br>
                        <input type="number" class="kq-score-input kq-court-black-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                               value="<?php echo esc_attr( $current['black_score'] ?? '' ); ?>">
                    </label>
                    <button type="button" class="kq-btn kq-btn-primary kq-save-score-btn">Save Score</button>
                    <span class="kq-saved" style="display:none;">Saved &#10003;</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <script>
    (function() {
        var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce        = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ          = <?php echo (int) $occurrence_id; ?>;
        var renderedRound = <?php echo (int) $round; ?>;

        var progressEl = document.getElementById('kq-status-progress');
        var msgEl      = document.getElementById('kq-score-msg');

        function showMsg(text, ok) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'kq-msg kq-notice ' + (ok ? 'kq-notice-ok' : 'kq-notice-err');
            msgEl.style.display = 'block';
        }

        // One wiring pass per court card -- each card owns its own pair
        // of inputs, save button, and saved-tag, so submitting one
        // court's score never touches another's inputs on the page.
        document.querySelectorAll('.kq-court-card').forEach(function(card) {
            var court      = card.dataset.court;
            var redInput   = card.querySelector('.kq-court-red-input');
            var blackInput = card.querySelector('.kq-court-black-input');
            var saveBtn    = card.querySelector('.kq-save-score-btn');
            var savedTag   = card.querySelector('.kq-saved');

            // 1.1.0: a cancelled court's card has no score inputs at all
            // (see spp_kq_render_in_play_screen()'s own PHP above) --
            // nothing to wire up for it.
            if (!redInput || !blackInput || !saveBtn) return;

            function updateSaveState() {
                if (!saveBtn) return;
                var r = redInput.value, b = blackInput.value;
                if (r === '' || b === '') { saveBtn.disabled = true; return; }
                var rn = parseInt(r, 10), bn = parseInt(b, 10);
                // Same rules, same order, as spp_kq_submit_court_score()'s
                // server-side checks -- this is immediate feedback only,
                // the server re-validates independently regardless.
                if (rn === 11 && bn === 11) {
                    saveBtn.disabled = true;
                    showMsg("11-11 isn't possible -- the game ends the instant either team reaches 11. Please double-check.", false);
                } else if (rn === bn) {
                    saveBtn.disabled = true;
                    showMsg("Scores can't be tied -- games are extended by a point specifically to avoid this.", false);
                } else {
                    saveBtn.disabled = false;
                    if (msgEl) msgEl.style.display = 'none';
                }
            }

            redInput.addEventListener('input', updateSaveState);
            blackInput.addEventListener('input', updateSaveState);
            updateSaveState();

            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                savedTag.style.display = 'none';

                var data = new FormData();
                data.append('action', 'spp_kq_submit_score');
                data.append('nonce', nonce);
                data.append('occ', occ);
                data.append('round', renderedRound);
                data.append('court_name', court);
                data.append('red_score', redInput.value);
                data.append('black_score', blackInput.value);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        updateSaveState();
                        if (!res.success) {
                            showMsg(res.data || 'Save failed.', false);
                            return;
                        }
                        savedTag.style.display = 'inline';
                        if (progressEl) progressEl.textContent = res.data.reported + ' of ' + res.data.total + ' courts reported';
                    })
                    .catch(function() {
                        saveBtn.disabled = false;
                        showMsg('Network error -- try again.', false);
                    });
            });
        });

        // Lightweight poll: update the live count, and reload only once
        // this round has actually moved on (no real-time push needed --
        // "people are standing together anyway").
        function poll() {
            var data = new FormData();
            data.append('action', 'spp_kq_poll_status');
            data.append('nonce', nonce);
            data.append('occ', occ);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) return;
                    var d = res.data;
                    if (d.phase !== 'in_play' || d.current_round !== renderedRound) {
                        window.location.reload();
                        return;
                    }
                    if (progressEl) progressEl.textContent = d.reported + ' of ' + d.total + ' courts reported';
                })
                .catch(function() {});
        }
        setInterval(poll, 4000);
    })();
    </script>

    <div class="kq-action-row kq-action-row-right">
        <?php if ( ! $scores_exist ) : ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Undo Start Play? This keeps the same drawn courts and returns everyone to the Ready to play screen -- nothing is lost, since no scores have been entered yet.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
        <?php endif; ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Cancel today\'s event? Any court that hasn\'t reported its score yet will lose this round\'s data entirely. Courts that already reported keep their result.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="cancel_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-danger">Cancel Event</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/** Screen 6: Complete screen */
function spp_kq_render_complete_screen( int $occurrence_id, int $round ) : string {
    $winner = spp_kq_get_final_winner_names( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event complete.</p>
    <?php if ( $winner ) : ?>
        <p class="kq-meta">Final round winners (Aces): <strong><?php echo esc_html( $winner ); ?></strong></p>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Screen 7: Cancelled screen -- kept vs. discarded courts */
function spp_kq_render_cancelled_screen( int $occurrence_id, int $round ) : string {
    $summary = spp_kq_get_cancellation_summary( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event cancelled &mdash; Round <?php echo esc_html( $round ); ?></p>
    <div class="kq-cancel-summary">
        <?php if ( ! empty( $summary['kept'] ) ) : ?>
            <div class="kq-kept">
                <h3>Kept &mdash; already reported, counts toward Club Ratings</h3>
                <ul>
                    <?php foreach ( $summary['kept'] as $court => $sc ) : ?>
                        <li><?php echo esc_html( $court ); ?>: Red <?php echo esc_html( $sc['red'] ); ?> &mdash; Black <?php echo esc_html( $sc['black'] ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $summary['discarded'] ) ) : ?>
            <div class="kq-discarded">
                <h3>Discarded &mdash; hadn't reported, not recorded</h3>
                <ul>
                    <?php foreach ( $summary['discarded'] as $court ) : ?>
                        <li><?php echo esc_html( $court ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( empty( $summary['kept'] ) && empty( $summary['discarded'] ) ) : ?>
            <p>No round data to report.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// POST action dispatch (Start Round 1 / Start Play / End Event /
// Cancel Event) -- no redirect, just returns a notice (or '') and lets
// the main shortcode re-render whatever screen the new state calls for.
// =============================================================

/**
 * @param string $event_date The occurrence's real event date ('Y-m-d'),
 *   passed by the caller -- same value spp_kq_render_start_screen() uses,
 *   so the button's own day-of gate and this server-side check can never
 *   diverge.
 */
function spp_kq_handle_post_actions( int $occurrence_id, string $event_date ) : string {
    if ( ! isset( $_POST['spp_kq_action'], $_POST['spp_kq_nonce'] ) ) {
        return '';
    }
    if ( ! wp_verify_nonce( $_POST['spp_kq_nonce'], 'spp_kq_live_action' ) ) {
        return 'Security check failed -- please try again.';
    }

    $action = sanitize_text_field( wp_unslash( $_POST['spp_kq_action'] ) );
    $round  = isset( $_POST['spp_kq_round'] ) ? intval( $_POST['spp_kq_round'] ) : 0;

    switch ( $action ) {
        case 'start_round1':
            // Same day-of gate the Start screen's button enforces (see that
            // function's docblock) -- re-checked here so a crafted or stale
            // POST can't bypass it. Must match what the UI offers, same
            // discipline as every other access check in this codebase.
            if ( current_time( 'Y-m-d' ) !== $event_date && ! spp_is_admin() ) {
                return 'This event can only be started on its actual event date.';
            }
            // 1.1.0: reconcile check-in reality BEFORE the transition's own
            // count check runs -- anyone confirmed but not checked in is
            // withdrawn now (spp_kq_roster_remove(), same call Roster
            // Adjust's own Remove button uses), so
            // spp_kq_transition_start_round1()'s unchanged
            // spp_kq_confirmed_count() check already evaluates the
            // post-reconciliation count. See inc/spp-kq-checkin.php's own
            // header for the full flow, including why this makes check-in
            // effectively mandatory.
            $checked_in_ids = spp_kq_get_checked_in_user_ids( $occurrence_id );
            foreach ( spp_kq_confirmed_user_ids( $occurrence_id ) as $uid ) {
                if ( ! in_array( $uid, $checked_in_ids, true ) ) {
                    spp_kq_roster_remove( $occurrence_id, $uid );
                }
            }
            $r = spp_kq_transition_start_round1( $occurrence_id );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'start_play':
            $r = spp_kq_transition_start_play( $occurrence_id, $round );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'end_event':
            // Same gate the Overview screen's own button visibility
            // enforces (see spp_kq_render_overview_screen()) -- must
            // match what the UI offers, re-checked here so a crafted or
            // stale POST can't declare a winner before anything's been
            // played.
            if ( ! spp_kq_has_any_recorded_score( $occurrence_id ) ) {
                return 'End Event isn\'t available yet -- no scores have been recorded for this event.';
            }
            $r = spp_kq_transition_end_event( $occurrence_id, $round );
            if ( ! $r['won'] ) {
                return '';
            }
            // Stage 4: automatic Club Rating publish on a successful
            // transition only -- spp_kq_maybe_publish_to_club_ratings()
            // (inc/spp-kq-club-rating.php) owns the pre-launch date guard
            // and the source ('ace'/'queen') resolution; this dispatcher
            // has no rating-engine knowledge of its own.
            $rating_notice = spp_kq_maybe_publish_to_club_ratings( $occurrence_id, $event_date );
            // Permanent history archive (spp_kq_history) + per-player
            // recap email -- same trigger point as the rating publish
            // above, same pre-launch guard (inc/spp-kq-history.php reuses
            // SPP_KQ_CLUB_RATING_LAUNCH_DATE, doesn't duplicate it).
            // 'cancel_event' below calls this exact same way -- any round
            // that had already reported before a cancellation is still
            // real data, worth archiving/recapping.
            $history_notice = spp_kq_finalize_event_history_and_recap( $occurrence_id, $event_date );
            return trim( $rating_notice . ( $history_notice !== '' ? ' ' . $history_notice : '' ) );

        case 'cancel_event':
            $r = spp_kq_transition_cancel_event( $occurrence_id, $round );
            if ( ! $r['won'] ) {
                return '';
            }
            // Same trigger point and same call pattern as 'end_event'
            // above -- any round that had already reported before
            // cancellation is still real data, worth archiving/recapping
            // exactly as if the event had ended normally. See
            // inc/spp-kq-history.php's own header: cancel_event and
            // end_event both trigger this, differing only in which CAS
            // transition got them here.
            $rating_notice  = spp_kq_maybe_publish_to_club_ratings( $occurrence_id, $event_date );
            $history_notice = spp_kq_finalize_event_history_and_recap( $occurrence_id, $event_date );
            return trim( $rating_notice . ( $history_notice !== '' ? ' ' . $history_notice : '' ) );

        case 'reset_event':
            // Available to any facilitator (spp_kq_can_facilitate(), the
            // feature-wide gate already checked at the shortcode
            // dispatcher) -- deliberately NOT admin-restricted, unlike
            // Full Reset below. Re-derives the real current phase itself
            // rather than trusting anything from the client; the actual
            // safety guarantee is the atomic CAS inside
            // spp_kq_transition_reset_event() itself, not this read.
            $state = spp_kq_get_event_state( $occurrence_id );
            if ( $state ) {
                spp_kq_transition_reset_event( $occurrence_id, $round, $state['phase'] );
            }
            return '';

        case 'full_reset':
            // Administrator-only -- checked here, server-side, as the
            // real enforcement; the button itself is also never printed
            // into the page for a non-admin (see spp_kq_live_shortcode()),
            // but that's belt-and-suspenders, not the actual gate. A
            // crafted POST from a non-admin session must fail here
            // regardless of what the UI would have shown them.
            if ( ! spp_is_admin() ) {
                return 'You do not have permission to do that.';
            }
            spp_kq_full_reset( $occurrence_id );
            // UX fix: a fully successful Full Reset on an occurrence with
            // nothing visible in assignments/scores to clear (e.g. still
            // 'not_started') previously rendered byte-identical to a
            // silent failure -- this dispatcher's own convention is '' on
            // success, plain text only on error, so nothing ever told the
            // facilitator it actually worked. SPP_KQ_NOTICE_OK_PREFIX
            // (see this file's own definition, and
            // spp_kq_render_occurrence_header()) opts this one message
            // into the success (kq-notice-ok) style instead of the
            // default error one -- confirmed via marker-value testing
            // that the deletes themselves were never the problem.
            return SPP_KQ_NOTICE_OK_PREFIX . 'Event fully reset.';

        case 'roster_add':
        case 'roster_remove':
            // KQ-specific replacement for gl-registration-admin (see
            // inc/spp-kq-roster.php's own header) -- gated only by this
            // shortcode's existing spp_kq_can_facilitate() check at the
            // top of spp_kq_live_shortcode(), same as every other action
            // here; no admin/editor/convenor role check. Re-verifies
            // phase itself (never trusts the client) -- the roster is
            // only ever adjustable before Round 1's draw locks it in.
            $roster_state = spp_kq_get_event_state( $occurrence_id );
            if ( ! $roster_state || $roster_state['phase'] !== 'not_started' ) {
                return 'The roster can only be adjusted before Round 1 starts.';
            }
            $roster_user_id = isset( $_POST['spp_kq_roster_user_id'] ) ? absint( $_POST['spp_kq_roster_user_id'] ) : 0;
            $roster_result  = ( $action === 'roster_add' )
                ? spp_kq_roster_add( $occurrence_id, $roster_user_id )
                : spp_kq_roster_remove( $occurrence_id, $roster_user_id );
            return $roster_result['success'] ? '' : ( $roster_result['error'] ?? '' );

        case 'checkin_mark':
        case 'checkin_unmark':
            // 1.1.0 -- see inc/spp-kq-checkin.php's own header. Pre-Round-1
            // only, same boundary roster_add/roster_remove already enforce
            // just above.
            $checkin_state = spp_kq_get_event_state( $occurrence_id );
            if ( ! $checkin_state || $checkin_state['phase'] !== 'not_started' ) {
                return 'Check-in is only available before Round 1 starts.';
            }
            $checkin_user_id = isset( $_POST['spp_kq_checkin_user_id'] ) ? absint( $_POST['spp_kq_checkin_user_id'] ) : 0;
            if ( ! $checkin_user_id || ! in_array( $checkin_user_id, spp_kq_confirmed_user_ids( $occurrence_id ), true ) ) {
                return 'Not a confirmed registrant for this event.';
            }
            spp_kq_set_checked_in( $occurrence_id, $checkin_user_id, $action === 'checkin_mark' );
            return '';

        case 'roster_swap':
            // 1.1.0 mid-event 1-for-1 substitute -- see
            // spp_kq_swap_player()'s own docblock (inc/spp-kq-live.php) for
            // the full validation it performs; this dispatcher just reads
            // the two user_ids off the POST and hands them over.
            $swap_old = isset( $_POST['spp_kq_swap_old_user_id'] ) ? absint( $_POST['spp_kq_swap_old_user_id'] ) : 0;
            $swap_new = isset( $_POST['spp_kq_swap_new_user_id'] ) ? absint( $_POST['spp_kq_swap_new_user_id'] ) : 0;
            $swap_result = spp_kq_swap_player( $occurrence_id, $swap_old, $swap_new );
            return $swap_result['success'] ? '' : ( $swap_result['error'] ?? '' );

        case 'roster_fill_slot':
            // 1.1.0 -- re-staffs one EMPTY slot on a court
            // spp_kq_transition_advance_round() re-created after a
            // cancellation. See spp_kq_fill_open_slot()'s own docblock.
            $fill_court = isset( $_POST['spp_kq_fill_court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_fill_court_name'] ) ) : '';
            $fill_color = isset( $_POST['spp_kq_fill_team_color'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_fill_team_color'] ) ) : '';
            $fill_user  = isset( $_POST['spp_kq_fill_new_user_id'] ) ? absint( $_POST['spp_kq_fill_new_user_id'] ) : 0;
            $fill_result = spp_kq_fill_open_slot( $occurrence_id, $fill_court, $fill_color, $fill_user );
            return $fill_result['success'] ? '' : ( $fill_result['error'] ?? '' );

        case 'cancel_court':
            // 1.1.0 -- see spp_kq_cancel_court()'s own docblock
            // (inc/spp-kq-live.php).
            $cancel_court_name = isset( $_POST['spp_kq_cancel_court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_cancel_court_name'] ) ) : '';
            $cancel_result = spp_kq_cancel_court( $occurrence_id, $cancel_court_name );
            return $cancel_result['success'] ? '' : ( $cancel_result['error'] ?? '' );
    }

    return '';
}

// =============================================================
// Main shortcode
// =============================================================

add_shortcode( 'spp_kq_live', 'spp_kq_live_shortcode' );

function spp_kq_live_shortcode() : string {
    if ( ! spp_kq_can_facilitate() ) {
        return '<p>Please log in to use this tool.</p>';
    }

    $occurrence_id = isset( $_GET['occ'] ) ? absint( $_GET['occ'] ) : 0;

    if ( ! $occurrence_id ) {
        return spp_kq_render_event_picker();
    }

    $occurrence = spp_kq_get_occurrence_summary( $occurrence_id );
    if ( ! $occurrence ) {
        return '<p>Occurrence not found.</p>';
    }

    // 30-minutes-before-start gate (1.2.0) -- see this file's own
    // "30-minutes-before-start access gate" section header, above
    // spp_kq_get_occurrence_summary(), for the full writeup. Checked
    // BEFORE spp_kq_handle_post_actions() so a crafted early POST
    // (check-in, roster-adjust, anything) can't bypass it either --
    // this blocks EVERYTHING for this occurrence, not just the phase
    // screens below. Administrators exempt, matching the existing
    // Start-Round-1 admin exemption (spp_is_admin()) exactly. Separate
    // from, and layered in front of, the existing Start-Round-1
    // date-only gate ($can_start_today in
    // spp_kq_render_start_screen()) -- that one is untouched and still
    // applies on its own once a facilitator is past this one.
    if ( ! spp_is_admin() && ! spp_kq_event_screens_open( $occurrence['event_date'], $occurrence['eff_event_time'] ) ) {
        return spp_kq_render_too_early_notice( $occurrence['event_date'], $occurrence['eff_event_time'] );
    }

    $notice = spp_kq_handle_post_actions( $occurrence_id, $occurrence['event_date'] );

    spp_kq_ensure_event_row( $occurrence_id );
    $state = spp_kq_get_event_state( $occurrence_id );
    $phase = $state['phase'];
    $round = (int) $state['current_round'];

    // Full Scoreboard (?kq_view=scoreboard) and Roster Adjust
    // (?kq_view=roster) are both flags layered on top of the phase-
    // driven switch below rather than phases of their own -- purely
    // additive, no interaction with spp_kq_events.phase/current_round
    // at all (Roster Adjust re-verifies phase==='not_started' itself,
    // inside spp_kq_render_roster_screen() -- see inc/spp-kq-roster.php).
    $kq_view = isset( $_GET['kq_view'] ) ? sanitize_key( wp_unslash( $_GET['kq_view'] ) ) : '';
    $viewing_scoreboard = ( $kq_view === 'scoreboard' );
    $viewing_roster      = ( $kq_view === 'roster' );

    ob_start();
    echo spp_kq_styles();
    echo '<div class="kq-wrap">';
    echo spp_kq_render_occurrence_header( $occurrence, $notice );
    echo spp_kq_render_scoreboard_link( $occurrence_id, $viewing_scoreboard );

    if ( $viewing_scoreboard ) {
        echo spp_kq_render_full_scoreboard_screen( $occurrence_id );
    } elseif ( $viewing_roster ) {
        echo spp_kq_render_roster_screen( $occurrence_id );
    } else {
        switch ( $phase ) {
            case 'not_started':
                echo spp_kq_render_start_screen( $occurrence_id, $occurrence['event_date'] );
                break;

            case 'organizing':
                $unclaimed = spp_kq_count_unclaimed( $occurrence_id, $round );
                if ( $round === 1 && $unclaimed > 0 ) {
                    echo spp_kq_render_draw_screen( $occurrence_id );
                } else {
                    echo spp_kq_render_overview_screen( $occurrence_id, $round );
                }
                break;

            case 'in_play':
                echo spp_kq_render_in_play_screen( $occurrence_id, $round );
                break;

            case 'complete':
                echo spp_kq_render_complete_screen( $occurrence_id, $round );
                break;

            case 'cancelled':
                echo spp_kq_render_cancelled_screen( $occurrence_id, $round );
                break;
        }
    }

    echo spp_kq_render_full_reset( $occurrence_id, $round );

    echo '</div>';
    return ob_get_clean();
}

/**
 * Full Scoreboard screen: every completed round, every court, every
 * player, that round's score -- not just the current round. Reachable
 * via ?kq_view=scoreboard from any phase (see the dispatcher above) --
 * pure read, changes nothing, no interaction with spp_kq_events.phase/
 * current_round. Uses spp_kq_get_full_scoreboard() (inc/spp-kq-
 * history.php), the exact same read this event's own eventual archival
 * write (spp_kq_archive_event_history(), same file) will use once it
 * ends -- so what a player sees live mid-event and what later gets
 * archived/emailed are guaranteed to agree.
 *
 * The actual round/court markup is spp_kq_render_scoreboard_markup()
 * (inc/spp-kq-history.php) -- factored out so the historical Event
 * Detail view ([spp_kq_event_detail], same file) can reuse the identical
 * layout against spp_kq_get_history_scoreboard() (the archive) instead
 * of duplicating this markup for a second data source. This function is
 * now just: fetch the live scoreboard, hand it to that shared renderer.
 */
function spp_kq_render_full_scoreboard_screen( int $occurrence_id ) : string {
    $scoreboard = spp_kq_get_full_scoreboard( $occurrence_id );
    ob_start();
    ?>
    <p class="kq-round-label">Full Scoreboard</p>
    <?php echo spp_kq_render_scoreboard_markup( $scoreboard, 'No completed rounds yet.' ); ?>
    <?php
    return ob_get_clean();
}

/**
 * "View Full Scoreboard" / "&laquo; Back" toggle link -- rendered once
 * in the main dispatcher, same placement pattern as
 * spp_kq_render_full_reset() (present regardless of which phase-driven
 * screen is showing). Only offered once at least one completed round
 * exists (spp_kq_has_any_recorded_score()) -- nothing to show before
 * that. Always shown once already viewing the scoreboard, so there's
 * always a way back.
 */
function spp_kq_render_scoreboard_link( int $occurrence_id, bool $viewing_scoreboard ) : string {
    if ( ! $viewing_scoreboard && ! spp_kq_has_any_recorded_score( $occurrence_id ) ) {
        return '';
    }
    $url = $viewing_scoreboard
        ? remove_query_arg( 'kq_view' )
        : add_query_arg( 'kq_view', 'scoreboard' );
    $label = $viewing_scoreboard ? '&laquo; Back' : 'View Full Scoreboard';
    return '<p class="kq-hint"><a href="' . esc_url( $url ) . '">' . $label . '</a></p>';
}

/**
 * Full Reset button -- administrator-only, deliberately available from
 * ANY phase (rendered once here, in the main dispatcher, not inside any
 * one screen's own render function, so it's present regardless of which
 * screen the switch above chose). Not printed into the page at all for
 * a non-administrator -- spp_is_admin() gates whether this function
 * emits anything, and the real enforcement is the matching check in
 * spp_kq_handle_post_actions()'s 'full_reset' case, not this visibility
 * check alone.
 */
function spp_kq_render_full_reset( int $occurrence_id, int $round ) : string {
    if ( ! spp_is_admin() ) {
        return '';
    }
    ob_start();
    ?>
    <div class="kq-full-reset-row">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('FULL RESET -- this permanently discards ALL recorded data for this event, including any real, already-saved scores. This cannot be undone. Only continue if you are certain. Proceed?');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="full_reset">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-danger">Full Reset (Admin Only)</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// AJAX: card draw
// =============================================================

add_action( 'wp_ajax_spp_kq_draw_card', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $user_id       = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

    if ( ! $occurrence_id || ! $user_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_draw_card( $occurrence_id, $user_id );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: score submit (Stage 3)
//
// The gate here is is_user_logged_in() -- the SAME feature-wide gate
// everything else in this feature uses, full stop. No second, narrower
// check: any logged-in user may submit any real court's score (2026-09
// access-model change, see spp_kq_submit_court_score()'s own section
// header in inc/spp-kq-live.php for why). court_name now comes from
// $_POST -- unavoidable now that it is no longer derived from the
// caller's own assignment -- but it is never trusted at face value:
// spp_kq_submit_court_score() validates it against a real
// spp_kq_scores row for this occurrence/round before writing anything.
// =============================================================

add_action( 'wp_ajax_spp_kq_submit_score', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $round         = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
    $court_name    = isset( $_POST['court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['court_name'] ) ) : '';
    $red_score     = isset( $_POST['red_score'] ) ? intval( $_POST['red_score'] ) : -1;
    $black_score   = isset( $_POST['black_score'] ) ? intval( $_POST['black_score'] ) : -1;

    if ( ! $occurrence_id || ! $round || $court_name === '' ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_submit_court_score( $occurrence_id, $round, $court_name, $red_score, $black_score, get_current_user_id() );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: lightweight status poll (Stage 3) -- lets the in-play screen
// update its "N of M reported" line live and detect a round advance
// without a full reload, while staying well short of real-time push.
// Read-only, no access restriction beyond being logged in: the count
// alone identifies no one's score.
// =============================================================

add_action( 'wp_ajax_spp_kq_poll_status', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state ) {
        wp_send_json_error( 'Occurrence not found.' );
    }

    $progress = spp_kq_get_round_progress( $occurrence_id, (int) $state['current_round'] );

    wp_send_json_success( array(
        'phase'         => $state['phase'],
        'current_round' => (int) $state['current_round'],
        'reported'      => $progress['reported'],
        'total'         => $progress['total'],
    ) );
} );
