<?php
/* =========================================================
   Ace/Queen of the Courts — Live Event Runner
   Version: 1.0.0
   Date: 2026-09-11

   PURPOSE:
   The live, in-event round/court/score runner for Ace and Queen of
   the Courts occurrences (GL Events categories 2 and 3) -- built on
   top of gl-events' own registration/waitlist tables, which are
   reused as-is (nothing here duplicates or replaces them).

   Stage 2 of a staged build (see conversation). This file currently
   contains the ACCESS GATE, the atomic state-transition mechanics for
   spp_kq_events, and the card-draw claim logic -- deliberately not
   yet the screens/shortcode/AJAX wiring that render them, which is
   still pending review (see this stage's own plan, presented before
   this file). Stage 1 (inc/spp-kq-movement.php) and this file's own
   schema (inc/spp-kq-schema.php) are both already wired into
   functions.php; nothing in either of those needed to change to
   support this file except spp-kq-schema.php's 1.1.0 nullable-user_id
   adjustment (see that file's own changelog).

   ACCESS MODEL (confirmed final): the entire feature requires only
   is_user_logged_in() -- no registration check, no occurrence-
   membership check, anywhere in this feature. Any logged-in club
   member may facilitate an event (start the draw, tap "Start Play",
   help with scoring) even if they aren't personally registered or
   playing that day. spp_kq_can_facilitate() is the ONE gate function
   -- every entry point in this feature calls it and nothing else, so
   there is exactly one place this policy is ever expressed.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * The one and only access check for this entire feature. Deliberately
 * just is_user_logged_in() -- see this file's own docblock for why.
 */
function spp_kq_can_facilitate() : bool {
    return is_user_logged_in();
}

/**
 * Fixed top-to-bottom court hierarchy. Index 0 is always the top
 * court (Aces); active courts for a given event are always the first
 * N of this list, where N is decided once at round-1 start from
 * turnout and never changes for the rest of that event.
 */
function spp_kq_master_court_hierarchy() : array {
    return array( 'Aces', 'Kings', 'Queens', 'Jacks' );
}

/**
 * Ensure a spp_kq_events row exists for this occurrence, defaulting
 * to phase='not_started', current_round=0. Idempotent and race-safe
 * on its own: two near-simultaneous first-visits both attempting this
 * INSERT will have exactly one succeed; the PRIMARY KEY(occurrence_id)
 * constraint rejects the other, which this function treats as success
 * (the row exists either way, which is all this function promises).
 */
function spp_kq_ensure_event_row( int $occurrence_id ) : void {
    global $wpdb;
    $table = spp_kq_events_table();

    $wpdb->suppress_errors( true );
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (occurrence_id, current_round, phase) VALUES (%d, 0, 'not_started')",
        $occurrence_id
    ) );
    $wpdb->suppress_errors( false );
}

/**
 * Fetch the current phase/current_round for an occurrence. Returns
 * null if spp_kq_ensure_event_row() hasn't been called for it yet.
 */
function spp_kq_get_event_state( int $occurrence_id ) : ?array {
    global $wpdb;
    $table = spp_kq_events_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT current_round, phase FROM {$table} WHERE occurrence_id = %d",
        $occurrence_id
    ), ARRAY_A );
    return $row ?: null;
}

/**
 * The fixed court order for an event, top-to-bottom. Authoritative
 * source is round 1's own assignment rows (every court that was ever
 * dealt into round 1 is, by design, active for the whole event) --
 * falls back to deriving it from the confirmed-registrant count only
 * when round 1 hasn't been dealt yet (i.e. while deciding whether/how
 * to start it).
 */
function spp_kq_determine_courts_order( int $occurrence_id ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();
    $hierarchy = spp_kq_master_court_hierarchy();

    $existing = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT court_name FROM {$table} WHERE occurrence_id = %d AND round_number = 1",
        $occurrence_id
    ) );

    // Also derive from confirmed turnout -- cheap, and the only source
    // immune to Cancel Event's own deletions. Found by real end-to-end
    // testing (2026-09-11): Cancel Event can delete SOME of round 1's own
    // assignment rows (any court that hadn't reported), which silently
    // undercounts the round-1-derived path above on a same-round
    // cancellation -- e.g. 1 surviving court read back as "the whole
    // event was 1 court," losing the other 3 entirely. Both derivations
    // are always contiguous top-down prefixes of the same fixed
    // hierarchy, so whichever implies MORE courts is always a superset of
    // the other and safe to prefer -- they only disagree when rows were
    // deleted out from under the round-1-derived path, never when both
    // sources are simply reflecting real, unmodified data.
    $confirmed_count = spp_kq_confirmed_count( $occurrence_id );
    $from_count = array_slice( $hierarchy, 0, intdiv( $confirmed_count, 4 ) );

    if ( empty( $existing ) ) {
        return $from_count;
    }

    $from_assignments = array_values( array_filter( $hierarchy, fn( $c ) => in_array( $c, $existing, true ) ) );

    return ( count( $from_assignments ) >= count( $from_count ) ) ? $from_assignments : $from_count;
}

