<?php
/* =========================================================
   SPP UNIFIED MENU LOGIC
   ========================================================= */

function spp_get_menu_items_by_name($menu_name) {
    $menu = wp_get_nav_menu_object($menu_name);
    if (!$menu) return [];
    return wp_get_nav_menu_items($menu->term_id);
}

function spp_render_menu_tree($items, $parent_id = 0) {
    $output = '';

    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === (int) $parent_id) {
            $classes = ['spp-mm-item', 'menu-item-' . $item->ID];

            $has_children = false;
            foreach ($items as $potential_child) {
                if ((int) $potential_child->menu_item_parent === (int) $item->ID) {
                    $has_children = true;
                    break;
                }
            }
            if ($has_children) {
                $classes[] = 'menu-item-has-children';
            }

            if (!empty($item->classes) && is_array($item->classes)) {
                $classes = array_merge($classes, $item->classes);
            }

            $class_string = implode(' ', array_filter($classes));

            $output .= '<li class="' . esc_attr($class_string) . '">';
            $output .= '<a href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a>';

            $children = spp_render_menu_tree($items, $item->ID);
            if ($children) {
                $output .= '<ul class="sub-menu">' . $children . '</ul>';
            }

            $output .= '</li>';
        }
    }

    return $output;
}

/* =========================================================
   LEFT DRAWER — CLUB NAVIGATION (ALL MEMBERS)
   ========================================================= */

function spp_mobile_menu_shortcode() {
    // Sections to show in left drawer - visible to all logged-in users
    $sections = ['Ladder', 'Other Events', 'Club info & Skills', 'Photos'];

    $output  = '<div class="spp-mobile-menu-wrapper">';

    // Add Home link at top
    $output .= '<div class="spp-mm-section">';
    $output .= '<ul class="spp-mm-list">';
    $output .= '<li class="spp-mm-item"><a href="/">Home</a></li>';
    $output .= '<li class="spp-mm-item"><a href="/faq/">FAQ</a></li>';
    $output .= '</ul>';
    $output .= '</div>';

    foreach ($sections as $menu_name) {
        $items = spp_get_menu_items_by_name($menu_name);
        if (!$items) continue;

        $output .= '<div class="spp-mm-section">';
        $output .= '<h3 class="spp-mm-heading">' . esc_html($menu_name) . '</h3>';
        $output .= '<ul class="spp-mm-list">' . spp_render_menu_tree($items) . '</ul>';
        $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('spp_mobile_menu', 'spp_mobile_menu_shortcode');

/* =========================================================
   RIGHT DRAWER — MY TOOLS (ROLE-BASED)
   ========================================================= */

function spp_tools_menu_shortcode() {
    $output = '<div class="spp-mobile-menu-wrapper spp-tools-mm">';

    // Account link — all logged-in users
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $output .= '<div class="spp-mm-section">';
        $output .= '<ul class="spp-mm-list">';
        $output .= '<li class="spp-mm-item"><a href="/account-3/">' . esc_html($current_user->first_name . ' ' . $current_user->last_name) . '</a></li>';
        $output .= '<li class="spp-mm-item"><a href="' . wp_logout_url('/') . '">Logout</a></li>';
        $output .= '</ul>';
        $output .= '</div>';
    } else {
        $output .= '<div class="spp-mm-section">';
        $output .= '<ul class="spp-mm-list">';
        $output .= '<li class="spp-mm-item"><a href="/login/">Login</a></li>';
        $output .= '</ul>';
        $output .= '</div>';
    }

    // Moderator menu — moderators and administrators
    if (current_user_can('administrator') || current_user_can('ladder')) {
        $mod_items = spp_get_menu_items_by_name('Moderator');
        if ($mod_items) {
            $output .= '<div class="spp-mm-section">';
            $output .= '<h3 class="spp-mm-heading">Moderator</h3>';
            $output .= '<ul class="spp-mm-list">' . spp_render_menu_tree($mod_items) . '</ul>';
            $output .= '</div>';
        }
    }

    // Management menu — administrators only
    if (current_user_can('administrator')) {
        $mgmt_items = spp_get_menu_items_by_name('Management');
        if ($mgmt_items) {
            $output .= '<div class="spp-mm-section">';
            $output .= '<h3 class="spp-mm-heading">Management</h3>';
            $output .= '<ul class="spp-mm-list">' . spp_render_menu_tree($mgmt_items) . '</ul>';
            $output .= '</div>';
        }
    }

    $output .= '</div>';
    return $output;
}
add_shortcode('spp_tools_menu', 'spp_tools_menu_shortcode');

/* =========================================================
   MOBILE DRAWERS — OUTPUT TO FOOTER
   ========================================================= */

add_action('wp_footer', function() {
    if (function_exists('et_fb_is_enabled') && et_fb_is_enabled()) return;

    $tools_label = is_user_logged_in()
        ? wp_get_current_user()->first_name . '\'s Tools'
        : 'Login';

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
