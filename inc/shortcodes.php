<?php
/* =========================================================
   SPP CUSTOM SHORTCODES
   Site: pickleballstouffville.ca
   
   Shortcodes:
   - [spp_dashboard]  — home page dashboard widget
   ========================================================= */

/* =========================================================
   [spp_dashboard]
   Home page dashboard — shows schedule and rank for ladder
   members, welcome message for guests and non-ladder members.

   v1.1 (2026-08-27): added Club Rating and DUPR lines right after
   the ladder rank line, same markup/styling. Both usermeta keys
   (spp_glicko_rating / spp_dupr_rating) can be genuinely absent —
   a legacy player with zero reconstructable historical games, or
   any brand-new member who hasn't played a ladder event yet — so
   both are checked with empty() and shown as "N/A" rather than
   left blank or left to throw a notice on a missing array key.
   ========================================================= */
function spp_dashboard_shortcode() {
    global $wpdb;

    $output = '<div class="spp-dashboard">';

    if (!is_user_logged_in()) {
        // -----------------------------------------------
        // Logged out — welcome message
        // -----------------------------------------------
        $output .= '
        <div class="spp-dashboard-welcome">
            <h2>Welcome to Stouffville Pickleball Players</h2>
            <p>We are a pickleball club based in Stouffville, Ontario with over 400 active members.</p>
            <p>Please <a href="/login/">login</a> or 
            <a href="https://www.pickleballcanada.org" target="_blank">register with Pickleball Canada</a> 
            to access member features.</p>
        </div>';

    } else {
        // -----------------------------------------------
        // Logged in — get user data
        // -----------------------------------------------
        $current_user = wp_get_current_user();
        $user_id      = $current_user->ID;
        $first_name   = $current_user->first_name;
        $last_name    = $current_user->last_name;

        $output .= '<div class="spp-dashboard-header">';
        $output .= '<h2>Welcome back, ' . esc_html($first_name) . ' ' . esc_html($last_name) . '!</h2>';
        $output .= '</div>';

        // Check ladder eligibility
        $ladder             = get_user_meta($user_id, 'Ladder',   true);
        $yr_end_dt          = get_user_meta($user_id, 'YrEndDt',  true);
        $rank               = get_user_meta($user_id, 'Rank',     true);
        $glicko_rating      = get_user_meta($user_id, 'spp_glicko_rating',       true);
        $glicko_rating_games = get_user_meta($user_id, 'spp_glicko_rating_games', true);
        $dupr_rating        = get_user_meta($user_id, 'spp_dupr_rating',         true);
        $current_year = date('Y');

        $is_ladder  = ($ladder === 'Yes');
        $is_current = (!empty($yr_end_dt) && $yr_end_dt >= $current_year . '-12-31');

        if ($is_ladder && $is_current) {
            // -----------------------------------------------
            // Active ladder member — show rank, Club Rating, DUPR
            // -----------------------------------------------
            $output .= '<div class="spp-dashboard-rank">';
            $output .= '<p>Your current ladder rank: <strong>';
            $output .= ($rank > 0) ? esc_html($rank) : 'To be determined';
            $output .= '</strong></p>';

            // spp_glicko_rating can be genuinely absent (legacy player with
            // no reconstructable historical games, or a brand-new member
            // who hasn't played a ladder event yet) — don't assume it exists.
            $output .= '<p>Club Rating: <strong>';
            if ($glicko_rating !== '' && $glicko_rating !== false) {
                $output .= esc_html($glicko_rating) . ' (based on ' . esc_html($glicko_rating_games) . ' ladder games)';
            } else {
                $output .= 'N/A';
            }
            $output .= '</strong></p>';

            // spp_dupr_rating is self-entered and optional — most players
            // won't have one.
            $output .= '<p>DUPR: <strong>';
            $output .= (!empty($dupr_rating)) ? esc_html($dupr_rating) : 'N/A';
            $output .= '</strong></p>';

            $output .= '</div>';
        }
        // Non-ladder and expired members see only the welcome message — nothing extra needed
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('spp_dashboard', 'spp_dashboard_shortcode');

/* =========================================================
   SYNC EMAIL CHANGE TO USERMETA
   When a user updates their email via UM Account form,
   also update the user_email meta key in usermeta.
   ========================================================= */
add_action('um_user_edit_profile', function($args) {
    if (!isset($args['user_email'])) return;
    
    $user_id = get_current_user_id();
    if (!$user_id) return;
    
    update_user_meta($user_id, 'user_email', sanitize_email($args['user_email']));
}, 10, 1);

// Also hook into standard WordPress email change
add_action('profile_update', function($user_id, $old_user_data) {
    $user = get_userdata($user_id);
    if (!$user) return;
    
    if ($user->user_email !== $old_user_data->user_email) {
        update_user_meta($user_id, 'user_email', $user->user_email);
    }
}, 10, 2);

/* =========================================================
   [spp_events] -- TEC dependency removed 2026-09-14.

   Previously wrapped TEC's own [tribe_events view="list" ...]
   shortcode, with a fallback that inferred the category from a TEC
   taxonomy archive query var / tribe_is_event_category() when no
   'category' attribute was passed. TEC is fully uninstalled --
   tribe_is_event_category() doesn't exist at all (confirmed via
   function_exists()), so that fallback branch was one PHP fatal
   ("Call to undefined function") away from taking down any page that
   embedded this shortcode with no explicit category -- it just never
   fired because every live embed happens to always pass one (see
   below). The happy path (category passed) wasn't fatal, but was
   silently broken anyway: do_shortcode() on 'tribe_events' (an
   unregistered tag, TEC's own shortcode gone with the plugin) just
   returns the bracket text unprocessed -- so all 6 real embeds
   (Ladder/Clinics/Queen of the Courts/Socials/Community/Ace of the
   Courts pages, confirmed via direct DB query) were rendering
   literal "[tribe_events view="list" category="..."]" text to real
   visitors instead of an event list.

   FIX: resolve the category slug against gl_event_categories (GL
   Events' own category table -- confirmed a clean, unambiguous 1:1
   slug match for every one of those 6 real embeds: ladder, ace,
   queen, socials, clinics, volunteers) and delegate to GL Events' own
   [gl_event_list] shortcode -- the real, currently-working equivalent
   (same pattern already used to retire [spp_event_registrations] in
   favor of [gl_event_registrations]). show_filter="false" keeps the
   single-category-only presentation these pages were built for,
   rather than introducing a new cross-category filter bar
   [gl_event_list] would otherwise show. No page content changes
   needed -- every existing [spp_events category="..."] embed keeps
   working exactly as embedded, now backed by a real data source.
   An unrecognized/missing category slug falls back to an unfiltered
   list, the same shape the original TEC-era fallback had.

   REACHABILITY NOTE (same standard used to retire
   [spp_event_registrations]): none of these 6 pages are linked from
   any of this site's 7 registered nav menus, nor from any href found
   in other published content -- so this fix is precautionary/
   defensive as much as corrective; flagged for Gaetan's own
   judgment on whether those pages should be actively linked
   somewhere, left as-is, or retired.
   ========================================================= */
add_shortcode('spp_events', function($atts) {
    $atts = shortcode_atts(['category' => ''], $atts);
    $cat_slug = $atts['category'];

    if ($cat_slug) {
        global $wpdb;
        $cat_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}gl_event_categories WHERE slug = %s",
            sanitize_title($cat_slug)
        ));
        if ($cat_id) {
            return do_shortcode('[gl_event_list category="' . $cat_id . '" show_filter="false"]');
        }
    }

    // No category resolved -- unfiltered list, same fallback shape the
    // original TEC-era version had for a bare [spp_events].
    return do_shortcode('[gl_event_list show_filter="false"]');
});

