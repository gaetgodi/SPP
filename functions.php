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
require_once get_stylesheet_directory() . '/inc/spp-pdf.php'; // generic [spp_pdf file="" title=""], consolidates the former spp-pdf-skills-assessment.php/spp-pdf-constitution.php/spp-pdf-instructor-subsidy.php (CM258/CM260/CM261 shims), all now retired
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
require_once get_stylesheet_directory() . '/inc/spp-pdf-generator-admin.php'; // must load right after spp-report-generator-admin.php above -- its admin_menu registration order is what puts this submenu item directly below "Report Generator" under Pages
require_once get_stylesheet_directory() . '/inc/spp-report-edit.php';
require_once get_stylesheet_directory() . '/inc/spp-kq-schema.php'; // Ace/Queen of the Courts live event runner -- must load before spp-kq-movement.php/spp-kq-live.php below
require_once get_stylesheet_directory() . '/inc/spp-kq-movement.php'; // pure movement algorithm, no dependency on spp-kq-schema.php itself, but grouped with the rest of this feature
require_once get_stylesheet_directory() . '/inc/spp-kq-live.php'; // calls functions from both files above
require_once get_stylesheet_directory() . '/inc/spp-kq-club-rating.php'; // Stage 4: feeds completed/cancelled KQ occurrences into the shared spp_crt_process_event_ratings() (inc/spp-update-club-ratings.php) -- must load after spp-update-club-ratings.php (line 69) and spp-kq-live.php/spp-kq-schema.php above; called from spp-kq-screens.php's post-action dispatcher below
require_once get_stylesheet_directory() . '/inc/spp-kq-format-ranking.php'; // Ace/Queen of the Courts decay-weighted format rankings + multi-level tie-break (spp_kq_update_format_rankings()) -- must load after spp-kq-club-rating.php above (reuses SPP_KQ_CLUB_RATING_LAUNCH_DATE/spp_kq_category_source()); reads spp_kq_history via spp_kq_history_table() (spp-kq-schema.php, line 97, already loaded) and spp_kq_court_value() (spp-kq-live.php, line 99, already loaded); called from spp-kq-screens.php's post-action dispatcher below, AFTER spp_kq_finalize_event_history_and_recap() now (that dispatcher's own 1.24.0 changelog)
require_once get_stylesheet_directory() . '/inc/spp-kq-history.php'; // Permanent spp_kq_history archive + live Full Scoreboard read + recap email -- must load after spp-kq-club-rating.php above (reuses SPP_KQ_CLUB_RATING_LAUNCH_DATE/spp_kq_category_source()) and before spp-kq-screens.php below (calls this file's spp_kq_finalize_event_history_and_recap()/spp_kq_render_full_scoreboard_screen() -- the latter actually defined in spp-kq-screens.php itself); also defines spp_report_kq_history(), registered in inc/spp-reports.php's registry
require_once get_stylesheet_directory() . '/inc/spp-kq-roster.php'; // KQ-specific replacement for gl-registration-admin (pre-Round-1 roster add/remove, gated only via [spp_kq_live]'s existing spp_kq_can_facilitate(), no gate of its own) -- must load before spp-kq-screens.php below, which calls spp_kq_roster_add()/spp_kq_roster_remove()/spp_kq_render_roster_screen()
require_once get_stylesheet_directory() . '/inc/spp-kq-checkin.php'; // Pre-Round-1 check-in (spp_kq_checkins) -- must load before spp-kq-screens.php below, which calls spp_kq_get_checked_in_user_ids()/spp_kq_set_checked_in(); calls spp_kq_player_name() from spp-kq-screens.php but only at render time, never at load time, so load order between those two is safe either way
require_once get_stylesheet_directory() . '/inc/spp-kq-screens.php'; // [spp_kq_live] shortcode + AJAX draw handler -- calls functions from all six files above

// TEC single-event template debug logging removed 2026-09-14: gated on
// is_singular('tribe_events'), which can never be true -- TEC is fully
// uninstalled (post_type_exists('tribe_events') === false) and zero
// posts of that post_type exist in wp_posts. Dead code, safe to remove
// outright (see inc/shortcodes.php's [spp_events] fix, same day, for
// the fuller TEC-removal writeup).

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
   ADMIN-ONLY ROLE HELPER
   Same checks-actual-roles convention as spp_is_admin_or_editor(),
   narrowed to administrator only -- for the handful of tools whose
   UM menu restriction is administrator-only, not admin-or-editor
   (e.g. spp_change_new_user_rank, spp_remove_inactive_ladder_users).
   ========================================================= */
