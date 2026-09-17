<?php
/* =========================================================
   Ace/Queen of the Courts — Format Rankings
   Version: 1.0.0
   Date: 2026-09-17

   PURPOSE:
   Two independent, decay-weighted moving-average rankings -- one for
   Ace of the Courts, one for Queen of the Courts -- based on the court
   each player ENDS an event on (Aces=4 down to Jacks=1, spp_kq_court_
   value(), inc/spp-kq-live.php). Deliberately separate from Club
   Rating (inc/spp-kq-club-rating.php, inc/spp-update-club-ratings.php):
   that's a Glicko-2 skill rating derived from actual game results
   across BOTH ladder and KQ; this is a simple, KQ-only "where do you
   typically finish" trend per format, and the two never influence each
   other -- an Ace result never touches a player's Queen average or
   vice versa.

   STORAGE, matching the ladder's own established Rank convention
   exactly (spp-copy-ranks-to-user-profile.php: plain usermeta,
   delete-then-insert) rather than inventing a different pattern:
     - spp_kq_ace_avg / spp_kq_queen_avg -- the raw decayed average
       itself (float), the ONLY state that needs to persist between
       events for the incremental EMA update below. Internal only --
       never displayed directly, never read by anything outside this
       file.
     - spp_kq_ace_rank / spp_kq_queen_rank -- the DISPLAYED value: this
       player's sequential sitewide position (1st, 2nd, 3rd...) when
       every player with an average in that format is sorted by it,
       best first. Recomputed for the WHOLE population after every
       event, not just today's participants -- one player's average
       moving can shift everyone else's relative position too -- via
       rankUsersWithTies() (inc/spp-create-results.php), the ladder's
       own existing tie-break helper, reused as-is rather than
       reimplemented: on a tie, whoever already held the better
       (lower-numbered) rank position keeps it, exactly the ladder's
       own rule (that function's own usort: current_rank ascending).
     - Both propagated into membership/Master/Masterlist{year}/
       Membershiplist{year} by spp_create_membership_table() (inc/
       spp-create-membership-table.php 1.5.0), same as ClubRating/DUPR.

   DECAY CONSTANT: alpha = 0.2, i.e.
     new_avg = 0.2 * court_value + 0.8 * old_avg
   -- the standard N-period EMA formula (alpha = 2/(N+1)) for N=9,
   landing in the middle of the requested ~8-10 event effective-memory
   range. A player's very first event in a format sets their average
   directly to that event's court value (no old_avg exists yet to
   blend with) -- no minimum-events gate; the decay itself is what
   lets a new player's average keep moving noticeably for their first
   several events and settle down as more accumulate.

   TRIGGER: automatic, same call site as spp_kq_maybe_publish_to_club_
   ratings()/spp_kq_finalize_event_history_and_recap() -- spp_kq_
   handle_post_actions()'s 'end_event'/'cancel_event' cases (inc/spp-kq-
   screens.php 1.23.0). Reuses that same pre-launch-date guard
   (SPP_KQ_CLUB_RATING_LAUNCH_DATE, inc/spp-kq-club-rating.php) for the
   same reason Club Rating has one: a sandbox/test event must never
   move a real player's format ranking.

   "FINAL COURT": the court from the LAST round that actually has a
   reported score for that player (both red_score/black_score non-NULL,
   cancelled=0) -- NOT necessarily the literal highest round_number
   they have an assignment row for. A round that never got played (the
   event ended before it was scored -- e.g. occurrence 265's real
   round 7 today, assigned by movement but never reported) reflects a
   placement decision, not a result; using the last PLAYED round is
   both the more defensible reading of "ends an event on" and avoids
   ever crediting a court nobody actually finished a game on.

   GUESTS: excluded entirely, before anything else runs for them --
   spp_kq_guest usermeta, same flag/check as Club Rating's own
   exclusion. A guest never gets an average, a rank, or a row in this
   ranking, ever -- per-player, not per-game, since (unlike Club
   Rating, which can meaningfully skip just the ONE game a guest
   touched) a format ranking is a per-player running average with
   nothing else to fall back to for a guest's own "final court".

   REPUBLISH: unlike Club Rating (club_rating_event_log, a full audit
   ledger enabling exact rollback-and-reprocess), this file keeps no
   per-event ledger of its own -- an EMA blend can't be un-blended
   without one. Not a gap in practice: 'end_event'/'cancel_event' are
   both CAS-guarded state transitions (spp_kq_transition_end_event()/
   spp_kq_transition_cancel_event(), inc/spp-kq-live.php) that only
   ever fire once per real completion; the only way to run this
   function twice for the same occurrence is a genuine Full Reset +
   full replay, which is exactly the "start over, this run's data was
   wrong" case a fresh EMA contribution is supposed to represent anyway.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * alpha = 2/(N+1) for N=9 -- see file header for the full reasoning.
 */
