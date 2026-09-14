<?php
/* =========================================================
   Membership Tags Refresh
   Version: 1.2.0
   Date: 2026-09-14

   Changes from 1.1.0:
   - spp_refresh_membership_tags() now returns an
     ['deleted'=>int,'synced'=>int,'failed'=>int] summary instead of
     nothing, so a caller can show a real status message (spp_membership_
     tags_refresh_ui(), inc/spp-membership-tags-refresh-ui.php) --
     "nothing needed syncing" vs. "N synced" vs. a failure count folded
     into that same summary, distinct from (and not a replacement for)
     the existing per-row ⚠ warning this file already echoes inline.
     Purely additive -- spp_create_membership_table()'s own bare,
     return-value-ignoring call is unaffected.

   Changes from 1.0.0:
   - BUG FIX (data loss, found via investigation): the untagged-member
     re-insert step used `SELECT *` from membership, which carries
     ClubRating/RatingGames/DUPR -- three columns membership has that
     MembershipTags does not. $wpdb->insert('MembershipTags', $row) then
     built an INSERT naming those columns too, which MySQL always
     rejected outright ("Unknown column 'ClubRating' in 'field list'"),
     confirmed via a live rollback-wrapped test (a fake user_id, real
     column shape, always rolled back). Neither this function nor either
     of its two callers (spp_membership_tags_refresh_ui(),
     spp_create_membership_table()) checked $wpdb->insert()'s return
     value, and production has WP_DEBUG/WP_DEBUG_DISPLAY both off, so
     this failed completely silently -- no error surfaced anywhere. Net
     effect whenever a genuinely untagged/new member existed: step 1
     below deletes their tag-less row, step 2's insert then fails, and
     the row is simply gone -- the opposite of "self-healing," and a
     real (if narrow, previously-unnoticed) violation of
     spp_create_membership_table()'s own claimed idempotency, since that
     function calls this one as its first step on every rebuild. Dormant
     until now only because the live dataset happened to have zero
     tag-less/missing rows at the time this was found.
     FIXED:
       - The SELECT now explicitly whitelists only the columns that
         exist in BOTH tables (Rank, user_id, first_name, last_name,
         user_phone, travel, user_email, Ladder, PCO, Rating, Tag) --
         MembershipTags' own schema is the source of truth for what a
         row looks like, not membership's. MembershipTags-only columns
         (status, Expiry, YrEndDt, locked, Balls) simply fall to their
         column defaults on a fresh placeholder row, same as any other
         normal insert into this table; membership-only columns
         (ClubRating/RatingGames/DUPR) are dropped, matching this
         table's own definition rather than adding them to
         MembershipTags to match membership.
       - $wpdb->insert()'s return value is now checked per row; a
         failure is echoed inline (same "log $wpdb->last_error but keep
         going" convention spp-create-membership-table.php already uses
         for its own CREATE TABLE ... AS steps -- a red ⚠ line, not
         error_log()/a file) rather than being silently swallowed.
     Steady-state behavior (0 tag-less rows, 0 missing rows) is
     unchanged -- the foreach body never runs in that case, so nothing
     here could regress it.

   Based on: Code Manager snippet "Membership tags table refresh
   only" (CM252)

   PURPOSE:
   Keeps MembershipTags in sync with membership: every active
   member should have a MembershipTags row so they show up in
   the tag-editing UI, even before anyone has actually tagged
   them. Called at the start of every "Create membership table"
   rebuild (CM102 today; spp_create_membership_table() once that
   snippet is migrated too).

   Logic (unchanged from CM252):
   1. Delete any MembershipTags row that never got a Tag value --
      this makes the sync self-healing rather than accumulating
      permanent null-Tag rows.
   2. Find every membership row without a matching MembershipTags
      row and insert a placeholder for it (Tag left unset, so a
      row that's still untagged next time gets swept and
      re-inserted again by step 1/2 until someone tags it).

   Changes from CM252:
   - Wrapped in a real function, spp_refresh_membership_tags(),
     instead of a bare top-level script -- so it's directly
     callable from tracked PHP (not just [cmruncode]).
   - Fixed a SQL injection risk: CM252 built its INSERT by manually
     wrapping every column value in double quotes with no escaping
     at all, sourced from membership (itself sourced from
     user-editable usermeta profile fields -- name, phone, travel
     notes). Replaced with $wpdb->insert('MembershipTags', $row),
     which parameterizes every value automatically.
   - Dropped two pieces of dead code carried in CM252 that this
     logic never used: a $prefix variable that was assigned but
     never referenced (MembershipTags/membership are unprefixed
     table names, consistent with Master/Schedules/Groups/etc.
     elsewhere in this codebase), and a
     "if (!session_status() == PHP_SESSION_ACTIVE) session_start()"
     guard -- always a no-op due to operator precedence (this
     project has the identical dead pattern in several other
     migrated snippets), and $_SESSION was never read anywhere in
     this snippet regardless.
   - No other behavior change. See CM252 in Code Manager for the
     original; that snippet's DB row now just calls this function,
     kept alive only for [cmruncode name='Membership tags table
     refresh only'] callers (CM102) during the transition.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * @return array ['deleted'=>int, 'synced'=>int, 'failed'=>int] -- a
 *   summary of what this run actually did, for callers that want to show
 *   a status message (spp_membership_tags_refresh_ui()) rather than just
 *   the per-row ⚠ failure warning already echoed inline above. Ignoring
 *   the return value (spp_create_membership_table()'s own bare call)
 *   remains perfectly valid -- this is purely additive.
 *   'deleted'  -- tag-less rows swept in step 1 (self-healing candidates).
 *   'synced'   -- of the rows found missing in step 2, how many were
 *                 successfully re-inserted.
 *   'failed'   -- of those same rows, how many failed to insert (each
 *                 already produced its own ⚠ line above; this is just the
 *                 count, not a second reporting mechanism).
 */
