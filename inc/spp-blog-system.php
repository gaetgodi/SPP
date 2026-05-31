<?php
/**
 * SPP Blog System
 * File: inc/spp-blog-system.php
 * Version: 1.1.1
 * Date: 2026-05-31
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
 *   [spp_pending_posts]  — frontend moderator review interface (blog_moderator only)
 *
 * Features:
 *   - Author submits title, content, category, optional expiry date
 *   - Post created as pending, spp_blog_expiry meta saved
 *   - Moderator reviews entirely on frontend — no wp-admin needed
 *   - Moderator can edit category and expiry before publishing
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
                wp_editor(
                    wp_kses_post( $_POST['spp_post_content'] ?? '' ),
                    'spp_post_content',
                    array(
                        'textarea_name' => 'spp_post_content',
                        'media_buttons' => true,
                        'textarea_rows' => 12,
                        'teeny'         => false,
                        'tinymce'       => true,
                    )
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

    if ( ! current_user_can( 'publish_posts' ) ) {
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
    if ( ! current_user_can( 'publish_posts' ) ) {
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
    if ( ! current_user_can( 'publish_posts' ) ) {
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
    if ( ! current_user_can( 'publish_posts' ) ) {
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
    if ( ! current_user_can( 'publish_posts' ) ) {
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