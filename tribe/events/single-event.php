<?php
/**
 * Single Event Template - SPP Custom Override
 * Two-column layout with side nav
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( '-1' );
}

$events_label_singular = tribe_get_event_label_singular();
$events_label_plural   = tribe_get_event_label_plural();
$event_id = Tribe__Events__Main::postIdHelper( get_the_ID() );
$event_id = apply_filters( 'tec_events_single_event_id', $event_id );
$title_classes = apply_filters( 'tribe_events_single_event_title_classes', [ 'tribe-events-single-event-title' ], $event_id );
$title_classes = implode( ' ', tribe_get_classes( $title_classes ) );
$before = apply_filters( 'tribe_events_single_event_title_html_before', '<h1 class="' . $title_classes . '">', $event_id );
$after  = apply_filters( 'tribe_events_single_event_title_html_after', '</h1>', $event_id );
$title  = apply_filters( 'tribe_events_single_event_title_html', the_title( $before, $after, false ), $event_id );
$cost   = tribe_get_formatted_cost( $event_id );
?>

<div class="et_pb_row et_flex_row spp-two-col-row">

    <div class="et_pb_column et_flex_column et_flex_column_18_24" id="content_column">

        <div id="tribe-events-content" class="tribe-events-single">

            <p class="tribe-events-back">
                <a href="<?php echo esc_url( tribe_get_events_link() ); ?>">&laquo; <?php printf( esc_html_x( 'All %s', '%s Events plural label', 'the-events-calendar' ), $events_label_plural ); ?></a>
            </p>

            <?php tribe_the_notices() ?>

            <?php echo $title; ?>

            <div class="tribe-events-schedule tribe-clearfix">
                <?php echo tribe_events_event_schedule_details( $event_id, '<div>', '</div>' ); ?>
                <?php if ( ! empty( $cost ) ) : ?>
                    <span class="tribe-events-cost"><?php echo esc_html( $cost ) ?></span>
                <?php endif; ?>
            </div>

            <div id="tribe-events-header" <?php tribe_events_the_header_attributes() ?>>
                <nav class="tribe-events-nav-pagination" aria-label="<?php printf( esc_html__( '%s Navigation', 'the-events-calendar' ), $events_label_singular ); ?>">
                    <ul class="tribe-events-sub-nav">
                        <li class="tribe-events-nav-previous"><?php tribe_the_prev_event_link( '<span>&laquo;</span> %title%' ) ?></li>
                        <li class="tribe-events-nav-next"><?php tribe_the_next_event_link( '%title% <span>&raquo;</span>' ) ?></li>
                    </ul>
                </nav>
            </div>

            <?php while ( have_posts() ) : the_post(); ?>
                <div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <?php echo tribe_event_featured_image( $event_id, 'full', false ); ?>
                    <?php do_action( 'tribe_events_single_event_before_the_content' ) ?>
                    <div class="tribe-events-single-event-description tribe-events-content">
                        <?php the_content(); ?>
                    </div>
                    <?php do_action( 'tribe_events_single_event_after_the_content' ) ?>
                    <?php do_action( 'tribe_events_single_event_before_the_meta' ) ?>
                    <?php tribe_get_template_part( 'modules/meta' ); ?>
                    <?php do_action( 'tribe_events_single_event_after_the_meta' ) ?>
                </div>
                <?php if ( get_post_type() == Tribe__Events__Main::POSTTYPE && tribe_get_option( 'showComments', false ) ) comments_template() ?>
            <?php endwhile; ?>

            <div id="tribe-events-footer">
                <nav class="tribe-events-nav-pagination" aria-label="<?php printf( esc_html__( '%s Navigation', 'the-events-calendar' ), $events_label_singular ); ?>">
                    <ul class="tribe-events-sub-nav">
                        <li class="tribe-events-nav-previous"><?php tribe_the_prev_event_link( '<span>&laquo;</span> %title%' ) ?></li>
                        <li class="tribe-events-nav-next"><?php tribe_the_next_event_link( '%title% <span>&raquo;</span>' ) ?></li>
                    </ul>
                </nav>
            </div>

        </div><!-- #tribe-events-content -->

    </div><!-- content column -->

    <div class="et_pb_column et_flex_column et-last-child et_flex_column_6_24">
        <?php echo do_shortcode('[spp_side_nav]'); ?>
    </div><!-- nav column -->

</div><!-- two col row -->