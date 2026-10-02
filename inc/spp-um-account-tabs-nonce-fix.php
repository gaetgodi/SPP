<?php
/* =========================================================
   UM Account "My Profile" tab -- profile nonce compatibility fix
   Version: 1.0.0
   Date: 2026-10-01

   1.0.0 (initial):
   Ultimate Member 2.14.0 (auto-updated 2026-09-30) changed
   um_profile_validate_nonce() to verify the profile_nonce against
   'um-profile-nonce' . form_id . user_id. The um-account-tabs-main
   add-on (v1.0.5) still renders it as 'um-profile-nonce' . user_id
   in its embedded profile form, so every save of the Account page's
   "My Profile" tab (/account/my-profile/, um_account_tabs post
   20010457, embedding UM form 20001281) hit UM's wp_die() --
   a silent HTTP 500, for every role, not a capability problem.

   Fixed here instead of patching the add-on (a plugin update would
   silently overwrite a direct patch): runs right after the add-on's
   own um_account_content_hook_my-profile filter (priority 10) and
   swaps the rendered profile_nonce value for one in the format UM
   2.14.0 actually validates. UM's own validation is left untouched
   at full strength.

   Tightly scoped: only this one tab, only when the rendered form_id
   is 20001281. If the add-on ever fixes its own nonce, this just
   replaces a valid value with an equally valid one; if its markup
   changes, the regex stops matching and the output passes through
   unchanged. Either way this file can then be deleted.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

define( 'SPP_UM_ACCOUNT_PROFILE_FORM_ID', 20001281 );

add_filter( 'um_account_content_hook_my-profile', 'spp_um_account_tabs_fix_profile_nonce', 11 );

function spp_um_account_tabs_fix_profile_nonce( $output ) {
    $user_id = get_current_user_id();
    if ( ! $user_id || ! is_string( $output ) ) {
        return $output;
    }

    if ( ! preg_match( '/name="form_id"[^>]*value="(\d+)"/', $output, $m )
        || (int) $m[1] !== SPP_UM_ACCOUNT_PROFILE_FORM_ID ) {
        return $output;
    }

    $nonce = wp_create_nonce( 'um-profile-nonce' . SPP_UM_ACCOUNT_PROFILE_FORM_ID . $user_id );

    return preg_replace(
        '/(<input type="hidden" name="profile_nonce" value=")[^"]*(")/',
        '${1}' . esc_attr( $nonce ) . '${2}',
        $output,
        1
    );
}