/**
 * Confirmed registrant count for an occurrence, via gl-events' own
 * GL_Registration class -- no reimplementation of registration status.
 */
function spp_kq_confirmed_count( int $occurrence_id ) : int {
    return count( GL_Registration::get_for_occurrence( $occurrence_id, 'confirmed' ) );
}

/**
 * Confirmed registrant user_ids for an occurrence.
 */
function spp_kq_confirmed_user_ids( int $occurrence_id ) : array {
    $regs = GL_Registration::get_for_occurrence( $occurrence_id, 'confirmed' );
    return array_map( fn( $r ) => (int) $r->user_id, $regs );
}

/**
 * One round's assignment rows, shaped for spp_kq_compute_next_round().
 * Excludes any still-unclaimed (NULL user_id) placeholder rows -- a
 * caller asking for a round's assignments should only ever do so once
 * that round is fully drawn/decided.
 */
function spp_kq_get_round_assignments( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, court_name, team_color FROM {$table}
         WHERE occurrence_id = %d AND round_number = %d AND user_id IS NOT NULL",
        $occurrence_id, $round_number
    ), ARRAY_A );
    return array_map( fn( $r ) => array(
        'user_id'    => (int) $r['user_id'],
        'court_name' => $r['court_name'],
        'team_color' => $r['team_color'],
    ), $rows );
}

/**
 * One round's score rows, shaped for spp_kq_compute_next_round().
 */
function spp_kq_get_round_scores( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_scores_table();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT court_name, red_score, black_score FROM {$table}
         WHERE occurrence_id = %d AND round_number = %d",
        $occurrence_id, $round_number
    ), ARRAY_A );
    return array_map( fn( $r ) => array(
        'court_name'  => $r['court_name'],
        'red_score'   => $r['red_score'] === null ? null : (int) $r['red_score'],
        'black_score' => $r['black_score'] === null ? null : (int) $r['black_score'],
    ), $rows );
}

/**
 * Partnership history spanning round 1 through $through_round
 * inclusive, shaped for spp_kq_build_pair_history() (via
 * spp_kq_compute_next_round()). Per that file's contract, this MUST
 * include the round being advanced FROM, not just prior rounds.
 */
function spp_kq_get_history_through_round( int $occurrence_id, int $through_round ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT round_number, court_name, team_color, user_id FROM {$table}
         WHERE occurrence_id = %d AND round_number <= %d AND user_id IS NOT NULL",
        $occurrence_id, $through_round
    ), ARRAY_A );
    return array_map( fn( $r ) => array(
        'round_number' => (int) $r['round_number'],
        'court_name'   => $r['court_name'],
        'team_color'   => $r['team_color'],
        'user_id'      => (int) $r['user_id'],
    ), $rows );
}

/**
 * Bulk-insert NULL-user_id placeholder rows for every (court, color)
 * slot of a round -- two per color per court. Shared by round 1's
 * start (Stage 2) and round-advance's next-round creation, EXCEPT
 * round-advance immediately overwrites every placeholder with a real
 * user_id from the movement algorithm's output in the same breath
 * (nothing but round 1 ever leaves a placeholder actually unclaimed).
 */
function spp_kq_create_assignment_placeholders( int $occurrence_id, int $round_number, array $courts ) : void {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $placeholders = array();
    $values = array();
    foreach ( $courts as $court ) {
        foreach ( array( 'red', 'red', 'black', 'black' ) as $color ) {
            $placeholders[] = '(%d, %d, NULL, %s, %s)';
            array_push( $values, $occurrence_id, $round_number, $court, $color );
        }
    }
    $sql = "INSERT INTO {$table} (occurrence_id, round_number, user_id, court_name, team_color) VALUES "
         . implode( ', ', $placeholders );
    $wpdb->query( $wpdb->prepare( $sql, $values ) );
}

