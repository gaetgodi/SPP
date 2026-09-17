<?php
/* =========================================================
   Ace/Queen of the Courts — Permanent History, Live Scoreboard, Recap Email
   Version: 1.9.0
   Date: 2026-09-17

   Changes from 1.8.0 (fix court display order -- was alphabetical, now
   the fixed Aces/Kings/Queens/Jacks hierarchy everywhere courts are
   shown): spp_kq_get_full_scoreboard() and spp_kq_get_history_
   scoreboard() now run their per-round court-keyed array through the
   new spp_kq_order_courts_for_display() (inc/spp-kq-live.php 1.7.0,
   built from spp_kq_master_court_hierarchy() -- one shared definition,
   not duplicated) before returning -- both previously left their
   court order exactly as SQL's "ORDER BY court_name ASC" produced it
   (alphabetical). Fixes the live Full Scoreboard screen AND the
   historical Event Detail view in one place, since both share this
   same spp_kq_render_scoreboard_markup() renderer, which does no
   ordering of its own. spp_report_kq_history() (the flat kq_history
   report, below) fixed separately -- its row order IS its display
   order (no court-keyed grouping to re-key), so its own SQL ORDER BY
   now uses a FIELD(court_name, ...) clause built from that same
   spp_kq_master_court_hierarchy() instead of court_name ASC.
   DISPLAY ONLY: spp_kq_archive_event_history()'s own write path (what
   actually lands in spp_kq_history) and every recap-email/Club-Rating
   consumer are unchanged -- this only reorders what's already been
   read, never what's stored or how it's computed.

   Changes from 1.7.0 (Guest registrant -- see inc/spp-kq-roster.php's
   own changelog for the full feature): spp_kq_send_recap_emails() now
   filters out any user_id flagged spp_kq_guest=1 in usermeta before the
   membership lookup, so a guest is never sent a recap (no real email
   address exists for them). Permanent history archival (spp_kq_archive_
   event_history() and everything it calls) is untouched on purpose --
   a guest's games are real record-keeping and still archive normally;
   their name displays via spp_kq_player_name()'s own guest fallback
   (inc/spp-kq-screens.php 1.19.0), no membership-table row needed.

   Changes from 1.6.0 (decouple the Submit Photo prompt from the
   Club-Rating launch-date gate -- see the conversation this was built
   from for the full investigation/spec):
   - Removed spp_kq_history_exists_for_occurrence(): confirmed via a
     full-theme search that its only two callers (wp_ajax_spp_kq_poll_
     status's now-removed redirect_url, and spp_kq_render_photo_
     prompt()'s own gating check) are BOTH gone as of this change --
     the poll handler dropped it when the redirect became an embedded
     form (inc/spp-kq-screens.php 1.16.0), and the photo prompt now
     uses spp_kq_has_any_recorded_score() directly instead, with
     deliberately no launch-date check riding along (see that
     function's own updated docblock). Genuinely dead code, not
     speculative cleanup -- removed as a direct, natural consequence of
     this fix rather than left to confuse a future reader into reusing
     it for the same coupling this fix just undid.
   - spp_kq_archive_event_history() and spp_kq_maybe_publish_to_club_
     ratings() (inc/spp-kq-club-rating.php) are BYTE-FOR-BYTE unchanged
     -- confirmed by inspection, not just claimed: neither function was
     touched, both still check event_date < SPP_KQ_CLUB_RATING_LAUNCH_
     DATE first, exactly as before. The photo prompt and the archival/
     ratings pipeline are now two genuinely independent decisions that
     happen to often agree once the launch date passes, not one shared
     gate.

   Changes from 1.5.0 (post-completion Submit Photo redirect -- see the
   conversation this was built from for the full spec; the poll
   handler/redirect-URL building live in inc/spp-kq-screens.php, see
   that file's own changelog):
   - New spp_kq_history_exists_for_occurrence(): a ground-truth
     "did spp_kq_archive_event_history() actually archive this
     occurrence" check, used by the live screen's poll handler to
     decide whether to redirect a still-polling device to Submit Photo
     once phase becomes complete/cancelled. Deliberately NOT a second
     implementation of spp_kq_archive_event_history()'s own gating
     logic (pre-launch date / not-ace-or-queen / nothing-reported) --
     that logic already ran, synchronously, in the SAME request that
     flipped phase to complete/cancelled (spp_kq_handle_post_actions(),
     inc/spp-kq-screens.php, calls spp_kq_finalize_event_history_and_
     recap() immediately after the transition succeeds, before that
     request ever returns) -- by the time any OTHER device's poll next
     runs and observes the new phase, spp_kq_history either already has
     rows for this occurrence or it never will for this occurrence
     (archival is one-shot per occurrence, see that function's own
     docblock). Reading the actual result is simpler and can never drift
     from spp_kq_archive_event_history()'s real decision the way a
     parallel re-implementation of its three gating reasons could.

   Changes from 1.4.0:
   - spp_kq_get_full_scoreboard()'s WHERE now also checks
     s.cancelled = 0 explicitly (schema 1.4.0), alongside the existing
     "both scores NOT NULL" predicate. Belt-and-suspenders, not a
     behavior change: a cancelled court's scores are never written in
     the first place (inc/spp-kq-live.php's spp_kq_cancel_court()
     touches only spp_kq_scores.cancelled, never red_score/
     black_score), so the NULL-score predicate alone already excluded
     it -- this just makes the exclusion explicit rather than
     incidental, since this function is the single shared read every
     downstream consumer (history archival, recap email, Club Ratings
     via inc/spp-kq-club-rating.php) relies on to keep a cancelled
     court out of everything.

   Changes from 1.3.0:
   - [spp_kq_event_detail]'s Date <select> now auto-submits on change
     (onchange="this.form.submit()"), same as the Event select already
     did; the "View" submit button is gone entirely. Selecting a
     category alone still just reloads with kq_source set (refreshing
     the date list); selecting a date now immediately reloads with both
     set, rendering the scoreboard with no separate click. No server-
     side validation logic changed -- this only affects how the form
     gets submitted, not what happens once it is. The disabled
     placeholder options ("Choose an event first", "No events found")
     remain inert -- a disabled <option> cannot be selected, so neither
     can trigger the onchange submit on its own.

   Changes from 1.2.0:
   - [spp_kq_event_detail]'s free-text <input type="date"> replaced with
     a <select> populated only from dates that genuinely have
     spp_kq_history rows for the currently-selected category (new
     spp_kq_get_history_dates_for_source()) -- picking a category then a
     date from the list it produced can no longer land on "No event
     found" the way a free-text date could. Category select reloads the
     page on change (onchange="this.form.submit()", same plain-GET-
     resubmit convention as this theme's own report table "Rows per
     page" control, inc/spp-report-table.php) so the date list refreshes
     to match. Zero-dates-for-this-category renders a single disabled
     "No events found" option rather than an empty/broken select.
     Server-side re-validation is unchanged and still authoritative --
     spp_kq_get_history_scoreboard() is still the real source of truth,
     re-queried regardless of whether the submitted date ever appeared
     in the dropdown, so a crafted request bypassing it entirely still
     resolves correctly (falls through to the same "No event found"
     notice) rather than being trusted.

   Changes from 1.1.0:
   - Added the Event Detail view ([spp_kq_event_detail]): look up one
     past event by category (ace/queen) + date and see its full
     round-by-round scoreboard, sourced from spp_kq_history (permanent)
     rather than spp_kq_scores/spp_kq_assignments (live, in-progress
     only) -- for looking at exactly one archived event in the familiar
     round/court/pairing layout, distinct from the kq_history flat
     report (inc/spp-reports.php), which is a browsable log across every
     archived event, not meant for looking at one in detail.
   - New spp_kq_get_history_scoreboard( $source, $event_date ): same
     grouped shape as spp_kq_get_full_scoreboard() above, built from
     spp_kq_history instead of the live tables.
   - Extracted spp_kq_render_scoreboard_markup() out of
     spp_kq_render_full_scoreboard_screen() (inc/spp-kq-screens.php) --
     same markup, unchanged, now a standalone function taking a
     scoreboard array in and an $empty_message, so both the live Full
     Scoreboard screen and this new Event Detail view render identical
     layout from two different data sources without duplicating markup.
     spp_kq_render_full_scoreboard_screen() itself is now just fetch +
     hand off to this shared renderer -- behavior confirmed unchanged via
     direct before/after byte comparison (see this version's own testing).
   - Access: is_user_logged_in() only, matching kq_history's own open
     visibility -- no admin/editor gate, deliberately not
     spp_kq_can_facilitate() (that gate's own docblock, inc/spp-kq-
     live.php, scopes it to the live-event-facilitation entry points;
     this is a separate, historical/informational concern, same
     sensitivity as the flat report it complements).

   Changes from 1.0.0:
   - BUG FIX: spp_kq_finalize_event_history_and_recap() is now called
     from BOTH the 'end_event' AND 'cancel_event' cases in
     spp_kq_handle_post_actions() (inc/spp-kq-screens.php), same call
     pattern, same trigger point as spp_kq_maybe_publish_to_club_ratings()
     (which already fired on both). 1.0.0 deliberately left this out of
     'cancel_event', on the assumption a cancelled event had nothing
     worth archiving -- wrong: spp_kq_transition_cancel_event() only ever
     discards the CURRENT round's unreported courts; every earlier round
     is untouched, real data. spp_kq_get_full_scoreboard()/
     spp_kq_archive_event_history() needed no fix for this -- neither
     ever assumed a "complete" event shape (both just filter on "both
     scores NOT NULL" per round/court, whatever subset of rounds/courts
     that turns out to be), confirmed by test case (c) in this version's
     own testing. cancel_event and end_event now trigger archiving/recap
     identically, differing only in which CAS transition
     (spp_kq_transition_end_event() vs. spp_kq_transition_cancel_event())
     got the caller here.

   PURPOSE:
   Three related features, all built on one shared read
   (spp_kq_get_full_scoreboard()) so "what a player sees live mid-event"
   and "what gets archived/emailed once the event ends (cleanly or by
   cancellation)" can never disagree:
     1. spp_kq_get_full_scoreboard() -- every completed round/court for
        an occurrence, named. Used live (the Full Scoreboard screen,
        inc/spp-kq-screens.php) AND at archival time (below), for both a
        normal end and a cancellation. Read-only, no schema change, no
        interaction with spp_kq_events.phase/current_round at all, and
        no assumption that every round/court is present -- a partial
        event (e.g. only round 1 ever completed) simply produces fewer
        rows, nothing more.
     2. spp_kq_archive_event_history() -- writes spp_kq_history
        (inc/spp-kq-schema.php 1.3.0), called ONCE from
        spp_kq_handle_post_actions()'s 'end_event' AND 'cancel_event'
        cases (inc/spp-kq-screens.php), the exact same trigger point
        spp_kq_maybe_publish_to_club_ratings() already uses for both,
        guarded by the same SPP_KQ_CLUB_RATING_LAUNCH_DATE pre-launch
        cutoff (inc/spp-kq-club-rating.php) -- reused verbatim, not
        duplicated, so the 4 rolling Practice/Test Sandbox occurrences
        can never write a permanent history row, same guarantee Club
        Ratings already has, regardless of which transition ends them.
     3. spp_kq_send_recap_emails() -- one email per player who appears
        in the finished (or cancelled-with-real-rounds) occurrence's
        scoreboard, round-by-round, no ranking content at all (KQ has no
        ranking; Glicko Club Ratings are computed and published
        separately, by inc/spp-kq-club-rating.php, and never mentioned
        here).

   spp_kq_finalize_event_history_and_recap() is the single entry point
   the dispatcher calls from both cases, mirroring
   spp_kq_maybe_publish_to_club_ratings()'s own call convention (called
   once per transition, after that transition's own CAS confirms it
   actually happened, returns a short plain-text status or '').

   The kq_history report entry in inc/spp-reports.php reads
   spp_kq_history directly (never the live spp_kq_scores/spp_kq_assignments
   tables) -- see that entry's own docblock.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

// =============================================================
// Shared read: full scoreboard (live mid-event, or a finished event)
// =============================================================

/**
 * Full scoreboard for an occurrence: every round/court that has a
 * COMPLETE score (both red_score/black_score non-NULL), joined with
 * player names. A round still in progress simply contributes no rows
 * yet for its own not-yet-reported courts -- same "both scores NOT
 * NULL" predicate spp_kq_has_any_recorded_score() and friends already
 * use elsewhere in this feature (inc/spp-kq-live.php), not a new
 * concept.
 *
 * Shared by two callers: the live Full Scoreboard screen (rendered
 * mid-event, inc/spp-kq-screens.php) and spp_kq_archive_event_history()
 * below (called once, after the event is fully over) -- one query
 * shape, not duplicated, so what a player sees live and what later gets
 * archived/emailed are guaranteed to agree.
 *
 * @return array [ round_number => [ court_name => [
 *     'red'         => [ ['user_id'=>int, 'name'=>string], ... ] (2 players),
 *     'black'       => [ ... ] (2 players),
 *     'red_score'   => int,
 *     'black_score' => int,
 * ] ] ], in round_number then court_name order (array insertion order,
 * since rows arrive pre-ordered from SQL and PHP preserves that).
 */
