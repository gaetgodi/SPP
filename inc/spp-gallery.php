<?php
/* =========================================================
   SPP GALLERY - spp_album taxonomy-driven gallery
   Ported from fishbucklake/inc/gallery.php ([fbl_gallery]).
   View logic (grid/carousel/masonry, lightbox, captions, links map)
   is unchanged — only the folder lookup is FileBird-free.

   [spp_gallery album="Ice Cream Social 2025" view="grid|carousel|masonry"
                columns="3" limit="0" size="large"
                order="date_desc|date_asc|name|name_desc|random"
                shuffle="pageload|daily|weekly|never"
                autoplay="5000" link="lightbox|none"]
   ========================================================= */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------
   Resolve spp_album name -> attachment IDs (cached)
   --------------------------------------------------------- */
function spp_gallery_get_ids($album_name) {
    $gen = (int) get_option('spp_gallery_cache_gen', 1);
    $cache_key = 'spp_gallery_' . $gen . '_' . md5($album_name);

    $ids = get_transient($cache_key);
    if ($ids !== false) {
        return $ids;
    }

    $term = spp_album_get($album_name);
    if (!$term) {
        return null; // album not found (distinct from empty album)
    }

    $ids = get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit', // attachments are never 'publish'
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => array(array(
            'taxonomy' => 'spp_album',
            'field'    => 'term_id',
            'terms'    => $term->term_id,
        )),
    ));

    $ids = array_map('intval', $ids);
    set_transient($cache_key, $ids, 12 * HOUR_IN_SECONDS);

    return $ids;
}

/* ---------------------------------------------------------
   Ordering — unchanged from FBL, no FileBird dependency here
   --------------------------------------------------------- */
function spp_gallery_order_ids($ids, $order, $shuffle, $album_name) {
    if ($order === 'random') {
        $tz  = new DateTimeZone('America/Toronto');
        $now = new DateTime('now', $tz);

        switch ($shuffle) {
            case 'daily':
                mt_srand(crc32($album_name . $now->format('Y-m-d')));
                shuffle($ids);
                mt_srand();
                break;
            case 'weekly':
                mt_srand(crc32($album_name . $now->format('o-W')));
                shuffle($ids);
                mt_srand();
                break;
            case 'never':
                mt_srand(crc32($album_name));
                shuffle($ids);
                mt_srand();
                break;
            case 'pageload':
            default:
                shuffle($ids);
                break;
        }
        return $ids;
    }

    $map = array(
        'date_desc' => array('date', 'DESC'),
        'date_asc'  => array('date', 'ASC'),
        'name'      => array('title', 'ASC'),
        'name_desc' => array('title', 'DESC'),
    );
    $ob = isset($map[$order]) ? $map[$order] : $map['date_desc'];

    $posts = get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post__in'       => $ids,
        'posts_per_page' => -1,
        'orderby'        => $ob[0],
        'order'          => $ob[1],
    ));

    return wp_list_pluck($posts, 'ID');
}

/* ---------------------------------------------------------
   Shortcode
   --------------------------------------------------------- */
