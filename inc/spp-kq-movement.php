<?php
/* =========================================================
   Ace/Queen of the Courts — Movement Algorithm
   Version: 1.0.0
   Date: 2026-09-11

   PURPOSE:
   Pure, standalone, unit-testable PHP -- no $wpdb, no WordPress
   functions, no side effects. Given one round's court/team
   assignments and that round's scores, computes the FOLLOWING
   round's assignments. This file has zero dependencies on the rest
   of the theme; see tests/spp-kq-movement-test.php for a runnable
   test harness that requires nothing but plain `php`.

   Stage 1 of a staged build (see conversation) -- schema +
   this algorithm only. Not yet wired into functions.php: nothing
   calls this file's functions yet. Stage 2 will add the atomic
   state-transition code that reads spp_kq_assignments/spp_kq_scores,
   calls spp_kq_compute_next_round(), and writes the result back as
   the next round's spp_kq_assignments rows.

   THE RULE BEING IMPLEMENTED (from the spec):
   Courts are ordered top-to-bottom, e.g. ['Aces','Kings','Queens',
   'Jacks'] -- index 0 is always the top court (Aces), the last index
   is always the bottom court for however many courts are active this
   event. For each court: the winning pair moves up one court, the
   losing pair moves down one court -- EXCEPT winners on the top
   court stay put (nothing above Aces to move into), and losers on
   the bottom court stay put (nothing below the bottom court to move
   into). Expressed as index arithmetic this needs no special-casing
   at all: winner_dest = max(i-1, 0), loser_dest = min(i+1, n-1) --
   both formulas already degenerate into "stay" at the two boundaries
   on their own.

   Each destination court receives exactly two incoming pairs (four
   players) and is re-split into fresh red/black teams, preferring
   whichever of the three possible splits of four people into two
   pairs has gone the LONGEST without repeating (or has never
   occurred at all, this event) -- "avoiding a repeat partnership
   unless unavoidable". The three-splits-of-four fact is what makes
   this tractable: with four people A/B/C/D there are only ever
   {AB,CD}, {AC,BD}, {AD,BC}, so "avoid a repeat" is a 3-way choice,
   not a search.

   PARTNERSHIP HISTORY CONTRACT: $history must contain every
   spp_kq_assignments row for this occurrence_id from round 1 through
   the CURRENT round (the round $assignments/$scores describe)
   INCLUSIVE -- not just prior rounds. The current round's own
   pairings must be counted as "already used today" too, or the
   algorithm could recreate the exact pairing that just played
   without recognizing it as a repeat. In practice this is one query:
   `WHERE occurrence_id = ? AND round_number <= ?` (? = the round
   number $assignments/$scores describe).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown for any input that isn't a clean, complete, decisive round --
 * a missing/incomplete score, a tied score (movement requires a
 * decisive winner; this codebase has no rule yet for resolving a tie,
 * so one is surfaced as an error rather than guessed at), a court
 * that isn't a clean 2v2, a duplicate user_id, or an unknown court
 * name. Callers (Stage 2) are expected to catch this and show the
 * convenor/players what's wrong rather than silently advancing on
 * bad data.
 */
class SPP_KQ_Movement_Error extends Exception {}

/**
 * Canonical, order-independent key for a pair of user_ids.
 */
function spp_kq_pair_key( int $a, int $b ) : string {
    if ( $a === $b ) {
        throw new SPP_KQ_Movement_Error( "Cannot form a pair from user {$a} and themself." );
    }
    return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
}

/**
 * Build a lookup of pair_key => most recent round_number that pair
 * appeared together as teammates, from a flat list of assignment
 * rows spanning any number of rounds. Rows are grouped by
 * (round_number, court_name, team_color); a group that doesn't
 * resolve to exactly two user_ids is ignored rather than fatal --
 * defensive only, real data from spp_kq_assignments is validated at
 * write time, not expected to be malformed here.
 *
 * @param array $history Rows shaped like
 *              ['round_number'=>int,'court_name'=>string,
 *               'team_color'=>'red'|'black','user_id'=>int].
 * @return array pair_key => int last round seen.
 */
function spp_kq_build_pair_history( array $history ) : array {
    $grouped = array();
    foreach ( $history as $row ) {
        $group_key = $row['round_number'] . '|' . $row['court_name'] . '|' . $row['team_color'];
        $grouped[ $group_key ][] = (int) $row['user_id'];
    }

    $pair_last_round = array();
    foreach ( $grouped as $group_key => $uids ) {
        if ( count( $uids ) !== 2 ) {
            continue; // Not a complete team -- ignore defensively.
        }
        list( $round_number ) = explode( '|', $group_key, 2 );
        $round_number = (int) $round_number;
        $key = spp_kq_pair_key( $uids[0], $uids[1] );
        if ( ! isset( $pair_last_round[ $key ] ) || $round_number > $pair_last_round[ $key ] ) {
            $pair_last_round[ $key ] = $round_number;
        }
    }
    return $pair_last_round;
}

