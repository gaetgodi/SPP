<?php
/* =========================================================
   Membership Tags Refresh
   Version: 1.0.0
   Date: 2026-09-05
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

function spp_refresh_membership_tags() {
    global $wpdb;

    // Drop any tag-less rows so they get a fresh placeholder below --
    // keeps this idempotent/self-healing rather than accumulating rows.
    $wpdb->query( "DELETE FROM MembershipTags WHERE Tag IS NULL" );

    // Every active member without a MembershipTags row yet.
    $untagged = $wpdb->get_results(
        "SELECT * FROM membership WHERE user_id NOT IN (SELECT user_id FROM MembershipTags) ORDER BY user_id",
        ARRAY_A
    );

    foreach ( $untagged as $row ) {
        // $wpdb->insert() prepares/escapes every value in $row --
        // replaces CM252's manually-quoted, unescaped INSERT.
        $wpdb->insert( 'MembershipTags', $row );
    }
}
