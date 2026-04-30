<?php
// Suppress deprecation warnings from outdated plugins on frontend
if (!is_admin()) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}
// Enqueue parent and child theme styles
function divi_spp_child_enqueue_styles() {
    wp_enqueue_style( 'divi-parent-style', get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'spp-tokens',   get_stylesheet_directory_uri() . '/css/spp-tokens.css',   ['divi-style'], '1.0.0' );
    wp_enqueue_style( 'spp-drawers',  get_stylesheet_directory_uri() . '/css/spp-drawers.css',  ['spp-tokens'], '1.0.0' );
    wp_enqueue_style( 'spp-dashboard',get_stylesheet_directory_uri() . '/css/spp-dashboard.css',['spp-tokens'], '1.0.0' );
    wp_enqueue_script( 'spp-drawers', get_stylesheet_directory_uri() . '/js/spp-drawers.js', [], '1.0.0', true );

    // FAQ System (CSS + JS - only on FAQ page)
    if (is_page('faq')) {
        wp_enqueue_style(
            'spp-faq',
            get_stylesheet_directory_uri() . '/css/faq.css',
            ['spp-tokens'],
            filemtime(get_stylesheet_directory() . '/css/faq.css')
        );
        wp_enqueue_script(
            'spp-faq',
            get_stylesheet_directory_uri() . '/js/faq-system.js',
            [],
            filemtime(get_stylesheet_directory() . '/js/faq-system.js'),
            true
        );
    }
}
add_action( 'wp_enqueue_scripts', 'divi_spp_child_enqueue_styles' );

// Core includes
require_once get_stylesheet_directory() . '/inc/menus.php';
require_once get_stylesheet_directory() . '/inc/shortcodes.php';
require_once get_stylesheet_directory() . '/inc/blog-roles.php';
add_filter('template_include', function($template) {
    if (is_singular('tribe_events')) {
        error_log('TEC single event template: ' . $template);
    }
    return $template;
}, 100);
// Restrict category archive pages to published posts only
add_action('pre_get_posts', function($query) {
    if ($query->is_category() && $query->is_main_query() && !is_admin()) {
        $query->set('post_type', 'post');
        $query->set('post_status', 'publish');
    }
});
// Restrict cmruncode pages to editors and admins only, with exceptions for member-facing pages
add_action('template_redirect', function() {
    if (!is_page()) return;
    if (current_user_can('administrator') || current_user_can('editor')) return;
    
    $member_pages = [1517, 20003754, 20003889, 20009040, 20009451, 20005967];
    
    global $post;
    if ($post && !in_array($post->ID, $member_pages) && has_shortcode($post->post_content, 'cmruncode')) {
        wp_redirect(home_url());
        exit;
    }
});
add_action('wp_footer', function() {
    if (!is_singular('tribe_events')) return;
    ?>
    <script>
    window.onerror = function(message, source, line, col, error) {
        fetch('/js-error-log.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: message, source: source, line: line})
        });
    };
    </script>
    <?php
});