/**
 * Given two incoming pairs (four distinct user_ids arriving at the
 * same destination court), choose how to re-split them into red vs
 * black. Preference order:
 *   1. A split where NEITHER resulting pair has ever partnered
 *      before today.
 *   2. If more than one such split exists, no further preference is
 *      needed -- both are equally "never repeated" -- but ties are
 *      still broken deterministically (see below) so the function is
 *      pure and reproducible.
 *   3. If every split repeats something, pick the split whose worst
 *      (most recent) repeat is furthest in the past -- the "least
     bad" unavoidable repeat.
 * Ties are broken by candidate order, and candidate order
 * deliberately tries the two CROSS splits (which break up both
 * incoming pairs) before the split that would just keep both incoming
 * pairs exactly as they were -- that split is guaranteed to already
 * be "seen" (it's literally this round's own pairing) whenever real
 * history is passed in per this file's contract, so it only ever wins
 * when it's the only option left.
 *
 * @param array $pair1 [uidA, uidB]
 * @param array $pair2 [uidC, uidD]
 * @param array $pair_last_round From spp_kq_build_pair_history().
 * @return array [ [red_uid, red_uid], [black_uid, black_uid] ]
 */
function spp_kq_choose_split( array $pair1, array $pair2, array $pair_last_round ) : array {
    list( $a, $b ) = $pair1;
    list( $c, $d ) = $pair2;

    $candidates = array(
        array( array( $a, $c ), array( $b, $d ) ), // cross split 1
        array( array( $a, $d ), array( $b, $c ) ), // cross split 2
        array( array( $a, $b ), array( $c, $d ) ), // same as incoming -- last resort
    );

    $best = null;
    $best_cost = null;

    foreach ( $candidates as $candidate ) {
        $cost = -1; // -1 = "never seen before" -- the best possible cost.
        foreach ( $candidate as $pair ) {
            $key = spp_kq_pair_key( $pair[0], $pair[1] );
            $last_seen = $pair_last_round[ $key ] ?? -1;
            $cost = max( $cost, $last_seen );
        }
        if ( $best === null || $cost < $best_cost ) {
            $best = $candidate;
            $best_cost = $cost;
        }
    }

    return $best;
}

/**
 * Validate one round's assignments: every court in $courts_order must
 * have exactly four players, split 2 red / 2 black, no user_id
 * repeated on a court or across courts. Returns the assignments
 * indexed by court name for convenience. Throws on anything else.
 */
function spp_kq_validate_round_assignments( array $courts_order, array $assignments ) : array {
    if ( empty( $courts_order ) ) {
        throw new SPP_KQ_Movement_Error( 'courts_order cannot be empty.' );
    }
    if ( count( $courts_order ) !== count( array_unique( $courts_order ) ) ) {
        throw new SPP_KQ_Movement_Error( 'courts_order contains a duplicate court name.' );
    }

    $by_court = array_fill_keys( $courts_order, array() );
    $seen_uid_anywhere = array();

    foreach ( $assignments as $a ) {
        $court = $a['court_name'];
        $uid   = (int) $a['user_id'];
        if ( ! isset( $by_court[ $court ] ) ) {
            throw new SPP_KQ_Movement_Error( "Assignment references unknown court '{$court}'." );
        }
        if ( isset( $seen_uid_anywhere[ $uid ] ) ) {
            throw new SPP_KQ_Movement_Error( "User {$uid} appears more than once in this round's assignments." );
        }
        $seen_uid_anywhere[ $uid ] = true;
        $by_court[ $court ][] = $a;
    }

    foreach ( $courts_order as $court ) {
        $players = $by_court[ $court ];
        if ( count( $players ) !== 4 ) {
            throw new SPP_KQ_Movement_Error( "Court '{$court}' has " . count( $players ) . " player(s), expected 4." );
        }
        $red = array(); $black = array();
        foreach ( $players as $p ) {
            if ( $p['team_color'] === 'red' ) {
                $red[] = (int) $p['user_id'];
            } elseif ( $p['team_color'] === 'black' ) {
                $black[] = (int) $p['user_id'];
            } else {
                throw new SPP_KQ_Movement_Error( "Invalid team_color '{$p['team_color']}' for user {$p['user_id']} on court '{$court}'." );
            }
        }
        if ( count( $red ) !== 2 || count( $black ) !== 2 ) {
            throw new SPP_KQ_Movement_Error( "Court '{$court}' is not a clean 2v2 (red=" . count( $red ) . ", black=" . count( $black ) . ")." );
        }
    }

    return $by_court;
}