const SPP_KQ_FORMAT_RANK_DECAY_ALPHA = 0.2;

/**
 * The single entry point, called automatically after a successful
 * 'end_event' or 'cancel_event' transition -- same trigger point as
 * spp_kq_maybe_publish_to_club_ratings(). Never called any other way.
 *
 * @return array ['notice' => string, 'updated' => bool]. 'updated' is
 *   true only when at least one real player's average actually changed
 *   this call -- lets the caller decide whether a membership-table
 *   rebuild is worth triggering, same convention spp_kq_maybe_publish_
 *   to_club_ratings() already established.
 */
function spp_kq_update_format_rankings( int $occurrence_id, string $event_date ) : array {

    // Same permanent, literal cutoff Club Rating uses -- see that
    // file's own header for why this is never a "before today" check.
    if ( $event_date < SPP_KQ_CLUB_RATING_LAUNCH_DATE ) {
        return array( 'notice' => '', 'updated' => false );
    }

    $source = spp_kq_category_source( $occurrence_id );
    if ( ! $source ) {
        return array( 'notice' => '', 'updated' => false ); // not a recognized Ace/Queen occurrence
    }

    $final_courts = spp_kq_get_final_courts( $occurrence_id );
    if ( empty( $final_courts ) ) {
        return array( 'notice' => '', 'updated' => false ); // nothing reported at all this event
    }

    global $wpdb;
    $usermeta  = $wpdb->prefix . 'usermeta';
    $avg_key   = "spp_kq_{$source}_avg";
    $updated_count = 0;

    foreach ( $final_courts as $user_id => $court_name ) {
        // Guests are excluded entirely -- never tracked, never given an
        // average, never appear in either ranking. See file header.
        if ( get_user_meta( $user_id, 'spp_kq_guest', true ) ) {
            continue;
        }
        $value = spp_kq_court_value( $court_name );
        if ( $value === null ) {
            continue; // defensive -- shouldn't happen, courts are a fixed closed set
        }

        $old_avg_raw = get_user_meta( $user_id, $avg_key, true );
        $new_avg = ( $old_avg_raw === '' || $old_avg_raw === false )
            ? (float) $value
            : ( SPP_KQ_FORMAT_RANK_DECAY_ALPHA * $value ) + ( ( 1 - SPP_KQ_FORMAT_RANK_DECAY_ALPHA ) * (float) $old_avg_raw );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$usermeta} WHERE user_id=%d AND meta_key=%s", $user_id, $avg_key ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %f)", $user_id, $avg_key, $new_avg ) );
        $updated_count++;
    }

    if ( $updated_count === 0 ) {
        return array( 'notice' => '', 'updated' => false ); // every player who reported today was a guest
    }

    spp_kq_recompute_format_ranks( $source );

    return array(
        'notice'  => sprintf(
            'Format rankings updated: %d player(s)\' %s of the Courts average.',
            $updated_count, $source === 'ace' ? 'Ace' : 'Queen'
        ),
        'updated' => true,
    );
}

/**
 * Every player's LAST PLAYED (reported, non-cancelled) court for this
 * occurrence -- see this file's own "FINAL COURT" header note for why
 * this is round_number MAX among REPORTED rounds, not the literal
 * highest round_number a player has an assignment row for. Same JOIN
 * shape spp_kq_get_full_scoreboard() (inc/spp-kq-history.php) and
 * spp_kq_build_club_rating_games() (inc/spp-kq-club-rating.php) already
 * use for "a court that was genuinely played".
 *
 * @return array user_id => court_name.
 */