/* =========================================================
   [spp_event_registrations] -- RETIRED 2026-09-14, dead code removed.

   Base table was {$wpdb->prefix}tec_occurrences (The Events Calendar's
   own custom table) -- confirmed via SHOW TABLES that it does not
   exist anywhere in this database (same root cause as
   inc/spp-rank-history.php's own tec_occurrences bug, fixed the same
   day -- see that file's own changelog for the full writeup). A
   missing base FROM table is a hard MySQL error, so this shortcode
   has been rendering nothing but a broken query for as long as
   tec_occurrences has been gone -- unrelated to, and unchanged since,
   April 2026 (git blame), so almost certainly since whenever TEC was
   actually uninstalled from this site, long before this removal.

   NOT retired blind: confirmed via direct query that only one page
   ever embedded it -- "Events status" (page ID 20009419, slug
   events-status), published but with ZERO nav menu items pointing at
   it (checked wp_postmeta for _menu_item_object_id) -- effectively
   unreachable through normal site navigation. The real, currently
   linked "Event Status" page members actually use is a DIFFERENT
   page ("GL Event Status", ID 20010189, in the nav menu twice) running
   [gl_event_registrations] -- the GL Events plugin's own shortcode,
   not this theme's -- which is unaffected by any of this and already
   does the same job correctly. The orphaned events-status page itself
   was left alone (a content/page decision, not a code one) -- worth a
   look if you want it unpublished or deleted.
   ========================================================= */