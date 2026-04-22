<?php
/**
 * Plugin Name: SPP Hints System
 * Description: Pickleball hints and strategies CPT for Stouffville Pickleball Players.
 * Version: 1.0.0
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
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );
    }

    /*------------------------------------------------
    # Admin columns
    ------------------------------------------------*/
    public function custom_columns( $columns ) {
        return array(
            'cb'                              => $columns['cb'],
            'title'                           => 'Title',
            'taxonomy-' . self::TAXONOMY      => 'Category',
            'date'                            => 'Date',
        );
    }

    public function custom_column_content( $column, $post_id ) {
        // Taxonomy column is handled automatically by WordPress
    }

    /*------------------------------------------------
    # Enqueue inline styles
    ------------------------------------------------*/
    public function enqueue_styles() {
        wp_add_inline_style( 'spp-tokens', '
            .spp-hints-filter { margin-bottom: 1.5em; }
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
            .spp-hints-list { list-style: none; padding: 0; margin: 0; }
            .spp-hint-item {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 1.2em 1.4em;
                margin-bottom: 1em;
            }
            .spp-hint-item h4 { margin: 0 0 0.5em; color: var(--spp-teal, #42afa3); }
            .spp-hint-item .spp-hint-category {
                font-size: 0.8em;
                color: #888;
                margin-bottom: 0.5em;
            }
            .spp-hint-item.hidden { display: none; }
        ' );
    }

    /*------------------------------------------------
    # [spp_hints] shortcode
    # Renders filterable list of all published hints
    ------------------------------------------------*/
    public function hints_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'category' => '', // optional: pre-filter by category slug
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

        // Hints list
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

            $data_cats = implode( ' ', $cat_slugs );
            $output   .= '<li class="spp-hint-item" data-categories="' . $data_cats . '">';
            if ( ! empty( $cat_names ) ) {
                $output .= '<div class="spp-hint-category">' . implode( ', ', $cat_names ) . '</div>';
            }
            $output .= '<h4>' . esc_html( $hint->post_title ) . '</h4>';
            $output .= '<div class="spp-hint-content">' . wpautop( wp_kses_post( $hint->post_content ) ) . '</div>';
            $output .= '</li>';
        }
        $output .= '</ul>';

        // Filter JS
        $output .= '
        <script>
        (function() {
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
