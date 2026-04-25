<?php
get_header();
?>
<div style="display:flex; gap:20px;">
    <div style="flex:3; background:yellow;">
        COLUMN 1 START
        <?php while ( have_posts() ) : the_post(); ?>
            <?php tribe_get_template_part( 'single-event' ); ?>
        <?php endwhile; ?>
        COLUMN 1 END
    </div>
    <div style="flex:1; background:lightblue;">
        COLUMN 2 START
        <?php echo do_shortcode('[spp_side_nav]'); ?>
        COLUMN 2 END
    </div>
</div>
<?php
get_footer();