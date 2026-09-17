<?php
/* =========================================================
   Ace/Queen of the Courts — Live Event Runner
   Version: 1.8.0
   Date: 2026-09-17

   Changes from 1.7.0: new spp_kq_court_value() -- a court's fixed point
   value (Aces=4 down to Jacks=1) for the new format-ranking system
   (inc/spp-kq-format-ranking.php), derived from spp_kq_master_court_
   hierarchy() so it can never drift out of sync with the actual court
   rank order. Pure lookup, no other behavior change.

   Changes from 1.6.0 (fix Full Scoreboard / Event Detail court display
   order): new spp_kq_order_courts_for_display() -- re-keys a court-
   named array into spp_kq_master_court_hierarchy()'s fixed order
   (Aces, Kings, Queens, Jacks), for DISPLAY only. Used by inc/spp-kq-
   history.php's spp_kq_get_full_scoreboard()/spp_kq_get_history_
   scoreboard(), which previously built their court-keyed arrays purely
   from SQL row order ("ORDER BY court_name ASC", i.e. alphabetical:
   Aces, Jacks, Kings, Queens) -- unlike spp_kq_get_round_court_view()
   (inc/spp-kq-screens.php), which already pre-seeded its keys from
   spp_kq_determine_courts_order() and was always correct. Movement/CAS/
   scoring untouched -- spp_kq_determine_courts_order() and
   spp_kq_master_court_hierarchy() itself, the actual source of court
   RANK for winner-moves-up/loser-moves-down, are unchanged; this new
   function is never called from anywhere in inc/spp-kq-movement.php or
   the transition/CAS code in this file.

   Changes from 1.5.0 ("Complete Allocation Randomly" at the round-1
   draw -- see the conversation this was built from for the full spec):
   - New spp_kq_complete_draw_randomly(): a facilitator shortcut that
     fills every still-open round-1 slot with whoever hasn't yet
     claimed one, randomly. INVESTIGATED first, not assumed: the real
     manual-claim mechanism is spp_kq_draw_card() above (wp_ajax_spp_
     kq_draw_card, inc/spp-kq-screens.php) -- one atomic RAND()-picked-
     candidate/CAS-UPDATE/retry-on-contention claim per call, already
     safe to call repeatedly and already guarding against a player who
     already has a slot. This new function is deliberately NOTHING
     MORE than a loop calling that exact same function once per not-
     yet-drawn player (spp_kq_get_not_yet_drawn(), inc/spp-kq-
     screens.php) -- no new concurrency mechanism, no second write
     path, and therefore no way for it to produce an assignment row
     that a manual tap couldn't also have produced. Zero interaction
     with the movement algorithm confirmed by inspection: spp_kq_
     compute_next_round() (inc/spp-kq-movement.php) only ever reads
     finished round assignment rows, with no concept of "how" a slot
     got filled.

   Changes from 1.4.0 (pre-round announcement flow, and a revision of
   this same day's earlier delayed-start timing -- see the conversation
   this was built from for the full spec; the screens/JS live in
   inc/spp-kq-screens.php, see that file's own changelog):

   TIMING REVISION: SPP_KQ_ROUND_START_DELAY_SECONDS drops from 30 to
   10, and its MEANING shifts -- this morning's version announced
   "Start play now" AT round_started_at (the end of a 30-second silent
   delay); today's revision announces it IMMEDIATELY at the Start Play
   press, with round_started_at simply 10 seconds later. The constant's
   own contract (round_started_at = press instant + this many seconds)
   is unchanged -- only its value and which client-side moment the
   announcement itself is tied to (a spp-kq-screens.php-side change,
   not this constant's concern).

   PRE-ROUND ANNOUNCEMENT FLOW (new): confirmed by re-reading the
   dispatcher (spp_kq_live_shortcode(), inc/spp-kq-screens.php) before
   building anything -- both Round 1 (draw complete, unclaimed = 0) and
   every Round 2+ (spp_kq_transition_advance_round() below, always
   landing on phase='organizing') currently fall through to the exact
   SAME spp_kq_render_overview_screen() with no intermediate step at
   all. New courts_announced_at (schema 1.7.0) is what the new
   intermediate screen keys off, in ONE unified way for both flows:
     - Round 1: stays NULL from the fresh draw; a facilitator presses
       "Ready -- Announce Courts", which calls the new
       spp_kq_transition_announce_courts() below (plain CAS: WHERE
       phase='organizing' AND current_round=expected AND
       courts_announced_at IS NULL) to stamp it to the press instant.
     - Round 2+: spp_kq_transition_advance_round() now ALSO stamps
       courts_announced_at, unconditionally, to current instant +
       SPP_KQ_COURTS_REST_SECONDS (120), in the SAME atomic UPDATE that
       advances current_round/phase -- no separate write, and this
       value is never NULL for round 2+, so the screen-selection logic
       in inc/spp-kq-screens.php never has to ask "which round is
       this" -- only "is courts_announced_at null or set" matters,
       which is exactly the "reuse one implementation for both flows"
       the spec asked for.
   Neither change touches spp_kq_submit_court_score()'s submission-
   order/round-still-current guard (1.2.0) or spp_kq_transition_
   advance_round()'s own CAS/movement-computation logic AT ALL beyond
   adding one more column to its existing SET clause -- confirmed by
   reading that function in full before editing it: the movement
   computation, cancelled-court handling, and the CAS's WHERE clause
   are byte-for-byte unchanged.

   Changes from 1.3.0 (30-second delayed start -- see the conversation
   this was built from for the full spec; the "Starting in..."
   countdown UI/JS and the "Start play now." announcement live in
   inc/spp-kq-screens.php, see that file's own changelog):
   - New SPP_KQ_ROUND_START_DELAY_SECONDS constant (30). spp_kq_
     transition_start_play() now stamps round_started_at as
     current_time('timestamp', true) + SPP_KQ_ROUND_START_DELAY_SECONDS
     instead of the bare current-instant -- round_started_at's MEANING
     is unchanged ("the instant the official round timer begins
     counting down from round_duration_seconds"), only ITS VALUE moves
     30 seconds later than the Start Play button press. Nothing else
     about this function changes: phase still flips organizing->in_play
     at the exact instant of the button press (score entry, polling,
     round-advance-on-all-reported are all unaffected and start working
     immediately, same as before) -- only the STORED start instant used
     by the timer/announcement math is delayed. This deliberately avoids
     a new phase or a second timestamp column: every existing reader of
     round_started_at (the in-play screen's client-side countdown) is
     already anchored to "current server time vs. this absolute epoch"
     -- a client simply now sometimes finds itself BEFORE that epoch
     (during the 30-second window) instead of always after it, and
     renders the pre-start "Starting in..." state for exactly that
     window with no new data plumbing required.

   Changes from 1.2.0 (match timer with voice announcements -- see the
   conversation this was built from for the full spec; the timer UI/JS
   and the duration input live in inc/spp-kq-screens.php, see that
   file's own changelog):
   - spp_kq_transition_start_play() now takes a third parameter,
     $duration_seconds, and folds round_duration_seconds/
     round_started_at into the SAME atomic organizing->in_play UPDATE
     (schema 1.6.0, inc/spp-kq-schema.php) -- both are written exactly
     once per round, at the exact instant the transition actually wins
     the compare-and-swap, so a duplicate/rejected Start Play attempt
     (round already started by another near-simultaneous request)
     never overwrites an already-running round's timer. round_started_at
     is current_time('timestamp', true) -- a true Unix/GMT epoch, NOT
     the local-wall-clock-as-UTC domain this file's own date gates use
     elsewhere -- see schema 1.6.0's own changelog for why that
     distinction matters here specifically (every consumer of this
     value is JS Date.now() math on the client, which is always a real
     UTC epoch).
   - spp_kq_get_event_state() now also selects round_duration_seconds/
     round_started_at, additively -- every existing caller destructures
     only the keys it already used ('phase'/'current_round'), so this
     is a no-op for all of them; the in-play screen render path is the
     only new consumer.

   Changes from 1.1.0 (race-condition fix -- audited and confirmed via
   live reproduction against a synthetic occurrence on production, same
   session that also fixed the analogous bug in the ladder's
   inc/spp-score-entry.php; that audit's own writeup has the full
   before-state):
   - THE BUG: spp_kq_submit_court_score()'s score write was a plain
     UPDATE with no ordering guard -- "most recent entry wins" was the
     documented intent (same as the ladder), but nothing enforced it.
     Reproduced concretely: a slow correction to an already-reported
     court (Aces, wrong score on record) raced a fast submission to a
     DIFFERENT court (Kings) that happened to be the round's last one
     to report. Kings' submission saw "all courts reported," computed
     movement from Aces' still-stale score, and committed the round
     advance BEFORE the Aces correction's UPDATE landed. The correction
     then landed anyway (nothing stopped it), leaving Aces' SCORE
     looking completely correct while the movement -- who actually
     plays where next round -- was silently computed from the wrong
     value, with both requests reporting success and no way to detect
     the mismatch afterward. Worse than the ladder bug: there the wrong
     VALUE could persist; here the value can end up right while a
     downstream, non-recomputable decision (movement) is wrong.
   - THE FIX has two parts, both folded into the SAME single atomic
     UPDATE statement (never a separate pre-check that could itself
     race -- same discipline spp_kq_transition_reset_event()'s own
     NOT-EXISTS-in-WHERE guard already established in this codebase):
       1. Submission-order guard: the score UPDATE's WHERE clause now
          requires `client_ts IS NULL OR client_ts < <incoming>` on the
          spp_kq_scores row itself (new column, schema 1.5.0) -- a
          write whose client_ts is older than one already accepted for
          that exact court+round is rejected outright, exactly the
          ladder fix's client_ts pattern, just stamped on KQ's own
          existing per-court row instead of a separate transient (KQ
          already has one row per occurrence+round+court to stamp;
          the ladder didn't have an equivalent single row for a round,
          which is why that fix used a transient instead).
       2. Round-still-current guard: the SAME UPDATE's WHERE clause
          also requires `EXISTS (... spp_kq_events WHERE current_round
          = this round AND phase = 'in_play' ...)`, evaluated at the
          instant of the write, not read-then-trusted from the
          function's earlier precondition check. This is what actually
          closes the race above: if the round has ALREADY advanced past
          this score's round_number by the time this UPDATE executes
          -- regardless of why it took so long to get here -- the write
          is rejected, atomically, every time. A too-late correction
          now fails LOUDLY ("this round is no longer accepting scores")
          instead of silently succeeding into a round whose movement
          already happened without it. This is a deliberate choice not
          to attempt an automatic movement redo (far riskier, no
          existing mechanism for it) -- once a round has advanced, nothing
          can retroactively fix its movement, so the correct behavior is
          to refuse and surface that plainly, not to pretend it worked.
     Net effect: spp_kq_transition_advance_round() itself needed NO
     internal change -- by the time it reads spp_kq_scores to compute
     movement, every value it sees is, by construction, one that can
     never again be silently superseded once the round moves past it.
   - A superseded write (either reason) still returns success:true
     (nothing is actually broken from the caller's point of view for
     the ordering-guard case) but skips the write and returns the
     court's true current score + progress, mirroring the ladder fix's
     `applied:false` convention -- so a stale client's UI can't paint
     stale data either.
   - Normal, non-racing single-submission scoring is unaffected: with
     no prior client_ts on the row (NULL) and the round genuinely still
     current, both new WHERE conditions are trivially true and the
     UPDATE proceeds exactly as before.

   Changes from 1.0.1 (mid-event roster swap + court cancellation --
   see the conversation this was built from for the full spec, and
   this file's own function docblocks below for each piece):
   - spp_kq_swap_player()/spp_kq_fill_open_slot(): a 1-for-1 substitute
     for the CURRENT round only, targeting a real spp_kq_assignments
     row (occupied or an open placeholder) -- never re-runs
     spp_kq_compute_next_round() or touches any other round. Both
     available in 'organizing' (between rounds, before Start Play) AND
     'in_play' (mid-round), the exact same mechanic either way -- see
     this file's own "between rounds" finding: round 2+'s assignments
     are already written synchronously the instant the prior round's
     last score lands (spp_kq_transition_advance_round() below), so
     there is no separate "decided but not yet drawn" state to
     intercept; a between-round swap edits the same already-written
     row a mid-round swap does, just before Start Play instead of
     before that court's score.
   - spp_kq_cancel_court(): flips spp_kq_scores.cancelled for one
     court/round (schema 1.4.0). No assignment rows touched -- the
     court's nominal players stay on record, simply never scored.
   - spp_kq_transition_advance_round() now excludes any court
     cancelled THIS round from the movement computation entirely
     (filtered out of $courts/$assignments/$scores/$history before
     spp_kq_compute_next_round() -- that pure algorithm itself is
     UNCHANGED, unaware cancellation exists at all). The surviving
     courts' win/loss destinations naturally contract around the gap
     (max(i-1,0)/min(i+1,n-1) on the REDUCED list), no special-casing
     needed. The cancelled court's own slot is then re-created as a
     fresh set of 4 EMPTY placeholders for the new round (same
     spp_kq_create_assignment_placeholders() round 1's own draw
     already uses) rather than dropped from the event -- confirmed
     behavior: a cancelled court's players do not auto-resume, a
     facilitator must re-staff all 4 slots via spp_kq_fill_open_slot()
     (inc/spp-kq-roster.php's live-swap screen) before that court can
     proceed, same as any other understaffed court.
   - New spp_kq_get_understaffed_courts()/spp_kq_court_is_fully_staffed():
     any ACTIVE (non-cancelled) court without exactly 4 real
     (non-NULL user_id) assignment rows for a round. Checked by
     spp_kq_transition_start_play() (blocks Start Play) and
     spp_kq_submit_court_score() (blocks that court's score) --
     belt-and-suspenders, since spp_kq_swap_player()/
     spp_kq_fill_open_slot() never themselves leave a court short, but
     a freshly re-created (post-cancellation) court's 4 placeholders
     start empty and must clear this check before proceeding.
   - spp_kq_get_round_progress() now excludes cancelled courts from
     both `total` and `reported` -- "N of M courts reported" never
     counts a cancelled court either way, matching this file's own
     "not counted anywhere" rule for cancellation.
   - spp_kq_full_reset() and spp_kq_transition_reset_event()'s
     'organizing'-branch (full clear back to not_started) both now
     also call spp_kq_clear_checkins() (inc/spp-kq-checkin.php) --
     starting over means re-doing check-in too, same "wipe everything
     this occurrence has accumulated" scope those two already have for
     spp_kq_assignments/spp_kq_scores.

   Changes from 1.0.0:
   - Text-only: spp_kq_transition_start_round1()'s own headcount error
     now says "Roster Adjust" instead of "Registration Admin" -- that
     tool has been replaced for KQ's use by inc/spp-kq-roster.php (a
     KQ-specific, spp_kq_can_facilitate()-gated roster tool, not the
     generic admin/editor/convenor-gated gl-events one). No behavior
     change here; the actual link lives in
     spp_kq_render_start_screen() (inc/spp-kq-screens.php).

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
 * Delayed-start buffer: seconds between the Start Play button press
 * and the official round timer actually beginning. See
 * spp_kq_transition_start_play()'s own docblock for how this is
 * applied -- the ONLY place this constant is used. 1.5.0: the "Start
 * play now" announcement itself fires at the press instant (a client-
 * side concern, inc/spp-kq-screens.php), not at press-instant-plus-
 * this-many-seconds the way it did this morning -- see this file's own
 * 1.5.0 changelog.
 */
