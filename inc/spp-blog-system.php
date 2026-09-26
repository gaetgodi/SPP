<?php
/**
 * SPP Blog System
 * File: inc/spp-blog-system.php
 * Version: 1.8.0
 * Date: 2026-09-26
 *
 * Changes from 1.7.0:
 * - "All Categories" view at /all-categories/: every live post across
 *   all categories, newest first, rendered by category.php (1.2.0) with
 *   its own loop. Routed through the `request` filter (no rewrite rule,
 *   nothing to flush); the query is post_type=post, publish, no paging
 *   limit (category.php has no pagination), and functions.php's existing
 *   category-archive pre_get_posts limit now also covers it. Never sets
 *   cat/category_name, so the Categories-for-Pages plugin can't add pages.
 * - Divi Theme Builder would otherwise treat it as the posts home and
 *   apply template 20009628's body layout; et_theme_builder_template_
 *   layouts drops only that body override for this view (header/footer
 *   unchanged -- identical to what a category archive gets).
 * - The /blog/ strip leads with "All Categories (N)", N = live posts
 *   counted by spp_blog_live_category_count() with no term (null).
 *
 * Changes from 1.6.0:
 * - Moderator "+ Add new category" under the category multi-select on
 *   /edit-post/ ([spp_blog_edit]) and on every /pending-posts/ card
 *   ([spp_pending_posts]): reveals a name field + Add, creates the
 *   category over AJAX (spp_add_blog_category, wp_insert_term) and
 *   selects it in place -- no reload. A name matching an existing
 *   category case-insensitively (or colliding on slug) selects that
 *   one instead. Gated by spp_can_moderate_blog() on both the screens
 *   and the handler; never on [spp_blog_submit]. On pending-posts the
 *   select fires its usual change event, so the existing auto-save
 *   stores the new selection; edit-post still saves on submit.
 * - /blog/ category filter strip: links to each /category/<slug>/
 *   page with at least one live post, injected directly above the
 *   page's Divi Blog module via render_block (page content and module
 *   settings untouched).
 * - spp_blog_live_category_count() / spp_blog_live_categories(): the
 *   live-post count SQL, moved verbatim from single.php so the strip
 *   and single.php (1.4.0) share it.
 * - New css/spp-blog-categories.css, enqueued here, holds the pill
 *   styles (moved from single.php), the strip and the add control.
 *
 * Changes from 1.5.0:
 * - All six moderation gates (spp_blog_edit_shortcode,
 *   spp_pending_posts_shortcode, spp_ajax_publish_post,
 *   spp_ajax_save_pending_post, spp_ajax_reject_post,
 *   spp_ajax_delete_post) now use spp_can_moderate_blog()
 *   (functions.php): administrator or the blog_moderator role, NOT
 *   editor. 1.5.0's spp_is_admin_or_editor() locked out blog_moderator
 *   -- the role built for this -- while letting editor in. The 9/7
 *   role-level fix (publish_posts removed from subscriber) is what
 *   closed the original hole and is untouched; a plain subscriber
 *   holds neither role and stays denied.
 *
 * Changes from 1.4.1:
 * - SECURITY FIX (Tier 2 access-control audit, item 5): every
 *   current_user_can( 'publish_posts' ) check in this file (six of
 *   them -- spp_blog_edit_shortcode, spp_pending_posts_shortcode,
 *   spp_ajax_publish_post, spp_ajax_save_pending_post,
 *   spp_ajax_reject_post, spp_ajax_delete_post) replaced with
 *   spp_is_admin_or_editor(). Root cause confirmed directly, not
 *   assumed: the 'subscriber' role itself carries 'publish_posts' as
 *   a base WordPress capability site-wide (get_role('subscriber')
 *   ->capabilities), unrelated to spp_sync_blog_author_caps() --
 *   every logged-in member, active or not, satisfied the old check.
 *   Empirically confirmed against a fresh, never-active subscriber
 *   test account: full access to /pending-posts/ moderation. The four
 *   AJAX handlers already had correct nonces (spp_pending_action /
 *   spp_delete_post) -- only the role check was wrong in all six
 *   places. No internal callers found for any of the six (confirmed
 *   fresh).
 *
 * Changes from 1.4.0:
 * - Edit form now returns to /pending-posts/ for pending posts and
 *   /blog/ for published posts (was always /blog/). Cancel link matches.
 *
 * Changes from 1.3.0:
 * - Blog edit form: replaced wp_editor with spp_rich_editor component
 *   (wp_editor fails to render on front-end shortcode context).
 * - Pending-review cards: added "Edit" link to /edit-post/?post_id=N
 *   so moderators can jump straight into the full editor.
 *
 * Changes from 1.2.0:
 * - Blog submit form: replaced wp_editor with spp_rich_editor component.
 *
 * Changes from 1.1.1:
 * - Added [spp_blog_edit] shortcode for frontend post editing
 *   Moderators/admins only, pre-populated form, redirects to /blog/ on save
 *
 * Changes from 1.1.0:
 * - Added spp_delete_post AJAX handler for published post deletion
 *
 * Changes from 1.0.0:
 * - Category select now supports multiple selections
 * - Category and expiry auto-save via AJAX when changed
 * - AJAX handler updated to accept array of category IDs
 *
 * Shortcodes:
 *   [spp_blog_submit]    — frontend blog post submission form for all logged-in users
 *   [spp_blog_edit]      — frontend edit form for moderators/admins (reads ?post_id=)
 *   [spp_pending_posts]  — frontend moderator review interface (blog_moderator only)
 *   (no shortcode)       — /blog/ category strip, via render_block on the Divi Blog module
 *
 * Features:
 *   - Author submits title, content, category, optional expiry date
 *   - Post created as pending, spp_blog_expiry meta saved
 *   - Moderator reviews entirely on frontend — no wp-admin needed
 *   - Moderator can edit category and expiry before publishing
 *   - Moderator can edit full content via Edit link
 *   - Publish/Reject via AJAX — no page reload
 *   - Category reset bug eliminated — wp-admin never touched
 *   - Email notification to blog_moderator on new submission
 */

