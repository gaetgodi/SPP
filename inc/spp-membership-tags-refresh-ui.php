<?php
/* =========================================================
   Membership Tags Refresh UI
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Membership tags table refresh"
   (CM208)

   PURPOSE:
   Admin-facing wrapper: refreshes MembershipTags (the same sync
   CM252/spp_refresh_membership_tags() performs), then displays the
   result via a WPDA app view (app_id 3) so an admin can review/edit
   tags immediately.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Membership tags table refresh'] (CM208,
     now a transition shim around this function): the page
     "Membership tags table refresh" (menu-reachable via Main,
     administrator+editor). Not touched by this migration -- keeps
     working via the shim.

   Changes from CM208:
   - Wrapped in a real function, spp_membership_tags_refresh_ui(),
     instead of a bare top-level script.
   - Replaced the entire duplicate sync logic (delete tag-less rows,
     select untagged membership rows, manually-quoted-and-unescaped
     INSERT via a locally-defined insertArr() helper -- the identical
     pattern CM252 was fixed for) with a direct call to
     spp_refresh_membership_tags(), the already-migrated function
     that performs the exact same sync via $wpdb->insert() (safe,
     parameterized). This is the whole reason CM252 was migrated
     first. Confirmed no other snippet calls insertArr() directly
     before dropping it entirely.
   - No other behavior change: the [wpda_app app_id="3"] display
     after the refresh is unchanged.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_membership_tags_refresh_ui() {
    spp_refresh_membership_tags();
    echo do_shortcode( '[wpda_app app_id="3"]' );
}
