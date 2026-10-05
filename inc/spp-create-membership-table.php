<?php
/* =========================================================
   Create Membership Table
   Version: 1.7.0
   Date: 2026-10-05
   Based on: Code Manager snippet "Create membership table" (CM102),
   version 1.1

   Changes from 1.6.0:
   - BUILD-THEN-SWAP: Master, Masterlist{year}, membership and
     Membershiplist{year} are no longer DROPped and recreated in place.
     Each is built as {name}_new, then all four are swapped in with ONE
     atomic multi-pair RENAME TABLE (spp_swap_in_new_tables(), inc/
     spp-table-swap.php) and the {name}_old tables dropped. Before
     this, a failed CREATE left the live table already dropped.
   - Any SQL error during the build (including the tmp pivot, its
     ALTER/UPDATE, and the PRIMARY KEY adds, which used to fail
     silently) now aborts the rebuild: red "⚠ <table> creation error"
     notice as before, plus "existing tables left unchanged", the
     _new tables dropped, and the live tables never touched. A PHP
     crash mid-build leaves _new leftovers that the next run drops.
   - The shared permanent `tmp` table is now a TEMPORARY table named
     tmp_membership: private to this request's DB connection, so no
     other request (spp_copy_ranks_to_user_profile(), schedule
     production) can drop it mid-build any more. Dropped with DROP
     TEMPORARY TABLE only -- a plain DROP TABLE IF EXISTS would fall
     through to a permanent table of the same name.
   - spp_create_membership_table_locked() returns true/false, passed
     through by the lock wrapper (false = not rebuilt, live tables
     unchanged). Lock behavior itself is unchanged from 1.6.0.
   - New action 'spp_membership_rebuild_table_built' ($name) fires
     after each _new table is built, before the swap -- used to
     simulate a mid-build failure in testing (throw from it); no
     production listener.
   - Output is byte-identical to 1.6.0 (same SELECTs, same schema);
     verified by fingerprinting all four tables before/after.

   Changes from 1.5.0:
   - RACE FIX: the rebuild is now serialized with a MariaDB named lock,
     GET_LOCK('spp_membership_rebuild', 30). Two overlapping rebuilds
     (seen 2026-10-04 via a double-submitted Create Schedule, see
     gl-schedule-production.php 2.0.9) share the fixed-name `tmp` table:
     one run's final DROP TABLE tmp killed the other's mid-way, so its
     four CREATE ... FROM tmp all failed AFTER their DROP TABLE had
     already removed the live Master/membership tables.
   - Waits (up to 30s) instead of refusing outright like the schedule-
     production lock does, on purpose: this function is idempotent and
     takes ~0.5s, and its callers (schedule production, score correction,
     apply override, copy ranks, change new-user rank, assign ranks, KQ
     end/cancel) carry straight on to read membership/Master afterward.
     Refusing would let a caller read those tables while another
     request has them dropped -- the exact failure being fixed. Queuing
     behind the other rebuild means the caller always sees complete
     tables.
   - Original body unchanged, renamed to
     spp_create_membership_table_locked(); spp_create_membership_table()
     is the lock wrapper (try/finally release; on a PHP fatal the lock
     still drops when the request's DB connection closes). Now returns
     true if the rebuild ran, false if the lock wait timed out (red
     notice echoed, nothing touched). Every existing caller ignores the
     return value, so this is additive.

   Changes from 1.4.0:
   - Added two new pivot columns, following the exact same ClubRating/
     DUPR precedent (1.3.0 below, same exact-match reasoning -- these
     are new, single-purpose usermeta keys with no sibling-key collision
     risk): KQAceRank (meta_key = 'spp_kq_ace_rank') and KQQueenRank
     (meta_key = 'spp_kq_queen_rank') -- the Ace/Queen of the Courts
     format-ranking system's displayed sequential rank position (inc/
     spp-kq-format-ranking.php, new this same day). Propagated to all
     four downstream tables (Master/Masterlist{year}/membership/
     Membershiplist{year}), same as every other rating-like column here.
     NULL for anyone who's never had a KQ event in that format count
     toward it (including every real ladder-only member) -- no default,
     no zero, so the Ranks & Ratings report can tell "never played" apart
     from an actual rank position.

   Changes from 1.3.0:
   - SECURITY FIX (Tier 1 access-control audit), by explicit decision,
     reversing this project's own prior stance: CM102/this shortcode
     was previously documented elsewhere as "intentionally ungated
     (idempotent, high-frequency routine-view page)". Confirmed today
     it's used unconditionally -- no button, no confirm, runs a full
     four-table rebuild on every bare page view -- on TWO pages with
     different Ultimate Member menu restrictions: "Remove user from
     Ladder" (administrator+editor) and "Club Membership list"
     (logged-in-only, no specific role). Gated to administrator+editor
     (matching the higher-stakes page) by explicit user decision: an
     unconditional full-table-rebuild-on-view has no good reason to be
     triggerable by an ordinary member, even though the underlying
     rebuild is read-safe/idempotent for the *data* -- the site's
     access-control posture shouldn't depend on that. Known, accepted
     consequence: the "Club Membership list" page's on-view rebuild no
     longer runs for ordinary logged-in members; they now see whatever
     an admin/editor last triggered elsewhere (this function's own
     nine other internal callers still rebuild it regularly).
     Gate placed ONLY in the standalone add_shortcode() wrapper below,
     never inside spp_create_membership_table() itself: confirmed
     fresh (grep across inc/, mu-plugins/, functions.php) real internal
     callers exist from spp_change_new_user_rank(),
     spp_apply_override_to_results_table(),
     spp_copy_ranks_to_user_profile(),
     spp_assign_ranks_to_registered_players() (x2), spp_score_correction(),
     and spp_run_schedule_production() -- all bare function calls, not
     through this shortcode/wrapper, so all nine keep working exactly
     as today, ungated, unaffected.
   - No other behavior change.

   Changes from 1.2.0:
   - Added two new pivot columns, ClubRating and DUPR, following the
     exact same MAX(CASE WHEN meta_key = '...' THEN meta_value END)
     exact-match pattern already used for Rating (fixed earlier
     tonight for the identical wildcard-collision risk):
       - ClubRating: meta_key = 'spp_glicko_rating'. Confirmed fresh
         against live usermeta before writing this: 217 rows exist
         under this exact key, and 217 more exist under
         'spp_glicko_rating_games' -- a wildcard match (e.g. LIKE
         '%spp_glicko_rating%') would silently pull in the games
         count alongside the rating, the same collision shape Rating
         itself had. Exact match avoids it entirely.
       - DUPR: meta_key = 'spp_dupr_rating'. Confirmed fresh: 5 rows
         exist under this exact key; no similarly-named sibling key
         found under case-insensitive collation (usermeta.meta_key is
         utf8mb4_unicode_ci, confirmed), so no comparable collision
         risk exists for this one today -- exact match used anyway
         for consistency and as a guard against a future sibling key
         (e.g. a games-count key) being added later.
   - Propagated both new columns to all four downstream tables built
     from this same tmp pivot (Master, Masterlist{year}, membership,
     Membershiplist{year}). Confirmed this does NOT happen
     automatically: each of the four CREATE TABLE ... AS SELECT
     statements enumerates its own explicit column list rather than
     using SELECT *, so t.ClubRating/t.DUPR had to be added to each
     of the four SELECT lists individually, not just to the tmp
     pivot. Verified no other code path INSERTs into any of these
     four tables directly (they are always rebuilt wholesale by this
     function), so adding two columns is a safe, purely additive
     schema change for all nine callers of
     spp_create_membership_table() site-wide.

   Changes from 1.1.0:
   - FIXED the same class of bug for user_phone: was
     MAX(CASE WHEN meta_key LIKE '%user_phone%' THEN meta_value END),
     which also matched user_registration_user_phone -- a live
     collision affecting 2 real members (2284, 2366) with genuinely
     different phone numbers under the two keys. Confirmed before
     fixing which value is actually correct, rather than assuming:
     spp-membership-editor.php's self-service "Phone Number" field
     explicitly maps to the bare user_phone key (the one members
     actively maintain today); user_registration_user_phone is not
     written anywhere in current theme or plugin code, and belongs
     to the same orphaned "user_registration_*" field family as
     user_registration_PCO and user_registration_Rating (a bulk
     one-time import from some now-removed registration plugin --
     for both affected users, user_registration_user_phone's
     usermeta row was created AFTER user_phone's, consistent with a
     bulk import running across many accounts rather than an
     ongoing per-user data source). Narrowed to an exact match on
     'user_phone'. Pure read-side fix, usermeta untouched.

   Changes from 1.0.0:
   - FIXED a real, live bug: the Rating column was built with
     MAX(CASE WHEN meta_key LIKE '%Rating%' THEN meta_value END),
     inherited unmodified from CM102. usermeta's meta_key collation
     is case-insensitive, so that wildcard also matched
     ClubRating, spp_glicko_rating, spp_glicko_rating_games (a raw
     game COUNT, not a rating), spp_dupr_rating, and
     user_registration_Rating -- MAX() then picked whichever of
     those six values sorted highest as a STRING, not the member's
     real Rating. Measured impact: 135 of 168 Master-list members
     (80%) were showing a wrong value; 53 of those were showing an
     out-of-scale integer that was actually their glicko game count
     (e.g. "46" as a "Rating"). Narrowed to an exact match on
     'Rating', the same pattern spp_random_ranks() already used
     correctly for the same key. This is a pure read-side fix --
     usermeta itself was never touched or wrong.
   - Audited every other MAX(CASE WHEN meta_key LIKE ...) column in
     this same pivot for the identical risk shape. PCO and Travel
     have the same dormant collision shape (PCO also matches
     "PCO #" and user_registration_PCO; Travel also matches
     Co_Travel) but 0 users currently have more than one of the
     relevant keys populated for either, so no live damage today.
     Left as exact-match candidates for a later pass, not touched
     here. (status's LIKE 'ur_user_status' pattern has no wildcard
     and matches zero rows in the live data regardless -- not a
     collision risk, just a currently-empty column.)

   PURPOSE:
   Rebuilds four tables from usermeta + MembershipTags:
     Master              — active ladder players (Rank <> 0, Ladder=Yes)
     Masterlist{yr}      — same, yearly backup
     membership          — all active members regardless of rank
     Membershiplist{yr}  — same, yearly backup

   Filters:
     - Excludes users in users_ex
     - Includes only members with Expiry or YrEndDt >= Dec 31 current year
     - Master/Masterlist: Rank <> 0 AND Ladder = 'Yes' only

   CALLED FROM (as of this migration):
     - Directly: gl-schedule-production.php, spp-schedule-production.php,
       spp-score-correction.php (updated to call
       spp_create_membership_table() directly as part of this migration)
     - Via [cmruncode name='Create membership table'] (CM102, now a
       transition shim around this function): CM66, CM71, CM101, CM131,
       CM148, CM181, CM219, CM277, CM279, and the menu-reachable pages
       "Ladder - Master List", "Club Membership list", "GL Publish
       Results after overrides". None of these have been touched by
       this migration -- they keep working unchanged via the shim and
       get updated individually as their own turn in the migration
       order comes up.

   Changes from CM102 v1.1:
   - Wrapped in a real function, spp_create_membership_table(), instead
     of a bare top-level script -- directly callable from tracked PHP.
   - Calls spp_refresh_membership_tags() directly instead of
     echo do_shortcode("[cmruncode name='Membership tags table refresh
     only']") -- CM252 was migrated first specifically so this call
     could become a real function call instead of a shortcode
     round-trip. No behavior change: spp_refresh_membership_tags()
     performs the identical MembershipTags sync as before and never
     produced visible output either way.
   - No other behavior change. Deliberately NOT changed here, carried
     forward exactly as-is pending a separate decision:
       - The "log $wpdb->last_error but keep going" pattern after each
         CREATE TABLE ... AS -- a failed tmp/Master/membership build
         still lets the pipeline continue to the next DROP+CREATE
         rather than stopping. This affects a table read on every view
         of two menu-reachable pages, so changing it to a hard stop is
         a separate decision, not bundled into this migration.
       - ini_set('display_errors', 0) / ini_set('display_startup_errors', 0)
         at the top -- process-wide for the rest of the request, not
         scoped to this function. Carried forward unchanged; worth a
         second look later but out of scope here.

   DELIBERATELY LEFT UNGATED (2026-09-06, post-incident audit): this
   function has no confirm-gate, unlike CM66/CM176/CM82/CM52/CM219.
   Considered and rejected adding one, on purpose:
     - Read in full and confirmed idempotent -- writes only to derived
       tables (tmp, Master, Masterlist{year}, membership,
       Membershiplist{year}), never back to usermeta. Re-running it
       with unchanged usermeta produces identical output every time.
     - The bare [spp_create_membership_table] tag lives on "Add user
       to Ladder by name" and "Add user to Ladder by code" -- pages
       people load routinely just to use the form on them, not
       specifically to trigger a rebuild. A confirm-button interrupt
       on every routine visit would be a real, felt usability cost
       for zero safety benefit, since there's nothing unsafe to
       confirm.
     - Verified in practice, not just in theory: this function ran
       repeatedly during tonight's testing (both regression sweeps,
       one deliberate trigger) with no adverse effect of any kind.
   Revisit this decision only if the function's own behavior changes
   to write something other than these derived tables.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_create_membership_table() {
    global $wpdb;

    // Serialize rebuilds -- see 1.6.0 changelog for why this waits
    // rather than refusing.
    if ( $wpdb->get_var( "SELECT GET_LOCK('spp_membership_rebuild', 30)" ) !== '1' ) {
        echo "<p style='color:red;'>⚠ Membership tables are being rebuilt by another request and it didn't finish within 30 seconds -- skipped this rebuild, nothing was changed. Try again shortly.</p>";
        return false;
    }

    try {
        return spp_create_membership_table_locked();
    } finally {
        $wpdb->query( "SELECT RELEASE_LOCK('spp_membership_rebuild')" );
    }
}

function spp_create_membership_table_locked() {
    global $wpdb;

    ini_set( 'display_errors', 0 );
    ini_set( 'display_startup_errors', 0 );

    $prefix      = $wpdb->prefix;
    $year        = date( 'Y' );
    $masterY     = "Masterlist" . $year;
    $membershipY = "Membershiplist" . $year;
    $membership  = "membership";
    $master      = "Master";
    $umetatable  = $prefix . "usermeta";

    // Build-then-swap (1.7.0): every target is built as {$name}_new and
    // swapped in together at the end; the live tables are never dropped
    // or written to before that, so any failure leaves them untouched.
    $targets = array( $master, $masterY, $membership, $membershipY );

    // Refresh membership tags before rebuilding tables.
    spp_refresh_membership_tags();

    // Runs one build step; any SQL error aborts the whole rebuild.
    $step = function ( $sql, $label ) use ( $wpdb ) {
        $wpdb->query( $sql );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( "{$label} creation error: " . $wpdb->last_error );
        }
    };

    try {
        spp_drop_new_tables( $targets ); // leftovers from an interrupted run

        // -------------------------------------------------------
        // Build tmp_membership — all active members from usermeta.
        // TEMPORARY: private to this DB connection, so no other request
        // can see or drop it. Always DROP TEMPORARY -- a plain DROP TABLE
        // IF EXISTS would hit a permanent table of the same name.
        // -------------------------------------------------------
        $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS tmp_membership" );
        $step( "
        CREATE TEMPORARY TABLE tmp_membership AS
        SELECT * FROM (
            SELECT
                LPAD(MAX(CASE WHEN meta_key LIKE 'Rank'  THEN meta_value END), 3, 0) AS Rank,
                {$umetatable}.user_id,
                MAX(CASE WHEN meta_key LIKE '%PCO%' ESCAPE '#' OR meta_key LIKE '%#_PCO%' ESCAPE '#' THEN meta_value END) AS PCO,
                MAX(CASE WHEN meta_key LIKE '%first#_name%'  ESCAPE '#' THEN meta_value END) AS first_name,
                MAX(CASE WHEN meta_key LIKE '%last#_name%'   ESCAPE '#' THEN meta_value END) AS last_name,
                MAX(CASE WHEN meta_key = 'user_phone'  THEN meta_value END) AS user_phone,
                MAX(CASE WHEN meta_key LIKE '%Travel%'       ESCAPE '#' THEN meta_value END) AS travel,
                MAX(CASE WHEN meta_key LIKE 'ur#_user#_status' ESCAPE '#' THEN meta_value END) AS status,
                {$prefix}users.user_email,
                MAX(CASE WHEN meta_key LIKE '%Ladder%'  THEN meta_value END) AS Ladder,
                MAX(CASE WHEN meta_key = 'Rating'  THEN meta_value END) AS Rating,
                MAX(CASE WHEN meta_key = 'spp_glicko_rating'  THEN meta_value END) AS ClubRating,
                MAX(CASE WHEN meta_key = 'spp_glicko_rating_games'  THEN meta_value END) AS RatingGames,
                MAX(CASE WHEN meta_key = 'spp_dupr_rating'  THEN meta_value END) AS DUPR,
                MAX(CASE WHEN meta_key = 'spp_kq_ace_rank'  THEN meta_value END) AS KQAceRank,
                MAX(CASE WHEN meta_key = 'spp_kq_queen_rank'  THEN meta_value END) AS KQQueenRank,
                MAX(CASE WHEN meta_key LIKE '%Expiry%'  THEN meta_value END) AS Expiry,
                MAX(CASE WHEN meta_key LIKE 'YrEndDt'   THEN meta_value END) AS YrEndDt
            FROM {$umetatable}
            INNER JOIN {$prefix}users
                ON {$umetatable}.user_id = {$prefix}users.ID
                AND {$umetatable}.user_id NOT IN (SELECT ID FROM users_ex)
            GROUP BY {$umetatable}.user_id
        ) t
        WHERE t.Expiry >= '{$year}-12-31' OR t.YrEndDt >= '{$year}-12-31'
        ORDER BY Rank
    ", 'tmp' );

        $step( "ALTER TABLE tmp_membership MODIFY COLUMN PCO INT", 'tmp' );
        $step( "UPDATE tmp_membership SET Ladder='No' WHERE Rank=0", 'tmp' );

        // -------------------------------------------------------
        // Build Master + Masterlist{year} (yearly backup) — active
        // ladder players only
        // -------------------------------------------------------
        foreach ( array( $master, $masterY ) as $name ) {
            $step( "
        CREATE TABLE `{$name}_new` AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.Rating, t.ClubRating, t.RatingGames, t.DUPR, t.KQAceRank, t.KQQueenRank, m.Tag
        FROM tmp_membership t
        INNER JOIN MembershipTags m ON t.user_id = m.user_id
        WHERE t.Rank <> 0 AND t.Ladder = 'Yes'
        ORDER BY CONVERT(t.Rank, SIGNED INTEGER)
    ", $name );
            do_action( 'spp_membership_rebuild_table_built', $name );
        }

        // -------------------------------------------------------
        // Build membership + Membershiplist{year} (yearly backup) —
        // all active members
        // -------------------------------------------------------
        foreach ( array( $membership, $membershipY ) as $name ) {
            $step( "
        CREATE TABLE `{$name}_new` AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.PCO, t.Rating, t.ClubRating, t.RatingGames, t.DUPR, t.KQAceRank, t.KQQueenRank, m.Tag
        FROM tmp_membership t
        LEFT JOIN MembershipTags m ON t.user_id = m.user_id
        ORDER BY last_name
    ", $name );
            $step( "ALTER TABLE `{$name}_new` ADD PRIMARY KEY (user_id)", $name );
            do_action( 'spp_membership_rebuild_table_built', $name );
        }

        // -------------------------------------------------------
        // Swap all four in with one atomic RENAME TABLE
        // -------------------------------------------------------
        if ( ! spp_swap_in_new_tables( $targets ) ) {
            throw new RuntimeException( 'swap error: ' . $wpdb->last_error );
        }
        return true;

    } catch ( Throwable $e ) {
        echo "<p style='color:red;'>⚠ " . esc_html( $e->getMessage() )
           . " -- membership tables NOT rebuilt; the existing Master/membership tables were left unchanged.</p>";
        return false;

    } finally {
        // No-op after a successful swap (the _new tables are gone by then).
        spp_drop_new_tables( $targets );
        $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS tmp_membership" );
    }
}

add_shortcode( 'spp_create_membership_table', function( $atts ) {
    // Administrator + editor -- see this file's 1.4.0 changelog entry
    // for the full reasoning (this shortcode is shared by two pages
    // with different UM-intended roles; gated to the stricter one by
    // explicit decision). Checked before the rebuild runs at all --
    // this shortcode has no form/confirm step of its own to nonce
    // (it's a bare, unconditional rebuild-on-view, not a distinct
    // "confirmed" action), so the role check is the complete fix here.
    if ( ! spp_is_admin_or_editor() ) {
        return '<p>You do not have permission to use this tool.</p>';
    }

    ob_start();
    spp_create_membership_table();
    return ob_get_clean();
} );
