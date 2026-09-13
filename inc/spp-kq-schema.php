<?php
/* =========================================================
   Ace/Queen of the Courts — Live Event Schema
   Version: 1.3.0
   Date: 2026-09-13

   Changes from 1.2.0 (new table, additive -- no ALTER of the three
   existing tables):
   - Added spp_kq_history: permanent, one-row-per-player-per-round-per-
     court archive, denormalized (event_date/source copied in alongside
     the round/court/score data rather than requiring a join back
     through gl_events_v later). Distinct from spp_kq_scores/
     spp_kq_assignments on purpose -- those two are LIVE, in-progress-
     event state that Full Reset and Cancel Event legitimately delete
     from; spp_kq_history is written exactly once per occurrence, only
     at a successful 'end_event' transition, only for occurrences dated
     on/after SPP_KQ_CLUB_RATING_LAUNCH_DATE (inc/spp-kq-club-rating.php)
     -- the same permanent, literal pre-launch cutoff Club Ratings
     already uses, reused rather than duplicated, so the 4 rolling
     Practice/Test Sandbox occurrences (spp-kq-screens.php's event
     picker) can never pollute a real player's permanent history, same
     guarantee Club Ratings already has. See inc/spp-kq-history.php for
     the write path and inc/spp-reports.php's kq_history entry for how
     this is read back.
   - UNIQUE KEY (occurrence_id, round_number, user_id) is the real
     integrity rule -- a player can only be in one court/color per round
     per occurrence, same shape as spp_kq_assignments' own unique key.
     court_name/team_color/red_score/black_score are stored per-row
     rather than normalized into a separate scores table: every
     described consumer (the kq_history report, the recap email) wants
     "rows about one player," and this table is written once, read
     often, never updated in place -- denormalizing here trades a small
     amount of redundant storage (red_score/black_score duplicated onto
     both players' rows for the same court) for zero joins on every read.

   Changes from 1.1.0 (this one IS a live ALTER -- spp_kq_events
   already exists and holds real rows by this point):
   - spp_kq_events.phase's ENUM gains a 5th value, 'cancelled', for
     the new Cancel Event transition (in_play -> cancelled). Verified
     directly before relying on it: dbDelta is well-documented to
     struggle with ALTERing an existing column's full type (see
     GL_Schema::migrate_hours_columns_to_decimal() elsewhere in this
     codebase, which works around exactly that for an INT->DECIMAL
     change) -- but an ENUM value-LIST addition is a different case,
     and a standalone probe against a throwaway clone table (create
     with the old 4-value enum, dbDelta() again with the 5-value
     definition, then attempt a real INSERT using the new value)
     confirmed dbDelta issues a correct ALTER TABLE for this specific
     change: the live column definition updated to include
     'cancelled' and an INSERT using it succeeded immediately. No
     manual ALTER TABLE fallback needed for this migration.

   Changes from 1.0.0 (Stage 2, before this schema was ever run against
   the live database -- a pre-launch adjustment, not a migration):
   - spp_kq_assignments.user_id loosened from NOT NULL to nullable.
     Needed for the card draw's own concurrency: round 1 starts by
     bulk-inserting all N*4 court/color slots as placeholder rows with
     user_id = NULL, and each card-tap claims one via
     `UPDATE ... SET user_id = ? WHERE id = ? AND user_id IS NULL` --
     the same per-row compare-and-swap the three named phase
     transitions use, just aimed at a row here instead of at
     spp_kq_events. MySQL's UNIQUE index treats multiple NULLs as
     non-colliding (unlike two equal non-NULL values), which is
     exactly the "many still-open slots may coexist, but a real
     user_id may only ever occupy one" behavior this needs -- no new
     column required. See the Stage 2 conversation for the fuller
     reasoning (a timestamp-based mutex breaks on same-second
     collisions; an insert/delete lock row risks getting stuck on a
     crashed request; both were rejected in favor of this, which
     stays inside "ordinary UPDATE row-locking, no exotic primitives").

   PURPOSE:
   Creates the four tables backing Ace and Queen of the Courts (GL
   Events categories 2 and 3, `gl_event_categories.id` 2/3): three for
   the live in-event round/court/score runner, plus one (spp_kq_history,
   added 1.3.0) for permanent post-event archival. Registration,
   waitlist, and convenor roster management are already fully handled
   by gl-events' own tables and [gl_registration_admin] -- this
   feature's own tables only track what happens LIVE, in-event
   (spp_kq_events/assignments/scores), and what survives permanently
   after (spp_kq_history), on top of an already-registered occurrence.

   - spp_kq_events: one row per occurrence, tracking which round is
     current and the event's phase (not_started / organizing /
     in_play / complete). (occurrence_id, current_round, phase)
     together are the compare-and-swap key every atomic state
     transition (Stage 2) will read and write via
     UPDATE ... WHERE current_round = ? AND phase = ? -- the first
     matching request performs the transition, any near-simultaneous
     duplicate matches zero rows and silently no-ops. No table locks
     needed.

   - spp_kq_assignments: one row per player per round -- their court
     and team color that round. This is the single source of truth
     for both "where is everyone right now" and "who has partnered
     with whom already today" (grouped by round_number + court_name +
     team_color within one occurrence_id -- no separate history
     table). Also what makes mid-event substitution trivial:
     swapping who occupies a (round, court, color) slot from some
     round onward touches only this table, never gl_registrations --
     a substituted-out player's earlier rounds are untouched rows,
     never lost or reattributed. UNIQUE(occurrence_id, round_number,
     user_id) is the real integrity rule: a player can only be in one
     court/color per round.

   - spp_kq_scores: one row per court per round. Winner is ALWAYS
     derived by comparing red_score/black_score at read time -- never
     stored as its own column -- so there is exactly one place a
     score can disagree with itself. NULL scores mean "not yet
     reported"; a round is ready to advance once every active
     court's row has both scores non-NULL.

   - spp_kq_history (1.3.0): one row per player per round per court,
     written ONCE per occurrence at a successful 'end_event' transition
     (inc/spp-kq-history.php), never updated afterward. Deliberately a
     separate table from spp_kq_assignments/spp_kq_scores above, not
     just "stop deleting those" -- Full Reset and Cancel Event both
     legitimately need to keep deleting live in-progress state, and
     permanent history must survive that. See inc/spp-kq-history.php
     for the write path's pre-launch guard.

   Modeled directly on GL_Schema's own dbDelta pattern
   (wp-content/plugins/gl-events/includes/class-gl-schema.php) -- the
   closest existing precedent in this codebase for standing up a
   fresh table set, same formatting conventions (dbDelta is fussy
   about exact KEY/PRIMARY KEY spacing) reused deliberately rather
   than reinvented.

   WIRING (Stage 2): version-gated via a wp_options row, dbDelta() on
   after_setup_theme -- this codebase's one existing precedent for
   standing up a table from a theme file (inc/passkey-server.php,
   also followed by inc/spp-report-variants.php), reused rather than
   inventing a new convention.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

define( 'SPP_KQ_DB_VERSION', '1.3.0' );

/**
 * Create (or, on a later run, no-op/upgrade) the three spp_kq_*
 * tables. Safe to call more than once -- dbDelta only applies the
 * diff between this definition and what already exists. Idempotent
 * via the stored db version, same pattern as
 * spp_report_variants_create_table().
 */
