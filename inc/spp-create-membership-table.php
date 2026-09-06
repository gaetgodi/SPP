<?php
/* =========================================================
   Create Membership Table
   Version: 1.3.0
   Date: 2026-09-06
   Based on: Code Manager snippet "Create membership table" (CM102),
   version 1.1

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

    ini_set( 'display_errors', 0 );
    ini_set( 'display_startup_errors', 0 );

    $prefix      = $wpdb->prefix;
    $year        = date( 'Y' );
    $masterY     = "Masterlist" . $year;
    $membershipY = "Membershiplist" . $year;
    $membership  = "membership";
    $master      = "Master";
    $umetatable  = $prefix . "usermeta";

    // Refresh membership tags before rebuilding tables.
    spp_refresh_membership_tags();

    // -------------------------------------------------------
    // Build tmp — all active members from usermeta
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS tmp" );

    $wpdb->query( "
        CREATE TABLE tmp AS
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
                MAX(CASE WHEN meta_key = 'spp_dupr_rating'  THEN meta_value END) AS DUPR,
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
    " );
    if ( $wpdb->last_error ) echo "<p style='color:red;'>⚠ tmp creation error: " . $wpdb->last_error . "</p>";

    $wpdb->query( "ALTER TABLE tmp MODIFY COLUMN PCO INT" );
    $wpdb->query( "UPDATE tmp SET Ladder='No' WHERE Rank=0" );

    // -------------------------------------------------------
    // Build Master — active ladder players only
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS {$master}" );
    $wpdb->query( "
        CREATE TABLE {$master} AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.Rating, t.ClubRating, t.DUPR, m.Tag
        FROM tmp t
        INNER JOIN MembershipTags m ON t.user_id = m.user_id
        WHERE t.Rank <> 0 AND t.Ladder = 'Yes'
        ORDER BY CONVERT(t.Rank, SIGNED INTEGER)
    " );
    if ( $wpdb->last_error ) echo "<p style='color:red;'>⚠ Master creation error: " . $wpdb->last_error . "</p>";

    // -------------------------------------------------------
    // Build Masterlist{year} — yearly backup of Master
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS {$masterY}" );
    $wpdb->query( "
        CREATE TABLE {$masterY} AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.Rating, t.ClubRating, t.DUPR, m.Tag
        FROM tmp t
        INNER JOIN MembershipTags m ON t.user_id = m.user_id
        WHERE t.Rank <> 0 AND t.Ladder = 'Yes'
        ORDER BY CONVERT(t.Rank, SIGNED INTEGER)
    " );
    if ( $wpdb->last_error ) echo "<p style='color:red;'>⚠ {$masterY} creation error: " . $wpdb->last_error . "</p>";

    // -------------------------------------------------------
    // Build membership — all active members
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS {$membership}" );
    $wpdb->query( "
        CREATE TABLE {$membership} AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.PCO, t.Rating, t.ClubRating, t.DUPR, m.Tag
        FROM tmp t
        LEFT JOIN MembershipTags m ON t.user_id = m.user_id
        ORDER BY last_name
    " );
    if ( $wpdb->last_error ) echo "<p style='color:red;'>⚠ membership creation error: " . $wpdb->last_error . "</p>";

    $wpdb->query( "ALTER TABLE {$membership} ADD PRIMARY KEY (user_id)" );

    // -------------------------------------------------------
    // Build Membershiplist{year} — yearly backup of membership
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS {$membershipY}" );
    $wpdb->query( "
        CREATE TABLE {$membershipY} AS
        SELECT t.Rank, t.user_id, t.first_name, t.last_name,
               t.user_phone, t.travel, t.user_email,
               t.Ladder, t.PCO, t.Rating, t.ClubRating, t.DUPR, m.Tag
        FROM tmp t
        LEFT JOIN MembershipTags m ON t.user_id = m.user_id
        ORDER BY last_name
    " );
    if ( $wpdb->last_error ) echo "<p style='color:red;'>⚠ {$membershipY} creation error: " . $wpdb->last_error . "</p>";

    $wpdb->query( "ALTER TABLE {$membershipY} ADD PRIMARY KEY (user_id)" );

    // -------------------------------------------------------
    // Cleanup
    // -------------------------------------------------------
    $wpdb->query( "DROP TABLE IF EXISTS tmp" );
}

add_shortcode( 'spp_create_membership_table', function( $atts ) {
    ob_start();
    spp_create_membership_table();
    return ob_get_clean();
} );