/**
 * Create a round's spp_kq_scores placeholder rows (NULL scores, one
 * per active court) -- lets "has every court reported" be a plain
 * COUNT rather than needing to already know which courts exist.
 */
function spp_kq_create_score_placeholders( int $occurrence_id, int $round_number, array $courts ) : void {
    global $wpdb;
    $table = spp_kq_scores_table();

    $placeholders = array();
    $values = array();
    foreach ( $courts as $court ) {
        $placeholders[] = '(%d, %d, %s)';
        array_push( $values, $occurrence_id, $round_number, $court );
    }
    $sql = "INSERT INTO {$table} (occurrence_id, round_number, court_name) VALUES "
         . implode( ', ', $placeholders );
    $wpdb->query( $wpdb->prepare( $sql, $values ) );
}

// =============================================================
// Atomic state transitions -- one UPDATE ... WHERE per transition.
// Each returns ['won' => bool, 'error' => ?string]. 'won' => false
// with 'error' => null means "lost the race, no error -- someone
// else already did this; there is nothing left for you to do."
// 'won' => false with a non-null 'error' means the transition was
// never attempted at all because the input wasn't valid.
// =============================================================

/**
 * Start round 1's draw: not_started (round 0) -> organizing (round 1).
 * The winner decides the event's fixed court set for its entire
 * duration (from confirmed turnout) and materializes round 1's
 * placeholder slots.
 */
function spp_kq_transition_start_round1( int $occurrence_id ) : array {
    global $wpdb;

    spp_kq_ensure_event_row( $occurrence_id );

    $confirmed_count = spp_kq_confirmed_count( $occurrence_id );
    if ( $confirmed_count < 4 || $confirmed_count > 16 || $confirmed_count % 4 !== 0 ) {
        return array(
            'won'   => false,
            'error' => "Cannot start: {$confirmed_count} confirmed registrant(s) -- need a multiple of 4, between 4 and 16. Adjust the roster via Registration Admin first.",
        );
    }

    $events_table = spp_kq_events_table();
    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET current_round = 1, phase = 'organizing'
         WHERE occurrence_id = %d AND current_round = 0 AND phase = 'not_started'",
        $occurrence_id
    ) );

    if ( (int) $affected !== 1 ) {
        return array( 'won' => false, 'error' => null );
    }

    $n_courts = intdiv( $confirmed_count, 4 );
    $courts   = array_slice( spp_kq_master_court_hierarchy(), 0, $n_courts );

    spp_kq_create_assignment_placeholders( $occurrence_id, 1, $courts );
    spp_kq_create_score_placeholders( $occurrence_id, 1, $courts );

    return array( 'won' => true, 'error' => null, 'courts' => $courts );
}

/**
 * Start Play: organizing -> in_play, same round number. $expected_round
 * is the round the caller believes is current -- guards against
 * starting play on a round that has already moved on.
 */
function spp_kq_transition_start_play( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;
    $events_table = spp_kq_events_table();

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET phase = 'in_play'
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing'",
        $occurrence_id, $expected_round
    ) );

    return array( 'won' => ( (int) $affected === 1 ), 'error' => null );
}

/**
 * Advance to the next round: in_play -> organizing, current_round+1.
 * The next round's assignments are computed via spp_kq_compute_next_round()
 * BEFORE attempting the transition -- a tie or bad data is caught while
 * nothing has moved yet, never after the transition has already been
 * claimed with nothing to write for it.
 *
 * This is a separate decision from ending the event -- see
 * spp_kq_transition_end_event() below, a distinct transition OUT of
 * 'organizing' (same state this one always lands in), not something
 * this function decides on its own.
 */