const SPP_KQ_ROUND_START_DELAY_SECONDS = 10;

/**
 * Round 2+ automatic rest period (1.5.0): seconds between a round
 * advancing (spp_kq_transition_advance_round() below) and "Go to your
 * courts" auto-announcing for the newly-current round. Round 1 has no
 * equivalent constant -- its own announce step is manually triggered
 * (spp_kq_transition_announce_courts() below), on the facilitator's
 * own timing, not a fixed duration.
 */
const SPP_KQ_COURTS_REST_SECONDS = 120;

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
 * Re-key an array whose top-level keys are court names into this same
 * fixed hierarchy order -- DISPLAY only, never used by movement/CAS/
 * scoring, which already get their court order from spp_kq_determine_
 * courts_order() (itself built from this same hierarchy) rather than
 * from anything this function touches. Introduced because a few read
 * paths (spp_kq_get_full_scoreboard()/spp_kq_get_history_scoreboard(),
 * inc/spp-kq-history.php) build their court-keyed array purely from
 * SQL row order (previously "ORDER BY court_name ASC", i.e.
 * alphabetical: Aces, Jacks, Kings, Queens) instead of pre-seeding keys
 * from the hierarchy the way spp_kq_get_round_court_view() (inc/spp-kq-
 * screens.php) already does -- this gives those callers the same fixed
 * display order from one shared definition instead of each
 * reimplementing it. Any key not in the hierarchy (shouldn't happen --
 * courts are a fixed, closed set) is appended at the end, unordered,
 * rather than silently dropped.
 */
