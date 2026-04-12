<?php
// Enqueue parent and child theme styles
function divi_spp_child_enqueue_styles() {
    wp_enqueue_style( 'divi-parent-style', get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'spp-drawers', get_stylesheet_directory_uri() . '/css/spp-drawers.css', [], '1.0.0' );
    wp_enqueue_script( 'spp-drawers', get_stylesheet_directory_uri() . '/js/spp-drawers.js', [], '1.0.0', true );
}
add_action( 'wp_enqueue_scripts', 'divi_spp_child_enqueue_styles' );

// Core includes
require_once get_stylesheet_directory() . '/inc/menus.php';