function spp_is_admin() {
    $roles = (array) wp_get_current_user()->roles;
    return in_array('administrator', $roles);
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
   EVENT DATE RESOLUTION HELPER
   Resolves any event_id from Results_all/Schedules-style tables to
   a real calendar date/time, regardless of which numbering epoch it
   belongs to: gl_event_occurrences covers the current GL Events ids
   (small, sequential); event_date_lookup (a small standalone
   backfill table) covers everything gl_event_occurrences doesn't,
   including the retired TEC/rtec-legacy ids (+30000000-offset).
   COALESCE prefers gl_event_occurrences when both resolve.

   This is the exact join+COALESCE logic spp_scores_events_dropdown()
   (CM273) has used correctly all along -- lifted out here so any
   other query needing "what real date did this event_id happen on"
   reuses the same resolution instead of re-deriving its own. A
   re-derived, narrower version of this same problem (a hardcoded
   constant from one numbering epoch compared against ids from
   another) is exactly what produced the event_id > 30000760 cutoff
   bug in spp_remove_inactive_ladder_users() -- see that file's
   changelog. Added when fixing that bug; also adopted by
   spp_scores_events_dropdown() itself, replacing its own inline copy
   of the same two joins.

   Usage: splice the two LEFT JOINs from $join into the query's FROM
   clause, then use $date_expr anywhere the resolved DATETIME is
   needed (SELECT, WHERE, ORDER BY, ...). $event_id_expr is the
   caller's own column/expression to resolve (e.g. 'r.event_id', or a
   CAST(...) over an information_schema column). $alias lets a query
   that needs this joined more than once (e.g. two different
   event_id columns) avoid alias collisions between joins.
   ========================================================= */
function spp_event_date_resolution_sql( $event_id_expr, $alias = 'ed' ) {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $geo    = $alias . '_geo';
    $edl    = $alias . '_edl';

    $join = "
        LEFT JOIN {$prefix}gl_event_occurrences {$geo} ON {$event_id_expr} = {$geo}.id
        LEFT JOIN event_date_lookup {$edl} ON {$event_id_expr} = {$edl}.event_id
    ";
    $date_expr = "COALESCE(CONCAT({$geo}.event_date, ' ', {$geo}.event_time), {$edl}.event_date)";

    return array( 'join' => $join, 'date_expr' => $date_expr );
}
/* =========================================================
   PAGE ACCESS RESTRICTION (template_redirect gate) -- REMOVED
   2026-09-21. Retired the whole mechanism ($migrated_admin_tool_
   shortcodes, $member_pages, and this hook) once every one of the
   20 tracked shortcodes was confirmed to self-gate via
   spp_is_admin_or_editor()/spp_is_admin() internally (the last 3 --
   spp_blank_scores_colour, spp_gl_ladder_events_dropdown,
   spp_schedule_before_after_comparison -- gained that check in this
   same change; spp_rank_history's existing spp_is_ladder_admin()
   feature-differentiation, not a hard block, was confirmed
   intentional and left untouched) or turned out to protect nothing
   at all (the raw [cmruncode] branch -- Code Manager is deactivated
   site-wide, so that shortcode already renders nothing for anyone).
   $member_pages' obsolete/no-op entries (Club Membership list
   20003754, Enter Scores 20010267, the already-trashed 20009765)
   needed no separate cleanup -- removing the whole mechanism
   resolved them along with everything else.
   ========================================================= */

/* =========================================================
   JS ERROR LOGGING (TEC single event pages) -- REMOVED 2026-09-14.
   Entirely gated on is_singular('tribe_events'), which can never be
   true: TEC is fully uninstalled (post_type_exists('tribe_events')
   === false) and zero posts of that post_type exist in wp_posts.
   Dead code, safe to remove outright -- see inc/shortcodes.php's
   [spp_events] fix, same day, for the fuller TEC-removal writeup.
   ========================================================= */

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