function spp_kq_create_tables() {
    global $wpdb;

    $installed_version = get_option( 'spp_kq_db_version', '0.0.0' );
    if ( version_compare( $installed_version, SPP_KQ_DB_VERSION, '>=' ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $p       = $wpdb->prefix; // e.g. lX9c1_

    // ── Events ────────────────────────────────────────────────────────────
    dbDelta( "CREATE TABLE {$p}spp_kq_events (
        occurrence_id  INT UNSIGNED NOT NULL,
        current_round  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        phase          ENUM('not_started','organizing','in_play','complete','cancelled') NOT NULL DEFAULT 'not_started',
        updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (occurrence_id)
    ) {$charset};" );

    // ── Assignments ───────────────────────────────────────────────────────
    // user_id is nullable: a round's court/color slots are bulk-inserted as
    // NULL placeholders the moment that round is decided, and the card draw
    // (round 1) or the movement algorithm's output (round 2+) fills them in.
    // For round 2+ all slots are filled in the same INSERT that creates them,
    // so NULL only ever exists, transiently, for a round-1 slot not yet drawn.
    // MySQL's UNIQUE index permits many NULLs to coexist (never treated as
    // colliding with each other) while still rejecting two rows with the
    // same real user_id -- see this file's 1.1.0 changelog for why that
    // exact property is what makes the card draw race-safe.
    dbDelta( "CREATE TABLE {$p}spp_kq_assignments (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        occurrence_id  INT UNSIGNED NOT NULL,
        round_number   SMALLINT UNSIGNED NOT NULL,
        user_id        BIGINT UNSIGNED DEFAULT NULL,
        court_name     VARCHAR(20) NOT NULL,
        team_color     ENUM('red','black') NOT NULL,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slot (occurrence_id, round_number, user_id),
        KEY idx_court_round (occurrence_id, round_number, court_name),
        KEY idx_user (occurrence_id, user_id)
    ) {$charset};" );

    // ── Scores ────────────────────────────────────────────────────────────
    dbDelta( "CREATE TABLE {$p}spp_kq_scores (
        occurrence_id  INT UNSIGNED NOT NULL,
        round_number   SMALLINT UNSIGNED NOT NULL,
        court_name     VARCHAR(20) NOT NULL,
        red_score      SMALLINT UNSIGNED DEFAULT NULL,
        black_score    SMALLINT UNSIGNED DEFAULT NULL,
        updated_by     BIGINT UNSIGNED DEFAULT NULL,
        updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (occurrence_id, round_number, court_name)
    ) {$charset};" );

    // ── History (permanent archive, see this file's 1.3.0 changelog) ───────
    dbDelta( "CREATE TABLE {$p}spp_kq_history (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        occurrence_id  INT UNSIGNED NOT NULL,
        round_number   SMALLINT UNSIGNED NOT NULL,
        court_name     VARCHAR(20) NOT NULL,
        team_color     ENUM('red','black') NOT NULL,
        user_id        BIGINT UNSIGNED NOT NULL,
        red_score      SMALLINT UNSIGNED NOT NULL,
        black_score    SMALLINT UNSIGNED NOT NULL,
        event_date     DATE NOT NULL,
        source         ENUM('ace','queen') NOT NULL,
        archived_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_player_round (occurrence_id, round_number, user_id),
        KEY idx_user (user_id),
        KEY idx_event_date (event_date)
    ) {$charset};" );

    update_option( 'spp_kq_db_version', SPP_KQ_DB_VERSION );
}
add_action( 'after_setup_theme', 'spp_kq_create_tables' );

/**
 * Fully-qualified table name helpers -- callers use these instead of
 * repeating $wpdb->prefix . 'spp_kq_x' everywhere.
 */
function spp_kq_events_table() {
    global $wpdb;
    return $wpdb->prefix . 'spp_kq_events';
}
function spp_kq_assignments_table() {
    global $wpdb;
    return $wpdb->prefix . 'spp_kq_assignments';
}
function spp_kq_scores_table() {
    global $wpdb;
    return $wpdb->prefix . 'spp_kq_scores';
}
function spp_kq_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'spp_kq_history';
}
