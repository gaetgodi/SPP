<?php
/* =========================================================
   SPP Create Results (for Override)
   Version: 3.1.0
   Date: 2026-08-14
   Based on: Create Results for Override (Main Path) 2.0

   Changes from 3.0.0:
   - Design flaw: the v2.2.0 snapshot logic treated "a snapshot
     table exists" as equivalent to "this is a clean pre-event
     baseline". It isn't -- Step 9b ran (and wrote a snapshot)
     any time execution reached it, including a run that should
     never have happened because the guard failed to trip.
     Concrete case: usermeta_rank_pre_event_160 was created by
     an accidental run AFTER event 160 had already been
     correctly processed, capturing already-POST-event usermeta
     (Ernst Boxler: 3) instead of the true pre-event value (1).
     The v3.0.0 UI offered it as a valid restore point anyway;
     forcing from it would have re-corrupted the ladder with
     already-processed data instead of undoing anything.
   - Fix (defense in depth, not just detection): Step 9b now
     independently re-checks Results_{$Event} existence itself,
     right before writing anything, instead of trusting the
     guard's evaluation from ~180 lines earlier in the same
     execution. If Results_{$Event} already exists at this
     point, Step 9b aborts the run outright -- refuses to
     fabricate a snapshot from already-processed state, and
     refuses to proceed to Step 10's Master.Rank read. This
     means a second, independent checkpoint exists immediately
     before the actually-dangerous statement, so a guard failure
     anywhere upstream (for any reason, including causes outside
     this file) no longer silently produces a corrupted
     "restore point" as a side effect.
   - That independent check on its own would have broken a
     legitimate force=1 re-run -- Results_{$Event} isn't dropped
     until Step 11, so it still exists at Step 9b even when the
     guard correctly validated and restored. Step 9b therefore
     doesn't gate on existence alone: a $force_restore_completed
     flag, set only as a side effect of the guard actually
     performing the validated restore in this same execution
     (not copied from $force, which only means "was asked"),
     tells "shouldn't be here" apart from "supposed to be here".
   - Every snapshot Step 9b creates is now stamped with a table
     COMMENT (single-statement: CREATE TABLE ... COMMENT='...'
     AS SELECT ...) recording creation time and
     clean_baseline=1. spp_cr_snapshot_is_valid_baseline()
     checks for that marker via SHOW TABLE STATUS instead of
     just SHOW TABLES existence -- both the shortcode preview
     and the force-restore path use it now. A snapshot without
     the marker (anything from before this fix, or anything
     that somehow still gets created outside this guarded path)
     is treated as untrustworthy, same as an invalidated one.
     A COMMENT was chosen over a separate metadata table
     specifically because it travels with the table through
     spp-score-correction.php's invalidation RENAME TABLE --
     a side table keyed by event_id would silently desync the
     moment that rename happens.

   Changes from 2.2.0:
   - BREAKING: [spp_create_results] no longer executes the
     pipeline on render. Confirmed on production (Aug 14): the
     shortcode ran unconditionally on ANY GET to the page,
     including a Facebook link-preview crawler bot
     (meta-externalagent) and Divi Visual Builder preview
     requests (which render the live page to build the editor
     preview) -- neither is a deliberate "run this" action, yet
     both fully executed Steps 1-15 and rebuilt Results_{event}/
     Results. This is very likely how event 160 first got
     double-penalized, independent of the re-run-guard bug
     investigated separately. The v2.2.0 guard closed the "ran
     twice on purpose" case; this closes the much bigger "ran
     without anyone asking at all" case.
   - The shortcode now only renders read-only state (current
     event, whether Results_{$Event} already exists, whether a
     valid pre-run snapshot exists) plus a confirmation button.
     Execution moved to a new wp_ajax_spp_cr_run POST handler,
     gated server-side by spp_is_admin_or_editor() (not just a
     hidden button -- the AJAX handler re-checks independently
     of whatever the page shows) and a nonce. Same Preview/Apply
     pattern spp-score-correction.php already uses. A GET can no
     longer mutate anything.
   - Event lookup extracted into spp_cr_current_event(), shared
     by the shortcode preview and the run engine, so the two
     can never disagree about which event is "current".
   - The v2.2.0 re-run guard and pre-run usermeta snapshot logic
     are unchanged, just now only reachable through the POST
     path instead of on every page render.

   Changes from 2.1.3:
   - Re-run guard: running this shortcode twice for the same event
     compounds the no-show penalty, because Step 10 branches 2-4 read
     Master.Rank live, and this pipeline's own output eventually feeds
     back into Master via CM66 ("Copy Ranks to user profile") ->
     usermeta -> CM102 ("Create membership table"). A second run then
     applies the no-show penalty a second time on top of an already-
     decayed Master.Rank. This corrupted event 160 and required a
     manual rebuild from a Plesk dump. Now, if Results_{$Event}
     already exists, the shortcode aborts immediately with an
     explanation and the snapshot table name to restore from, instead
     of silently re-running.
   - Pre-run usermeta snapshot: before Step 10 (the first point that
     reads Master.Rank), copies {$prefix}usermeta rows where
     meta_key='Rank' into usermeta_rank_pre_event_{$Event}. If that
     table already exists it is left untouched -- the original
     pre-run snapshot is the one a restore needs, not whatever
     usermeta looks like on a later run. Row count is echoed to the
     shortcode output.
   - force=1 shortcode attribute: when the re-run guard trips,
     [spp_create_results force="1"] restores usermeta Rank from the
     event's snapshot table via a targeted UPDATE ... JOIN (not a
     delete-then-reinsert -- that would silently drop the Rank row
     for any member who joined after the snapshot was taken, since
     they'd have no row in the snapshot to restore from). Only rows
     present in the snapshot are touched; anyone not in it is left
     alone. If force=1 is passed but no snapshot table exists for
     this event -- including when spp-score-correction.php has
     invalidated it (renamed to
     usermeta_rank_pre_event_{$Event}_invalidated_{timestamp} after a
     score correction, since a correction can legitimately change
     Master/usermeta after the snapshot was taken) -- the run still
     aborts. There's no trustworthy restore point to fall back to if
     the re-run also goes wrong, so it refuses rather than running
     blind.

   Changes from 2.1.2:
   - Fixed event_id being written as NULL (instead of the current
     event) for members who were not scheduled/registered for the
     event at all but still received a rank-decay entry pulled from
     Master (Step 10's 4th UNION branch, and the matching branch in
     the Step 10b shadow UNION). These rows' RankCalc was always
     correct, but the NULL event_id meant they silently dropped out
     of any query/view filtered by event_id -- e.g. the Modify
     Overrides page (WPDA app_id 7, project 30) -- even though their
     rank visibly changed for that event. Confirmed on production:
     104 such rows in Results_160/Results had event_id NULL prior to
     this fix. Results_all was unaffected (a separate, out-of-repo
     process backfills its event_id independently), so only the
     per-event snapshot tables (Results_{event}, live Results) and
     anything reading them were impacted. Existing NULL-event_id rows
     in Results_160/Results/Results_all are not touched by this fix
     and would need a manual backfill if desired.

   Changes from 2.1.1:
   - RankCalc_Shadow added as a real column on Results_{event}
     and Results (Step 11, propagated automatically via Step 14's
     CREATE TABLE ... LIKE), pulled from the already-built shadow
     table via LEFT JOIN on user_id. Sits directly alongside
     RankCalc/RankOverride in the Modify Overrides page (WPDA
     app_id 7, project 30) so the two can be compared side by
     side while actually making override decisions, instead of
     only appearing in the separate end-of-run comparison report.
   - Shadow Ranking Comparison report: header row text was
     unreadable (black-on-dark-blue) -- Divi's theme CSS was
     overriding a plain inline color:#fff with no !important,
     same failure mode fixed elsewhere in the membership editor.
     Each <th> now sets color:#fff !important individually.

   Changes from 2.1.0:
   - Shadow dampening now prefers the computed, club-relative
     ClubRating (see inc/spp-club-rating.php) over self-reported
     Master.Rating when available, falling back to self-reported
     Rating only for players with no ClubRating yet. Self-report
     is noisy; ClubRating is derived from actual rank standing.

   Changes from 2.0:
   - Migrated from Code Manager snippet ("Create results for
     override") to a tracked theme file, wrapped in
     [spp_create_results] shortcode.
   - Fixed session_status() check: was "!session_status() ==
     PHP_SESSION_ACTIVE" (always false due to precedence, so
     session_start() never actually ran); now correctly checks
     "session_status() !== PHP_SESSION_ACTIVE".
   - Added SHADOW ratio-dampened rank calculation. Runs
     alongside the existing (unchanged) calculation, writes to
     a separate Results_shadow_{event} table, and prints a
     before/after comparison report. The live Results table,
     RankOverride, and everything downstream is completely
     untouched by this -- shadow mode only.

     Rationale: a fixed +/-3 score bonus and fixed placement
     deltas don't account for forced group mismatches (a
     strong player parked in a weak group racks up an
     easy bonus; a weak player forced into a strong group
     eats a bonus-penalty neither earned through genuine
     competition). The dampening ratio is based on each
     player's Master.Rating distance from their group's
     average Rating (excluding self) -- Rating is a real
     cardinal skill scale (Beginner...Professional), unlike
     Rank, which is a strict ordinal permutation with no
     density to correct for. ratio = exp(-distance / K),
     K tunable below. A player at their group's average
     Rating is unaffected (ratio ~1); a player 2+ full rating
     steps from their group average is heavily dampened, with
     a small residual effect rather than a hard cutoff.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_create_results', 'spp_create_results_shortcode' );

/* =========================================================
   EVENT LOOKUP HELPER (v3.0.0)
   Shared by the shortcode (read-only preview) and the run
   engine (execution), so the two can never determine a
   different $Event for the same request.
   ========================================================= */
