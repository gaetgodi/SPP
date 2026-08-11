<?php
/* =========================================================
   SPP ALBUM TAXONOMY
   FileBird-free replacement for FBL's wp_fbv / wp_fbv_attachment_folder
   tables. A hierarchical taxonomy on the attachment post type — an
   image can belong to multiple albums at once (wp_set_object_terms
   with $append = true), mirroring FBL's _FBL/WEB_ multi-folder
   membership pattern without any custom tables.

   Consumed by:
   - spp-flickr-import.php  (writes: assigns imported images to an album)
   - spp-media-curator.php  (writes: title/caption edit, album-to-album copy)
   - spp-gallery.php        (reads:  [spp_gallery album="..."] shortcode)

   Album lookup is by SLUG, not name. Hierarchical taxonomies don't
   enforce name-uniqueness across different parent terms (two "2024"
   child albums under different parents is legal WP), but slugs are
   always unique — so every consumer resolves an album via
   get_term_by('slug', sanitize_title($input), 'spp_album'), never by
   raw name string. Keep this contract if you touch any of the three
   files above.
   ========================================================= */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------
   Registration
   --------------------------------------------------------- */
add_action('init', function () {
    register_taxonomy('spp_album', 'attachment', array(
        'labels' => array(
            'name'          => 'Albums',
            'singular_name' => 'Album',
            'search_items'  => 'Search Albums',
            'all_items'     => 'All Albums',
            'parent_item'   => 'Parent Album',
            'parent_item_colon' => 'Parent Album:',
            'edit_item'     => 'Edit Album',
            'update_item'   => 'Update Album',
            'add_new_item'  => 'Add New Album',
            'new_item_name' => 'New Album Name',
            'menu_name'     => 'Albums',
        ),
        'hierarchical'      => true,  // category-style parent/child, not tag-style
        'public'            => true,  // required for tax_query to work on the front end
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,  // exposes it to the media modal / block editor
        'query_var'         => true,
        'rewrite'           => false, // no public /spp_album/foo/ archive pages needed

        // WP core (7.0+) auto-adds an attachment taxonomy with show_ui=true
        // as its own submenu under Media (wp-admin/menu.php, via
        // get_taxonomies_for_attachments()) — same "Albums" label, same
        // edit-tags.php URL, as the add_media_page() call below. Without
        // this, both register and wp-admin > Media shows "Albums" twice.
        // Core's auto-added version also gates on manage_categories
        // (editors/admins only) where the add_media_page() below
        // deliberately uses upload_files, matching Flickr Import and
        // Media Curator's access level — so keep the manual one, suppress
        // core's.
        'show_in_menu'      => false,

        // Attachments carry post_status = 'inherit', never 'publish'. WP's
        // default term-count callback only counts 'publish' posts, which
        // would show every album as "(0)" in the admin UI. This generic
        // callback counts objects regardless of post_status.
        'update_count_callback' => '_update_generic_term_count',
    ));
});

/* ---------------------------------------------------------
   Admin: manage albums (rename/delete/merge) via WP's built-in
   taxonomy screen. Attachment-only taxonomies don't get an automatic
   admin-menu entry, so this adds one under Media.
   --------------------------------------------------------- */
add_action('admin_menu', function () {
    add_media_page(
        'Albums',
        'Albums',
        'upload_files',
        'edit-tags.php?taxonomy=spp_album&post_type=attachment'
    );
});

/* ---------------------------------------------------------
   Helpers shared by flickr-import.php, media-curator.php, gallery.php
   --------------------------------------------------------- */

/**
 * Resolve an album by human-typed name -> WP_Term, or null if it
 * doesn't exist. Matches by slug (see file header) so renames and
   duplicate display names never cause a silent wrong-album match.
 */
function spp_album_get($name) {
    $name = trim((string) $name);
    if ($name === '') return null;
    $term = get_term_by('slug', sanitize_title($name), 'spp_album');
    return ($term && !is_wp_error($term)) ? $term : null;
}

/**
 * Find an album by name, or create it (as a root-level term) if it
 * doesn't exist yet. Returns the term_id.
 */
function spp_album_get_or_create($name) {
    $name = trim((string) $name);
    $existing = spp_album_get($name);
    if ($existing) return (int) $existing->term_id;

    $result = wp_insert_term($name, 'spp_album');
    if (is_wp_error($result)) {
        // Race condition (created between the lookup above and here) —
        // one more lookup covers it; otherwise surface 0 to the caller.
        $again = spp_album_get($name);
        return $again ? (int) $again->term_id : 0;
    }
    return (int) $result['term_id'];
}

/**
 * Every album, its image count, "_Import" staging albums sorted first
 * (mirrors FBL's _FBL-folders-to-top convenience), then alphabetical.
 */
function spp_album_all_with_counts() {
    $terms = get_terms(array(
        'taxonomy'   => 'spp_album',
        'hide_empty' => false,
    ));
    if (is_wp_error($terms) || !$terms) return array();

    usort($terms, function ($a, $b) {
        $af = (substr($a->name, -7) === '_Import') ? 0 : 1;
        $bf = (substr($b->name, -7) === '_Import') ? 0 : 1;
        if ($af !== $bf) return $af - $bf;
        return strcasecmp($a->name, $b->name);
    });
    return $terms;
}

/* ---------------------------------------------------------
   Cache invalidation — used by the [spp_gallery] shortcode's
   transient cache (see spp-gallery.php). One native WP hook covers
   every membership change (add/remove/append) instead of FileBird's
   five separate action hooks.
   --------------------------------------------------------- */
function spp_gallery_flush_cache() {
    $gen = (int) get_option('spp_gallery_cache_gen', 1);
    update_option('spp_gallery_cache_gen', $gen + 1, false);
}
add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy) {
    if ($taxonomy === 'spp_album') spp_gallery_flush_cache();
}, 10, 4);
add_action('edited_term',       'spp_gallery_flush_cache'); // album renamed
add_action('delete_term',       'spp_gallery_flush_cache'); // album deleted
add_action('add_attachment',    'spp_gallery_flush_cache');
add_action('delete_attachment', 'spp_gallery_flush_cache');
