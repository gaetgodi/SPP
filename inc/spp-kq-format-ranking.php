<?php
/* =========================================================
   Ace/Queen of the Courts — Format Rankings
   Version: 1.1.1
   Date: 2026-09-17

   Changes from 1.1.0 (BUG FIX, found while re-testing the 1.1.0
   change against real data): spp_kq_update_format_rankings()/spp_kq_
   recompute_format_ranks() now write spp_kq_{source}_avg/rank via
   update_user_meta() instead of raw $wpdb->query() DELETE+INSERT.
   Confirmed directly on this site's own Redis-backed persistent object
   cache: a raw SQL write leaves get_user_meta() returning a STALE
   cached value indefinitely for that key -- and a LATER delete_user_
   meta() call can't self-heal it either, since it finds nothing left
   to delete (the raw SQL already removed the row) and skips its own
   cache invalidation as a result. The raw-SQL pattern was copied from
   spp_crt_process_event_ratings()'s own STEP 5 (spp_glicko_rating) --
   but that precedent never reads its OLD value back via get_user_meta()
   at all (its "old" state comes from club_rating_state, a plain custom
   table, always read via raw SQL); this file's whole EMA design
   depends on get_user_meta() correctly seeing the last write, so it
   needed the API that actually keeps the cache honest. See spp_kq_
   update_format_rankings()'s own inline comment for the full story.

   PURPOSE:
   Two independent, decay-weighted moving-average rankings -- one for
   Ace of the Courts, one for Queen of the Courts -- based on each
   player's HYPOTHETICAL next-round court after their actual final
   result (Aces=4 down to Jacks=1, spp_kq_court_value(), inc/spp-kq-
   live.php). Deliberately separate from Club Rating (inc/spp-kq-club-
   rating.php, inc/spp-update-club-ratings.php): that's a Glicko-2
   skill rating derived from actual game results across BOTH ladder and
   KQ; this is a simple, KQ-only "where do you typically finish" trend
   per format, and the two never influence each other -- an Ace result
   never touches a player's Queen average or vice versa.

   Changes from 1.0.0 (real ranking discrepancy investigated same day:
   Gaetan expected Kathleen Castillo above Sol Boada in Queen Rank for
   occurrence 265 -- both finished on Aces, but Kathleen WON her final
   game and Sol LOST his, and 1.0.0 scored strictly by final court, so
   the winner and loser of the very same last game got an IDENTICAL
   score. Root cause: the loser hadn't actually been relegated yet --
   that only happens at the start of a round that, for a player's last
   round, never comes): spp_kq_get_final_courts() renamed to spp_kq_
   get_hypothetical_next_courts() and rewritten to feed each player's
   actual final round through spp_kq_compute_next_round() (inc/spp-kq-
   movement.php) -- the SAME real movement/clamping logic the event
   itself already uses for real round-to-round advancement, reused
   as-is rather than reimplemented. A winner's hypothetical court is one
   court UP (clamped at Aces -- a winner already on top stays there); a
   loser's is one court DOWN (clamped at Jacks). This naturally splits
   a shared final court into "would rise" vs "would fall" based on the
   real result, resolving both the win/loss blindness and most same-
   court ties in one change. Scores by the same court-value scale as
   before (Aces=4..Jacks=1) -- only WHICH court is scored changed, not
   the value scale, the average/rank storage mechanism, the decay
   constant, the tie-break rule, or guest exclusion.

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

   "HYPOTHETICAL NEXT COURT": spp_kq_get_hypothetical_next_courts()
   starts from each player's real FINAL round -- the LAST round that
   actually has a reported score for them (both red_score/black_score
   non-NULL, cancelled=0), NOT necessarily the literal highest
   round_number they have an assignment row for. A round that never got
   played (the event ended before it was scored -- e.g. occurrence
   265's real round 7 today, assigned by movement but never reported)
   reflects a placement decision, not a result, so it's never used as
   anyone's final round. That final round's COMPLETE data (every active
   court, all decisive, non-cancelled scores) is then run through
   spp_kq_compute_next_round() to get the court their actual win/loss
   would have sent them to. Grouped by each player's own final round
   number (usually the same round for everyone, but can differ if one
   specific court was cancelled partway through while others kept
   going) -- one spp_kq_compute_next_round() call per distinct final
   round. A round that can't be validated by that function (e.g. a
   cancelled/incomplete court within it) skips every player whose final
   round that is, rather than guessing -- same "skip and count" posture
   as spp_kq_build_club_rating_games()'s own shape-mismatch handling
   (inc/spp-kq-club-rating.php). History is passed to spp_kq_compute_
   next_round() as an empty array on purpose: that function only needs
   real partnership history to choose which TWO of the four incoming
   players end up partnered together at the destination court (avoiding
   a repeat partnership) -- this file only reads which COURT each
   player's pair was sent to, never who they'd be paired with, so that
   choice doesn't matter here and querying real history would be pure
   waste.

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

    $final = spp_kq_get_hypothetical_next_courts( $occurrence_id );
    $hypothetical_courts = $final['courts'];
    if ( empty( $hypothetical_courts ) ) {
        return array( 'notice' => '', 'updated' => false ); // nothing reported at all this event
    }

    $avg_key   = "spp_kq_{$source}_avg";
    $updated_count = 0;

    foreach ( $hypothetical_courts as $user_id => $court_name ) {
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

        // 1.1.1 BUG FIX: this used to be raw $wpdb->query() DELETE+INSERT
        // (matching spp_crt_process_event_ratings()'s own STEP 5
        // precedent for spp_glicko_rating) -- but THAT precedent never
        // reads its old value back via get_user_meta() at all (its "old"
        // state comes from club_rating_state, a plain custom table, read
        // via raw SQL every time); THIS function's whole design depends
        // on get_user_meta() correctly seeing what was last written, for
        // the incremental EMA blend above. Confirmed directly, on this
        // site's own Redis-backed persistent object cache: a raw SQL
        // write leaves get_user_meta() returning a STALE cached value
        // indefinitely -- even a LATER delete_user_meta() call can't
        // self-heal it, since it finds nothing to delete (the raw SQL
        // already removed the row) and skips the cache invalidation as a
        // result. update_user_meta() is the correct tool for a value
        // that gets read back via get_user_meta() later -- it invalidates
        // the cache as part of the same write, every time.
        update_user_meta( $user_id, $avg_key, $new_avg );
        $updated_count++;
    }

    if ( $updated_count === 0 ) {
        return array( 'notice' => '', 'updated' => false ); // every player who reported today was a guest
    }

    spp_kq_recompute_format_ranks( $source );

    $skip_note = $final['skipped_players'] > 0
        ? " ({$final['skipped_players']} player(s) skipped -- their final round couldn't be validated)"
        : '';

    return array(
        'notice'  => sprintf(
            'Format rankings updated: %d player(s)\' %s of the Courts average%s.',
            $updated_count, $source === 'ace' ? 'Ace' : 'Queen', $skip_note
        ),
        'updated' => true,
    );
}

/**
 * Every player's HYPOTHETICAL next-round court -- see this file's own
 * "HYPOTHETICAL NEXT COURT" header note for the full design/reasoning.
 *
 * @return array ['courts' => user_id => hypothetical court_name,
 *                'skipped_players' => int count skipped because their
 *                final round couldn't be validated].
 */