function spp_refresh_membership_tags() : array {
    global $wpdb;

    // Drop any tag-less rows so they get a fresh placeholder below --
    // keeps this idempotent/self-healing rather than accumulating rows.
    // $wpdb->query() on a DELETE returns the affected-row count (or false
    // on a real query error, which would be a schema/connection problem
    // far beyond this function's own scope -- treated as 0 deleted here,
    // not specially handled).
    $deleted = $wpdb->query( "DELETE FROM MembershipTags WHERE Tag IS NULL" );
    $deleted = ( $deleted === false ) ? 0 : (int) $deleted;

    // Every active member without a MembershipTags row yet. Explicitly
    // whitelisted to only the columns MembershipTags actually has --
    // MembershipTags' own schema is the source of truth for what a row
    // looks like, not membership's (which also carries ClubRating/
    // RatingGames/DUPR, columns MembershipTags doesn't have -- a prior
    // SELECT * here made every insert below fail outright; see this
    // file's own 1.1.0 changelog).
    $untagged = $wpdb->get_results(
        "SELECT Rank, user_id, first_name, last_name, user_phone, travel, user_email, Ladder, PCO, Rating, Tag
         FROM membership
         WHERE user_id NOT IN (SELECT user_id FROM MembershipTags)
         ORDER BY user_id",
        ARRAY_A
    );

    $synced = 0;
    $failed = 0;

    foreach ( $untagged as $row ) {
        // $wpdb->insert() prepares/escapes every value in $row --
        // replaces CM252's manually-quoted, unescaped INSERT. Return
        // value now checked -- a failed insert here previously vanished
        // silently (production has WP_DEBUG/WP_DEBUG_DISPLAY both off);
        // same "log last_error but keep going" convention
        // spp-create-membership-table.php already uses for its own
        // CREATE TABLE ... AS steps, not a new pattern invented here.
        $inserted = $wpdb->insert( 'MembershipTags', $row );
        if ( $inserted === false ) {
            $failed++;
            echo "<p style='color:red;'>⚠ MembershipTags insert error (user_id {$row['user_id']}): " . $wpdb->last_error . "</p>";
        } else {
            $synced++;
        }
    }

    return array( 'deleted' => $deleted, 'synced' => $synced, 'failed' => $failed );
}
