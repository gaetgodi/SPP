<?php
get_header();
?>
<div id="et-main-area" style="background-color: var(--spp-bg-page); padding: 2rem 0;">
    <div class="et_pb_row et_flex_row spp-two-col-row">
        <div class="et_pb_column et_flex_column et_flex_column_18_24" id="content_column">
            <?php while ( have_posts() ) : the_post(); ?>
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            <?php endwhile; ?>
        </div>
        <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
            <?php echo do_shortcode('[spp_side_nav]'); ?>
        </div>
    </div>
</div>
<?php
get_footer();