// ============================================================
// [spp_blog_submit] — submission form
// ============================================================
add_shortcode( 'spp_blog_submit', 'spp_blog_submit_shortcode' );
function spp_blog_submit_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="/login/">login</a> to submit a blog post.</p>';
    }

    // Handle form submission
    if ( isset( $_POST['spp_blog_submit_nonce'] ) &&
         wp_verify_nonce( $_POST['spp_blog_submit_nonce'], 'spp_blog_submit' ) ) {

        $title    = sanitize_text_field( $_POST['spp_post_title'] ?? '' );
        $content  = wp_kses_post( $_POST['spp_post_content'] ?? '' );
        $cat_id   = (int)( $_POST['spp_post_category'] ?? 0 );
        $expiry   = sanitize_text_field( $_POST['spp_post_expiry'] ?? '' );

        if ( empty( $title ) || empty( $content ) ) {
            $error = 'Please enter a title and content for your post.';
        } else {
            $post_data = array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'pending',
                'post_type'    => 'post',
                'post_author'  => get_current_user_id(),
            );

            if ( $cat_id ) {
                $post_data['post_category'] = array( $cat_id );
            }

            $post_id = wp_insert_post( $post_data );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                // Save expiry meta
                if ( ! empty( $expiry ) ) {
                    update_post_meta( $post_id, 'spp_blog_expiry', $expiry );
                }

                // Notify blog moderators
                spp_notify_moderators_new_post( $post_id, $title );

                return '<div class="spp-blog-submit-success">'
                     . '<p>&#10003; Your post has been submitted and is pending review. Thank you!</p>'
                     . '<p><a href="' . esc_url( home_url( '/blog/' ) ) . '">Return to Blog</a></p>'
                     . '</div>';
            } else {
                $error = 'There was a problem submitting your post. Please try again.';
            }
        }
    }

    // Build category dropdown
    $categories = get_categories( array( 'hide_empty' => false ) );
    $cat_options = '<option value="">-- Select a category --</option>';
    foreach ( $categories as $cat ) {
        $cat_options .= '<option value="' . $cat->term_id . '">'
                      . esc_html( $cat->name ) . '</option>';
    }

    $today = date( 'Y-m-d' );

    ob_start();
    ?>
    <?php if ( isset( $error ) ): ?>
    <div class="spp-blog-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <div class="spp-blog-submit-wrap">
        <form method="post" class="spp-blog-submit-form">
            <?php wp_nonce_field( 'spp_blog_submit', 'spp_blog_submit_nonce' ); ?>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_title">Post Title <span class="spp-required">*</span></label>
                <input type="text"
                       id="spp_post_title"
                       name="spp_post_title"
                       class="spp-blog-input"
                       value="<?php echo esc_attr( $_POST['spp_post_title'] ?? '' ); ?>"
                       required>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_content">Content <span class="spp-required">*</span></label>
                <?php
                echo spp_rich_editor(
                    'spp_post_content',
                    wp_kses_post( $_POST['spp_post_content'] ?? '' ),
                    array( 'rows' => 12, 'placeholder' => 'Write your post here...' )
                );
                ?>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_category">Category</label>
                <select id="spp_post_category" name="spp_post_category" class="spp-blog-select">
                    <?php echo $cat_options; ?>
                </select>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_expiry">Expiry Date <span class="spp-blog-hint">(optional — post will be auto-drafted on this date)</span></label>
                <input type="date"
                       id="spp_post_expiry"
                       name="spp_post_expiry"
                       class="spp-blog-date"
                       min="<?php echo $today; ?>"
                       value="<?php echo esc_attr( $_POST['spp_post_expiry'] ?? '' ); ?>">
            </div>

            <div class="spp-blog-actions">
                <button type="submit" class="spp-blog-submit-btn">Submit Post</button>
            </div>
        </form>
    </div>

    <style>
    .spp-blog-submit-wrap {
        max-width: 800px;
        margin: 0 auto;
    }
    .spp-blog-error {
        background: #fdf3f2;
        border-left: 4px solid #c0392b;
        padding: 12px 16px;
        margin-bottom: 20px;
        color: #c0392b;
        border-radius: 0 4px 4px 0;
    }
    .spp-blog-submit-success {
        background: #eef7f6;
        border-left: 4px solid var(--spp-primary, #00897B);
        padding: 16px 20px;
        border-radius: 0 4px 4px 0;
    }
    .spp-blog-submit-success p { margin: 0 0 8px; }
    .spp-blog-field {
        margin-bottom: 20px;
    }
    .spp-blog-label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
        color: var(--spp-text, #2c2c2c);
    }
    .spp-blog-hint {
        font-weight: 400;
        font-size: 0.85rem;
        color: #888;
    }
    .spp-required { color: #c0392b; }
    .spp-blog-input,
    .spp-blog-select,
    .spp-blog-date {
        width: 100% !important;
        max-width: 100% !important;
        padding: 9px 12px !important;
        border: 1px solid #ccc !important;
        border-radius: 4px !important;
        font-size: 0.95rem !important;
        background: #fff !important;
        box-sizing: border-box !important;
    }
    .spp-blog-input:focus,
    .spp-blog-select:focus,
    .spp-blog-date:focus {
        border-color: var(--spp-primary, #00897B) !important;
        outline: none !important;
    }
    .spp-blog-actions { margin-top: 24px; }
    .spp-blog-submit-btn {
        background: var(--spp-primary, #00897B);
        color: #fff;
        border: none;
        border-radius: 5px;
        padding: 10px 28px;
        font-size: 1rem;
        cursor: pointer;
        font-weight: 600;
    }
    .spp-blog-submit-btn:hover {
        background: var(--spp-accent, #004D40);
    }
    </style>
    <?php
    return ob_get_clean();
}

// ============================================================
// [spp_blog_edit] — frontend edit form for moderators/admins
// Usage: place [spp_blog_edit] on /edit-post/ page
// Reads ?post_id= from URL
// ============================================================
add_shortcode( 'spp_blog_edit', 'spp_blog_edit_shortcode' );
function spp_blog_edit_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="/login/">login</a> to edit posts.</p>';
    }
    if ( ! spp_can_moderate_blog() ) {
        return '<p>You do not have permission to edit posts.</p>';
    }

    $post_id = isset( $_GET['post_id'] ) ? (int)$_GET['post_id'] : 0;
    if ( ! $post_id ) {
        return '<p>No post specified.</p>';
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'post' ) {
        return '<p>Post not found.</p>';
    }

    // Handle form submission
    if ( isset( $_POST['spp_blog_edit_nonce'] ) &&
         wp_verify_nonce( $_POST['spp_blog_edit_nonce'], 'spp_blog_edit_' . $post_id ) ) {

        $title   = sanitize_text_field( $_POST['spp_post_title'] ?? '' );
        $content = wp_kses_post( $_POST['spp_post_content'] ?? '' );
        $cats    = isset( $_POST['spp_post_category'] ) ? array_map( 'intval', (array)$_POST['spp_post_category'] ) : array();
        $expiry  = sanitize_text_field( $_POST['spp_post_expiry'] ?? '' );

        if ( empty( $title ) || empty( $content ) ) {
            $error = 'Please enter a title and content.';
        } else {
            $result = wp_update_post( array(
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $content,
            ) );

            if ( $result && ! is_wp_error( $result ) ) {
                if ( ! empty( $cats ) ) {
                    wp_set_post_categories( $post_id, $cats );
                }
                if ( ! empty( $expiry ) ) {
                    update_post_meta( $post_id, 'spp_blog_expiry', $expiry );
                } else {
                    delete_post_meta( $post_id, 'spp_blog_expiry' );
                }
                $redirect = ( get_post_status( $post_id ) === 'pending' )
                    ? home_url( '/pending-posts/' )
                    : home_url( '/blog/' );
                wp_redirect( $redirect );
                exit;
            } else {
                $error = 'There was a problem saving the post. Please try again.';
            }
        }
    }

    // Get current post data
    $current_cats  = wp_get_post_categories( $post_id );
    $current_expiry = get_post_meta( $post_id, 'spp_blog_expiry', true );
    $all_cats      = get_categories( array( 'hide_empty' => false ) );
    $today         = date( 'Y-m-d' );

    ob_start();
    ?>
    <?php if ( isset( $error ) ): ?>
    <div class="spp-blog-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <div class="spp-blog-submit-wrap">
        <p class="spp-blog-edit-meta">
            Editing: <strong><?php echo esc_html( $post->post_title ); ?></strong>
            &mdash; by <?php echo esc_html( get_userdata( $post->post_author )->display_name ); ?>
            | <?php echo get_the_date( 'M j, Y', $post ); ?>
        </p>
        <form method="post" class="spp-blog-submit-form">
            <?php wp_nonce_field( 'spp_blog_edit_' . $post_id, 'spp_blog_edit_nonce' ); ?>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_title">Post Title <span class="spp-required">*</span></label>
                <input type="text"
                       id="spp_post_title"
                       name="spp_post_title"
                       class="spp-blog-input"
                       value="<?php echo esc_attr( $_POST['spp_post_title'] ?? $post->post_title ); ?>"
                       required>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_content">Content <span class="spp-required">*</span></label>
                <?php
                echo spp_rich_editor(
                    'spp_post_content',
                    wp_kses_post( $_POST['spp_post_content'] ?? $post->post_content ),
                    array( 'rows' => 12, 'placeholder' => 'Edit the post content here...' )
                );
                ?>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_category">Categories <span class="spp-blog-hint">(hold Ctrl/Cmd to select multiple)</span></label>
                <select id="spp_post_category" name="spp_post_category[]" class="spp-blog-select" multiple size="5">
                    <?php foreach ( $all_cats as $cat ):
                        $selected = in_array( $cat->term_id, $current_cats ) ? ' selected' : '';
                    ?>
                    <option value="<?php echo $cat->term_id; ?>"<?php echo $selected; ?>><?php echo esc_html( $cat->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php echo spp_blog_add_category_control( '#spp_post_category' ); ?>
            </div>

            <div class="spp-blog-field">
                <label class="spp-blog-label" for="spp_post_expiry">Expiry Date <span class="spp-blog-hint">(optional — post will be auto-drafted on this date)</span></label>
                <input type="date"
                       id="spp_post_expiry"
                       name="spp_post_expiry"
                       class="spp-blog-date"
                       value="<?php echo esc_attr( $_POST['spp_post_expiry'] ?? $current_expiry ); ?>">
            </div>

            <div class="spp-blog-actions">
                <button type="submit" class="spp-blog-submit-btn">Save Changes</button>
                <a href="<?php echo esc_url( get_post_status( $post_id ) === 'pending' ? home_url( '/pending-posts/' ) : home_url( '/blog/' ) ); ?>" class="spp-blog-cancel-link">Cancel</a>
            </div>
        </form>
    </div>

    <style>
    .spp-blog-edit-meta {
        color: #666;
        font-size: 0.9rem;
        margin-bottom: 20px;
        padding: 10px 14px;
        background: #f9f9f9;
        border-left: 3px solid var(--spp-primary, #00897B);
        border-radius: 0 4px 4px 0;
    }
    .spp-blog-cancel-link {
        margin-left: 12px;
        color: #888;
        font-size: 0.95rem;
        text-decoration: none;
    }
    .spp-blog-cancel-link:hover { text-decoration: underline; }
    </style>
    <?php
    return ob_get_clean();
}

// ============================================================
// Notify moderators on new submission
// ============================================================
function spp_notify_moderators_new_post( $post_id, $title ) {
    $moderators = get_users( array(
        'role__in' => array( 'blog_moderator', 'administrator', 'editor' ),
    ) );

    if ( empty( $moderators ) ) return;

    $review_url = home_url( '/pending-posts/' );
    $subject    = 'New Blog Post Pending Review: ' . $title;
    $body       = 'A new blog post has been submitted and is waiting for your review.' . "\n\n"
                . 'Title: ' . $title . "\n"
                . 'Review it here: ' . $review_url . "\n\n"
                . '-- Stouffville Pickleball Players';

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    foreach ( $moderators as $mod ) {
        wp_mail( $mod->user_email, $subject, $body, $headers );
    }
}

// ============================================================
// [spp_pending_posts] — moderator review interface
// ============================================================
add_shortcode( 'spp_pending_posts', 'spp_pending_posts_shortcode' );
function spp_pending_posts_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="/login/">login</a> to access this page.</p>';
    }

    if ( ! spp_can_moderate_blog() ) {
        return '<p>You do not have permission to view this page.</p>';
    }

    // Enqueue scripts for AJAX actions
    wp_enqueue_script( 'jquery' );

    $posts = get_posts( array(
        'post_status'    => 'pending',
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    // Build category options for dropdowns
    $all_cats = get_categories( array( 'hide_empty' => false ) );
    $cat_options_html = '<option value="">-- No category --</option>';
    foreach ( $all_cats as $cat ) {
        $cat_options_html .= '<option value="' . $cat->term_id . '">'
                           . esc_html( $cat->name ) . '</option>';
    }

    ob_start();
    ?>
    <div class="spp-pending-wrap">
        <h3 class="spp-pending-title">Posts Pending Review</h3>

        <?php if ( empty( $posts ) ): ?>
        <p class="spp-pending-none">No posts pending review.</p>
        <?php else: ?>

        <div id="spp-pending-message" class="spp-pending-message" style="display:none;"></div>

        <?php foreach ( $posts as $post ):
            $author    = get_userdata( $post->post_author );
            $cats      = wp_get_post_categories( $post->ID );
            $current_cat = ! empty( $cats ) ? (int)$cats[0] : 0;
            $expiry    = get_post_meta( $post->ID, 'spp_blog_expiry', true );
            $excerpt   = wp_trim_words( $post->post_content, 40, '...' );
        ?>
        <div class="spp-pending-post" id="spp-post-<?php echo $post->ID; ?>">
            <div class="spp-pending-header">
                <span class="spp-pending-post-title"><?php echo esc_html( $post->post_title ); ?></span>
                <span class="spp-pending-meta">
                    by <strong><?php echo esc_html( $author->display_name ); ?></strong>
                    &mdash; <?php echo get_the_date( 'M j, Y', $post ); ?>
                </span>
            </div>

            <div class="spp-pending-excerpt"><?php echo esc_html( $excerpt ); ?></div>

            <div class="spp-pending-fields">
                <div class="spp-pending-field">
                    <label class="spp-pending-label">Category</label>
                    <select class="spp-pending-cat spp-pending-input" data-post-id="<?php echo $post->ID; ?>" multiple size="4">
                        <?php
                        foreach ( $all_cats as $cat ) {
                            $selected = in_array( (int)$cat->term_id, $cats ) ? ' selected' : '';
                            echo '<option value="' . $cat->term_id . '"' . $selected . '>'
                               . esc_html( $cat->name ) . '</option>';
                        }
                        ?>
                    </select>
                    <?php echo spp_blog_add_category_control( '.spp-pending-cat[data-post-id="' . $post->ID . '"]' ); ?>
                </div>
                <div class="spp-pending-field">
                    <label class="spp-pending-label">Expiry Date <span class="spp-blog-hint">(optional)</span></label>
                    <input type="date"
                           class="spp-pending-expiry spp-pending-input"
                           data-post-id="<?php echo $post->ID; ?>"
                           value="<?php echo esc_attr( $expiry ); ?>">
                </div>
            </div>

            <div class="spp-pending-actions">
                <button class="spp-pending-publish-btn"
                        data-post-id="<?php echo $post->ID; ?>"
                        onclick="sppPublishPost(<?php echo $post->ID; ?>)">
                    &#10003; Publish
                </button>
                <button class="spp-pending-reject-btn"
                        data-post-id="<?php echo $post->ID; ?>"
                        onclick="sppRejectPost(<?php echo $post->ID; ?>)">
                    &#10007; Reject
                </button>
                <a href="<?php echo esc_url( home_url( '/edit-post/?post_id=' . $post->ID ) ); ?>"
                   class="spp-pending-edit-link">Edit</a>
                <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"
                   target="_blank"
                   class="spp-pending-preview-link">Preview &rarr;</a>
                <span id="spp-save-indicator-<?php echo $post->ID; ?>"
                      class="spp-save-indicator"
                      style="display:none;font-size:0.85rem;margin-left:8px;"></span>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <style>
    .spp-pending-wrap { max-width: 900px; margin: 0 auto; }
    .spp-pending-title {
        color: var(--spp-primary, #00897B);
        border-bottom: 2px solid var(--spp-primary, #00897B);
        padding-bottom: 8px;
        margin-bottom: 20px;
    }
    .spp-pending-none { color: #888; font-style: italic; }
    .spp-pending-message {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-weight: 600;
    }
    .spp-pending-message.success {
        background: #eef7f6;
        border-left: 4px solid var(--spp-primary, #00897B);
        color: var(--spp-accent, #004D40);
    }
    .spp-pending-message.error {
        background: #fdf3f2;
        border-left: 4px solid #c0392b;
        color: #c0392b;
    }
    .spp-pending-post {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    .spp-pending-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }
    .spp-pending-post-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--spp-dark, #1a1a1a);
    }
    .spp-pending-meta {
        font-size: 0.85rem;
        color: #888;
    }
    .spp-pending-excerpt {
        color: #555;
        font-size: 0.92rem;
        line-height: 1.6;
        margin-bottom: 14px;
        padding: 10px 14px;
        background: #f9f9f9;
        border-radius: 4px;
        border-left: 3px solid #ddd;
    }
    .spp-pending-fields {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .spp-pending-field {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 200px;
    }
    .spp-pending-label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #555;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .spp-pending-input {
        padding: 7px 10px !important;
        border: 1px solid #ccc !important;
        border-radius: 4px !important;
        font-size: 0.9rem !important;
        background: #fff !important;
        box-sizing: border-box !important;
        width: 100% !important;
    }
    .spp-pending-input:focus {
        border-color: var(--spp-primary, #00897B) !important;
        outline: none !important;
    }
    .spp-pending-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .spp-pending-publish-btn {
        background: var(--spp-primary, #00897B);
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 8px 20px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
    }
    .spp-pending-publish-btn:hover { background: var(--spp-accent, #004D40); }
    .spp-pending-reject-btn {
        background: #c0392b;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 8px 20px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
    }
    .spp-pending-reject-btn:hover { background: #a93226; }
    .spp-pending-edit-link {
        color: #3766AB;
        font-size: 0.9rem;
        text-decoration: none;
        font-weight: 600;
        margin-left: 6px;
    }
    .spp-pending-edit-link:hover { text-decoration: underline; }
    .spp-pending-preview-link {
        color: var(--spp-link, #00897B);
        font-size: 0.9rem;
        text-decoration: none;
        margin-left: 6px;
    }
    .spp-pending-preview-link:hover { text-decoration: underline; }
    .spp-save-indicator {
        font-size: 0.85rem;
        margin-left: 8px;
        font-style: italic;
    }
    @media (max-width: 600px) {
        .spp-pending-header { flex-direction: column; }
        .spp-pending-fields { flex-direction: column; }
    }
    </style>

    <script>
    // Auto-save category and expiry when changed
    jQuery(document).ready(function($) {
        // Auto-save on category change
        $(document).on('change', '.spp-pending-cat', function() {
            var postId = $(this).data('post-id');
            sppAutoSave(postId);
        });

        // Auto-save on expiry change
        $(document).on('change', '.spp-pending-expiry', function() {
            var postId = $(this).data('post-id');
            sppAutoSave(postId);
        });
    });

    function sppGetCats(postId) {
        var cats = [];
        jQuery('.spp-pending-cat[data-post-id="' + postId + '"] option:selected').each(function() {
            cats.push(jQuery(this).val());
        });
        return cats;
    }

    function sppAutoSave(postId) {
        var cats   = sppGetCats(postId);
        var expiry = jQuery('.spp-pending-expiry[data-post-id="' + postId + '"]').val();
        var indicator = jQuery('#spp-save-indicator-' + postId);

        indicator.text('Saving...').css('color', '#888').show();

        jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
            action:  'spp_save_pending_post',
            post_id: postId,
            cats:    cats,
            expiry:  expiry,
            nonce:   '<?php echo wp_create_nonce("spp_pending_action"); ?>'
        }, function(response) {
            if (response.success) {
                indicator.text('Saved').css('color', 'green');
                setTimeout(function() { indicator.fadeOut(); }, 2000);
            } else {
                indicator.text('Save failed').css('color', '#c0392b');
            }
        });
    }

    function sppPublishPost(postId) {
        var cats   = sppGetCats(postId);
        var expiry = jQuery('.spp-pending-expiry[data-post-id="' + postId + '"]').val();
        var msg    = document.getElementById('spp-pending-message');

        jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
            action:  'spp_publish_post',
            post_id: postId,
            cats:    cats,
            expiry:  expiry,
            nonce:   '<?php echo wp_create_nonce("spp_pending_action"); ?>'
        }, function(response) {
            if (response.success) {
                document.getElementById('spp-post-' + postId).style.display = 'none';
                msg.className = 'spp-pending-message success';
                msg.textContent = response.data.message;
                msg.style.display = 'block';
                setTimeout(function() { msg.style.display = 'none'; }, 4000);
            } else {
                msg.className = 'spp-pending-message error';
                msg.textContent = 'Error: ' + (response.data || 'Action failed.');
                msg.style.display = 'block';
            }
        });
    }

    function sppRejectPost(postId) {
        if (!confirm('Reject and delete this post?')) return;
        var msg = document.getElementById('spp-pending-message');

        jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
            action:  'spp_reject_post',
            post_id: postId,
            nonce:   '<?php echo wp_create_nonce("spp_pending_action"); ?>'
        }, function(response) {
            if (response.success) {
                document.getElementById('spp-post-' + postId).style.display = 'none';
                msg.className = 'spp-pending-message success';
                msg.textContent = response.data.message;
                msg.style.display = 'block';
                setTimeout(function() { msg.style.display = 'none'; }, 4000);
            } else {
                msg.className = 'spp-pending-message error';
                msg.textContent = 'Error: ' + (response.data || 'Action failed.');
                msg.style.display = 'block';
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

// ============================================================
// AJAX — publish post
// ============================================================
add_action( 'wp_ajax_spp_publish_post', 'spp_ajax_publish_post' );
function spp_ajax_publish_post() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'spp_pending_action' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! spp_can_moderate_blog() ) {
        wp_send_json_error( 'Permission denied' );
    }

    $post_id = (int)$_POST['post_id'];
    $cats    = isset( $_POST['cats'] ) ? array_map( 'intval', (array)$_POST['cats'] ) : array();
    $expiry  = sanitize_text_field( $_POST['expiry'] ?? '' );

    // Update categories if set
    if ( ! empty( $cats ) ) {
        wp_set_post_categories( $post_id, $cats );
    }

    // Update expiry meta
    if ( ! empty( $expiry ) ) {
        update_post_meta( $post_id, 'spp_blog_expiry', $expiry );
    } else {
        delete_post_meta( $post_id, 'spp_blog_expiry' );
    }

    // Publish
    $result = wp_update_post( array(
        'ID'          => $post_id,
        'post_status' => 'publish',
    ) );

    if ( $result && ! is_wp_error( $result ) ) {
        wp_send_json_success( array( 'message' => 'Post published successfully.' ) );
    } else {
        wp_send_json_error( 'Failed to publish post.' );
    }
}

// ============================================================
// AJAX — auto-save category and expiry (no publish)
// ============================================================
add_action( 'wp_ajax_spp_save_pending_post', 'spp_ajax_save_pending_post' );
function spp_ajax_save_pending_post() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'spp_pending_action' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! spp_can_moderate_blog() ) {
        wp_send_json_error( 'Permission denied' );
    }

    $post_id = (int)$_POST['post_id'];
    $cats    = isset( $_POST['cats'] ) ? array_map( 'intval', (array)$_POST['cats'] ) : array();
    $expiry  = sanitize_text_field( $_POST['expiry'] ?? '' );

    if ( ! empty( $cats ) ) {
        wp_set_post_categories( $post_id, $cats );
    }

    if ( ! empty( $expiry ) ) {
        update_post_meta( $post_id, 'spp_blog_expiry', $expiry );
    } else {
        delete_post_meta( $post_id, 'spp_blog_expiry' );
    }

    wp_send_json_success( array( 'message' => 'Saved.' ) );
}

// ============================================================
// AJAX — reject (delete) pending post
// ============================================================
add_action( 'wp_ajax_spp_reject_post', 'spp_ajax_reject_post' );
function spp_ajax_reject_post() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'spp_pending_action' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! spp_can_moderate_blog() ) {
        wp_send_json_error( 'Permission denied' );
    }

    $post_id = (int)$_POST['post_id'];
    $result  = wp_delete_post( $post_id, true );

    if ( $result ) {
        wp_send_json_success( array( 'message' => 'Post rejected and deleted.' ) );
    } else {
        wp_send_json_error( 'Failed to delete post.' );
    }
}

// ============================================================
// AJAX — delete published post (from single.php)
// ============================================================
add_action( 'wp_ajax_spp_delete_post', 'spp_ajax_delete_post' );
function spp_ajax_delete_post() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'spp_delete_post' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! spp_can_moderate_blog() ) {
        wp_send_json_error( 'Permission denied' );
    }

    $post_id = (int)$_POST['post_id'];
    $result  = wp_delete_post( $post_id, true );

    if ( $result ) {
        wp_send_json_success( array( 'message' => 'Post deleted.' ) );
    } else {
        wp_send_json_error( 'Failed to delete post.' );
    }
}

// ============================================================
// 1.7.0 — live-post category counts (shared by single.php and the
// /blog/ strip). SQL moved verbatim from single.php 1.3.2: published
// posts with no spp_blog_expiry or one not yet past -- the same set
// category.php lists.
// ============================================================
// 1.8.0: $term_id null = live posts in any category or none (the
// /all-categories/ total) -- same rule, just without the term join.
function spp_blog_live_category_count( ?int $term_id ) : int {
    global $wpdb;
    $term_join  = $term_id === null ? '' : "JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
    $term_where = $term_id === null ? '' : $wpdb->prepare( 'tt.term_id = %d AND', $term_id );
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         {$term_join}
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'spp_blog_expiry'
         WHERE {$term_where}
         p.post_type = 'post'
         AND p.post_status = 'publish'
         AND (pm.meta_value IS NULL OR pm.meta_value >= %s)",
        date( 'Y-m-d' )
    ) );
}

/**
 * Every category with at least one live post, name order.
 *
 * @return array[] each ['term' => WP_Term, 'count' => int]
 */
function spp_blog_live_categories() : array {
    $out = array();
    foreach ( get_categories( array( 'hide_empty' => true ) ) as $cat ) {
        $count = spp_blog_live_category_count( $cat->term_id );
        if ( $count > 0 ) {
            $out[] = array( 'term' => $cat, 'count' => $count );
        }
    }
    return $out;
}

// ============================================================
// 1.7.0 — /blog/ category filter strip, directly above the page's
// Divi Blog module. Injected through render_block on that one module
// so neither the page content nor the module's settings are touched;
// each link is the existing /category/<slug>/ archive (category.php).
// ============================================================
const SPP_BLOG_PAGE_ID = 20009057;

function spp_blog_category_strip_html() : string {
    $live = spp_blog_live_categories();
    if ( empty( $live ) ) {
        return '';
    }
    $links = '<a href="' . esc_url( home_url( '/' . SPP_BLOG_ALL_SLUG . '/' ) ) . '" class="spp-cat-tag spp-cat-tag--outline">'
           . 'All Categories <span class="spp-cat-count">(' . spp_blog_live_category_count( null ) . ')</span></a>';
    foreach ( $live as $l ) {
        $links .= '<a href="' . esc_url( get_category_link( $l['term']->term_id ) ) . '" class="spp-cat-tag spp-cat-tag--outline">'
                . esc_html( $l['term']->name ) . ' <span class="spp-cat-count">(' . $l['count'] . ')</span></a>';
    }
    return '<nav class="spp-blog-cat-strip" aria-label="Blog categories">'
         . '<span class="spp-filed-label">Browse by category:</span>'
         . '<div class="spp-cat-tags">' . $links . '</div>'
         . '</nav>';
}

add_filter( 'render_block', function ( $html, $block ) {
    if ( ( $block['blockName'] ?? '' ) !== 'divi/blog' || is_admin() || ! is_page( SPP_BLOG_PAGE_ID ) ) {
        return $html;
    }
    return spp_blog_category_strip_html() . $html;
}, 10, 2 );

// ============================================================
// 1.8.0 — /all-categories/: every live post, rendered by category.php
// ============================================================
const SPP_BLOG_ALL_SLUG = 'all-categories';

/**
 * Whether $query (default: the main query) is the All Categories view.
 */
function spp_blog_is_all_view_query( ?WP_Query $query = null ) : bool {
    $query = $query ?? $GLOBALS['wp_query'] ?? null;
    return $query instanceof WP_Query && (bool) $query->get( 'spp_blog_all' );
}

// With the /%postname%/ permalink structure a bare /all-categories/
// parses as name= or pagename= (no such post/page exists). Swap that for
// the all-posts query before WP_Query runs -- no rewrite rule to flush.
// Only an exact match with nothing else in the request (no paging, no
// other vars) is taken over.
add_filter( 'request', function ( $qv ) {
    $slug = $qv['name'] ?? $qv['pagename'] ?? null;
    if ( $slug !== SPP_BLOG_ALL_SLUG || array_diff( array_keys( $qv ), array( 'name', 'pagename', 'page' ) ) || ! empty( $qv['page'] ) ) {
        return $qv;
    }
    return array(
        'spp_blog_all'   => 1,
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
} );

add_filter( 'template_include', function ( $template ) {
    if ( spp_blog_is_all_view_query() ) {
        return locate_template( 'category.php' ) ?: $template;
    }
    return $template;
} );

// Divi Theme Builder: this query is is_home(), which matches template
// 20009628's "archive:post_type:post" condition and would swap in its
// body layout (20009622) instead of category.php. Give this view exactly
// what a category archive gets -- same header/footer, no body override.
add_filter( 'et_theme_builder_template_layouts', function ( $layouts ) {
    if ( spp_blog_is_all_view_query() && isset( $layouts['et_body_layout'] ) ) {
        $layouts['et_body_layout'] = array( 'id' => 0, 'enabled' => true, 'override' => false );
    }
    return $layouts;
} );

add_filter( 'document_title_parts', function ( $parts ) {
    if ( spp_blog_is_all_view_query() ) {
        $parts['title'] = 'All Categories';
    }
    return $parts;
} );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_singular( 'post' ) || is_page( array( SPP_BLOG_PAGE_ID, 'edit-post', 'pending-posts' ) ) ) {
        wp_enqueue_style(
            'spp-blog-categories',
            get_stylesheet_directory_uri() . '/css/spp-blog-categories.css',
            array( 'spp-tokens' ),
            filemtime( get_stylesheet_directory() . '/css/spp-blog-categories.css' )
        );
    }
} );

// ============================================================
// 1.7.0 — moderator "+ Add new category" (edit-post + pending-posts)
// ============================================================

/**
 * Existing category whose name matches case-insensitively (and
 * ignoring surrounding space / HTML-entity encoding -- WP stores "&"
 * as "&amp;"), or null.
 */
function spp_blog_find_category_by_name( string $name ) : ?WP_Term {
    $needle = mb_strtolower( trim( html_entity_decode( $name, ENT_QUOTES ) ) );
    foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) {
        if ( mb_strtolower( trim( html_entity_decode( $cat->name, ENT_QUOTES ) ) ) === $needle ) {
            return $cat;
        }
    }
    return null;
}

