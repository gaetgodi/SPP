<?php
// Enqueue parent and child theme styles
function divi_spp_child_enqueue_styles() {
    wp_enqueue_style( 'divi-parent-style', get_template_directory_uri() . '/style.css' );
}
add_action( 'wp_enqueue_scripts', 'divi_spp_child_enqueue_styles' );
