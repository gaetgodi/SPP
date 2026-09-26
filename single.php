<?php
/**
 * Single Blog Post Template
 * File: single.php
 * Version: 1.4.0
 * Date: 2026-09-26
 *
 * Changes from 1.3.2 (no visible change):
 * - "Filed under" / "Browse all blog categories" counts now call
 *   spp_blog_live_category_count() / spp_blog_live_categories()
 *   (inc/spp-blog-system.php 1.7.0) -- the same SQL, moved verbatim so
 *   the new /blog/ category strip shares it instead of copying it.
 * - Category pill CSS moved verbatim to css/spp-blog-categories.css
 *   (enqueued on single posts) so the /blog/ strip shares it.
 *
 * Changes from 1.3.1:
 * - Edit/Delete links now shown only when spp_can_moderate_blog()
 *   (functions.php -- administrator or blog_moderator), the same gate
 *   the edit form and delete handler use (inc/spp-blog-system.php
 *   1.6.0). Was current_user_can('publish_posts'), which editors also
 *   hold, so they saw links that only led to "permission denied".
 *
 * Changes from 1.3.0:
 * - Edit link points to /edit-post/?post_id=X instead of wp-admin
 *
 * Changes from 1.2.1:
 * - Delete button added for moderators/admins on published posts
 *
 * Changes from 1.1.0:
 * - Expiry date shown in post meta for moderators/admins
 *
 * Changes from 1.0.0:
 * - Category sections styled as prominent pill/tag links
 * - "Filed under" and "Browse all categories" visually distinct
 * - Better responsive behaviour on mobile
 * - Excludes expired posts from category browse counts
 */
get_header();
?>
<div id="et-main-area" style="background-color: var(--spp-bg-page); padding: 2rem 0;">
    <div class="et_pb_row et_flex_row spp-two-col-row">
        <div class="et_pb_column et_flex_column et_flex_column_18_24" id="content_column">
            <?php while ( have_posts() ) : the_post(); ?>
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <p class="spp-post-meta">
                    by <?php the_author(); ?> | <?php echo get_the_date(); ?>
                    <?php
                    $expiry = get_post_meta( get_the_ID(), 'spp_blog_expiry', true );
                    if ( $expiry && is_user_logged_in() ):
                    ?>
                    | <span class="spp-post-expiry">Expires: <?php echo date( 'F j, Y', strtotime( $expiry ) ); ?></span>
                    <?php endif; ?>
                </p>
                <?php if ( spp_can_moderate_blog() ): ?>
                <div class="spp-post-admin-actions">
                    <a href="<?php echo esc_url( home_url( '/edit-post/?post_id=' . get_the_ID() ) ); ?>" class="spp-post-edit-link">&#9998; Edit</a>
                    <a href="#"
                       class="spp-post-delete-link"
                       onclick="return sppConfirmDelete(<?php echo get_the_ID(); ?>)">&#10007; Delete Post</a>
                </div>
                <?php endif; ?>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
                <?php if ( has_post_thumbnail() ) : ?>
                    <div class="spp-post-thumbnail">
                        <?php the_post_thumbnail('large'); ?>
                    </div>
                <?php endif; ?>

                <?php
                // ── Filed under ──────────────────────────────
                // Live-post counting is spp_blog_live_category_count()
                // (inc/spp-blog-system.php), shared with the /blog/ strip.
                $filed_cats = [];
                foreach (get_the_category() as $cat) {
                    if (spp_blog_live_category_count($cat->term_id) > 0) {
                        $filed_cats[] = '<a href="' . get_category_link($cat->term_id) . '" class="spp-cat-tag">' . esc_html($cat->name) . '</a>';
                    }
                }
                if (!empty($filed_cats)): ?>
                <div class="spp-filed-under">
                    <span class="spp-filed-label">Filed under:</span>
                    <span class="spp-cat-tags"><?php echo implode( '', $filed_cats ); ?></span>
                </div>
                <?php endif; ?>

                <?php
                // ── Browse all categories ────────────────────
                $browse_cats = [];
                foreach (spp_blog_live_categories() as $live) {
                    $browse_cats[] = '<a href="' . get_category_link($live['term']->term_id) . '" class="spp-cat-tag spp-cat-tag--outline">'
                        . esc_html($live['term']->name) . ' <span class="spp-cat-count">(' . $live['count'] . ')</span></a>';
                }
                if (!empty($browse_cats)): ?>
                <div class="spp-browse-cats">
                    <span class="spp-filed-label">Browse all blog categories:</span>
                    <div class="spp-cat-tags spp-cat-tags--wrap"><?php echo implode( '', $browse_cats ); ?></div>
                </div>
                <?php endif; ?>

            <?php endwhile; ?>
        </div>
        <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
            <?php echo do_shortcode('[spp_side_nav]'); ?>
        </div>
    </div>
</div>

<style>
/* ── Expiry indicator ────────────────────────────────── */
.spp-post-expiry {
    color: #c0392b;
    font-size: 0.85rem;
    font-weight: 600;
}

</style>

<script>
function sppConfirmDelete(postId) {
    if (!confirm('Are you sure you want to delete this post? This cannot be undone.')) return false;
    jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
        action:  'spp_delete_post',
        post_id: postId,
        nonce:   '<?php echo wp_create_nonce("spp_delete_post"); ?>'
    }, function(response) {
        if (response.success) {
            window.location.href = '<?php echo esc_url( home_url("/blog/") ); ?>';
        } else {
            alert('Error: ' + (response.data || 'Could not delete post.'));
        }
    });
    return false;
}
</script>
<?php
get_footer();