/**
 * The control, rendered under a category <select>. $target is a CSS
 * selector for that select. Only ever called from the two moderator
 * screens, after their spp_can_moderate_blog() gate -- never from
 * [spp_blog_submit]. The AJAX handler re-checks the gate itself.
 */
function spp_blog_add_category_control( string $target ) : string {
    static $script_done = false;
    ob_start();
    ?>
    <div class="spp-add-cat" data-target="<?php echo esc_attr( $target ); ?>">
        <button type="button" class="spp-add-cat-toggle">+ Add new category</button>
        <div class="spp-add-cat-form" hidden>
            <input type="text" class="spp-add-cat-input" placeholder="New category name" maxlength="100" aria-label="New category name">
            <button type="button" class="spp-add-cat-btn">Add</button>
            <span class="spp-add-cat-msg" role="status"></span>
        </div>
    </div>
    <?php
    if ( ! $script_done ) :
        $script_done = true;
    ?>
    <script>
    (function () {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_add_blog_category' ) ); ?>;

        // Put the option in every category select on the page (so other
        // pending cards can use it too), in name order; select it only in
        // the target.
        function addOption(sel, id, name) {
            var opt = sel.querySelector('option[value="' + id + '"]');
            if (!opt) {
                opt = new Option(name, id);
                var before = null;
                Array.prototype.some.call(sel.options, function (o) {
                    if (o.value && o.text.localeCompare(name, undefined, {sensitivity: 'base'}) > 0) { before = o; return true; }
                    return false;
                });
                sel.insertBefore(opt, before);
            }
            return opt;
        }

        function submit(wrap) {
            var input = wrap.querySelector('.spp-add-cat-input');
            var btn   = wrap.querySelector('.spp-add-cat-btn');
            var msg   = wrap.querySelector('.spp-add-cat-msg');
            var name  = input.value.trim();
            if (!name) { input.focus(); return; }
            btn.disabled = true;
            msg.className = 'spp-add-cat-msg';
            msg.textContent = 'Adding...';
            var body = new FormData();
            body.append('action', 'spp_add_blog_category');
            body.append('nonce', nonce);
            body.append('name', name);
            fetch(ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { throw new Error((res && res.data) || 'Could not add category.'); }
                    var d = res.data;
                    document.querySelectorAll('.spp-pending-cat, #spp_post_category').forEach(function (sel) {
                        addOption(sel, d.term_id, d.name);
                    });
                    var target = document.querySelector(wrap.dataset.target);
                    if (target) {
                        addOption(target, d.term_id, d.name).selected = true;
                        // Pending-posts auto-saves on change; edit-post saves on submit.
                        target.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                    msg.className = 'spp-add-cat-msg is-ok';
                    msg.textContent = d.existed ? '"' + d.name + '" already exists -- selected it.' : 'Added "' + d.name + '" and selected it.';
                    input.value = '';
                })
                .catch(function (e) {
                    msg.className = 'spp-add-cat-msg is-err';
                    msg.textContent = e.message;
                })
                .then(function () { btn.disabled = false; });
        }

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('spp-add-cat-toggle')) {
                var form = t.parentNode.querySelector('.spp-add-cat-form');
                form.hidden = !form.hidden;
                if (!form.hidden) { form.querySelector('.spp-add-cat-input').focus(); }
            } else if (t.classList.contains('spp-add-cat-btn')) {
                submit(t.closest('.spp-add-cat'));
            }
        });
        // Enter adds the category instead of submitting the edit-post form.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.classList.contains('spp-add-cat-input')) {
                e.preventDefault();
                submit(e.target.closest('.spp-add-cat'));
            }
        });
    })();
    </script>
    <?php
    endif;
    return ob_get_clean();
}

