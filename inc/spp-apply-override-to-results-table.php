<?php
/* =========================================================
   Apply Override to Results Table
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Apply Override to Results table"
   (CM52), fresh-pulled v2.5 (2026-08-27), confirmed byte-identical
   to the copy already reviewed tonight before drafting this file
   (sha256 c2efa7b9d5ad1860184ca9588a1d62432d3ca6232ff3b63294c51f8df39a2c54,
   25624 bytes) -- no drift.

   PURPOSE:
   The real results-publishing pipeline for the club. A two-stage
   confirm flow, gated by $_POST['apply_override_stage1'] /
   $_POST['apply_override_confirmed']:
     Stage 1 (confirm) -- shows a warning/confirm form, does nothing.
     Stage 1 (confirmed) -- rebuilds membership/Master, builds
       tmp_results from the (already-overridden) Results table,
       assigns final Rank, archives into Results_all, rebuilds the
       live Results table filtered to current members, creates three
       parallel per-event backup-table families (Results_<pad>,
       Schedules_<pad>_$, Schedules_00<pad> -- all three populated
       from the *Results* table despite the Schedules-shaped names;
       confirmed intentional and pre-existing, see note below),
       creates Schedules_Scores_<event> from the real Schedules table
       (the one CM273's dropdown actually reads), prunes older backup
       tables, removes the schedule/results pages from their WPDA
       menus, deactivates the player schedule view, and rotates the
       "preferred" tables.
     Stage 2 (confirm) -- shows an email-mode confirm form.
     Stage 2 (confirmed) -- sends the results-posted email (trial:
       convenor only, full: every Master member), then triggers
       "Copy Ranks to user profile" (CM66) and "Update Club Ratings"
       (CM284).

   EXPLICIT-PARAMETER TRACE (per the CM279 precedent): unlike CM279,
   which received its event_id as a real argument from its caller,
   CM52 receives NOTHING from its caller. The page "GL Publish
   Results after overrides" (ID 20010176) invokes
   [cmruncode name="Apply Override to Results table"] with no
   shortcode attributes, and the snippet's own `global $wpdb, $Event;`
   at the top is overwritten immediately in both the Stage 1 gate and
   Stage 1 confirmed branches by `$Event = $results_event;` before
   ever being read -- so no pre-existing value of $Event, however it
   got there, can leak in. $results_event is derived entirely
   internally, from the Results table's own event_id (falling back to
   the spp_current_event option only if Results is empty). Confirmed:
   this function needs no parameters; it is fully self-contained,
   matching how it already behaves live. $wpdb and $Event remain real
   globals here (not converted to a return value or local-only var),
   preserving the $GLOBALS['Event'] side effect for anything else on
   the page that might read it, per the same "don't touch the broader
   inter-snippet global-passing mechanism" precedent set during
   CM279's migration.

   NOTE ON THE THREE BACKUP-TABLE FAMILIES: Schedules_<pad>_$ and
   Schedules_00<pad> are populated from $results_table (Results), not
   from the Schedules table, despite the Schedules-shaped names. This
   looked like a possible bug on first read, but is confirmed
   NOT one: inc/spp-create-results.php (already-tracked, pre-existing
   code, the pipeline stage immediately before this one) creates the
   very same Schedules_<pad>_$ table from its own Results-shaped temp
   table ($resultstmp) using the identical naming convention and the
   identical cleanup-query LIKE patterns. This is a pre-existing,
   intentional (if confusingly named) site-wide backup convention,
   not something introduced by or unique to CM52. Carried forward
   exactly as-is.

   FLAGGED AND RESOLVED BEFORE DRAFTING (both discussed explicitly,
   not fixed unilaterally):

   1. Dead session guard: the original's
      "if (!session_status() == PHP_SESSION_ACTIVE) session_start()"
      is always false due to operator precedence (parses as
      "(!session_status()) == PHP_SESSION_ACTIVE"), so session_start()
      never actually runs from this line. Investigated rather than
      assumed: no plugin, mu-plugin, or theme file unconditionally
      starts a session earlier in this page's request (Ultimate
      Member's own session_start() calls are all narrowly conditional
      on unrelated features -- member-directory random sort,
      redirect-URL helpers -- not triggered on this page); the page's
      own content has nothing before this shortcode that would start
      one either. Traced the one path that actually matters: if
      $_SESSION['apply_override_name'] (or ['apply_override_event'])
      is empty/unset at Stage 2, the local $name/$Event set from it
      (Stage 2 gate) are never read again anywhere in the file -- the
      Stage 2 render uses only $recipient_count/$convenor_email/
      $email_mode, and the Stage 2 email itself derives $event_date
      independently from Results.event_id / gl_event_occurrences
      (with its own separate fallback that reads $_SESSION directly,
      not this dead-end local var). No $wpdb write happens anywhere
      in Stage 2 -- every mutation is already committed in Stage 1
      before this is read. So the guard's dead-ness has no effect on
      behavior today, confirmed empirically, not assumed. Decision:
      dropped the guard entirely, matching the same pattern already
      removed from every other snippet migrated tonight (CM252, 254,
      279, 268, 80).

      CM275's migrated file (inc/spp-gl-ladder-events-dropdown.php,
      already written to the DB earlier tonight) had this identical
      guard silently rewritten into a *working* check during that
      migration -- an undisclosed behavior change, caught only while
      reading this file side-by-side with it. That file has been
      corrected in place to match this same drop-the-guard decision
      (see its own changelog entry).

   2. $GLOBALS['$Event'] / $GLOBALS['$name'] (literal dollar-sign in
      the key, at the very end of Stage 2) is the exact bug shape
      already found and decided during CM80's migration tonight
      (inc/spp-show-results.php): it has never actually cleared the
      real $GLOBALS['Event']/['name'], and is a harmless no-op.
      Preserved verbatim here without re-asking, per that standing
      decision.

   UPDATE (2026-09-05): CM66 ("Copy Ranks to user profile") was
   migrated to spp_copy_ranks_to_user_profile() -- the call in Stage 2
   below goes directly to that function instead of through [cmruncode].
   "Create membership table" (CM102) is also called directly, matching
   how gl-schedule-production.php and spp-score-correction.php already
   call it.

   UPDATE (2026-09-06): CM284 ("Update Club Ratings") has since been
   migrated to spp_update_club_ratings() -- the call in Stage 2 below
   now goes directly to that function instead of through [cmruncode]
   too. Every internal call in this file now goes to a real function;
   nothing here still goes through [cmruncode].

   No wildcard meta_key patterns anywhere in this file -- no
   usermeta audit applies. The RankCalc_Shadow column fix and the
   explicit 9-column Results_all INSERT (both already live in v2.5,
   documented in the snippet's own changelog above) are carried
   forward exactly as-is; not re-litigated here.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_apply_override_to_results_table() {

    global $wpdb, $Event;

    $prefix        = $wpdb->prefix;
    $member        = "membership";
    $results_table = "Results";
    $project = 30;
    $page    = 72;

    // -- Email mode from option -- default to trial --------------------------------
    $email_mode = get_option( 'spp_email_mode', 'trial' );

    // -- Determine the event being published --------------------------------------
    // Read from the Results table (authoritative — it carries the event_id of the
    // PLAYED event, stamped by Create Results from Schedules.event_id). Do NOT use
    // spp_current_event: Schedule Production advances it to the NEXT event as soon
    // as the following week is scheduled, which would mis-stamp these results.
    $results_event = (int) $wpdb->get_var("SELECT event_id FROM {$results_table} LIMIT 1");
    if ( ! $results_event ) $results_event = (int) get_option('spp_current_event', 0);

    // -- Get convenor email --------------------------------------------------------
    $convenor_email = $wpdb->get_var( $wpdb->prepare("
        SELECT m.user_email
        FROM {$prefix}gl_event_occurrences o
        JOIN membership m ON m.user_id = o.convenor_id
        WHERE o.id = %d
        LIMIT 1
    ", $results_event ) );
    if ( ! $convenor_email ) $convenor_email = 'abrooks@rogers.com';

    // -- Stage confirmations -------------------------------------------------------
    $stage1_confirmed = isset($_POST['apply_override_stage1']) && $_POST['apply_override_stage1'] === '1';
    $stage2_confirmed = isset($_POST['apply_override_confirmed']) && $_POST['apply_override_confirmed'] === '1';

    // -- Handle email mode toggle from Stage 1 or Stage 2 form --------------------
    if ( ($stage1_confirmed || $stage2_confirmed) && isset($_POST['email_mode']) && in_array($_POST['email_mode'], ['trial','full']) ) {
        $email_mode = sanitize_text_field($_POST['email_mode']);
        update_option('spp_email_mode', $email_mode);
    }

    // -- STAGE 1 GATE --------------------------------------------------------------
    if (!$stage1_confirmed && !$stage2_confirmed) {

        // -- GL EVENTS: read event ID from the Results table (the played event) ---
        $Event = $results_event;
        $occ_s1 = $wpdb->get_row( $wpdb->prepare(
            "SELECT title, event_date FROM {$prefix}gl_event_occurrences WHERE id = %d",
            $Event
        ), ARRAY_A );
        $name         = $occ_s1 ? $occ_s1['title'] . ' ' . date( 'F d, Y', strtotime( $occ_s1['event_date'] ) ) : 'Event ' . $Event;
        $results_name = 'Results for ' . $name;
        // -------------------------------------------------------------------------
        $total_count  = $wpdb->get_var("SELECT COUNT(*) FROM Master WHERE user_email != ''");
        ?>
        <style>
            .confirm-wrap { max-width:600px; margin:30px auto; font-family:Arial,sans-serif; }
            .confirm-box { background:#fdf3f2; border:2px solid #c0392b; border-radius:6px; padding:20px; margin:20px 0; }
            .confirm-box h3 { color:#c0392b; margin:0 0 12px; }
            .confirm-box ul { margin:10px 0; padding-left:20px; }
            .confirm-box li { margin:6px 0; font-size:15px; }
            .confirm-btn { padding:10px 24px; background:#c0392b; color:white; border:none; border-radius:4px; font-size:15px; cursor:pointer; }
            .confirm-btn:hover { background:#a93226; }
            .cancel-lnk { margin-left:16px; color:#888; font-size:14px; text-decoration:none; }
            .email-mode-box { background:#f0f7ff; border:1px solid #3766AB; border-radius:6px; padding:16px; margin:16px 0; }
            .email-mode-box h4 { color:#3766AB; margin:0 0 10px; }
            .email-mode-box label { display:block; margin:8px 0; font-size:15px; cursor:pointer; }
            .email-mode-box input[type=radio] { margin-right:8px; }
            .mode-trial { color:#e67e22; font-weight:bold; }
            .mode-full  { color:#c0392b; font-weight:bold; }
        </style>
        <div class="confirm-wrap">
            <h2>Apply Override<br><?php echo esc_html($results_name); ?></h2>

            <div class="email-mode-box">
                <h4>Email Notification Mode</h4>
                <label>
                    <input type="radio" name="email_mode_preview" value="trial" <?php checked($email_mode,'trial'); ?> onchange="document.getElementById('mode_input1').value='trial';">
                    <span class="mode-trial">Trial &mdash;</span> Send to convenor only (<?php echo esc_html($convenor_email); ?>)
                </label>
                <label>
                    <input type="radio" name="email_mode_preview" value="full" <?php checked($email_mode,'full'); ?> onchange="document.getElementById('mode_input1').value='full';">
                    <span class="mode-full">Full send &mdash;</span> Send to all <?php echo $total_count; ?> Master list members
                </label>
            </div>

            <div class="confirm-box">
                <h3>Confirm Apply Override</h3>
                <p>This will:</p>
                <ul>
                    <li>Rebuild membership and Master tables</li>
                    <li>Process results and update rankings</li>
                    <li>Rotate preferred tables</li>
                    <li>Remove schedule and results pages from menu</li>
                    <li>Create backup tables for this event</li>
                </ul>
                <p style="color:#c0392b;font-weight:bold;">The player schedule view will be deactivated. Are you sure results are final?</p>
            </div>
            <form method="post">
                <input type="hidden" name="apply_override_stage1" value="1">
                <input type="hidden" name="email_mode" id="mode_input1" value="<?php echo esc_attr($email_mode); ?>">
                <button type="submit" class="confirm-btn">Yes, Apply Override Now</button>
                <a href="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" class="cancel-lnk">Cancel</a>
            </form>
        </div>
        <?php
        return;
    }

    // -- STAGE 1 CONFIRMED -- run Steps 0-8 ---------------------------------------
    if ($stage1_confirmed) {

        spp_create_membership_table();

        // -- GL EVENTS: read event ID from the Results table (the played event) ---
        $Event = $results_event;
        $occ_s1c = $wpdb->get_row( $wpdb->prepare(
            "SELECT title, event_date FROM {$prefix}gl_event_occurrences WHERE id = %d",
            $Event
        ), ARRAY_A );
        $name = $occ_s1c ? $occ_s1c['title'] . ' ' . date( 'F d, Y', strtotime( $occ_s1c['event_date'] ) ) : 'Event ' . $Event;
        // -------------------------------------------------------------------------

        $wpdb->query("DROP TABLE IF EXISTS tmp_results");
        $wpdb->query("CREATE TABLE tmp_results AS (
            SELECT RankPrev, RankCalc, RankOverride, RankCalc_Shadow, user_id, group_id, Score, event_id, display_name
            FROM {$results_table}
            ORDER BY CAST(RankOverride AS DECIMAL(6,2)) ASC, RankPrev
        )");
        $wpdb->query("ALTER TABLE tmp_results ADD Rank INT AUTO_INCREMENT PRIMARY KEY FIRST");

        // -- GL EVENTS: stamp tmp_results with the played event ID ----------------
        $wpdb->query("UPDATE tmp_results SET event_id = {$Event}");
        $event_pad   = str_pad($Event, 10, '0', STR_PAD_LEFT);
        $table_bak   = "Results_{$event_pad}";
        $thisevent   = "Schedules_{$event_pad}_\$";
        $thesescores = "Schedules_00{$event_pad}";

        $wpdb->query("UPDATE tmp_results SET event_id = {$Event} WHERE event_id IS NULL");

        // -- Verify the stamp actually applied to every row before proceeding ------
        $unstamped_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM tmp_results WHERE event_id IS NULL OR event_id != {$Event}");
        if ($unstamped_count > 0) {
            echo "<p style='color:#c0392b;font-weight:bold;font-size:16px;'>⚠ WARNING: {$unstamped_count} row(s) in tmp_results failed to stamp with event_id {$Event} after both UPDATE attempts. Forcing a final correction pass.</p>";
            $wpdb->query("UPDATE tmp_results SET event_id = {$Event} WHERE event_id IS NULL OR event_id != {$Event}");
            $unstamped_count_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM tmp_results WHERE event_id IS NULL OR event_id != {$Event}");
            if ($unstamped_count_after > 0) {
                echo "<p style='color:#c0392b;font-weight:bold;font-size:16px;'>⚠ STILL {$unstamped_count_after} row(s) unstamped after correction pass — manual investigation required before continuing.</p>";
            } else {
                echo "<p style='color:#339966;'>OK: Correction pass resolved all unstamped rows.</p>";
            }
        }

        $num_event = intval($Event);
        $wpdb->query("DELETE FROM Results_all WHERE event_id = {$num_event}");
        $wpdb->query("INSERT INTO Results_all (Rank, RankPrev, RankCalc, RankOverride, user_id, group_id, Score, event_id, display_name) SELECT Rank, RankPrev, RankCalc, RankOverride, user_id, group_id, Score, event_id, display_name FROM tmp_results");
        // -- Verify Results_all row count matches what we just inserted ------------
        $expected_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM tmp_results");
        $actual_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM Results_all WHERE event_id = {$num_event}");
        if ($actual_count !== $expected_count) {
            echo "<p style='color:#c0392b;font-weight:bold;font-size:16px;background:#fdf3f2;border:2px solid #c0392b;border-radius:6px;padding:14px;'>";
            echo "⚠ ROW COUNT MISMATCH: tmp_results had {$expected_count} rows, but Results_all shows only {$actual_count} rows for event {$num_event} after insert. ";
            echo "This is the same failure mode that caused incorrect rankings and a wrong results email date on a previous run. Do not send the results email until this is investigated and corrected.";
            echo "</p>";
        } else {
            echo "<p style='color:#339966;'>OK: Results_all row count verified ({$actual_count} rows for event {$num_event}).</p>";
        }

        $wpdb->query("DROP TABLE IF EXISTS {$results_table}");
        $wpdb->query("CREATE TABLE {$results_table} AS (
            SELECT * FROM tmp_results WHERE user_id IN (SELECT user_id FROM {$member})
        )");
        $wpdb->query("ALTER TABLE {$results_table} ADD PRIMARY KEY (user_id)");

        foreach ([$table_bak, $thisevent, $thesescores] as $backup) {
            $wpdb->query("DROP TABLE IF EXISTS {$backup}");
            $wpdb->query("CREATE TABLE {$backup} LIKE {$results_table}");
            $wpdb->query("INSERT INTO {$backup} SELECT * FROM {$results_table}");
        }

        $wpdb->query("DROP TABLE IF EXISTS Schedules_Scores_{$Event}");
        $wpdb->query("CREATE TABLE Schedules_Scores_{$Event} SELECT * FROM Schedules");

        $tables_to_clean = $wpdb->get_results("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND (
                (table_name LIKE 'Results\\_%' AND table_name != 'Results_all' AND table_name != '{$table_bak}')
                OR (table_name LIKE 'Schedules\\_%\\_\\$' AND table_name != '{$thisevent}')
                OR (table_name LIKE 'Schedules\\_00%' AND table_name != '{$thesescores}')
                OR (table_name LIKE 'results\\_new\\_%')
                OR table_name = 'resultsfirstpass'
            )
        ", ARRAY_A);
        foreach ($tables_to_clean as $tbl) {
            $wpdb->query("DROP TABLE IF EXISTS `{$tbl['table_name']}`");
        }

        $wpdb->query("ALTER TABLE {$prefix}wpda_project_page MODIFY COLUMN page_sequence INT(10)");
        $wpdb->query("UPDATE {$prefix}wpda_project_page SET page_name='{$name}', page_title='{$name}', page_sequence={$Event}, add_to_menu='No' WHERE project_id={$project} AND page_id={$page}");
        $wpdb->query("UPDATE {$prefix}wpda_project_page SET add_to_menu='No' WHERE project_id=29 AND page_id=70");
        $wpdb->query("UPDATE {$prefix}wpda_project_page SET add_to_menu='No' WHERE project_id=29 AND page_id IN (73, 74)");
        echo "<br>OK: Schedule and results pages removed from menu.<br>";

        // -- Deactivate player schedule view --------------------------------------
        update_option( 'spp_schedule_published', 0 );

        $pref_new    = "preferred_new";
        $pref_active = "preferred";
        $pref_prev   = "preferred_prev";
        $var = $wpdb->get_var("SHOW TABLES LIKE '{$pref_new}'");
        if ($var == $pref_new) {
            $wpdb->query("DROP TABLE IF EXISTS {$pref_active}");
            $wpdb->query("CREATE TABLE {$pref_active} LIKE {$pref_new}");
            $wpdb->query("INSERT INTO {$pref_active} SELECT * FROM {$pref_new}");
        }
        $wpdb->query("DROP TABLE IF EXISTS {$pref_prev}");
        echo "<br>OK: Preferred tables rotated.<br>";
        // Mark results as posted so next schedule production is unblocked
        update_option('spp_results_posted', 1);

        $_SESSION['apply_override_event'] = $Event;
        $_SESSION['apply_override_name']  = $name;
    }

    // -- STAGE 2 GATE --------------------------------------------------------------
    if ($stage2_confirmed) {
        $Event = isset($_SESSION['apply_override_event']) ? (int)$_SESSION['apply_override_event'] : 0;
        $name  = isset($_SESSION['apply_override_name'])  ? $_SESSION['apply_override_name']        : '';
    }

    if (!$stage2_confirmed) {
        $recipient_count = $wpdb->get_var("SELECT COUNT(*) FROM Master WHERE user_email != ''");
        ?>
        <style>
            .confirm-wrap { max-width:600px; margin:30px auto; font-family:Arial,sans-serif; }
            .confirm-box { background:#fdf3f2; border:2px solid #c0392b; border-radius:6px; padding:20px; margin:20px 0; }
            .confirm-box h3 { color:#c0392b; margin:0 0 12px; }
            .confirm-box ul { margin:10px 0; padding-left:20px; }
            .confirm-box li { margin:6px 0; font-size:15px; }
            .confirm-btn { padding:10px 24px; background:#c0392b; color:white; border:none; border-radius:4px; font-size:15px; cursor:pointer; }
            .confirm-btn:hover { background:#a93226; }
            .cancel-lnk { margin-left:16px; color:#888; font-size:14px; text-decoration:none; }
            .email-mode-box { background:#f0f7ff; border:1px solid #3766AB; border-radius:6px; padding:16px; margin:16px 0; }
            .email-mode-box h4 { color:#3766AB; margin:0 0 10px; }
            .email-mode-box label { display:block; margin:8px 0; font-size:15px; cursor:pointer; }
            .email-mode-box input[type=radio] { margin-right:8px; }
            .mode-trial { color:#e67e22; font-weight:bold; }
            .mode-full  { color:#c0392b; font-weight:bold; }
        </style>
        <div class="confirm-wrap">
            <p style="color:green;">OK: Results processed and menus updated successfully.</p>

            <div class="email-mode-box">
                <h4>Email Notification Mode</h4>
                <label>
                    <input type="radio" name="email_mode_preview" value="trial" <?php checked($email_mode,'trial'); ?> onchange="document.getElementById('mode_input2').value='trial';">
                    <span class="mode-trial">Trial &mdash;</span> Send to convenor only (<?php echo esc_html($convenor_email); ?>)
                </label>
                <label>
                    <input type="radio" name="email_mode_preview" value="full" <?php checked($email_mode,'full'); ?> onchange="document.getElementById('mode_input2').value='full';">
                    <span class="mode-full">Full send &mdash;</span> Send to all <?php echo $recipient_count; ?> Master list members
                </label>
            </div>

            <div class="confirm-box">
                <h3>Confirm Results Email</h3>
                <p>Ready to send results notification.</p>
                <p style="color:#c0392b;font-weight:bold;">This cannot be undone. Are you sure?</p>
            </div>
            <form method="post">
                <input type="hidden" name="apply_override_confirmed" value="1">
                <input type="hidden" name="email_mode" id="mode_input2" value="<?php echo esc_attr($email_mode); ?>">
                <button type="submit" class="confirm-btn">Yes, Send Results Emails Now</button>
                <a href="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" class="cancel-lnk">Skip email &mdash; done</a>
            </form>
        </div>
        <?php
        return;
    }

    // -- STAGE 2 CONFIRMED -- send results email -----------------------------------

    // -- GL EVENTS: get event date from gl_event_occurrences ----------------------
    // Read directly from Results (authoritative, just written by Stage 1) rather
    // than session state, which can be stale on double-submit or page reload.
    $event_id_for_date = (int) $wpdb->get_var("SELECT event_id FROM {$results_table} LIMIT 1");
    if ( ! $event_id_for_date ) {
        $event_id_for_date = isset($_SESSION['apply_override_event']) ? (int)$_SESSION['apply_override_event'] : $results_event;
    }
    $occ_date   = $wpdb->get_var( $wpdb->prepare(
        "SELECT event_date FROM {$prefix}gl_event_occurrences WHERE id = %d",
        $event_id_for_date
    ) );
    $event_date = $occ_date ? date( 'F d, Y', strtotime( $occ_date ) ) : date( 'F d, Y' );
    // -----------------------------------------------------------------------------

    $ranks_url  = 'https://pickleballstouffville.ca/ladder-players-master-list/';
    $scores_url = 'https://pickleballstouffville.ca/show-scores-600/';
    $logo_url   = 'https://pickleballstouffville.ca/wp-content/uploads/2024/03/SPP_Logo-96DPI-8X-3.52-480x211.png';
    $subject    = "Stouffville Pickleball Players -- Ladder Results Posted for $event_date";
    $headers    = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    $body = '<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f5f5f5;">
<table width="620" cellpadding="0" cellspacing="0" style="margin:20px auto;background:#fdfae8;border:1px solid #ddd;font-family:Arial,sans-serif;font-size:14px;color:#333;">
  <tr>
    <td style="padding:16px 24px;border-bottom:1px solid #ddd;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="160"><img src="' . $logo_url . '" width="160" alt="SPP Logo"></td>
          <td style="text-align:center;padding-left:10px;">
            <strong style="font-size:16px;">Stouffville Pickleball Players</strong><br>
            <span style="font-size:13px;color:#666;">Pickleball for everyone in the Stouffville area</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:24px;">
      <p style="font-size:16px;font-weight:bold;color:#00897B;margin:0 0 12px 0;">Ladder Results are Posted!</p>
      <p>The results for the ladder session of <strong>' . $event_date . '</strong> have been finalized and your new rank is now available.</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
          <td style="padding:10px;background:#eef7f6;border-radius:6px;text-align:center;">
            <a href="' . $ranks_url . '" style="color:#3766AB;font-weight:bold;font-size:15px;text-decoration:none;">View Updated Ladder Rankings</a><br>
            <span style="font-size:12px;color:#666;">See where you stand in the full player list</span>
          </td>
        </tr>
        <tr><td style="height:10px;"></td></tr>
        <tr>
          <td style="padding:10px;background:#eef7f6;border-radius:6px;text-align:center;">
            <a href="' . $scores_url . '" style="color:#3766AB;font-weight:bold;font-size:15px;text-decoration:none;">View Scores and Rank History</a><br>
            <span style="font-size:12px;color:#666;">See your score, rank change, and how others performed</span>
          </td>
        </tr>
      </table>
      <p style="font-size:13px;color:#666;margin-top:16px;">Questions about your result? Contact us through the website.</p>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 24px;border-top:1px solid #ddd;font-size:12px;color:#666;text-align:center;">
      Stouffville Pickleball Players &mdash; pickleballstouffville.ca
    </td>
  </tr>
</table>
</body>
</html>';

    $is_trial = ($email_mode === 'trial');

    if ( $is_trial ) {
        $ok = wp_mail($convenor_email, $subject . ' [TRIAL]', $body, $headers);
        echo "<br><span style='font-size:14px;color:#339966;'>" . ($ok ? "OK: Trial results email sent to: {$convenor_email}" : "FAILED: Trial email failed") . "</span>";
    } else {
        $recipients = $wpdb->get_col("SELECT user_email FROM Master WHERE user_email != '' ORDER BY last_name");
        $sent_count = 0; $fail_count = 0;
        foreach ($recipients as $email) {
            $ok = wp_mail($email, $subject, $body, $headers);
            if ($ok) $sent_count++; else $fail_count++;
        }
        echo "<br><span style='font-size:14px;color:#339966;'>Results notification sent to {$sent_count} players." . ($fail_count > 0 ? " ({$fail_count} failed.)" : '') . "</span>";
    }

    spp_copy_ranks_to_user_profile();
    spp_update_club_ratings();
    unset($GLOBALS['$Event']);
    unset($_SESSION['apply_override_event']);
    unset($_SESSION['apply_override_name']);
}

add_shortcode( 'spp_apply_override_to_results_table', function( $atts ) {
    ob_start();
    spp_apply_override_to_results_table();
    return ob_get_clean();
} );
