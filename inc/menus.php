<?php
/* =========================================================
   SPP UNIFIED MENU LOGIC
   Reads directly from the Main menu structure.
   Left drawer  = public sections (Ladder, FAQ, Other Events, etc.)
   Right drawer = role-based tools (Moderator, Management) + account
   ========================================================= */

/* ---------------------------------------------------------
   Get all items from the Main menu, indexed by ID
   --------------------------------------------------------- */
function spp_get_main_menu_items() {
    $menu = wp_get_nav_menu_object('Main');
    if (!$menu) return [];
    $items = wp_get_nav_menu_items($menu->term_id);
    if (!$items) return [];
    return $items;
}

/* ---------------------------------------------------------
   Render a tree of items starting from $parent_id
   --------------------------------------------------------- */
function spp_render_menu_tree($items, $parent_id = 0) {
    $output = '';

    foreach ($items as $item) {
        if ((int) $item->menu_item_parent !== (int) $parent_id) continue;

        $classes = ['spp-mm-item', 'menu-item-' . $item->ID];

        foreach ($items as $child) {
            if ((int) $child->menu_item_parent === (int) $item->ID) {
                $classes[] = 'menu-item-has-children';
                break;
            }
        }

        if (!empty($item->classes) && is_array($item->classes)) {
            $classes = array_merge($classes, array_filter($item->classes));
        }

        $output .= '<li class="' . esc_attr(implode(' ', $classes)) . '">';
        $output .= '<a href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a>';

        $children = spp_render_menu_tree($items, $item->ID);
        if ($children) {
            $output .= '<ul class="sub-menu">' . $children . '</ul>';
        }

        $output .= '</li>';
    }

    return $output;
}

/* ---------------------------------------------------------
   Find a top-level item by title, return its ID
   --------------------------------------------------------- */
function spp_find_top_level_id($items, $title) {
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === 0 && strtolower($item->title) === strtolower($title)) {
            return $item->ID;
        }
    }
    return null;
}

/* ---------------------------------------------------------
   Render one section: heading + children of a top-level item
   --------------------------------------------------------- */
function spp_render_section($items, $section_title) {
    $parent_id = spp_find_top_level_id($items, $section_title);
    if (!$parent_id) return '';

    $parent_item = null;
    foreach ($items as $item) {
        if ($item->ID === $parent_id) {
            $parent_item = $item;
            break;
        }
    }

    $children = spp_render_menu_tree($items, $parent_id);

    $output = '<div class="spp-mm-section">';

    if ($children) {
        $output .= '<h3 class="spp-mm-heading">' . esc_html($section_title) . '</h3>';
        $output .= '<ul class="spp-mm-list">' . $children . '</ul>';
    } else if ($parent_item) {
        // Direct link with no children — always visible
        $output .= '<div class="spp-mm-section--direct">';
        $output .= '<ul class="spp-mm-list">';
        $output .= '<li class="spp-mm-item"><a href="' . esc_url($parent_item->url) . '">' . esc_html($parent_item->title) . '</a></li>';
        $output .= '</ul>';
        $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
}

/* =========================================================
   LEFT DRAWER — CLUB NAVIGATION (ALL USERS)
   ========================================================= */
function spp_mobile_menu_shortcode() {
    $items = spp_get_main_menu_items();
    if (!$items) return '<p>Menu not found.</p>';

    $output = '<nav class="spp-mobile-menu-wrapper spp-side-nav--collapsible">';

    // Home — direct link, always visible
    $output .= '<div class="spp-mm-section spp-mm-section--direct">';
    $output .= '<ul class="spp-mm-list">';
    $output .= '<li class="spp-mm-item"><a href="/">Home</a></li>';
    $output .= '</ul>';
    $output .= '</div>';

    $left_sections = ['Ladder', 'FAQ', 'Other Events', 'Club info & Skills', 'Photos'];

    foreach ($left_sections as $section) {
        $output .= spp_render_section($items, $section);
    }

    $output .= '</nav>';
    return $output;
}
add_shortcode('spp_mobile_menu', 'spp_mobile_menu_shortcode');

/* =========================================================
   RIGHT DRAWER — MY TOOLS (ROLE-BASED)
   ========================================================= */
function spp_tools_menu_shortcode() {
    $items = spp_get_main_menu_items();

    $output = '<nav class="spp-mobile-menu-wrapper spp-tools-mm spp-side-nav--collapsible">';

    // Account — all users, always visible
    $output .= '<div class="spp-mm-section spp-mm-section--direct">';
    $output .= '<ul class="spp-mm-list">';

    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $full_name    = trim($current_user->first_name . ' ' . $current_user->last_name);
        $output .= '<li class="spp-mm-item"><a href="/account-3/">' . esc_html($full_name ?: $current_user->user_login) . '</a></li>';
        $output .= '<li class="spp-mm-item"><a href="' . esc_url(wp_logout_url('/')) . '">Logout</a></li>';
    } else {
        $output .= '<li class="spp-mm-item"><a href="/login/">Login</a></li>';
    }

    $output .= '</ul>';
    $output .= '</div>';

    // Moderator — ladder role and administrators
    if (current_user_can('administrator') || current_user_can('ladder')) {
        $mod = spp_render_section($items, 'Moderator');
        if ($mod) $output .= $mod;
    }

    // Management — administrators only
    if (current_user_can('administrator')) {
        $mgmt = spp_render_section($items, 'Management');
        if ($mgmt) $output .= $mgmt;
    }

    $output .= '</nav>';
    return $output;
}
add_shortcode('spp_tools_menu', 'spp_tools_menu_shortcode');

