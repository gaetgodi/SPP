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
require_once get_stylesheet_directory() . '/inc/spp-album-taxonomy.php'; // must load before flickr-import/media-curator/gallery below
require_once get_stylesheet_directory() . '/inc/spp-flickr-import.php';
require_once get_stylesheet_directory() . '/inc/spp-media-curator.php';
require_once get_stylesheet_directory() . '/inc/spp-gallery.php';
require_once get_stylesheet_directory() . '/inc/spp-gallery-builder.php'; // depends on spp_gallery_get_ids() above
require_once get_stylesheet_directory() . '/inc/blog-roles.php';
require_once get_stylesheet_directory() . '/inc/score-scanner.php';
// require_once get_stylesheet_directory() . '/inc/spp-registrant-list.php'; // Superseded by gl-events plugin
require_once get_stylesheet_directory() . '/inc/spp-blog-reminder.php';
require_once get_stylesheet_directory() . '/inc/spp-blog-system.php';
require_once get_stylesheet_directory() . '/inc/spp-membership-tags-refresh.php'; // must load before spp-create-membership-table.php below, which calls spp_refresh_membership_tags() directly
require_once get_stylesheet_directory() . '/inc/spp-create-membership-table.php'; // must load before gl-schedule-production/spp-score-correction/spp-change-new-user-rank below, which now call spp_create_membership_table() directly instead of via CM102's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-change-new-user-rank.php'; // calls spp_create_membership_table() directly -- must load after spp-create-membership-table.php above; no direct tracked-file caller otherwise, only reached via CM219's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-random-ranks.php'; // must load before spp-assign-ranks-to-registered-players.php below, which calls spp_random_ranks() directly
require_once get_stylesheet_directory() . '/inc/spp-create-view.php'; // must load before gl-schedule-production below, which now calls spp_create_view() directly instead of via CM254's [cmruncode] (spp-schedule-production.php, updated the same way, isn't in this require chain at all -- see its own note)
require_once get_stylesheet_directory() . '/inc/spp-assign-ranks-to-registered-players.php'; // must load before gl-schedule-production/spp-schedule-production below, which now call spp_assign_ranks_to_registered_players() directly instead of via CM279's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-pdf-skills-assessment.php'; // no direct tracked-file caller -- only reached via CM258's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-pdf-constitution.php'; // no direct tracked-file caller -- only reached via CM260's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-pdf-instructor-subsidy.php'; // no direct tracked-file caller -- only reached via CM261's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-remove-inactive-ladder-users.php'; // no direct tracked-file caller -- only reached via CM176's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-score-scanner-ui.php'; // no direct tracked-file caller -- only reached via CM268's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-rank-history.php'; // no direct tracked-file caller -- only reached via CM272's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-show-results.php'; // no direct tracked-file caller -- only reached via CM80's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-remove-user-from-ladder.php'; // no direct tracked-file caller -- only reached via CM82's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-blank-scores-colour.php'; // calls spp_create_view() directly -- must load after spp-create-view.php above; no direct tracked-file caller otherwise, only reached via CM194's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-membership-tags-refresh-ui.php'; // calls spp_refresh_membership_tags() directly -- must load after spp-membership-tags-refresh.php above; no direct tracked-file caller otherwise, only reached via CM208's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-score-review-grid.php'; // calls spp_create_view() directly -- must load after spp-create-view.php above; no direct tracked-file caller otherwise, only reached via CM215's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-copy-ranks-to-user-profile.php'; // calls spp_create_membership_table() directly -- must load after spp-create-membership-table.php above, and before spp-apply-override-to-results-table.php below, which now calls spp_copy_ranks_to_user_profile() directly instead of via CM66's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-update-club-ratings.php'; // must load before spp-apply-override-to-results-table.php below, which now calls spp_update_club_ratings() directly instead of via CM284's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-apply-override-to-results-table.php'; // calls spp_create_membership_table(), spp_copy_ranks_to_user_profile(), and spp_update_club_ratings() directly -- must load after all three above; no direct tracked-file caller otherwise, only reached via CM52's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-full-form-for-ladder.php'; // must load before spp-gl-ladder-events-dropdown.php below, which calls spp_full_form_for_ladder() directly instead of via CM270's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-short-form-for-ladder.php'; // must load before spp-gl-ladder-events-dropdown.php below, which calls spp_short_form_for_ladder() directly instead of via CM271's [cmruncode]
require_once get_stylesheet_directory() . '/inc/spp-gl-ladder-events-dropdown.php'; // no direct tracked-file caller -- only reached via CM275's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/spp-scores-events-dropdown.php'; // no direct tracked-file caller -- only reached via CM273's [cmruncode] shim; independent of the ladder-events chain above
require_once get_stylesheet_directory() . '/inc/spp-schedule-before-after-comparison.php'; // no direct tracked-file caller -- only reached via CM264's [cmruncode] shim
require_once get_stylesheet_directory() . '/inc/gl-schedule-production.php';
require_once get_stylesheet_directory() . '/inc/gl-publish-schedule.php';
require_once get_stylesheet_directory() . '/inc/spp-schedule-admin.php';
require_once get_stylesheet_directory() . '/inc/spp-score-entry.php';
require_once get_stylesheet_directory() . '/inc/spp-score-correction.php';
require_once get_stylesheet_directory() . '/inc/spp-switch-players.php';
require_once get_stylesheet_directory() . '/inc/passkey-server.php';
require_once get_stylesheet_directory() . '/inc/passkey-endpoints.php';
require_once get_stylesheet_directory() . '/inc/passkey-profile.php';
require_once get_stylesheet_directory() . '/inc/passkey-login.php';
require_once get_stylesheet_directory() . '/inc/spp-schedule-check.php';
require_once get_stylesheet_directory() . '/inc/spp-membership-editor.php';
require_once get_stylesheet_directory() . '/inc/spp-schedule-adjust.php';
require_once get_stylesheet_directory() . '/inc/spp-club-rating.php';
require_once get_stylesheet_directory() . '/inc/spp-create-results.php';
require_once get_stylesheet_directory() . '/inc/gl-player-schedule-view.php';
require_once get_stylesheet_directory() . '/inc/spp-report-table.php'; // must load before spp-reports.php below, which calls spp_render_report_table()
require_once get_stylesheet_directory() . '/inc/spp-report-variants.php'; // must load before spp-reports.php below, which calls spp_get_report_variant()
require_once get_stylesheet_directory() . '/inc/spp-reports.php';
require_once get_stylesheet_directory() . '/inc/spp-report-generator-admin.php'; // must load after spp-reports.php, which defines spp_report_filter_columns()
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

   UPDATE (2026-09-06): this gate detects an "admin tool page" purely
   by sniffing post_content for the literal [cmruncode] shortcode.
   Tonight's Code Manager migration replaced [cmruncode name="X"] with
   real [spp_x] shortcodes on 31 pages -- which silently made this
   check return false (no more "cmruncode" string in the content) and
   removed the restriction from all 22 of those pages that weren't
   already in $member_pages, including the site's highest-stakes page
   (Apply Override / Publish Results). Caught during Phase 3
   verification, fixed immediately: the check now also recognizes
   every shortcode tag migrated tonight that has a real page-level
   caller, restoring the exact same protection under the new names.
   Any FUTURE snippet migration that adds a new [spp_x] shortcode to
   a previously admin/editor-only cmruncode page must add its tag to
   this list too, or that page will silently lose this restriction
   the same way.
   ========================================================= */