function spp_kq_order_courts_for_display( array $by_court ) : array {
    $ordered = array();
    foreach ( spp_kq_master_court_hierarchy() as $court ) {
        if ( array_key_exists( $court, $by_court ) ) {
            $ordered[ $court ] = $by_court[ $court ];
            unset( $by_court[ $court ] );
        }
    }
    return $ordered + $by_court;
}

/**
 * A court's fixed point value for the format-ranking system (inc/spp-
 * kq-format-ranking.php) -- Aces (top court) = 4 down to Jacks (bottom
 * court) = 1, derived from this same master hierarchy so the value
 * scale can never drift out of sync with the actual court rank order.
 * Null for an unrecognized court name (shouldn't happen -- courts are a
 * fixed, closed set) rather than guessing a value.
 */
function spp_kq_court_value( string $court_name ) : ?int {
    $hierarchy = spp_kq_master_court_hierarchy();
    $index = array_search( $court_name, $hierarchy, true );
    return $index === false ? null : ( count( $hierarchy ) - $index );
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
        "SELECT current_round, phase, round_duration_seconds, round_started_at, courts_announced_at FROM {$table} WHERE occurrence_id = %d",
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
            'error' => "Cannot start: {$confirmed_count} confirmed registrant(s) -- need a multiple of 4, between 4 and 16. Adjust the roster via Roster Adjust first.",
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
 * Round 1 only: facilitator-pressed "Ready -- Announce Courts", fired
 * whenever they judge the room ready (no fixed timing, unlike round
 * 2+'s automatic rest period below). Plain CAS, same shape as every
 * other transition here: courts_announced_at IS NULL in the WHERE
 * clause means a duplicate/near-simultaneous press simply no-ops on
 * whichever request loses, exactly like every other "first request to
 * match wins" transition in this file. Round 2+ never calls this --
 * spp_kq_transition_advance_round() stamps courts_announced_at itself,
 * unconditionally, the moment a round advances.
 */
function spp_kq_transition_announce_courts( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;

    // Same understaffed guard spp_kq_transition_start_play() already
    // enforces, applied here too for the same reason: announcing "go
    // to your courts" while a court is short a player is confusing at
    // best -- the UI already hides this button in that state
    // (spp_kq_render_overview_screen()), this is the "never trust the
    // client" re-check.
    $understaffed = spp_kq_get_understaffed_courts( $occurrence_id, $expected_round );
    if ( ! empty( $understaffed ) ) {
        return array(
            'won'   => false,
            'error' => 'Cannot announce courts: ' . implode( ', ', $understaffed ) . " still need players -- fix via Roster Adjust or cancel the court first.",
        );
    }

    $events_table = spp_kq_events_table();
    $announced_at = current_time( 'timestamp', true );

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET courts_announced_at = %d
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing' AND courts_announced_at IS NULL",
        $announced_at, $occurrence_id, $expected_round
    ) );

    return array( 'won' => ( (int) $affected === 1 ), 'error' => null );
}

