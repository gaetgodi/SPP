<?php
/**
 * SPP Rich Text Editor — reusable front-end editor component
 *
 * File: mu-plugins/spp-rich-editor.php
 * Version: 1.0.0
 * Date:    2026-06-13
 *
 * PURPOSE:
 *   A self-contained rich text editor for FRONT-END forms. Unlike
 *   wp_editor()/TinyMCE, it does not depend on wp-admin scripts being
 *   enqueued, so it works reliably anywhere a shortcode renders —
 *   theme templates, plugin shortcodes, and Code Manager snippets.
 *
 * USAGE:
 *   echo spp_rich_editor( 'field_name', $existing_html, array(
 *       'rows'        => 8,            // visible height in ~text rows
 *       'placeholder' => 'Type here...',
 *   ) );
 *
 *   The form this sits in posts $_POST['field_name'] containing HTML.
 *   ALWAYS sanitize on save with wp_kses_post():
 *       $clean = wp_kses_post( $_POST['field_name'] ?? '' );
 *
 * TOOLBAR:
 *   Bold, Italic, Underline, H2, H3, bullet list, numbered list,
 *   text color (fixed palette), align left/center/right, link, clear.
 *
 * NOTES:
 *   - Multiple editors per page are supported (each gets a unique id).
 *   - Shared CSS + the toolbar command JS are printed once per request.
 *   - Editable text is forced dark (#222) on white so it is always
 *     visible regardless of theme/page text color.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a rich text editor field.
 *
 * @param string $field_name  POST field name (also used to derive ids).
 * @param string $content     Existing HTML content to preload.
 * @param array  $args        Optional: 'rows' (int), 'placeholder' (string).
 * @return string             HTML for the editor.
 */