function spp_kq_transition_advance_round( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;

    $courts      = spp_kq_determine_courts_order( $occurrence_id );
    $assignments = spp_kq_get_round_assignments( $occurrence_id, $expected_round );
    $scores      = spp_kq_get_round_scores( $occurrence_id, $expected_round );
    $history     = spp_kq_get_history_through_round( $occurrence_id, $expected_round );

    try {
        $next_assignments = spp_kq_compute_next_round( $courts, $assignments, $scores, $history );
    } catch ( SPP_KQ_Movement_Error $e ) {
        return array( 'won' => false, 'error' => $e->getMessage() );
    }

    $events_table = spp_kq_events_table();
    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET current_round = current_round + 1, phase = 'organizing'
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'in_play'",
        $occurrence_id, $expected_round
    ) );

    if ( (int) $affected !== 1 ) {
        return array( 'won' => false, 'error' => null );
    }

    $next_round_number = $expected_round + 1;

    $assignments_table = spp_kq_assignments_table();
    $placeholders = array();
    $values = array();
    foreach ( $next_assignments as $a ) {
        $placeholders[] = '(%d, %d, %d, %s, %s)';
        array_push( $values, $occurrence_id, $next_round_number, $a['user_id'], $a['court_name'], $a['team_color'] );
    }
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$assignments_table} (occurrence_id, round_number, user_id, court_name, team_color) VALUES "
        . implode( ', ', $placeholders ),
        $values
    ) );

    spp_kq_create_score_placeholders( $occurrence_id, $next_round_number, $courts );

    return array( 'won' => true, 'error' => null, 'round' => $next_round_number );
}

/**
 * End Event (normal close, between rounds): organizing -> complete.
 * Available from the exact same state as Start Play -- both are valid
 * transitions OUT of 'organizing' for the same $expected_round, aimed
 * at the same row, so if both fire at once the ordinary CAS mechanism
 * already guarantees exactly one of them succeeds with no new locking:
 * whichever UPDATE commits first changes `phase` away from
 * 'organizing', so the other's WHERE clause simply stops matching.
 *
 * No round-count/end-time check here, deliberately -- ending the
 * event is a facilitator's call to make whenever they choose, not a
 * condition this function detects on its own (see the Stage 2
 * conversation: nothing in the data model tracks a target round
 * count).
 */
function spp_kq_transition_end_event( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;
    $events_table = spp_kq_events_table();

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET phase = 'complete'
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing'",
        $occurrence_id, $expected_round
    ) );

    return array( 'won' => ( (int) $affected === 1 ), 'error' => null );
}

/**
 * Cancel Event (weather/emergency, mid-round): in_play -> cancelled.
 * 'cancelled' is distinct from 'complete' -- a cancelled day gets no
 * Aces-winner recognition. Per the confirmed rule: any court that had
 * ALREADY reported this round before cancellation fires keeps its
 * score (a real result, still feeds Club Ratings later); any court
 * that had NOT yet reported has its current round's data for that
 * court discarded outright -- both its spp_kq_scores row and its
 * spp_kq_assignments rows for this round are deleted, not just left
 * NULL, so nothing downstream can mistake an interrupted game for a
 * real one. Earlier rounds are never touched -- advancing already
 * required every court to have reported, so only the CURRENT round
 * can have anything left unreported.
 */
function spp_kq_transition_cancel_event( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;
    $events_table = spp_kq_events_table();

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET phase = 'cancelled'
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'in_play'",
        $occurrence_id, $expected_round
    ) );

    if ( (int) $affected !== 1 ) {
        return array( 'won' => false, 'error' => null );
    }

    $assignments_table = spp_kq_assignments_table();
    $scores_table      = spp_kq_scores_table();

    $unreported_courts = $wpdb->get_col( $wpdb->prepare(
        "SELECT court_name FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d
           AND ( red_score IS NULL OR black_score IS NULL )",
        $occurrence_id, $expected_round
    ) );

    foreach ( $unreported_courts as $court ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$assignments_table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
            $occurrence_id, $expected_round, $court
        ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$scores_table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
            $occurrence_id, $expected_round, $court
        ) );
    }

    return array( 'won' => true, 'error' => null, 'discarded_courts' => $unreported_courts );
}

/**
 * Whether ANY court, in ANY round, of this occurrence has ever had a
 * real score recorded (both red_score and black_score NOT NULL). The
 * one gate both End Event's availability and Reset Event's
 * availability key off -- "has anything real happened yet" is the
 * same question from opposite directions. Scans every round, not just
 * the current one: reaching round 2+ requires round 1 to have been
 * fully scored first, so in practice this can only be true once
 * something genuinely real has happened, regardless of which round is
 * current now.
 */
function spp_kq_has_any_recorded_score( int $occurrence_id ) : bool {
    global $wpdb;
    $t = spp_kq_scores_table();
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE occurrence_id = %d AND red_score IS NOT NULL AND black_score IS NOT NULL",
        $occurrence_id
    ) ) > 0;
}

