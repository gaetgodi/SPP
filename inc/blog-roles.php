<?php
/*================================================
# Blog Roles
# Defines blog_author and blog_moderator capability sets.
# - blog_author: auto-assigned to all active members (YrEndDt = current year Dec 31)
# - blog_moderator: manually assigned via WP admin user edit screen checkbox
================================================*/

/*------------------------------------------------
# 1. Capability definitions
------------------------------------------------*/
function spp_blog_author_caps() {
    return [
        'read'          => true,
        'edit_posts'    => true,
        'delete_posts'  => true,
        'upload_files'  => true,
    ];
}

function spp_blog_moderator_caps() {
    return [
        'read'                   => true,
        'edit_posts'             => true,
        'delete_posts'           => true,
        'upload_files'           => true,
        'publish_posts'          => true,
        'edit_others_posts'      => true,
        'delete_others_posts'    => true,
        'edit_published_posts'   => true,
        'delete_published_posts' => true,
    ];
}

/*------------------------------------------------
# 2. blog_author — sync on login and profile update
# Active member = YrEndDt meta equals Dec 31 of current year
------------------------------------------------*/
function spp_sync_blog_author_caps( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    $is_active = ( get_user_meta( $user_id, 'YrEndDt', true ) === date('Y') . '-12-31' );

    foreach ( spp_blog_author_caps() as $cap => $grant ) {
        $is_active ? $user->add_cap( $cap, $grant ) : $user->remove_cap( $cap );
    }
}
add_action( 'wp_login', function( $user_login, $user ) {
    spp_sync_blog_author_caps( $user->ID );
}, 10, 2 );
add_action( 'profile_update', 'spp_sync_blog_author_caps' );

/*------------------------------------------------
# 3. blog_moderator — checkbox on WP admin user edit screen
------------------------------------------------*/
function spp_moderator_checkbox( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <h3><?php esc_html_e( 'SPP Blog', 'Divi' ); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="spp_blog_moderator"><?php esc_html_e( 'Blog Moderator', 'Divi' ); ?></label></th>
            <td>
                <input type="checkbox" name="spp_blog_moderator" id="spp_blog_moderator" value="1"
                    <?php checked( (bool) get_user_meta( $user->ID, 'spp_blog_moderator', true ) ); ?> />
                <span class="description"><?php esc_html_e( 'Can review, edit, publish, and delete all blog posts.', 'Divi' ); ?></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'spp_moderator_checkbox' );
add_action( 'edit_user_profile', 'spp_moderator_checkbox' );

function spp_save_moderator_checkbox( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! check_admin_referer( 'update-user_' . $user_id ) ) return;

    $user         = get_userdata( $user_id );
    $is_moderator = ! empty( $_POST['spp_blog_moderator'] );

    update_user_meta( $user_id, 'spp_blog_moderator', $is_moderator ? '1' : '' );

    foreach ( spp_blog_moderator_caps() as $cap => $grant ) {
        $is_moderator ? $user->add_cap( $cap, $grant ) : $user->remove_cap( $cap );
    }
}
add_action( 'personal_options_update',  'spp_save_moderator_checkbox' );
add_action( 'edit_user_profile_update', 'spp_save_moderator_checkbox' );

/*------------------------------------------------
# 4. blog_moderator — WP admin backend access
# Uses admin_init (not init) so caps are fully loaded before the check
------------------------------------------------*/
add_filter( 'show_admin_bar', function( $show ) {
    return current_user_can( 'publish_posts' ) ? true : $show;
} );

add_action( 'admin_init', function() {
    if ( ! current_user_can( 'publish_posts' ) && ! current_user_can( 'manage_options' ) ) {
        wp_redirect( home_url() );
        exit;
    }
} );

/*------------------------------------------------
# 5. Frontend post submission via FluentForms (Form ID 3)
# Creates a pending post with the logged-in member as author
------------------------------------------------*/
add_action( 'fluentform/submission_inserted', function( $entryId, $formData, $form ) {
    if ( $form->id != 3 || ! is_user_logged_in() ) return;

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

/*------------------------------------------------
# 6. Email all blog_moderators when a post is submitted (pending)
------------------------------------------------*/
add_action( 'transition_post_status', function( $new_status, $old_status, $post ) {
    if ( $new_status !== 'pending' || $old_status === 'pending' ) return;
    if ( $post->post_type !== 'post' ) return;

    $moderators = get_users( [ 'meta_key' => 'spp_blog_moderator', 'meta_value' => '1' ] );
    if ( empty( $moderators ) ) return;

    $author   = get_userdata( $post->post_author );
    $post_url = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
    $subject  = 'New blog post pending review: ' . $post->post_title;
    $message  = "A new blog post has been submitted for review.\n\n"
              . "Title: "  . $post->post_title     . "\n"
              . "Author: " . $author->display_name . "\n\n"
              . "Review it here: " . $post_url;

    foreach ( $moderators as $moderator ) {
        wp_mail( $moderator->user_email, $subject, $message );
    }
}, 10, 3 );

/*------------------------------------------------
# 7. Redirect non-logged-in users away from blog pages
------------------------------------------------*/
add_action( 'template_redirect', function() {
    if ( is_user_logged_in() ) return;

    $blog_page_id   = get_option( 'page_for_posts' );
    $submit_page_id = get_page_by_path( 'submit-post' )?->ID;

    if (
        ( $blog_page_id   && is_page( $blog_page_id ) ) ||
        ( $submit_page_id && is_page( $submit_page_id ) ) ||
        is_singular( 'post' )
    ) {
        wp_redirect( wp_login_url( get_permalink() ) );
        exit;
    }
} );