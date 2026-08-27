<?php
/* =========================================================
   Update Club Ratings
   Version: 1.0
   Date: 2026-08-27

   Companion to "Copy Ranks to user profile" — call it right
   after that snippet, in Apply Override Stage 2. Reads the
   just-published Schedules_Scores_{event_id} table, applies
   ONE incremental Glicko update to club_rating_state (seeded
   once from two years of history — see club_rating_state_bootstrap.sql),
   and writes spp_club_rating / spp_club_rating_games to usermeta
   for every player who played this week.

   RE-PUBLISH SAFE: if Apply Override is run again for the same
   event (a score got corrected, overrides recalculated, results
   re-published), this snippet detects the event already has a
   ledger entry in club_rating_event_log, rolls every affected
   player back to their pre-event snapshot, then reprocesses with
   the corrected data — so a re-publish doesn't double-count the
   event. If any affected player has since been touched by a LATER
   event (shouldn't normally happen — corrections are expected
   same-week, before the next event is played), it aborts with a
   loud warning instead of silently corrupting that later event's
   result.

   Does NOT touch spp_dupr_rating — that's self-reported and
   assumed to be entered elsewhere (profile field). If that's
   wrong, tell me and I'll fold it in here too.

   ASSUMPTIONS TO CONFIRM (see accompanying message):
   - Schedules_Scores_{event_id} has the same columns as the
     historical dump: user_id, group_id, Crt_ID, time_id,
     Game1..Game5, Rank. If DESCRIBE Schedules shows different
     names, the column list below needs matching edits.
   - club_rating_state has been bootstrapped (one-time SQL,
     provided separately) before this snippet is ever called.
   ========================================================= */

global $wpdb;

// -- DRY RUN ------------------------------------------------------------------
// While true: does all the same reads, reconstruction, and Glicko math against
// real production data, but performs NO writes anywhere (club_rating_state,
// club_rating_event_log, usermeta all untouched) and instead prints a full
// per-player report of what WOULD happen. Safe to run repeatedly against any
// already-published event with zero risk. Flip to false only once the dry-run
// output has been checked against a real event and looks right.
$DRY_RUN = true;

$prefix        = $wpdb->prefix;
$umetatable    = $prefix . 'usermeta';
$results_table = "Results";
$state_table   = "club_rating_state";
$log_table     = "club_rating_event_log";

// -- Determine the event being published, same defensive pattern as ----------
// -- "Apply Override to Results Table" Stage 2 (read from Results, not $Event --
// -- / session, since both can be stale on reload). ---------------------------
$Event = (int) $wpdb->get_var("SELECT event_id FROM {$results_table} LIMIT 1");
if ( ! $Event ) {
    echo "<p style='color:#c0392b;font-weight:bold;'>⚠ Update Club Ratings: could not determine event_id from Results table — aborting, no ratings changed.</p>";
    return;
}

$scores_table = "Schedules_Scores_{$Event}";
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$scores_table}'");
if ( $table_exists !== $scores_table ) {
    echo "<p style='color:#c0392b;font-weight:bold;'>⚠ Update Club Ratings: {$scores_table} does not exist — aborting, no ratings changed.</p>";
    return;
}

// ==============================================================
// STEP 1 — pull this event's rows and reconstruct doubles games
// ==============================================================

$rows = $wpdb->get_results(
    "SELECT user_id, group_id, Rank, Game1, Game2, Game3, Game4, Game5 FROM {$scores_table}",
    ARRAY_A
);

if ( empty($rows) ) {
    echo "<p style='color:#c0392b;font-weight:bold;'>⚠ Update Club Ratings: {$scores_table} has no rows — aborting, no ratings changed.</p>";
    return;
}

// group rows by group_id
$groups = array();
$rank_by_user = array();
foreach ($rows as $r) {
    $groups[$r['group_id']][] = $r;
    $rank_by_user[(int)$r['user_id']] = (int)$r['Rank'];
}