/**
 * Reset Event: safe, any logged-in facilitator (spp_kq_can_facilitate()
 * -- the feature-wide gate -- is all that's required, checked once at
 * the shortcode dispatcher; nothing extra here). Only ever reachable
 * from two screens, each offering exactly one of these two behaviors
 * -- never a general "reset from wherever you are":
 *
 * - Draw screen (organizing, round 1, draw INCOMPLETE -- real unclaimed
 *   slots remain): full clear. Deletes this occurrence's assignments/
 *   scores and resets the events row to (round=0, phase='not_started').
 * - In-Play screen (in_play, nothing reported yet this round): undoes
 *   Start Play ONLY -- phase back to 'organizing', same round,
 *   assignments left completely untouched, so the Overview screen
 *   reappears showing the exact same drawn courts.
 *
 * Deliberately NOT offered once a round-1 draw is complete but Start
 * Play hasn't been tapped yet (the Overview screen, organizing, zero
 * scores) -- there is nothing to undo in that state, so no Reset
 * button is rendered there at all; see spp_kq_render_overview_screen().
 *
 * CONCURRENCY: the has-any-recorded-score check is folded directly
 * into the same atomic UPDATE's WHERE clause (a NOT EXISTS subquery),
 * not a separate pre-check -- a naive "check, then separately CAS"
 * has a real gap where a real score could commit in between, and this
 * transition would then silently discard it. A single UPDATE
 * statement's WHERE evaluation and write are atomic with respect to
 * concurrent statements, so this closes that gap entirely rather than
 * just narrowing it. Both branches are still additionally guarded by
 * the ordinary current_round/phase CAS every other transition uses.
 */
function spp_kq_transition_reset_event( int $occurrence_id, int $expected_round, string $current_phase ) : array {
    global $wpdb;
    $events_table = spp_kq_events_table();
    $scores_table = spp_kq_scores_table();

    $no_scores_yet_sql = "NOT EXISTS (
        SELECT 1 FROM {$scores_table} s
        WHERE s.occurrence_id = {$events_table}.occurrence_id
          AND s.red_score IS NOT NULL AND s.black_score IS NOT NULL
    )";

    if ( $current_phase === 'in_play' ) {
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$events_table}
             SET phase = 'organizing'
             WHERE occurrence_id = %d AND current_round = %d AND phase = 'in_play'
               AND {$no_scores_yet_sql}",
            $occurrence_id, $expected_round
        ) );
        return array( 'won' => ( (int) $affected === 1 ), 'error' => null );
    }

    if ( $current_phase === 'organizing' ) {
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$events_table}
             SET current_round = 0, phase = 'not_started'
             WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing'
               AND {$no_scores_yet_sql}",
            $occurrence_id, $expected_round
        ) );
        if ( (int) $affected !== 1 ) {
            return array( 'won' => false, 'error' => null );
        }
        // Only reachable here if the CAS above just confirmed zero scores
        // exist -- by construction (see docblock) this can only mean
        // round 1's own placeholder rows, nothing from any other round.
        $wpdb->delete( spp_kq_assignments_table(), array( 'occurrence_id' => $occurrence_id ) );
        $wpdb->delete( $scores_table, array( 'occurrence_id' => $occurrence_id ) );
        return array( 'won' => true, 'error' => null );
    }

    return array( 'won' => false, 'error' => 'Nothing to reset.' );
}

/**
 * Full Reset: administrator-only (enforced by the caller,
 * spp_is_admin(), checked before this is ever invoked -- this function
 * itself performs no capability check of its own, matching every other
 * transition function in this file, which all rely on their caller for
 * that). Deliberately more powerful and more dangerous than Reset
 * Event above: works from ANY phase, including complete/cancelled, and
 * unconditionally discards this occurrence's entire spp_kq_* state,
 * real recorded scores included. No CAS -- this is an intentional
 * unconditional wipe, not a state-machine transition guarding against
 * a stale click; the admin-only gate is the safety mechanism here, not
 * a compare-and-swap.
 */
