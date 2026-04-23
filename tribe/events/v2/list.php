<?php
/**
 * View: List View — SPP Override
 *
 * Wraps the standard list view in a two-column layout
 * with the SPP side nav in the right column.
 *
 * Override location:
 * divi-spp-child/tribe/events/v2/list.php
 *
 * @version 6.12.0
 *
 * @var array    $events               The array containing the events.
 * @var string   $rest_url             The REST URL.
 * @var string   $rest_method          The HTTP method, either `POST` or `GET`, the View will use to make requests.
 * @var int      $should_manage_url    int containing if it should manage the URL.
 * @var bool     $disable_event_search Boolean on whether to disable the event search.
 * @var string[] $container_classes    Classes used for the container of the view.
 * @var array    $container_data       An additional set of container `data` attributes.
 * @var string   $breakpoint_pointer   String we use as pointer to the current view we are setting up with breakpoints.
 */

$header_classes = [ 'tribe-events-header' ];
if ( empty( $disable_event_search ) ) {
    $header_classes[] = 'tribe-events-header--has-event-search';
}
?>

<div class="spp-events-layout">

    <div class="spp-events-main">

        <div
            <?php tec_classes( $container_classes ); ?>
            data-js="tribe-events-view"
            data-view-rest-url="<?php echo esc_url( $rest_url ); ?>"
            data-view-rest-method="<?php echo esc_attr( $rest_method ); ?>"
            data-view-manage-url="<?php echo esc_attr( $should_manage_url ); ?>"
            <?php foreach ( $container_data as $key => $value ) : ?>
                data-view-<?php echo esc_attr( $key ) ?>="<?php echo esc_attr( $value ) ?>"
            <?php endforeach; ?>
            <?php if ( ! empty( $breakpoint_pointer ) ) : ?>
                data-view-breakpoint-pointer="<?php echo esc_attr( $breakpoint_pointer ); ?>"
            <?php endif; ?>
        >
            <section class="tribe-common-l-container tribe-events-l-container">
                <?php $this->template( 'components/loader', [ 'text' => __( 'Loading...', 'the-events-calendar' ) ] ); ?>

                <?php $this->template( 'components/json-ld-data' ); ?>

                <?php $this->template( 'components/data' ); ?>

                <?php $this->template( 'components/before' ); ?>

                <?php $this->template( 'components/header' ); ?>

                <?php $this->template( 'components/filter-bar' ); ?>

                <ul
                    class="tribe-events-calendar-list"
                    aria-label="
                    <?php
                        /* translators: %s: Events (plural) */
                        echo esc_attr( sprintf( __( 'List of %s', 'the-events-calendar' ), tribe_get_event_label_plural() ) );
                    ?>
                    "
                >
                    <?php foreach ( $events as $event ) : ?>
                        <?php $this->setup_postdata( $event ); ?>

                        <?php $this->template( 'list/month-separator', [ 'event' => $event ] ); ?>

                        <?php $this->template( 'list/event', [ 'event' => $event ] ); ?>

                    <?php endforeach; ?>
                </ul>

                <?php $this->template( 'list/nav' ); ?>

                <?php $this->template( 'components/ical-link' ); ?>

                <?php $this->template( 'components/after' ); ?>

            </section>
        </div>

    </div><!-- .spp-events-main -->

    <div class="spp-events-sidebar">
        <?php echo do_shortcode( '[spp_side_nav]' ); ?>
    </div><!-- .spp-events-sidebar -->

</div><!-- .spp-events-layout -->

<?php $this->template( 'components/breakpoints' ); ?>
