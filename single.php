<?php
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
                global $wpdb;
                $categories = get_the_category();
                $blog_cats = [];
                foreach ($categories as $cat) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} p 
                         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                         WHERE tt.term_id = %d 
                         AND p.post_type = 'post' 
                         AND p.post_status = 'publish'",
                        $cat->term_id
                    ));
                    if ($count > 0) {
                        $blog_cats[] = '<a href="' . get_category_link($cat->term_id) . '">' . esc_html($cat->name) . '</a>';
                    }
                }
                if (!empty($blog_cats)) {
                    echo '<p class="spp-post-categories">Filed under: ' . implode(', ', $blog_cats) . '</p>';
                    
                }
                <?php
// Browse all blog categories
$all_cats = get_categories(['hide_empty' => true]);
$all_blog_cats = [];
foreach ($all_cats as $cat) {
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p 
         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE tt.term_id = %d 
         AND p.post_type = 'post' 
         AND p.post_status = 'publish'",
        $cat->term_id
    ));
    if ($count > 0) {
        $all_blog_cats[] = '<a href="' . get_category_link($cat->term_id) . '">' . esc_html($cat->name) . ' (' . $count . ')</a>';
    }
}
if (!empty($all_blog_cats)) {
    echo '<p class="spp-post-categories">Browse all blog categories: ' . implode(' | ', $all_blog_cats) . '</p>';
}
?>
                ?>
            <?php endwhile; ?>
        </div>
        <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
            <?php echo do_shortcode('[spp_side_nav]'); ?>
        </div>
    </div>
</div>
<?php
get_footer();