add_action('template_redirect', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!is_page()) return;
    if (spp_is_admin_or_editor()) return;

    $member_pages = [1517, 20003754, 20003889, 20009040, 20009451, 20005967, 20009765, 20009901, 1948, 20006331, 20010179, 20010257, 20010267];

    $migrated_admin_tool_shortcodes = [
        'spp_apply_override_to_results_table', 'spp_copy_ranks_to_user_profile',
        'spp_show_results', 'spp_remove_user_from_ladder', 'spp_create_membership_table',
        'spp_random_ranks', 'spp_remove_inactive_ladder_users', 'spp_blank_scores_colour',
        'spp_membership_tags_refresh_ui', 'spp_score_review_grid', 'spp_create_view',
        'spp_pdf_skills_assessment', 'spp_pdf_constitution', 'spp_pdf_instructor_subsidy',
        'spp_score_scanner_ui', 'spp_rank_history', 'spp_scores_events_dropdown',
        'spp_gl_ladder_events_dropdown', 'spp_schedule_before_after_comparison',
        'spp_change_new_user_rank',
    ];

    global $post;
    if ( ! $post || in_array($post->ID, $member_pages) ) return;

    $is_admin_tool_page = has_shortcode($post->post_content, 'cmruncode');
    if ( ! $is_admin_tool_page ) {
        foreach ($migrated_admin_tool_shortcodes as $tag) {
            if (has_shortcode($post->post_content, $tag)) { $is_admin_tool_page = true; break; }
        }
    }

    if ($is_admin_tool_page) {
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