function spp_kq_get_full_scoreboard( int $occurrence_id ) : array {
    global $wpdb;
    $scores_table      = spp_kq_scores_table();
    $assignments_table = spp_kq_assignments_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.round_number, s.court_name, s.red_score, s.black_score,
                a.user_id, a.team_color, m.first_name, m.last_name
         FROM {$scores_table} s
         JOIN {$assignments_table} a
           ON a.occurrence_id = s.occurrence_id
          AND a.round_number  = s.round_number
          AND a.court_name    = s.court_name
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE s.occurrence_id = %d
           AND s.red_score IS NOT NULL AND s.black_score IS NOT NULL
           AND s.cancelled = 0
           AND a.user_id IS NOT NULL
         ORDER BY s.round_number ASC, s.court_name ASC, a.team_color ASC",
        $occurrence_id
    ), ARRAY_A );

    $out = array();
    foreach ( $rows as $r ) {
        $round = (int) $r['round_number'];
        $court = $r['court_name'];
        if ( ! isset( $out[ $round ][ $court ] ) ) {
            $out[ $round ][ $court ] = array(
                'red'         => array(),
                'black'       => array(),
                'red_score'   => (int) $r['red_score'],
                'black_score' => (int) $r['black_score'],
            );
        }
        $out[ $round ][ $court ][ $r['team_color'] ][] = array(
            'user_id' => (int) $r['user_id'],
            'name'    => spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ),
        );
    }
    foreach ( $out as $round => $courts ) {
        $out[ $round ] = spp_kq_order_courts_for_display( $courts );
    }
    return $out;
}

