<?php
/* =========================================================
   SPP Constitution V2 2024 (PDF embed)
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "SPP Constitution V2 2024" (CM260)

   PURPOSE:
   Embeds the static Constitution PDF in a Google Docs viewer
   iframe. No logic beyond building the URL.

   CALLED FROM (as of this migration):
     Via [cmruncode name='SPP Constitution V2 2024'] (CM260, now a
     transition shim around this function): the page "Constitution"
     (menu-reachable via Main). The page "SPP Constitution V2 2024"
     (orphan, not menu-linked) also calls it. Neither page touched
     by this migration -- both keep working via the shim.

   Changes from CM260: wrapped in a real function,
   spp_pdf_constitution(), instead of a bare top-level script.
   No other behavior change.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_pdf_constitution() {
    $pdf_url = get_site_url() . '/wp-content/uploads/2025/09/SPP-Constitution-V2-2024.pdf';
    echo '<iframe src="https://docs.google.com/viewer?url=' . urlencode( $pdf_url ) . '&embedded=true" width="100%" height="800px" style="border:none;"></iframe>';
}

add_shortcode( 'spp_pdf_constitution', function( $atts ) {
    ob_start();
    spp_pdf_constitution();
    return ob_get_clean();
} );
