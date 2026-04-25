<?php
get_header();
?>
<div style="display:flex; gap:20px;">
    <div style="flex:3;">
        <?php while ( have_posts() ) : the_post(); ?>
            <?php tribe_get_template_part( 'single-event' ); ?>
        <?php endwhile; ?>
    </div>
    <div style="flex:1;">
        <?php echo do_shortcode('[spp_side_nav]'); ?>
    </div>
</div>
<?php
get_footer();