function spp_kq_get_final_courts( int $occurrence_id ) : array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.user_id, a.round_number, a.court_name
         FROM " . spp_kq_assignments_table() . " a
         JOIN " . spp_kq_scores_table() . " s
           ON s.occurrence_id = a.occurrence_id AND s.round_number = a.round_number AND s.court_name = a.court_name
         WHERE a.occurrence_id = %d AND a.user_id IS NOT NULL
           AND s.red_score IS NOT NULL AND s.black_score IS NOT NULL AND s.cancelled = 0
         ORDER BY a.user_id ASC, a.round_number DESC",
        $occurrence_id
    ), ARRAY_A );

    $final = array();
    foreach ( $rows as $r ) {
        $uid = (int) $r['user_id'];
        if ( ! isset( $final[ $uid ] ) ) { // first row per user_id wins, thanks to round_number DESC
            $final[ $uid ] = $r['court_name'];
        }
    }
    return $final;
}

/**
 * Full sitewide re-rank for one format ('ace'/'queen') -- every player
 * who has EVER had an average in this format (not just today's
 * participants), sorted best-average-first, ties broken by
 * rankUsersWithTies() (inc/spp-create-results.php) -- the ladder's own
 * existing helper, reused as-is; see file header. Writes the new
 * sequential position to spp_kq_{source}_rank usermeta for every
 * affected player.
 *
 * SCALED SCORES -- READ BEFORE "SIMPLIFYING" THIS AWAY: rankUsersWithTies()
 * sorts via `usort($entries, fn($a,$b) => $b['score'] - $a['score'])`.
 * PHP's usort casts a non-integer comparator return to (int) internally,
 * and (int) truncates toward zero -- so a real, non-tied difference
 * under 1.0 (e.g. 4.0 vs 3.5, diff 0.5) silently truncates to (int)0
 * and gets treated as a TIE. Confirmed directly: `usort([3.5,4.0], fn($x,$y)=>$y-$x)`
 * leaves the array unsorted. This theme's own KQ averages live in a
 * narrow 1.0-4.0 range (spp_kq_court_value()), so most real pairs
 * differ by well under 1.0 -- this bug would trigger constantly here,
 * even though it may rarely surface for the ladder's own typically
 * wider-spread integer Score values. NOT fixed in rankUsersWithTies()
 * itself (out of scope for a KQ feature to silently alter shared,
 * high-stakes ladder ranking code without a dedicated look -- flagged
 * to the user instead). Worked around HERE ONLY: multiply every score
 * by a large constant before handing it to that function -- exact
 * equality (a genuine tie) is preserved under multiplication, and any
 * genuinely different average now differs by far more than 1.0, so the
 * truncation bug can never trigger for this call. The function's
 * RETURNED ranks (plain sequential integers) are unaffected either way
 * -- only the scores fed into its own internal sort are scaled.
 */
const SPP_KQ_FORMAT_RANK_TIEBREAK_SCALE = 1000000;

function spp_kq_recompute_format_ranks( string $source ) : void {
    global $wpdb;
    $usermeta = $wpdb->prefix . 'usermeta';
    $avg_key  = "spp_kq_{$source}_avg";
    $rank_key = "spp_kq_{$source}_rank";

    $avg_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, meta_value FROM {$usermeta} WHERE meta_key = %s", $avg_key
    ), ARRAY_A );
    if ( empty( $avg_rows ) ) {
        return;
    }

    $scores = array();
    foreach ( $avg_rows as $r ) {
        $scores[ (int) $r['user_id'] ] = (float) $r['meta_value'] * SPP_KQ_FORMAT_RANK_TIEBREAK_SCALE;
    }

    $rank_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, meta_value FROM {$usermeta} WHERE meta_key = %s", $rank_key
    ), ARRAY_A );
    $current_ranks = array();
    foreach ( $rank_rows as $r ) {
        $current_ranks[ (int) $r['user_id'] ] = (int) $r['meta_value'];
    }

    // rankUsersWithTies() (inc/spp-create-results.php): sorts descending
    // by score (best first), ties broken by ascending current_rank
    // (whoever already held the better position keeps it on a tie,
    // defaulting to 9999 -- lowest tie-break priority -- for a player
    // with no current_ranks entry yet), then assigns sequential 1..N.
    $new_ranks = rankUsersWithTies( $scores, $current_ranks );

    foreach ( $new_ranks as $user_id => $rank ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$usermeta} WHERE user_id=%d AND meta_key=%s", $user_id, $rank_key ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %d)", $user_id, $rank_key, $rank ) );
    }
}