add_shortcode('spp_gallery', function ($atts) {
    $atts = shortcode_atts(array(
        'album'    => '',
        'view'     => 'grid',
        'columns'  => 3,
        'limit'    => 0,
        'size'     => 'large',
        'order'    => 'date_desc',
        'shuffle'  => 'pageload',
        'autoplay' => 5000,
        'link'     => 'lightbox',
        'caption'  => 'caption',
        'fit'      => 'cover',
        'thumb_caption' => 'none',
        'links'    => '',
    ), $atts, 'spp_gallery');

    $album = trim($atts['album']);
    if ($album === '') {
        return current_user_can('edit_posts')
            ? '<p class="spp-gallery-error">[spp_gallery] requires an album attribute.</p>'
            : '';
    }

    $ids = spp_gallery_get_ids($album);

    if ($ids === null) {
        return current_user_can('edit_posts')
            ? '<p class="spp-gallery-error">[spp_gallery] Album "' . esc_html($album) . '" not found.</p>'
            : '';
    }

    if (empty($ids)) {
        return current_user_can('edit_posts')
            ? '<p class="spp-gallery-error">[spp_gallery] Album "' . esc_html($album) . '" contains no images.</p>'
            : '';
    }

    $ids = spp_gallery_order_ids($ids, $atts['order'], $atts['shuffle'], $album);

    $limit = (int) $atts['limit'];
    if ($limit > 0) {
        $ids = array_slice($ids, 0, $limit);
    }

    $view      = in_array($atts['view'], array('grid', 'carousel', 'masonry'), true) ? $atts['view'] : 'grid';
    $columns   = max(1, min(6, (int) $atts['columns']));
    $lightbox  = ($atts['link'] === 'lightbox');
    $group     = 'spp-' . sanitize_title($album);
    $autoplay  = max(1000, (int) $atts['autoplay']);
    $cap_mode  = in_array($atts['caption'], array('caption', 'title', 'none'), true) ? $atts['caption'] : 'caption';
    $tcap_mode = in_array($atts['thumb_caption'], array('none', 'caption', 'title', 'overlay'), true) ? $atts['thumb_caption'] : 'none';
    $fit       = ($atts['fit'] === 'contain') ? 'contain' : 'cover';

    // Parse links="Title:URL,Title:URL" into a title => URL map (case-insensitive match).
    $link_map = array();
    if (trim($atts['links']) !== '') {
        foreach (explode(',', $atts['links']) as $pair) {
            $pos = strpos($pair, ':');
            if ($pos === false) continue;
            $t = trim(substr($pair, 0, $pos));
            $u = trim(substr($pair, $pos + 1));
            if ($t !== '' && $u !== '') {
                $link_map[strtolower($t)] = $u;
            }
        }
    }

    ob_start();
    ?>
    <div class="spp-gallery spp-gallery--<?php echo esc_attr($view); ?> spp-gallery--fit-<?php echo esc_attr($fit); ?>"
         style="--spp-gallery-columns: <?php echo esc_attr($columns); ?>;"
         <?php if ($view === 'carousel'): ?>data-spp-autoplay="<?php echo esc_attr($autoplay); ?>"<?php endif; ?>>

        <?php foreach ($ids as $i => $id):
            $full  = wp_get_attachment_image_url($id, 'full');
            $thumb = wp_get_attachment_image($id, $atts['size'], false, array(
                'class'   => 'spp-gallery-image',
                'loading' => ($view === 'carousel' && $i === 0) ? 'eager' : 'lazy',
            ));
            if (!$full || !$thumb) continue;

            $caption = '';
            if ($cap_mode === 'caption') {
                $caption = wp_get_attachment_caption($id);
            } elseif ($cap_mode === 'title') {
                $caption = wp_get_attachment_caption($id);
                if (!$caption) $caption = get_the_title($id);
            }

            $tcaption = '';
            if ($tcap_mode === 'caption') {
                $tcaption = wp_get_attachment_caption($id);
            } elseif ($tcap_mode === 'title' || $tcap_mode === 'overlay') {
                $tcaption = wp_get_attachment_caption($id);
                if (!$tcaption) $tcaption = get_the_title($id);
            }

            // Does this image's title match an entry in the links map?
            $page_link = '';
            $title_key = strtolower(get_the_title($id));
            if (isset($link_map[$title_key])) {
                $page_link = $link_map[$title_key];
            }
        ?>
            <div class="spp-gallery-item<?php echo ($view === 'carousel' && $i === 0) ? ' is-active' : ''; ?><?php echo ($tcap_mode === 'overlay') ? ' spp-gallery-item--overlay' : ''; ?>">
                <?php if ($page_link): ?>
                    <a href="<?php echo esc_url($page_link); ?>" class="spp-gallery-link spp-gallery-link--page">
                        <?php echo $thumb; ?>
                    </a>
                <?php elseif ($lightbox): ?>
                    <a href="<?php echo esc_url($full); ?>"
                       class="spp-gallery-link"
                       data-fancybox="<?php echo esc_attr($group); ?>"
                       <?php if ($caption): ?>data-caption="<?php echo esc_attr($caption); ?>"<?php endif; ?>>
                        <?php echo $thumb; ?>
                    </a>
                    <?php else: ?>
                    <?php echo $thumb; ?>
                <?php endif; ?>
                <?php if ($tcaption): ?>
                    <?php if ($tcap_mode === 'overlay'): ?>
                        <div class="spp-gallery-caption--overlay"><span><?php echo esc_html($tcaption); ?></span></div>
                    <?php else: ?>
                        <div class="spp-gallery-caption"><?php echo esc_html($tcaption); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($view === 'carousel' && count($ids) > 1): ?>
            <button type="button" class="spp-gallery-prev" aria-label="Previous image"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></button>
            <button type="button" class="spp-gallery-next" aria-label="Next image"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></button>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

/* ---------------------------------------------------------
   Conditional asset enqueue — only on pages that actually use
   [spp_gallery], same pattern FBL uses in inc/enqueue.php.
   Fancybox is loaded from CDN for the lightbox; drop this block
   (and link="lightbox" everywhere) if that external dependency
   isn't wanted.
   --------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
    if (function_exists('et_fb_is_enabled') && et_fb_is_enabled()) return;

    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'spp_gallery')) return;

    $theme = get_stylesheet_directory_uri();
    $dir   = get_stylesheet_directory();

    wp_enqueue_style(
        'spp-gallery',
        $theme . '/css/spp-gallery.css',
        array('spp-tokens'),
        filemtime($dir . '/css/spp-gallery.css')
    );
    wp_enqueue_script(
        'spp-gallery',
        $theme . '/js/spp-gallery.js',
        array(),
        filemtime($dir . '/js/spp-gallery.js'),
        true
    );

    wp_enqueue_script('fancybox', 'https://cdn.jsdelivr.net/npm/@fancyapps/ui@4.0/dist/fancybox.umd.js', array(), '4.0', true);
    wp_enqueue_style('fancybox', 'https://cdn.jsdelivr.net/npm/@fancyapps/ui@4.0/dist/fancybox.css', array(), '4.0');
    wp_add_inline_script('fancybox', 'document.addEventListener("DOMContentLoaded", function() { Fancybox.bind("[data-fancybox]", {}); });');
}, 20);
