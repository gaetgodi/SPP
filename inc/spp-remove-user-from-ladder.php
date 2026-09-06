<?php
/* =========================================================
   Remove User From Ladder
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Remove user from Ladder" (CM82)

   PURPOSE:
   Manual, single-user equivalent of
   spp_remove_inactive_ladder_users() (CM176's migration): an admin
   picks a player from a dropdown and submits, which sets
   Ladder='No', archives their current Rank to old_Rank usermeta,
   and strips the WP 'Ladder' role. Renders a select-and-submit
   form built from Masterlist{year} (the yearly Master snapshot,
   not the live Master table -- preserved as-is; both are rebuilt
   together by spp_create_membership_table() so they're normally in
   sync, but this does mean a change to Master since the last
   rebuild wouldn't show here until the next rebuild runs).

   CALLED FROM (as of this migration):
     Via [cmruncode name='Remove user from Ladder'] (CM82, now a
     transition shim around this function): the page "Remove user
     from Ladder" (menu-reachable via Main, administrator+editor).
     Not touched by this migration -- keeps working via the shim.

   Changes from CM82:
   - Wrapped in a real function, spp_remove_user_from_ladder(),
     instead of a bare top-level script.
   - Narrowed the Ladder-meta delete from "meta_key LIKE '%Ladder%'"
     to an exact match on 'Ladder' -- audited fresh: 'Ladder' is the
     only usermeta key matching that wildcard site-wide.
   - FIXED (per the standing decision from CM176's identical bug,
     not re-asked): the old_Rank INSERT had no DELETE-before-INSERT
     guard -- the exact same gap CM176 had. Unlike CM176 (dormant at
     migration time), this one is live: 248 usermeta rows currently
     have an old_Rank value, and this tool can be pointed at any of
     them at any time (a previously-removed-and-rejoined player,
     removed again). Added a DELETE FROM usermeta WHERE
     meta_key='old_Rank' before the INSERT.
   - No other behavior change. Not fixed, noted only: the original's
     final `header("Refresh:0")` runs after this snippet's own
     leading `echo "<pre>"` has already sent output, so it could
     never actually have worked (PHP silently ignores/warns on
     header() after output has started) -- preserved verbatim,
     including the `<pre>` tag left open with no matching close, for
     the same "faithful translation" reasoning as CM176.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_remove_user_from_ladder() {
    global $wpdb;

    echo '<pre>';

    $prefix        = $wpdb->prefix;
    $umetatable    = $prefix . 'usermeta';
    $ladder        = 'Masterlist' . date( 'Y' );
    $playersnorank = 'PlayersNoRank';
    $user_name     = '';

    $sql_all = "select * from $ladder where $ladder.Ladder like 'Yes' order by last_name, first_name";
    $all     = $wpdb->get_results( $sql_all, ARRAY_A );

    $results = [];
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['User'] ) ) {
        $user = (int) $_POST['User'];
        $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$prefix}users WHERE ID = %d", $user ), ARRAY_A );
    }

    if ( ! empty( $results ) ) {
        $user_id   = (int) $results[0]['ID'];
        $my_user   = new WP_User( $user_id );
        $my_user->remove_role( 'Ladder' );
        $user_name = $user_id . ' ' . $results[0]['display_name'] . ' removed from Ladder, refreshing master list';

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Ladder'", $user_id ) );

        $rank_row = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $user_id ), ARRAY_A );
        $rank     = $rank_row[0]['meta_value'] ?? null;

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $user_id ) );

        // Fix: clear any existing old_Rank before archiving, so removing
        // the same player a second time never leaves two old_Rank rows.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='old_Rank'", $user_id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'old_Rank',%s)", $user_id, $rank ) );

        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Ladder','No')", $user_id ) );

        $table_exists = $wpdb->query( "SHOW TABLES LIKE '$playersnorank'" );
        if ( $table_exists ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$playersnorank} WHERE user_id=%d", $user_id ) );
            header( 'Refresh:0' );
        }
    }

    echo "<br />$user_name";

    if ( ! empty( $results ) ) {
        $all = $wpdb->get_results( $sql_all, ARRAY_A );
    }
    ?>
    <form id="User" name="User" method="post">
        <label for="User">Remove from Ladder table:</label>
        <select name="User">
            <option value="0">--- select ---</option>
            <?php foreach ( $all as $some ) : ?>
                <option value="<?php echo (int) $some['user_id']; ?>">
                    <?php echo esc_html( $some['user_id'] . ' ' . $some['last_name'] . ', ' . $some['first_name'] . ' Ladder=' . $some['Ladder'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="submit" name="submit" value="Submit">
    </form>
    <?php
}
