<?php
/* =========================================================
   Table Swap (build-then-swap helper)
   Version: 1.0.0
   Date: 2026-10-05

   PURPOSE:
   Shared helper for rebuilding derived tables without ever leaving the
   live table dropped or half-built. Callers build each replacement as
   "{$name}_new", then call spp_swap_in_new_tables() to swap all of them
   in with ONE multi-pair RENAME TABLE statement:

     RENAME TABLE A TO A_old, A_new TO A, B TO B_old, B_new TO B, ...

   A multi-pair RENAME TABLE is all-or-nothing (verified on this
   server's MariaDB 10.5, 2026-10-05: when a later pair fails, earlier
   pairs are reverted), and readers block on it briefly rather than
   ever seeing a missing table. If the build fails, the caller simply
   never calls this, so the live tables are untouched.

   Introduced for the 2026-10-04 schedule-production race (see
   gl-schedule-production.php 2.0.9/2.1.0, spp-create-membership-table.php
   1.6.0/1.7.0), where DROP-then-CREATE left Master/membership dropped
   when the CREATE failed.

   Notes:
   - A live table that doesn't exist yet (first build ever, or a new
     year's Masterlist{year}/Membershiplist{year} on Jan 1) gets only
     the "_new TO name" pair -- renaming a missing table would fail the
     whole statement.
   - Stale "{$name}_old" leftovers (from a request killed between the
     swap and the cleanup) are dropped first, or the rename would fail.
   - Views that reference a swapped table by name (e.g. schedules_w)
     resolve to the new table after the swap, same as with the old
     DROP+RENAME.
   - MariaDB 10.5 has no crash-safe atomic DDL (that's 10.6+): a DB
     SERVER crash mid-rename is not covered. A PHP crash/timeout is.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Swap freshly built "{$name}_new" tables in for the live "$name" tables,
 * atomically, then drop the replaced "{$name}_old" tables.
 *
 * @param string[] $names Live table names (exact case; this server has
 *                        lower_case_table_names = 0).
 * @return bool True if swapped. False if the RENAME failed -- in that
 *              case nothing was swapped and every live table is as it was;
 *              the "_new" tables are left for the caller to clean up.
 */
function spp_swap_in_new_tables( array $names ) : bool {
    global $wpdb;

    $pairs = array();
    foreach ( $names as $name ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$name}_old`" );

        $live_exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $name
        ) );
        if ( $live_exists ) {
            $pairs[] = "`{$name}` TO `{$name}_old`";
        }
        $pairs[] = "`{$name}_new` TO `{$name}`";
    }

    $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
    if ( $wpdb->last_error ) {
        return false;
    }

    foreach ( $names as $name ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$name}_old`" );
    }
    return true;
}

/**
 * Drop any "{$name}_new" build tables -- leftovers from a failed or
 * interrupted build. Never touches the live tables.
 *
 * @param string[] $names Live table names.
 */
function spp_drop_new_tables( array $names ) : void {
    global $wpdb;
    foreach ( $names as $name ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$name}_new`" );
    }
}