/* =========================================================
   MOBILE DRAWERS — OUTPUT TO FOOTER
   ========================================================= */
add_action('wp_footer', function() {
    if (function_exists('et_fb_is_enabled') && et_fb_is_enabled()) return;

    $tools_label = 'Login';
    if (is_user_logged_in()) {
        $first = wp_get_current_user()->first_name;
        $tools_label = $first ? $first . '\'s Tools' : 'My Tools';
    }

    echo '
    <div id="spp-mm-drawer">
        <button id="spp-mm-open">
            <span class="spp-mm-open-icon"></span>
            <span class="spp-mm-open-label">Club Menu</span>
        </button>
        <button id="spp-footer-mm-open" class="spp-mm-secondary-btn">
            <span class="spp-mm-open-icon"></span>
            <span class="spp-mm-open-label">' . esc_html($tools_label) . '</span>
        </button>
    </div>

    <div id="spp-mm-overlay"></div>

    <div id="spp-mm-bottom-sheet">
        <div class="spp-mm-sheet-header">
            <span class="spp-mm-sheet-title">Club Menu</span>
            <button id="spp-mm-close">
                <span id="spp-mm-close-icon"></span>
            </button>
        </div>
        <div class="spp-mm-sheet-body">
            ' . do_shortcode('[spp_mobile_menu]') . '
        </div>
    </div>

    <div id="spp-footer-mm-bottom-sheet">
        <div class="spp-mm-sheet-header">
            <span class="spp-mm-sheet-title">' . esc_html($tools_label) . '</span>
            <button id="spp-footer-mm-close">
                <span class="spp-mm-close-icon"></span>
            </button>
        </div>
        <div class="spp-mm-sheet-body">
            ' . do_shortcode('[spp_tools_menu]') . '
        </div>
    </div>';
});

/* =========================================================
   DESKTOP SIDE NAV — SHORTCODE
   ========================================================= */
function spp_side_nav_shortcode() {
    $items = spp_get_main_menu_items();
    if (!$items) return '';

    $output = '<nav class="spp-side-nav spp-side-nav--collapsible">';

    foreach ($items as $item) {
        if ((int) $item->menu_item_parent !== 0) continue;

        $children = spp_render_menu_tree($items, $item->ID);
        $output .= '<div class="spp-mm-section">';

        if ($children) {
            $output .= '<h3 class="spp-mm-heading">' . esc_html($item->title) . '</h3>';
            $output .= '<ul class="spp-mm-list">' . $children . '</ul>';
        } else {
            // Direct link — always visible
            $output .= '<div class="spp-mm-section--direct">';
            $output .= '<ul class="spp-mm-list">';
            $output .= '<li class="spp-mm-item"><a href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a></li>';
            $output .= '</ul>';
            $output .= '</div>';
        }

        $output .= '</div>';
    }

    $output .= '</nav>';
    return $output;
}
add_shortcode('spp_side_nav', 'spp_side_nav_shortcode');