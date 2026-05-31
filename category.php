<?php
/**
 * Blog Category Template
 * File: category.php
 * Version: 1.1.0
 * Date: 2026-05-27
 *
 * Changes from 1.0.0:
 * - Excludes expired posts from listing
 * - Version number added
 */
get_header();
?>
<div id="et-main-area" style="background-color: var(--spp-bg-page); padding: 2rem 0;">
    <div class="et_pb_row et_flex_row spp-two-col-row">
        <div class="et_pb_column et_flex_column et_flex_column_18_24" id="content_column">
            <h1 class="entry-title"><?php single_cat_title('Category: '); ?></h1>
            <?php if ( have_posts() ) : while ( have_posts() ) : the_post();
                // Skip expired posts
                $expiry = get_post_meta( get_the_ID(), 'spp_blog_expiry', true );
                if ( $expiry && $expiry < date('Y-m-d') ) continue;
            ?>
                <div class="spp-blog-item">
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p class="spp-post-meta">by <?php the_author(); ?> | <?php echo get_the_date(); ?></p>
                    <div class="spp-blog-excerpt"><?php the_excerpt(); ?></div>
                </div>
                <hr>
            <?php endwhile; else : ?>
                <p>No blog posts found in this category.</p>
            <?php endif; ?>
        </div>
        <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
            <?php echo do_shortcode('[spp_side_nav]'); ?>
        </div>
    </div>
</div>
<?php
get_footer();