/**
 * Start Play: organizing -> in_play, same round number. $expected_round
 * is the round the caller believes is current -- guards against
 * starting play on a round that has already moved on.
 *
 * UNDERSTAFFED GUARD (1.1.0): refuses to start if any active
 * (non-cancelled) court doesn't have exactly 4 real players assigned --
 * the only way this happens today is a court re-created empty after a
 * cancellation (spp_kq_transition_advance_round()) that hasn't been
 * re-staffed yet via spp_kq_fill_open_slot(). A fully-staffed-or-
 * cancelled round is otherwise the norm (spp_kq_swap_player()/
 * spp_kq_fill_open_slot() never themselves leave a court short), so
 * this check should rarely actually block anything -- it exists so a
 * facilitator can never accidentally start play with a court short a
 * player instead of using Roster Adjust or Cancel Court to resolve it
 * first.
 *
 * $duration_seconds (1.3.0): the facilitator-set match-timer length for
 * THIS round, locked in the instant this transition wins -- written
 * into round_duration_seconds/round_started_at in the same atomic
 * UPDATE as the phase flip itself, so a rejected/duplicate attempt
 * (round already started elsewhere) can never clobber a genuinely
 * already-running round's timer. round_started_at is a true Unix/GMT
 * epoch (current_time('timestamp', true)) -- see schema 1.6.0's own
 * changelog for why that's deliberately NOT this file's usual
 * local-wall-clock-as-UTC current_time() domain.
 *
 * DELAYED START (1.4.0): round_started_at is stamped SPP_KQ_ROUND_
 * START_DELAY_SECONDS in the future, not the instant of this call --
 * see that constant's own docblock. Phase still flips to 'in_play'
 * (and score entry/polling/round-advance all still become live) at the
 * real instant this transition wins; only the timer's own official
 * start instant is pushed later, so round_duration_seconds keeps
 * counting from when play actually starts rather than from the button
 * press.
 */
