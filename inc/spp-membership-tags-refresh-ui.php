<?php
/* =========================================================
   Membership Tags Refresh UI
   Version: 1.1.0
   Date: 2026-09-07
   Based on: Code Manager snippet "Membership tags table refresh"
   (CM208)

   Changes from 1.0.0:
   - SECURITY FIX (Tier 1 access-control audit): the 2026-09-06
     confirm-gate below added a propose/confirm step but no identity
     check and no nonce. Ultimate Member's restriction on this page's
     Main menu link (administrator+editor, confirmed from this week's
     UM menu audit) only hides that link; both the confirm screen and
     the real refresh-then-display action were reachable by anyone,
     logged in or not. Fixed with spp_is_admin_or_editor(), checked
     first thing in the add_shortcode() wrapper -- the same place this
     file's own 2026-09-06 note already said the gate belongs. A nonce
     (wp_nonce_field()/wp_verify_nonce(), action
     'spp_membership_tags_refresh_ui_action') added to the existing
     confirm form, required alongside
     'membership_tags_refresh_confirmed'.
   - No other behavior change.

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

   UPDATE (2026-09-06) -- added a confirm-gate for consistency with
   every other mutating admin tool in the project (CM66/CM176's
   incident fix, CM82, CM52, CM219, CM120). Not a response to any
   actual harm here -- spp_refresh_membership_tags() is documented
   idempotent/self-healing in its own file -- but a mutating admin
   tool shouldn't rely on being safe by luck when a real gate costs
   nothing. Gated rather than documented-as-exception (unlike
   CM102/CM254): this page ("Membership tags table refresh") is a
   dedicated tool deliberately visited to run a refresh, not a
   routine-viewing page, so a confirm step costs nothing in practice.

   spp_membership_tags_refresh_ui() has no internal callers besides
   its own add_shortcode() wrapper (confirmed by a fresh grep), so
   there's no competing caller to preserve -- the gate could have
   lived in the function body. Put it in the wrapper anyway, matching
   the uniform rule now used everywhere else in this project (gate
   always lives in the wrapper, function body never checks $_POST
   itself), rather than making the location depend on whether a
   caller happened to exist today. On a bare GET, still shows the
   current tags table (app_id 3) so there's something to look at
   before deciding to refresh; only a POST with
   membership_tags_refresh_confirmed=1 actually runs the sync first.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_membership_tags_refresh_ui() {
    spp_refresh_membership_tags();
    echo do_shortcode( '[wpda_app app_id="3"]' );
}

add_shortcode( 'spp_membership_tags_refresh_ui', function( $atts ) {
    // Administrator + editor, per this page's Ultimate Member menu
    // restriction -- confirmed from this week's UM menu audit. Checked
    // before the confirm-gate below, before anything else.
    if ( ! spp_is_admin_or_editor() ) {
        return '<p>You do not have permission to use this tool.</p>';
    }

    ob_start();

    // Nonce required alongside 'membership_tags_refresh_confirmed' --
    // closes the CSRF gap the existing confirm step didn't cover.
    $confirmed = isset( $_POST['membership_tags_refresh_confirmed'] ) && $_POST['membership_tags_refresh_confirmed'] === '1'
        && isset( $_POST['spp_membership_tags_refresh_ui_nonce'] )
        && wp_verify_nonce( $_POST['spp_membership_tags_refresh_ui_nonce'], 'spp_membership_tags_refresh_ui_action' );

    if ( $confirmed ) {
        spp_membership_tags_refresh_ui();
    } else {
        ?>
        <div style="max-width:600px;margin:0 auto 16px;font-family:Arial,sans-serif;">
            <div style="background:#f0f7ff;border:1px solid #3766AB;border-radius:6px;padding:16px;margin:16px 0;">
                <p>This will sync the MembershipTags table for any currently-untagged member.</p>
            </div>
            <form method="post">
                <?php wp_nonce_field( 'spp_membership_tags_refresh_ui_action', 'spp_membership_tags_refresh_ui_nonce' ); ?>
                <input type="hidden" name="membership_tags_refresh_confirmed" value="1">
                <button type="submit" style="padding:10px 24px;background:#3766AB;color:#fff;border:none;border-radius:4px;cursor:pointer;">Yes, Refresh Now</button>
            </form>
        </div>
        <?php
        echo do_shortcode( '[wpda_app app_id="3"]' );
    }

    return ob_get_clean();
} );
