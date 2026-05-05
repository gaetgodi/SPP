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
require_once get_stylesheet_directory() . '/inc/score-scanner.php';

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
    
    $member_pages = [1517, 20003754, 20003889, 20009040, 20009451, 20005967, 20009765];
    
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
/* =========================================================
   LOGIN RESTRICTION — EXPIRED MEMBERSHIP
   Prevents login for members whose YrEndDt is not current year.
   Admins, editors, blog_moderators and blog_authors are exempt.
   ========================================================= */
add_filter('authenticate', function($user, $username, $password) {
    if (is_wp_error($user)) return $user;
    if (!$user) return $user;

    // Exempt administrators, editors, blog_moderators and blog_authors
    $exempt_roles = ['administrator', 'editor', 'blog_moderator', 'blog_author'];
    foreach ($exempt_roles as $role) {
        if (in_array($role, (array)$user->roles)) {
            return $user;
        }
    }
    $ok_to_login = get_user_meta($user->ID, 'OkToLogin', true);
if ($ok_to_login === 'Yes') return $user;

    $yr_end_dt = get_user_meta($user->ID, 'YrEndDt', true);
    $current_year_end = date('Y') . '-12-31';

    if (empty($yr_end_dt) || $yr_end_dt < $current_year_end) {
        return new WP_Error('invalid_membership', 
            'You are not currently registered with Stouffville Pickleball Players. Please renew your membership at <a href="https://www.pickleballcanada.org/club/stouffville-pickleball-players/">Pickleball Canada</a>.');
    }

    return $user;
}, 30, 3);
// Enqueue schedule CSS
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'spp-schedule',
        get_stylesheet_directory_uri() . '/css/spp-schedule.css',
        [],
        '1.0.0'
    );
});