<?php
/**
 * SPP Blog Reminder Modal
 * File: spp-blog-reminder.php
 * Location: wp-content/themes/divi-spp-child/inc/
 *
 * Shortcode: [spp_blog_reminder]
 *
 * Shows a dismissible modal on the home page on first login of the day,
 * OR when a new post has been published since the last time the modal was seen.
 * Uses a cookie (spp_blog_seen) storing date|latest_post_id.
 *
 * Version: 1.3.0
 * Date: 2026-09-20
 *
 * Changes from 1.2.0 (real usage feedback, reviewed and approved --
 * fully disable, no longer needed now that recent blog posts are shown
 * directly on most pages):
 * - spp_blog_reminder_shortcode() now returns '' unconditionally, as
 *   its very first line, before the 1.2.0 window check or any of the
 *   1.0.0 post-query/cookie logic runs. Gated at the shortcode itself
 *   rather than by removing [spp_blog_reminder] from the Home page
 *   (post ID 20008866) it's actually embedded on: guarantees the modal
 *   can never render regardless of WHERE the shortcode is placed,
 *   present or future, without needing to hunt down every embed
 *   location -- a stronger guarantee than removing one known embed, and
 *   avoids touching page content (Divi block markup) for a pure
 *   theme-code change.
 * - The window mechanism itself (spp_blog_reminder_window_active(),
 *   spp_blog_reminder_activate_window()/its transition_post_status
 *   hook, the spp_blog_reminder_enabled_until option) is DELIBERATELY
 *   left completely untouched -- still fires on every real publish,
 *   still updates the option -- it's simply never read by anything
 *   user-facing any more. Harmless sitting dormant; not deleted in case
 *   a future need for a similar window-gated notice wants to reuse the
 *   pattern.
 *
 * Changes from 1.1.0:
 * - Global on/off window, gated by a new spp_blog_reminder_enabled_until
 *   option (a Unix timestamp: "the reminder is active until this time" --
 *   absent/0 or in the past means off). Defaults to off (option is never
 *   pre-populated; a fresh install/site has no active window until the
 *   first real publish). spp_blog_reminder_activate_window() hooks
 *   transition_post_status for post_type 'post', $new_status==='publish'
 *   AND $old_status!=='publish' (a genuine go-live, not a re-save of an
 *   already-published post -- same "only the real transition, not every
 *   edit" discipline this file's own moderator-notify hook uses in
 *   inc/blog-roles.php) -- sets the option to now + 2 days every time.
 *   The shortcode checks this window FIRST, before the existing post
 *   query/cookie logic runs at all: outside the window the modal never
 *   renders, regardless of whether there is technically an unseen post.
 *   Inside the window, every existing behavior (3-most-recent-posts
 *   query, once-per-day-or-new-post cookie tracking) is unchanged.
 *   No new admin UI for a manual override -- there is no existing
 *   blog-admin settings screen this would obviously belong on (checked
 *   before building this); an administrator can flip it manually via
 *   `wp option update spp_blog_reminder_enabled_until <unix-timestamp>`
 *   (or `0` to force it off) in the meantime.
 *
 * Changes from 1.0.0:
 * - Cookie now stores date|latest_post_id so modal re-shows when a new
 *   post is published, even if already seen today.
 */

/**
 * The global on/off gate (see this file's own 1.2.0 changelog). Absent,
 * 0, or in the past all mean "off." Checked first in the shortcode below,
 * before any of the existing post-query/cookie logic runs.
 */
function spp_blog_reminder_window_active() : bool {
    $enabled_until = (int) get_option( 'spp_blog_reminder_enabled_until', 0 );
    return $enabled_until > time();
}

/**
 * Opens (or extends) the reminder window to 2 days from right now.
 * Hooked to transition_post_status below -- fires only on a genuine
 * publish (a post that was NOT already 'publish' just became it), so
 * re-saving an already-published post never resets the window.
 */
function spp_blog_reminder_activate_window( string $new_status, string $old_status, WP_Post $post ) : void {
    if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
    if ( $post->post_type !== 'post' ) return;

    update_option( 'spp_blog_reminder_enabled_until', time() + 2 * DAY_IN_SECONDS );
}
add_action( 'transition_post_status', 'spp_blog_reminder_activate_window', 10, 3 );

add_shortcode( 'spp_blog_reminder', 'spp_blog_reminder_shortcode' );
function spp_blog_reminder_shortcode() {
    // 1.3.0: fully disabled -- no longer needed now that recent blog
    // posts are shown directly on most pages. See this file's own
    // 1.3.0 changelog for why this is gated HERE rather than by
    // removing the shortcode from the Home page, and why the window
    // mechanism above (spp_blog_reminder_window_active()/spp_blog_
    // reminder_activate_window()) is left dormant rather than deleted.
    return '';
}

// -------------------------------------------------------
// Blog expiry — daily cron to auto-draft expired posts
// -------------------------------------------------------
if ( ! wp_next_scheduled( 'spp_blog_expiry_check' ) ) {
    wp_schedule_event( time(), 'daily', 'spp_blog_expiry_check' );
}

add_action( 'spp_blog_expiry_check', 'spp_blog_expire_posts' );
function spp_blog_expire_posts() {
    $today = date( 'Y-m-d' );
    $expired = get_posts( array(
        'post_type'   => 'post',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => array(
            array(
                'key'     => 'spp_blog_expiry',
                'value'   => $today,
                'compare' => '<',
                'type'    => 'DATE',
            ),
        ),
    ) );

    foreach ( $expired as $post ) {
        wp_update_post( array(
            'ID'          => $post->ID,
            'post_status' => 'draft',
        ) );
    }
}

// -------------------------------------------------------
// Blog expiry meta field — shown in post editor
// -------------------------------------------------------
add_action( 'add_meta_boxes', 'spp_blog_expiry_meta_box' );
function spp_blog_expiry_meta_box() {
    add_meta_box(
        'spp_blog_expiry',
        'Blog Post Expiry Date',
        'spp_blog_expiry_meta_box_html',
        'post',
        'side',
        'default'
    );
}

function spp_blog_expiry_meta_box_html( $post ) {
    $expiry = get_post_meta( $post->ID, 'spp_blog_expiry', true );
    wp_nonce_field( 'spp_blog_expiry_nonce', 'spp_blog_expiry_nonce' );
    ?>
    <p style="margin:0 0 6px;font-size:12px;color:#666;">
        Leave blank for no expiry. Post will be auto-drafted on this date.
    </p>
    <input type="date"
           id="spp_blog_expiry"
           name="spp_blog_expiry"
           value="<?php echo esc_attr( $expiry ); ?>"
           style="width:100%;">
    <?php if ( $expiry ): ?>
    <p style="margin:6px 0 0;font-size:12px;color:#c0392b;">
        Expires: <?php echo date( 'F j, Y', strtotime( $expiry ) ); ?>
    </p>
    <?php endif; ?>
    <?php
}

add_action( 'save_post', 'spp_blog_expiry_save_meta' );
function spp_blog_expiry_save_meta( $post_id ) {
    if ( ! isset( $_POST['spp_blog_expiry_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['spp_blog_expiry_nonce'], 'spp_blog_expiry_nonce' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['spp_blog_expiry'] ) && ! empty( $_POST['spp_blog_expiry'] ) ) {
        update_post_meta( $post_id, 'spp_blog_expiry', sanitize_text_field( $_POST['spp_blog_expiry'] ) );
    } else {
        delete_post_meta( $post_id, 'spp_blog_expiry' );
    }
}