/**
 * Same grouped shape as spp_kq_get_full_scoreboard() above, sourced from
 * the PERMANENT spp_kq_history archive instead of the live spp_kq_scores/
 * spp_kq_assignments tables -- for looking at exactly one past,
 * already-finished event by category+date (the Event Detail view,
 * [spp_kq_event_detail] below), not an in-progress one. Names are looked
 * up fresh via membership (never frozen into the archived rows), same
 * convention as spp_report_kq_history() and spp_kq_get_full_scoreboard()
 * itself.
 *
 * @param string $source     'ace' or 'queen' (spp_kq_history.source).
 * @param string $event_date 'Y-m-d'.
 * @return array Empty array if no matching event exists for this
 *                source+date -- callers treat that as "not found" the
 *                same way spp_kq_get_full_scoreboard() treats "no rounds
 *                recorded yet" (both are simply an empty array, no
 *                distinct error signal needed) -- see
 *                spp_kq_render_scoreboard_markup()'s own $empty_message
 *                parameter for how each caller words that case.
 */
function spp_kq_get_history_scoreboard( string $source, string $event_date ) : array {
    global $wpdb;
    $table = spp_kq_history_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT h.round_number, h.court_name, h.team_color, h.user_id, h.red_score, h.black_score,
                m.first_name, m.last_name
         FROM {$table} h
         LEFT JOIN membership m ON m.user_id = h.user_id
         WHERE h.source = %s AND h.event_date = %s
         ORDER BY h.round_number ASC, h.court_name ASC, h.team_color ASC",
        $source, $event_date
    ), ARRAY_A );

    $out = array();
    foreach ( $rows as $r ) {
        $round = (int) $r['round_number'];
        $court = $r['court_name'];
        if ( ! isset( $out[ $round ][ $court ] ) ) {
            $out[ $round ][ $court ] = array(
                'red'         => array(),
                'black'       => array(),
                'red_score'   => (int) $r['red_score'],
                'black_score' => (int) $r['black_score'],
            );
        }
        $out[ $round ][ $court ][ $r['team_color'] ][] = array(
            'user_id' => (int) $r['user_id'],
            'name'    => spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ),
        );
    }
    foreach ( $out as $round => $courts ) {
        $out[ $round ] = spp_kq_order_courts_for_display( $courts );
    }
    return $out;
}