function spp_cr_current_event() {
    global $wpdb;
    $prefix       = $wpdb->prefix;
    $results_prev = "Schedules";

    $Event = (int) $wpdb->get_var( "SELECT event_id FROM {$results_prev} WHERE group_id <> 99 LIMIT 1" );
    if ( ! $Event ) {
        $Event = (int) get_option( 'spp_current_event', 0 );  // fallback
    }
    $occ = $wpdb->get_row( $wpdb->prepare(
        "SELECT title, event_date, event_time FROM {$prefix}gl_event_occurrences WHERE id = %d",
        $Event
    ), ARRAY_A );
    $name = $occ
        ? $occ['title'] . ' ' . date( 'F d, Y', strtotime( $occ['event_date'] ) )
        : 'Event ' . $Event;

    return array( 'event' => $Event, 'name' => $name, 'occ' => $occ );
}

/* =========================================================
   SNAPSHOT VALIDITY HELPER (v3.1.0)
   -----------------------------------------------------------
   A snapshot table existing is not the same as it being a
   trustworthy pre-event baseline. usermeta_rank_pre_event_160
   was created by a run that should have been blocked by the
   guard, and captured already-POST-event usermeta instead of
   a true pre-event state -- see the v3.1.0 changelog above.
   Snapshots Step 9b creates now carry a table COMMENT marking
   them clean_baseline=1; this checks for that marker via
   SHOW TABLE STATUS rather than just checking existence. Any
   snapshot without the marker -- including every one that
   existed before this fix shipped -- is treated as not valid.
   ========================================================= */