function spp_kq_transition_start_play( int $occurrence_id, int $expected_round, int $duration_seconds ) : array {
    global $wpdb;

    $understaffed = spp_kq_get_understaffed_courts( $occurrence_id, $expected_round );
    if ( ! empty( $understaffed ) ) {
        return array(
            'won'   => false,
            'error' => 'Cannot start play: ' . implode( ', ', $understaffed ) . " still need players -- fix via Roster Adjust or cancel the court first.",
        );
    }

    $events_table = spp_kq_events_table();
    $started_at   = current_time( 'timestamp', true ) + SPP_KQ_ROUND_START_DELAY_SECONDS;

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET phase = 'in_play', round_duration_seconds = %d, round_started_at = %d
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing'",
        $duration_seconds, $started_at, $occurrence_id, $expected_round
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
 *
 * CANCELLED COURTS (1.1.0): any court cancelled THIS round
 * (spp_kq_get_cancelled_courts()) is filtered out of $courts/
 * $assignments/$scores/$history before spp_kq_compute_next_round() ever
 * sees them -- that pure algorithm (inc/spp-kq-movement.php) is
 * completely unaware cancellation exists; it just computes movement for
 * whichever courts it's given, and win/loss destinations naturally
 * contract around the resulting gap (max(i-1,0)/min(i+1,n-1) evaluated
 * against the REDUCED list). The cancelled court's own slot is then
 * re-created as a fresh set of 4 EMPTY placeholders (same
 * spp_kq_create_assignment_placeholders() round 1's own draw already
 * uses) for the new round rather than dropped from the event --
 * confirmed behavior: it does not auto-resume with the players who were
 * on it; a facilitator must re-staff it via spp_kq_fill_open_slot()
 * before it can proceed (see spp_kq_get_understaffed_courts(), which
 * both spp_kq_transition_start_play() and spp_kq_submit_court_score()
 * check).
 *
 * COURTS-ANNOUNCED REST PERIOD (1.5.0): courts_announced_at is
 * unconditionally stamped to current instant + SPP_KQ_COURTS_REST_
 * SECONDS in the same UPDATE that advances current_round/phase --
 * every round this function ever lands on (round 2+, by construction,
 * since this only ever advances OUT of round >= 1) gets its own
 * automatic "Go to your courts" rest countdown with no further action
 * needed. See inc/spp-kq-screens.php for how the screen this lands on
 * reads that value.
 */
function spp_kq_transition_advance_round( int $occurrence_id, int $expected_round ) : array {
    global $wpdb;

    $full_courts = spp_kq_determine_courts_order( $occurrence_id );
    $cancelled   = spp_kq_get_cancelled_courts( $occurrence_id, $expected_round );

    $movement_courts = array_values( array_diff( $full_courts, $cancelled ) );

    $assignments = spp_kq_get_round_assignments( $occurrence_id, $expected_round );
    $scores      = spp_kq_get_round_scores( $occurrence_id, $expected_round );
    $history     = spp_kq_get_history_through_round( $occurrence_id, $expected_round );

    if ( ! empty( $cancelled ) ) {
        $assignments = array_values( array_filter( $assignments, fn( $a ) => ! in_array( $a['court_name'], $cancelled, true ) ) );
        $scores      = array_values( array_filter( $scores,      fn( $s ) => ! in_array( $s['court_name'], $cancelled, true ) ) );
        $history     = array_values( array_filter( $history,     fn( $h ) => ! in_array( $h['court_name'], $cancelled, true ) ) );
    }

    try {
        $next_assignments = spp_kq_compute_next_round( $movement_courts, $assignments, $scores, $history );
    } catch ( SPP_KQ_Movement_Error $e ) {
        return array( 'won' => false, 'error' => $e->getMessage() );
    }

    $events_table   = spp_kq_events_table();
    // 1.5.0: courts_announced_at is stamped here too, unconditionally,
    // in the SAME atomic UPDATE as the round advance itself -- round
    // 2+'s "Go to your courts" rest countdown is fully determined the
    // instant this transition wins, no separate write, never NULL for
    // round 2+ (see this file's own 1.5.0 changelog).
    $rest_announce_at = current_time( 'timestamp', true ) + SPP_KQ_COURTS_REST_SECONDS;
    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$events_table}
         SET current_round = current_round + 1, phase = 'organizing', courts_announced_at = %d
         WHERE occurrence_id = %d AND current_round = %d AND phase = 'in_play'",
        $rest_announce_at, $occurrence_id, $expected_round
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

    // Re-create any cancelled court's slot as fresh, empty placeholders
    // (NULL user_id, same shape round 1's own draw starts from) rather
    // than dropping it from the event -- see this function's own
    // docblock.
    if ( ! empty( $cancelled ) ) {
        spp_kq_create_assignment_placeholders( $occurrence_id, $next_round_number, $cancelled );
    }

    spp_kq_create_score_placeholders( $occurrence_id, $next_round_number, $full_courts );

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
        // 1.1.0: starting over means re-doing check-in too.
        spp_kq_clear_checkins( $occurrence_id );
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
    // 1.1.0: starting over means re-doing check-in too.
    spp_kq_clear_checkins( $occurrence_id );
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

/**
 * "Complete Allocation Randomly" (1.17.0) -- a facilitator-usable
 * shortcut for the round-1 draw: fills every STILL-open slot with
 * whichever confirmed/checked-in players haven't yet claimed one,
 * randomly paired. Deliberately built as nothing more than a loop
 * over spp_kq_draw_card() above, called once per not-yet-drawn player
 * (spp_kq_get_not_yet_drawn(), inc/spp-kq-screens.php -- confirmed
 * registrants minus whoever already has a round-1 assignment row) --
 * every individual assignment goes through that SAME atomic per-slot
 * claim (RAND()-picked candidate, UPDATE ... WHERE user_id IS NULL,
 * retried on contention) a real card-tap already uses, so a row this
 * produces is byte-for-byte indistinguishable from one a player
 * claimed for themselves -- no new concurrency mechanism, and zero
 * interaction with spp_kq_compute_next_round()/the movement algorithm
 * (inc/spp-kq-movement.php), which only ever reads finished assignment
 * rows and has no idea how they were filled in.
 *
 * Safe to call at ANY point in the draw (spec: 0 already-claimed
 * through N-1 already-claimed) -- a player who already has a slot
 * simply isn't in $not_drawn's list at all, so this never touches
 * them. Safe against a manual claim racing THIS call for the same
 * player too: spp_kq_draw_card()'s own "already been drawn" guard
 * (checked first, inside that function) rejects that one player's
 * assignment cleanly -- $assigned just comes back one lower than
 * $remaining_before, not a batch failure -- while every other
 * player's own independent claim in the same loop is unaffected.
 *
 * @return array{assigned: int, remaining_before: int}
 */
function spp_kq_complete_draw_randomly( int $occurrence_id ) : array {
    $not_drawn = spp_kq_get_not_yet_drawn( $occurrence_id );
    $assigned  = 0;
    foreach ( $not_drawn as $player ) {
        $result = spp_kq_draw_card( $occurrence_id, (int) $player['user_id'] );
        if ( $result['success'] ) {
            $assigned++;
        }
    }
    return array( 'assigned' => $assigned, 'remaining_before' => count( $not_drawn ) );
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
    // cancelled = 0: a cancelled court is excluded from BOTH total and
    // reported -- "N of M courts reported" never counts it either way,
    // same "not required, not counted anywhere" rule as every other
    // cancellation-aware read in this feature (see this file's own
    // 1.1.0 changelog).
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM( CASE WHEN red_score IS NOT NULL AND black_score IS NOT NULL THEN 1 ELSE 0 END ) AS reported
         FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND cancelled = 0",
        $occurrence_id, $round_number
    ), ARRAY_A );
    return array( 'reported' => (int) ( $row['reported'] ?? 0 ), 'total' => (int) ( $row['total'] ?? 0 ) );
}

/**
 * One court's current red_score/black_score/client_ts for a round --
 * the "true current state" payload returned alongside both a normal
 * success and a superseded/rejected one (1.2.0), so a caller whose own
 * write didn't stick still gets handed reality instead of silence.
 */
function spp_kq_get_court_score( int $occurrence_id, int $round_number, string $court_name ) : ?array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score, client_ts FROM " . spp_kq_scores_table() . "
         WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $occurrence_id, $round_number, $court_name
    ), ARRAY_A );
    if ( ! $row ) return null;
    return array(
        'red_score'   => $row['red_score']   === null ? null : (int) $row['red_score'],
        'black_score' => $row['black_score'] === null ? null : (int) $row['black_score'],
        'client_ts'   => $row['client_ts']   === null ? null : (int) $row['client_ts'],
    );
}