function spp_kq_full_reset( int $occurrence_id ) : void {
    global $wpdb;
    $wpdb->delete( spp_kq_events_table(), array( 'occurrence_id' => $occurrence_id ) );
    $wpdb->delete( spp_kq_assignments_table(), array( 'occurrence_id' => $occurrence_id ) );
    $wpdb->delete( spp_kq_scores_table(), array( 'occurrence_id' => $occurrence_id ) );
}

// =============================================================
// Card draw (round 1 only)
// =============================================================

/**
 * Claim one random still-open (court, color) slot for $user_id in
 * round 1. Guards: $user_id must be a confirmed registrant not
 * already drawn. The actual claim is the same per-row compare-and-
 * swap the phase transitions use, retried against a different random
 * row on loss (see this feature's own schema changelog for why this
 * needed a nullable user_id rather than a table lock).
 */
function spp_kq_draw_card( int $occurrence_id, int $user_id ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    if ( ! in_array( $user_id, spp_kq_confirmed_user_ids( $occurrence_id ), true ) ) {
        return array( 'success' => false, 'error' => 'Not a confirmed registrant for this event.' );
    }

    $already = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = 1 AND user_id = %d",
        $occurrence_id, $user_id
    ) );
    if ( $already > 0 ) {
        return array( 'success' => false, 'error' => 'This player has already been drawn.' );
    }

    for ( $attempt = 0; $attempt < 5; $attempt++ ) {
        $candidate_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE occurrence_id = %d AND round_number = 1 AND user_id IS NULL
             ORDER BY RAND() LIMIT 1",
            $occurrence_id
        ) );

        if ( ! $candidate_id ) {
            return array( 'success' => false, 'error' => 'No open slots remain -- the draw may already be complete.' );
        }

        // A losing UPDATE here (0 rows) is an expected, routine outcome of
        // contention, not a bug -- suppress so it never lands in error logs.
        $wpdb->suppress_errors( true );
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET user_id = %d WHERE id = %d AND user_id IS NULL",
            $user_id, $candidate_id
        ) );
        $db_error = $wpdb->last_error;
        $wpdb->suppress_errors( false );

        if ( (int) $affected === 1 ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT court_name, team_color FROM {$table} WHERE id = %d",
                $candidate_id
            ), ARRAY_A );
            $remaining = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = 1 AND user_id IS NULL",
                $occurrence_id
            ) );
            return array(
                'success'       => true,
                'court_name'    => $row['court_name'],
                'team_color'    => $row['team_color'],
                'draw_complete' => ( $remaining === 0 ),
            );
        }

        if ( $db_error && ! str_contains( $db_error, 'Duplicate entry' ) ) {
            // A real, unexpected DB error -- surface it rather than retrying blindly.
            return array( 'success' => false, 'error' => 'Database error -- please try again.' );
        }

        // Lost the race for this specific slot (someone else claimed the same
        // row, or -- caught by UNIQUE(occurrence_id,round_number,user_id) --
        // this exact user_id was claimed a moment earlier by a duplicate tap).
        // Try a different random remaining slot.
        usleep( random_int( 5000, 20000 ) );
    }

    return array( 'success' => false, 'error' => 'Could not claim a slot after several attempts -- please try again.' );
}

// =============================================================
// Score entry (Stage 3). Real, informed access-model change (2026-09,
// based on Gaetan's own hands-on testing): this used to require a real
// spp_kq_assignments row for the specific occurrence/round/user_id --
// a SECOND, narrower gate on top of spp_kq_can_facilitate() -- and it
// turned out to block legitimate facilitation (testing solo, or
// helping run an event, means touching scores for courts you are not
// personally playing on). Removed entirely: viewing and submitting ANY
// court's score for the current round now uses the exact same
// feature-wide gate as every other action here (the draw, Start Play,
// End Event, Cancel Event, Reset) -- any logged-in user, full stop.
// One access model for the whole feature, not one carved-out exception.
//
// The client still never gets to invent a court out of thin air, just
// on different terms: court_name is now a real POST value (previously
// there was nothing to send -- the court was derived from the caller's
// own assignment), so spp_kq_submit_court_score() validates it against
// a real spp_kq_scores placeholder row for this occurrence/round
// rather than against who the caller happens to be.
// =============================================================

/**
 * How many of this round's courts have a fully-reported score vs. the
 * total -- the live "N of M courts reported" figure shown on the
 * in-play screen and used to decide whether to attempt a round
 * advance. Shared by the render path, the poll endpoint, and the
 * submit handler so all three always agree.
 */