function spp_rich_editor( $field_name, $content = '', $args = array() ) {

    $rows        = isset( $args['rows'] ) ? (int) $args['rows'] : 8;
    $placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : 'Type here...';
    $min_height  = max( 80, $rows * 22 );  // ~22px per row

    // Unique, stable-ish id per field name + a random suffix so the same
    // field rendered twice on a page will not collide.
    static $counter = 0;
    $counter++;
    $uid     = 'spp_re_' . preg_replace( '/[^a-zA-Z0-9_]/', '_', $field_name ) . '_' . $counter;
    $ta_id   = $uid . '_ta';
    $ed_id   = $uid . '_ed';

    // Shared assets printed once per request.
    spp_rich_editor_assets();

    ob_start();
    ?>
    <div class="spp-re-wrap" data-spp-re="<?php echo esc_attr( $uid ); ?>">
        <div class="spp-re-toolbar" role="toolbar" aria-label="Formatting">
            <button type="button" class="spp-re-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>
            <button type="button" class="spp-re-btn" data-cmd="italic" title="Italic"><em>I</em></button>
            <button type="button" class="spp-re-btn" data-cmd="underline" title="Underline"><u>U</u></button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="formatBlock" data-val="h2" title="Heading">H2</button>
            <button type="button" class="spp-re-btn" data-cmd="formatBlock" data-val="h3" title="Subheading">H3</button>
            <button type="button" class="spp-re-btn" data-cmd="formatBlock" data-val="p" title="Normal text">P</button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="insertUnorderedList" title="Bullet list">&#8226; List</button>
            <button type="button" class="spp-re-btn" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="justifyLeft" title="Align left">&#8676;</button>
            <button type="button" class="spp-re-btn" data-cmd="justifyCenter" title="Align center">&#8596;</button>
            <button type="button" class="spp-re-btn" data-cmd="justifyRight" title="Align right">&#8677;</button>
            <span class="spp-re-sep"></span>
            <span class="spp-re-colors">
                <button type="button" class="spp-re-color" data-color="#222222" style="background:#222222;" title="Black"></button>
                <button type="button" class="spp-re-color" data-color="#c0392b" style="background:#c0392b;" title="Red"></button>
                <button type="button" class="spp-re-color" data-color="#00897B" style="background:#00897B;" title="Teal"></button>
                <button type="button" class="spp-re-color" data-color="#3766AB" style="background:#3766AB;" title="Blue"></button>
                <button type="button" class="spp-re-color" data-color="#e67e22" style="background:#e67e22;" title="Orange"></button>
                <button type="button" class="spp-re-color" data-color="#2e7d32" style="background:#2e7d32;" title="Green"></button>
            </span>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="createLink" title="Insert link">&#128279;</button>
            <button type="button" class="spp-re-btn" data-cmd="unlink" title="Remove link">&#10006;&#128279;</button>
        </div>

        <div id="<?php echo esc_attr( $ed_id ); ?>"
             class="spp-re-editable"
             contenteditable="true"
             data-target="<?php echo esc_attr( $ta_id ); ?>"
             data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
             style="min-height:<?php echo (int) $min_height; ?>px;"><?php
                // Content is already-sanitized HTML from the DB; output as-is.
                echo wp_kses_post( $content );
            ?></div>

        <textarea id="<?php echo esc_attr( $ta_id ); ?>"
                  name="<?php echo esc_attr( $field_name ); ?>"
                  class="spp-re-hidden"
                  style="display:none;"><?php echo esc_textarea( $content ); ?></textarea>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Print shared CSS + JS once per request.
 */
function spp_rich_editor_assets() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style>
    .spp-re-wrap { border:1px solid #ccc; border-radius:6px; overflow:hidden; background:#fff; margin-bottom:4px; }
    .spp-re-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:2px; padding:6px 8px; background:#f3f3f3; border-bottom:1px solid #ddd; }
    .spp-re-btn { background:#fff; border:1px solid #ccc; border-radius:4px; min-width:30px; height:30px; padding:0 8px; font-size:14px; cursor:pointer; color:#333; line-height:1; }
    .spp-re-btn:hover { background:#e8f1f0; border-color:#00897B; }
    .spp-re-btn:active { background:#d8e8e6; }
    .spp-re-sep { width:1px; align-self:stretch; background:#ddd; margin:2px 4px; }
    .spp-re-colors { display:inline-flex; gap:3px; align-items:center; }
    .spp-re-color { width:20px; height:20px; border:1px solid #999; border-radius:3px; cursor:pointer; padding:0; }
    .spp-re-color:hover { transform:scale(1.15); }
    .spp-re-editable { padding:12px 14px; font-size:0.95rem; line-height:1.6; color:#222 !important; background:#fff !important; outline:none; }
    .spp-re-editable * { color:inherit; }
    .spp-re-editable:empty:before { content:attr(data-placeholder); color:#999 !important; }
    .spp-re-editable:focus { box-shadow:inset 0 0 0 2px rgba(0,137,123,0.15); }
    .spp-re-editable h2 { font-size:1.4rem; margin:0.4em 0; color:#222; }
    .spp-re-editable h3 { font-size:1.15rem; margin:0.4em 0; color:#222; }
    .spp-re-editable ul, .spp-re-editable ol { margin:0.4em 0; padding-left:1.6em; }
    .spp-re-editable a { color:#3766AB; text-decoration:underline; }
    </style>
    <script>
    (function() {
        if ( window.sppRichEditorInit ) return;
        window.sppRichEditorInit = true;

        function syncToTextarea( ed ) {
            var ta = document.getElementById( ed.getAttribute('data-target') );
            if ( ta ) ta.value = ed.innerHTML;
        }

        function exec( ed, cmd, val ) {
            ed.focus();
            try {
                if ( cmd === 'formatBlock' ) {
                    document.execCommand( 'formatBlock', false, val );
                } else if ( cmd === 'createLink' ) {
                    var url = window.prompt( 'Enter the link URL (include https://):', 'https://' );
                    if ( url ) document.execCommand( 'createLink', false, url );
                } else {
                    document.execCommand( cmd, false, null );
                }
            } catch (e) {}
            syncToTextarea( ed );
        }

        // Delegate all toolbar clicks.
        document.addEventListener( 'click', function( e ) {
            var btn = e.target.closest ? e.target.closest('.spp-re-btn, .spp-re-color') : null;
            if ( ! btn ) return;
            var wrap = btn.closest('.spp-re-wrap');
            if ( ! wrap ) return;
            var ed = wrap.querySelector('.spp-re-editable');
            if ( ! ed ) return;
            e.preventDefault();

            if ( btn.classList.contains('spp-re-color') ) {
                ed.focus();
                try { document.execCommand( 'foreColor', false, btn.getAttribute('data-color') ); } catch(e2) {}
                syncToTextarea( ed );
            } else {
                exec( ed, btn.getAttribute('data-cmd'), btn.getAttribute('data-val') );
            }
        });

        // Keep textarea in sync as the user types.
        document.addEventListener( 'input', function( e ) {
            if ( e.target.classList && e.target.classList.contains('spp-re-editable') ) {
                syncToTextarea( e.target );
            }
        });

        // Safety net: sync every editor on any form submit.
        document.addEventListener( 'submit', function( e ) {
            var eds = e.target.querySelectorAll ? e.target.querySelectorAll('.spp-re-editable') : [];
            for ( var i = 0; i < eds.length; i++ ) { syncToTextarea( eds[i] ); }
        }, true );
    })();
    </script>
    <?php
}