/**
 * Submit (or correct -- always an overwrite, "most recent entry wins"
 * exactly as spp-score-entry.php's own ladder version) one court's
 * score for the round the caller believes is current. Re-derives
 * everything server-side rather than trusting the caller:
 *  - phase must still be 'in_play' AND current_round must still equal
 *    $round_number (the round may have already advanced between page
 *    load and submit -- rejected, not silently misapplied). This
 *    up-front check is a fast/friendly rejection for the common case
 *    (page just genuinely went stale); it is NOT what makes the write
 *    itself race-safe -- see the WRITE ITSELF below for that.
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
 * THE WRITE ITSELF (1.2.0 -- see this file's own 1.2.0 changelog for
 * the reproduced bug this closes): a single atomic UPDATE whose WHERE
 * clause folds in BOTH of the following, never a separate pre-check
 * that could itself go stale between checking and writing:
 *   1. `client_ts IS NULL OR client_ts < $client_ts` -- an ordering
 *      guard exactly like the ladder's, stamped on this court's own
 *      row (schema 1.5.0) instead of a transient. $client_ts <= 0 (an
 *      old cached client that predates this fix) disables just this
 *      condition, never the one below.
 *   2. `EXISTS (spp_kq_events WHERE current_round = $round_number AND
 *      phase = 'in_play')` -- re-verified AT WRITE TIME, not trusted
 *      from the precondition check above. This is what actually closes
 *      the race: if the round has already advanced past this score's
 *      round by the moment this UPDATE executes -- for ANY reason,
 *      including this exact request simply having taken a long time to
 *      get here -- the write is refused. A too-late correction fails
 *      loudly instead of landing silently into a round whose movement
 *      already happened without it; there is no attempt to undo and
 *      recompute movement after the fact, deliberately -- see the
 *      changelog for why.
 * Zero rows affected means one of the two guards fired; the caller gets
 * back which one (a fresh event-state read distinguishes them) plus
 * this court's actual current score, so its UI can reconcile rather
 * than assume its own submission's values are now truth.
 *
 * After a successful write, checks whether every court in this round
 * has now reported and, if so, attempts spp_kq_transition_advance_round()
 * (Stage 2, unchanged -- and unchanged again here: by the time it reads
 * spp_kq_scores, every value it can see is one the guard above
 * guarantees can never again be silently superseded once the round
 * moves past it, so no internal change was needed there) -- safe under
 * real concurrency because that function's own CAS is what actually
 * decides the single winner; this function's own "should I even try"
 * check just decides who ATTEMPTS, not who succeeds, and multiple
 * simultaneous attempts are exactly what that CAS already handles (see
 * Stage 2's own concurrency test, and this stage's own version of the
 * same test against the real submit path).
 */
function spp_kq_submit_court_score( int $occurrence_id, int $round_number, string $court_name, int $red_score, int $black_score, int $user_id, int $client_ts = 0 ) : array {
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state || $state['phase'] !== 'in_play' || (int) $state['current_round'] !== $round_number ) {
        return array( 'success' => false, 'error' => 'This round is no longer accepting scores -- refresh to see the current state.' );
    }

    global $wpdb;
    $scores_table = spp_kq_scores_table();
    $events_table = spp_kq_events_table();

    $court_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT cancelled FROM {$scores_table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $occurrence_id, $round_number, $court_name
    ), ARRAY_A );
    if ( ! $court_row ) {
        return array( 'success' => false, 'error' => 'Not a valid court for this round -- refresh to see the current state.' );
    }
    if ( (int) $court_row['cancelled'] === 1 ) {
        return array( 'success' => false, 'error' => 'This court was cancelled for this round -- no score to enter.' );
    }
    // Belt-and-suspenders (see spp_kq_transition_start_play()'s own
    // docblock): spp_kq_swap_player()/spp_kq_fill_open_slot() never
    // themselves leave a court short, so this should never actually
    // trigger in practice -- but a facilitator submitting a score
    // straight off a just-generated round (bypassing the Start Play
    // guard via a stale page) must not be able to record a game for a
    // court that isn't really a clean 2v2 yet.
    if ( ! spp_kq_court_is_fully_staffed( $occurrence_id, $round_number, $court_name ) ) {
        return array( 'success' => false, 'error' => "This court doesn't have exactly 4 players assigned yet -- fix via Roster Adjust before entering a score." );
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

    // $client_ts <= 0 means an old cached client that predates this fix --
    // disable just the ordering guard for THIS write (never touch the
    // round-still-current guard below) and leave any previously-stored
    // client_ts on the row alone rather than clobbering it with a
    // meaningless value. Interpolated directly (not a %d placeholder):
    // already an int by function signature, same "validated int, safe to
    // interpolate" precedent as spp_sc_apply()'s WHERE event_id = {$event_id}
    // elsewhere in this codebase.
    $client_ts     = max( 0, $client_ts );
    $where_ts_guard = ( $client_ts > 0 ) ? "AND (s.client_ts IS NULL OR s.client_ts < {$client_ts})" : '';
    $set_ts         = ( $client_ts > 0 ) ? ", s.client_ts = {$client_ts}" : '';

    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$scores_table} s
         SET s.red_score = %d, s.black_score = %d, s.updated_by = %d {$set_ts}
         WHERE s.occurrence_id = %d AND s.round_number = %d AND s.court_name = %s
           {$where_ts_guard}
           AND EXISTS (
               SELECT 1 FROM {$events_table} e
               WHERE e.occurrence_id = s.occurrence_id
                 AND e.current_round = s.round_number
                 AND e.phase = 'in_play'
           )",
        $red_score, $black_score, $user_id,
        $occurrence_id, $round_number, $court_name
    ) );

    if ( (int) $affected !== 1 ) {
        // Distinguish which guard fired: re-read event state fresh (not
        // the $state captured at the top, which is now provably stale by
        // definition -- that's exactly why we're here).
        $now_state = spp_kq_get_event_state( $occurrence_id );
        $round_moved_on = ! $now_state || $now_state['phase'] !== 'in_play' || (int) $now_state['current_round'] !== $round_number;

        if ( $round_moved_on ) {
            return array( 'success' => false, 'error' => 'This round is no longer accepting scores -- refresh to see the current state.' );
        }

        // Otherwise: the round is still current, so this was purely the
        // ordering guard -- a newer submission for this exact court
        // already landed. Not an error from the caller's point of view;
        // hand back the real current state instead of pretending this
        // request's numbers are now truth.
        $current = spp_kq_get_court_score( $occurrence_id, $round_number, $court_name );
        $progress = spp_kq_get_round_progress( $occurrence_id, $round_number );
        return array(
            'success'    => true,
            'applied'    => false,
            'court_name' => $court_name,
            'red_score'  => $current['red_score']   ?? null,
            'black_score'=> $current['black_score'] ?? null,
            'reported'   => $progress['reported'],
            'total'      => $progress['total'],
            'advanced'   => false,
            'message'    => 'A newer entry for this court was already saved.',
        );
    }

    $progress = spp_kq_get_round_progress( $occurrence_id, $round_number );
    $advanced = false;

    if ( $progress['total'] > 0 && $progress['reported'] === $progress['total'] ) {
        $advance = spp_kq_transition_advance_round( $occurrence_id, $round_number );
        $advanced = $advance['won']; // false just means someone else's simultaneous attempt already won it -- not an error.
    }

    return array(
        'success'     => true,
        'applied'     => true,
        'court_name'  => $court_name,
        'red_score'   => $red_score,
        'black_score' => $black_score,
        'reported'    => $progress['reported'],
        'total'       => $progress['total'],
        'advanced'    => $advanced,
    );
}