/**
 * Pure scoreboard markup -- round-by-round court cards, no data-fetching
 * of its own. Shared by the live Full Scoreboard screen
 * (spp_kq_render_full_scoreboard_screen(), inc/spp-kq-screens.php, fed by
 * spp_kq_get_full_scoreboard() above) and the historical Event Detail
 * view (spp_kq_event_detail_shortcode() below, fed by
 * spp_kq_get_history_scoreboard() above) -- same input shape, same
 * markup out, regardless of which table the caller actually read from.
 * Extracted from spp_kq_render_full_scoreboard_screen()'s own original
 * body (see that function's docblock) -- markup itself is unchanged from
 * before this existed.
 *
 * @param array  $scoreboard    Grouped [round => [court => [...]]] shape,
 *                               same as both functions above return.
 * @param string $empty_message Shown instead of any round/court markup
 *                               when $scoreboard is empty -- callers word
 *                               this to fit their own context (a live
 *                               event with no rounds yet vs. a historical
 *                               lookup that found no matching event).
 */
function spp_kq_render_scoreboard_markup( array $scoreboard, string $empty_message = 'No completed rounds yet.' ) : string {
    ob_start();
    ?>
    <?php if ( empty( $scoreboard ) ) : ?>
        <p class="kq-hint"><?php echo esc_html( $empty_message ); ?></p>
    <?php else : ?>
        <?php foreach ( $scoreboard as $round_number => $courts ) : ?>
            <h3 class="kq-picker-section-heading">Round <?php echo esc_html( $round_number ); ?></h3>
            <div class="kq-court-grid">
                <?php foreach ( $courts as $court_name => $court ) : ?>
                    <div class="kq-court-card">
                        <div class="kq-court-name"><?php echo esc_html( $court_name ); ?></div>
                        <div class="kq-team kq-team-red">
                            Red: <?php echo esc_html( implode( ', ', array_column( $court['red'], 'name' ) ) ); ?>
                            &mdash; <?php echo esc_html( $court['red_score'] ); ?>
                        </div>
                        <div class="kq-team kq-team-black">
                            Black: <?php echo esc_html( implode( ', ', array_column( $court['black'], 'name' ) ) ); ?>
                            &mdash; <?php echo esc_html( $court['black_score'] ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// =============================================================
// [spp_kq_event_detail] -- historical Event Detail view (category + date
// lookup against the permanent spp_kq_history archive)
// =============================================================

/**
 * Distinct spp_kq_history event_date values for one category, within one
 * year -- powers the Event Detail view's date dropdown
 * (spp_kq_event_detail_shortcode() below), so it only ever lists dates
 * that genuinely have data for the selected category, most recent first.
 *
 * @param string $source 'ace' or 'queen'.
 * @param string $year   'YYYY'.
 * @return array List of 'Y-m-d' strings, most recent first.
 */
function spp_kq_get_history_dates_for_source( string $source, string $year ) : array {
    global $wpdb;
    $table = spp_kq_history_table();
    return $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT event_date FROM {$table}
         WHERE source = %s AND YEAR( event_date ) = %d
         ORDER BY event_date DESC",
        $source, $year
    ) );
}

/**
 * Event Detail: look up one past Ace/Queen of the Courts event by
 * category + date and show its full round-by-round scoreboard, same
 * layout as the live Full Scoreboard screen (spp_kq_render_
 * scoreboard_markup(), shared with it) -- but reading spp_kq_history
 * (permanent) rather than spp_kq_scores/spp_kq_assignments (live,
 * in-progress-only). Distinct from the kq_history flat report
 * (inc/spp-reports.php): that one is a browsable, ever-growing log
 * across every archived event; this is for looking at exactly one event
 * at a time, in the familiar round/court/pairing shape.
 *
 * ACCESS: is_user_logged_in() only -- no admin/editor gate, matching
 * kq_history's own open visibility (this is historical/informational,
 * the same sensitivity level as that flat report; nothing here exposes
 * anything kq_history doesn't already show any logged-in member).
 *
 * INPUT: a plain GET form (kq_source, kq_event_date) -- a pure read/
 * lookup with no mutation, so GET (not a nonce-gated POST) is the right
 * tool here, same convention this theme already uses for read-only
 * navigation (e.g. spp-report-table.php's own sort/pagination links).
 * Both fields are <select> dropdowns, not free-text/date-picker inputs,
 * and BOTH auto-submit on change (onchange="this.form.submit()", same
 * plain-GET-resubmit convention this theme's own report table controls
 * already use for their "Rows per page" selector, inc/spp-report-
 * table.php) -- no separate submit button anywhere on this form.
 * Selecting a category reloads with just kq_source set, refreshing the
 * date list to match; selecting a date immediately reloads with both
 * set, rendering the scoreboard. The date list itself
 * (spp_kq_get_history_dates_for_source() above) only ever contains
 * dates that genuinely have spp_kq_history rows for the selected
 * category, so a "not found" result is unreachable through normal
 * dropdown use -- only a crafted request can still reach it (see the
 * server-side re-validation below, which never trusts the dropdown's
 * own list). The disabled placeholder options ("Choose an event first",
 * "No events found") are inert by construction -- a disabled <option>
 * cannot be selected, so it can never itself trigger the onchange
 * submit.
 *
 * DATE RANGE: constrained to the current year only (current_time('Y'),
 * WP's own timezone-aware "today" -- same helper
 * spp_kq_transition_start_round1()'s day-of check already uses,
 * inc/spp-kq-live.php) -- both the date dropdown's own contents AND a
 * server-side re-check (never trust the client) reject anything
 * outside it, with a plain notice rather than silently misbehaving. No
 * support for browsing prior years yet -- not needed until this archive
 * actually spans more than one.
 */
