<?php
/* =========================================================
   SPP GALLERY BUILDER (admin page)
   Ported from fishbucklake/inc/gallery-builder.php.

   - Generates [spp_gallery] shortcodes (spp_album taxonomy dropdown,
     via the shared spp_album_all_with_counts() helper — same one
     Media Curator's source-album dropdown already uses)
   - Live preview panel (AJAX, renders real shortcode output)

   Deliberately NOT ported from FBL's version:
   - "Find Pages" / "Update Pages" — FBL's version can scan live post
     content for existing shortcode usages and batch-rewrite them
     (base64 raw/token round-tripping, Divi-5-encoding-aware). That's
     a write path that directly rewrites real page content; it wasn't
     part of what was asked for here, so it's deferred rather than
     silently built. Straightforward to port later from the FBL
     source if wanted — the scaffolding (find-shortcodes-in-content,
     exact-match-safe replace) doesn't depend on FileBird at all.
   - FBL's custom 'manage_fbl_gallery' capability (granted to editor/
     admin via admin_init) — uses upload_files instead, matching
     Albums, Flickr Import, and Media Curator's access level exactly.

   Admin page: Media > Gallery Builder
   ========================================================= */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------
   Admin menu
   --------------------------------------------------------- */
add_action('admin_menu', function () {
    add_media_page(
        'SPP Gallery Shortcode Builder',
        'Gallery Builder',
        'upload_files',
        'spp-gallery-builder',
        'spp_gallery_builder_page'
    );
});

/* ---------------------------------------------------------
   Enqueue frontend gallery CSS on the builder page so the
   preview looks close to the real thing
   --------------------------------------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'media_page_spp-gallery-builder') return;
    $theme = get_stylesheet_directory_uri();
    $dir   = get_stylesheet_directory();
    wp_enqueue_style('spp-gallery', $theme . '/css/spp-gallery.css', array(), filemtime($dir . '/css/spp-gallery.css'));
});

/* ---------------------------------------------------------
   AJAX: render a shortcode preview
   --------------------------------------------------------- */