// =============================================================
// Mid-event roster swap + court cancellation (1.1.0). Both operate on
// the CURRENT round only -- read via spp_kq_get_event_state(), never
// trusted from the client -- and both are available in 'organizing'
// (between rounds, before Start Play) and 'in_play' (mid-round, before
// that specific court's score is submitted) alike, gated the same way.
// Rendered by spp_kq_render_roster_screen()'s live-swap branch
// (inc/spp-kq-roster.php), dispatched via the 'roster_swap'/
// 'roster_fill_slot'/'cancel_court' cases in
// spp_kq_handle_post_actions() (inc/spp-kq-screens.php).
// =============================================================

/**
 * Court/team_color one user is assigned to in a given round, or null if
 * they aren't assigned in that round at all.
 */
function spp_kq_get_assignment_slot( int $occurrence_id, int $round_number, int $user_id ) : ?array {
    global $wpdb;
    $table = spp_kq_assignments_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT court_name, team_color FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND user_id = %d",
        $occurrence_id, $round_number, $user_id
    ), ARRAY_A );
    return $row ?: null;
}

/**
 * Whether one court has already reported a complete score for a round --
 * the gate every mid-event roster action (swap, fill-open-slot, cancel)
 * shares: once a court has reported, its game already happened for real
 * and none of these should be able to touch it anymore.
 */
function spp_kq_court_has_reported( int $occurrence_id, int $round_number, string $court_name ) : bool {
    global $wpdb;
    $table = spp_kq_scores_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $occurrence_id, $round_number, $court_name
    ), ARRAY_A );
    return $row && $row['red_score'] !== null && $row['black_score'] !== null;
}

/**
 * Whether one court has exactly 4 real (non-NULL user_id) players
 * assigned for a round -- see spp_kq_transition_start_play()'s own
 * docblock for why this is checked before play starts, and
 * spp_kq_submit_court_score()'s for why it's checked again there.
 */
function spp_kq_court_is_fully_staffed( int $occurrence_id, int $round_number, string $court_name ) : bool {
    global $wpdb;
    $table = spp_kq_assignments_table();
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s AND user_id IS NOT NULL",
        $occurrence_id, $round_number, $court_name
    ) );
    return $count === 4;
}

/**
 * Every ACTIVE (non-cancelled) court for a round that does NOT have
 * exactly 4 real players -- today this can only be a court just
 * re-created empty by spp_kq_transition_advance_round() after a
 * cancellation (spp_kq_swap_player()/spp_kq_fill_open_slot() never
 * themselves leave a court short), but this is computed generally
 * rather than special-cased to that one path.
 */
function spp_kq_get_understaffed_courts( int $occurrence_id, int $round_number ) : array {
    $cancelled = spp_kq_get_cancelled_courts( $occurrence_id, $round_number );
    $active    = array_diff( spp_kq_determine_courts_order( $occurrence_id ), $cancelled );

    $bad = array();
    foreach ( $active as $court ) {
        if ( ! spp_kq_court_is_fully_staffed( $occurrence_id, $round_number, $court ) ) {
            $bad[] = $court;
        }
    }
    return $bad;
}

/**
 * Court names cancelled for one round (spp_kq_scores.cancelled = 1).
 */
function spp_kq_get_cancelled_courts( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_scores_table();
    return $wpdb->get_col( $wpdb->prepare(
        "SELECT court_name FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND cancelled = 1",
        $occurrence_id, $round_number
    ) );
}

/**
 * Shared precondition for every mid-event roster action below: the
 * event must be past Round 1's draw (organizing or in_play; round 1
 * itself must have no unclaimed slots left -- the card draw, not this
 * feature, owns getting a fresh round 1 to a clean 2v2 per court).
 * Returns the current round number on success, or null with an error.
 *
 * @return array ['round'=>?int, 'error'=>?string]
 */
function spp_kq_live_roster_precondition( int $occurrence_id ) : array {
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state || ! in_array( $state['phase'], array( 'organizing', 'in_play' ), true ) ) {
        return array( 'round' => null, 'error' => "Swaps and cancellations are only available once Round 1's draw is complete and the event is underway." );
    }
    $round = (int) $state['current_round'];
    if ( $round === 1 && spp_kq_count_unclaimed( $occurrence_id, 1 ) > 0 ) {
        return array( 'round' => null, 'error' => "Round 1's draw isn't finished yet -- finish the draw before swapping players." );
    }
    return array( 'round' => $round, 'error' => null );
}

