<?php
/*================================================
# Blog Roles
# Defines blog_author and blog_moderator capability sets.
# - blog_author: auto-assigned to all active members (YrEndDt = current year Dec 31)
# - blog_moderator: manually assigned via WP admin user edit screen checkbox
================================================*/

/*------------------------------------------------
# Capability definitions
------------------------------------------------*/
function spp_blog_author_caps() {
    return [
        'read'               => true,
        'edit_posts'         => true,
        'delete_posts'       => true,
        'upload_files'       => true,
    ];
}

function spp_blog_moderator_caps() {
    return [
        'read'                    => true,
        'edit_posts'              => true,
        'delete_posts'            => true,
        'upload_files'            => true,
        'publish_posts'           => true,
        'edit_others_posts'       => true,
        'delete_others_posts'     => true,
        'edit_published_posts'    => true,
        'delete_published_posts'  => true,
    ];
}

/*------------------------------------------------
# Sync blog_author caps on login and profile update
# Active member = YrEndDt meta equals Dec 31 of current year
------------------------------------------------*/
function spp_sync_blog_author_caps( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    $active_yr_end = date('Y') . '-12-31';
    $yr_end_dt     = get_user_meta( $user_id, 'YrEndDt', true );
    $is_active     = ( $yr_end_dt === $active_yr_end );

    if ( $is_active ) {
        foreach ( spp_blog_author_caps() as $cap => $grant ) {
            $user->add_cap( $cap, $grant );
        }
    } else {
        foreach ( spp_blog_author_caps() as $cap => $grant ) {
            $user->remove_cap( $cap );
        }
    }
}
// Fires on login — $user_login and $user are passed, we need ID
add_action( 'wp_login', function( $user_login, $user ) {
    spp_sync_blog_author_caps( $user->ID );
}, 10, 2 );
// Fires on profile save
add_action( 'profile_update', 'spp_sync_blog_author_caps', 10, 1 );

/*------------------------------------------------
# blog_moderator checkbox on WP admin user edit screen
------------------------------------------------*/

// Display checkbox
function spp_moderator_checkbox( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $is_moderator = (bool) get_user_meta( $user->ID, 'spp_blog_moderator', true );
    ?>
    <h3><?php esc_html_e( 'SPP Blog', 'Divi' ); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="spp_blog_moderator"><?php esc_html_e( 'Blog Moderator', 'Divi' ); ?></label></th>
            <td>
                <input type="checkbox" name="spp_blog_moderator" id="spp_blog_moderator" value="1" <?php checked( $is_moderator ); ?> />
                <span class="description"><?php esc_html_e( 'Can review, edit, publish, and delete all blog posts.', 'Divi' ); ?></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'spp_moderator_checkbox' );
add_action( 'edit_user_profile', 'spp_moderator_checkbox' );

// Save checkbox and grant/revoke caps
function spp_save_moderator_checkbox( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! check_admin_referer( 'update-user_' . $user_id ) ) return;

    $user         = get_userdata( $user_id );
    $is_moderator = isset( $_POST['spp_blog_moderator'] ) && $_POST['spp_blog_moderator'] == '1';

    update_user_meta( $user_id, 'spp_blog_moderator', $is_moderator ? '1' : '' );

    if ( $is_moderator ) {
        foreach ( spp_blog_moderator_caps() as $cap => $grant ) {
            $user->add_cap( $cap, $grant );
        }
    } else {
        foreach ( spp_blog_moderator_caps() as $cap => $grant ) {
            $user->remove_cap( $cap );
        }
    }
}
add_action( 'personal_options_update', 'spp_save_moderator_checkbox' );
add_action( 'edit_user_profile_update', 'spp_save_moderator_checkbox' );
/*------------------------------------------------
# FluentForms post submission handler
# Form ID 3 — "Submit a Blog Post"
# Creates a pending post with the logged-in member as author
------------------------------------------------*/
add_action( 'fluentform/submission_inserted', function( $entryId, $formData, $form ) {
    if ( $form->id != 3 ) return;
    if ( ! is_user_logged_in() ) return;

    $title   = sanitize_text_field( $formData['input_text_1'] ?? '' );
    $content = wp_kses_post( $formData['description'] ?? '' );

    if ( empty( $title ) ) return;

    wp_insert_post( [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'pending',
        'post_author'  => get_current_user_id(),
        'post_type'    => 'post',
    ] );
}, 10, 3 );