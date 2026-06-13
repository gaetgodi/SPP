<?php
/**
 * SPP Rich Text Editor — reusable front-end editor component
 *
 * File: mu-plugins/spp-rich-editor.php
 * Version: 1.1.0
 * Date:    2026-06-13
 *
 * Changes from 1.0.1:
 * - Toolbar buttons now use inline SVG icons instead of HTML entity
 *   glyphs (which rendered as question marks / boxes in some fonts).
 * - Added Font size control (Small / Normal / Large / X-Large).
 * - Added Line height control (Tight / Normal / Loose).
 * - Font size and line height apply via a span wrapper with inline
 *   style (not execCommand fontSize, which only supports the crude
 *   1-7 scale). wp_kses_post permits style attributes on spans.
 * - Size / line-height selects preserve the text selection the same
 *   way the toolbar buttons do (mousedown preventDefault).
 *
 * Changes from 1.0.0:
 * - Toolbar buttons preventDefault on mousedown so they no longer
 *   steal focus / collapse the selection. Fixes text color (foreColor)
 *   and all selection-based commands.
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
 *   Bold, Italic, Underline, H2, H3, P, bullet list, numbered list,
 *   align left/center/right, text color (fixed palette), font size,
 *   line height, link, unlink.
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

    // Unique id per field name + a counter so the same field rendered
    // twice on a page will not collide.
    static $counter = 0;
    $counter++;
    $uid     = 'spp_re_' . preg_replace( '/[^a-zA-Z0-9_]/', '_', $field_name ) . '_' . $counter;
    $ta_id   = $uid . '_ta';
    $ed_id   = $uid . '_ed';

    // Shared assets printed once per request.
    spp_rich_editor_assets();

    // SVG icon helper (defined in assets as JS is not needed; inline here).
    $ic = 'spp_rich_editor_icon';

    ob_start();
    ?>
    <div class="spp-re-wrap" data-spp-re="<?php echo esc_attr( $uid ); ?>">
        <div class="spp-re-toolbar" role="toolbar" aria-label="Formatting">
            <button type="button" class="spp-re-btn" data-cmd="bold" title="Bold"><?php echo $ic('bold'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="italic" title="Italic"><?php echo $ic('italic'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="underline" title="Underline"><?php echo $ic('underline'); ?></button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn spp-re-btn-text" data-cmd="formatBlock" data-val="h2" title="Heading">H2</button>
            <button type="button" class="spp-re-btn spp-re-btn-text" data-cmd="formatBlock" data-val="h3" title="Subheading">H3</button>
            <button type="button" class="spp-re-btn spp-re-btn-text" data-cmd="formatBlock" data-val="p" title="Normal text">P</button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="insertUnorderedList" title="Bullet list"><?php echo $ic('ul'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="insertOrderedList" title="Numbered list"><?php echo $ic('ol'); ?></button>
            <span class="spp-re-sep"></span>
            <button type="button" class="spp-re-btn" data-cmd="justifyLeft" title="Align left"><?php echo $ic('alignLeft'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="justifyCenter" title="Align center"><?php echo $ic('alignCenter'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="justifyRight" title="Align right"><?php echo $ic('alignRight'); ?></button>
            <span class="spp-re-sep"></span>
            <select class="spp-re-select spp-re-size" title="Font size">
                <option value="">Size</option>
                <option value="14px">Small</option>
                <option value="16px">Normal</option>
                <option value="20px">Large</option>
                <option value="26px">X-Large</option>
            </select>
            <select class="spp-re-select spp-re-lh" title="Line height">
                <option value="">Spacing</option>
                <option value="1.2">Tight</option>
                <option value="1.6">Normal</option>
                <option value="2.0">Loose</option>
            </select>
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
            <button type="button" class="spp-re-btn" data-cmd="createLink" title="Insert link"><?php echo $ic('link'); ?></button>
            <button type="button" class="spp-re-btn" data-cmd="unlink" title="Remove link"><?php echo $ic('unlink'); ?></button>
        </div>

        <div id="<?php echo esc_attr( $ed_id ); ?>"
             class="spp-re-editable"
             contenteditable="true"
             data-target="<?php echo esc_attr( $ta_id ); ?>"
             data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
             style="min-height:<?php echo (int) $min_height; ?>px;"><?php
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
 * Return an inline SVG icon by name. 18x18, currentColor stroke.
 */
