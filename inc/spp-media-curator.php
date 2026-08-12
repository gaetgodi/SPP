<?php
/* =========================================================
   SPP MEDIA CURATOR (scaled down)
   Ported from fishbucklake/inc/media-curator.php. Keeps the two core
   workflows: album-scoped Title/Caption editing, and album-to-album
   copy (membership only — copying never removes an image from its
   source album, and never deletes the file).

   Deliberately cut vs. FBL's version, to keep this "lightweight":
   - No remove-from-folder / orphan-guard action. With a taxonomy,
     un-tagging an image from an album is a normal WP term-removal
     operation available from the attachment's own Edit screen — a
     dedicated bulk-remove tool isn't essential for SPP's scale.
   - No custom JS autocomplete dropdown for the target album — a
     native <datalist> gives the same "existing album, or type a new
     one" behaviour with far less code.
   Ask if any of these are wanted back; they're a straightforward
   re-port from the FBL source once the taxonomy layer below exists.

   - Pick a source album; edit Title & Caption inline.
   - Table live-sorts by Title (drives [spp_gallery order="name"]).
   - Empty captions are PRE-FILLED with the title as a muted suggestion
     (not saved). Flag rows + "Copy title -> caption" commits them.
   - Select rows and copy (album-membership, non-destructive) into a
     target album. Per-row copy and batch copy both supported.
   - Select-all toggle for both Flag and Select columns.
   - Hover a thumbnail for a larger preview + pixel dimensions.

   Admin page: Media > Media Curator
   ========================================================= */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------
   Admin menu
   --------------------------------------------------------- */
add_action('admin_menu', function () {
    add_media_page(
        'Media Curator',
        'Media Curator',
        'upload_files',
        'spp-media-curator',
        'spp_media_curator_render_page'
    );
});

/* ---------------------------------------------------------
   Admin page markup
   --------------------------------------------------------- */
function spp_media_curator_render_page() {
    if (!current_user_can('upload_files')) return;
    $albums = spp_album_all_with_counts();
    $nonce  = wp_create_nonce('spp_curator');
    ?>
    <div class="wrap spp-curator">
        <h1>Media Curator</h1>
        <p class="description">
            Pick a source album to edit titles and captions. Titles drive
            <code>[spp_gallery order="name"]</code>. Empty captions are pre-filled with the
            title as a muted suggestion — flag rows and use the button to commit them.
            Select rows to copy them into a target album (the image stays in its
            current album(s) too — copying is additive, never a move).
        </p>

        <div class="spp-curator-toolbar">
            <label><strong>Source album:</strong>
                <select id="spp-curator-source">
                    <option value="">— choose —</option>
                    <?php foreach ($albums as $a): ?>
                        <option value="<?php echo esc_attr($a->slug); ?>">
                            <?php echo esc_html($a->name . ' (' . (int) $a->count . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span id="spp-curator-count" class="spp-curator-count"></span>
        </div>

        <div class="spp-curator-actions" style="display:none;">
            <button type="button" class="button" id="spp-curator-copycaptions">
                Copy title → caption for flagged rows
            </button>

            <span class="spp-curator-sep">|</span>

            <label>Copy selected to:
                <input type="text" id="spp-curator-target" name="spp-curator-target"
                       list="spp-curator-albums" value="" size="32" autocomplete="off"
                       placeholder="album name">
                <datalist id="spp-curator-albums">
                    <?php foreach ($albums as $a): ?>
                        <option value="<?php echo esc_attr($a->name); ?>"
                                data-slug="<?php echo esc_attr($a->slug); ?>"
                                label="<?php echo esc_attr($a->name . ' (' . (int) $a->count . ')'); ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>
            <button type="button" class="button button-primary" id="spp-curator-batchcopy">
                Copy selected →
            </button>

            <span id="spp-curator-selected-count" class="spp-curator-count">Selected: 0</span>
            <span id="spp-curator-status" class="spp-curator-status"></span>
        </div>

        <table class="widefat fixed striped spp-curator-table" style="display:none; margin-top:1rem;">
            <thead>
                <tr>
                    <th style="width:70px;">Image</th>
                    <th>Title</th>
                    <th>Caption</th>
                    <th style="width:50px;" title="Flag for caption-copy">
                        Flag<br><input type="checkbox" id="spp-curator-flagall" title="Flag all">
                    </th>
                    <th style="width:60px;" title="Select for copy">
                        Select<br><input type="checkbox" id="spp-curator-selectall" title="Select all">
                    </th>
                    <th style="width:90px;">Copy</th>
                </tr>
            </thead>
            <tbody id="spp-curator-rows"></tbody>
        </table>

        <p id="spp-curator-empty" style="display:none;"><em>This album has no images.</em></p>

        <div id="spp-curator-hover" class="spp-curator-hover" aria-hidden="true"></div>
    </div>

    <script>
    window.SPP_CURATOR = {
        ajax:  <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo json_encode($nonce); ?>
    };
    </script>
    <?php
}

/* ---------------------------------------------------------
   Enqueue admin assets (only on our page)
   --------------------------------------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'media_page_spp-media-curator') return;
    $theme = get_stylesheet_directory_uri();
    $dir   = get_stylesheet_directory();
    wp_enqueue_style('spp-curator', $theme . '/css/spp-media-curator.css', array(), filemtime($dir . '/css/spp-media-curator.css'));
    wp_enqueue_script('spp-curator', $theme . '/js/spp-media-curator.js', array(), filemtime($dir . '/js/spp-media-curator.js'), true);
});

/* ---------------------------------------------------------
   AJAX: load an album's images
   --------------------------------------------------------- */
add_action('wp_ajax_spp_curator_load', function () {
    check_ajax_referer('spp_curator', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('forbidden');

    $album_slug = isset($_POST['album']) ? sanitize_title(wp_unslash($_POST['album'])) : '';
    $term = $album_slug ? get_term_by('slug', $album_slug, 'spp_album') : null;
    if (!$term) wp_send_json_error('album not found');

    $ids = get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => array(array(
            'taxonomy' => 'spp_album',
            'field'    => 'term_id',
            'terms'    => $term->term_id,
        )),
    ));

    $items = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        $caption = wp_get_attachment_caption($id);
        $title   = get_the_title($id);
        $meta = wp_get_attachment_metadata($id);
        $dims = (is_array($meta) && !empty($meta['width']) && !empty($meta['height']))
              ? $meta['width'] . ' × ' . $meta['height']
              : '';
        $items[] = array(
            'id'         => $id,
            'thumb'      => wp_get_attachment_image_url($id, 'thumbnail'),
            'large'      => wp_get_attachment_image_url($id, 'large'),
            'filename'   => basename(get_attached_file($id)),
            'title'      => $title,
            'caption'    => $caption,
            'suggestion' => ($caption === '' || $caption === null) ? $title : '',
            'dims'       => $dims,
        );
    }
    usort($items, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });

    wp_send_json_success(array('items' => $items, 'count' => count($items)));
});

