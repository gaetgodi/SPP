<?php
/* =========================================================
   Update Club Ratings
   Version: 1.0.0
   Date: 2026-09-06
   Based on: Code Manager snippet "Update Club Ratings" (CM284)
   fresh-pulled tonight (27570 bytes,
   sha256 efd168b2f4e7a9575a57479a4ca65af52aff59b064ff55d83852ee6db38c05a9,
   v1.4, 2026-08-27).

   PURPOSE (plain-English trace of the actual math, not just "computes
   ratings"):
   Runs one incremental Glicko-1 rating update for the club's ladder,
   using the just-published event's per-game scores as the "games"
   for this rating period, and maintains a separate 2.0-5.0 "Club
   Rating" display value derived from those Glicko ratings.

   INPUTS:
   - Schedules_Scores_{event_id} (event_id read from Results.event_id,
     same defensive pattern as Apply Override Stage 2 -- not $Event,
     not session, both can be stale on reload): user_id, group_id,
     Rank, Game1..Game5 per row.
   - club_rating_state: this club's persistent Glicko state, one row
     per player (mu, rd, games_played, first_event, last_event) --
     seeded once from two years of history (club_rating_state_bootstrap.sql,
     already in the repo, already run -- not touched by this migration).
   - club_rating_event_log: a ledger of before/after snapshots per
     (event_id, user_id), used only to make a same-week re-publish
     safe (see STEP 2a below).

   STEP 1 -- reconstruct doubles games from the raw score columns.
   Each Game1..Game5 column is a "slot". A slot is skipped entirely
   (not counted) if every player in the group has NULL there. Within
   a played slot, a group of 4 present players splits into two teams
   of 2 sharing the same recorded value (that value IS the team's own
   score -- partners share it because they're on the same team,
   confirmed against real records). A group of 5 present players is
   treated as one bye (a value appearing exactly once) plus a normal
   4-player split of the rest. A slot where everyone present shows 0
   is "not played" (the unused-column default, not a real 0-0 result
   -- a real result can't have every player score 0) and is counted
   separately from a slot that has real, non-zero values but still
   doesn't cleanly split into two teams of 2 with distinct scores
   ("unreconstructed" -- a likely genuine score-entry problem).

   STEP 2 -- load every player's current Glicko state, seed anyone
   appearing in this event's games for the first time. A brand-new
   player's starting mu is predicted by a simple linear regression of
   ladder Rank vs. mu across established peers (games_played >= 3) at
   this event, if at least 4 such peers exist; otherwise everyone new
   starts at the standard Glicko defaults (mu 1500, rd 300).

     STEP 2a -- re-publish safety. If this event_id already has ledger
     rows (a prior run already processed it -- scores got corrected
     and Apply Override was re-run), first checks that every affected
     player's CURRENT state still exactly matches what that prior run
     left them at. If a LATER event has since built on top of one of
     them, it aborts loudly instead of rolling back (which would
     silently corrupt that later event's result). Otherwise it rolls
     every affected player back to their pre-event snapshot (or
     removes them entirely if this event created them from scratch),
     deletes the event's ledger rows, and reprocesses cleanly with
     the corrected data -- so a re-publish never double-counts.

   STEP 3 -- the actual Glicko-1 update, applied as one rating period
   per event (not one update per game). For each reconstructed game,
   each team's mu/rd is the average of its two players'. The expected
   score uses the standard Glicko deviation-scaled logistic function
   g(RD) / 1/(1+10^(-g(RD)*(mu_a-mu_b)/400)). The "actual" score is
   NOT a plain win/loss (1/0) -- it's margin-of-victory-scaled via
   tanh(margin_ratio * 2.2), mapped to [0,1], so a blowout counts more
   than a narrow win. Every player's game-observations for the event
   are then combined via the standard Glicko d²/new-RD/new-mu formulas,
   with RD clamped to [40, 350].

   STEP 4 -- persists each event-player's new mu/rd/games_played to
   club_rating_state (update or insert), and writes one
   club_rating_event_log row per player recording the exact
   before/after snapshot (this is what makes STEP 2a's rollback
   possible).

   STEP 5 -- rescales to the public-facing 2.0-5.0 "Club Rating" and
   writes it to usermeta (spp_glicko_rating, spp_glicko_rating_games).
   The scale is recomputed FRESH each run from the current population
   of "established" players (games_played >= 15): mean and std-dev of
   their mu, then a single scale factor k chosen so the current best
   and worst established players land at (at most) 5.0 and 2.0
   respectively, centered at 3.5. Written for EVERY player in
   club_rating_state, not just this event's players -- deliberate
   (v1.4): the scale itself shifts slightly every run as the
   population changes, so a non-player's displayed rating can drift
   even though their own mu never moved. If fewer than 10 players are
   currently "established", club_rating_state and the ledger are still
   written (STEP 4 already ran), but usermeta is skipped this run --
   matches live behavior exactly, not changed here. At current
   population (217 rows, 157 established as of this migration), this
   threshold is nowhere close to being hit.

   STEP 6 -- once this event is being processed, every OLDER event's
   schedule is necessarily already locked (schedule production only
   moves forward), so its ledger row (needed only to protect a
   same-week re-publish) is dead weight and gets purged, keeping the
   ledger at essentially one event's worth of rows. Confirmed live:
   58 ledger rows, all for event 162 (the current one) -- matches
   this design exactly. NOTE: because this step runs after STEP 5's
   `n_est < 10` early return, the purge would be skipped in that same
   edge case. Purely theoretical at the current population size (157
   established, vs. a threshold of 10) -- not changed here, matches
   live control flow exactly.

   Does NOT touch spp_dupr_rating (self-reported, entered elsewhere).

   ASSUMPTIONS FROM THE ORIGINAL HEADER, NOW CONFIRMED (not just
   carried forward as an open question): DESCRIBE'd the live
   Schedules_Scores_162 table directly -- user_id, group_id, Rank,
   Game1..Game5 are all present exactly as this code expects.
   club_rating_state / club_rating_event_log schemas also confirmed
   directly against the live tables.

   BUG-SHAPE AUDIT (same five shapes found earlier tonight) --
   RESULT: CLEAN, none present. Checked explicitly, not assumed:
   - No meta_key LIKE '%X%' wildcard anywhere -- both usermeta deletes
     in Step 5 use an exact meta_key match already
     ('spp_glicko_rating' / 'spp_glicko_rating_games').
   - No dead session_status()/session_start() guard anywhere in this
     file.
   - No delete-before-insert ordering issue -- Step 4's persistence is
     a proper exists-check upsert (SELECT then UPDATE or INSERT), and
     Step 5's usermeta writes are delete-then-insert on an exact key,
     same safe pattern used everywhere else tonight.
   - No $GLOBALS['$X']-style typo anywhere.
   - Does not call any already-migrated function internally -- CM284
     has no [cmruncode]/do_shortcode calls anywhere; it only reads/
     writes Schedules_Scores_*, club_rating_state,
     club_rating_event_log, and usermeta directly. Nothing to replace
     with a direct function call.

   PRE-EXISTING FINDING, NOT part of this migration's scope, flagged
   for awareness: inc/update_club_ratings.php (git-tracked, added in
   commit 53c025a, last updated in lockstep with CM284's own v1.4 in
   commit d5bd53b) is a byte-for-byte copy of this same raw snippet
   source -- confirmed via diff against tonight's fresh CM284 pull.
   It is a bare top-level script (no function wrapper, no ABSPATH
   guard) and is NOT require_once'd anywhere in functions.php, so it
   has never actually executed on this site; it appears to have been
   kept purely as a version-controlled reference copy of the
   algorithm, alongside club_rating_state_bootstrap.sql and
   spp_club_rating_pipeline.py (a reference Python implementation,
   also already in the repo, also not touched here). Now superseded
   by this file. Left in place, not deleted or modified -- removing a
   pre-existing git-tracked file is outside what this migration was
   asked to do; flagging it for a separate decision.

   CALLED FROM (verified exhaustively -- theme/mu-plugin grep, Code
   Manager DB grep, and a full post_content scan): exactly one caller,
   internally, as spp_update_club_ratings(), from the already-migrated
   spp_apply_override_to_results_table() (CM52) -- that file has been
   updated to call this function directly instead of going through
   [cmruncode] (see its own changelog entry). No page calls
   [cmruncode name='Update Club Ratings'] directly, and no other Code
   Manager snippet calls it either.

   EXPLICIT PARAMETERS: like CM52, this snippet receives nothing from
   its caller -- `global $wpdb;` is the only global read, and $Event
   is derived entirely internally from Results.event_id. Confirmed no
   hidden dependency on caller scope.

   Changes from CM284: wrapped in a real function,
   spp_update_club_ratings( bool $dry_run = false ) -- the previously
   hardcoded `$DRY_RUN = false;` is now a parameter defaulting to the
   same value, so a dry run (as used to validate v1.0 against event
   162) can be triggered programmatically instead of editing source
   and flipping a boolean; calling with no arguments matches live
   behavior exactly. The seven crt_* helper functions are renamed to
   the required spp_ prefix (spp_crt_try_split_pairs,
   spp_crt_reconstruct_round, spp_crt_seed_new_player, spp_crt_g_rd,
   spp_crt_expected_score, spp_crt_margin_scale, spp_crt_to_scale) --
   a pure rename, per this theme's naming convention (CLAUDE.md).
   Their function_exists() guards (needed under Code Manager's
   eval()-per-request model) are dropped, same as every other
   migrated snippet tonight -- no guard needed since this file is
   require_once'd exactly once. No other behavior change: identical
   reconstruction logic, identical Glicko math, identical rescale,
   identical writes.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'spp_crt_try_split_pairs' ) ) {
/**
 * Try to split 4 (user_id, value) pairs into two teams of 2 sharing a value.
 * Returns array('team1'=>[uid,uid], 'team2'=>[uid,uid], 'score1'=>int, 'score2'=>int)
 * or null if it doesn't cleanly split.
 *
 * A player's recorded value IS their own team's actual score — partners
 * share it because they're on the same team. (Confirmed against real
 * records — see the spreadsheet's Data Quality Notes, Issue 6.)
 */
