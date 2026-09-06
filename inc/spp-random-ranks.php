<?php
/* =========================================================
   Random Ranks
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "RandomRanks" (CM120)

   PURPOSE:
   Assigns a real Rank to every player in Master currently parked
   at a negative sentinel rank (Rank < 0 AND Ladder = 'Yes') --
   the state gl-schedule-production.php's own pipeline (via CM279
   "GL Assign ranks to registered player") leaves a genuinely new
   or previously-dropped registrant in once it can't resolve a
   rank any other way. For each such player:
     - If they have an old_Rank usermeta value (a returning player
       who was previously removed from the ladder), restore
       old_Rank + a 3-point bias.
     - Otherwise, compute a rating-tier-based random rank against
       the current live Master population stats (mean/stddev of
       Rank among players with Rank > 0), via
       spp_random_rank_from_rating().
   Writes: usermeta (Rank, Ladder, and -- only for the no-old_Rank
   path -- a default Rating of 3 if one is missing).

   CALLED FROM (as of this migration):
     Via [cmruncode name='RandomRanks'] only (CM120, now a
     transition shim around this function): CM131, CM148, CM279,
     and the page "Random Ranks". None of these have been touched
     by this migration -- they keep working unchanged via the shim
     and get updated individually as their own turn in the
     migration order comes up. (No theme file calls this directly
     today, so there's no direct caller to switch over in this
     step, unlike CM102's migration.)

   Changes from CM120:
   - Wrapped in a real function, spp_random_ranks(), instead of a
     bare top-level script -- directly callable from tracked PHP.
     The inner rank-band lookup (formerly a nested `get_rank()`
     guarded by function_exists(), needed under Code Manager's
     eval() re-run model) is now its own top-level, properly
     prefixed function, spp_random_rank_from_rating() -- no guard
     needed since this file is require_once'd exactly once.
   - FIXED (per explicit decision, not a silent change): the
     old_Rank restore branch never actually took effect in CM120.
     After the if(old_Rank)/else block, CM120's code unconditionally
     re-queried Rating, computed a fresh rating-based Rank_calc, and
     overwrote whatever the old_Rank branch had just inserted a few
     lines earlier -- so "restore old_Rank + bias for a returning
     player" was dead code; every player got a fresh random rank
     regardless of old_Rank. The old_Rank branch now `continue`s
     after persisting its result, so it actually takes effect.
   - FIXED (same decision): the old_Rank branch's cleanup delete
     used $user_id, not the loop variable $id -- $user_id wasn't
     assigned yet on first iteration and held the *previous*
     iteration's user afterward, so this could silently delete a
     different, already-processed player's Rank usermeta row
     (which drops that player out of Master on the next "Create
     membership table" run, since NULL Rank fails the Rank <> 0
     filter). Now uses $id throughout, matching the row actually
     being processed.
   - Tightened the final cleanup delete from
     "meta_key LIKE '%Rank%'" (which also matched old_Rank) to an
     exact match on 'Rank' -- safe now that old_Rank is handled and
     `continue`s in its own branch above, so the only rows reaching
     this delete are users confirmed to have no old_Rank meta.
   - Guarded the rand($min, $max) call: CM120 could pass $min > $max
     into rand() for a rating that doesn't match one of the named
     tiers (falls into the `default` case, $max = $num-10,
     $min = $num-20), which throws a ValueError in PHP 8 when the
     player population is small. Now swapped if reversed instead of
     left to crash. This is a real, live risk given the self-service
     DUPR rating field (2.000-8.000, continuous) added recently --
     ratings no longer reliably land on 2/2.5/3/3.5/4/4.5/5.
   - Corrected a stale/misleading comment: CM120 said "to have Rank
     re-calculated make it 0" next to a query that actually checks
     `Rank < 0`, not `Rank = 0`. Comment now matches the real
     condition; behavior unchanged (still Rank < 0).
   - Dropped an unused variable ($sd, the raw stddev) that CM120
     computed but never read -- only a commented-out alternate
     formula referenced it.
   - No other behavior change. Confirmed independently that the
     switch's case 2.5 has its break statement intact (a past bug
     documented in spp-schedule-adjust.php's own changelog --
     "missing break on case 2.5 restored" -- describing a fix
     already applied directly to CM120 at some point; not present
     in the version migrated here).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_random_rank_from_rating( $rating, $ave, $se, $num ) {
    switch ( $rating ) {
        case 2:
            $max = $num;
            $min = $ave + 6 * $se;
            break;
        case 2.5:
            $max = $ave + 8 * $se;
            $min = $ave + 6 * $se;
            break;
        case 3:
            $max = $ave + 4 * $se;
            $min = $ave + 2 * $se;
            break;
        case 3.5:
            $max = $ave + $se;
            $min = $ave - 2 * $se;
            break;
        case 4:
            $max = $ave - 2.5 * $se;
            $min = $ave - 4 * $se;
            break;
        case 4.5:
            $max = $ave - 4.5 * $se;
            $min = $ave - 6 * $se;
            break;
        case 5:
        case 'Professional':
            $max = $ave - 6.5 * $se;
            $min = $ave - 8 * $se;
            break;
        default:
            $max = $num - 10;
            $min = $num - 20;
    }

    $min = (int) round( $min );
    $max = (int) round( $max );
    if ( $min > $max ) {
        // A rating that doesn't land cleanly in one of the named tiers
        // (e.g. a continuous DUPR value) can send $min above $max here,
        // especially with a small player population -- rand() throws
        // on that in PHP 8. Swap rather than crash.
        [ $min, $max ] = [ $max, $min ];
    }

    $r = rand( $min, $max );
    return min( $num, max( 5, $r ) );
}

function spp_random_ranks() {
    global $wpdb;

    $master     = "Master";
    $umetatable = $wpdb->prefix . "usermeta";
    $bias       = 3;

    $stats = $wpdb->get_results(
        "SELECT avg(Rank) as ave, STDDEV_SAMP(Rank)/sqrt(count(*)) as se, count(*) as numplayers
         FROM {$master} WHERE Rank > 0 AND Rank IS NOT NULL",
        ARRAY_A
    );
    $numP = $stats[0]['numplayers'];
    $ave  = $stats[0]['ave'];
    $se   = $stats[0]['se'];

    // Players parked at a negative sentinel rank, still on the ladder.
    // (Rank < 0 is the real condition -- setting Rank to exactly 0 does
    // NOT trigger recalculation here.)
    $needs_rank = $wpdb->get_results(
        "SELECT user_id, Rank, Ladder FROM {$master} WHERE Rank < 0 AND Ladder LIKE 'Yes'",
        ARRAY_A
    );

    if ( empty( $needs_rank ) ) {
        return;
    }

    foreach ( $needs_rank as $row ) {
        $id = (int) $row['user_id'];

        // Returning player: restore old_Rank + bias if we have one on file.
        $old_rank_row = $wpdb->get_results(
            $wpdb->prepare( "SELECT meta_value FROM {$umetatable} WHERE user_id=%d AND meta_key='old_Rank'", $id ),
            ARRAY_A
        );

        if ( ! empty( $old_rank_row ) ) {
            $rank = $old_rank_row[0]['meta_value'] + $bias;

            $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $id ) );
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Rank',%s)", $id, $rank ) );

            $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Ladder'", $id ) );
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Ladder','Yes')", $id ) );

            echo "<br>User: $id, restored old rank: $rank<br>";
            continue; // old_Rank now actually takes effect -- skip the rating-based calc below.
        }

        // No old_Rank on file -- ensure a default Rating exists, then
        // compute a rating-tier-based random rank.
        $rating = $wpdb->get_var(
            $wpdb->prepare( "SELECT meta_value FROM {$umetatable} WHERE user_id=%d AND meta_key='Rating'", $id )
        );
        if ( empty( $rating ) ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Rating','3')", $id ) );
            $rating = '3';
        }

        $rank_calc = spp_random_rank_from_rating( $rating, round( $ave ), round( $se ), $numP );
        echo "<br>User: $id, Rating: $rating, Calculated Rank: $rank_calc<br>";

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Ladder'", $id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Ladder','Yes')", $id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Rank',%s)", $id, $rank_calc ) );
    }
}

add_shortcode( 'spp_random_ranks', function( $atts ) {
    ob_start();
    spp_random_ranks();
    return ob_get_clean();
} );
