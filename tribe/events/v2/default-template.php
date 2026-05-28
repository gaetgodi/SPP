<?php
use Tribe\Events\Views\V2\Template_Bootstrap;
get_header();
?>
<div id="et-main-area" style="background-color: var(--spp-bg-page); padding: 2rem 0;">
    <div class="et_pb_row et_flex_row spp-two-col-row">
        <div class="et_pb_column et_flex_column et_flex_column_18_24" id="content_column">
            <?php echo tribe( Template_Bootstrap::class )->get_view_html(); ?>
        </div>
        <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
            <?php echo do_shortcode('[spp_side_nav]'); ?>
        </div>
    </div>
</div>
<?php
get_footer();