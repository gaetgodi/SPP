<?php
/* =========================================================
   Copy Ranks to User Profile
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Copy Ranks to user profile" (CM66)
   fresh-pulled tonight (1699 bytes,
   sha256 86fecfe9fe9381eedd9ad6d966c12f68f235754d65a2887ea29b5293cdbb030e).

   PURPOSE:
   Copies every current Results.Rank into usermeta (meta_key
   'Rank') for that user, then rebuilds the membership/Master table
   so the new ranks are reflected everywhere else on the site. Called
   from Apply Override Stage 2, right after the results email is
   sent, and from Update Club Ratings (CM284)'s own documentation as
   the snippet that must run immediately before it.

   FIXED (same bug shape as CM120/CM279/CM82/CM176, same decision
   already made tonight -- applied here without re-asking): the
   per-user cleanup was
     DELETE FROM usermeta WHERE meta_key LIKE '%Rank%' AND user_id=$id
   which also matches and deletes 'old_Rank' for that user, alongside
   the intended 'Rank'. Narrowed to an exact match on 'Rank'.

   BLAST RADIUS (checked against real, current data before drafting,
   same as the live-data check done for CM82): of the 168 users
   currently in the Results table (who this snippet would touch if
   run today), 0 currently carry an old_Rank usermeta row -- so
   today's Apply Override run would not have been affected. But this
   is not the full picture: old_Rank isn't tied to Results membership,
   it's tied to ladder history. Site-wide, 248 users carry an old_Rank
   row, and 149 of those are CURRENT active members (in the
   `membership` table) who simply didn't play in the most recently
   published event -- any one of them playing (and appearing in
   Results) in a future week would have had their real old_Rank
   silently wiped by this wildcard the next time this snippet ran.
   Same real, live risk class as the other four fixes; just not
   triggered by this week's specific data.

   Also hardened the previously raw, unescaped
   DELETE/INSERT ... "... user_id = $id" / "VALUES ($id, 'Rank', $Rank)"
   into $wpdb->prepare() calls, matching the standard hardening
   applied to every migrated snippet tonight.

   OBSERVED BUT HARMLESS, NOT CHANGED: the tmp-table filter
   "WHERE (t.Rank <> 0 OR t.Rank IS NOT NULL)" is dead by construction
   on the live schema -- Results.Rank is NOT NULL (default 0) and is
   always populated by CM52's own auto-increment rebuild, so no row
   in Results ever has Rank = 0 or NULL; confirmed against live data
   (0 such rows). The OR (rather than AND) also makes the "<> 0" half
   redundant on its own terms regardless. Preserved verbatim -- there
   is no realistic input that makes this clause behave differently
   fixed vs. as-is, so there is no real decision to make here, unlike
   the session_status()/old_Rank cases.

   CALLED FROM (as of this migration, verified exhaustively --
   theme/mu-plugin grep, Code Manager DB grep, and a full post_content
   scan, not just the earlier deep-dive):
     Internally, directly as spp_copy_ranks_to_user_profile(), from
     the already-migrated spp_apply_override_to_results_table()
     (CM52) -- that file has been updated to call this function
     directly instead of going through [cmruncode] (see its own
     changelog entry).
     Via [cmruncode name='Copy Ranks to user profile'] (CM66, now a
     transition shim around this function) directly on three pages:
     "Apply Override to Results table" (1591, orphaned -- 0 menu
     links, superseded by 20010176), "Copy Ranks to user profile"
     (1721, a dedicated standalone page, orphaned -- 0 menu links,
     last modified 2021-09-14), and "GL Publish Results after
     overrides" (20010176, the live menu-reachable one -- confirmed
     2 nav_menu_item pointers). All three keep working via the shim.
     "Publish Results" (1804) does NOT call CM66 directly (only calls
     CM52, which now calls this function internally).
     CM52's own disabled ancestor, "Gl apply override" (CM277,
     disabled), also calls it via [cmruncode] -- out of scope, matches
     the disabled/kept-as-fallback precedent already noted for CM278
     in CLAUDE.md.
     Mentions in inc/spp-create-results.php and
     inc/update_club_ratings.php, and in CM284's own header, are
     descriptive comments about call order, not actual invocations --
     confirmed by reading each in context.

   Changes from CM66: wrapped in a real function,
   spp_copy_ranks_to_user_profile(). Replaced
   `echo do_shortcode("[cmruncode name='Create membership table']")`
   with a direct call to spp_create_membership_table() (already
   migrated tonight), matching how gl-schedule-production.php and
   spp-score-correction.php already call it. No other behavior
   change: identical tmp-table build, identical loop, identical
   final message.

   UPDATE (2026-09-06) -- same missing-gate class as CM176's incident
   fix: this function itself had no request-method check, so a bare
   [spp_copy_ranks_to_user_profile] page view would run the full
   Rank-copy + membership rebuild unconditionally. Did NOT cause the
   CM176 incident (verified separately) and the function's effect
   looks idempotent (it re-copies Results.Rank into usermeta, then
   rebuilds Master from that) rather than destructive like CM176's
   Ladder wipe -- but same code-quality gap, fixed on the same
   principle rather than waited on.

   FIXED without changing this function's signature or behavior at
   all: spp_copy_ranks_to_user_profile() is called internally,
   unconditionally, from spp_apply_override_to_results_table()'s
   Stage 2 (already inside that caller's own confirmation gate) --
   adding a gate INSIDE this function would have broken that call.
   Instead, the confirm-gate lives only in the add_shortcode()
   wrapper below (the sole place a bare page view can reach this):
   on a plain GET it now shows a read-only preview (a COUNT of
   affected users, no writes) with a confirm button; only a POST
   with copy_ranks_confirmed=1 calls the real function. The function
   body itself, and every internal caller, are untouched.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_copy_ranks_to_user_profile() {

    global $wpdb;
    $prefix = $wpdb->prefix;
    $umetatable = $prefix . 'usermeta';
    $table_last = "Results"; // this is usually Results

    $wpdb->query( "DROP TABLE IF EXISTS tmp" );

    $wpdb->query( "create table tmp as
        select * from (
        select $table_last.Rank
        ,$table_last.user_id from $table_last
        ) t
        where (t.Rank <> 0 or t.Rank is not null)
        order by user_id
    " );

    $result = $wpdb->get_results( "Select * from tmp", ARRAY_A );
    foreach ( $result as $value ) {
        $id   = $value['user_id'];
        $Rank = $value['Rank'];

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$umetatable} WHERE user_id=%d AND meta_key='Rank'", $id ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d,'Rank',%s)", $id, $Rank ) );
    }

    $wpdb->query( "DROP TABLE IF EXISTS tmp" );

    spp_create_membership_table();

    echo "<br />All Ranks created or updated!";
}

add_shortcode( 'spp_copy_ranks_to_user_profile', function( $atts ) {
    ob_start();

    $confirmed = isset( $_POST['copy_ranks_confirmed'] ) && $_POST['copy_ranks_confirmed'] === '1';

    if ( ! $confirmed ) {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results WHERE (Rank <> 0 OR Rank IS NOT NULL)" );
        ?>
        <div style="max-width:600px;margin:20px auto;font-family:Arial,sans-serif;">
            <div style="background:#f0f7ff;border:1px solid #3766AB;border-radius:6px;padding:16px;margin:16px 0;">
                <p>This will copy the current Results.Rank into usermeta for <strong><?php echo $count; ?></strong> user(s), then rebuild the membership/Master tables.</p>
            </div>
            <form method="post">
                <input type="hidden" name="copy_ranks_confirmed" value="1">
                <button type="submit" style="padding:10px 24px;background:#3766AB;color:#fff;border:none;border-radius:4px;cursor:pointer;">Yes, Copy Ranks Now</button>
                <a href="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" style="margin-left:12px;color:#888;">Cancel</a>
            </form>
        </div>
        <?php
    } else {
        spp_copy_ranks_to_user_profile();
    }

    return ob_get_clean();
} );