function spp_kq_get_round_progress( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_scores_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM( CASE WHEN red_score IS NOT NULL AND black_score IS NOT NULL THEN 1 ELSE 0 END ) AS reported
         FROM {$table} WHERE occurrence_id = %d AND round_number = %d",
        $occurrence_id, $round_number
    ), ARRAY_A );
    return array( 'reported' => (int) ( $row['reported'] ?? 0 ), 'total' => (int) ( $row['total'] ?? 0 ) );
}

/**
 * Submit (or correct -- always an overwrite, "most recent entry wins"
 * exactly as spp-score-entry.php's own ladder version) one court's
 * score for the round the caller believes is current. Re-derives
 * everything server-side rather than trusting the caller:
 *  - phase must still be 'in_play' AND current_round must still equal
 *    $round_number (the round may have already advanced between page
 *    load and submit -- rejected, not silently misapplied).
 *  - $court_name must be a real court that genuinely exists for this
 *    occurrence/round -- checked against a real spp_kq_scores
 *    placeholder row, never taken on faith. Unlike the assignment
 *    check this replaces, this is NOT who-are-you gated: any logged-in
 *    facilitator may submit for any real court (see this section's own
 *    header for why).
 *  - scores must be 0-11 (games are played to 11) and not equal -- a
 *    tie is a data-entry error to correct, never guessed at or
 *    silently resolved (games are extended by a point specifically so
 *    a real tie should not occur); 11-11 specifically gets its own
 *    message, since it's a logical impossibility, not just a tie.
 *
 * After a successful write, checks whether every court in this round
 * has now reported and, if so, attempts spp_kq_transition_advance_round()
 * (Stage 2, unchanged) -- safe under real concurrency because that
 * function's own CAS is what actually decides the single winner; this
 * function's own "should I even try" check just decides who ATTEMPTS,
 * not who succeeds, and multiple simultaneous attempts are exactly
 * what that CAS already handles (see Stage 2's own concurrency test,
 * and this stage's own version of the same test against the real
 * submit path).
 */
function spp_kq_submit_court_score( int $occurrence_id, int $round_number, string $court_name, int $red_score, int $black_score, int $user_id ) : array {
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state || $state['phase'] !== 'in_play' || (int) $state['current_round'] !== $round_number ) {
        return array( 'success' => false, 'error' => 'This round is no longer accepting scores -- refresh to see the current state.' );
    }

    global $wpdb;
    $scores_table = spp_kq_scores_table();

    $court_is_real = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$scores_table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $occurrence_id, $round_number, $court_name
    ) );
    if ( ! $court_is_real ) {
        return array( 'success' => false, 'error' => 'Not a valid court for this round -- refresh to see the current state.' );
    }

    if ( $red_score < 0 || $red_score > 11 || $black_score < 0 || $black_score > 11 ) {
        return array( 'success' => false, 'error' => 'Scores must be between 0 and 11 -- games are played to 11.' );
    }
    // Checked before the generic tie rejection below: 11-11 specifically
    // is a logical impossibility (the game ends the instant either team
    // reaches 11 -- both sides can never simultaneously be at 11), not
    // just an ordinary tie, and it's an easy digit-repeated data-entry
    // mistake -- worth its own clearer message.
    if ( $red_score === 11 && $black_score === 11 ) {
        return array( 'success' => false, 'error' => "11-11 isn't possible -- the game ends the instant either team reaches 11. Please double-check before saving." );
    }
    if ( $red_score === $black_score ) {
        return array( 'success' => false, 'error' => "Scores can't be tied -- games are extended by a point specifically to avoid this. Please check and resubmit." );
    }

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$scores_table} SET red_score = %d, black_score = %d, updated_by = %d
         WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $red_score, $black_score, $user_id, $occurrence_id, $round_number, $court_name
    ) );

    $progress = spp_kq_get_round_progress( $occurrence_id, $round_number );
    $advanced = false;

    if ( $progress['total'] > 0 && $progress['reported'] === $progress['total'] ) {
        $advance = spp_kq_transition_advance_round( $occurrence_id, $round_number );
        $advanced = $advance['won']; // false just means someone else's simultaneous attempt already won it -- not an error.
    }

    return array(
        'success'    => true,
        'court_name' => $court_name,
        'reported'   => $progress['reported'],
        'total'      => $progress['total'],
        'advanced'   => $advanced,
    );
}