function spp_cr_snapshot_is_valid_baseline( $snapshot_table ) {
    global $wpdb;
    $status = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$snapshot_table}'", ARRAY_A );
    if ( ! $status ) {
        return false; // doesn't exist at all
    }
    $comment = $status['Comment'] ?? '';
    return strpos( $comment, 'clean_baseline=1' ) !== false;
}

/* =========================================================
   SHORTCODE: read-only preview + confirmation form (v3.0.0)
   -----------------------------------------------------------
   Never executes the pipeline. Renders current state (which
   event, whether it has already been processed, whether a
   valid pre-run snapshot exists) and a button. Actual execution
   happens exclusively via the wp_ajax_spp_cr_run POST handler
   below, which independently re-checks the capability and a
   nonce server-side -- same Preview/Apply pattern
   spp-score-correction.php already uses.
   ========================================================= */
function spp_create_results_shortcode() {
    if ( ! spp_is_admin_or_editor() ) {
        return '<p class="gl-error">You do not have permission to access this tool.</p>';
    }

    global $wpdb;
    $info  = spp_cr_current_event();
    $Event = $info['event'];
    $name  = $info['name'];

    if ( ! $Event ) {
        return '<p>No event selected for result calculations.</p>';
    }

    $results_table_check = "Results_{$Event}";
    $snapshot_table       = "usermeta_rank_pre_event_{$Event}";
    $already_processed    = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$results_table_check}'" );
    $snapshot_exists      = $already_processed
        ? spp_cr_snapshot_is_valid_baseline( $snapshot_table )
        : false;

    ob_start();
    ?>
    <div class="spp-cr-wrap" style="font-family:Arial,sans-serif;font-size:14px;max-width:700px;">
        <p><strong>Event:</strong> <?php echo esc_html( $name ); ?> (ID <?php echo (int) $Event; ?>)</p>

        <?php if ( $already_processed ) : ?>
            <p style="color:#c0392b;"><strong><?php echo esc_html( $results_table_check ); ?></strong> already exists -- this event has already been processed. Running again would compound the no-show penalty unless usermeta is first restored from a pre-run snapshot.</p>
            <?php if ( $snapshot_exists ) : ?>
                <p>Pre-run snapshot <strong><?php echo esc_html( $snapshot_table ); ?></strong> is available and will be used to restore usermeta Rank before a forced re-run.</p>
                <button type="button" id="spp-cr-run-btn" class="spp-cr-btn" data-force="1" style="padding:10px 20px;border:none;border-radius:5px;font-size:0.95rem;cursor:pointer;color:#fff;background:#c0392b;">Force Re-run Event <?php echo (int) $Event; ?></button>
            <?php else : ?>
                <p style="color:#c0392b;">No valid snapshot exists for this event (it may have been invalidated by a score correction) -- a forced re-run is refused until usermeta is reviewed manually.</p>
            <?php endif; ?>
        <?php else : ?>
            <p>This event has not been processed yet.</p>
            <button type="button" id="spp-cr-run-btn" class="spp-cr-btn" data-force="0" style="padding:10px 20px;border:none;border-radius:5px;font-size:0.95rem;cursor:pointer;color:#fff;background:#3766AB;">Create Results for Event <?php echo (int) $Event; ?></button>
        <?php endif; ?>

        <div id="spp-cr-status" style="margin-top:12px;"></div>
        <div id="spp-cr-output" style="margin-top:12px;"></div>
    </div>

    <script>
    (function() {
        var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce   = '<?php echo esc_js( wp_create_nonce( 'spp_create_results' ) ); ?>';
        var btn     = document.getElementById('spp-cr-run-btn');
        if ( ! btn ) return;

        btn.addEventListener('click', function() {
            var force = btn.dataset.force === '1';
            var msg = force
                ? 'Force re-run this event? This restores usermeta Rank from the snapshot first, then recalculates results.'
                : 'Run results calculation for this event?';
            if ( ! confirm(msg) ) return;

            btn.disabled = true;
            document.getElementById('spp-cr-status').textContent = 'Running...';
            document.getElementById('spp-cr-output').innerHTML = '';

            var fd = new FormData();
            fd.append('action', 'spp_cr_run');
            fd.append('nonce', nonce);
            fd.append('force', force ? '1' : '0');

            fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    if (res.success) {
                        document.getElementById('spp-cr-status').textContent = 'Done.';
                        document.getElementById('spp-cr-output').innerHTML = res.data.html || '';
                    } else {
                        document.getElementById('spp-cr-status').textContent = 'Error: ' + (res.data || 'unknown error');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    document.getElementById('spp-cr-status').textContent = 'Request failed.';
                });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/* =========================================================
   AJAX: run the pipeline (v3.0.0)
   The only path that can execute spp_create_results_run().
   Capability + nonce both checked server-side -- the client-side
   button being present/hidden is never trusted as the real gate.
   ========================================================= */
add_action( 'wp_ajax_spp_cr_run', function() {
    if ( ! spp_is_admin_or_editor() ) wp_send_json_error( 'No permission' );
    if ( ! check_ajax_referer( 'spp_create_results', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce' );

    $force = isset( $_POST['force'] ) && $_POST['force'] === '1';

    ob_start();
    spp_create_results_run( $force );
    $html = ob_get_clean();

    wp_send_json_success( array( 'html' => $html ) );
});

/* =========================================================
   SHADOW RATIO HELPERS
   ========================================================= */

// Tunable: larger K = gentler dampening, smaller K = more aggressive.
// Starting value: 1.0 rating step.
define( 'SPP_CR_SHADOW_K', 1.0 );

function spp_cr_rating_numeric( $rating ) {
    $rating = trim( (string) $rating );
    if ( strcasecmp( $rating, 'Beginner' ) === 0 )     return 1.5;
    if ( strcasecmp( $rating, 'Professional' ) === 0 ) return 5.5;
    $n = (float) $rating;
    return $n > 0 ? $n : 3.0; // fallback matches RandomRanks' own default
}

function spp_cr_shadow_ratio( $distance ) {
    return exp( -$distance / SPP_CR_SHADOW_K );
}

function spp_create_results_run( $force = false ) {

if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

global $wpdb, $Event, $name;

$prefix       = $wpdb->prefix;
$results_prev = "Schedules";
$master       = "Master";
$f            = 1;
$noshow_np    = 1.6 * $f;  // NP: scheduled, did not play, notified
$noshow_ns    = 2.6 * $f;  // NS: no-show, no notification
$bonus        = 3;

// ── GL EVENTS: read event ID via the shared lookup helper (v3.0.0) ───────────
// Same helper the read-only shortcode preview uses, so the two can never
// disagree about which event is "current".
$info  = spp_cr_current_event();
$Event = $info['event'];
$name  = $info['name'];
$occ   = $info['occ'];
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($Event) || $Event == 0) {
    echo "No event selected for result calculations";
    return;
}

$prev = $Event;

// ── RE-RUN GUARD (v2.2.0) ──────────────────────────────────────────────────────
// Running this shortcode twice for the same event compounds the no-show
// penalty: Step 10 branches 2-4 read Master.Rank live, and this pipeline's
// own output eventually feeds back into Master via CM66 -> usermeta -> CM102.
// A second run then applies the no-show penalty a second time on top of an
// already-decayed Master.Rank -- this corrupted event 160. Results_{$Event}
// existing is our signal that this event has already been processed.
$snapshot_table = "usermeta_rank_pre_event_{$Event}";
$results_table_check = "Results_{$Event}";
$already_processed = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$results_table_check}'" );

// v3.1.0: proof-of-work flag for Step 9b's independent re-check below.
// Results_{$Event} is NOT dropped until Step 11 (~150 lines later), so on a
// legitimate force=1 re-run it still exists all the way through Step 9b --
// that alone can't be what Step 9b uses to decide "should I be here". This
// flag is set ONLY as a side effect of the guard actually completing a
// validated restore in *this* execution, right here, right now -- not
// copied from $force (which just means "was asked", not "was granted").
// Step 9b trusts that a real restore happened, not that one was requested.
$force_restore_completed = false;

if ( $already_processed ) {
    if ( $force ) {
        $snapshot_exists = spp_cr_snapshot_is_valid_baseline( $snapshot_table );
        if ( ! $snapshot_exists ) {
            echo "<span style='font-size:20px;color:#c0392b;'>force=1 was passed but no valid snapshot table ({$snapshot_table}) exists for event {$Event} -- refusing to run without a restore point. If a score correction ran since the last snapshot, it will have been renamed to {$snapshot_table}_invalidated_&lt;timestamp&gt; and usermeta must be reviewed manually before proceeding. Aborting.</span>";
            return;
        }
        // Targeted UPDATE ... JOIN, not a delete-then-reinsert: a global
        // delete would silently drop the Rank row for any member who
        // joined after the snapshot was taken (no row in the snapshot to
        // restore them from). This only touches users present in the
        // snapshot and leaves everyone else alone. wpdb->query() on an
        // UPDATE returns the number of rows actually CHANGED (not matched)
        // -- a useful signal that the restore did something.
        $restored = $wpdb->query(
            "UPDATE {$prefix}usermeta u
             JOIN {$snapshot_table} s ON s.user_id = u.user_id
             SET u.meta_value = s.meta_value
             WHERE u.meta_key = 'Rank'"
        );
        $force_restore_completed = true;
        echo "<span style='font-size:16px;color:#e67e22;'>force=1: restored {$restored} usermeta Rank row(s) that had changed since {$snapshot_table} was taken, before re-running event {$Event}.</span><br>";
    } else {
        echo "<span style='font-size:20px;color:#c0392b;'>Event {$Event} has already been processed -- {$results_table_check} already exists. Re-running would compound the no-show penalty on top of an already-decayed Master.Rank (see v2.2.0 changelog). To re-run intentionally, pass force=1 to [spp_create_results]; this restores usermeta Rank from {$snapshot_table} before proceeding, if that snapshot is still valid. Aborting.</span>";
        return;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Step 1: Validate enough scores entered (must be >= 80% present)
$numRows = $wpdb->get_var("SELECT COUNT(*) FROM {$results_prev} WHERE Score >= 0 AND group_id <> 99");
$noscore = $wpdb->get_var("SELECT COUNT(*) FROM {$results_prev} WHERE Score = 0 AND group_id <> 99");

if ($noscore > $numRows / 5) {
    echo "<span style='font-size:24px; color:#339966;'>{$noscore} results not entered -- cannot calculate new Ranks!</span>";
    return;
}
// Step 1b: Check for players with missing scores
$incomplete_players = $wpdb->get_results("
    SELECT s.user_id, s.group_id, s.first_name,
           (CASE WHEN s.Game1 IS NOT NULL THEN 1 ELSE 0 END
          + CASE WHEN s.Game2 IS NOT NULL THEN 1 ELSE 0 END
          + CASE WHEN s.Game3 IS NOT NULL THEN 1 ELSE 0 END
          + CASE WHEN s.Game4 IS NOT NULL THEN 1 ELSE 0 END
          + CASE WHEN s.Game5 IS NOT NULL THEN 1 ELSE 0 END) AS games_entered,
           g.group_size,
           (g.group_size - 1) AS games_expected
    FROM {$results_prev} s
    JOIN (
        SELECT group_id, COUNT(*) AS group_size
        FROM {$results_prev}
        WHERE group_id <> 99
        GROUP BY group_id
    ) g ON s.group_id = g.group_id
    WHERE s.group_id <> 99
    AND (s.Game1 IS NULL OR s.Game1 >= 0)
    HAVING games_entered < games_expected
", ARRAY_A);

if (!empty($incomplete_players)) {
    $groups_affected = array_unique(array_column($incomplete_players, 'group_id'));
    sort($groups_affected);
    $group_list = implode(', ', $groups_affected);
    $count = count($groups_affected);
    echo "<span style='font-size:24px; color:#c0392b;'>{$count} group(s) have incomplete scores (Group {$group_list}) — cannot calculate results!</span>";
    return;
}

// Step 2: Replace NULL game scores with 0
for ($x = 1; $x <= 5; $x++) {
    $wpdb->query("UPDATE {$results_prev} SET Game{$x} = 0 WHERE Game{$x} IS NULL");
}

// Step 3: Reset RankPrime to current Rank
$wpdb->query("UPDATE {$results_prev} SET Rank = RankPrime");

// Step 4: Determine score bonus thresholds
$maxscore = $wpdb->get_var("SELECT MAX(Score) FROM {$results_prev}");
if ($maxscore > 48) {
    $bonus_max = 56;  // Games to 60
    $bonus_min = 28;
} else {
    $bonus_max = 45;  // Games to 48
    $bonus_min = 20;
}

// Step 5: Build working copy of Schedules
$resultsnew = "results_new_{$prev}";
$resultstmp = "resultsfirstpass";

$wpdb->query("DROP TABLE IF EXISTS {$resultsnew}");
$wpdb->query("DROP TABLE IF EXISTS {$resultstmp}");
$wpdb->query("CREATE TABLE {$resultstmp} LIKE {$results_prev}");
$wpdb->query("INSERT INTO {$resultstmp} SELECT * FROM {$results_prev}");
$wpdb->query("ALTER TABLE {$resultstmp} ADD group_rank INT");
$wpdb->query("ALTER TABLE {$resultstmp} ADD newrank DEC(8,2)");
$wpdb->query("ALTER TABLE {$resultstmp} ADD newrank_shadow DEC(8,2)"); // shadow only, never read downstream

// Step 6: Build current ranks lookup for tie-breaking [user_id => rank]
$rank_rows    = $wpdb->get_results("SELECT user_id, Rank FROM {$resultstmp}", ARRAY_A);
$currentRanks = array_column($rank_rows, 'Rank', 'user_id');

// Step 6b (shadow only): build a per-player rating lookup for the ratio
// calc, preferring the computed, club-relative ClubRating (spp-club-
// rating.php) over the self-reported Master.Rating -- self-reported
// ratings are noisy (players routinely over/under-estimate their own
// level), while ClubRating is derived from actual rank standing within
// this club. Falls back to self-reported Rating only for players who
// don't have a ClubRating yet (e.g. brand new, no rank history at all).
$rating_rows     = $wpdb->get_results("SELECT user_id, Rating FROM {$master}", ARRAY_A);
$selfRatingByUid = array_column($rating_rows, 'Rating', 'user_id');
$club_rating_rows = $wpdb->get_results(
    "SELECT user_id, meta_value AS ClubRating FROM {$prefix}usermeta WHERE meta_key = 'ClubRating'", ARRAY_A
);
$clubRatingByUid = array_column($club_rating_rows, 'ClubRating', 'user_id');
$groupMembers = $wpdb->get_results("SELECT user_id, group_id FROM {$resultstmp} WHERE group_id <> 99", ARRAY_A);
$groupsByGid  = [];
foreach ($groupMembers as $gm) { $groupsByGid[$gm['group_id']][] = $gm['user_id']; }

function spp_cr_effective_rating( $uid, $clubRatingByUid, $selfRatingByUid ) {
    if ( isset( $clubRatingByUid[ $uid ] ) ) return (float) $clubRatingByUid[ $uid ];
    return spp_cr_rating_numeric( $selfRatingByUid[ $uid ] ?? 3.0 );
}

// Step 7: Calculate group rankings using current rank as tie-breaker
$groups      = $wpdb->get_var("SELECT MAX(group_id) FROM {$results_prev}");
$rankedUsers = [];

for ($gp = 1; $gp <= $groups; $gp++) {
    $rows   = $wpdb->get_results("SELECT user_id, Score FROM {$results_prev} WHERE group_id = {$gp} AND Game1 >= 0", ARRAY_A);
    $scores = [];
    foreach ($rows as $row) {
        $scores[$row['user_id']] = $row['Score'];
    }
    $rankedUsers[] = rankUsersWithTies($scores, $currentRanks);
}

// Step 8: Apply group ranks to working table
for ($gp = 0; $gp < $groups; $gp++) {
    foreach ($rankedUsers[$gp] as $user_id => $group_rank) {
        $wpdb->query("UPDATE {$resultstmp} SET group_rank = {$group_rank} WHERE user_id = {$user_id}");
    }
}

// Step 9: Calculate new rank for each player who played (live, unchanged)
// plus a parallel shadow rank using the Rating-distance dampening ratio.
for ($gp = 0; $gp < $groups; $gp++) {
    $gp1 = $gp + 1;
    foreach ($rankedUsers[$gp] as $user_id => $group_rank) {
        $maxrk = $wpdb->get_var("SELECT MAX(group_rank) FROM {$resultstmp} WHERE group_id = {$gp1}");
        $row   = $wpdb->get_results("SELECT Rank, group_rank, Score FROM {$resultstmp} WHERE user_id = {$user_id}", ARRAY_A);
        $rank  = $row[0]['Rank'];
        $score = $row[0]['Score'];
        $calc  = setCaclRank($f, $maxrk, $group_rank, $rank, $score, $bonus, $bonus_max, $bonus_min);
        $wpdb->query("UPDATE {$resultstmp} SET newrank = {$calc} WHERE user_id = {$user_id}");

        // Shadow: dampen the same delta by a ratio based on rating distance
        // from this player's group average (excluding self). Uses ClubRating
        // when available, self-reported Rating as fallback -- see Step 6b.
        $my_rating = spp_cr_effective_rating( $user_id, $clubRatingByUid, $selfRatingByUid );
        $peers     = array_diff( $groupsByGid[$gp1] ?? [], [$user_id] );
        if ( ! empty( $peers ) ) {
            $peer_ratings = array_map( fn($u) => spp_cr_effective_rating( $u, $clubRatingByUid, $selfRatingByUid ), $peers );
            $group_avg_rating = array_sum($peer_ratings) / count($peer_ratings);
        } else {
            $group_avg_rating = $my_rating; // solo/edge case -- no dampening possible, ratio will be 1
        }
        $distance = abs( $my_rating - $group_avg_rating );
        $ratio    = spp_cr_shadow_ratio( $distance );
        $delta    = $calc - $rank;
        $calc_shadow = $rank + ( $delta * $ratio );
        $wpdb->query("UPDATE {$resultstmp} SET newrank_shadow = {$calc_shadow} WHERE user_id = {$user_id}");
    }
}

// Step 9b: Pre-run usermeta snapshot (v2.2.0, hardened v3.1.0) -------------
// Captured here, immediately before Step 10 (the first place that reads
// Master.Rank), so it reflects usermeta Rank exactly as it stood before
// this run's eventual CM66 -> usermeta -> CM102 feedback loop can touch it.
//
// v3.1.0: re-checks Results_{$Event} existence independently, right here,
// rather than trusting the guard's evaluation from ~180 lines earlier in
// this same execution. This is deliberate defense-in-depth -- event 160
// was corrupted by a run where the guard failed to trip, and that run
// still reached this point and wrote a snapshot from ALREADY-POST-EVENT
// usermeta, not a true pre-event baseline. "We got here" must not be taken
// to mean "this is legitimately a first run"; it has to be verified again,
// and aborted rather than allowed to fabricate a snapshot (or reach Step
// 10's Master.Rank read) if the assumption doesn't hold.
//
// Results_{$Event} is NOT dropped until Step 11 (~150 lines below), so on
// a *legitimate* force=1 re-run it still exists right here too -- existence
// alone can't be the abort condition, or every valid forced re-run would
// be killed at this exact line. $force_restore_completed (set above, only
// as a side effect of the guard actually performing a validated restore in
// this same execution -- not merely $force being truthy) is what tells
// "shouldn't be here" apart from "supposed to be here, guard did its job".
$results_exist_at_snapshot_time = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$results_table_check}'" );
if ( $results_exist_at_snapshot_time && ! $force_restore_completed ) {
    echo "<span style='font-size:20px;color:#c0392b;'>Aborting: {$results_table_check} already exists, but execution reached the pre-run snapshot step without a completed force-restore in this run. This should have been caught by the guard above -- refusing to fabricate a snapshot from already-processed usermeta state, and refusing to proceed to Step 10.</span>";
    return;
}

// If a snapshot already exists for this event, it is NOT overwritten -- the
// original pre-run state is what a force=1 restore needs, not whatever
// usermeta looks like on a later run.
$snapshot_already_exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$snapshot_table}'" );
if ( ! $snapshot_already_exists ) {
    // Single statement -- COMMENT is set inline in the CREATE TABLE ... AS
    // SELECT itself, not a separate ALTER TABLE afterward. A two-statement
    // sequence could fail between CREATE and ALTER and leave an
    // uncommented (therefore untrusted, per spp_cr_snapshot_is_valid_
    // baseline()) snapshot sitting around; this can't land in that state.
    $snapshot_comment = "spp_cr_snapshot created_at=" . current_time( 'mysql' ) . " clean_baseline=1";
    $wpdb->query(
        "CREATE TABLE {$snapshot_table} COMMENT='{$snapshot_comment}' AS SELECT * FROM {$prefix}usermeta WHERE meta_key = 'Rank'"
    );
    $snapshot_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshot_table}" );
    echo "<span style='font-size:14px;color:#666;'>Pre-run snapshot created: {$snapshot_table} ({$snapshot_count} row(s)), marked clean_baseline.</span><br>";
} else {
    $snapshot_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshot_table}" );
    echo "<span style='font-size:14px;color:#666;'>Pre-run snapshot already exists: {$snapshot_table} ({$snapshot_count} row(s)) -- not overwritten.</span><br>";
}

// Step 10: Merge players + no-shows into ranked table (live, unchanged)
$wpdb->query("DROP TABLE IF EXISTS tmp1");
$wpdb->query("CREATE TABLE tmp1 SELECT * FROM (
    (SELECT
        Rank AS old_0_Rank, newrank, user_id, group_id, Score, event_id,
        CONCAT(first_name, ' ', last_name) AS display_name
     FROM {$resultstmp}
     WHERE Game1 >= 0)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_np} AS DECIMAL(6,2)) AS newrank,
        m.user_id, t.group_id, NULL AS Score, t.event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.Game1 = -1)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_ns} AS DECIMAL(6,2)) AS newrank,
        m.user_id, t.group_id, NULL AS Score, t.event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.Game1 = -2)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_np} AS DECIMAL(6,2)) AS newrank,
        m.user_id, NULL AS group_id, NULL AS Score, {$Event} AS event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     LEFT JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.user_id IS NULL AND m.Rank IS NOT NULL AND m.Rank <> 0)
) t ORDER BY CAST(t.newrank AS DECIMAL(8,2)) ASC, CAST(t.old_0_Rank AS UNSIGNED) ASC");

