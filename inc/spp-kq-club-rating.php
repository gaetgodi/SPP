<?php
/* =========================================================
   Ace/Queen of the Courts — Club Rating Integration
   Version: 1.0.0
   Date: 2026-09-12

   PURPOSE:
   Feeds a completed (or cancelled-with-kept-data) Ace/Queen occurrence
   into the exact same shared Glicko rating engine the ladder uses --
   spp_crt_process_event_ratings(), extracted from inc/spp-update-
   club-ratings.php's Steps 2-6 (see that file's own 1.1.0 changelog
   for the full reasoning). This file supplies only KQ's own "Step 1":
   reconstructing $games/$rank_by_user from spp_kq_scores/
   spp_kq_assignments/Master, in exactly the shape that shared
   function expects.

   TRIGGER: automatic. spp_kq_maybe_publish_to_club_ratings() is
   called from spp_kq_handle_post_actions() (inc/spp-kq-screens.php)
   immediately after a successful 'end_event' or 'cancel_event'
   transition -- no separate admin action, no button.

   PRE-LAUNCH GUARD -- read this before touching the cutoff date:
   any occurrence whose real event_date is before SPP_KQ_CLUB_
   RATING_LAUNCH_DATE (2026-09-17) is skipped entirely, before this
   file does anything else -- no adapter call, no call into
   spp_crt_process_event_ratings(), nothing that could touch
   club_rating_state or club_rating_event_log. This is a PERMANENT,
   LITERAL constant, not a "before today" check: a dynamic cutoff
   would keep suppressing genuinely real events forever once they're
   in the past relative to whatever "today" happens to be when the
   check runs. A fixed date exempts only this initial pre-launch
   testing window; every real occurrence from Sep 17 onward publishes
   normally, permanently, no matter how far in the past it eventually
   becomes. DO NOT change this to date()/current_time() logic.

   Because of this guard, none of the 4 Practice/Test Sandbox
   occurrences (spp-kq-screens.php's event picker deliberately offers
   4 real PAST occurrences, dated before this feature ever existed,
   for free-form testing -- see its own comment) nor any pre-launch
   dry run on a real future occurrence can EVER create a
   club_rating_state or club_rating_event_log row. That also means
   Full Reset needs no rating-rollback extension, and there is no
   "out of order rollback" question to resolve for test data -- there
   is nothing to roll back, because nothing test-related is ever
   published to the rating engine in the first place. Verified
   empirically, 2026-09-12: see the Stage 4 conversation for a real
   sandbox occurrence completed end-to-end with zero rows appearing in
   either table afterward, and a constructed Sep-17+ occurrence
   publishing normally.

   This guard lives entirely in this file -- spp_crt_process_event_
   ratings() and spp_update_club_ratings() (inc/spp-update-club-
   ratings.php) have no knowledge of it and are called no
   differently, with no new parameters, than before this file existed.
   The ladder's own path (spp_apply_override_to_results_table() ->
   spp_update_club_ratings()) never runs any code in this file at all.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Permanent, hardcoded pre-launch cutoff -- see file header. This is
 * NOT a "before today" check; do not replace with date()/current_
 * time() logic.
 */
const SPP_KQ_CLUB_RATING_LAUNCH_DATE = '2026-09-17';

/**
 * Maps a KQ occurrence to the club_rating_event_log 'source' bucket
 * it publishes under, from the occurrence's real GL Events category
 * (2 = Ace, 3 = Queen -- see spp-kq-schema.php's own header). Returns
 * null if the occurrence isn't found or isn't category 2/3 -- should
 * never happen given every caller of this file only ever reaches
 * occurrences already inside the KQ feature, but fail safe rather
 * than guess a source.
 */
function spp_kq_category_source( int $occurrence_id ) : ?string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $cat = $wpdb->get_var( $wpdb->prepare(
        "SELECT eff_category_id FROM {$view} WHERE occurrence_id = %d",
        $occurrence_id
    ) );
    if ( (int) $cat === 2 ) return 'ace';
    if ( (int) $cat === 3 ) return 'queen';
    return null;
}

/**
 * KQ's own "Step 1": reconstruct $games + $rank_by_user for one
 * occurrence, in exactly the shape spp_crt_process_event_ratings()
 * expects. Simpler than the ladder's own Step 1 -- spp_kq_scores has
 * no "0 is the unused-column default" ambiguity (NULL unambiguously
 * means "not yet reported", the same predicate already used at
 * spp_kq_has_any_recorded_score() and elsewhere in spp-kq-live.php),
 * and team membership is explicit in spp_kq_assignments.team_color,
 * never inferred from score values.
 *
 * A cancelled occurrence needs zero special-casing here: Cancel
 * Event already hard-deletes the unreported round's rows (both
 * spp_kq_scores and spp_kq_assignments -- see spp_kq_transition_
 * cancel_event()), so this query already only ever sees kept courts.
 */