add_action( 'wp_ajax_spp_add_blog_category', 'spp_ajax_add_blog_category' );
function spp_ajax_add_blog_category() {
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'spp_add_blog_category' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! spp_can_moderate_blog() ) {
        wp_send_json_error( 'Permission denied' );
    }

    $name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
    if ( $name === '' ) {
        wp_send_json_error( 'Please enter a category name.' );
    }
    if ( mb_strlen( $name ) > 100 ) {
        wp_send_json_error( 'Category name is too long (100 characters max).' );
    }

    $existing = spp_blog_find_category_by_name( $name );
    if ( $existing ) {
        wp_send_json_success( array( 'term_id' => (int) $existing->term_id, 'name' => html_entity_decode( $existing->name, ENT_QUOTES ), 'existed' => true ) );
    }

    $result = wp_insert_term( $name, 'category' );
    if ( is_wp_error( $result ) ) {
        // Different name, same slug (e.g. "Ladder!" vs "Ladder"): use that one.
        $dupe_id = $result->get_error_data( 'term_exists' );
        if ( $dupe_id ) {
            $term = get_term( (int) $dupe_id, 'category' );
            wp_send_json_success( array( 'term_id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'existed' => true ) );
        }
        wp_send_json_error( 'Could not create category: ' . $result->get_error_message() );
    }

    $term = get_term( (int) $result['term_id'], 'category' );
    wp_send_json_success( array( 'term_id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'existed' => false ) );
}