$wpdb->query("ALTER TABLE tmp1 ADD COLUMN Rank INT NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST");
$wpdb->query("DROP TABLE IF EXISTS {$resultsnew}");
$wpdb->query("RENAME TABLE tmp1 TO {$resultsnew}");

// Step 10b (shadow only): same pool-and-sort, using newrank_shadow instead,
// into a completely separate table. Nothing here touches {$resultsnew} or
// anything downstream of it.
$wpdb->query("DROP TABLE IF EXISTS tmp1_shadow");
$wpdb->query("CREATE TABLE tmp1_shadow SELECT * FROM (
    (SELECT
        Rank AS old_0_Rank, newrank_shadow AS newrank, user_id, group_id, Score, event_id,
        CONCAT(first_name, ' ', last_name) AS display_name
     FROM {$resultstmp}
     WHERE Game1 >= 0)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_np} AS DECIMAL(6,2)) AS newrank,
        m.user_id, t.group_id, NULL AS Score, t.event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.Game1 = -1)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_ns} AS DECIMAL(6,2)) AS newrank,
        m.user_id, t.group_id, NULL AS Score, t.event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.Game1 = -2)
    UNION
    (SELECT
        m.Rank AS old_0_Rank,
        CAST(m.Rank + {$noshow_np} AS DECIMAL(6,2)) AS newrank,
        m.user_id, NULL AS group_id, NULL AS Score, {$Event} AS event_id,
        CONCAT(m.first_name, ' ', m.last_name) AS display_name
     FROM {$master} m
     LEFT JOIN {$resultstmp} t ON m.user_id = t.user_id
     WHERE t.user_id IS NULL AND m.Rank IS NOT NULL AND m.Rank <> 0)
) t ORDER BY CAST(t.newrank AS DECIMAL(8,2)) ASC, CAST(t.old_0_Rank AS UNSIGNED) ASC");

