<?php
/* =========================================================
   SPP ALBUM "CREATED" NOTICE
   Shows "Created new album: <name>" whenever a NEW spp_album term
   is made, so it's unmistakable that a new album was created rather
   than an existing one reused (a typo in an album name silently
   creates a new album otherwise).

   Hooks WP's own created_spp_album action, so it covers every way
   an album can be created without touching each tool:
   - Media Library "Albums" field (media modal or the attachment edit
     screen: core's wp_set_object_terms creates unknown names)
   - Media Curator "Copy selected to:" (spp_album_get_or_create)
   - Flickr Import, Albums screen, anything else

   Delivery:
   - Admin AJAX/REST requests (media modal, Media Curator): the album
     name rides back on an X-SPP-Album-Created response header, and the
     small script below shows a toast as soon as the request finishes.
   - Everything else (normal form posts, redirects, a request from the
     front end): queued per user and shown as a toast on the next admin
     page that user loads.
   Only for users who can manage media (upload_files).
   ========================================================= */

if (!defined('ABSPATH')) exit;

define('SPP_ALBUM_NOTICE_META', '_spp_album_created_queue');

add_action('created_spp_album', function ($term_id) {
    if (!is_user_logged_in() || !current_user_can('upload_files')) return;

    $term = get_term($term_id, 'spp_album');
    if (!$term || is_wp_error($term)) return;

    $is_async = wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST);
    $referer  = (string) wp_get_raw_referer();
    $from_admin = $referer !== '' && strpos($referer, admin_url()) === 0;

    if ($is_async && $from_admin && !headers_sent()) {
        header('X-SPP-Album-Created: ' . rawurlencode($term->name), false);
        return;
    }

    $queue = get_user_meta(get_current_user_id(), SPP_ALBUM_NOTICE_META, true);
    $queue = is_array($queue) ? $queue : array();
    $queue[] = $term->name;
    update_user_meta(get_current_user_id(), SPP_ALBUM_NOTICE_META, array_slice($queue, -20));
});

add_action('admin_footer', function () {
    if (!current_user_can('upload_files')) return;

    $queued = get_user_meta(get_current_user_id(), SPP_ALBUM_NOTICE_META, true);
    $queued = is_array($queued) ? array_values($queued) : array();
    if ($queued) {
        delete_user_meta(get_current_user_id(), SPP_ALBUM_NOTICE_META);
    }
    ?>
    <style>
    #spp-album-toasts{position:fixed;right:20px;bottom:20px;z-index:200000;display:flex;flex-direction:column;gap:8px;max-width:360px;}
    .spp-album-toast{background:#fff;border-left:4px solid #00a32a;box-shadow:0 2px 10px rgba(0,0,0,.2);padding:12px 36px 12px 14px;font-size:14px;line-height:1.4;position:relative;border-radius:2px;}
    .spp-album-toast strong{display:block;margin-bottom:2px;}
    .spp-album-toast button{position:absolute;top:6px;right:6px;border:0;background:none;font-size:18px;line-height:1;cursor:pointer;color:#50575e;padding:2px 6px;}
    </style>
    <div id="spp-album-toasts" role="status" aria-live="polite"></div>
    <script>
    (function () {
        var box = document.getElementById('spp-album-toasts');
        var HEADER = 'X-SPP-Album-Created';

        function toast(name) {
            var t = document.createElement('div');
            t.className = 'spp-album-toast';
            var title = document.createElement('strong');
            title.textContent = 'Created new album: ' + name;
            var note = document.createElement('span');
            note.textContent = 'If you meant an existing album, check the spelling on the Albums screen.';
            var close = document.createElement('button');
            close.type = 'button';
            close.setAttribute('aria-label', 'Dismiss');
            close.textContent = '×';
            close.addEventListener('click', function () { t.remove(); });
            t.appendChild(title); t.appendChild(note); t.appendChild(close);
            box.appendChild(t);
            setTimeout(function () { t.remove(); }, 15000);
        }

        function fromHeader(value) {
            if (!value) return;
            value.split(',').forEach(function (part) {
                part = part.trim();
                if (part) { try { toast(decodeURIComponent(part)); } catch (e) { toast(part); } }
            });
        }

        // Media modal and other jQuery/XHR requests.
        var open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function () {
            if (!this.sppAlbumHooked) {
                this.sppAlbumHooked = true;
                this.addEventListener('load', function () {
                    try { fromHeader(this.getResponseHeader(HEADER)); } catch (e) {}
                });
            }
            return open.apply(this, arguments);
        };

        // Media Curator and other fetch() requests.
        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function () {
                return origFetch.apply(this, arguments).then(function (res) {
                    try { fromHeader(res.headers.get(HEADER)); } catch (e) {}
                    return res;
                });
            };
        }

        <?php echo wp_json_encode($queued); ?>.forEach(toast);
    })();
    </script>
    <?php
});
