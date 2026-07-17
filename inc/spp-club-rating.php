<?php
/* =========================================================
   SPP Club Rating
   Version: 1.0.0
   Date: 2026-07-17

   Computes a club-relative, continuous rating (2.000-5.000,
   3 decimal places, ties allowed) for every ranked player,
   derived from their current Master.Rank position relative
   to the club's own current rank distribution -- NOT from
   self-reported Rating, which is noisy (see spp-create-
   results.php shadow-dampening discussion).

   Anchor table (rank-position in ave/se units -> rating),
   same se-multiples originally derived for the get_rank()
   tier-midpoint correction:
     z=-7.25 -> 5.0   z=-5.25 -> 4.5   z=-3.25 -> 4.0
     z=-0.5  -> 3.5   z=+3.0  -> 3.0   z=+7.0  -> 2.5
     z=+9.0  -> 2.0
   Piecewise-linear between anchors; hard-clamped to
   [2.000, 5.000] for players beyond either end (no
   extrapolation past the club's own defined scale).

   Current value stored in usermeta 'ClubRating' (fast lookup
   for other tools, e.g. shadow dampening). Every computation
   run ALSO inserts a dated snapshot into ClubRating_history --
   nothing is overwritten there -- so a player's rating over
   time, club-wide caliber trends, and (someday) a mapping to
   an external scale like DUPR all become possible later
   without needing to have planned for it in advance.

   Trigger: no manual tool. spp_maybe_run_club_rating_refresh()
   hooks 'init' and fires automatically the first time anyone
   loads any page on or after July 31 or September 30 each
   year (America/Toronto), guarded so each window only runs
   once regardless of how much traffic hits in between.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'spp_maybe_run_club_rating_refresh' );

/* =========================================================
   ANCHOR TABLE + INTERPOLATION
   ========================================================= */

function spp_club_rating_anchors() {
    // Sorted by z ascending (better rank / lower z -> higher rating).
    return array(
        array( -7.25, 5.0 ),
        array( -5.25, 4.5 ),
        array( -3.25, 4.0 ),
        array( -0.5,  3.5 ),
        array(  3.0,  3.0 ),
        array(  7.0,  2.5 ),
        array(  9.0,  2.0 ),
    );
}

function spp_club_rating_from_z( $z ) {
    $anchors = spp_club_rating_anchors();

    if ( $z <= $anchors[0][0] )                    return 5.000;
    if ( $z >= $anchors[ count($anchors)-1 ][0] )   return 2.000;

    for ( $i = 0; $i < count( $anchors ) - 1; $i++ ) {
        [ $z_lo, $r_hi ] = $anchors[ $i ];
        [ $z_hi, $r_lo ] = $anchors[ $i + 1 ];
        if ( $z >= $z_lo && $z <= $z_hi ) {
            $t = ( $z - $z_lo ) / ( $z_hi - $z_lo );
            return round( $r_hi + ( $r_lo - $r_hi ) * $t, 3 );
        }
    }
    return 3.000; // unreachable given the clamps above, but a safe fallback
}

/* =========================================================
   COMPUTATION
   ========================================================= */

function spp_compute_club_ratings() {
    global $wpdb;

    $wpdb->query( "
        CREATE TABLE IF NOT EXISTS ClubRating_history (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            computed_date DATE NOT NULL,
            club_rating DECIMAL(5,3) NOT NULL,
            rank_at_computation INT NOT NULL,
            ave_at_computation DECIMAL(8,3) NOT NULL,
            se_at_computation DECIMAL(8,3) NOT NULL,
            INDEX (user_id),
            INDEX (computed_date)
        )
    " );

    $stats = $wpdb->get_row(
        "SELECT AVG(Rank) AS ave, STDDEV_SAMP(Rank)/SQRT(COUNT(*)) AS se, COUNT(*) AS n
         FROM Master WHERE Rank > 0", ARRAY_A
    );
    if ( empty( $stats ) || (int) $stats['n'] < 2 || empty( $stats['se'] ) ) {
        return array( 'computed' => 0, 'reason' => 'Not enough ranked players to compute club stats.' );
    }

    $ave = (float) $stats['ave'];
    $se  = (float) $stats['se'];

    $players = $wpdb->get_results( "SELECT user_id, Rank FROM Master WHERE Rank > 0", ARRAY_A );

    $today = current_time( 'Y-m-d' ); // America/Toronto via WP's timezone setting
    $computed = 0;

    foreach ( $players as $p ) {
        $rank = (int) $p['Rank'];
        $z = ( $rank - $ave ) / $se;
        $rating = spp_club_rating_from_z( $z );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}usermeta WHERE user_id = %d AND meta_key = 'ClubRating'", $p['user_id']
        ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}usermeta (user_id, meta_key, meta_value) VALUES (%d, 'ClubRating', %f)",
            $p['user_id'], $rating
        ) );

        $wpdb->insert( 'ClubRating_history', array(
            'user_id'              => $p['user_id'],
            'computed_date'        => $today,
            'club_rating'          => $rating,
            'rank_at_computation'  => $rank,
            'ave_at_computation'   => round( $ave, 3 ),
            'se_at_computation'    => round( $se, 3 ),
        ) );

        $computed++;
    }

    return array( 'computed' => $computed, 'ave' => $ave, 'se' => $se, 'date' => $today );
}

/* =========================================================
   AUTOMATIC BIANNUAL TRIGGER
   ========================================================= */

function spp_maybe_run_club_rating_refresh() {
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return; // avoid piling onto WP-Cron requests
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return; // only fire on ordinary page loads

    $today = current_time( 'Y-m-d' );
    $year  = current_time( 'Y' );

    $windows = array(
        "{$year}-07-31" => "club_rating_refresh_done_{$year}_07",
        "{$year}-09-30" => "club_rating_refresh_done_{$year}_09",
    );

    foreach ( $windows as $trigger_date => $option_key ) {
        if ( $today >= $trigger_date && ! get_option( $option_key ) ) {
            spp_compute_club_ratings();
            update_option( $option_key, current_time( 'mysql' ) );
        }
    }
}