$wpdb->query("ALTER TABLE tmp1_shadow ADD COLUMN Rank INT NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST");
$shadow_table = "Results_shadow_{$prev}";
$wpdb->query("DROP TABLE IF EXISTS {$shadow_table}");
$wpdb->query("RENAME TABLE tmp1_shadow TO {$shadow_table}");

// Step 11: Add override columns, plus the shadow comparison value pulled
// in from the shadow table built in Step 10b -- sits right next to
// RankCalc/RankOverride so the two can be compared directly in the
// Modify Overrides page, instead of only appearing in a separate report.
$wpdb->query("DROP TABLE IF EXISTS tmp1");
$wpdb->query("CREATE TABLE tmp1 SELECT * FROM (
    SELECT r.Rank, r.old_0_Rank AS RankPrev, r.newrank AS RankCalc, r.newrank AS RankOverride,
           sh.newrank AS RankCalc_Shadow,
           r.user_id, r.group_id, r.Score, r.event_id, r.display_name
    FROM {$resultsnew} r
    LEFT JOIN {$shadow_table} sh ON sh.user_id = r.user_id
    ORDER BY CAST(r.newrank AS DECIMAL(8,2)) ASC
) t1");

$table = "Results_{$prev}";
$wpdb->query("DROP TABLE IF EXISTS {$table}");
$wpdb->query("RENAME TABLE tmp1 TO {$table}");

