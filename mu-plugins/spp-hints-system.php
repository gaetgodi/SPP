<?php
/**
 * Plugin Name: SPP Hints System
 * Description: Pickleball hints and strategies CPT for Stouffville Pickleball Players.
 * Version: 1.4.0
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
    # [spp_hints] shortcode
    # Renders accordion list of all published hints
    # Uses inline styles via JS to beat Divi CSS resets
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

        $teal     = '#00897B';
        $darkteal = '#004D40';

        $output = '<div class="spp-hints" style="font-family: inherit;">';

        // Category filter dropdown (only if more than one category)
        if ( count( $categories ) > 1 ) {
            $output .= '<div style="margin-bottom:1.5em;display:flex;align-items:center;gap:0.75em;flex-wrap:wrap;">';
            $output .= '<label for="spp-hints-select" style="font-weight:700;color:' . $teal . ';font-size:1em;white-space:nowrap;">Select Category:</label>';
            $output .= '<select id="spp-hints-select" style="'
                . 'flex:1;min-width:200px;max-width:500px;'
                . 'padding:0.5em 2.2em 0.5em 0.9em;'
                . 'border:2px solid ' . $teal . ';'
                . 'border-radius:4px;'
                . 'font-size:1em;'
                . 'color:#333;'
                . 'background-color:#fff;'
                . 'background-image:url(\'data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="%23333" d="M5 7l5 5 5-5z"/></svg>\');'
                . 'background-repeat:no-repeat;'
                . 'background-position:right 0.6em center;'
                . 'background-size:1.2em;'
                . 'appearance:none;-webkit-appearance:none;'
                . 'cursor:pointer;'
                . '">';
            $output .= '<option value="all">All Categories</option>';
            foreach ( $categories as $slug => $name ) {
                $output .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $name ) . '</option>';
            }
            $output .= '</select>';
            $output .= '</div>';
        }

        // Accordion hints list
        $output .= '<ul class="spp-hints-list" style="list-style:none;padding:0;margin:0;">';
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
            $item_id   = 'spp-hint-' . $hint->ID;

            $output .= '<li class="spp-hint-item" data-categories="' . $data_cats . '" style="border:1px solid #ddd;border-radius:6px;margin-bottom:0.6em;overflow:hidden;background:#fff;">';

            // Toggle button
            $output .= '<button class="spp-hint-toggle" aria-expanded="false" aria-controls="' . $item_id . '" style="width:100%;text-align:left;background:none;border:none;padding:0.5em 0.8em;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:1em;box-sizing:border-box;">';
            $output .= '<span style="display:flex;align-items:center;gap:0.5em;flex:1;">';
            if ( ! empty( $cat_names ) ) {
                $output .= '<span style="font-size:0.90em;color:#fff;background:' . $teal . ';border-radius:3px;padding:4px 10px;white-space:nowrap;flex-shrink:0;">' . implode( ', ', $cat_names ) . '</span>';
            }
            $output .= '<span style="font-weight:600;color:#333;font-size:1em;">' . esc_html( $hint->post_title ) . '</span>';
            $output .= '</span>';
            $output .= '<span class="spp-hint-arrow" style="font-size:0.8em;color:#999;flex-shrink:0;display:inline-block;transition:transform 0.2s;">▼</span>';
            $output .= '</button>';

            // Content — hidden by default via inline style
            $output .= '<div class="spp-hint-content" id="' . $item_id . '" style="display:none;padding:0.8em 1.2em 1.2em;border-top:1px solid #eee;color:#444;line-height:1.7;">';
            $output .= wpautop( wp_kses_post( $hint->post_content ) );
            $output .= '</div>';

            $output .= '</li>';
        }
        $output .= '</ul>';

        // JS for accordion and dropdown filter
        $output .= '
        <script>
        (function() {
            // Accordion
            document.querySelectorAll(".spp-hint-toggle").forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var item    = this.closest(".spp-hint-item");
                    var content = item.querySelector(".spp-hint-content");
                    var arrow   = item.querySelector(".spp-hint-arrow");
                    var isOpen  = content.style.display === "block";

                    // Close all
                    document.querySelectorAll(".spp-hint-content").forEach(function(c) {
                        c.style.display = "none";
                    });
                    document.querySelectorAll(".spp-hint-arrow").forEach(function(a) {
                        a.style.transform = "rotate(0deg)";
                    });
                    document.querySelectorAll(".spp-hint-toggle").forEach(function(b) {
                        b.setAttribute("aria-expanded", "false");
                        b.style.background = "none";
                    });

                    // Open clicked if it was closed
                    if (!isOpen) {
                        content.style.display = "block";
                        arrow.style.transform = "rotate(180deg)";
                        this.setAttribute("aria-expanded", "true");
                        this.style.background = "#f5fafa";
                    }
                });

                // Hover effect
                btn.addEventListener("mouseenter", function() {
                    if (this.getAttribute("aria-expanded") !== "true") {
                        this.style.background = "#f5fafa";
                    }
                });
                btn.addEventListener("mouseleave", function() {
                    if (this.getAttribute("aria-expanded") !== "true") {
                        this.style.background = "none";
                    }
                });
            });

            // Dropdown category filter
            var select = document.getElementById("spp-hints-select");
            if (select) {
                select.addEventListener("change", function() {
                    var filter = this.value;

                    // Close all open hints first
                    document.querySelectorAll(".spp-hint-content").forEach(function(c) {
                        c.style.display = "none";
                    });
                    document.querySelectorAll(".spp-hint-arrow").forEach(function(a) {
                        a.style.transform = "rotate(0deg)";
                    });
                    document.querySelectorAll(".spp-hint-toggle").forEach(function(b) {
                        b.setAttribute("aria-expanded", "false");
                        b.style.background = "none";
                    });

                    // Show/hide items
                    document.querySelectorAll(".spp-hint-item").forEach(function(item) {
                        var cats = item.getAttribute("data-categories");
                        if (filter === "all" || cats.indexOf(filter) !== -1) {
                            item.style.display = "block";
                        } else {
                            item.style.display = "none";
                        }
                    });
                });
            }
        })();
        </script>';

        $output .= '</div>';
        return $output;
    }
}

new SPP_Hints_System();