/**
 * Try to split 4 (user_id, value) pairs into two teams of 2 sharing a value.
 * Returns array('team1'=>[uid,uid], 'team2'=>[uid,uid], 'score1'=>int, 'score2'=>int)
 * or null if it doesn't cleanly split.
 *
 * A player's recorded value IS their own team's actual score — partners
 * share it because they're on the same team. (Confirmed against real
 * records — see the spreadsheet's Data Quality Notes, Issue 6.)
 */
if (!function_exists('crt_try_split_pairs')) {
function crt_try_split_pairs($present) {
    if (count($present) !== 4) return null;
    $by_val = array();
    foreach ($present as $p) {
        $by_val[$p['val']][] = $p['uid'];
    }
    if (count($by_val) !== 2) return null;
    $vals = array_keys($by_val);
    $teams = array_values($by_val);
    if (count($teams[0]) !== 2 || count($teams[1]) !== 2) return null;
    if ($vals[0] === $vals[1]) return null;
    return array(
        'team1' => $teams[0], 'team2' => $teams[1],
        'score1' => (int)$vals[0], 'score2' => (int)$vals[1],
    );
}
}

if (!function_exists('crt_reconstruct_round')) {
function crt_reconstruct_round($present) {
    $n = count($present);
    if ($n === 4) {
        return crt_try_split_pairs($present);
    }
    if ($n === 5) {
        // One player sat out (a rotating bye). Older data used NULL for this;
        // newer schema versions default the column to 0, so a bye shows up as
        // a lone unmatched value among the five (see Methodology tab).
        $counts = array();
        foreach ($present as $p) {
            $counts[$p['val']] = ($counts[$p['val']] ?? 0) + 1;
        }
        $singles = array_keys(array_filter($counts, function($c) { return $c === 1; }));
        if (count($singles) === 1) {
            $bye_val = $singles[0];
            $remaining = array_values(array_filter($present, function($p) use ($bye_val) {
                return $p['val'] != $bye_val;
            }));
            if (count($remaining) === 4) {
                return crt_try_split_pairs($remaining);
            }
        }
        return null;
    }
    return null;
}
}

$games = array();
$unreconstructed = 0;
$total_slots = 0;

foreach ($groups as $group_id => $members) {
    for ($gn = 1; $gn <= 5; $gn++) {
        $col = "Game{$gn}";
        $present = array();
        foreach ($members as $m) {
            if ($m[$col] !== null) {
                $present[] = array('uid' => (int)$m['user_id'], 'val' => (int)$m[$col]);
            }
        }
        if (empty($present)) continue;
        $total_slots++;
        $result = crt_reconstruct_round($present);
        if ($result === null) {
            $unreconstructed++;
            continue;
        }
        $games[] = $result;
    }
}

if ($total_slots === 0) {
    echo "<p style='color:#c0392b;font-weight:bold;'>⚠ Update Club Ratings: no game slots found in {$scores_table} — aborting, no ratings changed.</p>";
    return;
}

// ==============================================================
// STEP 2 — load prior state, seed any brand-new players
// ==============================================================

$Q = log(10) / 400;
$DEFAULT_MU = 1500.0;
$DEFAULT_RD = 300.0;
$MIN_RD = 40.0;
$MAX_RD = 350.0;

if (!function_exists('crt_g_rd')) {
function crt_g_rd($rd, $Q) {
    return 1.0 / sqrt(1.0 + 3.0 * $Q * $Q * $rd * $rd / (M_PI * M_PI));
}
}
if (!function_exists('crt_expected_score')) {
function crt_expected_score($mu_a, $mu_b, $rd_b, $Q) {
    return 1.0 / (1.0 + pow(10, -crt_g_rd($rd_b, $Q) * ($mu_a - $mu_b) / 400.0));
}
}
if (!function_exists('crt_margin_scale')) {
function crt_margin_scale($score_a, $score_b) {
    $diff = $score_a - $score_b;
    $total = max($score_a + $score_b, 1);
    return 0.5 + 0.5 * tanh(($diff / $total) * 2.2);
}
}