add_action('wp_ajax_spp_gb_preview', function () {
    check_ajax_referer('spp_gallery_builder', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('Not allowed.');

    $shortcode = isset($_POST['shortcode']) ? wp_unslash($_POST['shortcode']) : '';

    // Only allow our own shortcode through
    if (!preg_match('/^\[spp_gallery\s[^\]]*\]$/', $shortcode)) {
        wp_send_json_error('Invalid shortcode.');
    }

    wp_send_json_success(do_shortcode($shortcode));
});

/* ---------------------------------------------------------
   AJAX: get image titles for an album (for the Links panel)
   --------------------------------------------------------- */
add_action('wp_ajax_spp_gb_album_titles', function () {
    check_ajax_referer('spp_gallery_builder', 'nonce');
    if (!current_user_can('upload_files')) wp_send_json_error('Not allowed.');

    $album = isset($_POST['album']) ? sanitize_text_field(wp_unslash($_POST['album'])) : '';
    if ($album === '') wp_send_json_error('No album given.');

    $ids = spp_gallery_get_ids($album);
    if ($ids === null || empty($ids)) {
        wp_send_json_success(array());
    }

    $titles = array();
    foreach ($ids as $id) {
        $titles[] = get_the_title($id);
    }
    $titles = array_values(array_unique($titles));

    wp_send_json_success($titles);
});

/* ---------------------------------------------------------
   Builder page
   --------------------------------------------------------- */
function spp_gallery_builder_page() {
    // spp_album taxonomy terms with image counts — shared helper,
    // same one Media Curator's source-album dropdown uses.
    $albums = spp_album_all_with_counts();

    // Registered image sizes
    $sizes = get_intermediate_image_sizes();
    $sizes[] = 'full';

    $nonce = wp_create_nonce('spp_gallery_builder');
    ?>
    <div class="wrap">
        <h1>SPP Gallery Shortcode Builder</h1>
        <p>Pick an album and options. Preview live, then copy the shortcode onto a page.</p>

        <?php if (empty($albums)): ?>
            <div class="notice notice-warning"><p>No albums found. Tag some images into an album first (Media Curator, or Flickr Import).</p></div>
        <?php endif; ?>

        <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">

        <!-- ================= OPTIONS PANEL ================= -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 640px; flex: 0 0 auto;">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sppgb-album">Album</label></th>
                    <td>
                        <select id="sppgb-album" style="min-width: 260px;">
                            <?php foreach ($albums as $a): ?>
                                <option value="<?php echo esc_attr($a->name); ?>">
                                    <?php echo esc_html($a->name); ?> (<?php echo intval($a->count); ?> images)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-view">View</label></th>
                    <td>
                        <select id="sppgb-view">
                            <option value="grid">grid (default)</option>
                            <option value="carousel">carousel</option>
                            <option value="masonry">masonry</option>
                        </select>
                    </td>
                </tr>
                <tr class="sppgb-row-columns">
                    <th scope="row"><label for="sppgb-columns">Columns</label></th>
                    <td>
                        <select id="sppgb-columns">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3 (default)</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </select>
                        <p class="description">Grid and masonry only. Drops to 2 columns under 980px, 1 under 600px.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-limit">Limit</label></th>
                    <td>
                        <input type="number" id="sppgb-limit" value="0" min="0" step="1" style="width: 90px;">
                        <p class="description">0 = show all images in the album.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-size">Image size</label></th>
                    <td>
                        <select id="sppgb-size">
                            <?php foreach ($sizes as $s): ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($s, 'large'); ?>>
                                    <?php echo esc_html($s); ?><?php echo ($s === 'large') ? ' (default)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Thumbnail size shown on the page. Lightbox always opens the full image.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-order">Order</label></th>
                    <td>
                        <select id="sppgb-order">
                            <option value="date_desc">date_desc - newest first (default)</option>
                            <option value="date_asc">date_asc - oldest first</option>
                            <option value="name">name - by Title label, A-Z</option>
                            <option value="name_desc">name_desc - by Title label, Z-A</option>
                            <option value="random">random</option>
                        </select>
                    </td>
                </tr>
                <tr class="sppgb-row-shuffle" style="display: none;">
                    <th scope="row"><label for="sppgb-shuffle">Shuffle</label></th>
                    <td>
                        <select id="sppgb-shuffle">
                            <option value="pageload">pageload - new order every visit (default)</option>
                            <option value="daily">daily - changes at midnight</option>
                            <option value="weekly">weekly - changes Monday</option>
                            <option value="never">never - one fixed random order</option>
                        </select>
                    </td>
                </tr>
                <tr class="sppgb-row-autoplay" style="display: none;">
                    <th scope="row"><label for="sppgb-autoplay">Autoplay (ms)</label></th>
                    <td>
                        <input type="number" id="sppgb-autoplay" value="5000" min="1000" step="500" style="width: 110px;">
                        <p class="description">Carousel only. Time each slide is shown; 5000 = 5 seconds.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-caption">Lightbox caption</label></th>
                    <td>
                        <select id="sppgb-caption">
                            <option value="caption">caption - only if Caption field is filled (default)</option>
                            <option value="title">title - fall back to Title/filename</option>
                            <option value="none">none - never show captions</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-tcaption">Thumbnail caption</label></th>
                    <td>
                        <select id="sppgb-tcaption">
                            <option value="none">none - no text under thumbnails (default)</option>
                            <option value="caption">caption - only if Caption field is filled</option>
                            <option value="title">title - fall back to Title/filename</option>
                        </select>
                    </td>
                </tr>
                <tr class="sppgb-row-fit">
                    <th scope="row"><label for="sppgb-fit">Image fit</label></th>
                    <td>
                        <select id="sppgb-fit">
                            <option value="cover">cover - fill the frame, crop overflow (default)</option>
                            <option value="contain">contain - full image, letterbox if needed</option>
                        </select>
                        <p class="description">Carousel and grid. Masonry always shows full images.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sppgb-link">Click behavior</label></th>
                    <td>
                        <select id="sppgb-link">
                            <option value="lightbox">lightbox (default)</option>
                            <option value="none">none - images not clickable</option>
                        </select>
                    </td>
                </tr>
            </table>

            <hr>

            <h2>Page links (optional)</h2>
            <p class="description">
                Turn specific images into links to other pages instead of the lightbox.
                Leave a field blank to keep that image's normal behavior.
            </p>
            <div id="sppgb-links-wrap">
                <em>Select an album to see its images here.</em>
            </div>

            <hr>

            <h2 style="margin-top: 0;">Generated shortcode</h2>
            <p>
                <code id="sppgb-output" style="display: block; padding: 12px; background: #f0f0f1; font-size: 14px; user-select: all; word-break: break-all;"></code>
            </p>
            <p>
                <button type="button" class="button button-primary" id="sppgb-copy">Copy to Clipboard</button>
                <span id="sppgb-copied" style="margin-left: 10px; color: #00a32a; font-weight: bold; display: none;">Copied!</span>
            </p>
        </div>

        <!-- ================= PREVIEW PANEL ================= -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccc; flex: 1 1 500px; min-width: 400px;">
            <h2 style="margin-top: 0;">Preview
                <span class="description" style="font-weight: normal; font-size: 12px;">
                    (approximate - lightbox and carousel autoplay only run on the real page)
                </span>
            </h2>
            <div id="sppgb-preview" style="border: 1px dashed #ccc; padding: 12px; min-height: 120px;">
                <em>Adjust options to load preview...</em>
            </div>
        </div>

        </div><!-- /flex -->

        <script>
        (function() {
            var nonce = '<?php echo esc_js($nonce); ?>';
            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            var els = {
                album:    document.getElementById('sppgb-album'),
                view:     document.getElementById('sppgb-view'),
                columns:  document.getElementById('sppgb-columns'),
                limit:    document.getElementById('sppgb-limit'),
                size:     document.getElementById('sppgb-size'),
                order:    document.getElementById('sppgb-order'),
                shuffle:  document.getElementById('sppgb-shuffle'),
                autoplay: document.getElementById('sppgb-autoplay'),
                caption:  document.getElementById('sppgb-caption'),
                tcaption: document.getElementById('sppgb-tcaption'),
                fit:      document.getElementById('sppgb-fit'),
                link:     document.getElementById('sppgb-link'),
                linksWrap: document.getElementById('sppgb-links-wrap'),
                output:   document.getElementById('sppgb-output'),
                copyBtn:  document.getElementById('sppgb-copy'),
                copied:   document.getElementById('sppgb-copied'),
                preview:  document.getElementById('sppgb-preview')
            };

            var rowColumns  = document.querySelector('.sppgb-row-columns');
            var rowShuffle  = document.querySelector('.sppgb-row-shuffle');
            var rowAutoplay = document.querySelector('.sppgb-row-autoplay');

            var previewTimer = null;
            var albumTitles = [];
            var linksLoadedFor = '';

            function build() {
                var view  = els.view.value;
                var order = els.order.value;

                rowColumns.style.display  = (view === 'carousel') ? 'none' : '';
                rowAutoplay.style.display = (view === 'carousel') ? '' : 'none';
                rowShuffle.style.display  = (order === 'random') ? '' : 'none';

                var parts = ['[spp_gallery album="' + els.album.value + '"'];

                if (view !== 'grid') parts.push('view="' + view + '"');

                if (view !== 'carousel' && els.columns.value !== '3') {
                    parts.push('columns="' + els.columns.value + '"');
                }

                var limit = parseInt(els.limit.value, 10) || 0;
                if (limit > 0) parts.push('limit="' + limit + '"');

                if (els.size.value !== 'large') parts.push('size="' + els.size.value + '"');

                if (order !== 'date_desc') parts.push('order="' + order + '"');

                if (order === 'random' && els.shuffle.value !== 'pageload') {
                    parts.push('shuffle="' + els.shuffle.value + '"');
                }

                if (view === 'carousel') {
                    var ap = parseInt(els.autoplay.value, 10) || 5000;
                    if (ap !== 5000) parts.push('autoplay="' + ap + '"');
                }

                if (els.link.value !== 'lightbox') parts.push('link="' + els.link.value + '"');

                var linksAttr = buildLinksAttr();
                if (linksAttr) parts.push('links="' + linksAttr + '"');

                if (els.link.value === 'lightbox' && els.caption.value !== 'caption') {
                    parts.push('caption="' + els.caption.value + '"');
                }

                if (els.tcaption.value !== 'none') {
                    parts.push('thumb_caption="' + els.tcaption.value + '"');
                }

                if (els.fit.value !== 'cover' && els.view.value !== 'masonry') {
                    parts.push('fit="' + els.fit.value + '"');
                }

                els.output.textContent = parts.join(' ') + ']';

                schedulePreview();
            }

            function loadAlbumTitles() {
                var album = els.album.value;
                if (album === linksLoadedFor) return;
                linksLoadedFor = album;

                // Clear stale links immediately so the shortcode never carries
                // a previous album's titles while the new list loads.
                albumTitles = [];
                els.linksWrap.innerHTML = '<em>Loading images...</em>';
                build();

                var body = new URLSearchParams();
                body.append('action', 'spp_gb_album_titles');
                body.append('nonce', nonce);
                body.append('album', album);

                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success || !res.data.length) {
                            albumTitles = [];
                            els.linksWrap.innerHTML = '<em>No images found in this album.</em>';
                            return;
                        }
                        albumTitles = res.data;
                        renderLinksPanel();
                    })
                    .catch(function() {
                        els.linksWrap.innerHTML = '<em>Could not load images.</em>';
                    });
            }

            function renderLinksPanel() {
                var html = '<table class="widefat striped"><tbody>';
                albumTitles.forEach(function(title, i) {
                    html += '<tr>' +
                        '<td style="width:40%;">' + escapeHtml(title) + '</td>' +
                        '<td><input type="text" class="sppgb-link-input" data-title="' +
                        escapeHtml(title).replace(/"/g, '&quot;') +
                        '" placeholder="/page-slug/ or leave blank" style="width:100%;"></td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
                els.linksWrap.innerHTML = html;

                els.linksWrap.querySelectorAll('.sppgb-link-input').forEach(function(inp) {
                    inp.addEventListener('input', build);
                });
            }

            function buildLinksAttr() {
                if (!els.linksWrap) return '';
                var pairs = [];
                els.linksWrap.querySelectorAll('.sppgb-link-input').forEach(function(inp) {
                    var url = inp.value.trim();
                    if (!url) return;
                    var title = inp.getAttribute('data-title') || '';
                    pairs.push(title + ':' + url);
                });
                return pairs.join(',');
            }

            function schedulePreview() {
                if (previewTimer) clearTimeout(previewTimer);
                previewTimer = setTimeout(loadPreview, 500);
            }

            function loadPreview() {
                var body = new URLSearchParams();
                body.append('action', 'spp_gb_preview');
                body.append('nonce', nonce);
                body.append('shortcode', els.output.textContent);

                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            els.preview.innerHTML = res.data ||
                                '<em>Shortcode produced no output (empty album?).</em>';
                            // Carousel preview: show first slide statically
                            els.preview.querySelectorAll('.spp-gallery--carousel .spp-gallery-item').forEach(function(s, i) {
                                s.classList.toggle('is-active', i === 0);
                            });
                        } else {
                            els.preview.innerHTML = '<em>Preview error: ' + (res.data || 'unknown') + '</em>';
                        }
                    })
                    .catch(function() {
                        els.preview.innerHTML = '<em>Preview request failed.</em>';
                    });
            }

            function escapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            ['view', 'columns', 'limit', 'size', 'order', 'shuffle',
            'autoplay', 'caption', 'tcaption', 'fit', 'link'].forEach(function(key) {
                els[key].addEventListener('change', build);
                els[key].addEventListener('input', build);
            });

            // Album changes: clear+rebuild happens inside loadAlbumTitles,
            // so it alone drives both the panel and the shortcode.
            els.album.addEventListener('change', loadAlbumTitles);

            els.copyBtn.addEventListener('click', function() {
                var text = els.output.textContent;
                function done() {
                    els.copied.style.display = 'inline';
                    setTimeout(function() { els.copied.style.display = 'none'; }, 2000);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    done();
                }
            });

            build();
            loadAlbumTitles();
        })();
        </script>
    </div>
    <?php
}