// Step 12: Backup Schedules for this event
$event_pad     = str_pad($Event, 10, '0', STR_PAD_LEFT);
$bak_schedules = "{$results_prev}_{$event_pad}";
$thisevent     = "Schedules_{$event_pad}_\$";

$wpdb->query("DROP TABLE IF EXISTS {$bak_schedules}");
$wpdb->query("CREATE TABLE {$bak_schedules} AS SELECT * FROM {$results_prev}");
$wpdb->query("ALTER TABLE {$bak_schedules} DROP Score");

$wpdb->query("DROP TABLE IF EXISTS {$thisevent}");
$wpdb->query("CREATE TABLE {$thisevent} AS SELECT * FROM {$resultstmp}");

// ── GL EVENTS: also create Schedules_Scores_ table for scores drop-down ──────
$wpdb->query("DROP TABLE IF EXISTS Schedules_Scores_{$Event}");
$wpdb->query("CREATE TABLE Schedules_Scores_{$Event} SELECT * FROM Schedules");

// ── GL EVENTS: ensure event_date_lookup has this event ────────────────────────
$existing = $wpdb->get_var( $wpdb->prepare(
    "SELECT event_id FROM event_date_lookup WHERE event_id = %d", $Event
) );
if ( ! $existing && $occ ) {
    $event_time = ! empty( $occ['event_time'] ) ? $occ['event_time'] : '17:00:00';
    $wpdb->insert( 'event_date_lookup', array(
        'event_id'   => $Event,
        'event_date' => $occ['event_date'] . ' ' . $event_time,
    ) );
}
// ─────────────────────────────────────────────────────────────────────────────