function spp_crt_try_split_pairs( $present ) {
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

if ( ! function_exists( 'spp_crt_reconstruct_round' ) ) {
function spp_crt_reconstruct_round( $present ) {
    $n = count($present);
    if ($n === 4) {
        return spp_crt_try_split_pairs($present);
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
                return spp_crt_try_split_pairs($remaining);
            }
        }
        return null;
    }
    return null;
}
}

if ( ! function_exists( 'spp_crt_g_rd' ) ) {
function spp_crt_g_rd( $rd, $Q ) {
    return 1.0 / sqrt(1.0 + 3.0 * $Q * $Q * $rd * $rd / (M_PI * M_PI));
}
}
if ( ! function_exists( 'spp_crt_expected_score' ) ) {
function spp_crt_expected_score( $mu_a, $mu_b, $rd_b, $Q ) {
    return 1.0 / (1.0 + pow(10, -spp_crt_g_rd($rd_b, $Q) * ($mu_a - $mu_b) / 400.0));
}
}
if ( ! function_exists( 'spp_crt_margin_scale' ) ) {
function spp_crt_margin_scale( $score_a, $score_b ) {
    $diff = $score_a - $score_b;
    $total = max($score_a + $score_b, 1);
    return 0.5 + 0.5 * tanh(($diff / $total) * 2.2);
}
}

