<?php
/**
 * Single Blog Post Template
 * File: single.php
 * Version: 1.1.0
 * Date: 2026-05-27
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
                </p>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
                <?php if ( has_post_thumbnail() ) : ?>
                    <div class="spp-post-thumbnail">
                        <?php the_post_thumbnail('large'); ?>
                    </div>
                <?php endif; ?>

                <?php
                $today_date = date('Y-m-d');
                global $wpdb;

                // ── Filed under ──────────────────────────────
                $categories = get_the_category();
                $filed_cats = [];
                foreach ($categories as $cat) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'spp_blog_expiry'
                         WHERE tt.term_id = %d
                         AND p.post_type = 'post'
                         AND p.post_status = 'publish'
                         AND (pm.meta_value IS NULL OR pm.meta_value >= %s)",
                        $cat->term_id, $today_date
                    ));
                    if ($count > 0) {
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
                $all_cats = get_categories(['hide_empty' => true]);
                $browse_cats = [];
                foreach ($all_cats as $cat) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'spp_blog_expiry'
                         WHERE tt.term_id = %d
                         AND p.post_type = 'post'
                         AND p.post_status = 'publish'
                         AND (pm.meta_value IS NULL OR pm.meta_value >= %s)",
                        $cat->term_id, $today_date
                    ));
                    if ($count > 0) {
                        $browse_cats[] = '<a href="' . get_category_link($cat->term_id) . '" class="spp-cat-tag spp-cat-tag--outline">'
                            . esc_html($cat->name) . ' <span class="spp-cat-count">(' . $count . ')</span></a>';
                    }
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
/* ── Filed under / Browse categories ─────────────────── */
.spp-filed-under,
.spp-browse-cats {
    margin: 1.5rem 0 0.5rem;
    padding: 14px 16px;
    background: var(--spp-item-bg, #eef7f6);
    border-left: 4px solid var(--spp-primary, #00897B);
    border-radius: 0 6px 6px 0;
}

.spp-browse-cats {
    margin-top: 0.75rem;
    border-left-color: var(--spp-accent, #004D40);
}

.spp-filed-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--spp-text-subtle, #555);
    margin-bottom: 8px;
}

.spp-cat-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}

.spp-cat-tags--wrap {
    margin-top: 6px;
}

/* Filled tag — "Filed under" */
.spp-cat-tag {
    display: inline-block;
    background: var(--spp-primary, #00897B);
    color: #fff !important;
    text-decoration: none !important;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 600;
    transition: background 0.2s;
    white-space: nowrap;
}
.spp-cat-tag:hover {
    background: var(--spp-accent, #004D40) !important;
    color: #fff !important;
}

/* Outline tag — "Browse all" */
.spp-cat-tag--outline {
    background: transparent;
    color: var(--spp-primary, #00897B) !important;
    border: 2px solid var(--spp-primary, #00897B);
    padding: 3px 10px;
}
.spp-cat-tag--outline:hover {
    background: var(--spp-primary, #00897B) !important;
    color: #fff !important;
}

.spp-cat-count {
    font-weight: 400;
    font-size: 0.82rem;
    opacity: 0.85;
}

/* Mobile */
@media (max-width: 600px) {
    .spp-filed-under,
    .spp-browse-cats {
        padding: 12px;
    }
    .spp-cat-tag {
        font-size: 0.85rem;
        padding: 5px 12px;
        min-height: 36px;
        display: inline-flex;
        align-items: center;
    }
}
</style>

<?php
get_footer();