// Step 13: Cleanup all previous event backup tables (keep current event only)
$tables_to_clean = $wpdb->get_results("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
    AND (
        (table_name LIKE 'Results\\_%'          AND table_name != 'Results_all' AND table_name != '{$table}' AND table_name != '{$shadow_table}')
        OR (table_name LIKE 'Schedules\\_%\\_\\$' AND table_name != '{$thisevent}')
        OR (table_name LIKE 'Schedules\\_00%'     AND table_name != 'Schedules_00{$event_pad}')
        OR (table_name LIKE 'results\\_new\\_%'   AND table_name != '{$resultsnew}')
        OR table_name = 'resultsfirstpass'
    )
", ARRAY_A);

foreach ($tables_to_clean as $tbl) {
    $wpdb->query("DROP TABLE IF EXISTS `{$tbl['table_name']}`");
}

// Step 14: Create Results table for moderator overrides (live, unchanged)
$wpdb->query("DROP TABLE IF EXISTS Results");
$wpdb->query("CREATE TABLE Results LIKE {$table}");
$wpdb->query("INSERT INTO Results SELECT * FROM {$table} ORDER BY RankOverride, Rank");
$wpdb->query("ALTER TABLE Results DROP PRIMARY KEY");
$wpdb->query("ALTER TABLE Results ADD PRIMARY KEY (user_id)");

// Step 15: Rotate preferred tables (live, unchanged)
$pref_temp   = "preferred_temp";
$pref_active = "preferred";
$wpdb->query("DROP TABLE IF EXISTS {$pref_active}");
$wpdb->query("RENAME TABLE {$pref_temp} TO {$pref_active}");

unset($GLOBALS['$Event']);

echo "<span style='font-size:18px; color:#339966;'>v2.1.0 -- Results calculated successfully for event {$Event}. Cleaned up " . count($tables_to_clean) . " old backup table(s).</span>";

// ── Shadow comparison report ──────────────────────────────────────────────────
$live_ranks   = $wpdb->get_results( "SELECT Rank, user_id, display_name FROM {$table}", ARRAY_A );
$shadow_ranks = $wpdb->get_results( "SELECT Rank, user_id FROM {$shadow_table}", ARRAY_A );
$shadow_by_uid = array_column( $shadow_ranks, 'Rank', 'user_id' );

$comparison = array();
foreach ( $live_ranks as $r ) {
    $uid = $r['user_id'];
    if ( ! isset( $shadow_by_uid[ $uid ] ) ) continue;
    $comparison[] = array(
        'name'      => $r['display_name'],
        'live_rank' => (int) $r['Rank'],
        'shadow_rank' => (int) $shadow_by_uid[ $uid ],
        'moved'     => (int) $r['Rank'] - (int) $shadow_by_uid[ $uid ],
    );
}
usort( $comparison, fn( $a, $b ) => abs( $b['moved'] ) <=> abs( $a['moved'] ) );

echo '<h3 style="margin-top:20px;">Shadow Ranking Comparison (Rating-distance dampened, K=' . SPP_CR_SHADOW_K . ')</h3>';
echo '<p style="font-size:13px;color:#666;">This does NOT affect Results, RankOverride, or anything live -- for review only. Sorted by biggest movers first.</p>';
echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px;">';
echo '<tr style="background:#3766AB;"><th style="color:#fff !important;">Player</th><th style="color:#fff !important;">Live Rank</th><th style="color:#fff !important;">Shadow Rank</th><th style="color:#fff !important;">Moved</th></tr>';
foreach ( $comparison as $c ) {
    $moved_display = $c['moved'] > 0 ? "+{$c['moved']}" : (string) $c['moved'];
    $row_style = $c['moved'] !== 0 ? 'background:#fff8e1;' : '';
    echo "<tr style=\"$row_style\"><td>" . esc_html( $c['name'] ) . "</td><td>{$c['live_rank']}</td><td>{$c['shadow_rank']}</td><td>{$moved_display}</td></tr>";
}
echo '</table>';

} // end spp_create_results_run()

