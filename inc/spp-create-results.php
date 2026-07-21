<?php
/* =========================================================
   SPP Create Results (for Override)
   Version: 2.1.2
   Date: 2026-07-21
   Based on: Create Results for Override (Main Path) 2.0

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

function spp_create_results_shortcode() {
    ob_start();
    spp_create_results_run();
    return ob_get_clean();
}

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

function spp_create_results_run() {

if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

global $wpdb, $Event, $name;

$prefix       = $wpdb->prefix;
$results_prev = "Schedules";
$master       = "Master";
$f            = 1;
$noshow_np    = 1.6 * $f;  // NP: scheduled, did not play, notified
$noshow_ns    = 2.6 * $f;  // NS: no-show, no notification
$bonus        = 3;

// ── GL EVENTS: read event ID from the Schedules table itself ─────────────────
$Event = (int) $wpdb->get_var( "SELECT event_id FROM {$results_prev} WHERE group_id <> 99 LIMIT 1" );
if ( ! $Event ) {
    $Event = (int) get_option( 'spp_current_event', 0 );  // fallback
}
$occ   = $wpdb->get_row( $wpdb->prepare(
    "SELECT title, event_date, event_time FROM {$prefix}gl_event_occurrences WHERE id = %d",
    $Event
), ARRAY_A );
$name  = $occ
    ? $occ['title'] . ' ' . date( 'F d, Y', strtotime( $occ['event_date'] ) )
    : 'Event ' . $Event;
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($Event) || $Event == 0) {
    echo "No event selected for result calculations";
    return;
}

$prev = $Event;

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
        m.user_id, NULL AS group_id, NULL AS Score, NULL AS event_id,
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
        m.user_id, NULL AS group_id, NULL AS Score, NULL AS event_id,
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