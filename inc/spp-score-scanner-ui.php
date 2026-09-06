<?php
/* =========================================================
   Score Scanner UI
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Score Scanner" (CM268)

   PURPOSE:
   Front-end markup only -- the upload box, buttons, and result
   containers for the score-sheet scanner. Read the full CM268
   source fresh before migrating: it is genuinely just HTML/CSS,
   no hidden logic. The onclick handlers (sppScanScores(),
   sppClearScores()) are already defined in the already-tracked
   js/score-scanner.js, and the real AJAX backend
   (wp_ajax_spp_scan_scores, wp_ajax_spp_save_scores,
   wp_ajax_spp_clear_scores) already lives in tracked
   inc/score-scanner.php, which also unconditionally enqueues
   js/score-scanner.js site-wide via wp_enqueue_scripts. This
   migration only relocates the markup; no new dependency wiring
   needed.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Score Scanner'] (CM268, now a transition
     shim around this function): the page "Score Scanner"
     (menu-reachable via Main). Not touched by this migration --
     keeps working via the shim.

   Changes from CM268: wrapped in a real function,
   spp_score_scanner_ui(), instead of a bare top-level script.
   Dropped the dead "if (!session_status() == PHP_SESSION_ACTIVE)
   session_start()" guard -- always a no-op due to operator
   precedence (the same pattern already removed from every other
   migrated snippet), and $_SESSION was never read anywhere in
   this snippet regardless. No other behavior change -- identical
   markup, identical CSS.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_score_scanner_ui() {
    ?>
    <style>
    .spp-scan-wrap { max-width: 900px; margin: 0 auto; font-family: Arial, sans-serif; }
    .spp-scan-title { color: #3766AB; font-size: 1.4em; font-weight: bold; margin-bottom: 16px; }
    .spp-upload-box { background: #f8f9fa; border: 2px dashed #3766AB; border-radius: 8px; padding: 24px; text-align: center; margin-bottom: 20px; }
    .spp-upload-box input[type=file] { margin: 12px auto; display: block; }
    .spp-btn { background: #3766AB; color: white; border: none; border-radius: 4px; padding: 10px 24px; font-size: 14px; cursor: pointer; margin: 4px; }
    .spp-btn:hover { background: #2a4f8a; }
    .spp-btn-green { background: #27ae60; }
    .spp-btn-green:hover { background: #1e8449; }
    .spp-btn-red { background: #c0392b; }
    .spp-btn-red:hover { background: #a93226; }
    .spp-status { margin: 12px 0; padding: 10px; border-radius: 4px; font-size: 0.95em; }
    .spp-status-info { background: #d6eaf8; color: #1a5276; }
    .spp-status-success { background: #d5f5e3; color: #1e8449; }
    .spp-status-error { background: #fadbd8; color: #922b21; }
    .spp-review-table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 0.88em; }
    .spp-review-table th { background: #3766AB; color: white; padding: 6px 8px; text-align: left; }
    .spp-review-table td { padding: 5px 8px; border-bottom: 1px solid #eee; }
    .spp-review-table tr:nth-child(even) td { background: #f5f5f5; }
    .spp-group-header td { background: #2c3e50 !important; color: white; font-weight: bold; }
    .spp-sub-note { background: #fff3cd; color: #856404; font-style: italic; }
    .spp-score-input { width: 40px; text-align: center; border: 1px solid #ccc; border-radius: 3px; padding: 2px; }
    .spp-loading { display: none; text-align: center; padding: 20px; color: #3766AB; font-style: italic; }
    </style>

    <div class="spp-scan-wrap">
        <div class="spp-scan-title">Score Sheet Scanner</div>
        <div class="spp-upload-box">
            <p><strong>Upload completed score sheet</strong> (PDF or image, one or more pages)</p>
            <input type="file" id="spp-score-file" accept=".pdf,.jpg,.jpeg,.png" multiple>
            <br>
            <button class="spp-btn" onclick="sppScanScores()">Scan Scores</button>
            <button class="spp-btn spp-btn-red" onclick="sppClearScores()">Clear All Scores</button>
        </div>
        <div id="spp-loading" class="spp-loading">Scanning score sheet — please wait...</div>
        <div id="spp-status"></div>
        <div id="spp-review"></div>
    </div>
    <?php
}

add_shortcode( 'spp_score_scanner_ui', function( $atts ) {
    ob_start();
    spp_score_scanner_ui();
    return ob_get_clean();
} );
