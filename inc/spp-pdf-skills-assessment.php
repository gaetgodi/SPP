<?php
/* =========================================================
   Pickleball Canada Skills Self Assessment (PDF embed)
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Pickleball Canada Skills Self
   Assessment" (CM258)

   PURPOSE:
   Embeds the static Skills Self Assessment PDF in a Google Docs
   viewer iframe. No logic beyond building the URL.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Pickleball Canada Skills Self Assessment']
     (CM258, now a transition shim around this function): the page
     "Pickleball Canada Skills self-assessment" (menu-reachable via
     Main). Not touched by this migration -- keeps working via the
     shim.

   Changes from CM258: wrapped in a real function,
   spp_pdf_skills_assessment(), instead of a bare top-level script.
   No other behavior change.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_pdf_skills_assessment() {
    $pdf_url = get_site_url() . '/wp-content/uploads/2024/05/Pickleball-Canada-Skills-Self-Assessment-Chart.pdf';
    echo '<iframe src="https://docs.google.com/viewer?url=' . urlencode( $pdf_url ) . '&embedded=true" width="100%" height="800px" style="border:none;"></iframe>';
}
