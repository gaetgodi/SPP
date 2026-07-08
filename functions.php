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
    wp_enqueue_style( 'spp-footer',   get_stylesheet_directory_uri() . '/css/spp-footer.css',   ['spp-tokens'], '1.0.0' );
    wp_enqueue_style( 'spp-header',   get_stylesheet_directory_uri() . '/css/spp-header.css',   ['spp-tokens'], '1.0.0' );
    wp_enqueue_style( 'spp-registrant-list', get_stylesheet_directory_uri() . '/css/spp-registrant-list.css', array(), '3.0.0' );


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
// require_once get_stylesheet_directory() . '/inc/spp-registrant-list.php'; // Superseded by gl-events plugin
require_once get_stylesheet_directory() . '/inc/spp-blog-reminder.php';
require_once get_stylesheet_directory() . '/inc/spp-blog-system.php';
require_once get_stylesheet_directory() . '/inc/gl-schedule-production.php';
require_once get_stylesheet_directory() . '/inc/spp-schedule-admin.php';
require_once get_stylesheet_directory() . '/inc/spp-score-entry.php';
require_once get_stylesheet_directory() . '/inc/spp-score-correction.php';
require_once get_stylesheet_directory() . '/inc/spp-switch-players.php';
require_once get_stylesheet_directory() . '/inc/passkey-server.php';
require_once get_stylesheet_directory() . '/inc/passkey-endpoints.php';
require_once get_stylesheet_directory() . '/inc/passkey-profile.php';
require_once get_stylesheet_directory() . '/inc/passkey-login.php';

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

/* =========================================================
   ROLE HELPER
   Checks actual roles, not capabilities.
   Use this instead of current_user_can('editor') which
   returns true for subscribers with edit_posts capability
   added by spp_sync_blog_author_caps().
   ========================================================= */
function spp_is_admin_or_editor() {
    $roles = (array) wp_get_current_user()->roles;
    return in_array('administrator', $roles) || in_array('editor', $roles);
}
/* =========================================================
   LADDER ADMIN ROLE HELPER
   Checks for roles that can access ladder admin features
   such as rank history for any player.
   Currently: administrator, editor, ladder-cop
   ========================================================= */
function spp_is_ladder_admin() {
    $roles = (array) wp_get_current_user()->roles;
    return in_array('administrator', $roles)
        || in_array('editor', $roles)
        || in_array('ladder-cop', $roles);
}
/* =========================================================
   PAGE ACCESS RESTRICTION
   Restricts cmruncode pages to editors and admins only,
   with exceptions for member-facing pages.
   ========================================================= */
add_action('template_redirect', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!is_page()) return;
    if (spp_is_admin_or_editor()) return;

    $member_pages = [1517, 20003754, 20003889, 20009040, 20009451, 20005967, 20009765, 20009901, 1948, 20006331, 20010179, 20010257, 20010267];

    global $post;
    if ($post && !in_array($post->ID, $member_pages) && has_shortcode($post->post_content, 'cmruncode')) {
        wp_redirect(home_url());
        exit;
    }
});

/* =========================================================
   JS ERROR LOGGING
   Logs frontend JS errors for TEC single event pages.
   ========================================================= */
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
/**
 * Auto-redirect to edit mode on UM profile pages for admins and editors
 */
add_action( 'template_redirect', function() {
    if ( ! is_user_logged_in() ) return;
    if ( ! um_is_core_page( 'user' ) ) return;
    if ( isset( $_GET['um_action'] ) ) return;
    
    $current_user = wp_get_current_user();
    if ( array_intersect( ['administrator', 'editor'], (array) $current_user->roles ) ) {
        wp_redirect( add_query_arg( 'um_action', 'edit', $_SERVER['REQUEST_URI'] ) );
        exit;
    }
});
// ── Add Passkeys tab to UM Account page ───────────────────────────────────────
add_filter( 'um_account_page_default_tabs_hook', function( $tabs ) {
    $tabs['passkeys'] = array(
        'icon'       => 'um-faicon-key',
        'title'      => 'Passkeys',
        'custom'     => true,
        'show_button'=> false,
    );
    return $tabs;
}, 100 );

add_filter( 'um_account_content_hook_passkeys', function( $output, $shortcode_args ) {
    return do_shortcode( '[spp_passkey_profile]' );
}, 10, 2 );
// ─────────────────────────────────────────────────────────────────────────────