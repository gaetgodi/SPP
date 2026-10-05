<?php
/* =========================================================
   Table Swap (build-then-swap helper)
   Version: 1.1.0
   Date: 2026-10-05

   Changes from 1.0.0:
   - Optional $suffix on spp_swap_in_new_tables()/spp_drop_new_tables()
     (default '_new', so existing callers are unchanged). Needed for
     `preferred`, whose source table is already NAMED preferred_new
     (built by schedule production, read by the Preferred page / WPDA
     app 29 / publisher 11 / the preferred_new report) -- staging the
     replacement there would clobber its own source.
   - New spp_promote_preferred_new(): build-then-swap copy of
     preferred_new into the live preferred, via preferred_build.
     Shared by Create Results (step 15) and Apply Override, which
     before this did DROP-then-RENAME of a never-created
     preferred_temp (Create Results -- left preferred missing every
     week since Oct 2025) and DROP-then-copy (Apply Override).

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
 * Swap freshly built "{$name}{$suffix}" tables in for the live "$name"
 * tables, atomically, then drop the replaced "{$name}_old" tables.
 *
 * @param string[] $names  Live table names (exact case; this server has
 *                         lower_case_table_names = 0).
 * @param string   $suffix Staging-table suffix (default '_new').
 * @return bool True if swapped. False if the RENAME failed -- in that
 *              case nothing was swapped and every live table is as it was;
 *              the staging tables are left for the caller to clean up.
 */
function spp_swap_in_new_tables( array $names, string $suffix = '_new' ) : bool {
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
        $pairs[] = "`{$name}{$suffix}` TO `{$name}`";
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
 * Drop any "{$name}{$suffix}" build tables -- leftovers from a failed or
 * interrupted build. Never touches the live tables.
 *
 * @param string[] $names  Live table names.
 * @param string   $suffix Staging-table suffix (default '_new').
 */
function spp_drop_new_tables( array $names, string $suffix = '_new' ) : void {
    global $wpdb;
    foreach ( $names as $name ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$name}{$suffix}`" );
    }
}

/**
 * Make next week's preferred list (preferred_new, built by schedule
 * production) the live preferred table, via build-then-swap:
 * copy into "{$live}_build", then one atomic RENAME TABLE. The live
 * table is never missing, and the source is left as-is.
 *
 * - Source has rows   -> swapped in.
 * - Source is empty   -> an EMPTY live table is swapped in (nobody was
 *                        deferred, so nobody gets priority next week).
 * - Source is missing -> live table left untouched (warning).
 * - Build/swap error  -> live table left untouched (red notice).
 *
 * Echoes its own status line. Table names are parameters only so the
 * test script can point it at throwaway tables.
 *
 * @return bool True if swapped in.
 */
function spp_promote_preferred_new( string $source = 'preferred_new', string $live = 'preferred' ) : bool {
    global $wpdb;
    $suffix = '_build';
    $build  = "{$live}{$suffix}";

    $source_exists = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $source
    ) );
    if ( ! $source_exists ) {
        echo '<p style="color:#e67e22;">⚠ ' . esc_html( "$source not found -- $live left unchanged." ) . '</p>';
        return false;
    }

    try {
        spp_drop_new_tables( array( $live ), $suffix ); // leftover from an interrupted run
        foreach ( array(
            "CREATE TABLE `$build` LIKE `$source`",
            "INSERT INTO `$build` SELECT * FROM `$source`",
        ) as $sql ) {
            $wpdb->query( $sql );
            if ( $wpdb->last_error ) {
                throw new RuntimeException( "$live build error: " . $wpdb->last_error );
            }
        }
        // Test hook: throw from here to simulate a mid-build failure.
        do_action( 'spp_preferred_table_built', $live );

        if ( ! spp_swap_in_new_tables( array( $live ), $suffix ) ) {
            throw new RuntimeException( "$live swap error: " . $wpdb->last_error );
        }
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$live`" );
        echo '<p style="color:#339966;">OK: ' . esc_html( "$live updated from $source ($count player(s))." ) . '</p>';
        return true;

    } catch ( Throwable $e ) {
        echo '<p style="color:red;">⚠ ' . esc_html( $e->getMessage() . " -- $live left unchanged." ) . '</p>';
        return false;

    } finally {
        spp_drop_new_tables( array( $live ), $suffix ); // no-op after a successful swap
    }
}