/* =========================================================
   HELPER: rankUsersWithTies
   ========================================================= */
function rankUsersWithTies($scores, $currentRanks) {
    $entries = [];
    foreach ($scores as $user_id => $score) {
        $entries[] = [
            'user_id'      => $user_id,
            'score'        => $score,
            'current_rank' => isset($currentRanks[$user_id]) ? $currentRanks[$user_id] : 9999,
        ];
    }

    usort($entries, function($a, $b) {
        if ($b['score'] !== $a['score']) {
            return $b['score'] - $a['score'];
        }
        return $a['current_rank'] - $b['current_rank'];
    });

    $ranks = [];
    $rank  = 0;

    foreach ($entries as $entry) {
        $rank++;
        $ranks[$entry['user_id']] = $rank;
    }

    return $ranks;
}

/* =========================================================
   HELPER: setCaclRank
   ========================================================= */
function setCaclRank($f, $maxrk, $group_rank, $rank, $score, $bonus, $max, $min) {
    $win    = -3.0 * $f;
    $mid2nd = -1.8 * $f;
    $mid3rd =  0.3 * $f;
    $mid4th =  1.4 * $f;
    $lose   =  2.4 * $f;

    if ($score >= $max) {
        $win    = ($win    - $bonus) * $f;
        $mid2nd = ($mid2nd - $bonus) * $f;
        $mid3rd = ($mid3rd - $bonus) * $f;
        $mid4th = ($mid4th - $bonus) * $f;
        $lose   = ($lose   - $bonus) * $f;
    }
    if ($score <= $min) {
        $win    = ($win    + $bonus) * $f;
        $mid2nd = ($mid2nd + $bonus) * $f;
        $mid3rd = ($mid3rd + $bonus) * $f;
        $mid4th = ($mid4th + $bonus) * $f;
        $lose   = ($lose   + $bonus) * $f;
    }

    // No floor applied -- see v2.0 changelog. RankCalc/RankOverride can go
    // negative; the final ordinal Rank is assigned separately via
    // AUTO_INCREMENT after sorting, so this causes no display problem.
    switch ($maxrk) {
        case 1:
            return $rank + $win;
        case 2:
            switch ($group_rank) {
                case 1: return $rank + $win;
                case 2: return $rank + $lose;
            }
            break;
        case 3:
            switch ($group_rank) {
                case 1: return $rank + $win;
                case 2: return $rank + $mid2nd;
                case 3: return $rank + $lose;
            }
            break;
        case 4:
            switch ($group_rank) {
                case 1: return $rank + $win;
                case 2: return $rank + $mid2nd;
                case 3: return $rank + $mid3rd;
                case 4: return $rank + $lose;
            }
            break;
        case 5:
            switch ($group_rank) {
                case 1: return $rank + $win;
                case 2: return $rank + $mid2nd;
                case 3: return $rank + $mid3rd;
                case 4: return $rank + $mid4th;
                case 5: return $rank + $lose;
            }
            break;
        default:
            return 9999;
    }
}