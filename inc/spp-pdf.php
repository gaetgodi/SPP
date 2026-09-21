<?php
/* =========================================================
   SPP PDF Embed (generic)
   Version: 1.1.0
   Date: 2026-09-21

   Changes from 1.0.0:
   - title now optionally renders as a visible caption directly above the
     iframe (a <h3> styled off the same --spp-h3-* tokens css/spp-tokens.css
     already defines for every other heading on the site, not a one-off
     color/size) -- purely opt-in: title omitted/empty still renders no
     caption at all, unchanged from before. The invisible iframe title=
     attribute and data-pdf-title are unchanged/still set either way,
     falling back to "PDF document" when title is empty so the iframe
     always has an accessible name even with no visible caption.
     IMPORTANT: the 4 real pages already using [spp_pdf] (Constitution x2,
     Skills Self-Assessment, Instructor Certification Subsidy) all already
     pass a title attribute (added when they were migrated onto this
     shortcode) -- this change makes all 4 start showing a visible caption
     where none rendered before. Not reverted here since a visible
     document title is a reasonable default any of the 4 could plausibly
     want; if any specific page shouldn't show one, drop title from that
     page's shortcode call (or blank it) rather than changing this
     function's default behavior.

   PURPOSE:
   Generic replacement for the three near-identical hardcoded PDF-embed
   shortcodes this consolidates -- spp_pdf_constitution,
   spp_pdf_skills_assessment, spp_pdf_instructor_subsidy (each just a
   bare function building one hardcoded /wp-content/uploads/... URL into
   a Google Docs viewer iframe, no other logic). Same visual output/
   behavior, but the file and a title are shortcode attributes instead
   of one file per function.

   USAGE: [spp_pdf file="20008435" title="SPP Constitution"]
     - file: a Media Library attachment ID (preferred -- resolved via
       wp_get_attachment_url(), so Gaetan can add a new PDF later just
       by uploading it and reading its ID off the Media Library edit
       screen, no path-typing) OR a literal URL/relative path (anything
       non-numeric is used as-is, absolute-ified against the site URL
       if it isn't already absolute) -- covers a PDF that for whatever
       reason isn't/can't be a clean Media Library attachment.
     - title: used as the iframe's title="" attribute (accessibility --
       none of the three original shortcodes had one) and passed
       through to the wrapper as data-pdf-title, ready for a future
       visible caption without touching the render logic again.

   Deliberately left ready for, but NOT building now (per instruction --
   only what's asked for today):
     - a page-count indicator (would hang off spp_pdf_resolve_url()'s
       $file input, e.g. via an Imagick/fpdf page count on the resolved
       path)
     - a "download" vs "view inline" toggle (the wrapper div + data-*
       attributes below are already the hook point -- a toggle button
       could swap the iframe src between the viewer URL and a plain
       $url download link without any markup restructuring)
     - consistent cross-page styling (the .spp-pdf-embed wrapper class
       is the hook; no site-wide PDF styling exists today to preserve,
       so none is invented here)
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a shortcode 'file' attribute to an absolute URL.
 * Purely-numeric input is treated as a Media Library attachment ID;
 * anything else is treated as a literal URL/path (absolute-ified
 * against the site URL if not already absolute). Returns '' if a
 * numeric ID doesn't resolve to a real attachment.
 */
function spp_pdf_resolve_url( string $file ): string {
    if ( $file === '' ) {
        return '';
    }

    if ( ctype_digit( $file ) ) {
        $url = wp_get_attachment_url( (int) $file );
        return $url ? $url : '';
    }

    if ( preg_match( '#^https?://#i', $file ) ) {
        return $file;
    }

    return get_site_url() . '/' . ltrim( $file, '/' );
}

function spp_pdf_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'file'   => '',
        'title'  => '',
        'height' => '800px',
    ), $atts, 'spp_pdf' );

    $pdf_url = spp_pdf_resolve_url( $atts['file'] );
    if ( $pdf_url === '' ) {
        return '<p>PDF not found.</p>';
    }

    $viewer_src = 'https://docs.google.com/viewer?url=' . urlencode( $pdf_url ) . '&embedded=true';

    ob_start();
    ?>
    <div class="spp-pdf-embed" data-pdf-title="<?php echo esc_attr( $atts['title'] ); ?>">
        <?php if ( $atts['title'] !== '' ) : ?>
        <h3 style="color:var(--spp-h3-color);font-size:var(--spp-h3-size);font-weight:var(--spp-h3-weight);margin:0 0 10px;">
            <?php echo esc_html( $atts['title'] ); ?>
        </h3>
        <?php endif; ?>
        <iframe
            src="<?php echo esc_url( $viewer_src ); ?>"
            title="<?php echo esc_attr( $atts['title'] ?: 'PDF document' ); ?>"
            width="100%"
            height="<?php echo esc_attr( $atts['height'] ); ?>"
            style="border:none;"
        ></iframe>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'spp_pdf', 'spp_pdf_shortcode' );