$players_this_event = array();
foreach ($games as $g) {
    foreach ($g['team1'] as $u) $players_this_event[$u] = true;
    foreach ($g['team2'] as $u) $players_this_event[$u] = true;
}
$players_this_event = array_keys($players_this_event);

if (empty($players_this_event)) {
    echo "<p style='color:#e67e22;font-weight:bold;'>⚠ Update Club Ratings: {$total_slots} game slot(s) found in {$scores_table}, but none reconstructed cleanly ({$unreconstructed} unreconstructed) — no ratings changed this week.</p>";
    return;
}

// load existing state for everyone (needed both for this event's players and
// for seeding-regression peers)
$all_state_rows = $wpdb->get_results("SELECT user_id, mu, rd, games_played, first_event, last_event FROM {$state_table}", ARRAY_A);
$state = array();
foreach ($all_state_rows as $r) {
    $state[(int)$r['user_id']] = array(
        'mu' => (float)$r['mu'], 'rd' => (float)$r['rd'],
        'games_played' => (int)$r['games_played'],
        'first_event' => (int)$r['first_event'], 'last_event' => (int)$r['last_event'],
    );
}

// ==============================================================
// STEP 2a — if this event was already processed (results were
// corrected and re-published), roll every affected player back to
// their pre-event snapshot before reprocessing, so this run doesn't
// double-count the event on top of the earlier one.
// ==============================================================

$EPS = 0.001; // float-comparison tolerance for the safety check below

$prior_log_rows = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$log_table} WHERE event_id = %d", $Event),
    ARRAY_A
);

if ( ! empty($prior_log_rows) ) {
    // Safety check first: every logged player's CURRENT state must still
    // match what this event left them at. If it doesn't, a later event has
    // already used this (about-to-be-invalidated) rating as an input, and
    // rolling back now would leave the system inconsistent. Abort loudly
    // rather than silently corrupt — same posture as the row-count checks
    // in Apply Override.
    $drifted = array();
    foreach ($prior_log_rows as $lr) {
        $uid = (int)$lr['user_id'];
        if ( ! isset($state[$uid]) ) { $drifted[] = $uid; continue; }
        $cur = $state[$uid];
        if ( abs($cur['mu'] - (float)$lr['mu_after']) > $EPS
          || abs($cur['rd'] - (float)$lr['rd_after']) > $EPS
          || $cur['games_played'] !== (int)$lr['games_after'] ) {
            $drifted[] = $uid;
        }
    }
    if ( ! empty($drifted) ) {
        echo "<p style='color:#c0392b;font-weight:bold;background:#fdf3f2;border:2px solid #c0392b;border-radius:6px;padding:14px;'>";
        echo "⚠ Update Club Ratings: event {$Event} was already processed, but " . count($drifted) . " player(s) (user_id: " . implode(', ', $drifted) . ") have since been updated by a LATER event. ";
        echo "Rolling back event {$Event} now would corrupt those later updates. Aborting — no ratings changed. This needs a manual look before reprocessing this event.";
        echo "</p>";
        return;
    }

    // Safe to roll back (in-memory $state always updated so downstream math
    // stays correct even in dry-run mode; only the actual writes are gated).
    $rolled_back = 0;
    foreach ($prior_log_rows as $lr) {
        $uid = (int)$lr['user_id'];
        if ( (int)$lr['games_before'] === 0 && (int)$lr['last_event_before'] === $Event ) {
            // this event created the player from scratch — undo the creation entirely
            unset($state[$uid]);
            if (!$DRY_RUN) $wpdb->query($wpdb->prepare("DELETE FROM {$state_table} WHERE user_id = %d", $uid));
        } else {
            $state[$uid] = array(
                'mu' => (float)$lr['mu_before'], 'rd' => (float)$lr['rd_before'],
                'games_played' => (int)$lr['games_before'],
                'first_event' => isset($state[$uid]) ? $state[$uid]['first_event'] : $Event,
                'last_event' => (int)$lr['last_event_before'],
            );
        }
        $rolled_back++;
    }
    if (!$DRY_RUN) $wpdb->query($wpdb->prepare("DELETE FROM {$log_table} WHERE event_id = %d", $Event));
    echo "<p style='color:#e67e22;font-weight:bold;'>Event {$Event} was already processed once — " . ($DRY_RUN ? "would roll back" : "rolled back") . " {$rolled_back} player(s) to their pre-event rating before reprocessing with corrected scores.</p>";
}