/**
 * 1-for-1 swap: $old_user_id (must currently be assigned in the current
 * round) is replaced by $new_user_id in the EXACT court/team_color slot
 * they occupied -- no re-running of partner-rotation/history logic,
 * spp_kq_compute_next_round() is never touched by this. Mirrors
 * spp_kq_roster_add()/spp_kq_roster_remove() (inc/spp-kq-roster.php) for
 * the gl_registrations side: $new_user_id is confirmed, $old_user_id is
 * withdrawn, same as the pre-Round-1 Roster Adjust tool's own add/
 * remove -- so a mid-event swap keeps the registrant list honest too.
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_swap_player( int $occurrence_id, int $old_user_id, int $new_user_id ) : array {
    $pre = spp_kq_live_roster_precondition( $occurrence_id );
    if ( $pre['error'] ) {
        return array( 'success' => false, 'error' => $pre['error'] );
    }
    $round = $pre['round'];

    if ( $new_user_id <= 0 ) {
        return array( 'success' => false, 'error' => 'Please select a replacement.' );
    }
    if ( $old_user_id === $new_user_id ) {
        return array( 'success' => false, 'error' => 'The replacement must be a different player.' );
    }

    $slot = spp_kq_get_assignment_slot( $occurrence_id, $round, $old_user_id );
    if ( ! $slot ) {
        return array( 'success' => false, 'error' => 'That player is not currently assigned to a court this round.' );
    }
    if ( spp_kq_court_has_reported( $occurrence_id, $round, $slot['court_name'] ) ) {
        return array( 'success' => false, 'error' => 'That court has already reported its score for this round -- swap is no longer available.' );
    }
    if ( spp_kq_get_assignment_slot( $occurrence_id, $round, $new_user_id ) ) {
        return array( 'success' => false, 'error' => 'That player is already assigned to a court this round.' );
    }

    $add_result = spp_kq_roster_add( $occurrence_id, $new_user_id );
    if ( ! $add_result['success'] ) {
        return $add_result;
    }
    spp_kq_roster_remove( $occurrence_id, $old_user_id );

    global $wpdb;
    $wpdb->update( spp_kq_assignments_table(),
        array( 'user_id' => $new_user_id ),
        array( 'occurrence_id' => $occurrence_id, 'round_number' => $round, 'user_id' => $old_user_id )
    );

    return array( 'success' => true, 'error' => null, 'court_name' => $slot['court_name'], 'round' => $round );
}

/**
 * Fill one specific EMPTY slot (court_name + team_color, user_id IS
 * NULL) for the current round -- the re-staffing half of a cancelled
 * court's revival (see spp_kq_transition_advance_round()'s own
 * docblock). Distinct from spp_kq_swap_player() only in that there is
 * no $old_user_id to look up or withdraw; otherwise identical
 * validation (membership, not already assigned this round, court not
 * yet reported).
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_fill_open_slot( int $occurrence_id, string $court_name, string $team_color, int $new_user_id ) : array {
    $pre = spp_kq_live_roster_precondition( $occurrence_id );
    if ( $pre['error'] ) {
        return array( 'success' => false, 'error' => $pre['error'] );
    }
    $round = $pre['round'];

    if ( $new_user_id <= 0 ) {
        return array( 'success' => false, 'error' => 'Please select a player.' );
    }
    if ( spp_kq_court_has_reported( $occurrence_id, $round, $court_name ) ) {
        return array( 'success' => false, 'error' => 'That court has already reported its score for this round.' );
    }
    if ( spp_kq_get_assignment_slot( $occurrence_id, $round, $new_user_id ) ) {
        return array( 'success' => false, 'error' => 'That player is already assigned to a court this round.' );
    }

    $add_result = spp_kq_roster_add( $occurrence_id, $new_user_id );
    if ( ! $add_result['success'] ) {
        return $add_result;
    }

    global $wpdb;
    $table = spp_kq_assignments_table();
    // No ORDER BY needed -- the (up to two) same-color placeholder slots
    // on a court are interchangeable, so LIMIT 1 against user_id IS NULL
    // claims whichever MySQL finds first, same "any open slot will do"
    // posture as spp_kq_draw_card()'s own claim, just without that
    // function's random-retry loop (no real concurrent contention here:
    // this is a deliberate facilitator pick, not a player-facing race).
    $affected = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET user_id = %d
         WHERE occurrence_id = %d AND round_number = %d AND court_name = %s AND team_color = %s AND user_id IS NULL
         LIMIT 1",
        $new_user_id, $occurrence_id, $round, $court_name, $team_color
    ) );

    if ( (int) $affected !== 1 ) {
        return array( 'success' => false, 'error' => 'No open slot there anymore -- refresh and try again.' );
    }

    return array( 'success' => true, 'error' => null, 'court_name' => $court_name, 'round' => $round );
}

/**
 * Mark one court cancelled for the current round -- no assignment rows
 * touched (its nominal players stay on record, simply never scored; see
 * spp_kq_get_round_progress()/spp_kq_submit_court_score() for how
 * "cancelled" is then excluded everywhere downstream). Only available
 * before that court has reported a score.
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_cancel_court( int $occurrence_id, string $court_name ) : array {
    $pre = spp_kq_live_roster_precondition( $occurrence_id );
    if ( $pre['error'] ) {
        return array( 'success' => false, 'error' => $pre['error'] );
    }
    $round = $pre['round'];

    global $wpdb;
    $scores_table = spp_kq_scores_table();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score, cancelled FROM {$scores_table} WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
        $occurrence_id, $round, $court_name
    ), ARRAY_A );

    if ( ! $row ) {
        return array( 'success' => false, 'error' => 'Not a valid court for this round.' );
    }
    if ( (int) $row['cancelled'] === 1 ) {
        return array( 'success' => false, 'error' => 'That court is already cancelled for this round.' );
    }
    if ( $row['red_score'] !== null && $row['black_score'] !== null ) {
        return array( 'success' => false, 'error' => 'That court has already reported a score for this round -- nothing to cancel.' );
    }

    $wpdb->update( $scores_table,
        array( 'cancelled' => 1 ),
        array( 'occurrence_id' => $occurrence_id, 'round_number' => $round, 'court_name' => $court_name )
    );

    return array( 'success' => true, 'error' => null, 'court_name' => $court_name, 'round' => $round );
}
