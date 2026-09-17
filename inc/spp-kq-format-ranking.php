<?php
/* =========================================================
   Ace/Queen of the Courts — Format Rankings
   Version: 1.2.0
   Date: 2026-09-17

   Changes from 1.1.1 (reworked the tie-break scheme -- reviewed and
   approved same day as 1.1.0/1.1.1; this REVISES the hypothetical-
   next-court scoring 1.1.0 introduced, replacing it with a multi-level
   tie-break chain instead):
     - REVERTED the per-event score back to each player's REAL final
       court (Aces=4..Jacks=1) -- NOT the hypothetical next-round court
       spp_kq_compute_next_round() would have sent them to. spp_kq_get_
       hypothetical_next_courts() renamed back to spp_kq_get_final_
       courts() and no longer calls spp_kq_compute_next_round() at all;
       inc/spp-kq-movement.php is no longer a dependency of this file.
     - Reads from spp_kq_history now, not the live spp_kq_scores/
       spp_kq_assignments tables -- simpler (that table is already
       filtered to real, reported, non-cancelled rounds only, so no
       JOIN/NULL-filtering of its own is needed) and, critically, the
       SAME source the new tie-breaks below need for OTHER players'
       past events. This requires spp_kq_history to already hold THIS
       occurrence's rows by the time this file runs -- spp_kq_handle_
       post_actions()'s 'end_event'/'cancel_event' cases (inc/spp-kq-
       screens.php 1.24.0) now call spp_kq_finalize_event_history_and_
       recap() FIRST, before this file, to guarantee that.
     - spp_kq_recompute_format_ranks() no longer routes through
       rankUsersWithTies() for the whole sort -- that function's own
       two-level (score, then previous-rank) signature can't express a
       4-level chain, and its float-comparator bug (found for the
       AVERAGE itself, 1.1.0) made it unsuitable to extend further
       anyway. Now an explicit multi-key usort() built directly in this
       file: (1) average, descending; (2) NEW -- consecutive rounds held
       at the final court (spp_kq_get_tiebreak_data()), descending;
       (3) NEW -- won vs lost the final round; (4) the ladder's own
       previous-rank rule, reused as the exact same comparison
       rankUsersWithTies() itself uses ($a<=>$b on current_rank,
       defaulting to 9999 for unranked) -- inlined rather than calling
       that function, since a whole-list helper doesn't fit as one leg
       of a per-pair usort() comparator; the RULE is reused, not
       reimplemented, even though the call site isn't literal.

   PURPOSE:
   Two independent, decay-weighted moving-average rankings -- one for
   Ace of the Courts, one for Queen of the Courts -- based on the REAL
   court each player ends an event on (Aces=4 down to Jacks=1, spp_kq_
   court_value(), inc/spp-kq-live.php). Deliberately separate from Club
   Rating (inc/spp-kq-club-rating.php, inc/spp-update-club-ratings.php):
   that's a Glicko-2 skill rating derived from actual game results
   across BOTH ladder and KQ; this is a simple, KQ-only "where do you
   typically finish" trend per format, and the two never influence each
   other -- an Ace result never touches a player's Queen average or
   vice versa.

   STORAGE, matching the ladder's own established Rank convention
   (spp-copy-ranks-to-user-profile.php: plain usermeta) rather than
   inventing a different pattern -- written via update_user_meta(), NOT
   raw SQL (see the 1.1.1 note further down for why that distinction is
   load-bearing here specifically):
     - spp_kq_ace_avg / spp_kq_queen_avg -- the raw decayed average
       itself (float), the ONLY state that needs to persist between
       events for the incremental EMA update below. Internal only --
       never displayed directly, never read by anything outside this
       file.
     - spp_kq_ace_rank / spp_kq_queen_rank -- the DISPLAYED value: this
       player's sequential sitewide position (1st, 2nd, 3rd...) when
       every player with an average in that format is sorted by the
       multi-level chain described above. Recomputed for the WHOLE
       population after every event, not just today's participants --
       one player's average moving can shift everyone else's relative
       position too.
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
   ratings() -- spp_kq_handle_post_actions()'s 'end_event'/'cancel_event'
   cases (inc/spp-kq-screens.php), AFTER spp_kq_finalize_event_history_
   and_recap() now (1.2.0 -- see above). Reuses that same pre-launch-date
   guard (SPP_KQ_CLUB_RATING_LAUNCH_DATE, inc/spp-kq-club-rating.php)
   for the same reason Club Rating has one: a sandbox/test event must
   never move a real player's format ranking.

   "FINAL COURT": each player's court on the LAST round of their most
   recent event in this format, as permanently recorded in spp_kq_
   history -- that table only ever holds real, reported, non-cancelled
   rounds (see spp_kq_flatten_scoreboard_for_history()/spp_kq_get_full_
   scoreboard()'s own filtering, inc/spp-kq-history.php), so no extra
   NULL/cancelled filtering is needed here. A round that never got
   played (the event ended before it was scored) was never archived at
   all, so it's never reachable as anyone's final round.

   TIE-BREAK CHAIN (spp_kq_recompute_format_ranks()), in order:
     1. Decayed average, descending -- the primary criterion.
     2. Consecutive rounds held at that final court, descending, counted
        backward from the final round until a different court appears
        or the event's first round is reached (spp_kq_get_tiebreak_
        data()) -- rewards sustained dominance at a court over a
        last-round arrival.
     3. Won vs lost the final round -- a promoted winner ranks above a
        demoted loser who lands in the same tier with the same
        rounds-held count.
     4. The ladder's own previous-rank tie-break rule (whoever already
        held the better position keeps it on a genuine remaining tie).
   Levels 2-3 are computed from each tied player's OWN most recent
   event in this format (not necessarily the event that was just
   processed -- this is a sitewide re-rank of everyone with an average,
   and different players' most recent contributing event can differ),
   and only for players actually tied on the level above -- untied
   players never pay for tie-break data they don't need.

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

   1.1.1 CACHE-SAFETY NOTE (still true, read before "simplifying" the
   update_user_meta() calls back to raw SQL): confirmed directly on
   this site's own Redis-backed persistent object cache that a raw SQL
   usermeta write leaves get_user_meta() returning a STALE cached value
   indefinitely, un-fixable by a later delete_user_meta() (it finds
   nothing left to delete and skips its own cache invalidation). This
   file's whole EMA design depends on get_user_meta() correctly seeing
   the last write -- update_user_meta()/delete_user_meta() are the only
   safe way to touch spp_kq_{source}_avg/rank here.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * alpha = 2/(N+1) for N=9 -- see file header for the full reasoning.
 */
