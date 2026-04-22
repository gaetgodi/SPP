<?php
/**
 * Plugin Name: SPP Hints System
 * Description: Pickleball hints and strategies CPT for Stouffville Pickleball Players.
 * Version: 1.1.0
 * Author: Stouffville Pickleball Players
 *
 * FILE LOCATION: /wp-content/mu-plugins/spp-hints-system.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SPP_Hints_System {

    const POST_TYPE = 'spp_hint';
    const TAXONOMY  = 'spp_hint_category';

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',        array( $this, 'custom_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column',  array( $this, 'custom_column_content' ), 10, 2 );
        add_shortcode( 'spp_hints', array( $this, 'hints_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    /*------------------------------------------------
    # Register CPT
    ------------------------------------------------*/
    public function register_post_type() {
        $labels = array(
            'name'               => 'Hints',
            'singular_name'      => 'Hint',
            'menu_name'          => 'Hints',
            'add_new'            => 'Add New Hint',
            'add_new_item'       => 'Add New Hint',
            'edit_item'          => 'Edit Hint',
            'new_item'           => 'New Hint',
            'view_item'          => 'View Hint',
            'search_items'       => 'Search Hints',
            'not_found'          => 'No hints found',
            'not_found_in_trash' => 'No hints found in trash',
            'all_items'          => 'All Hints',
        );

        register_post_type( self::POST_TYPE, array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => false,
            'show_in_admin_bar'  => true,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 22,
            'menu_icon'          => 'dashicons-lightbulb',
            'show_in_rest'       => true,
            'supports'           => array( 'title', 'editor', 'revisions' ),
        ) );
    }

    /*------------------------------------------------
    # Register Taxonomy
    ------------------------------------------------*/
    public function register_taxonomy() {
        $labels = array(
            'name'          => 'Hint Categories',
            'singular_name' => 'Hint Category',
            'menu_name'     => 'Categories',
            'all_items'     => 'All Categories',
            'edit_item'     => 'Edit Category',
            'add_new_item'  => 'Add New Category',
        );

        register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => false,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );
    }

    /*------------------------------------------------
    # Admin columns
    ------------------------------------------------*/
    public function custom_columns( $columns ) {
        return array(
            'cb'            => $columns['cb'],
            'title'         => 'Title',
            'hint_category' => 'Category',
            'date'          => 'Date',
        );
    }

    public function custom_column_content( $column, $post_id ) {
        if ( $column === 'hint_category' ) {
            $terms = get_the_terms( $post_id, self::TAXONOMY );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $names = wp_list_pluck( $terms, 'name' );
                echo esc_html( implode( ', ', $names ) );
            } else {
                echo '—';
            }
        }
    }

    /*------------------------------------------------
    # Enqueue inline styles
    ------------------------------------------------*/
    public function enqueue_styles() {
        wp_add_inline_style( 'spp-tokens', '
            /* Hints filter buttons */
            .spp-hints-filter {
                margin-bottom: 1.5em;
            }
            .spp-hints-filter button {
                background: var(--spp-teal, #42afa3);
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 6px 14px;
                margin: 0 4px 6px 0;
                cursor: pointer;
                font-size: 0.9em;
            }
            .spp-hints-filter button.active,
            .spp-hints-filter button:hover {
                background: var(--spp-dark-teal, #2d8a7e);
            }

            /* Hints accordion list */
            .spp-hints-list {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .spp-hint-item {
                border: 1px solid #ddd;
                border-radius: 6px;
                margin-bottom: 0.6em;
                overflow: hidden;
                background: #fff;
            }
            .spp-hint-item.hidden {
                display: none;
            }

            /* Accordion toggle button */
            .spp-hint-toggle {
                width: 100%;
                text-align: left;
                background: none;
                border: none;
                padding: 0.9em 1.2em;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1em;
            }
            .spp-hint-toggle:hover {
                background: #f5fafa;
            }
            .spp-hint-toggle-left {
                display: flex;
                align-items: center;
                gap: 0.7em;
                flex: 1;
            }
            .spp-hint-category-badge {
                font-size: 0.75em;
                color: #fff;
                background: var(--spp-teal, #42afa3);
                border-radius: 3px;
                padding: 2px 7px;
                white-space: nowrap;
            }
            .spp-hint-title {
                font-weight: 600;
                color: #333;
                font-size: 1em;
            }
            .spp-hint-arrow {
                font-size: 0.8em;
                color: #999;
                transition: transform 0.2s;
                flex-shrink: 0;
            }
            .spp-hint-item.open .spp-hint-arrow {
                transform: rotate(180deg);
            }

            /* Accordion content */
            .spp-hint-content {
                display: none;
                padding: 0 1.2em 1.2em;
                border-top: 1px solid #eee;
                color: #444;
                line-height: 1.7;
            }
            .spp-hint-item.open .spp-hint-content {
                display: block;
            }
        ' );
    }

    /*------------------------------------------------
    # [spp_hints] shortcode
    # Renders accordion list of all published hints
    # with optional category filter buttons
    ------------------------------------------------*/
    public function hints_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'category' => '',
        ), $atts );

        $hints = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        if ( empty( $hints ) ) {
            return '<p>No hints available yet.</p>';
        }

        // Collect all categories used by published hints
        $categories = array();
        foreach ( $hints as $hint ) {
            $terms = get_the_terms( $hint->ID, self::TAXONOMY );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $categories[ $term->slug ] = $term->name;
                }
            }
        }

        $output = '<div class="spp-hints">';

        // Category filter buttons (only if more than one category)
        if ( count( $categories ) > 1 ) {
            $output .= '<div class="spp-hints-filter">';
            $output .= '<button class="active" data-filter="all">All</button>';
            foreach ( $categories as $slug => $name ) {
                $output .= '<button data-filter="' . esc_attr( $slug ) . '">' . esc_html( $name ) . '</button>';
            }
            $output .= '</div>';
        }

        // Accordion hints list
        $output .= '<ul class="spp-hints-list">';
        foreach ( $hints as $hint ) {
            $terms     = get_the_terms( $hint->ID, self::TAXONOMY );
            $cat_slugs = array();
            $cat_names = array();
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $cat_slugs[] = esc_attr( $term->slug );
                    $cat_names[] = esc_html( $term->name );
                }
            }

            $data_cats  = implode( ' ', $cat_slugs );
            $item_id    = 'spp-hint-' . $hint->ID;

            $output .= '<li class="spp-hint-item" data-categories="' . $data_cats . '">';
            $output .= '<button class="spp-hint-toggle" aria-expanded="false" aria-controls="' . $item_id . '">';
            $output .= '<span class="spp-hint-toggle-left">';
            if ( ! empty( $cat_names ) ) {
                $output .= '<span class="spp-hint-category-badge">' . implode( ', ', $cat_names ) . '</span>';
            }
            $output .= '<span class="spp-hint-title">' . esc_html( $hint->post_title ) . '</span>';
            $output .= '</span>';
            $output .= '<span class="spp-hint-arrow">▼</span>';
            $output .= '</button>';
            $output .= '<div class="spp-hint-content" id="' . $item_id . '">';
            $output .= wpautop( wp_kses_post( $hint->post_content ) );
            $output .= '</div>';
            $output .= '</li>';
        }
        $output .= '</ul>';

        // Accordion + filter JS
        $output .= '
        <script>
        (function() {
            // Accordion
            document.querySelectorAll(".spp-hint-toggle").forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var item = this.closest(".spp-hint-item");
                    var isOpen = item.classList.contains("open");
                    // Close all
                    document.querySelectorAll(".spp-hint-item.open").forEach(function(el) {
                        el.classList.remove("open");
                        el.querySelector(".spp-hint-toggle").setAttribute("aria-expanded", "false");
                    });
                    // Open clicked if it was closed
                    if (!isOpen) {
                        item.classList.add("open");
                        this.setAttribute("aria-expanded", "true");
                    }
                });
            });

            // Category filter
            var buttons = document.querySelectorAll(".spp-hints-filter button");
            var items   = document.querySelectorAll(".spp-hint-item");
            buttons.forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var filter = this.getAttribute("data-filter");
                    buttons.forEach(function(b) { b.classList.remove("active"); });
                    this.classList.add("active");
                    items.forEach(function(item) {
                        if (filter === "all" || item.getAttribute("data-categories").indexOf(filter) !== -1) {
                            item.classList.remove("hidden");
                        } else {
                            item.classList.add("hidden");
                            item.classList.remove("open");
                        }
                    });
                });
            });
        })();
        </script>';

        $output .= '</div>';
        return $output;
    }
}

new SPP_Hints_System();