if ( ! function_exists( 'spp_crt_seed_new_player' ) ) {
function spp_crt_seed_new_player( $uid, $rank_by_user, $state, $DEFAULT_MU, $DEFAULT_RD ) {
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

if ( ! function_exists( 'spp_crt_to_scale' ) ) {
function spp_crt_to_scale( $mu, $mean_mu, $std_mu, $k ) {
    return round(3.5 + ($mu - $mean_mu) / $std_mu * $k, 2);
}
}

function spp_update_club_ratings( bool $dry_run = false ) {

    global $wpdb;

    $DRY_RUN = $dry_run;

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

    $games = array();
    $unreconstructed = 0;
    $not_played = 0;
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

            // Everyone present shows the unused-column default (0) -- this slot
            // was never played (see v1.3 changelog), not a score-entry problem.
            // Counted in its own bucket so the unreconstructed warning only
            // fires on genuine failures. A real result can't have every player
            // score 0; a genuine skunk (e.g. 15-0) mixes a 0 with a nonzero
            // value and isn't caught here.
            $all_zero = true;
            foreach ($present as $p) {
                if ($p['val'] !== 0) { $all_zero = false; break; }
            }
            if ($all_zero) { $not_played++; continue; }

            $result = spp_crt_reconstruct_round($present);
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

    $players_this_event = array();
    foreach ($games as $g) {
        foreach ($g['team1'] as $u) $players_this_event[$u] = true;
        foreach ($g['team2'] as $u) $players_this_event[$u] = true;
    }
    $players_this_event = array_keys($players_this_event);

    if (empty($players_this_event)) {
        echo "<p style='color:#e67e22;font-weight:bold;'>⚠ Update Club Ratings: {$total_slots} game slot(s) found in {$scores_table} ({$not_played} not played, {$unreconstructed} unreconstructed), but none reconstructed cleanly — no ratings changed this week.</p>";
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

    $new_player_count = 0;
    foreach ($players_this_event as $uid) {
        if (!isset($state[$uid])) {
            list($mu0, $rd0) = spp_crt_seed_new_player($uid, $rank_by_user, $state, $DEFAULT_MU, $DEFAULT_RD);
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

        $exp1 = spp_crt_expected_score($mu1, $mu2, $rd2, $Q);
        $actual1 = spp_crt_margin_scale($g['score1'], $g['score2']);

        foreach ($t1 as $u) $deltas[$u][] = array($rd2, $actual1, $exp1);
        foreach ($t2 as $u) $deltas[$u][] = array($rd1, 1 - $actual1, 1 - $exp1);
    }

    foreach ($deltas as $uid => $obs) {
        $st = $state[$uid];
        $d2_inv = 0; $sum_term = 0;
        foreach ($obs as $o) {
            list($opp_rd, $actual, $expected) = $o;
            $gval = spp_crt_g_rd($opp_rd, $Q);
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
    // Written for EVERY user_id in club_rating_state (not just this event's
    // players): the scale (mean_mu/std_mu/k) shifts slightly every run as the
    // population changes, so a non-playing member's displayed rating can drift
    // even though their own mu didn't move. Their mu itself is untouched here
    // either way — this only controls who gets their usermeta rewritten.

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

    foreach ($state as $uid => $st) {
        $rating = spp_crt_to_scale($st['mu'], $mean_mu, $std_mu, $k);
        $games_played = $st['games_played'];

        if (!$DRY_RUN) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$umetatable} WHERE meta_key = 'spp_glicko_rating' AND user_id = %d", $uid));
            $wpdb->query($wpdb->prepare("INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d, 'spp_glicko_rating', %s)", $uid, $rating));

            $wpdb->query($wpdb->prepare("DELETE FROM {$umetatable} WHERE meta_key = 'spp_glicko_rating_games' AND user_id = %d", $uid));
            $wpdb->query($wpdb->prepare("INSERT INTO {$umetatable} (user_id, meta_key, meta_value) VALUES (%d, 'spp_glicko_rating_games', %d)", $uid, $games_played));
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
            $rating = spp_crt_to_scale($after['mu'], $mean_mu, $std_mu, $k);
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
         . "({$total_slots} game slot(s), " . count($games) . " reconstructed, {$not_played} not played, {$unreconstructed} unreconstructed";
    if ($new_player_count > 0) $msg .= ", {$new_player_count} new player(s) seeded";
    $msg .= ").";
    echo "<br><span style='font-size:14px;color:" . ($DRY_RUN ? '#e67e22' : '#339966') . ";'>{$msg}</span>";

    if ($unreconstructed > 0) {
        echo "<br><span style='font-size:13px;color:#e67e22;'>Note: {$unreconstructed} of {$total_slots} slot(s) in {$scores_table} couldn't be cleanly reconstructed into two teams (likely a genuine score-entry mismatch, distinct from the {$not_played} slot(s) that simply weren't played) — those games contributed nothing to any rating this week.</span>";
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
}
