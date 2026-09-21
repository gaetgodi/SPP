<?php
/* =========================================================
   SPP PDF Shortcode Generator (admin)
   Version: 1.0.0
   Date: 2026-09-21

   PURPOSE:
   wp-admin screen (under Pages, directly below Report Generator) that
   lets Gaetan pick a PDF from the Media Library via WordPress's own
   native media picker (wp.media()) and optionally type a title, then
   copies out the ready-to-paste [spp_pdf file="<id>" title="<title>"]
   shortcode text for inc/spp-pdf.php. One-shot generator only -- no DB
   write, nothing persisted, matching the "no need to persist anything"
   instruction this was built against.

   Client-side only (no AJAX): the shortcode string is built purely from
   the attachment ID wp.media() hands back and the title field's current
   value, both already in the browser -- there's nothing a server round
   trip would add. This is a deliberate departure from Report Generator's
   own "no JS" philosophy (documented in that file's own header) only
   because the one thing this task explicitly asks for -- "WordPress's
   native media picker/uploader UI" -- IS wp.media(), a JS component;
   there's no non-JS way to offer that specific picker.

   ACCESS CONTROL -- identical two-layer pattern to Report Generator
   (inc/spp-report-generator-admin.php), copied deliberately rather than
   reimplemented, per this task's "gate the same way" instruction:
     1. add_submenu_page()'s own capability: 'manage_options'.
     2. The real, load-bearing check at the top of the render callback:
        an explicit in_array('administrator', ...) role check, not
        current_user_can('manage_options') again.
   Registered via the same require_once ordering trick Report Generator
   itself relies on for menu position -- requiring this file immediately
   after spp-report-generator-admin.php in functions.php places its
   add_action('admin_menu', ...) callback right after Report Generator's
   own, so WordPress appends this submenu item directly below it (both
   registered at the default priority 10, so registration order is menu
   order).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function() {
    $hook = add_submenu_page(
        'edit.php?post_type=page',
        'PDF Shortcode Generator',
        'PDF Shortcode Generator',
        'manage_options', // layer 1: hides the menu item from non-admins
        'spp-pdf-shortcode-generator',
        'spp_render_pdf_shortcode_generator_page'
    );

    // Only load the media picker JS on this exact screen.
    add_action( 'load-' . $hook, function() {
        add_action( 'admin_enqueue_scripts', function() {
            wp_enqueue_media();
        } );
    } );
} );

function spp_render_pdf_shortcode_generator_page() {
    // Layer 2: the real gate -- same technique/reasoning as
    // spp_render_report_generator_page()'s own (inc/spp-report-generator-
    // admin.php), copied deliberately, not reimplemented independently.
    $roles = (array) wp_get_current_user()->roles;
    if ( ! in_array( 'administrator', $roles, true ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }
    ?>
    <div class="wrap">
        <h1>PDF Shortcode Generator</h1>
        <p>Pick a PDF from the Media Library, optionally give it a title, and copy the generated <code>[spp_pdf]</code> shortcode onto a page.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="spp-pdf-gen-pick">PDF file</label></th>
                <td>
                    <button type="button" class="button" id="spp-pdf-gen-pick">Select PDF from Media Library</button>
                    <p id="spp-pdf-gen-selected" style="margin-top:8px;font-style:italic;color:#555;">No file selected.</p>
                    <input type="hidden" id="spp-pdf-gen-id" value="">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="spp-pdf-gen-title">Title (optional)</label></th>
                <td>
                    <input type="text" id="spp-pdf-gen-title" class="regular-text" placeholder="e.g. SPP Constitution">
                    <p class="description">Shown as a visible caption above the embedded PDF, and used as the PDF viewer's accessible label. Leave blank for neither -- see inc/spp-pdf.php for the field's exact behavior.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="spp-pdf-gen-output">Shortcode</label></th>
                <td>
                    <input type="text" id="spp-pdf-gen-output" class="large-text code" readonly value="[spp_pdf file=&quot;&quot;]" onclick="this.select();">
                    <p>
                        <button type="button" class="button button-primary" id="spp-pdf-gen-copy">Copy to clipboard</button>
                        <span id="spp-pdf-gen-copied" style="margin-left:8px;color:#2271b1;display:none;">Copied!</span>
                    </p>
                </td>
            </tr>
        </table>
    </div>

    <script>
    (function() {
        var pickBtn   = document.getElementById('spp-pdf-gen-pick');
        var idField   = document.getElementById('spp-pdf-gen-id');
        var titleField= document.getElementById('spp-pdf-gen-title');
        var selectedP = document.getElementById('spp-pdf-gen-selected');
        var output    = document.getElementById('spp-pdf-gen-output');
        var copyBtn   = document.getElementById('spp-pdf-gen-copy');
        var copiedMsg = document.getElementById('spp-pdf-gen-copied');
        var frame;

        function escAttr(s) {
            return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
        }

        function updateOutput() {
            var id = idField.value;
            var title = titleField.value.trim();
            var tag = '[spp_pdf file="' + id + '"';
            if (title !== '') {
                tag += ' title="' + escAttr(title) + '"';
            }
            tag += ']';
            output.value = tag;
        }

        pickBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'Select a PDF',
                library: { type: 'application/pdf' },
                multiple: false,
                button: { text: 'Use this PDF' }
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                idField.value = attachment.id;
                selectedP.textContent = attachment.filename + ' (ID ' + attachment.id + ')';
                updateOutput();
            });
            frame.open();
        });

        titleField.addEventListener('input', updateOutput);

        copyBtn.addEventListener('click', function() {
            output.select();
            navigator.clipboard.writeText(output.value).then(function() {
                copiedMsg.style.display = 'inline';
                setTimeout(function() { copiedMsg.style.display = 'none'; }, 1500);
            }).catch(function() {
                document.execCommand('copy');
            });
        });
    })();
    </script>
    <?php
}