const SPP_KQ_FORMAT_RANK_DECAY_ALPHA = 0.2;

/**
 * The single entry point, called automatically after a successful
 * 'end_event' or 'cancel_event' transition, AFTER history archival --
 * see this file's own header. Never called any other way.
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
        return array( 'notice' => '', 'updated' => false ); // nothing archived for this occurrence
    }

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

        // update_user_meta(), not raw SQL -- see file header's 1.1.1
        // cache-safety note.
        update_user_meta( $user_id, $avg_key, $new_avg );
        $updated_count++;
    }

    if ( $updated_count === 0 ) {
        return array( 'notice' => '', 'updated' => false ); // every player archived for today was a guest
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
 * Every player's REAL final court for this occurrence, from spp_kq_
 * history -- see this file's own "FINAL COURT" header note.
 *
 * @return array user_id => court_name.
 */
function spp_kq_get_final_courts( int $occurrence_id ) : array {
    global $wpdb;
    $table = spp_kq_history_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, round_number, court_name
         FROM {$table}
         WHERE occurrence_id = %d
         ORDER BY user_id ASC, round_number DESC",
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
 * Tie-break data for ONE player, from their MOST RECENT event of this
 * format ('ace'/'queen') in spp_kq_history -- not necessarily the
 * occurrence spp_kq_update_format_rankings() just processed, since
 * spp_kq_recompute_format_ranks() is a sitewide re-rank and a tie can
 * span players whose own most recent contributing event differs. See
 * this file's own "TIE-BREAK CHAIN" header note.
 *
 * @return array ['rounds_held' => int, 'won_final' => bool]. Defaults
 *   to [0, false] if no history is found (shouldn't happen for anyone
 *   who has an average, but defensive).
 */
function spp_kq_get_tiebreak_data( int $user_id, string $source ) : array {
    global $wpdb;
    $table = spp_kq_history_table();

    $latest = $wpdb->get_row( $wpdb->prepare(
        "SELECT occurrence_id FROM {$table}
         WHERE user_id = %d AND source = %s
         ORDER BY event_date DESC, occurrence_id DESC LIMIT 1",
        $user_id, $source
    ), ARRAY_A );
    if ( ! $latest ) {
        return array( 'rounds_held' => 0, 'won_final' => false );
    }

    // This player's own rounds in that event, most recent first.
    $rounds = $wpdb->get_results( $wpdb->prepare(
        "SELECT round_number, court_name, team_color, red_score, black_score
         FROM {$table} WHERE occurrence_id = %d AND user_id = %d
         ORDER BY round_number DESC",
        $latest['occurrence_id'], $user_id
    ), ARRAY_A );
    if ( empty( $rounds ) ) {
        return array( 'rounds_held' => 0, 'won_final' => false ); // defensive -- shouldn't happen, $latest came from this same table
    }

    $final = $rounds[0];
    $final_court = $final['court_name'];
    $won_final = ( $final['team_color'] === 'red' )
        ? ( (int) $final['red_score'] > (int) $final['black_score'] )
        : ( (int) $final['black_score'] > (int) $final['red_score'] );

    // Consecutive rounds counting backward from the final round while
    // the court stays the same, stopping at the first differing court
    // or the event's first round -- $rounds is already round_number
    // DESC, so walking it forward IS walking backward through the event.
    $rounds_held = 0;
    foreach ( $rounds as $r ) {
        if ( $r['court_name'] !== $final_court ) {
            break;
        }
        $rounds_held++;
    }

    return array( 'rounds_held' => $rounds_held, 'won_final' => $won_final );
}

/**
 * Full sitewide re-rank for one format ('ace'/'queen') -- every player
 * who has EVER had an average in this format (not just today's
 * participants). Multi-level sort, see this file's own "TIE-BREAK
 * CHAIN" header note for the 4 levels in order. Writes the new
 * sequential position to spp_kq_{source}_rank usermeta for every
 * affected player via update_user_meta() (see file header's 1.1.1
 * cache-safety note).
 */
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

    $avg_by_uid = array();
    foreach ( $avg_rows as $r ) {
        $avg_by_uid[ (int) $r['user_id'] ] = (float) $r['meta_value'];
    }

    $rank_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, meta_value FROM {$usermeta} WHERE meta_key = %s", $rank_key
    ), ARRAY_A );
    $current_rank_by_uid = array();
    foreach ( $rank_rows as $r ) {
        $current_rank_by_uid[ (int) $r['user_id'] ] = (int) $r['meta_value'];
    }

    // Group by average (string-keyed to a fixed precision so float
    // representation noise can't split a genuine tie into two groups)
    // so tie-break data (a spp_kq_history lookup per player) is only
    // ever computed for players who are actually tied on it -- an
    // untied player never pays for data they don't need.
    $groups = array();
    foreach ( $avg_by_uid as $uid => $avg ) {
        $groups[ number_format( $avg, 6 ) ][] = $uid;
    }

    $tiebreak_by_uid = array();
    foreach ( $groups as $uids_in_group ) {
        if ( count( $uids_in_group ) < 2 ) {
            continue; // no tie at this average -- nothing to compute
        }
        foreach ( $uids_in_group as $uid ) {
            $tiebreak_by_uid[ $uid ] = spp_kq_get_tiebreak_data( $uid, $source );
        }
    }

    $entries = array();
    foreach ( $avg_by_uid as $uid => $avg ) {
        $entries[] = array(
            'user_id'      => $uid,
            'avg'          => $avg,
            'current_rank' => $current_rank_by_uid[ $uid ] ?? 9999,
        );
    }

    usort( $entries, function( $a, $b ) use ( $tiebreak_by_uid ) {
        // 1. Decayed average, descending -- the primary criterion.
        // Float subtraction avoided entirely (see rankUsersWithTies()'s
        // own comparator bug, inc/spp-create-results.php, worked around
        // rather than inherited here): a small epsilon treats anything
        // closer than that as a genuine tie, everything else compares
        // via <=> on a plain boolean-driven branch, never truncated.
        if ( abs( $a['avg'] - $b['avg'] ) > 0.0000001 ) {
            return $a['avg'] < $b['avg'] ? 1 : -1;
        }

        $a_tb = $tiebreak_by_uid[ $a['user_id'] ] ?? array( 'rounds_held' => 0, 'won_final' => false );
        $b_tb = $tiebreak_by_uid[ $b['user_id'] ] ?? array( 'rounds_held' => 0, 'won_final' => false );

        // 2. Consecutive rounds held at the final court, descending.
        if ( $a_tb['rounds_held'] !== $b_tb['rounds_held'] ) {
            return $b_tb['rounds_held'] <=> $a_tb['rounds_held'];
        }

        // 3. Won the final round beats lost the final round.
        if ( $a_tb['won_final'] !== $b_tb['won_final'] ) {
            return $a_tb['won_final'] ? -1 : 1;
        }

        // 4. The ladder's own previous-rank tie-break rule, reused
        // exactly (rankUsersWithTies(), inc/spp-create-results.php:
        // ascending current_rank, lower/better wins, 9999 default for
        // never-ranked) -- inlined rather than calling that function,
        // since a whole-list helper doesn't fit as one leg of a
        // per-pair comparator; plain integers here, so <=> is always
        // exact, no truncation risk.
        return $a['current_rank'] <=> $b['current_rank'];
    } );

    $rank = 0;
    foreach ( $entries as $entry ) {
        $rank++;
        update_user_meta( $entry['user_id'], $rank_key, $rank );
    }
}