/* ---------------------------------------------------------
   AJAX: save a single field (title or caption)
   --------------------------------------------------------- */
add_action('wp_ajax_spp_curator_save', function () {
    check_ajax_referer('spp_curator', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('forbidden');

    $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
    $value = isset($_POST['value']) ? wp_kses_post(wp_unslash($_POST['value'])) : '';

    if (!$id || !in_array($field, array('title', 'caption'), true)) {
        wp_send_json_error('bad request');
    }

    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') wp_send_json_error('not an attachment');

    if ($field === 'title') {
        wp_update_post(array('ID' => $id, 'post_title' => sanitize_text_field($value)));
    } else {
        wp_update_post(array('ID' => $id, 'post_excerpt' => $value));
    }
    wp_send_json_success();
});

/* ---------------------------------------------------------
   AJAX: commit captions for flagged rows.
   --------------------------------------------------------- */
add_action('wp_ajax_spp_curator_copycaptions', function () {
    check_ajax_referer('spp_curator', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('forbidden');

    $ids  = isset($_POST['ids'])  ? array_map('intval', (array) $_POST['ids']) : array();
    $vals = isset($_POST['vals']) ? (array) wp_unslash($_POST['vals']) : array();
    if (empty($ids)) wp_send_json_error('no rows flagged');

    $done = 0;
    foreach ($ids as $i => $id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') continue;
        $caption = isset($vals[$i]) ? wp_kses_post($vals[$i]) : $post->post_title;
        wp_update_post(array('ID' => $id, 'post_excerpt' => $caption));
        $done++;
    }
    wp_send_json_success(array('updated' => $done));
});

/* ---------------------------------------------------------
   AJAX: copy attachments into a target album (membership, additive)
   --------------------------------------------------------- */
add_action('wp_ajax_spp_curator_copyalbum', function () {
    check_ajax_referer('spp_curator', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('forbidden');

    $ids    = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
    $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';
    // Defensive: some browsers fill the input with the <option>'s label
    // (which we set to "Name (count)" for the datalist hint) instead of
    // its value when a suggestion is picked — strip a trailing "(N)" so
    // that doesn't get created as a literal album name.
    $target = trim( preg_replace( '/\s*\(\d+\)\s*$/', '', $target ) );

    if (empty($ids))   wp_send_json_error('no images selected');
    if ($target === '') wp_send_json_error('enter a target album name');

    $existing_term = spp_album_get($target);
    $created = !$existing_term;
    $tid = spp_album_get_or_create($target);
    if (!$tid) wp_send_json_error('could not create/find target album');

    $copied = 0;
    foreach ($ids as $id) {
        if (has_term($tid, 'spp_album', $id)) continue; // already there, matches FBL's INSERT IGNORE no-op
        wp_set_object_terms($id, array($tid), 'spp_album', true); // append=true — additive, not a move
        $copied++;
    }

    $term = get_term($tid, 'spp_album');
    wp_send_json_success(array(
        'target'    => $target,
        'name'      => $term && !is_wp_error($term) ? $term->name : $target,
        'slug'      => $term && !is_wp_error($term) ? $term->slug : sanitize_title($target),
        'copied'    => $copied,
        'skipped'   => count($ids) - $copied,
        'created'   => $created,
        'new_count' => $term && !is_wp_error($term) ? (int) $term->count : 0,
    ));
});