function spp_kq_get_hypothetical_next_courts( int $occurrence_id ) : array {
    global $wpdb;

    // Step 1: each player's own real final (last reported, non-
    // cancelled) round + court -- same JOIN shape spp_kq_get_full_
    // scoreboard() (inc/spp-kq-history.php) and spp_kq_build_club_
    // rating_games() (inc/spp-kq-club-rating.php) already use for "a
    // court that was genuinely played".
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

    $raw_final = array(); // user_id => final round_number
    foreach ( $rows as $r ) {
        $uid = (int) $r['user_id'];
        if ( ! isset( $raw_final[ $uid ] ) ) { // first row per user_id wins, thanks to round_number DESC
            $raw_final[ $uid ] = (int) $r['round_number'];
        }
    }
    if ( empty( $raw_final ) ) {
        return array( 'courts' => array(), 'skipped_players' => 0 );
    }

    // Step 2: group players by their own final round number -- usually
    // everyone shares the same one, but a court cancelled partway
    // through the event for just them can make it differ.
    $by_round = array();
    foreach ( $raw_final as $uid => $round_number ) {
        $by_round[ $round_number ][] = $uid;
    }

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $hypothetical = array();
    $skipped_players = 0;

    // Step 3: one spp_kq_compute_next_round() call per distinct final
    // round -- that function needs a COMPLETE round (every active
    // court, clean 2v2, decisive non-cancelled score) to run at all.
    foreach ( $by_round as $round_number => $uids_this_round ) {
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, court_name, team_color FROM " . spp_kq_assignments_table() . "
             WHERE occurrence_id = %d AND round_number = %d AND user_id IS NOT NULL",
            $occurrence_id, $round_number
        ), ARRAY_A );
        $scores = $wpdb->get_results( $wpdb->prepare(
            "SELECT court_name, red_score, black_score FROM " . spp_kq_scores_table() . "
             WHERE occurrence_id = %d AND round_number = %d AND cancelled = 0",
            $occurrence_id, $round_number
        ), ARRAY_A );

        try {
            $next = spp_kq_compute_next_round( $courts_order, $assignments, $scores, array() );
        } catch ( SPP_KQ_Movement_Error $e ) {
            // Can't validate this round (e.g. a cancelled/incomplete
            // court within it) -- skip everyone whose final round this
            // is, rather than guessing at their hypothetical court.
            $skipped_players += count( $uids_this_round );
            continue;
        }

        $dest_by_uid = array();
        foreach ( $next as $row ) {
            $dest_by_uid[ (int) $row['user_id'] ] = $row['court_name'];
        }

        foreach ( $uids_this_round as $uid ) {
            if ( isset( $dest_by_uid[ $uid ] ) ) {
                $hypothetical[ $uid ] = $dest_by_uid[ $uid ];
            } else {
                $skipped_players++; // defensive -- shouldn't happen, every uid here was IN this round's own validated assignments
            }
        }
    }

    return array( 'courts' => $hypothetical, 'skipped_players' => $skipped_players );
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

    // update_user_meta(), not raw SQL -- see spp_kq_update_format_
    // rankings()'s own 1.1.1 bug-fix comment for why (this key isn't
    // currently read back via get_user_meta() anywhere, but keeping
    // both this and spp_kq_{source}_avg on the same safe convention
    // avoids planting the same stale-cache trap for whatever reads it
    // via that API next).
    foreach ( $new_ranks as $user_id => $rank ) {
        update_user_meta( $user_id, $rank_key, $rank );
    }
}