function spp_kq_event_detail_shortcode() : string {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to view this.</p>';
    }

    $current_year = current_time( 'Y' );
    $source       = isset( $_GET['kq_source'] ) ? sanitize_key( wp_unslash( $_GET['kq_source'] ) ) : '';
    $event_date   = isset( $_GET['kq_event_date'] ) ? sanitize_text_field( wp_unslash( $_GET['kq_event_date'] ) ) : '';

    $source_valid = in_array( $source, array( 'ace', 'queen' ), true );

    // Date dropdown's own contents -- only ever dates that genuinely
    // have spp_kq_history rows for the selected category, so a "not
    // found" result is unreachable via normal dropdown use (picking a
    // category, then a date from the list it produced -- both selects
    // auto-submit on change, no separate button). Category-select-
    // triggers-reload is a plain GET resubmit
    // (onchange="this.form.submit()" below) -- same convention this
    // theme's own report table controls already use for their "Rows per
    // page" selector (inc/spp-report-table.php) -- not new JS/AJAX
    // machinery invented for this.
    $available_dates = $source_valid ? spp_kq_get_history_dates_for_source( $source, $current_year ) : array();

    $submitted  = ( $source !== '' && $event_date !== '' );
    $notice     = '';
    $scoreboard = array();

    if ( $submitted ) {
        if ( ! $source_valid ) {
            $notice = 'Please choose Ace or Queen.';
        } elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event_date ) ) {
            $notice = 'Please choose a valid date.';
        } elseif ( substr( $event_date, 0, 4 ) !== $current_year ) {
            $notice = "Please choose a date in {$current_year} -- earlier years aren't supported yet.";
        } else {
            // Server-side truth, not the dropdown's own option list --
            // re-queried here regardless of whether $event_date actually
            // appeared in $available_dates, so a crafted/tampered
            // kq_event_date (one the dropdown never offered) still
            // resolves through the real query and correctly falls
            // through to the "No event found" notice below rather than
            // being trusted just because it looked like a plausible date.
            $scoreboard = spp_kq_get_history_scoreboard( $source, $event_date );
        }
    }

    ob_start();
    echo spp_kq_styles();
    ?>
    <div class="kq-wrap">
        <h2 class="kq-heading">Ace / Queen of the Courts &mdash; Event Detail</h2>
        <p class="kq-hint">Look up a past Ace or Queen of the Courts event's full scoreboard.</p>

        <form method="get" class="kq-inline-form">
            <label>
                Event
                <select name="kq_source" onchange="this.form.submit()">
                    <option value="">&mdash; Select &mdash;</option>
                    <option value="ace" <?php selected( $source, 'ace' ); ?>>Ace of the Courts</option>
                    <option value="queen" <?php selected( $source, 'queen' ); ?>>Queen of the Courts</option>
                </select>
            </label>
            <label>
                Date
                <select name="kq_event_date" onchange="this.form.submit()" <?php disabled( ! $source_valid ); ?>>
                    <?php if ( ! $source_valid ) : ?>
                        <option value="">&mdash; Choose an event first &mdash;</option>
                    <?php elseif ( empty( $available_dates ) ) : ?>
                        <option value="" disabled selected>No events found</option>
                    <?php else : ?>
                        <option value="">&mdash; Select &mdash;</option>
                        <?php foreach ( $available_dates as $d ) : ?>
                            <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $event_date, $d ); ?>>
                                <?php echo esc_html( date_i18n( 'F j, Y', strtotime( $d ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
        </form>

        <?php if ( $notice !== '' ) : ?>
            <div class="kq-notice kq-notice-err"><?php echo esc_html( $notice ); ?></div>
        <?php elseif ( $submitted ) : ?>
            <?php if ( empty( $scoreboard ) ) : ?>
                <p class="kq-hint">No event found for that date/category.</p>
            <?php else : ?>
                <p class="kq-round-label">
                    <?php echo esc_html( $source === 'ace' ? 'Ace of the Courts' : 'Queen of the Courts' ); ?>
                    &mdash; <?php echo esc_html( date_i18n( 'F j, Y', strtotime( $event_date ) ) ); ?>
                </p>
                <?php echo spp_kq_render_scoreboard_markup( $scoreboard ); ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'spp_kq_event_detail', 'spp_kq_event_detail_shortcode' );

/**
 * Per-player round-by-round detail extracted from
 * spp_kq_get_full_scoreboard()'s grouped shape, for one $user_id --
 * shared by the recap email. Ordered by round_number ascending (the
 * scoreboard's own array key order already guarantees this).
 *
 * @return array List of ['round_number'=>int, 'court_name'=>string,
 *   'partner_name'=>string, 'opponent_names'=>string (joined with ' & '),
 *   'my_score'=>int, 'opp_score'=>int, 'won'=>bool].
 */
function spp_kq_extract_player_rounds( array $scoreboard, int $user_id ) : array {
    $out = array();
    foreach ( $scoreboard as $round_number => $courts ) {
        foreach ( $courts as $court_name => $court ) {
            foreach ( array( 'red', 'black' ) as $color ) {
                $on_this_team = array_values( array_filter( $court[ $color ], fn( $p ) => $p['user_id'] === $user_id ) );
                if ( empty( $on_this_team ) ) {
                    continue;
                }

                $other_color = ( $color === 'red' ) ? 'black' : 'red';
                $partner     = array_values( array_filter( $court[ $color ], fn( $p ) => $p['user_id'] !== $user_id ) );
                $opponents   = $court[ $other_color ];

                $out[] = array(
                    'round_number'   => $round_number,
                    'court_name'     => $court_name,
                    'partner_name'   => $partner[0]['name'] ?? '',
                    'opponent_names' => implode( ' & ', array_column( $opponents, 'name' ) ),
                    'my_score'       => $court[ $color . '_score' ],
                    'opp_score'      => $court[ $other_color . '_score' ],
                    'won'            => $court[ $color . '_score' ] > $court[ $other_color . '_score' ],
                );
                continue 3; // a player appears at most once per round -- found their court, move to the next round.
            }
        }
    }
    return $out;
}

// =============================================================
// Permanent archival (spp_kq_history)
// =============================================================

/**
 * Flatten spp_kq_get_full_scoreboard()'s grouped shape into the exact
 * rows spp_kq_history needs: one per player per round per court.
 * user_id-keyed, no names -- names are looked up again at read time by
 * whoever consumes spp_kq_history (the report, the recap email), same
 * as every other report in this registry re-joins membership rather
 * than freezing a name into archived data.
 */
function spp_kq_flatten_scoreboard_for_history( array $scoreboard ) : array {
    $rows = array();
    foreach ( $scoreboard as $round_number => $courts ) {
        foreach ( $courts as $court_name => $court ) {
            foreach ( array( 'red', 'black' ) as $color ) {
                foreach ( $court[ $color ] as $player ) {
                    $rows[] = array(
                        'round_number' => $round_number,
                        'court_name'   => $court_name,
                        'team_color'   => $color,
                        'user_id'      => $player['user_id'],
                        'red_score'    => $court['red_score'],
                        'black_score'  => $court['black_score'],
                    );
                }
            }
        }
    }
    return $rows;
}

/**
 * Archive one finished-or-cancelled occurrence's full round-by-round
 * scoreboard into spp_kq_history -- called ONCE, from
 * spp_kq_handle_post_actions()'s 'end_event' AND 'cancel_event' cases
 * (inc/spp-kq-screens.php), immediately after either transition
 * succeeds (the caller only invokes this once that transition's own
 * $r['won'] is true) -- the exact same trigger point
 * spp_kq_maybe_publish_to_club_ratings() already uses for both.
 *
 * No assumption anywhere here that the event ran to some expected
 * length: spp_kq_get_full_scoreboard() (below) has no round-count
 * concept at all, it simply returns whatever rounds/courts actually
 * have a complete score -- a cancelled event with only round 1 ever
 * completed archives exactly those rows, nothing more, nothing forced.
 *
 * PRE-LAUNCH GUARD: reuses SPP_KQ_CLUB_RATING_LAUNCH_DATE
 * (inc/spp-kq-club-rating.php) verbatim, not a second constant -- an
 * occurrence dated before it is skipped entirely, same as Club Ratings,
 * so the 4 rolling Practice/Test Sandbox occurrences (spp-kq-screens.php's
 * event picker) can never write a permanent history row, whether they
 * end cleanly or get cancelled. See that constant's own docblock for
 * why this must stay a literal date, never date()/current_time() logic.
 *
 * Idempotent by construction, not by an explicit re-check here: this is
 * only ever called once per real end_event/cancel_event transition,
 * gated by that transition's own CAS ($r['won']) at the call site -- the
 * same guarantee spp_kq_maybe_publish_to_club_ratings() already relies
 * on for its own single-fire behavior. A given occurrence can only ever
 * reach ONE of 'complete'/'cancelled' (phase is a one-way transition
 * from 'in_play'), so this can never double-fire for the same
 * occurrence via both paths.
 *
 * @return array ['archived'=>bool, 'row_count'=>int, 'reason'=>?string,
 *   'scoreboard'=>array] -- 'scoreboard' is spp_kq_get_full_scoreboard()'s
 *   own return value, handed straight to the recap email step so it
 *   reuses the exact data just archived rather than re-querying.
 */
function spp_kq_archive_event_history( int $occurrence_id, string $event_date ) : array {
    if ( $event_date < SPP_KQ_CLUB_RATING_LAUNCH_DATE ) {
        return array( 'archived' => false, 'row_count' => 0, 'reason' => 'pre-launch', 'scoreboard' => array() );
    }

    $source = spp_kq_category_source( $occurrence_id );
    if ( ! $source ) {
        return array( 'archived' => false, 'row_count' => 0, 'reason' => 'not-ace-or-queen', 'scoreboard' => array() );
    }

    $scoreboard = spp_kq_get_full_scoreboard( $occurrence_id );
    $flat_rows  = spp_kq_flatten_scoreboard_for_history( $scoreboard );

    if ( empty( $flat_rows ) ) {
        return array( 'archived' => false, 'row_count' => 0, 'reason' => 'nothing-reported', 'scoreboard' => $scoreboard );
    }

    global $wpdb;
    $table = spp_kq_history_table();

    $placeholders = array();
    $values       = array();
    foreach ( $flat_rows as $r ) {
        $placeholders[] = '(%d, %d, %s, %s, %d, %d, %d, %s, %s)';
        array_push( $values,
            $occurrence_id, $r['round_number'], $r['court_name'], $r['team_color'], $r['user_id'],
            $r['red_score'], $r['black_score'], $event_date, $source
        );
    }
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table}
            (occurrence_id, round_number, court_name, team_color, user_id, red_score, black_score, event_date, source)
         VALUES " . implode( ', ', $placeholders ),
        $values
    ) );

    return array( 'archived' => true, 'row_count' => count( $flat_rows ), 'reason' => null, 'scoreboard' => $scoreboard );
}