if (!function_exists('crt_seed_new_player')) {
function crt_seed_new_player($uid, $rank_by_user, $state, $DEFAULT_MU, $DEFAULT_RD) {
    if (!isset($rank_by_user[$uid])) return array($DEFAULT_MU, $DEFAULT_RD);
    $r = $rank_by_user[$uid];
    $peers = array();
    foreach ($rank_by_user as $u2 => $rank2) {
        if ($u2 === $uid) continue;
        if (!isset($state[$u2]) || $state[$u2]['games_played'] < 3) continue;
        $peers[] = array($rank2, $state[$u2]['mu']);
    }
    if (count($peers) < 4) return array($DEFAULT_MU, $DEFAULT_RD);
    $n = count($peers);
    $mean_x = array_sum(array_column($peers, 0)) / $n;
    $mean_y = array_sum(array_column($peers, 1)) / $n;
    $var_x = 0; $cov = 0;
    foreach ($peers as $p) {
        $var_x += ($p[0] - $mean_x) * ($p[0] - $mean_x);
        $cov   += ($p[0] - $mean_x) * ($p[1] - $mean_y);
    }
    if ($var_x == 0) return array($mean_y, $DEFAULT_RD);
    $slope = $cov / $var_x;
    $pred = $mean_y + $slope * ($r - $mean_x);
    return array($pred, $DEFAULT_RD);
}
}

$new_player_count = 0;
foreach ($players_this_event as $uid) {
    if (!isset($state[$uid])) {
        list($mu0, $rd0) = crt_seed_new_player($uid, $rank_by_user, $state, $DEFAULT_MU, $DEFAULT_RD);
        $state[$uid] = array('mu' => $mu0, 'rd' => $rd0, 'games_played' => 0, 'first_event' => $Event, 'last_event' => $Event);
        $new_player_count++;
    }
}

// snapshot every affected player's state as it stands going into this
// event's update — this is the ledger's "before" row for each of them.
$before_snapshot = array();
foreach ($players_this_event as $uid) {
    $before_snapshot[$uid] = $state[$uid];
}

// ==============================================================
// STEP 3 — apply this event's games as one Glicko rating period
// ==============================================================

$deltas = array(); // uid => array of [opp_rd, actual, expected]

foreach ($games as $g) {
    $t1 = $g['team1']; $t2 = $g['team2'];
    $mu1 = ($state[$t1[0]]['mu'] + $state[$t1[1]]['mu']) / 2;
    $mu2 = ($state[$t2[0]]['mu'] + $state[$t2[1]]['mu']) / 2;
    $rd1 = ($state[$t1[0]]['rd'] + $state[$t1[1]]['rd']) / 2;
    $rd2 = ($state[$t2[0]]['rd'] + $state[$t2[1]]['rd']) / 2;

    $exp1 = crt_expected_score($mu1, $mu2, $rd2, $Q);
    $actual1 = crt_margin_scale($g['score1'], $g['score2']);

    foreach ($t1 as $u) $deltas[$u][] = array($rd2, $actual1, $exp1);
    foreach ($t2 as $u) $deltas[$u][] = array($rd1, 1 - $actual1, 1 - $exp1);
}