function spp_rich_editor_icon( $name ) {
    $svg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    $end = '</svg>';
    switch ( $name ) {
        case 'bold':
            return $svg . '<path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/>' . $end;
        case 'italic':
            return $svg . '<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>' . $end;
        case 'underline':
            return $svg . '<path d="M6 3v7a6 6 0 0 0 12 0V3"/><line x1="4" y1="21" x2="20" y2="21"/>' . $end;
        case 'ul':
            return $svg . '<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>' . $end;
        case 'ol':
            return $svg . '<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4l2-2.5a1 1 0 0 0-2-1"/>' . $end;
        case 'alignLeft':
            return $svg . '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>' . $end;
        case 'alignCenter':
            return $svg . '<line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="5" y1="18" x2="19" y2="18"/>' . $end;
        case 'alignRight':
            return $svg . '<line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>' . $end;
        case 'link':
            return $svg . '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>' . $end;
        case 'unlink':
            return $svg . '<path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M5.17 11.75l-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="2" y1="2" x2="22" y2="22"/>' . $end;
    }
    return '';
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
    .spp-re-btn { display:inline-flex; align-items:center; justify-content:center; background:#fff; border:1px solid #ccc; border-radius:4px; min-width:32px; height:32px; padding:0 6px; font-size:13px; cursor:pointer; color:#333; line-height:1; }
    .spp-re-btn:hover { background:#e8f1f0; border-color:#00897B; color:#00695f; }
    .spp-re-btn:active { background:#d8e8e6; }
    .spp-re-btn-text { font-weight:700; }
    .spp-re-btn svg { display:block; }
    .spp-re-sep { width:1px; align-self:stretch; background:#ddd; margin:2px 4px; }
    .spp-re-select { height:32px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#333; font-size:13px; padding:0 4px; cursor:pointer; }
    .spp-re-select:hover { border-color:#00897B; }
    .spp-re-colors { display:inline-flex; gap:3px; align-items:center; }
    .spp-re-color { width:22px; height:22px; border:1px solid #999; border-radius:3px; cursor:pointer; padding:0; }
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

        function edFor( node ) {
            var wrap = node.closest ? node.closest('.spp-re-wrap') : null;
            return wrap ? wrap.querySelector('.spp-re-editable') : null;
        }

        // Wrap the current selection in a span with an inline style.
        // Used for font-size and line-height (execCommand fontSize only
        // supports the crude 1-7 scale, so we do it manually).
        function wrapSelectionStyle( ed, prop, value ) {
            ed.focus();
            var sel = window.getSelection();
            if ( !sel || sel.rangeCount === 0 || sel.isCollapsed ) return;
            var range = sel.getRangeAt(0);
            var span = document.createElement('span');
            span.style[prop] = value;
            try {
                span.appendChild( range.extractContents() );
                range.insertNode( span );
                // Reselect the wrapped content.
                sel.removeAllRanges();
                var nr = document.createRange();
                nr.selectNodeContents( span );
                sel.addRange( nr );
            } catch (e) {}
            syncToTextarea( ed );
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

        // Prevent toolbar controls from stealing focus / collapsing the
        // selection. mousedown fires BEFORE the selection is cleared.
        document.addEventListener( 'mousedown', function( e ) {
            var ctl = e.target.closest ? e.target.closest('.spp-re-btn, .spp-re-color, .spp-re-select') : null;
            if ( ctl ) {
                // Selects need to open, so only preventDefault for buttons.
                if ( ! ctl.classList.contains('spp-re-select') ) e.preventDefault();
            }
        });

        // Toolbar button clicks.
        document.addEventListener( 'click', function( e ) {
            var btn = e.target.closest ? e.target.closest('.spp-re-btn, .spp-re-color') : null;
            if ( ! btn ) return;
            var ed = edFor( btn );
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

        // Font-size and line-height selects.
        document.addEventListener( 'change', function( e ) {
            var sel = e.target;
            if ( ! sel.classList ) return;
            var ed = edFor( sel );
            if ( ! ed ) return;
            var val = sel.value;
            if ( ! val ) return;
            if ( sel.classList.contains('spp-re-size') ) {
                wrapSelectionStyle( ed, 'fontSize', val );
            } else if ( sel.classList.contains('spp-re-lh') ) {
                wrapSelectionStyle( ed, 'lineHeight', val );
            }
            sel.selectedIndex = 0; // reset label
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