// =============================================================
// Recap email
// =============================================================

/**
 * Build one player's recap email body -- plain, simple HTML table, one
 * row per round played. Deliberately NO ranking/rating content of any
 * kind (see this file's header): KQ has no ranking, and Club Ratings
 * are a separate, already-published concern this email never
 * duplicates or references.
 */
function spp_kq_build_recap_email_body( ?string $first_name, string $label, string $event_date, array $rounds ) : string {
    $greeting_name = $first_name ?: 'there';
    ob_start();
    ?>
    <p>Hi <?php echo esc_html( $greeting_name ); ?>,</p>
    <p>Here's your round-by-round recap from <?php echo esc_html( $label ); ?> on <?php echo esc_html( $event_date ); ?>:</p>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">
        <tr style="background:#2c3e50;color:#fff;">
            <th>Round</th><th>Court</th><th>Partner</th><th>Opponents</th><th>Score</th><th>Result</th>
        </tr>
        <?php foreach ( $rounds as $r ) : ?>
        <tr>
            <td><?php echo esc_html( $r['round_number'] ); ?></td>
            <td><?php echo esc_html( $r['court_name'] ); ?></td>
            <td><?php echo esc_html( $r['partner_name'] ); ?></td>
            <td><?php echo esc_html( $r['opponent_names'] ); ?></td>
            <td><?php echo esc_html( $r['my_score'] . '-' . $r['opp_score'] ); ?></td>
            <td><?php echo $r['won'] ? 'Won' : 'Lost'; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p>Thanks for playing!</p>
    <?php
    return ob_get_clean();
}