foreach ($deltas as $uid => $obs) {
    $st = $state[$uid];
    $d2_inv = 0; $sum_term = 0;
    foreach ($obs as $o) {
        list($opp_rd, $actual, $expected) = $o;
        $gval = crt_g_rd($opp_rd, $Q);
        $d2_inv += $Q * $Q * $gval * $gval * $expected * (1 - $expected);
        $sum_term += $gval * ($actual - $expected);
    }
    if ($d2_inv > 0) {
        $d2 = 1.0 / $d2_inv;
        $new_rd = sqrt(1.0 / (1.0 / ($st['rd'] * $st['rd']) + 1.0 / $d2));
        $new_mu = $st['mu'] + $Q * $new_rd * $new_rd * $sum_term;
    } else {
        $new_rd = $st['rd']; $new_mu = $st['mu'];
    }
    $new_rd = max($MIN_RD, min($MAX_RD, $new_rd));
    $state[$uid]['mu'] = $new_mu;
    $state[$uid]['rd'] = $new_rd;
    $state[$uid]['games_played'] += count($obs);
    $state[$uid]['last_event'] = $Event;
}

// ==============================================================
// STEP 4 — persist updated state (upsert) and record the ledger
// ==============================================================

$updated_count = 0;
foreach ($players_this_event as $uid) {
    $st = $state[$uid];
    $before = $before_snapshot[$uid];

    if (!$DRY_RUN) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$state_table} WHERE user_id = %d", $uid));
        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$state_table} SET mu=%f, rd=%f, games_played=%d, last_event=%d, updated_at=NOW() WHERE user_id=%d",
                $st['mu'], $st['rd'], $st['games_played'], $st['last_event'], $uid
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$state_table} (user_id, mu, rd, games_played, first_event, last_event, updated_at) VALUES (%d, %f, %f, %d, %d, %d, NOW())",
                $uid, $st['mu'], $st['rd'], $st['games_played'], $st['first_event'], $st['last_event']
            ));
        }

        // ledger row for this event — lets a future re-publish roll back cleanly
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$log_table} (event_id, user_id, mu_before, rd_before, games_before, last_event_before, mu_after, rd_after, games_after, processed_at)
             VALUES (%d, %d, %f, %f, %d, %d, %f, %f, %d, NOW())",
            $Event, $uid, $before['mu'], $before['rd'], $before['games_played'], $before['last_event'],
            $st['mu'], $st['rd'], $st['games_played']
        ));
    } // end !$DRY_RUN

    $updated_count++;
}

// ==============================================================
// STEP 5 — rescale to 2.0-5.0 Club Rating and write usermeta
// ==============================================================
// Recomputed fresh each run from the CURRENT established population
// (games_played >= 15), so the scale stays anchored as the club evolves.

$established = $wpdb->get_results("SELECT mu FROM {$state_table} WHERE games_played >= 15", ARRAY_A);
$mus = array_map(function($r) { return (float)$r['mu']; }, $established);
$n_est = count($mus);

if ($n_est < 10) {
    echo "<p style='color:#e67e22;font-weight:bold;'>⚠ Update Club Ratings: only {$n_est} established players (need at least a handful for a stable scale) — ratings updated in club_rating_state, but usermeta NOT written this run.</p>";
    return;
}

$mean_mu = array_sum($mus) / $n_est;
$var = 0;
foreach ($mus as $m) { $var += ($m - $mean_mu) * ($m - $mean_mu); }
$std_mu = sqrt($var / ($n_est - 1));
$max_mu = max($mus); $min_mu = min($mus);
$z_top = ($max_mu - $mean_mu) / $std_mu;
$z_bot = ($min_mu - $mean_mu) / $std_mu;
$k = min(1.5 / $z_top, 1.5 / abs($z_bot));

if (!function_exists('crt_to_scale')) {
function crt_to_scale($mu, $mean_mu, $std_mu, $k) {
    return round(3.5 + ($mu - $mean_mu) / $std_mu * $k, 2);
}
}

foreach ($players_this_event as $uid) {
    $rating = crt_to_scale($state[$uid]['mu'], $mean_mu, $std_mu, $k);
    $games_played = $state[$uid]['games_played'];

    if (!$DRY_RUN) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$umetatable} WHERE meta_key = 'spp_club_rating' AND user_id = %d", $uid));
        $wpdb->query($wpdb->prepare("INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d, 'spp_club_rating', %s)", $uid, $rating));

        $wpdb->query($wpdb->prepare("DELETE FROM {$umetatable} WHERE meta_key = 'spp_club_rating_games' AND user_id = %d", $uid));
        $wpdb->query($wpdb->prepare("INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d, 'spp_club_rating_games', %d)", $uid, $games_played));
    }
}