/**
 * The main entry point: given one round's assignments and scores
 * (plus the partnership history needed for repeat-avoidance), compute
 * the next round's assignments.
 *
 * @param array $courts_order  Ordered top-to-bottom, e.g.
 *                              ['Aces','Kings','Queens','Jacks'].
 *                              Fixed for the whole event -- courts
 *                              are decided once at round 1 and never
 *                              added/removed mid-event.
 * @param array $assignments   This round's rows:
 *                              ['user_id'=>int,'court_name'=>string,
 *                               'team_color'=>'red'|'black'].
 * @param array $scores        This round's rows:
 *                              ['court_name'=>string,
 *                               'red_score'=>int,'black_score'=>int].
 * @param array $history       See this file's docblock -- must
 *                              include rounds 1..current inclusive.
 * @return array Next round's rows, same shape as $assignments.
 * @throws SPP_KQ_Movement_Error
 */
function spp_kq_compute_next_round( array $courts_order, array $assignments, array $scores, array $history = array() ) : array {
    $by_court = spp_kq_validate_round_assignments( $courts_order, $assignments );

    $score_by_court = array();
    foreach ( $scores as $s ) {
        $score_by_court[ $s['court_name'] ] = $s;
    }

    $winner_pair = array();
    $loser_pair  = array();

    foreach ( $courts_order as $court ) {
        if ( ! isset( $score_by_court[ $court ] ) ) {
            throw new SPP_KQ_Movement_Error( "No score recorded for court '{$court}'." );
        }
        $s = $score_by_court[ $court ];
        if ( ! isset( $s['red_score'], $s['black_score'] ) || $s['red_score'] === null || $s['black_score'] === null ) {
            throw new SPP_KQ_Movement_Error( "Score for court '{$court}' is incomplete." );
        }
        $red_score   = (int) $s['red_score'];
        $black_score = (int) $s['black_score'];
        if ( $red_score === $black_score ) {
            throw new SPP_KQ_Movement_Error( "Court '{$court}' is tied {$red_score}-{$black_score} -- movement requires a decisive winner." );
        }

        $red_uids = array(); $black_uids = array();
        foreach ( $by_court[ $court ] as $p ) {
            if ( $p['team_color'] === 'red' ) {
                $red_uids[] = (int) $p['user_id'];
            } else {
                $black_uids[] = (int) $p['user_id'];
            }
        }

        if ( $red_score > $black_score ) {
            $winner_pair[ $court ] = $red_uids;
            $loser_pair[ $court ]  = $black_uids;
        } else {
            $winner_pair[ $court ] = $black_uids;
            $loser_pair[ $court ]  = $red_uids;
        }
    }

    // -- Movement: winner_dest = max(i-1, 0), loser_dest = min(i+1, n-1) --
    $n = count( $courts_order );
    $incoming = array_fill_keys( $courts_order, array() );

    foreach ( $courts_order as $i => $court ) {
        $win_dest_i  = max( $i - 1, 0 );
        $lose_dest_i = min( $i + 1, $n - 1 );
        $incoming[ $courts_order[ $win_dest_i ] ][]  = $winner_pair[ $court ];
        $incoming[ $courts_order[ $lose_dest_i ] ][] = $loser_pair[ $court ];
    }

    $pair_last_round = spp_kq_build_pair_history( $history );

    $next_round = array();
    foreach ( $courts_order as $court ) {
        $pairs = $incoming[ $court ];
        if ( count( $pairs ) !== 2 ) {
            // Should be unreachable given the movement math above --
            // a genuine internal-consistency error, not bad input.
            throw new SPP_KQ_Movement_Error( "Internal error: court '{$court}' received " . count( $pairs ) . " incoming pair(s), expected 2." );
        }

        list( $red_pair, $black_pair ) = spp_kq_choose_split( $pairs[0], $pairs[1], $pair_last_round );

        foreach ( $red_pair as $uid ) {
            $next_round[] = array( 'user_id' => $uid, 'court_name' => $court, 'team_color' => 'red' );
        }
        foreach ( $black_pair as $uid ) {
            $next_round[] = array( 'user_id' => $uid, 'court_name' => $court, 'team_color' => 'black' );
        }
    }

    return $next_round;
}