/**
 * Send one recap email per player who appears anywhere in $scoreboard.
 * Same header convention as gl-publish-schedule.php's own recap email
 * (Content-Type text/html, From: Stouffville Pickleball Players).
 *
 * DRY RUN: apply_filters( 'spp_kq_recap_email_dry_run', false ) -- when
 * true, no real wp_mail() call is made; each intended send is returned
 * in the result array instead (with the built 'rounds' data included),
 * so a test can inspect exactly what would have been sent without
 * emailing anyone. Default false -- real production events need no
 * extra configuration to actually send.
 *
 * @return array List of ['user_id'=>int, 'email'=>string,
 *   'subject'=>string, 'sent'=>bool, 'dry_run'=>bool, 'error'=>?string].
 */
function spp_kq_send_recap_emails( array $scoreboard, string $event_date, string $source ) : array {
    $user_ids = array();
    foreach ( $scoreboard as $courts ) {
        foreach ( $courts as $court ) {
            foreach ( array( 'red', 'black' ) as $color ) {
                foreach ( $court[ $color ] as $p ) {
                    $user_ids[ $p['user_id'] ] = true;
                }
            }
        }
    }
    $user_ids = array_keys( $user_ids );
    if ( empty( $user_ids ) ) {
        return array();
    }

    // Guests (spp_kq_roster_add_guest(), inc/spp-kq-roster.php) have no
    // real email address -- never attempt to send them a recap.
    // Belt-and-suspenders: the membership lookup below wouldn't return a
    // row for them anyway (guests are deliberately not in that table),
    // but this makes the skip explicit rather than incidental.
    $user_ids = array_values( array_filter( $user_ids, function( $uid ) {
        return ! get_user_meta( $uid, 'spp_kq_guest', true );
    } ) );
    if ( empty( $user_ids ) ) {
        return array();
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
    $players = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, user_email FROM membership WHERE user_id IN ({$placeholders})",
        $user_ids
    ), ARRAY_A );

    $dry_run = (bool) apply_filters( 'spp_kq_recap_email_dry_run', false );
    $label   = ( $source === 'ace' ) ? 'Ace of the Courts' : 'Queen of the Courts';
    $subject = "Stouffville Pickleball Players -- {$label} Recap for {$event_date}";
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    $results = array();
    foreach ( $players as $p ) {
        $uid   = (int) $p['user_id'];
        $email = (string) $p['user_email'];

        if ( $email === '' ) {
            $results[] = array( 'user_id' => $uid, 'email' => '', 'subject' => $subject, 'sent' => false, 'dry_run' => $dry_run, 'error' => 'no email on file' );
            continue;
        }

        $rounds = spp_kq_extract_player_rounds( $scoreboard, $uid );

        if ( $dry_run ) {
            $results[] = array( 'user_id' => $uid, 'email' => $email, 'subject' => $subject, 'sent' => false, 'dry_run' => true, 'error' => null, 'rounds' => $rounds );
            continue;
        }

        $body = spp_kq_build_recap_email_body( $p['first_name'], $label, $event_date, $rounds );
        $ok   = wp_mail( $email, $subject, $body, $headers );
        $results[] = array( 'user_id' => $uid, 'email' => $email, 'subject' => $subject, 'sent' => (bool) $ok, 'dry_run' => false, 'error' => null );
    }

    return $results;
}

// =============================================================
// Single entry point for the dispatcher
// =============================================================

/**
 * Called from spp_kq_handle_post_actions()'s 'end_event' AND
 * 'cancel_event' cases (inc/spp-kq-screens.php), immediately alongside
 * spp_kq_maybe_publish_to_club_ratings() -- same trigger point for both,
 * same pre-launch guard (reused via spp_kq_archive_event_history(), not
 * duplicated). Archives permanent history and sends each participant a
 * recap email, from one shared spp_kq_get_full_scoreboard() read so
 * both use identical data -- whether the event ended cleanly or was
 * cancelled mid-round, any round that had already reported is treated
 * the same way.
 *
 * Returns a short plain-text status, same convention as
 * spp_kq_maybe_publish_to_club_ratings() ('' if nothing worth saying,
 * e.g. a pre-launch/sandbox occurrence, or a cancellation before any
 * round ever reported).
 */
function spp_kq_finalize_event_history_and_recap( int $occurrence_id, string $event_date ) : string {
    $archive = spp_kq_archive_event_history( $occurrence_id, $event_date );

    if ( ! $archive['archived'] ) {
        return '';
    }

    $source = spp_kq_category_source( $occurrence_id );
    $emails = spp_kq_send_recap_emails( $archive['scoreboard'], $event_date, $source );
    $sent_count = count( array_filter( $emails, fn( $e ) => $e['sent'] ) );

    return sprintf(
        'History archived: %d row(s). Recap emails sent: %d of %d player(s).',
        $archive['row_count'], $sent_count, count( $emails )
    );
}

// =============================================================
// spp-reports.php entry: kq_history (registered in inc/spp-reports.php's
// $spp_report_registry; defined here alongside the rest of this feature)
// =============================================================