if ($DRY_RUN) {
    echo "<div style='max-width:900px;margin:16px 0;padding:14px 18px;background:#fff8e1;border:2px solid #e67e22;border-radius:6px;font-family:Arial,sans-serif;'>";
    echo "<h3 style='color:#e67e22;margin:0 0 10px;'>DRY RUN — no data was changed</h3>";
    echo "<p style='font-size:13px;color:#666;margin:0 0 12px;'>club_rating_state, club_rating_event_log, and usermeta were not written. ";
    echo "Note: the scale below (mean/std) was computed from club_rating_state as it stands BEFORE this run — in live mode it would reflect this event's own updates too, so the very last decimal of each rating may shift slightly once actually applied.</p>";
    echo "<table style='width:100%;border-collapse:collapse;font-size:13px;'>";
    echo "<tr style='background:#fdf3f2;text-align:left;'><th style='padding:6px;'>user_id</th><th style='padding:6px;'>mu before → after</th><th style='padding:6px;'>games before → after</th><th style='padding:6px;'>Club Rating (would write)</th><th style='padding:6px;'>New player?</th></tr>";
    foreach ($players_this_event as $uid) {
        $before = $before_snapshot[$uid];
        $after  = $state[$uid];
        $rating = crt_to_scale($after['mu'], $mean_mu, $std_mu, $k);
        $is_new = ($before['games_played'] === 0 && $before['last_event'] === $Event) ? 'yes' : '';
        echo "<tr style='border-top:1px solid #eee;'>";
        echo "<td style='padding:6px;'>{$uid}</td>";
        echo "<td style='padding:6px;'>" . number_format($before['mu'],1) . " → " . number_format($after['mu'],1) . "</td>";
        echo "<td style='padding:6px;'>{$before['games_played']} → {$after['games_played']}</td>";
        echo "<td style='padding:6px;font-weight:bold;'>{$rating}</td>";
        echo "<td style='padding:6px;'>{$is_new}</td>";
        echo "</tr>";
    }
    echo "</table></div>";
}

$msg = ($DRY_RUN ? "DRY RUN: would update " : "OK: Club ratings updated for ") . count($players_this_event) . " player(s) from event {$Event} "
     . "({$total_slots} game slot(s), " . count($games) . " reconstructed, {$unreconstructed} unreconstructed";
if ($new_player_count > 0) $msg .= ", {$new_player_count} new player(s) seeded";
$msg .= ").";
echo "<br><span style='font-size:14px;color:" . ($DRY_RUN ? '#e67e22' : '#339966') . ";'>{$msg}</span>";

if ($unreconstructed > 0) {
    echo "<br><span style='font-size:13px;color:#e67e22;'>Note: {$unreconstructed} of {$total_slots} slot(s) in {$scores_table} couldn't be cleanly reconstructed into two teams (likely a score-entry mismatch) — those games contributed nothing to any rating this week.</span>";
}

// ==============================================================
// STEP 6 — purge ledger rows for any OTHER event
// ==============================================================
// Once we're processing event $Event's results, every OLDER event's
// schedule has necessarily already been superseded (schedule production
// advances sequentially), which per the club's own rule means that older
// event's scores can no longer be corrected. Its ledger row (needed only
// to protect against a same-week re-publish) is dead weight from here on,
// so it's safe to drop — keeping the ledger at essentially one event's
// worth of rows at all times, with no separate cleanup job needed.
if (!$DRY_RUN) {
    $purged = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$log_table} WHERE event_id != %d", $Event));
    if ($purged > 0) {
        echo "<br><span style='font-size:13px;color:#666;'>Cleared {$purged} ledger row(s) from prior, now-locked event(s).</span>";
    }
}