function spp_kq_build_club_rating_games( int $occurrence_id ) : array {
    global $wpdb;
    $scores_table      = spp_kq_scores_table();
    $assignments_table = spp_kq_assignments_table();

    $score_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT round_number, court_name, red_score, black_score
         FROM {$scores_table}
         WHERE occurrence_id = %d AND red_score IS NOT NULL AND black_score IS NOT NULL",
        $occurrence_id
    ), ARRAY_A );

    $games = array();
    $skipped_courts = 0;

    foreach ( $score_rows as $sr ) {
        $sides = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, team_color FROM {$assignments_table}
             WHERE occurrence_id = %d AND round_number = %d AND court_name = %s AND user_id IS NOT NULL",
            $occurrence_id, $sr['round_number'], $sr['court_name']
        ), ARRAY_A );

        $red = array(); $black = array();
        foreach ( $sides as $s ) {
            if ( $s['team_color'] === 'red' ) $red[] = (int) $s['user_id'];
            else $black[] = (int) $s['user_id'];
        }

        // Defensive: a genuine KQ court is always exactly 2 red + 2 black.
        // Skip (and count) anything else rather than feed a malformed
        // "game" into the shared rating math -- same posture as the
        // ladder's own "unreconstructed" bucket in its own Step 1.
        if ( count( $red ) !== 2 || count( $black ) !== 2 ) {
            $skipped_courts++;
            continue;
        }

        $games[] = array(
            'team1' => $red, 'team2' => $black,
            'score1' => (int) $sr['red_score'], 'score2' => (int) $sr['black_score'],
        );
    }

    $players = array();
    foreach ( $games as $g ) {
        foreach ( $g['team1'] as $u ) $players[ $u ] = true;
        foreach ( $g['team2'] as $u ) $players[ $u ] = true;
    }
    $players = array_keys( $players );

    // Ladder Rank for whichever of these players are ladder-registered --
    // same 'Rank > 0' filter spp-club-rating.php already uses. A KQ player
    // with no ladder history simply isn't in this map; spp_crt_seed_new_
    // player() already handles that (falls back to Glicko defaults).
    $rank_by_user = array();
    if ( ! empty( $players ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $players ), '%d' ) );
        $rank_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, Rank FROM Master WHERE user_id IN ({$placeholders}) AND Rank > 0",
            $players
        ), ARRAY_A );
        foreach ( $rank_rows as $rr ) {
            $rank_by_user[ (int) $rr['user_id'] ] = (int) $rr['Rank'];
        }
    }

    return array( 'games' => $games, 'rank_by_user' => $rank_by_user, 'skipped_courts' => $skipped_courts );
}

/**
 * The single entry point called automatically after a successful
 * 'end_event' or 'cancel_event' transition. Never called any other
 * way. Returns a short plain-text status for the facilitator's
 * notice banner ('' if there's nothing worth saying) -- deliberately
 * plain text, not HTML: spp_kq_render_occurrence_header() renders
 * $notice through esc_html().
 */
function spp_kq_maybe_publish_to_club_ratings( int $occurrence_id, string $event_date ) : string {

    // Hard guard -- checked FIRST, before anything below can touch
    // club_rating_state or club_rating_event_log. See file header:
    // permanent literal cutoff, not "before today".
    if ( $event_date < SPP_KQ_CLUB_RATING_LAUNCH_DATE ) {
        return '';
    }

    $source = spp_kq_category_source( $occurrence_id );
    if ( ! $source ) {
        return ''; // not a recognized Ace/Queen occurrence -- nothing to do
    }

    $built = spp_kq_build_club_rating_games( $occurrence_id );
    if ( empty( $built['games'] ) ) {
        return ''; // nothing reported (e.g. a cancelled event with zero kept courts) -- nothing to publish
    }

    $r = spp_crt_process_event_ratings( $built['games'], $built['rank_by_user'], $occurrence_id, $source, false );

    if ( $r['aborted'] ) {
        return 'Club Ratings NOT updated -- this occurrence was already published, and a later event has since built on one of its players\' ratings. Needs a manual look at club_rating_event_log before reprocessing.';
    }

    if ( ! $r['usermeta_written'] ) {
        return "Club Ratings updated internally for {$r['updated_count']} player(s), but there aren't enough established players yet ({$r['n_established']}) for a stable public scale -- usermeta not written this run.";
    }

    $skip_note = $built['skipped_courts'] > 0
        ? " ({$built['skipped_courts']} court(s) skipped -- couldn't be matched 2v2)"
        : '';
    $republish_note = $r['rolled_back_count'] > 0 ? ' (re-published: prior contribution rolled back first.)' : '';

    return sprintf(
        'Published to Club Ratings: %d player(s) updated from %d game(s)%s.%s',
        $r['updated_count'], count( $built['games'] ), $skip_note, $republish_note
    );
}
