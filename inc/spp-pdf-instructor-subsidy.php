<?php
/* =========================================================
   Instructor Certification Subsidy (PDF embed)
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Instructor Certification Subsidy"
   (CM261)

   PURPOSE:
   Embeds the static Instructor Certification Subsidy PDF in a
   Google Docs viewer iframe. No logic beyond building the URL.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Instructor Certification Subsidy']
     (CM261, now a transition shim around this function): the page
     "Instructor Certification Subsidy" (menu-reachable via Main).
     Not touched by this migration -- keeps working via the shim.

   Changes from CM261: wrapped in a real function,
   spp_pdf_instructor_subsidy(), instead of a bare top-level script.
   No other behavior change.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_pdf_instructor_subsidy() {
    $pdf_url = get_site_url() . '/wp-content/uploads/2026/04/SPP-Instructor-Subsidy-v3.pdf';
    echo '<iframe src="https://docs.google.com/viewer?url=' . urlencode( $pdf_url ) . '&embedded=true" width="100%" height="800px" style="border:none;"></iframe>';
}

add_shortcode( 'spp_pdf_instructor_subsidy', function( $atts ) {
    ob_start();
    spp_pdf_instructor_subsidy();
    return ob_get_clean();
} );
