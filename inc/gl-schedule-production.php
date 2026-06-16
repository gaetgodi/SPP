<?php
/* =========================================================
   GL Schedule Production
   Version: 1.9.1
   Date: 2026-06-16

   Changes from 1.9.0:
   - Distribution fill rewritten: remaining groups allocated
     proportionally to slots receiving displaced - players,
     then evenly to slots with room. Prevents greedy fill
     from starving later time slots.

   Changes from 1.8.1:
   - Base distribution rewritten: demand-driven sequential.
     Counts + and - travel preferences per time slot, allocates
     minimum groups to hold + players, ensures room for displaced
     - players, fills remaining groups sequentially slot 1 onward.
     Replaces random distribution and minimum-1 floor.

   Changes from 1.8.0:
   - Distribution floor: enforce minimum 1 group per time slot.
     Prevents degenerate case where a time slot ends up empty
     and + preference players cannot be placed there.

   Changes from 1.7.0:
   - Phase 5 enhanced: paired carpool swap logic added. When a
     + preference player also has a carpool partner, both are
     swapped together with no-preference players at the target
     time. Tolerance tiers expanded to 1.0x, 1.5x, 3.0x.

   Changes from 1.6.0:
   - Phase 5 added: travel + preference last pass. Iteratively
     swaps +5:30/+6:40/+7:50 players not at their preferred time
     with no-preference players at the target time. Uses
     $effective_tolerance with 1.5x fallback. Carpool guardrail.

   Changes from 1.5.0:
   - Phase 4 added: carpool same-slot last pass. After all other
     phases, iteratively swaps individual players to bring carpool
     partners into the same time slot. Priority carpools processed
     first. Uses $effective_tolerance, widening to 1.5x if no
     candidate found at normal tolerance. Loops until convergence
     or 20 iterations. Carpool score guardrail prevents regressions.

   Changes from 1.4.0:
- Guard added: schedule production blocked until previous
  results are posted via Apply Override.
  Uses spp_results_posted option (set to 1 by Apply Override
  Stage 1, reset to 0 after schedule creation).

   Changes from 1.3.0:
   - Clear spp_modified_groups option when new schedule is created.

   Changes from 1.1.0:
   - Pass 1.5 now handles +6:40 and +7:50 preferences in addition
     to +5:30 using generic $pref_patterns loop (from v1.8.12).
   - $score_violation now scores +6:40 and +7:50 preferences
     (score 50) in addition to existing +5:30 handling.
   - Final violation report now checks and reports +6:40 and
     +7:50 players not at their preferred time slot.
   - GL Assign ranks snippet name fixed (no trailing 's').

   Changes from 1.0.0:
   - Reads registrants from lX9c1_gl_registrations instead of
     lX9c1_rtec_entries. No +30000000 hack.
   - $Event is now the actual gl_event_occurrences.id value.
   - registration_date populated from gl_registrations.registered_at
     (legacy field, no longer used functionally).
   - All other logic — Master, Groups, Courts, Times, Schedules,
     preferred tables, carpool, post-processing — unchanged.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_create_schedule', 'spp_create_schedule_shortcode' );

function spp_create_schedule_shortcode() {
    ob_start();
    spp_run_schedule_production();
    return ob_get_clean();
}

function spp_run_schedule_production() {

if (!session_status() == PHP_SESSION_ACTIVE) {
session_start();
}
global $wpdb;
global $Event;
global $name;
global $ctoffdt;
global $groupings;
global $entries;
global $carpool_rank_tolerance;

$Schedules = "Schedules";
$ctoffdt = "2050-12-31";
$prefix = $wpdb->prefix;
if (!isset($Event) || !$Event) { echo '<p class="gl-error">No event selected. Please select a ladder event first.</p>'; return; }
// -------------------------------------------------------
// GUARD: Block if published schedule awaiting results
// -------------------------------------------------------
if (get_option('spp_schedule_published', 0) && !get_option('spp_results_posted', 0)) {
    echo '<p class="gl-error" style="color:#c0392b;font-weight:bold;">Cannot produce a new schedule: the published schedule\'s results have not been posted yet. Publish results first.</p>';
    return;
}
$schedules_prev = "SchedulesPrev$Event";
// Need to run this to refresh travel fields
echo do_shortcode("[cmruncode name='Create membership table']");

// -------------------------------------------------------
// ASSIGN RANKS
// -------------------------------------------------------
echo do_shortcode("[cmruncode name='GL Assign ranks to registered player']");

$settings = get_option('Pkldr_settings');
list('Pkldr_Project' => $Pkldr_Project, 'Pkldr_PageLdr' => $Pkldr_PageLdr) = $settings;
$project = 29;
$page = 70;

if (isset($Event) and $Event <> 0) {

    $master = "Master";
    $this_page = 72;
    $other_page = 75;
    $this_project = 30;
    $page630 = 73;
    $page745 = 74;

    // Take schedule off menu while in draft
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET page_name = 'Schedule for $name', page_title = 'Schedule for $name', page_sequence = $Event, add_to_menu = 'No', page_setname = '$Schedules', page_table_name = '$Schedules' WHERE project_id = $project AND page_id = $page");
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET add_to_menu = 'No' WHERE project_id = $this_project AND page_id = $this_page");
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET add_to_menu = 'No' WHERE project_id = $this_project AND page_id = $other_page");
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET page_name = '$name - 5:30pm', page_title = '$name - 5:30pm', add_to_menu = 'No' WHERE project_id = $project AND page_id = $page630");
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET page_name = '$name - 7:50pm', page_title = '$name - 7:50pm', add_to_menu = 'No' WHERE project_id = $project AND page_id = $page745");

    // -------------------------------------------------------
    // AI SCHEDULE PRODUCTION
    // -------------------------------------------------------

    if (!isset($carpool_rank_tolerance) || $carpool_rank_tolerance <= 0) {
        $carpool_rank_tolerance = 15;
    }
    global $wpda_shortcode_args;
    if (!empty($wpda_shortcode_args['show_tolerance'])) {
        echo "Carpool rank tolerance: <strong>$carpool_rank_tolerance</strong><br>";
    }

    $event = $Event;
    update_option('spp_current_event', $Event);
    $Master = "Master";
    $rerun = "SchedulesP$Event";
    $pref_temp = "preferred_new";
    $pref_active = "preferred";
    $pref_previous = "preferred_prev";
    $pref_permanent = "preferred_permanent";

    $preferred = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $pref_permanent"));

    $groupings = $table_name = $wpdb->prefix . "GpsCrtsTms54_3_" . $event;

    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rerun));
    if ($table_exists === $rerun) {
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE '$pref_previous'")) === $pref_previous) {
            $wpdb->query("TRUNCATE TABLE $pref_active");
            $wpdb->query("INSERT INTO $pref_active SELECT * FROM $pref_previous");
        }
    } else {
        $wpdb->query("CREATE TABLE $rerun LIKE $pref_active");
        $var = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE '$pref_active'"));
        if ($var == $pref_active) {
            $wpdb->query("DROP TABLE IF EXISTS $pref_previous");
            $wpdb->query("CREATE TABLE $pref_previous LIKE $pref_active");
            $wpdb->query("INSERT INTO $pref_previous SELECT * FROM $pref_active");
        }
    }

    // ── GL EVENTS: read registrants from gl_registrations ────────────────────
    $entries = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id
         FROM {$wpdb->prefix}gl_registrations
         WHERE occurrence_id = %d AND status = 'confirmed'",
        $event
    ) );
    // ─────────────────────────────────────────────────────────────────────────

    $totalentries = count($entries);

    $active_courts = $wpdb->get_results("SELECT Crt_ID FROM Courts WHERE active = 1");
    shuffle($active_courts);
    $num_crts = count($active_courts);

    $active_times = $wpdb->get_results("SELECT T_ID FROM Times WHERE Active = 1 ORDER BY T_ID");
    $num_times = count($active_times);

    $time_ids = array_column($active_times, 'T_ID');
    sort($time_ids);

    $preferred_users = $wpdb->get_col("SELECT user_id FROM $pref_active");
    $valid_preferred_users = array_intersect($entries, $preferred_users);

    $groupings = $table_name = $wpdb->prefix . "GpsCrtsTms54_3_" . $event;

    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    $wpdb->query("
        CREATE TABLE $table_name ( 
            Sequence INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            Crt_ID INT NOT NULL,
            time_id INT NOT NULL,
            user_id INT NOT NULL,
            first_name varchar(30) NOT NULL,
            Rank INT NOT NULL,
            event_id INT NOT NULL,
            registration_date DATE NOT NULL,
            travel varchar(100)
        )
    ");

    $remaining_entries = array_diff($entries, $valid_preferred_users);
    shuffle($remaining_entries);

    $max_slots = $num_crts * $num_times;
    $max_registrants = $max_slots * 5;
    $total_registrants = min($totalentries, $max_registrants);
    $groups_needed = min($max_slots, (int)floor($total_registrants / 4));
    $total_registrants = min($total_registrants, $groups_needed * 5);
    $extra_registrants = ($groups_needed * 5) - $total_registrants;

    // Ensure Groups table has enough entries for all groups needed
    $existing_groups = $wpdb->get_col("SELECT GP_ID FROM Groups WHERE GP_ID != 99");
    $max_existing = count($existing_groups) > 0 ? max($existing_groups) : 0;
    if ($groups_needed > $max_existing) {
        for ($g = $max_existing + 1; $g <= $groups_needed; $g++) {
            $wpdb->insert('Groups', [
                'GP_ID'   => $g,
                'GP_name' => 'Group ' . str_pad($g, 2, '0', STR_PAD_LEFT)
            ]);
        }
        echo "Added " . ($groups_needed - $max_existing) . " new group(s) to Groups table.<br>";
    }

    $needed = $total_registrants - count($valid_preferred_users);
    $randomly_selected_users = array_slice($remaining_entries, 0, $needed);

    $all_selected = array_merge($valid_preferred_users, $randomly_selected_users);
    $ranked_selected = [];
    foreach ($all_selected as $uid) {
        $rank = $wpdb->get_var($wpdb->prepare("SELECT Rank FROM $Master WHERE user_id = %d", $uid));
        $ranked_selected[] = ['user_id' => $uid, 'rank' => (int)$rank];
    }
    usort($ranked_selected, function($a, $b) { return $a['rank'] - $b['rank']; });
    $selected_registrants = array_column($ranked_selected, 'user_id');

    $wpdb->query("DROP TABLE IF EXISTS $pref_temp");
    $wpdb->query("CREATE TABLE $pref_temp LIKE $pref_permanent");

    // -------------------------------------------------------
    // HELPER FUNCTIONS
    // -------------------------------------------------------

    $normalize_travel = function($travel) {
        if (empty($travel)) return '';
        $t = trim($travel);
        $t = preg_replace('/^-\s+(5:30|6:40|7:50)/i', '-$1', $t);
        $t = preg_replace('/^([+-]?)(5:30|6:40|7:50)\s*pm\b/i', '$1$2', $t);
        $t = preg_replace('/^(-)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t);
        $t = preg_replace('/^(\+)(5:30|6:40|7:50)([A-Za-z]+)/i', '$1$2 $3', $t);
        if (preg_match('/^(5:30|6:40|7:50)(\s+\S+)?$/i', $t)) {
            if ($t[0] !== '+' && $t[0] !== '-') {
                $t = '+' . $t;
            }
        }
        return $t;
    };

    $extract_carpool = function($travel) use ($normalize_travel) {
        $travel = $normalize_travel($travel);
        if (empty($travel)) return '';
        $cleaned = preg_replace('/^[+-]?(5:30|6:40|7:50)\s*/i', '', $travel);
        $cleaned = ltrim($cleaned, '+-');
        return strtolower(trim($cleaned));
    };

    $is_priority_carpool = function($travel) use ($normalize_travel) {
        $travel = $normalize_travel($travel);
        if (empty($travel)) return false;
        $cleaned = preg_replace('/^[+-]?(5:30|6:40|7:50)\s*/i', '', $travel);
        return $travel[0] === '+' && !empty(trim($cleaned, '+-'));
    };

    $carpool_key = function($name) {
        return ltrim(trim($name), '+');
    };

    $group_avg_rank = function($gid) use (&$players_per_group, &$player_ranks) {
        $ranks = array_map(fn($uid) => $player_ranks[$uid] ?? 50, $players_per_group[$gid]);
        return count($ranks) > 0 ? array_sum($ranks) / count($ranks) : 50;
    };

    $has_travel_conflict = function($uid, $time_id) use ($wpdb, $Master, $time_ids, $normalize_travel) {
        $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
        $travel = $normalize_travel($raw);
        if (empty($travel)) return false;
        $t_pos = array_search($time_id, $time_ids);
        if ($t_pos === 0 && preg_match('/^-5:30/i', $travel)) return true;
        if ($t_pos === 2 && preg_match('/^-7:50/i', $travel)) return true;
        if ($t_pos === 1 && preg_match('/^-6:40/i', $travel)) return true;
        return false;
    };

    $get_carpool_name = function($uid) use ($wpdb, $Master, $extract_carpool, $carpool_key) {
        $travel = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
        return $carpool_key($extract_carpool($travel));
    };

    $effective_tolerance = function($rank) use ($carpool_rank_tolerance) {
        if ($rank <= 20) return (int)round($carpool_rank_tolerance * 2 / 3);
        if ($rank <= 50) return $carpool_rank_tolerance;
        return (int)round($carpool_rank_tolerance * 4 / 3);
    };

    // -------------------------------------------------------
    // PREFERRED_NEW POPULATION — carpool-pair aware
    // -------------------------------------------------------
    $not_selected_users = array_diff($remaining_entries, $randomly_selected_users);
    $playing_set        = array_flip($selected_registrants);

    $safe_for_preferred = [];
    $already_handled    = [];

    foreach ($not_selected_users as $uid) {
        if (isset($already_handled[$uid])) continue;

        $raw_travel  = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
        $cp_name     = $carpool_key($extract_carpool($raw_travel));

        if (empty($cp_name)) {
            $safe_for_preferred[] = $uid;
        } else {
            $entry_ids_str = implode(',', array_map('intval', $entries));
            $all_registrant_travels = $wpdb->get_results(
                "SELECT user_id, travel FROM $Master WHERE user_id IN ($entry_ids_str)",
                ARRAY_A
            );

            $partner_ids = [];
            foreach ($all_registrant_travels as $row) {
                if ($row['user_id'] == $uid) continue;
                $row_cp = $carpool_key($extract_carpool($row['travel']));
                if ($row_cp === $cp_name) {
                    $partner_ids[] = (int)$row['user_id'];
                }
            }

            $partner_playing = false;
            foreach ($partner_ids as $pid) {
                if (isset($playing_set[$pid])) { $partner_playing = true; break; }
            }

            if ($partner_playing) {
                $already_handled[$uid] = true;
            } else {
                $safe_for_preferred[] = $uid;
                foreach ($partner_ids as $pid) {
                    if (in_array($pid, $not_selected_users)) {
                        $safe_for_preferred[] = $pid;
                        $already_handled[$pid] = true;
                    }
                }
                $already_handled[$uid] = true;
            }
        }
    }

    $safe_for_preferred = array_unique($safe_for_preferred);

    if (!empty($safe_for_preferred)) {
        foreach ($safe_for_preferred as $spuid) {
            $spuid = (int)$spuid;
            $sp_rank  = (int)$wpdb->get_var("SELECT Rank FROM $Master WHERE user_id = $spuid");
            $sp_fn    = $wpdb->get_var("SELECT first_name FROM $Master WHERE user_id = $spuid");
            $sp_ln    = $wpdb->get_var("SELECT last_name FROM $Master WHERE user_id = $spuid");
            $sp_phone = $wpdb->get_var("SELECT user_phone FROM $Master WHERE user_id = $spuid");
            $sp_tr    = $wpdb->get_var("SELECT travel FROM $Master WHERE user_id = $spuid");
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $pref_temp (user_id, Rank, first_name, last_name, user_phone, travel)
                 VALUES (%d, %d, %s, %s, %s, %s)",
                $spuid,
                $sp_rank  ?: 99,
                $sp_fn    ?: '',
                $sp_ln    ?: '',
                $sp_phone ?: '',
                $sp_tr    ?: ''
            ));
        }
    }
    if (!empty($preferred)) {
        foreach ($preferred as $puid) {
            $puid = (int)$puid;
            $p_rank  = (int)$wpdb->get_var("SELECT Rank FROM $Master WHERE user_id = $puid");
            $p_fn    = $wpdb->get_var("SELECT first_name FROM $Master WHERE user_id = $puid");
            $p_ln    = $wpdb->get_var("SELECT last_name FROM $Master WHERE user_id = $puid");
            $p_phone = $wpdb->get_var("SELECT user_phone FROM $Master WHERE user_id = $puid");
            $p_tr    = $wpdb->get_var("SELECT travel FROM $Master WHERE user_id = $puid");
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $pref_temp (user_id, Rank, first_name, last_name, user_phone, travel)
                 VALUES (%d, %d, %s, %s, %s, %s)",
                $puid,
                $p_rank  ?: 99,
                $p_fn    ?: '',
                $p_ln    ?: '',
                $p_phone ?: '',
                $p_tr    ?: ''
            ));
        }
    }

    $result = $wpdb->get_results("SELECT m.user_id, m.Rank, m.first_name, m.last_name, m.user_phone, m.travel FROM $pref_permanent m", OBJECT);
    foreach ($result as $p) {
        $wpdb->query($wpdb->prepare(
            "UPDATE $pref_temp SET Rank = %d, first_name = %s, last_name = %s, user_phone = %s, travel = %s WHERE user_id = %d",
            $p->Rank, $p->first_name, $p->last_name, $p->user_phone, $p->travel, $p->user_id
        ));
    }

    $group_sizes = array_fill(0, $groups_needed, 5);
    for ($i = 0; $i < $extra_registrants; $i++) {
        $group_sizes[$i] = 4;
    }
    arsort($group_sizes);
    $group_sizes = array_values($group_sizes);

    // -------------------------------------------------------
    // PRE-ASSIGN PLAYERS TO GROUPS BY RANK ORDER
    // -------------------------------------------------------
    $player_ranks = [];
    foreach ($selected_registrants as $uid) {
        $player_ranks[$uid] = (int)$wpdb->get_var($wpdb->prepare("SELECT Rank FROM $Master WHERE user_id = %d", $uid));
    }

    $players_per_group = [];
    $temp_assigned = 0;
    for ($g = 0; $g < $groups_needed; $g++) {
        $gid = $g + 1;
        $players_per_group[$gid] = [];
        for ($i = 0; $i < $group_sizes[$g]; $i++) {
            if ($temp_assigned >= $total_registrants) break;
            $players_per_group[$gid][] = $selected_registrants[$temp_assigned];
            $temp_assigned++;
        }
    }

    // -------------------------------------------------------
    // BUILD CARPOOL MAPS
    // -------------------------------------------------------
    $priority_carpool_to_groups = [];
    $carpool_to_groups = [];
    foreach ($players_per_group as $gid => $players) {
        foreach ($players as $uid) {
            $travel = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
            $carpool = $extract_carpool($travel);
            if (!empty($carpool)) {
                $key = $carpool_key($carpool);
                if ($is_priority_carpool($travel)) {
                    $priority_carpool_to_groups[$key][$gid] = $gid;
                } else {
                    $carpool_to_groups[$key][$gid] = $gid;
                }
            }
        }
    }
    $priority_carpool_to_groups = array_filter($priority_carpool_to_groups, fn($g) => count($g) >= 2);
    $carpool_to_groups = array_filter($carpool_to_groups, fn($g) => count($g) >= 2);
    $all_carpools = $priority_carpool_to_groups + $carpool_to_groups;

    // -------------------------------------------------------
    // BASE TIME SLOT DISTRIBUTION — DEMAND-DRIVEN SEQUENTIAL
    // -------------------------------------------------------

    // Count + and - preferences per time slot
    $plus_demand = array_fill(0, $num_times, 0);
    $minus_demand = array_fill(0, $num_times, 0);

    foreach ($selected_registrants as $uid) {
        $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
        $travel = $normalize_travel($raw);
        if (empty($travel)) continue;
        if (preg_match('/^\+5:30/i', $travel)) $plus_demand[0]++;
        elseif (preg_match('/^\+6:40/i', $travel)) $plus_demand[1]++;
        elseif (preg_match('/^\+7:50/i', $travel)) $plus_demand[2]++;
        if (preg_match('/^-5:30/i', $travel)) $minus_demand[0]++;
        elseif (preg_match('/^-6:40/i', $travel)) $minus_demand[1]++;
        elseif (preg_match('/^-7:50/i', $travel)) $minus_demand[2]++;
    }

    // Minimum groups per slot: enough to hold + players (groups of 4-5)
    $counts = array_fill(0, $num_times, 0);
    $remaining_groups = $groups_needed;

    for ($i = 0; $i < $num_times; $i++) {
        $min_needed = (int)ceil($plus_demand[$i] / 4);
        $min_needed = max($min_needed, 1);
        $min_needed = min($min_needed, $num_crts, $remaining_groups);
        $counts[$i] = $min_needed;
        $remaining_groups -= $min_needed;
    }

    // Ensure slots receiving - players from other slots have room
    // Players with -X must go somewhere else, so other slots need capacity
    for ($i = 0; $i < $num_times; $i++) {
        if ($minus_demand[$i] > 0) {
            $displaced = (int)ceil($minus_demand[$i] / 4);
            for ($j = 0; $j < $num_times; $j++) {
                if ($j === $i) continue;
                if ($remaining_groups <= 0) break;
                $can_add = $num_crts - $counts[$j];
                $to_add = min($displaced, $can_add, $remaining_groups);
                if ($to_add > 0) {
                    $counts[$j] += $to_add;
                    $remaining_groups -= $to_add;
                    $displaced -= $to_add;
                }
                if ($displaced <= 0) break;
            }
        }
    }
    // Fill remaining groups: prioritize slots receiving displaced - players,
    // then distribute any leftover evenly across slots with room
    if ($remaining_groups > 0) {
        $receive_weight = array_fill(0, $num_times, 0);
        for ($i = 0; $i < $num_times; $i++) {
            if ($minus_demand[$i] > 0) {
                for ($j = 0; $j < $num_times; $j++) {
                    if ($j === $i) continue;
                    $receive_weight[$j] += $minus_demand[$i];
                }
            }
        }

        // Allocate proportionally to receive weight
        $total_weight = array_sum($receive_weight);
        if ($total_weight > 0) {
            $weighted_alloc = array_fill(0, $num_times, 0);
            foreach ($receive_weight as $i => $w) {
                if ($w <= 0) continue;
                $share = (int)round($remaining_groups * $w / $total_weight);
                $can_add = $num_crts - $counts[$i];
                $to_add = min($share, $can_add, $remaining_groups);
                if ($to_add > 0) {
                    $counts[$i] += $to_add;
                    $remaining_groups -= $to_add;
                }
            }
        }

        // Any leftover: distribute evenly to slots with room
        while ($remaining_groups > 0) {
            $added_any = false;
            for ($i = 0; $i < $num_times; $i++) {
                if ($remaining_groups <= 0) break;
                if ($counts[$i] < $num_crts) {
                    $counts[$i]++;
                    $remaining_groups--;
                    $added_any = true;
                }
            }
            if (!$added_any) break;
        }
    }



    echo "Distribution (demand-driven): " . implode(', ', $counts);
    echo " | +demand: " . implode(',', $plus_demand);
    echo " | -demand: " . implode(',', $minus_demand) . "<br>";

    $time_assignments = [];
    $t_index = 0;
    foreach ($active_times as $time) {
        for ($g = 0; $g < $counts[$t_index]; $g++) {
            $time_assignments[] = $time->T_ID;
        }
        $t_index++;
    }
    shuffle($time_assignments);

    $group_time_map = [];
    for ($g = 0; $g < $groups_needed; $g++) {
        $group_time_map[$g + 1] = $time_assignments[$g];
    }

    $time_slot_counts = [];
    foreach ($active_times as $time) { $time_slot_counts[$time->T_ID] = 0; }
    foreach ($group_time_map as $tid) { $time_slot_counts[$tid]++; }

    // -------------------------------------------------------
    // CARPOOL ADJUSTMENTS — PASS 1
    // -------------------------------------------------------
    $run_pass1 = function($carpools, $label) use (&$group_time_map, &$time_slot_counts, $active_times, $num_crts) {
        foreach ($carpools as $carpool_name => $gids) {
            $gids = array_values($gids);
            $time_votes = [];
            foreach ($gids as $gid) {
                $t = $group_time_map[$gid];
                $time_votes[$t] = ($time_votes[$t] ?? 0) + 1;
            }
            arsort($time_votes);

            $target_time = null;
            foreach ($time_votes as $t => $votes) {
                if ($time_slot_counts[$t] < $num_crts) { $target_time = $t; break; }
            }
            if (!$target_time) {
                foreach ($active_times as $time) {
                    if ($time_slot_counts[$time->T_ID] < $num_crts) { $target_time = $time->T_ID; break; }
                }
            }
            if (!$target_time) continue;

            foreach ($gids as $gid) {
                if ($group_time_map[$gid] != $target_time) {
                    if ($time_slot_counts[$target_time] < $num_crts) {
                        $old_time = $group_time_map[$gid];
                        $group_time_map[$gid] = $target_time;
                        $time_slot_counts[$target_time]++;
                        $time_slot_counts[$old_time]--;
                        echo "✓ {$label}Carpool '$carpool_name': Group $gid moved to time slot $target_time.<br>";
                    }
                }
            }
        }
    };

    $run_pass1($priority_carpool_to_groups, '[Priority] ');
    $run_pass1($carpool_to_groups, '');

    // -------------------------------------------------------
    // CARPOOL ADJUSTMENTS — PASS 1.5
    // -------------------------------------------------------
    $carpool_group_lookup = [];
    foreach ($players_per_group as $gid => $players) {
        foreach ($players as $uid) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
            $cp = $carpool_key($extract_carpool($raw));
            if (!empty($cp)) {
                $carpool_group_lookup[$cp][] = $gid;
            }
        }
    }

    // Pass 1.5 handles all + preferences: +5:30, +6:40, +7:50
    $pref_patterns = array(
        array('pattern' => '/^\+5:30/i', 'neg_pattern' => '/^-5:30/i', 'pos' => 0, 'label' => '+5:30', 'time_label' => '5:30pm'),
        array('pattern' => '/^\+6:40/i', 'neg_pattern' => '/^-6:40/i', 'pos' => 1, 'label' => '+6:40', 'time_label' => '6:40pm'),
        array('pattern' => '/^\+7:50/i', 'neg_pattern' => '/^-7:50/i', 'pos' => 2, 'label' => '+7:50', 'time_label' => '7:50pm'),
    );

    foreach ($pref_patterns as $pref) {
        foreach ($players_per_group as $gid => $players) {
            $has_pref = false;
            foreach ($players as $uid) {
                $raw    = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
                $travel = $normalize_travel($raw);
                if (preg_match($pref['pattern'], $travel)) { $has_pref = true; break; }
            }
            if (!$has_pref) continue;
            if ($group_time_map[$gid] === $time_ids[$pref['pos']]) continue;

            $incoming_time = $group_time_map[$gid];

            $swapped = false;
            foreach ($group_time_map as $other_gid => $other_tid) {
                if ($other_gid === $gid) continue;
                if ($other_tid !== $time_ids[$pref['pos']]) continue;

                $has_conflict = false;
                foreach ($players_per_group[$other_gid] as $uid) {
                    $raw    = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
                    $travel = $normalize_travel($raw);
                    if (preg_match($pref['neg_pattern'], $travel)) { $has_conflict = true; break; }
                }
                if ($has_conflict) continue;

                $carpool_broken = false;
                foreach ($players_per_group[$other_gid] as $uid) {
                    $raw    = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
                    $travel = $normalize_travel($raw);
                    if (!preg_match($pref['pattern'], $travel)) continue;
                    $cp = $carpool_key($extract_carpool($raw));
                    if (empty($cp)) continue;
                    $partner_gids = $carpool_group_lookup[$cp] ?? array();
                    foreach ($partner_gids as $partner_gid) {
                        if ($partner_gid === $other_gid) continue;
                        $partner_time  = $group_time_map[$partner_gid];
                        $pos_incoming  = array_search($incoming_time, $time_ids);
                        $pos_partner   = array_search($partner_time, $time_ids);
                        if (abs($pos_incoming - $pos_partner) > 1) {
                            $carpool_broken = true;
                            break 2;
                        }
                    }
                }
                if ($carpool_broken) continue;

                $group_time_map[$gid]       = $time_ids[$pref['pos']];
                $group_time_map[$other_gid] = $incoming_time;
                echo "✓ [{$pref['label']}] Group $gid moved to {$pref['time_label']} (swapped with Group $other_gid).<br>";
                $swapped = true;
                break;
            }
        }
    }

    // -------------------------------------------------------
    // CARPOOL ADJUSTMENTS — PASS 2
    // -------------------------------------------------------
    foreach ($all_carpools as $carpool_name => $gids) {
        $gids = array_values($gids);
        for ($i = 0; $i < count($gids); $i++) {
            for ($j = $i + 1; $j < count($gids); $j++) {
                $gid1 = $gids[$i];
                $gid2 = $gids[$j];
                $pos1 = array_search($group_time_map[$gid1], $time_ids);
                $pos2 = array_search($group_time_map[$gid2], $time_ids);
                $gap = abs($pos1 - $pos2);
                if ($gap > 1) {
                    $middle_time = $time_ids[min($pos1, $pos2) + 1];
                    if ($time_slot_counts[$middle_time] < $num_crts) {
                        $old_time = $group_time_map[$gid2];
                        $group_time_map[$gid2] = $middle_time;
                        $time_slot_counts[$middle_time]++;
                        $time_slot_counts[$old_time]--;
                        echo "✓ Carpool '$carpool_name': Group $gid2 moved to time slot $middle_time (max 1 slot apart).<br>";
                    } elseif ($time_slot_counts[$middle_time] < $num_crts) {
                        $old_time = $group_time_map[$gid1];
                        $group_time_map[$gid1] = $middle_time;
                        $time_slot_counts[$middle_time]++;
                        $time_slot_counts[$old_time]--;
                        echo "✓ Carpool '$carpool_name': Group $gid1 moved to time slot $middle_time (max 1 slot apart).<br>";
                    }
                }
            }
        }
    }

    // -------------------------------------------------------
    // CARPOOL ADJUSTMENTS — PASS 3
    // -------------------------------------------------------
    $try_cascade_move = function(
        $player, $src_gid, $target_time, $strict = false
    ) use (
        &$players_per_group, &$group_time_map, &$player_ranks,
        $group_avg_rank, $has_travel_conflict, $get_carpool_name,
        $carpool_rank_tolerance, $effective_tolerance, $time_ids
    ) {
        $moves = [];
        $max_cascade = 10;
        $cascade_count = 0;
        $current_player = $player;
        $current_src = $src_gid;
        $current_target_time = $target_time;

        while ($cascade_count < $max_cascade) {
            $cascade_count++;
            $player_rank = $player_ranks[$current_player] ?? 50;
            $best_target = null;
            $best_diff = PHP_INT_MAX;

            $time_order = $strict ? [$current_target_time] : array_merge(
                [$current_target_time],
                array_unique(array_diff(array_values($group_time_map), [$current_target_time]))
            );

            foreach ($time_order as $pref_time) {
                foreach ($players_per_group as $gid => $gplayers) {
                    if ($gid === $current_src) continue;
                    if ($group_time_map[$gid] !== $pref_time) continue;
                    if ($has_travel_conflict($current_player, $pref_time)) continue;
                    $avg = (float)$group_avg_rank($gid);
                    $diff = abs($avg - $player_rank);
                    if ($diff <= $effective_tolerance($player_rank) && $diff < $best_diff) {
                        $best_diff = $diff;
                        $best_target = $gid;
                    }
                }
                if ($best_target) break;
            }

            if (!$best_target) return [];

            $src_size = count($players_per_group[$current_src]);
            $tgt_size = count($players_per_group[$best_target]);

            if ($src_size >= 5 && $tgt_size <= 4) {
                $players_per_group[$current_src] = array_values(array_diff($players_per_group[$current_src], [$current_player]));
                $players_per_group[$best_target][] = $current_player;
                $moves[] = ['type' => 'move', 'player' => $current_player, 'from' => $current_src, 'to' => $best_target];
                return $moves;
            } elseif ($src_size >= 4 && $tgt_size <= 4) {
                $src_avg = (float)$group_avg_rank($current_src);
                $src_time = $group_time_map[$current_src];
                $best_swap = null;
                $best_swap_diff = PHP_INT_MAX;
                foreach ($players_per_group[$best_target] as $uid) {
                    if (!empty($get_carpool_name($uid))) continue;
                    if ($has_travel_conflict($uid, $src_time)) continue;
                    $uid_rank = $player_ranks[$uid] ?? 50;
                    $sd = abs($uid_rank - $src_avg);
                    if ($sd < $best_swap_diff) { $best_swap_diff = $sd; $best_swap = $uid; }
                }
                if (!$best_swap) return [];
                $pos_player = array_search($current_player, $players_per_group[$current_src]);
                $pos_swap = array_search($best_swap, $players_per_group[$best_target]);
                $players_per_group[$current_src][$pos_player] = $best_swap;
                $players_per_group[$best_target][$pos_swap] = $current_player;
                $moves[] = ['type' => 'swap', 'player' => $current_player, 'swap_player' => $best_swap, 'from' => $current_src, 'to' => $best_target];
                return $moves;
            } elseif ($tgt_size >= 5) {
                if ($src_size <= 4) return [];
                $moving_down = $player_rank > (float)$group_avg_rank($best_target);
                $cascade_candidate = null;
                $cascade_rank = $moving_down ? PHP_INT_MAX : -1;
                foreach ($players_per_group[$best_target] as $uid) {
                    if (!empty($get_carpool_name($uid))) continue;
                    $uid_rank = $player_ranks[$uid] ?? 50;
                    if ($moving_down && $uid_rank < $cascade_rank) { $cascade_rank = $uid_rank; $cascade_candidate = $uid; }
                    elseif (!$moving_down && $uid_rank > $cascade_rank) { $cascade_rank = $uid_rank; $cascade_candidate = $uid; }
                }
                if (!$cascade_candidate) return [];
                $players_per_group[$current_src] = array_values(array_diff($players_per_group[$current_src], [$current_player]));
                $players_per_group[$best_target] = array_values(array_diff($players_per_group[$best_target], [$cascade_candidate]));
                $players_per_group[$best_target][] = $current_player;
                $players_per_group[$current_src][] = $cascade_candidate;
                $moves[] = ['type' => 'cascade', 'player' => $current_player, 'from' => $current_src, 'to' => $best_target, 'cascade' => $cascade_candidate];
                $current_player = $cascade_candidate;
                $current_target_time = $group_time_map[$best_target];
            } else {
                return [];
            }
        }
        return [];
    };

    foreach ($all_carpools as $carpool_name => $gids) {
        $is_priority = isset($priority_carpool_to_groups[$carpool_name]);
        $gids = array_values($gids);
        for ($i = 0; $i < count($gids); $i++) {
            for ($j = $i + 1; $j < count($gids); $j++) {
                $gid1 = $gids[$i];
                $gid2 = $gids[$j];
                $t1 = $group_time_map[$gid1];
                $t2 = $group_time_map[$gid2];
                if ($t1 === $t2) continue;
                $pos1 = array_search($t1, $time_ids);
                $pos2 = array_search($t2, $time_ids);
                $gap = abs($pos1 - $pos2);
                if (!$is_priority && $gap <= 1) continue;

                $label = $is_priority ? '[Priority] ' : '';

                $cp_in_gid1 = null;
                $cp_in_gid2 = null;
                foreach ($players_per_group[$gid1] as $uid) {
                    if ($get_carpool_name($uid) === $carpool_name) { $cp_in_gid1 = $uid; break; }
                }
                foreach ($players_per_group[$gid2] as $uid) {
                    if ($get_carpool_name($uid) === $carpool_name) { $cp_in_gid2 = $uid; break; }
                }

                $resolved = false;

                if ($cp_in_gid2) {
                    $moves = $try_cascade_move($cp_in_gid2, $gid2, $t1, $is_priority);
                    if (!empty($moves)) {
                        foreach ($moves as $m) {
                            $pr = $player_ranks[$m['player']] ?? '?';
                            if ($m['type'] === 'move') {
                                echo "✓ {$label}Carpool '$carpool_name': Moved player (rank $pr) from Group {$m['from']} to Group {$m['to']}.<br>";
                            } elseif ($m['type'] === 'swap') {
                                $swap_rank = $player_ranks[$m['swap_player']];
                                echo "✓ {$label}Carpool '$carpool_name': Swapped player (rank $pr) into Group {$m['to']} — swapped out rank {$swap_rank}.<br>";
                            } elseif ($m['type'] === 'cascade') {
                                $cascade_rank = $player_ranks[$m['cascade']];
                                echo "✓ {$label}Carpool '$carpool_name': Moved player (rank $pr) to Group {$m['to']} — cascaded rank {$cascade_rank}.<br>";
                            }
                        }
                        $resolved = true;
                    }
                }

                if (!$resolved && $cp_in_gid1) {
                    $moves = $try_cascade_move($cp_in_gid1, $gid1, $t2, $is_priority);
                    if (!empty($moves)) {
                        foreach ($moves as $m) {
                            $pr = $player_ranks[$m['player']] ?? '?';
                            if ($m['type'] === 'move') {
                                echo "✓ {$label}Carpool '$carpool_name': Moved player (rank $pr) from Group {$m['from']} to Group {$m['to']}.<br>";
                            } elseif ($m['type'] === 'swap') {
                                $swap_rank = $player_ranks[$m['swap_player']];
                                echo "✓ {$label}Carpool '$carpool_name': Swapped player (rank $pr) into Group {$m['to']} — swapped out rank {$swap_rank}.<br>";
                            } elseif ($m['type'] === 'cascade') {
                                $cascade_rank = $player_ranks[$m['cascade']];
                                echo "✓ {$label}Carpool '$carpool_name': Moved player (rank $pr) to Group {$m['to']} — cascaded rank {$cascade_rank}.<br>";
                            }
                        }
                        $resolved = true;
                    }
                }
            }
        }
    }

    // -------------------------------------------------------
    // BUILD COURT ASSIGNMENTS AND INSERT INTO GROUPINGS TABLE
    // -------------------------------------------------------
    $time_group_counts = [];
    foreach ($active_times as $time) { $time_group_counts[$time->T_ID] = 0; }
    foreach ($group_time_map as $gid => $tid) { $time_group_counts[$tid]++; }

    $court_assignments = [];
    foreach ($active_times as $time) {
        $count = $time_group_counts[$time->T_ID];
        $courts_for_slot = array_slice($active_courts, 0, $count);
        shuffle($courts_for_slot);
        $court_assignments[$time->T_ID] = $courts_for_slot;
    }
    $court_index = [];
    foreach ($active_times as $time) { $court_index[$time->T_ID] = 0; }

    $dist_summary = [];
    foreach ($active_times as $time) { $dist_summary[] = $time_group_counts[$time->T_ID]; }

    echo "<br>Groups: $groups_needed | Registrants: $total_registrants | Available slots: $max_slots<br>";
    echo "Distribution across time slots: " . implode(', ', $dist_summary) . "<br>";

    foreach ($players_per_group as $group_id => $players) {
        $time_id = $group_time_map[$group_id];
        $court = $court_assignments[$time_id][$court_index[$time_id]];
        $court_index[$time_id]++;

        foreach ($players as $user_id) {
            $pref_user = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $pref_active WHERE user_id = %d", $user_id));
            if ($user_id == $pref_user) {
                $first_name = "P-" . $wpdb->get_var($wpdb->prepare("SELECT first_name FROM $Master WHERE user_id = %d", $user_id));
            } else {
                $first_name = $wpdb->get_var($wpdb->prepare("SELECT first_name FROM $Master WHERE user_id = %d", $user_id));
            }
            $user_rank = $player_ranks[$user_id] ?? $wpdb->get_var($wpdb->prepare("SELECT Rank FROM $Master WHERE user_id = %d", $user_id));
            $travel = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $user_id));

            // ── GL EVENTS: get registration date from gl_registrations ────────
            $registration_date = $wpdb->get_var( $wpdb->prepare(
                "SELECT DATE(registered_at)
                 FROM {$wpdb->prefix}gl_registrations
                 WHERE user_id = %d AND occurrence_id = %d AND status = 'confirmed'",
                $user_id, $event
            ) );
            if ( ! $registration_date ) $registration_date = date('Y-m-d');
            // ─────────────────────────────────────────────────────────────────

            $wpdb->insert($table_name, [
                'group_id'          => $group_id,
                'time_id'           => $time_id,
                'Crt_ID'            => $court->Crt_ID,
                'user_id'           => $user_id,
                'first_name'        => $first_name,
                'Rank'              => $user_rank,
                'event_id'          => $event,
                'registration_date' => $registration_date,
                'travel'            => $travel
            ]);
        }
    }

    // Display preferred_new table
    $table_name_pref = $pref_temp;
    $columns = $wpdb->get_col("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name_pref'
    ");
    if (!empty($preferred)) {
        $placeholders = implode(',', array_fill(0, count($preferred), '%d'));
        $query = $wpdb->prepare("SELECT * FROM $table_name_pref WHERE user_id NOT IN ($placeholders) ORDER BY Rank", ...$preferred);
        $pref_results = $wpdb->get_results($query);
    } else {
        $pref_results = $wpdb->get_results("SELECT * FROM $table_name_pref ORDER BY Rank");
    }
    if (empty($columns)) {
        echo "<p>No preferred table created in this schedule.</p>";
    } elseif (empty($pref_results)) {
        echo "<p>No players added to the preferred list for next week.</p>";
    } else {
        $preferred_count = count($pref_results);
        echo "<p>$preferred_count player(s) added to the preferred list for next week.</p>";
        ?>
        <style>
            .preferred-table-container { max-width:1000px;margin:40px auto;padding:20px;font-family:Arial,sans-serif;background:#ffffff;border-radius:10px;box-shadow:0 5px 15px rgba(0,0,0,0.1); }
            .preferred-table { width:100%;border-collapse:collapse; }
            .preferred-table th,.preferred-table td { padding:12px 16px;border:1px solid #ccc;text-align:left; }
            .preferred-table th { background-color:#0073aa;color:white !important; }
            .preferred-table tr:nth-child(even) { background-color:#f9f9f9; }
            .preferred-table tr:hover { background-color:#e6f7ff; }
        </style>
        <div class="preferred-table-container">
            <h2>Preferred Table without "permanent preferred" to be used in next week's Ladder</h2>
            <table class="preferred-table">
                <thead><tr><?php foreach ($columns as $col): ?><th><?php echo esc_html($col); ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($pref_results as $row): ?><tr><?php foreach ($columns as $col): ?><td><?php echo esc_html($row->$col); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php
    }

    // -------------------------------------------------------
    // CREATE SCHEDULES TABLE FROM GROUPINGS
    // -------------------------------------------------------
    $table = "Schedules3";
    $wpdb->query("DROP TABLE IF EXISTS $table");
    $wpdb->query("
        CREATE TABLE $table AS (
            SELECT 
                r.registration_date,
                r.Rank,
                r.user_id,
                r.first_name,
                m.last_name,
                m.user_phone,
                m.user_email,
                r.event_id,
                r.travel,
                0 AS Score
            FROM $groupings AS r
            LEFT OUTER JOIN $master AS m ON r.user_id = m.user_id
            ORDER BY r.Rank
        )
    ");
    $wpdb->query("ALTER TABLE $table ADD COLUMN Sequence int(10) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST");

    $wpdb->query("DROP TABLE IF EXISTS tmp");
    $wpdb->query("
        CREATE TABLE tmp AS (
            SELECT
                t.Sequence,
                t.registration_date,
                t.Rank,
                t.user_id,
                t.first_name,
                t.last_name,
                t.user_phone,
                t.user_email,
                t.event_id,
                null AS Game1,
                null AS Game2,
                null AS Game3,
                null AS Game4,
                null AS Game5,
                (0) AS Score,
                g.group_id AS group_id,
                g.Crt_ID AS Crt_ID,
                g.time_id AS time_id,
                t.travel AS travel
            FROM $table t
            INNER JOIN $groupings AS g ON t.user_id = g.user_id
            ORDER BY t.Rank, g.time_id, g.Crt_ID
        )
        UNION
        (SELECT
            9999 AS Sequence,
            t3.registration_date,
            t2.Rank,
            t3.user_id,
            t2.first_name,
            t2.last_name,
            t2.user_phone,
            t2.user_email,
            t3.event_id,
            null AS Game1,
            null AS Game2,
            null AS Game3,
            null AS Game4,
            null AS Game5,
            (0) AS Score,
            99 AS group_id,
            99 AS Crt_ID,
            99 AS time_id,
            t2.travel AS travel
        FROM $groupings t3
        INNER JOIN $master t2 ON t3.user_id = t2.user_id
            AND t3.event_id = $Event
            AND t3.registration_date > '$ctoffdt'
        )
    ");

    $wpdb->query("ALTER TABLE tmp MODIFY COLUMN Rank smallint");
    $wpdb->query("DROP TABLE IF EXISTS $Schedules");
    $wpdb->query("RENAME TABLE tmp TO $Schedules");
    $wpdb->query("ALTER TABLE $Schedules ADD PRIMARY KEY(user_id)");
    $wpdb->query("ALTER TABLE $Schedules DROP Score");
    $wpdb->query("ALTER TABLE $Schedules ADD Score int GENERATED ALWAYS AS (Game1+Game2+Game3+Game4+Game5)");
    $wpdb->query("ALTER TABLE $Schedules ADD RankPrime varchar(3)");
    $wpdb->query("UPDATE $Schedules SET RankPrime = Rank");
    for ($x = 1; $x <= 5; $x++) {
        $wpdb->query("ALTER TABLE $Schedules MODIFY Game$x SMALLINT");
    }

    $wpdb->query("DROP TABLE IF EXISTS SchedulesPreTravel");
    $wpdb->query("CREATE TABLE SchedulesPreTravel SELECT * FROM $Schedules");

    // -------------------------------------------------------
    // POST-PROCESSING — ITERATIVE IMPROVEMENT
    // -------------------------------------------------------

    $score_violation = function($uid, $time_id) use ($normalize_travel, $wpdb, $Master, $time_ids) {
        $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
        $travel = $normalize_travel($raw);
        if (empty($travel)) return 0;
        $t_pos = array_search($time_id, $time_ids);
        if ($t_pos === 0 && preg_match('/^-5:30/i', $travel)) return 100;
        if ($t_pos === 2 && preg_match('/^-7:50/i', $travel)) return 100;
        if ($t_pos === 1 && preg_match('/^-6:40/i', $travel)) return 100;
        if ($t_pos !== 0 && preg_match('/^\+5:30/i', $travel)) return 50;
        if ($t_pos !== 1 && preg_match('/^\+6:40/i', $travel)) return 50;
        if ($t_pos !== 2 && preg_match('/^\+7:50/i', $travel)) return 50;
        return 0;
    };

    $group_violation_score = function($group_id) use ($wpdb, $Schedules, $score_violation) {
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, time_id FROM $Schedules WHERE group_id = %d AND group_id != 99",
            $group_id
        ), ARRAY_A);
        $score = 0;
        foreach ($players as $p) {
            $score += $score_violation($p['user_id'], $p['time_id']);
        }
        return $score;
    };

    $group_violation_score_at = function($group_id, $new_time) use ($wpdb, $Schedules, $score_violation) {
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM $Schedules WHERE group_id = %d AND group_id != 99",
            $group_id
        ), ARRAY_A);
        $score = 0;
        foreach ($players as $p) {
            $score += $score_violation($p['user_id'], $new_time);
        }
        return $score;
    };

    $carpool_score = function() use ($wpdb, $Schedules, $normalize_travel, $Master, $time_ids, $extract_carpool, $carpool_key, $is_priority_carpool) {
        $players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, m.travel 
             FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id 
             WHERE s.group_id != 99",
            ARRAY_A
        );
        $carpool_groups = [];
        foreach ($players as $p) {
            $travel = $normalize_travel($p['travel']);
            $cp = $carpool_key($extract_carpool($travel));
            if (!empty($cp)) {
                $carpool_groups[$cp][] = ['time_id' => $p['time_id'], 'priority' => $is_priority_carpool($travel)];
            }
        }
        $score = 0;
        foreach ($carpool_groups as $cp => $members) {
            if (count($members) < 2) continue;
            $is_pri = array_reduce($members, fn($c, $m) => $c || $m['priority'], false);
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $pos1 = array_search($members[$i]['time_id'], $time_ids);
                    $pos2 = array_search($members[$j]['time_id'], $time_ids);
                    $gap = abs($pos1 - $pos2);
                    if ($is_pri && $gap > 0) $score += 40;
                    elseif (!$is_pri && $gap > 1) $score += 20;
                }
            }
        }
        return $score;
    };

    $swap_groups_in_schedule = function($gp1, $gp2) use ($wpdb, $Schedules) {
        $info1 = $wpdb->get_row("SELECT Crt_ID, time_id FROM $Schedules WHERE group_id = $gp1 LIMIT 1", ARRAY_A);
        $info2 = $wpdb->get_row("SELECT Crt_ID, time_id FROM $Schedules WHERE group_id = $gp2 LIMIT 1", ARRAY_A);
        $wpdb->query("UPDATE $Schedules SET time_id = {$info2['time_id']}, Crt_ID = {$info2['Crt_ID']} WHERE group_id = $gp1");
        $wpdb->query("UPDATE $Schedules SET time_id = {$info1['time_id']}, Crt_ID = {$info1['Crt_ID']} WHERE group_id = $gp2");
    };

    $time_labels = [
        $time_ids[0] => '5:30pm',
        $time_ids[1] => '6:40pm',
        $time_ids[2] => '7:50pm',
    ];

    // -------------------------------------------------------
    // PHASE 1 — ITERATIVE GROUP SWAPS
    // -------------------------------------------------------
    echo "<br><strong>Post-processing Phase 1 — Iterative Group Swaps:</strong><br>";

    $max_group_passes = 3;
    $group_pass_improvements = 0;

    for ($pass = 1; $pass <= $max_group_passes; $pass++) {
        $pass_improved = false;
        $carpool_baseline = $carpool_score();

        $all_groups = $wpdb->get_col(
            "SELECT DISTINCT group_id FROM $Schedules WHERE group_id != 99 ORDER BY group_id"
        );

        foreach ($all_groups as $gp1) {
            $score1_before = $group_violation_score($gp1);
            if ($score1_before === 0) continue;

            $info1 = $wpdb->get_row("SELECT Crt_ID, time_id FROM $Schedules WHERE group_id = $gp1 LIMIT 1", ARRAY_A);
            $time1 = (int)$info1['time_id'];

            foreach ($all_groups as $gp2) {
                if ($gp2 == $gp1) continue;

                $info2 = $wpdb->get_row("SELECT Crt_ID, time_id FROM $Schedules WHERE group_id = $gp2 LIMIT 1", ARRAY_A);
                $time2 = (int)$info2['time_id'];
                if ($time1 === $time2) continue;

                $score2_before = $group_violation_score($gp2);
                $score1_after = $group_violation_score_at($gp1, $time2);
                $score2_after = $group_violation_score_at($gp2, $time1);

                $improvement = ($score1_before + $score2_before) - ($score1_after + $score2_after);
                if ($improvement <= 0) continue;

                $swap_groups_in_schedule($gp1, $gp2);
                $carpool_after = $carpool_score();

                if ($carpool_after > $carpool_baseline) {
                    $swap_groups_in_schedule($gp1, $gp2);
                    continue;
                }

                echo "✓ Pass $pass: Swapped Group $gp1 ({$time_labels[$time1]}→{$time_labels[$time2]}) with Group $gp2 ({$time_labels[$time2]}→{$time_labels[$time1]}).<br>";
                $pass_improved = true;
                $group_pass_improvements++;
                $time1 = $time2;
                break;
            }
        }

        if (!$pass_improved) {
            echo "✓ Pass $pass: No further group swap improvements found.<br>";
            break;
        }
    }

    if ($group_pass_improvements === 0) {
        echo "✓ No group swaps needed — schedule already optimal.<br>";
    }

    // -------------------------------------------------------
    // PHASE 2 — ITERATIVE PLAYER SWAPS
    // -------------------------------------------------------
    echo "<br><strong>Post-processing Phase 2 — Iterative Player Swaps:</strong><br>";

    $max_player_passes = 3;
    $player_pass_improvements = 0;

    for ($pass = 1; $pass <= $max_player_passes; $pass++) {
        $pass_improved = false;
        $carpool_baseline = $carpool_score();

        $violating_players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, s.Rank
             FROM $Schedules s
             WHERE s.group_id != 99
             ORDER BY s.Rank",
            ARRAY_A
        );

        foreach ($violating_players as $vp) {
            $uid1 = (int)$vp['user_id'];

            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT group_id, time_id FROM $Schedules WHERE user_id = %d",
                $uid1
            ), ARRAY_A);
            if (!$current) continue;
            $gid1 = (int)$current['group_id'];
            $tid1 = (int)$current['time_id'];

            $vscore1 = $score_violation($uid1, $tid1);
            if ($vscore1 === 0) continue;

            $gsize1 = (int)$wpdb->get_var("SELECT COUNT(*) FROM $Schedules WHERE group_id = $gid1 AND group_id != 99");

            $raw1 = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid1));
            $cp1 = $carpool_key($extract_carpool($raw1));

            $candidates = $wpdb->get_results(
                "SELECT s.user_id, s.group_id, s.time_id, s.Rank
                 FROM $Schedules s
                 WHERE s.group_id != 99 AND s.group_id != $gid1 AND s.user_id != $uid1
                 ORDER BY ABS(s.Rank - {$vp['Rank']})",
                ARRAY_A
            );

            foreach ($candidates as $cp) {
                $uid2 = (int)$cp['user_id'];
                $rank2 = (int)$cp['Rank'];

                $current2 = $wpdb->get_row($wpdb->prepare(
                    "SELECT group_id, time_id FROM $Schedules WHERE user_id = %d",
                    $uid2
                ), ARRAY_A);
                if (!$current2) continue;
                $gid2 = (int)$current2['group_id'];
                $tid2 = (int)$current2['time_id'];

                if ($gid2 === $gid1) continue;
                if ($tid1 === $tid2) continue;

                $gsize1_now = (int)$wpdb->get_var("SELECT COUNT(*) FROM $Schedules WHERE group_id = $gid1 AND group_id != 99");
                $gsize2_now = (int)$wpdb->get_var("SELECT COUNT(*) FROM $Schedules WHERE group_id = $gid2 AND group_id != 99");
                if ($gsize1_now < 4 || $gsize2_now < 4) continue;

                $rank_diff = abs($vp['Rank'] - $rank2);
                if ($rank_diff > $effective_tolerance($vp['Rank'])) continue;

                $raw2 = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid2));
                $cp2 = $carpool_key($extract_carpool($raw2));
                if (!empty($cp2)) continue;

                $vscore1_after = $score_violation($uid1, $tid2);
                $vscore2_after = $score_violation($uid2, $tid1);
                $vscore2_before = $score_violation($uid2, $tid2);

                $improvement = ($vscore1 + $vscore2_before) - ($vscore1_after + $vscore2_after);
                if ($improvement <= 0) continue;

                $carpool_before = $carpool_score();

                $crt1_orig = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $uid1");
                $crt2_orig = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $uid2");
                $crt_for_uid1 = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $gid2 AND user_id != $uid2 LIMIT 1");
                $crt_for_uid2 = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $gid1 AND user_id != $uid1 LIMIT 1");
                if (!$crt_for_uid1) $crt_for_uid1 = $crt2_orig;
                if (!$crt_for_uid2) $crt_for_uid2 = $crt1_orig;
                $wpdb->query("UPDATE $Schedules SET group_id = $gid2, time_id = $tid2, Crt_ID = $crt_for_uid1 WHERE user_id = $uid1");
                $wpdb->query("UPDATE $Schedules SET group_id = $gid1, time_id = $tid1, Crt_ID = $crt_for_uid2 WHERE user_id = $uid2");

                $carpool_after = $carpool_score();
                if ($carpool_after > $carpool_baseline) {
                    $wpdb->query("UPDATE $Schedules SET group_id = $gid1, time_id = $tid1, Crt_ID = $crt1_orig WHERE user_id = $uid1");
                    $wpdb->query("UPDATE $Schedules SET group_id = $gid2, time_id = $tid2, Crt_ID = $crt2_orig WHERE user_id = $uid2");
                    continue;
                }

                $rank1 = $vp['Rank'];
                $name1 = $wpdb->get_var($wpdb->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM $Master WHERE user_id = %d", $uid1));
                $name2 = $wpdb->get_var($wpdb->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM $Master WHERE user_id = %d", $uid2));
                echo "✓ Pass $pass: Swapped {$name1} (rank $rank1, user $uid1, Group $gid1, {$time_labels[$tid1]}→{$time_labels[$tid2]}) with {$name2} (rank $rank2, user $uid2, Group $gid2).<br>";
                $pass_improved = true;
                $player_pass_improvements++;
                break;
            }
        }

        if (!$pass_improved) {
            echo "✓ Pass $pass: No further player swap improvements found.<br>";
            break;
        }
    }

    if ($player_pass_improvements === 0) {
        echo "✓ No player swaps needed — schedule already optimal.<br>";
    }

    // -------------------------------------------------------
    // PHASE 3 — PREFERRED TABLE SWAP
    // -------------------------------------------------------
    echo "<br><strong>Post-processing Phase 3 - Preferred Table Swap:</strong><br>";

    $phase3_swaps = array();

    // ── GL EVENTS: Phase 3 candidates must be registered in gl_registrations ─
    $pref_candidates = $wpdb->get_results(
        "SELECT p.user_id, m.first_name, m.last_name, m.Rank, m.travel
         FROM $pref_temp p
         JOIN $Master m ON p.user_id = m.user_id
         WHERE m.Rank IS NOT NULL
         AND p.user_id NOT IN (SELECT user_id FROM $Schedules WHERE group_id != 99)
         AND p.user_id IN (
             SELECT user_id FROM {$wpdb->prefix}gl_registrations
             WHERE occurrence_id = $event AND status = 'confirmed'
         )
         ORDER BY m.Rank",
        ARRAY_A
    );
    // ─────────────────────────────────────────────────────────────────────────

    $pref_by_uid = array();
    foreach ($pref_candidates as $pc) {
        $pref_by_uid[(int)$pc['user_id']] = $pc;
    }

    $still_violating = $wpdb->get_results(
        "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank,
                m.first_name, m.last_name, m.travel
         FROM $Schedules s
         JOIN $Master m ON s.user_id = m.user_id
         WHERE s.group_id != 99
         ORDER BY s.Rank",
        ARRAY_A
    );

    $phase3_handled = array();

    foreach ($still_violating as $vp) {
        $uid1  = (int)$vp['user_id'];
        if (isset($phase3_handled[$uid1])) continue;

        $row1 = $wpdb->get_row("SELECT group_id, time_id, Crt_ID FROM $Schedules WHERE user_id = $uid1", ARRAY_A);
        if (!$row1) continue;

        $tid1  = (int)$row1['time_id'];
        $gid1  = (int)$row1['group_id'];
        $crt1  = (int)$row1['Crt_ID'];
        $rank1 = (int)$vp['Rank'];
        $fn1   = $vp['first_name'];
        $ln1   = $vp['last_name'];
        $tr1   = $vp['travel'];

        $vscore = $score_violation($uid1, $tid1);
        if ($vscore === 0) continue;

        $travel1    = $normalize_travel($tr1);
        $target_tid = null;
        if (preg_match('/^-5:30/i', $travel1)) {
            foreach ($time_ids as $tid) {
                if ($tid !== $time_ids[0]) { $target_tid = $tid; break; }
            }
        } elseif (preg_match('/^-7:50/i', $travel1)) {
            foreach (array_reverse($time_ids) as $tid) {
                if ($tid !== $time_ids[2]) { $target_tid = $tid; break; }
            }
        } elseif (preg_match('/^\+5:30/i', $travel1)) {
            $target_tid = $time_ids[0];
        }
        if (!$target_tid) continue;

        $cp_name1 = $carpool_key($extract_carpool($travel1));

        if (empty($cp_name1)) {
            $best_cand = null;
            $best_diff = PHP_INT_MAX;
            foreach ($pref_by_uid as $cuid => $cand) {
                if (isset($phase3_handled[$cuid])) continue;
                if ($has_travel_conflict($cuid, $tid1)) continue;
                $crank = (int)$cand['Rank'];
                $diff  = abs($crank - $rank1);
                if ($diff <= $effective_tolerance($rank1) && $diff < $best_diff) {
                    $best_diff = $diff;
                    $best_cand = $cand;
                }
            }

            if (!$best_cand) continue;

            $cuid   = (int)$best_cand['user_id'];
            $crank  = (int)$best_cand['Rank'];
            $ctravel= $best_cand['travel'];
            $cfn    = $wpdb->get_var("SELECT first_name FROM $Master WHERE user_id = $cuid");
            $cln    = $wpdb->get_var("SELECT last_name FROM $Master WHERE user_id = $cuid");
            $cname  = $cfn . ' ' . $cln;
            $vname  = $fn1 . ' ' . $ln1;

            $wpdb->query("UPDATE $Schedules SET group_id = 99, time_id = 99, Crt_ID = 99 WHERE user_id = $uid1");

            // ── GL EVENTS: get registration date from gl_registrations ────────
            $reg_row = $wpdb->get_var( $wpdb->prepare(
                "SELECT DATE(registered_at) FROM {$wpdb->prefix}gl_registrations
                 WHERE user_id = %d AND occurrence_id = %d AND status = 'confirmed'",
                $cuid, $event
            ) );
            $reg_date = $reg_row ? $reg_row : date('Y-m-d');
            // ─────────────────────────────────────────────────────────────────

            $wpdb->query("DELETE FROM $Schedules WHERE user_id = $cuid");
            $wpdb->insert($Schedules, array(
                'user_id'           => $cuid,
                'group_id'          => $gid1,
                'time_id'           => $tid1,
                'Crt_ID'            => $crt1,
                'Rank'              => $crank,
                'first_name'        => $cfn,
                'event_id'          => $event,
                'registration_date' => $reg_date,
                'travel'            => $ctravel,
            ));
            $wpdb->query("UPDATE $Schedules s JOIN $Master m ON s.user_id = m.user_id SET s.last_name = m.last_name, s.user_phone = m.user_phone, s.user_email = m.user_email WHERE s.user_id = $cuid");

            $wpdb->query("DELETE FROM $pref_temp WHERE user_id = $uid1");
            $wpdb->insert($pref_temp, array('user_id' => $uid1));
            $fn1_clean = preg_replace('/^P-/', '', $fn1);
            $wpdb->query($wpdb->prepare(
                "UPDATE $pref_temp SET Rank = %d, first_name = %s, last_name = %s, travel = %s WHERE user_id = %d",
                $rank1, $fn1_clean, $ln1, $tr1, $uid1
            ));

            $wpdb->query("DELETE FROM $pref_temp WHERE user_id = $cuid");
            unset($pref_by_uid[$cuid]);

            $phase3_handled[$uid1] = true;
            $phase3_handled[$cuid] = true;

            $msg = 'OK: ' . $vname . ' (rank ' . $rank1 . ', user ' . $uid1 . ') deferred';
            $msg .= ' -- replaced by ' . $cname . ' (rank ' . $crank . ', user ' . $cuid . ') from preferred list.';
            $phase3_swaps[] = $msg;

        } else {
            $all_sched = $wpdb->get_results(
                "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.first_name, m.last_name, m.travel
                 FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id
                 WHERE s.group_id != 99",
                ARRAY_A
            );

            $carpool_partners = array($uid1 => $vp);
            foreach ($all_sched as $sp) {
                $sp_uid = (int)$sp['user_id'];
                if ($sp_uid === $uid1) continue;
                $sp_cp = $carpool_key($extract_carpool($normalize_travel($sp['travel'])));
                if ($sp_cp === $cp_name1) {
                    $carpool_partners[$sp_uid] = $sp;
                }
            }

            if (count($carpool_partners) < 2) continue;

            $replacements = array();
            $used_cuids   = array();
            $can_replace  = true;

            foreach ($carpool_partners as $pvid => $pvdata) {
                $prank = (int)$pvdata['Rank'];
                $ptid_row = $wpdb->get_var("SELECT time_id FROM $Schedules WHERE user_id = $pvid");
                $ptid  = $ptid_row ? (int)$ptid_row : (int)$pvdata['time_id'];
                $best_cand = null;
                $best_diff = PHP_INT_MAX;
                foreach ($pref_by_uid as $cuid => $cand) {
                    if (isset($phase3_handled[$cuid])) continue;
                    if (in_array($cuid, $used_cuids)) continue;
                    if ($has_travel_conflict($cuid, $ptid)) continue;
                    $crank = (int)$cand['Rank'];
                    $diff  = abs($crank - $prank);
                    if ($diff <= $effective_tolerance($prank) && $diff < $best_diff) {
                        $best_diff = $diff;
                        $best_cand = $cand;
                    }
                }
                if (!$best_cand) { $can_replace = false; break; }
                $replacements[$pvid] = $best_cand;
                $used_cuids[] = (int)$best_cand['user_id'];
            }

            if (!$can_replace) continue;

            foreach ($carpool_partners as $pvid => $pvdata) {
                $cand   = $replacements[$pvid];
                $cuid   = (int)$cand['user_id'];
                $crank  = (int)$cand['Rank'];
                $ctravel= $cand['travel'];
                $cfn    = $wpdb->get_var("SELECT first_name FROM $Master WHERE user_id = $cuid");
                $cln    = $wpdb->get_var("SELECT last_name FROM $Master WHERE user_id = $cuid");
                $cname  = $cfn . ' ' . $cln;
                $prank  = (int)$pvdata['Rank'];
                $pfn    = $pvdata['first_name'];
                $pln    = $pvdata['last_name'];
                $ptr    = $pvdata['travel'];
                $pname  = $pfn . ' ' . $pln;

                $ptid_row = $wpdb->get_var("SELECT time_id FROM $Schedules WHERE user_id = $pvid");
                $ptid = $ptid_row ? (int)$ptid_row : (int)$pvdata['time_id'];
                $pgid_row = $wpdb->get_var("SELECT group_id FROM $Schedules WHERE user_id = $pvid");
                $pgid = $pgid_row ? (int)$pgid_row : (int)$pvdata['group_id'];
                $pcrt_row = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $pvid");
                $pcrt = $pcrt_row ? (int)$pcrt_row : (int)$pvdata['Crt_ID'];

                $wpdb->query("UPDATE $Schedules SET group_id = 99, time_id = 99, Crt_ID = 99 WHERE user_id = $pvid");

                // ── GL EVENTS: get registration date from gl_registrations ────
                $reg_row = $wpdb->get_var( $wpdb->prepare(
                    "SELECT DATE(registered_at) FROM {$wpdb->prefix}gl_registrations
                     WHERE user_id = %d AND occurrence_id = %d AND status = 'confirmed'",
                    $cuid, $event
                ) );
                $reg_date = $reg_row ? $reg_row : date('Y-m-d');
                // ─────────────────────────────────────────────────────────────

                $wpdb->query("DELETE FROM $Schedules WHERE user_id = $cuid");
                $wpdb->insert($Schedules, array(
                    'user_id'           => $cuid,
                    'group_id'          => $pgid,
                    'time_id'           => $ptid,
                    'Crt_ID'            => $pcrt,
                    'Rank'              => $crank,
                    'first_name'        => $cfn,
                    'event_id'          => $event,
                    'registration_date' => $reg_date,
                    'travel'            => $ctravel,
                ));
                $wpdb->query("UPDATE $Schedules s JOIN $Master m ON s.user_id = m.user_id SET s.last_name = m.last_name, s.user_phone = m.user_phone, s.user_email = m.user_email WHERE s.user_id = $cuid");

                $wpdb->query("DELETE FROM $pref_temp WHERE user_id = $pvid");
                $wpdb->insert($pref_temp, array('user_id' => $pvid));
                $pfn_clean = preg_replace('/^P-/', '', $pfn);
                $wpdb->query($wpdb->prepare(
                    "UPDATE $pref_temp SET Rank = %d, first_name = %s, last_name = %s, travel = %s WHERE user_id = %d",
                    $prank, $pfn_clean, $pln, $ptr, $pvid
                ));

                $wpdb->query("DELETE FROM $pref_temp WHERE user_id = $cuid");
                unset($pref_by_uid[$cuid]);

                $phase3_handled[$pvid] = true;
                $phase3_handled[$cuid] = true;

                $msg = 'OK: ' . $pname . ' (rank ' . $prank . ', user ' . $pvid . ') deferred';
                $msg .= ' [carpool: ' . $cp_name1 . '] -- replaced by ' . $cname . ' (rank ' . $crank . ', user ' . $cuid . ').';
                $phase3_swaps[] = $msg;
            }
        }
    }

    if (empty($phase3_swaps)) {
        echo "OK: No preferred table swaps needed -- all violations resolved in Phase 1/2.<br>";
    } else {
        foreach ($phase3_swaps as $msg) {
            echo $msg . "<br>";
        }
        echo "<br>Players deferred this week have been added to the preferred list for next week.<br>";
    }

    // -------------------------------------------------------
    // PHASE 4 — CARPOOL SAME-SLOT LAST PASS
    // -------------------------------------------------------
    echo "<br><strong>Post-processing Phase 4 -- Carpool Same-Slot Last Pass:</strong><br>";

    $phase4_swaps = 0;
    $phase4_max_loops = 20;
    $phase4_loop = 0;

    do {
        $phase4_loop++;
        $swapped_this_pass = false;
        $carpool_baseline_p4 = $carpool_score();

        $all_sched_p4 = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.travel, m.first_name, m.last_name
             FROM $Schedules s
             JOIN $Master m ON s.user_id = m.user_id
             WHERE s.group_id != 99",
            ARRAY_A
        );

        $cp_groups_p4 = array();
        foreach ($all_sched_p4 as $row) {
            $travel = $normalize_travel($row['travel']);
            $cp = $carpool_key($extract_carpool($travel));
            if (empty($cp)) continue;
            $cp_groups_p4[$cp][] = $row;
        }

        $priority_pairs = array();
        $regular_pairs = array();
        foreach ($cp_groups_p4 as $cp => $members) {
            if (count($members) < 2) continue;
            $times_used = array_unique(array_column($members, 'time_id'));
            if (count($times_used) <= 1) continue;
            $is_pri = false;
            foreach ($members as $m) {
                if ($is_priority_carpool($m['travel'])) { $is_pri = true; break; }
            }
            if ($is_pri) {
                $priority_pairs[$cp] = $members;
            } else {
                $regular_pairs[$cp] = $members;
            }
        }

        $ordered_pairs = $priority_pairs + $regular_pairs;

        foreach ($ordered_pairs as $cp => $members) {
            $time_votes_p4 = array();
            foreach ($members as $m) {
                $t = (int)$m['time_id'];
                $time_votes_p4[$t] = ($time_votes_p4[$t] ?? 0) + 1;
            }
            arsort($time_votes_p4);
            $target_tid_p4 = array_key_first($time_votes_p4);

            $to_move = array();
            foreach ($members as $m) {
                if ((int)$m['time_id'] !== $target_tid_p4) {
                    $to_move[] = $m;
                }
            }

            foreach ($to_move as $mover) {
                $mv_uid  = (int)$mover['user_id'];
                $mv_gid  = (int)$mover['group_id'];
                $mv_tid  = (int)$mover['time_id'];
                $mv_rank = (int)$mover['Rank'];
                $mv_name = $mover['first_name'] . ' ' . $mover['last_name'];

                $found = false;
                $tolerance_multipliers = array(1.0, 1.5);

                foreach ($tolerance_multipliers as $mult) {
                    $tol = (int)round($effective_tolerance($mv_rank) * $mult);
                    $tol_label = $mult > 1.0 ? ' (relaxed)' : '';

                    $swap_cands = $wpdb->get_results(
                        "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.travel, m.first_name, m.last_name
                         FROM $Schedules s
                         JOIN $Master m ON s.user_id = m.user_id
                         WHERE s.group_id != 99
                         AND s.time_id = $target_tid_p4
                         AND s.user_id != $mv_uid
                         ORDER BY ABS(s.Rank - $mv_rank)",
                        ARRAY_A
                    );

                    foreach ($swap_cands as $sc) {
                        $sc_uid  = (int)$sc['user_id'];
                        $sc_gid  = (int)$sc['group_id'];
                        $sc_tid  = (int)$sc['time_id'];
                        $sc_rank = (int)$sc['Rank'];
                        $sc_travel_norm = $normalize_travel($sc['travel']);

                        if (!empty($sc_travel_norm)) continue;

                        if (abs($mv_rank - $sc_rank) > $tol) continue;

                        if ($has_travel_conflict($sc_uid, $mv_tid)) continue;

                        $sc_cp_name = $carpool_key($extract_carpool($sc['travel']));
                        if (!empty($sc_cp_name)) continue;

                        $mv_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $mv_uid");
                        $sc_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $sc_uid");
                        $crt_for_mv = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $sc_gid AND user_id != $sc_uid LIMIT 1");
                        $crt_for_sc = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $mv_gid AND user_id != $mv_uid LIMIT 1");
                        if (!$crt_for_mv) $crt_for_mv = $sc_crt;
                        if (!$crt_for_sc) $crt_for_sc = $mv_crt;

                        $wpdb->query("UPDATE $Schedules SET group_id = $sc_gid, time_id = $sc_tid, Crt_ID = $crt_for_mv WHERE user_id = $mv_uid");
                        $wpdb->query("UPDATE $Schedules SET group_id = $mv_gid, time_id = $mv_tid, Crt_ID = $crt_for_sc WHERE user_id = $sc_uid");

                        $carpool_after_p4 = $carpool_score();
                        if ($carpool_after_p4 > $carpool_baseline_p4) {
                            $wpdb->query("UPDATE $Schedules SET group_id = $mv_gid, time_id = $mv_tid, Crt_ID = $mv_crt WHERE user_id = $mv_uid");
                            $wpdb->query("UPDATE $Schedules SET group_id = $sc_gid, time_id = $sc_tid, Crt_ID = $sc_crt WHERE user_id = $sc_uid");
                            continue;
                        }

                        $sc_name = $sc['first_name'] . ' ' . $sc['last_name'];
                        $label_p4 = isset($priority_pairs[$cp]) ? '[Priority] ' : '';
                        echo "OK: {$label_p4}Carpool '$cp': $mv_name (rank $mv_rank, {$time_labels[$mv_tid]}-->{$time_labels[$target_tid_p4]}) swapped with $sc_name (rank $sc_rank){$tol_label}.<br>";
                        $phase4_swaps++;
                        $swapped_this_pass = true;
                        $found = true;
                        break;
                    }

                    if ($found) break;
                }

                if ($swapped_this_pass) break 2;
            }
        }
    } while ($swapped_this_pass && $phase4_loop < $phase4_max_loops);

    if ($phase4_swaps === 0) {
        echo "OK: All carpool partners already in the same time slot.<br>";
    } else {
        echo "OK: $phase4_swaps carpool swap(s) completed in $phase4_loop pass(es).<br>";
    }

    // -------------------------------------------------------
    // PHASE 5 — TRAVEL + PREFERENCE LAST PASS
    // -------------------------------------------------------
    echo "<br><strong>Post-processing Phase 5 -- Travel Preference Last Pass:</strong><br>";

    $phase5_swaps = 0;
    $phase5_max_loops = 20;
    $phase5_loop = 0;
    $tolerance_multipliers_p5 = array(1.0, 1.5, 3.0);

    do {
        $phase5_loop++;
        $swapped_p5 = false;
        $carpool_baseline_p5 = $carpool_score();

        $plus_players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.travel, m.first_name, m.last_name
             FROM $Schedules s
             JOIN $Master m ON s.user_id = m.user_id
             WHERE s.group_id != 99
             ORDER BY s.Rank",
            ARRAY_A
        );

        $p5_handled = array();

        foreach ($plus_players as $pp) {
            $pp_uid    = (int)$pp['user_id'];
            if (isset($p5_handled[$pp_uid])) continue;
            $pp_tid    = (int)$pp['time_id'];
            $pp_gid    = (int)$pp['group_id'];
            $pp_rank   = (int)$pp['Rank'];
            $pp_travel = $normalize_travel($pp['travel']);

            $target_pos = null;
            if (preg_match('/^\+5:30/i', $pp_travel)) $target_pos = 0;
            elseif (preg_match('/^\+6:40/i', $pp_travel)) $target_pos = 1;
            elseif (preg_match('/^\+7:50/i', $pp_travel)) $target_pos = 2;
            if ($target_pos === null) continue;

            $target_tid_p5 = $time_ids[$target_pos];
            if ($pp_tid == $target_tid_p5) continue;

            $pp_cp = $carpool_key($extract_carpool($pp_travel));

            // ── PAIRED CARPOOL SWAP ──────────────────────────────────────
            if (!empty($pp_cp)) {
                $cp_movers = array();
                foreach ($plus_players as $other) {
                    $o_uid = (int)$other['user_id'];
                    $o_travel = $normalize_travel($other['travel']);
                    $o_cp = $carpool_key($extract_carpool($o_travel));
                    if ($o_cp === $pp_cp) {
                        $cp_movers[] = $other;
                    }
                }

                $all_want_same = true;
                foreach ($cp_movers as $cm) {
                    $cm_travel = $normalize_travel($cm['travel']);
                    $cm_target = null;
                    if (preg_match('/^\+5:30/i', $cm_travel)) $cm_target = 0;
                    elseif (preg_match('/^\+6:40/i', $cm_travel)) $cm_target = 1;
                    elseif (preg_match('/^\+7:50/i', $cm_travel)) $cm_target = 2;
                    if ($cm_target !== $target_pos) { $all_want_same = false; break; }
                }
                if (!$all_want_same) continue;

                $movers_needing_swap = array();
                foreach ($cp_movers as $cm) {
                    if ((int)$cm['time_id'] != $target_tid_p5) {
                        $movers_needing_swap[] = $cm;
                    }
                }
                if (empty($movers_needing_swap)) continue;

                $found_all = false;

                foreach ($tolerance_multipliers_p5 as $mult) {
                    $swap_pairs = array();
                    $used_sc_uids = array();
                    $can_swap_all = true;

                    foreach ($movers_needing_swap as $mv) {
                        $mv_uid  = (int)$mv['user_id'];
                        $mv_rank = (int)$mv['Rank'];
                        $tol = (int)round($effective_tolerance($mv_rank) * $mult);

                        $swap_cands = $wpdb->get_results(
                            "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.travel, m.first_name, m.last_name
                             FROM $Schedules s
                             JOIN $Master m ON s.user_id = m.user_id
                             WHERE s.group_id != 99
                             AND s.time_id = $target_tid_p5
                             ORDER BY ABS(s.Rank - $mv_rank)",
                            ARRAY_A
                        );

                        $found_cand = false;
                        foreach ($swap_cands as $sc) {
                            $sc_uid = (int)$sc['user_id'];
                            if (in_array($sc_uid, $used_sc_uids)) continue;
                            $sc_travel_norm = $normalize_travel($sc['travel']);
                            if (!empty($sc_travel_norm)) continue;
                            $sc_cp_name = $carpool_key($extract_carpool($sc['travel']));
                            if (!empty($sc_cp_name)) continue;
                            if (abs($mv_rank - (int)$sc['Rank']) > $tol) continue;
                            $mv_tid_current = (int)$mv['time_id'];
                            if ($has_travel_conflict($sc_uid, $mv_tid_current)) continue;

                            $swap_pairs[] = array('mover' => $mv, 'swap' => $sc);
                            $used_sc_uids[] = $sc_uid;
                            $found_cand = true;
                            break;
                        }
                        if (!$found_cand) { $can_swap_all = false; break; }
                    }

                    if (!$can_swap_all) continue;

                    $rollback = array();
                    foreach ($swap_pairs as $pair) {
                        $mv = $pair['mover'];
                        $sc = $pair['swap'];
                        $mv_uid = (int)$mv['user_id'];
                        $sc_uid = (int)$sc['user_id'];
                        $mv_gid = (int)$mv['group_id'];
                        $sc_gid = (int)$sc['group_id'];
                        $mv_tid = (int)$mv['time_id'];
                        $sc_tid = (int)$sc['time_id'];

                        $mv_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $mv_uid");
                        $sc_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $sc_uid");
                        $crt_for_mv = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $sc_gid AND user_id != $sc_uid LIMIT 1");
                        $crt_for_sc = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $mv_gid AND user_id != $mv_uid LIMIT 1");
                        if (!$crt_for_mv) $crt_for_mv = $sc_crt;
                        if (!$crt_for_sc) $crt_for_sc = $mv_crt;

                        $rollback[] = array(
                            'mv_uid' => $mv_uid, 'mv_gid' => $mv_gid, 'mv_tid' => $mv_tid, 'mv_crt' => $mv_crt,
                            'sc_uid' => $sc_uid, 'sc_gid' => $sc_gid, 'sc_tid' => $sc_tid, 'sc_crt' => $sc_crt,
                        );

                        $wpdb->query("UPDATE $Schedules SET group_id = $sc_gid, time_id = $sc_tid, Crt_ID = $crt_for_mv WHERE user_id = $mv_uid");
                        $wpdb->query("UPDATE $Schedules SET group_id = $mv_gid, time_id = $mv_tid, Crt_ID = $crt_for_sc WHERE user_id = $sc_uid");
                    }

                    $carpool_after_p5 = $carpool_score();
                    if ($carpool_after_p5 > $carpool_baseline_p5) {
                        foreach (array_reverse($rollback) as $rb) {
                            $wpdb->query("UPDATE $Schedules SET group_id = {$rb['mv_gid']}, time_id = {$rb['mv_tid']}, Crt_ID = {$rb['mv_crt']} WHERE user_id = {$rb['mv_uid']}");
                            $wpdb->query("UPDATE $Schedules SET group_id = {$rb['sc_gid']}, time_id = {$rb['sc_tid']}, Crt_ID = {$rb['sc_crt']} WHERE user_id = {$rb['sc_uid']}");
                        }
                        continue;
                    }

                    $tol_label = $mult > 1.0 ? ' (relaxed ' . $mult . 'x)' : '';
                    foreach ($swap_pairs as $pair) {
                        $mv = $pair['mover'];
                        $sc = $pair['swap'];
                        $mv_name = $mv['first_name'] . ' ' . $mv['last_name'];
                        $sc_name = $sc['first_name'] . ' ' . $sc['last_name'];
                        $mv_tid_label = $time_labels[(int)$mv['time_id']] ?? $mv['time_id'];
                        echo "OK: [Carpool '$pp_cp'] $mv_name (rank {$mv['Rank']}, {$mv_tid_label}-->{$time_labels[$target_tid_p5]}) swapped with $sc_name (rank {$sc['Rank']}){$tol_label}.<br>";
                        $phase5_swaps++;
                        $p5_handled[(int)$mv['user_id']] = true;
                    }
                    $swapped_p5 = true;
                    $found_all = true;
                    break;
                }

                if ($found_all) break;
                continue;
            }

            // ── SINGLE PLAYER SWAP ───────────────────────────────────────
            $found_p5 = false;

            foreach ($tolerance_multipliers_p5 as $mult) {
                $tol = (int)round($effective_tolerance($pp_rank) * $mult);
                $tol_label = $mult > 1.0 ? ' (relaxed ' . $mult . 'x)' : '';

                $swap_cands_p5 = $wpdb->get_results(
                    "SELECT s.user_id, s.group_id, s.time_id, s.Crt_ID, s.Rank, m.travel, m.first_name, m.last_name
                     FROM $Schedules s
                     JOIN $Master m ON s.user_id = m.user_id
                     WHERE s.group_id != 99
                     AND s.time_id = $target_tid_p5
                     AND s.user_id != $pp_uid
                     ORDER BY ABS(s.Rank - $pp_rank)",
                    ARRAY_A
                );

                foreach ($swap_cands_p5 as $sc) {
                    $sc_uid    = (int)$sc['user_id'];
                    $sc_gid    = (int)$sc['group_id'];
                    $sc_tid    = (int)$sc['time_id'];
                    $sc_rank   = (int)$sc['Rank'];
                    $sc_travel_norm = $normalize_travel($sc['travel']);

                    if (!empty($sc_travel_norm)) continue;

                    if (abs($pp_rank - $sc_rank) > $tol) continue;

                    if ($has_travel_conflict($sc_uid, $pp_tid)) continue;

                    $sc_cp_name = $carpool_key($extract_carpool($sc['travel']));
                    if (!empty($sc_cp_name)) continue;

                    $pp_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $pp_uid");
                    $sc_crt = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE user_id = $sc_uid");
                    $crt_for_pp = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $sc_gid AND user_id != $sc_uid LIMIT 1");
                    $crt_for_sc = $wpdb->get_var("SELECT Crt_ID FROM $Schedules WHERE group_id = $pp_gid AND user_id != $pp_uid LIMIT 1");
                    if (!$crt_for_pp) $crt_for_pp = $sc_crt;
                    if (!$crt_for_sc) $crt_for_sc = $pp_crt;

                    $wpdb->query("UPDATE $Schedules SET group_id = $sc_gid, time_id = $sc_tid, Crt_ID = $crt_for_pp WHERE user_id = $pp_uid");
                    $wpdb->query("UPDATE $Schedules SET group_id = $pp_gid, time_id = $pp_tid, Crt_ID = $crt_for_sc WHERE user_id = $sc_uid");

                    $carpool_after_p5 = $carpool_score();
                    if ($carpool_after_p5 > $carpool_baseline_p5) {
                        $wpdb->query("UPDATE $Schedules SET group_id = $pp_gid, time_id = $pp_tid, Crt_ID = $pp_crt WHERE user_id = $pp_uid");
                        $wpdb->query("UPDATE $Schedules SET group_id = $sc_gid, time_id = $sc_tid, Crt_ID = $sc_crt WHERE user_id = $sc_uid");
                        continue;
                    }

                    $pp_name = $pp['first_name'] . ' ' . $pp['last_name'];
                    $sc_name = $sc['first_name'] . ' ' . $sc['last_name'];
                    echo "OK: $pp_name (rank $pp_rank, {$time_labels[$pp_tid]}-->{$time_labels[$target_tid_p5]}) swapped with $sc_name (rank $sc_rank){$tol_label}.<br>";
                    $phase5_swaps++;
                    $swapped_p5 = true;
                    $found_p5 = true;
                    break;
                }

                if ($found_p5) break;
            }

            if ($swapped_p5) break;
        }
    } while ($swapped_p5 && $phase5_loop < $phase5_max_loops);

    if ($phase5_swaps === 0) {
        echo "OK: All + preference players already at their preferred time slot.<br>";
    } else {
        echo "OK: $phase5_swaps preference swap(s) completed in $phase5_loop pass(es).<br>";
    }

    // -------------------------------------------------------
    // FINAL VIOLATION REPORT
    // -------------------------------------------------------
    echo "<br><strong>Travel Time Conflict Report:</strong><br>";

    $remaining_530 = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT group_id) FROM $Schedules 
        WHERE time_id = 1 AND group_id != 99
        AND travel REGEXP '^-[ ]?5:30'
    ");
    $remaining_750 = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT group_id) FROM $Schedules 
        WHERE time_id = 3 AND group_id != 99
        AND travel REGEXP '^-[ ]?7:50'
    ");
    $remaining_plus530 = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT group_id) FROM $Schedules 
        WHERE time_id != 1 AND group_id != 99
        AND (travel LIKE '+5:30%' OR travel REGEXP '^[+]?5:30[^0-9]')
    ");

    if ($remaining_530 === 0) echo "✓ No -5:30 conflicts remaining.<br>";
    else echo "⚠ $remaining_530 group(s) still have -5:30 players at 5:30pm — manual swap needed.<br>";

    if ($remaining_750 === 0) echo "✓ No -7:50 conflicts remaining.<br>";
    else echo "⚠ $remaining_750 group(s) still have -7:50 players at 7:50pm — manual swap needed.<br>";

    $remaining_plus640 = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id != 2 AND group_id != 99
        AND travel LIKE '+6:40%'
    ");
    $remaining_plus750 = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT group_id) FROM $Schedules
        WHERE time_id != 3 AND group_id != 99
        AND travel LIKE '+7:50%'
    ");

    if ($remaining_plus530 === 0) echo "✓ All +5:30 players are at 5:30pm.<br>";
    else echo "⚠ $remaining_plus530 group(s) still have +5:30 players not at 5:30pm — manual swap needed.<br>";

    if ($remaining_plus640 === 0) echo "✓ All +6:40 players are at 6:40pm.<br>";
    else echo "⚠ $remaining_plus640 group(s) still have +6:40 players not at 6:40pm — manual swap needed.<br>";

    if ($remaining_plus750 === 0) echo "✓ All +7:50 players are at 7:50pm.<br>";
    else echo "⚠ $remaining_plus750 group(s) still have +7:50 players not at 7:50pm — manual swap needed.<br>";

    // CARPOOL REPORT
    $cp_score = $carpool_score();
    if ($cp_score === 0) {
        echo "✓ All carpool partners are in the same or adjacent time slots.<br>";
    } else {
        $cp_players = $wpdb->get_results(
            "SELECT s.user_id, s.group_id, s.time_id, m.travel, m.first_name, m.last_name
             FROM $Schedules s JOIN $Master m ON s.user_id = m.user_id 
             WHERE s.group_id != 99",
            ARRAY_A
        );
        $carpool_groups = [];
        foreach ($cp_players as $p) {
            $travel = $normalize_travel($p['travel']);
            $cp = $carpool_key($extract_carpool($travel));
            if (!empty($cp)) {
                $carpool_groups[$cp][] = [
                    'name'     => $p['first_name'] . ' ' . $p['last_name'],
                    'time_id'  => $p['time_id'],
                    'priority' => $is_priority_carpool($travel),
                ];
            }
        }
        $issues = [];
        foreach ($carpool_groups as $cp => $members) {
            if (count($members) < 2) continue;
            $is_pri = array_reduce($members, fn($c, $m) => $c || $m['priority'], false);
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $pos1 = array_search($members[$i]['time_id'], $time_ids);
                    $pos2 = array_search($members[$j]['time_id'], $time_ids);
                    $gap  = abs($pos1 - $pos2);
                    $t1   = $time_labels[$members[$i]['time_id']] ?? $members[$i]['time_id'];
                    $t2   = $time_labels[$members[$j]['time_id']] ?? $members[$j]['time_id'];
                    if ($is_pri && $gap > 0) {
                        $issues[] = "⚠ {$members[$i]['name']} ({$t1}) and {$members[$j]['name']} ({$t2}) carpool together but are in different time slots — manual adjustment recommended.<br>";
                    } elseif (!$is_pri && $gap > 1) {
                        $issues[] = "⚠ {$members[$i]['name']} ({$t1}) and {$members[$j]['name']} ({$t2}) carpool together but are 2 time slots apart — manual adjustment recommended.<br>";
                    }
                }
            }
        }
        if (!empty($issues)) {
            echo "⚠ Some carpool partners could not be fully scheduled together:<br>";
            foreach ($issues as $issue) echo "&nbsp;&nbsp;&nbsp;" . $issue;
        } else {
            echo "✓ All carpool partners are in the same or adjacent time slots.<br>";
        }
    }

    // Update WPDA project page — keep as draft
    $wpdb->query("ALTER TABLE {$prefix}wpda_project_page MODIFY COLUMN page_sequence INT(10)");
    $wpdb->query("UPDATE {$prefix}wpda_project_page SET page_name = 'Schedule for $name', page_title = 'Schedule for $name', page_sequence = $Event, add_to_menu = 'No', page_setname = 'Schedules', page_table_name = 'Schedules' WHERE project_id = $project AND page_id = $page");
    $wpdb->query("UPDATE {$prefix}wpda_app SET app_title = 'Schedule for $name' WHERE app_id = 4");

    $wpdb->query("DROP TABLE IF EXISTS $schedules_prev");
    $wpdb->query("CREATE TABLE $schedules_prev SELECT * FROM $Schedules");

    echo do_shortcode("[cmruncode name='Create View']");

    // TEST SUITE SNAPSHOT
    $test_table = "ScheduleTest_$event";
    $wpdb->query("DROP TABLE IF EXISTS $test_table");

    // ── GL EVENTS: test suite snapshot from gl_registrations ─────────────────
    $wpdb->query("
        CREATE TABLE $test_table AS
        SELECT
            r.user_id,
            m.first_name,
            m.last_name,
            m.Rank,
            m.travel,
            DATE(r.registered_at) AS registration_date,
            r.status,
            '1.0.0-gl' AS algo_version,
            NOW() AS snapshot_date
        FROM {$wpdb->prefix}gl_registrations r
        JOIN $Master m ON r.user_id = m.user_id
        WHERE r.occurrence_id = $event
        AND r.status = 'confirmed'
        ORDER BY m.Rank
    ");
    // ─────────────────────────────────────────────────────────────────────────

    echo "✓ Test suite snapshot saved to $test_table.<br>";

    echo "<br><strong>Schedule created in draft mode — review and modify before publishing to players.</strong><br>";

    // Clear modified groups from previous event
    delete_option( 'spp_modified_groups' );
    // Mark results as not yet posted for this new schedule
    update_option('spp_results_posted', 0);
    echo "✓ Modified groups list cleared for new event.<br>";

} else {
    echo "<br />No event set for calculations";
}
}