/**
 * KQ History report: every archived spp_kq_history row, enriched with
 * partner/opponent names computed from the same-court group (a PHP
 * pass over the already-fetched rows, not a self-join). Visible to
 * every logged-in member -- no per-player restriction, no admin-only
 * gate, per explicit instruction: this is a plain post-event score log
 * (no ranking -- KQ has none; Club Ratings are a separate, already-
 * published concern this report doesn't touch), nothing sensitive in
 * the same sense results/membership_tags are (no override/internal
 * fields, no financial/tag data).
 *
 * GROUPING WITHIN THE FLAT-TABLE RENDERER: spp_render_report_table()
 * only ever renders a flat, single-sort-key table -- no nested "event >
 * round > court" section headers. "Organized by event, then by round"
 * is reproduced the same way spp_report_membership()/spp_report_master()
 * already reproduce a compound sort within that same constraint (see
 * inc/spp-reports.php's own 1.1.0 changelog): the SQL query itself is
 * pre-ordered event_date DESC, round_number ASC, court_name ASC, and
 * 'default_sort' is a single key (event_date DESC) -- PHP 8's stable
 * usort(), sorting by that one key alone, preserves the round/court
 * sub-ordering already baked into the query for rows sharing the same
 * event_date, exactly the trick membership/master_list already rely on.
 */
function spp_report_kq_history() {
    global $wpdb;
    $table = spp_kq_history_table();

    // Court order within the SQL itself (not a PHP-side re-sort, unlike
    // spp_kq_get_full_scoreboard()/spp_kq_get_history_scoreboard() above)
    // -- this report's row order IS its display order (flat table, no
    // court-keyed grouping to re-key), and this function's own stable-
    // usort convention (see this function's docblock/1.1.0 changelog)
    // depends on the SQL pre-ordering courts correctly within each
    // round. FIELD() clause built from spp_kq_master_court_hierarchy()
    // (inc/spp-kq-live.php) -- same single shared definition as the
    // PHP-side re-sort above, not a second hardcoded copy of the order.
    $court_field_sql = 'FIELD(h.court_name, ' . implode( ', ', array_map(
        fn( $c ) => "'" . esc_sql( $c ) . "'", spp_kq_master_court_hierarchy()
    ) ) . ')';

    $rows = $wpdb->get_results(
        "SELECT h.occurrence_id, h.round_number, h.court_name, h.team_color, h.user_id,
                h.red_score, h.black_score, h.event_date, h.source,
                m.first_name, m.last_name
         FROM {$table} h
         LEFT JOIN membership m ON m.user_id = h.user_id
         ORDER BY h.event_date DESC, h.round_number ASC, {$court_field_sql} ASC, h.team_color ASC",
        ARRAY_A
    );

    // Group by (occurrence_id, round_number, court_name) so each row can
    // look up its own partner/opponents -- same shape
    // spp_kq_get_full_scoreboard() builds from the live tables, rebuilt
    // here from the archived table instead (this report never touches
    // spp_kq_assignments/spp_kq_scores).
    $groups = array();
    foreach ( $rows as $r ) {
        $key = $r['occurrence_id'] . '|' . $r['round_number'] . '|' . $r['court_name'];
        $groups[ $key ][ $r['team_color'] ][] = $r;
    }

    $name_of = fn( $row ) => spp_kq_player_name( $row['first_name'], $row['last_name'], (int) $row['user_id'] );

    $report_rows = array();
    foreach ( $rows as $r ) {
        $key         = $r['occurrence_id'] . '|' . $r['round_number'] . '|' . $r['court_name'];
        $group       = $groups[ $key ];
        $my_color    = $r['team_color'];
        $other_color = ( $my_color === 'red' ) ? 'black' : 'red';

        $partner = null;
        foreach ( $group[ $my_color ] as $teammate ) {
            if ( (int) $teammate['user_id'] !== (int) $r['user_id'] ) {
                $partner = $teammate;
                break;
            }
        }
        $opponents = $group[ $other_color ] ?? array();

        $my_score  = ( $my_color === 'red' ) ? $r['red_score'] : $r['black_score'];
        $opp_score = ( $my_color === 'red' ) ? $r['black_score'] : $r['red_score'];

        $report_rows[] = array(
            'event_date'     => $r['event_date'],
            'source'         => ( $r['source'] === 'ace' ) ? 'Ace' : 'Queen',
            'round_number'   => (int) $r['round_number'],
            'court_name'     => $r['court_name'],
            'player_name'    => $name_of( $r ),
            'partner_name'   => $partner ? $name_of( $partner ) : '',
            'opponent_names' => implode( ' & ', array_map( $name_of, $opponents ) ),
            'score'          => "{$my_score}-{$opp_score}",
            'result'         => ( (int) $my_score > (int) $opp_score ) ? 'Won' : 'Lost',
        );
    }

    $columns = array(
        array( 'key' => 'event_date',     'label' => 'Event Date', 'sortable' => true ),
        array( 'key' => 'source',         'label' => 'Event',      'sortable' => true ),
        array( 'key' => 'round_number',   'label' => 'Round',      'sortable' => true ),
        array( 'key' => 'court_name',     'label' => 'Court',      'sortable' => true ),
        array( 'key' => 'player_name',    'label' => 'Player',     'sortable' => true ),
        array( 'key' => 'partner_name',   'label' => 'Partner',    'sortable' => true ),
        array( 'key' => 'opponent_names', 'label' => 'Opponents',  'sortable' => true ),
        array( 'key' => 'score',          'label' => 'Score',      'sortable' => false ),
        array( 'key' => 'result',         'label' => 'Result',     'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $report_rows,
        'default_sort' => array( 'column' => 'event_date', 'direction' => 'DESC' ),
    );
}
