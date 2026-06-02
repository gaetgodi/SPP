<?php
/* =========================================================
   GL Schedule Production
   Version: 1.0.00
   Date: 2026-05-28
   Based on: Schedule Production 1.8.11

   Changes from 1.8.11:
   - Reads registrants from lX9c1_gl_registrations instead of
     lX9c1_rtec_entries. No +30000000 hack.
   - $Event is now the actual gl_event_occurrences.id value.
   - registration_date populated from gl_registrations.registered_at
     (legacy field, no longer used functionally).
   - All other logic — Master, Groups, Courts, Times, Schedules,
     preferred tables, carpool, post-processing — unchanged.
   ========================================================= */

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
if (!isset($Event)) { return 0; }

$schedules_prev = "SchedulesPrev$Event";
// Need to run this to refresh travel fields
echo do_shortcode("[cmruncode name='Create membership table']");

// -------------------------------------------------------
// ASSIGN RANKS
// -------------------------------------------------------
echo do_shortcode("[cmruncode name='GL Assign ranks to registered players']");

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
    // BASE TIME SLOT DISTRIBUTION
    // -------------------------------------------------------
    $valid_combos = [];
    for ($a = 1; $a <= $num_crts; $a++) {
        for ($b = 1; $b <= $num_crts; $b++) {
            $c = $groups_needed - $a - $b;
            if ($c >= 1 && $c <= $num_crts) {
                if ($a === $b && $b === $c) continue;
                $valid_combos[] = [$a, $b, $c];
            }
        }
    }
    if (empty($valid_combos)) {
        $even = intval($groups_needed / $num_times);
        $counts = array_fill(0, $num_times, $even);
        $remainder = $groups_needed % $num_times;
        for ($i = 0; $i < $remainder; $i++) $counts[$i]++;
    } else {
        $counts = $valid_combos[array_rand($valid_combos)];
    }
    shuffle($counts);

    echo "Distribution base (before carpool): " . implode(', ', $counts) . "<br>";

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

    foreach ($players_per_group as $gid => $players) {
        $has_plus_530 = false;
        foreach ($players as $uid) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
            $travel = $normalize_travel($raw);
            if (preg_match('/^\+5:30/i', $travel)) { $has_plus_530 = true; break; }
        }
        if (!$has_plus_530) continue;
        if ($group_time_map[$gid] === $time_ids[0]) continue;

        $incoming_time = $group_time_map[$gid];

        $swapped = false;
        foreach ($group_time_map as $other_gid => $other_tid) {
            if ($other_gid === $gid) continue;
            if ($other_tid !== $time_ids[0]) continue;

            $has_minus_530 = false;
            foreach ($players_per_group[$other_gid] as $uid) {
                $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
                $travel = $normalize_travel($raw);
                if (preg_match('/^-5:30/i', $travel)) { $has_minus_530 = true; break; }
            }
            if ($has_minus_530) continue;

            $carpool_broken = false;
            foreach ($players_per_group[$other_gid] as $uid) {
                $raw = $wpdb->get_var($wpdb->prepare("SELECT travel FROM $Master WHERE user_id = %d", $uid));
                $travel = $normalize_travel($raw);
                if (!preg_match('/^\+5:30/i', $travel)) continue;
                $cp = $carpool_key($extract_carpool($raw));
                if (empty($cp)) continue;
                $partner_gids = $carpool_group_lookup[$cp] ?? [];
                foreach ($partner_gids as $partner_gid) {
                    if ($partner_gid === $other_gid) continue;
                    $partner_time = $group_time_map[$partner_gid];
                    $pos_incoming = array_search($incoming_time, $time_ids);
                    $pos_partner  = array_search($partner_time, $time_ids);
                    if (abs($pos_incoming - $pos_partner) > 1) {
                        $carpool_broken = true;
                        break 2;
                    }
                }
            }
            if ($carpool_broken) continue;

            $group_time_map[$gid] = $time_ids[0];
            $group_time_map[$other_gid] = $incoming_time;
            echo "✓ [+5:30] Group $gid moved to 5:30pm (swapped with Group $other_gid).<br>";
            $swapped = true;
            break;
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

    if ($remaining_plus530 === 0) echo "✓ All +5:30 players are at 5:30pm.<br>";
    else echo "⚠ $remaining_plus530 group(s) still have +5:30 players not at 5:30pm — manual swap needed.<br>";

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

} else {
    echo "<br />No event set for calculations";
}
?>
