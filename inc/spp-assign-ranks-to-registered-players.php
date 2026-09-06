<?php
/* =========================================================
   GL Assign Ranks To Registered Players
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "GL Assign ranks to registered
   player" (CM279), version 1.2.0

   PURPOSE:
   The real, current "who's playing tonight and what's their rank"
   resolver for the live GL Events schedule-production pipeline.
   For the given event's confirmed registrants:
     1. Cleans up duplicate Rank/Ladder usermeta rows.
     2. Ensures every confirmed registrant has Ladder='Yes' usermeta
        and the WordPress 'Ladder' role.
     3. Assigns a -10 sentinel Rank to anyone with Rank<=0/missing.
     4. Rebuilds membership/Master (spp_create_membership_table())
        if anything changed, so step 5's JOIN sees current data.
     5. Builds reg{$event_id}: a per-event registrant+rank snapshot
        with an auto-increment Sequence.
     6. For anyone still unranked, restores old_Rank+bias where on
        file, otherwise hands off to spp_random_ranks() for a
        rating-based rank, then rebuilds membership/Master again.

   CALLED FROM (as of this migration):
     gl-schedule-production.php and spp-schedule-production.php,
     updated to call spp_assign_ranks_to_registered_players()
     directly as part of this migration -- these were the only two
     callers (re-confirmed fresh: no page, no other snippet calls
     this by name).

   Changes from CM279 v1.2.0:
   - Wrapped in a real function with an EXPLICIT parameter,
     spp_assign_ranks_to_registered_players( int $event_id ),
     instead of reading global $Event out of eval()'s shared scope.
     Traced both callers first: neither computes $Event itself --
     both inherit it from a Code Manager snippet ("Ladder Events
     drop down" family) that sets the bare global earlier in the
     same page render. Confirmed populated and numeric at both call
     sites before finalizing the signature; both now pass
     (int) $Event explicitly.
   - Calls spp_create_membership_table() and spp_random_ranks()
     directly instead of
     echo do_shortcode("[cmruncode name='Create membership table']")
     and echo do_shortcode("[cmruncode name='RandomRanks']").
     Confirmed both functions depend only on global $wpdb -- neither
     expected any state this file was implicitly providing via
     [cmruncode]'s shared eval() scope.
   - FIXED (by explicit decision, not silently, not a bug hunt --
     this one was already known-adjacent from the CM120 migration):
     Step 6's cleanup delete used meta_key LIKE '%Rank%', which also
     matches and deletes 'old_Rank' -- immediately before the very
     next lines try to read 'old_Rank' back to decide whether to
     restore it. That made the old_Rank-restore branch here
     permanently dead code, and guaranteed old_Rank was already gone
     by the time spp_random_ranks() runs right after this loop --
     pre-empting the old_Rank-restoration fix already made there.
     Narrowed to an exact match on 'Rank' so old_Rank survives long
     enough to actually be checked and used.
   - Steps 1 (duplicate Rank/Ladder cleanup) and 3 (-10 sentinel
     rank assignment) are UNCHANGED beyond direct translation to
     $wpdb->prepare() placeholders, per explicit instruction -- not
     bug-hunted, not touched beyond that.
   - Dropped an unused `global $name;` declaration (never read
     anywhere in CM279's body -- same class of harmless dead
     declaration already dropped from other migrated snippets) and
     the dead "if (!session_status() == PHP_SESSION_ACTIVE)
     session_start()" guard (always a no-op due to operator
     precedence; $_SESSION was never read anywhere in this snippet).
   - No other behavior change.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_assign_ranks_to_registered_players( int $event_id ) {
    global $wpdb;

    if ( $event_id === 0 ) {
        return;
    }

    $prefix     = $wpdb->prefix;
    $umetatable = $prefix . "usermeta";
    $table      = "reg" . $event_id;
    $membership = "membership";
    $bias       = 3;

    $wpdb->query( "DROP TABLE IF EXISTS $table" );

    // -- Count confirmed registrants from gl_registrations -------------------
    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT COUNT(*) AS entries
        FROM {$prefix}gl_registrations
        WHERE occurrence_id = %d AND status = 'confirmed'
    ", $event_id ), ARRAY_A );

    if ( ! $results ) {
        return;
    }

    $entries = $results[0]['entries'];
    if ( $entries <= 0 ) {
        return;
    }

    // -- Get all confirmed registrants ------------------------------------
    $all_confirmed = $wpdb->get_col( $wpdb->prepare( "
        SELECT user_id FROM {$prefix}gl_registrations
        WHERE occurrence_id = %d AND status = 'confirmed'
    ", $event_id ) );

    // -- Step 1: Clean up duplicate Rank and Ladder meta rows -- direct
    // translation only, per instruction; not bug-hunted.
    foreach ( $all_confirmed as $uid ) {
        $uid = (int) $uid;

        // Clean duplicate Rank rows -- keep highest non-zero value
        $rank_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT umeta_id, meta_value FROM $umetatable
            WHERE user_id = %d AND meta_key = 'Rank'
            ORDER BY CAST(meta_value AS SIGNED) DESC
        ", $uid ), ARRAY_A );
        if ( count( $rank_rows ) > 1 ) {
            foreach ( $rank_rows as $i => $row ) {
                if ( $i === 0 ) continue;
                $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE umeta_id = %d", $row['umeta_id'] ) );
            }
            echo "Cleaned duplicate Rank rows for user $uid.<br>";
        }

        // Clean duplicate Ladder rows -- keep 'Yes' if exists, else first
        $ladder_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT umeta_id, meta_value FROM $umetatable
            WHERE user_id = %d AND meta_key = 'Ladder'
            ORDER BY FIELD(meta_value, 'Yes', 'No')
        ", $uid ), ARRAY_A );
        if ( count( $ladder_rows ) > 1 ) {
            foreach ( $ladder_rows as $i => $row ) {
                if ( $i === 0 ) continue;
                $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE umeta_id = %d", $row['umeta_id'] ) );
            }
            echo "Cleaned duplicate Ladder rows for user $uid.<br>";
        }
    }

    // -- Step 2: Ensure Ladder = Yes for all confirmed registrants --------
    $new_ladder_users = [];
    foreach ( $all_confirmed as $uid ) {
        $uid = (int) $uid;
        $has_ladder = $wpdb->get_var( $wpdb->prepare( "
            SELECT meta_value FROM $umetatable
            WHERE user_id = %d AND meta_key = 'Ladder' AND meta_value = 'Yes'
        ", $uid ) );
        if ( empty( $has_ladder ) ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE user_id = %d AND meta_key = 'Ladder'", $uid ) );
            $wpdb->query( $wpdb->prepare( "INSERT INTO $umetatable (user_id, meta_key, meta_value) VALUES (%d, 'Ladder', 'Yes')", $uid ) );
            $my_user     = new WP_User( $uid );
            $my_user->add_role( 'Ladder' );
            $wp_user     = get_userdata( $uid );
            $player_name = $wp_user ? $wp_user->first_name . ' ' . $wp_user->last_name : "user ID $uid";
            echo "Added Ladder role to: $player_name<br>";
            $new_ladder_users[] = $uid;
        }
    }

    // -- Step 3: Assign starting rank to players with Rank=0 or missing --
    // Direct translation only, per instruction; not bug-hunted.
    $rank_fixed_users = [];
    foreach ( $all_confirmed as $uid ) {
        $uid      = (int) $uid;
        $rank     = $wpdb->get_var( $wpdb->prepare( "
            SELECT meta_value FROM $umetatable
            WHERE user_id = %d AND meta_key = 'Rank'
        ", $uid ) );
        $rank_int = (int) $rank;

        if ( $rank_int <= 0 || $rank === null ) {
            $new_rank = -10;
            $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE user_id = %d AND meta_key = 'Rank'", $uid ) );
            $wpdb->query( $wpdb->prepare( "INSERT INTO $umetatable (user_id, meta_key, meta_value) VALUES (%d, 'Rank', %d)", $uid, $new_rank ) );

            $wp_user     = get_userdata( $uid );
            $player_name = $wp_user ? $wp_user->first_name . ' ' . $wp_user->last_name : "user ID $uid";
            echo "Assigned starting rank ($new_rank) to: $player_name (user $uid)<br>";
            $rank_fixed_users[] = $uid;
        }
    }

    // -- Step 4: Rebuild membership ONCE before building reg{$event_id} --------
    // Required so new players appear in membership for the JOIN in Step 5.
    if ( ! empty( $new_ladder_users ) || ! empty( $rank_fixed_users ) ) {
        spp_create_membership_table();
    }

    // -- Step 5: Build reg{$event_id} from gl_registrations + membership -------
    $wpdb->query( $wpdb->prepare( "
        CREATE TABLE $table AS (
            SELECT
                DATE(r.registered_at) AS registration_date,
                m.Rank,
                r.user_id,
                m.first_name,
                m.last_name,
                m.user_phone,
                m.user_email,
                r.occurrence_id AS event_id,
                m.travel,
                0 AS Score
            FROM {$prefix}gl_registrations AS r
            LEFT OUTER JOIN $membership AS m ON r.user_id = m.user_id
            WHERE r.occurrence_id = %d AND r.status = 'confirmed'
            ORDER BY m.Rank
        )
    ", $event_id ) );

    $wpdb->query( "ALTER TABLE $table ADD COLUMN Sequence int(10) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST" );

    // -- Step 6: Handle remaining missing ranks via RandomRanks -----------
    $results      = $wpdb->get_results( "SELECT count(*) as missingRanks FROM $table WHERE Rank <= 0 OR Rank IS NULL", ARRAY_A );
    $missingRanks = $results[0]['missingRanks'];

    if ( $missingRanks > 0 ) {
        echo "<br>There are $missingRanks missing rank(s) — assigning random ranks:<br>";
        $results = $wpdb->get_results( "SELECT * FROM $table WHERE Rank <= 0 OR Rank IS NULL", ARRAY_A );

        foreach ( $results as $value ) {
            $user_id     = (int) $value['user_id'];
            $my_user     = new WP_User( $user_id );
            $player_name = trim( $value['first_name'] . " " . $value['last_name'] );
            if ( $player_name === '' ) {
                $wp_user     = get_userdata( $user_id );
                $wp_name     = $wp_user ? $wp_user->first_name . " " . $wp_user->last_name : "";
                $player_name = "Unknown (WP user: $wp_name, ID: $user_id)";
            }

            $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE user_id=%d AND meta_key LIKE '%%Ladder%%'", $user_id ) );
            $my_user->remove_role( "Ladder" );
            // FIXED: exact match on 'Rank', not CM279's '%Rank%' wildcard --
            // that also deleted 'old_Rank' before the check just below could
            // ever read it. See migration notes above.
            $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE user_id=%d AND meta_key = 'Rank'", $user_id ) );
            $wpdb->query( $wpdb->prepare( "INSERT INTO $umetatable (user_id, meta_key, meta_value) VALUES (%d, 'Ladder', 'Yes')", $user_id ) );

            $Rank     = -10;
            $old_rank = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM $umetatable WHERE user_id=%d AND meta_key = 'old_Rank'", $user_id ), ARRAY_A );
            if ( ! empty( $old_rank ) ) {
                $Rank = $old_rank[0]['meta_value'] + $bias;
                $wpdb->query( $wpdb->prepare( "DELETE FROM $umetatable WHERE user_id=%d AND meta_key = 'Rank'", $user_id ) );
                echo "Missing rank — assigning rank from old rank ($Rank) to: $player_name (user ID: $user_id)<br>";
            } else {
                echo "Missing rank — assigning random rank to: $player_name (user ID: $user_id)<br>";
            }

            $wpdb->query( $wpdb->prepare( "INSERT INTO $umetatable (user_id, meta_key, meta_value) VALUES (%d, 'Rank', %s)", $user_id, $Rank ) );
            $my_user->add_role( "Ladder" );
        }

        // -- Rebuild via RandomRanks then membership ONCE after -------------------
        spp_random_ranks();
        spp_create_membership_table();
    }
}
