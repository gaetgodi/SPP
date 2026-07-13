<?php
/* =========================================================
   GL Publish Schedule
   Version: 1.2.2
   Date: 2026-07-13
   Based on: Publish Schedule 2.4

   Changes from 1.2.1:
   - Added note to personalized group email for players on
     Court 5 or 6 at the 5:30pm time slot, asking them to
     arrive 10 minutes early to draw the lines on their court.

   Changes from 1.2.0:
   - Migrated from Code Manager snippet (CM 278) to a tracked
     theme file, wrapped in [gl_publish_schedule] shortcode.
   - Removed leftover DEBUG echo line.
   - Fixed session_status() check: was "!session_status() ==
     PHP_SESSION_ACTIVE" (always false due to precedence, so
     session_start() never actually ran); now correctly checks
     "session_status() !== PHP_SESSION_ACTIVE".

   Changes from 1.1.0:
   - Event ID read from spp_current_event option instead of
     wpda_project_page. All WPDA references removed.

   Changes from 1.0.0:
   - Added Enter Scores introduction and link in personalized
     player email.

   Changes from 2.6:
   - Event date fetched from gl_event_occurrences instead of
     tec_occurrences + tec_events + posts join.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'gl_publish_schedule', 'gl_publish_schedule_shortcode' );

function gl_publish_schedule_shortcode() {
    ob_start();
    gl_publish_schedule_run();
    return ob_get_clean();
}

function gl_publish_schedule_run() {

    if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

    global $wpdb, $Event, $name;

    $prefix = $wpdb->prefix;

    $test_mode  = true;
    $test_email = 'gaetgodi@godin.on.ca';

    // Get event ID from spp_current_event option
    $Event = (int) get_option('spp_current_event', 0);
    if (!$Event) {
        echo '<p>No current event set (spp_current_event option is empty).</p>';
        return;
    }

    // ── GL EVENTS: get event date from gl_event_occurrences ──────────────────────
    $occ = $wpdb->get_row( $wpdb->prepare(
        "SELECT title, event_date FROM {$prefix}gl_event_occurrences WHERE id = %d",
        $Event
    ), ARRAY_A );

    if (!$occ) {
        echo '<p>Event ' . $Event . ' not found in gl_event_occurrences.</p>';
        return;
    }

    $date  = date_format( date_create( $occ['event_date'] ), 'F d, Y' );
    $name  = $occ['title'];
    $title = "Schedule for " . $occ['title'] . " - $date";
    // ─────────────────────────────────────────────────────────────────────────────

    // -------------------------------------------------------
    // CONFIRMATION GATE -- prevent accidental duplicate sends
    // -------------------------------------------------------
    $confirmed = isset($_POST['publish_confirmed']) && $_POST['publish_confirmed'] === '1';

    if (!$confirmed) {
        $playing_count         = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM Schedules WHERE group_id != 99");
        $total_count           = $wpdb->get_var("SELECT COUNT(*) FROM Master WHERE user_email != ''");
        $generic_count_preview = $total_count - $playing_count;
        $test_emails           = array_map('trim', explode(',', $test_email));
        $test_count            = count($test_emails);
        ?>
        <style>
            .confirm-wrap { max-width:600px; margin:30px auto; font-family:Arial,sans-serif; }
            .confirm-wrap h2 { color:#333; }
            .confirm-box { background:#fdf3f2; border:2px solid #c0392b; border-radius:6px; padding:20px; margin:20px 0; }
            .confirm-box h3 { color:#c0392b; margin:0 0 12px; }
            .confirm-box ul { margin:10px 0; padding-left:20px; }
            .confirm-box li { margin:6px 0; font-size:15px; }
            .confirm-btn { padding:10px 24px; background:#c0392b; color:white; border:none; border-radius:4px; font-size:15px; cursor:pointer; }
            .confirm-btn:hover { background:#a93226; }
            .test-btn { padding:10px 24px; background:#e67e22; color:white; border:none; border-radius:4px; font-size:15px; cursor:pointer; }
            .test-btn:hover { background:#ca6f1e; }
            .cancel-lnk { margin-left:16px; color:#888; font-size:14px; text-decoration:none; }
        </style>
        <div class="confirm-wrap">
            <h2>Publish Schedule -- <?php echo esc_html($title); ?></h2>
            <?php if ($test_mode): ?>
            <div class="confirm-box" style="border-color:#e67e22;background:#fef9f0;">
                <h3 style="color:#e67e22;">TEST MODE -- Limited Email Blast</h3>
                <p>Test mode is ON. Emails will only be sent to:</p>
                <ul>
                    <?php foreach ($test_emails as $te): ?>
                    <li><strong><?php echo esc_html($te); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <p><strong><?php echo $playing_count; ?></strong> players scheduled / <strong><?php echo $total_count; ?></strong> total in Master -- only <strong><?php echo $test_count; ?></strong> test email(s) will be sent.</p>
                <p style="color:#e67e22;font-weight:bold;">Set $test_mode = false to send to all players.</p>
            </div>
            <?php else: ?>
            <div class="confirm-box">
                <h3>Confirm Schedule Email Blast</h3>
                <p>This will publish the schedule and send emails to:</p>
                <ul>
                    <li><strong><?php echo $playing_count; ?></strong> players playing tonight (personalized)</li>
                    <li><strong><?php echo $generic_count_preview; ?></strong> non-playing Master list members (generic notice)</li>
                    <li><strong><?php echo $total_count; ?></strong> total emails</li>
                </ul>
                <p style="color:#c0392b;font-weight:bold;">This cannot be undone. Are you sure?</p>
            </div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="publish_confirmed" value="1">
                <?php if ($test_mode): ?>
                <button type="submit" class="test-btn">Yes, Publish Schedule and Send Test Email(s)</button>
                <?php else: ?>
                <button type="submit" class="confirm-btn">Yes, Publish Schedule and Send Emails</button>
                <?php endif; ?>
                <a href="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" class="cancel-lnk">Cancel</a>
            </form>
        </div>
        <?php
        return;
    }

    // -------------------------------------------------------
    // CONFIRMED -- publish schedule pages and send emails
    // -------------------------------------------------------

    // Strip P- prefix from first_name
    $wpdb->query("UPDATE Schedules SET first_name = SUBSTRING(first_name, 3) WHERE first_name LIKE 'P-%'");
    echo "&#10003; Preferred player P- prefix stripped from schedule.<br>";

    echo "<br>$date";

    // ── GL EVENTS: set published flag ────────────────────────────────────────────
    update_option( 'spp_schedule_published', 1 );
    echo "<br>&#10003; Schedule published (spp_schedule_published = 1).<br>";
    // ─────────────────────────────────────────────────────────────────────────────

    // -------------------------------------------------------
    // PERSONALIZED SCHEDULE EMAIL
    // -------------------------------------------------------
    $test_emails  = array_map('trim', explode(',', $test_email));
    $event_date   = $date;
    $schedule_url = 'https://pickleballstouffville.ca/gl-player-schedule-view/';
    $logo_url     = 'https://pickleballstouffville.ca/wp-content/uploads/2024/03/SPP_Logo-96DPI-8X-3.52-480x211.png';
    $subject      = "Stouffville Pickleball Players -- Ladder Schedule for $event_date";

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    $pairings_5 = array(
        array('name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => 4),
        array('name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,4), 'bye' => 3),
        array('name' => 'Round 3', 'blue' => array(0,3), 'red' => array(2,4), 'bye' => 1),
        array('name' => 'Round 4', 'blue' => array(0,4), 'red' => array(1,3), 'bye' => 2),
        array('name' => 'Round 5', 'blue' => array(1,2), 'red' => array(3,4), 'bye' => 0),
    );

    $pairings_4 = array(
        array('name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => -1),
        array('name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,3), 'bye' => -1),
        array('name' => 'Round 3', 'blue' => array(0,3), 'red' => array(1,2), 'bye' => -1),
    );

    $build_header = function() use ($logo_url, $event_date) {
        return '<!DOCTYPE html>
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
  </tr>';
    };

    $build_footer = function() use ($schedule_url) {
        return '
  <tr>
    <td style="padding:16px 24px;border-top:1px solid #ddd;font-size:12px;color:#666;text-align:center;">
      We are on tonight unless you hear otherwise by 4pm!<br>
      <a href="' . $schedule_url . '" style="color:#3766AB;">View full schedule on the website</a>
    </td>
  </tr>
</table>
</body>
</html>';
    };

    $build_group_table = function($players, $pairings, $my_user_id) {
        $player0 = $players[0];

        $html = '
  <tr>
    <td style="padding:16px 24px 8px 24px;">
      <p style="margin:0 0 8px 0;font-size:15px;font-weight:bold;color:red;">You are playing tonight!</p>
      <div style="background:#2c3e50;color:#ffffff;padding:8px 12px;border-radius:6px 6px 0 0;font-weight:bold;font-size:15px;margin-bottom:0;">
        ' . esc_html($player0['GP_name']) . ' &mdash; ' . esc_html($player0['Crt_name']) . ' &mdash; ' . esc_html($player0['T_desc']) . '
      </div>';

        if ( in_array( (int) $player0['Crt_ID'], [5, 6], true ) && (int) $player0['time_id'] === 1 ) {
            $html .= '
      <p style="margin:8px 0 0 0;padding:8px 12px;background:#fff3cd;border:1px solid #ffe08a;border-radius:0 0 6px 6px;color:#7a5b00;font-weight:bold;">
        Please arrive 10 minutes early to draw the lines on your court.
      </p>';
        }

        $html .= '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;margin-bottom:12px;">
        <thead>
          <tr style="background:#3766AB;">
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;width:40px;">Rank</td>
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;">Name</td>
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;">Phone</td>
          </tr>
        </thead>
        <tbody>';

        foreach ($players as $i => $p) {
            $is_me  = ((int)$p['user_id'] === (int)$my_user_id);
            $me_tag = $is_me ? ' (me)' : '';
            $bg     = $is_me
                ? 'background:#e8f5e9;font-weight:bold;'
                : ($i % 2 == 0 ? 'background:#f9f9f9;' : 'background:#ffffff;');
            $html .= '
          <tr style="' . $bg . '">
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['Rank']) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['first_name'] . ' ' . $p['last_name']) . $me_tag . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html($p['user_phone']) . '</td>
          </tr>';
        }

        $html .= '
        </tbody>
      </table>';

        $html .= '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;">
        <thead>
          <tr style="background:#3766AB;">
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;width:70px;">Round</td>
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;background:#5b8dd9;">Blue Team</td>
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;background:#c0607a;">Red Team</td>
            <td style="color:#ffffff;padding:5px 8px;font-weight:bold;">Bye</td>
          </tr>
        </thead>
        <tbody>';

        $my_pos = -1;
        foreach ($players as $i => $p) {
            if ((int)$p['user_id'] === (int)$my_user_id) { $my_pos = $i; break; }
        }

        foreach ($pairings as $round) {
            $blue_names = array_map(function($i) use ($players) {
                return isset($players[$i]) ? $players[$i]['first_name'] : '';
            }, $round['blue']);

            $red_names = array_map(function($i) use ($players) {
                return isset($players[$i]) ? $players[$i]['first_name'] : '';
            }, $round['red']);

            $bye_name = ($round['bye'] >= 0 && isset($players[$round['bye']]))
                ? $players[$round['bye']]['first_name']
                : '-';

            $i_am_blue  = in_array($my_pos, $round['blue']);
            $i_am_red   = in_array($my_pos, $round['red']);
            $i_have_bye = ($round['bye'] === $my_pos);

            $blue_bg = $i_am_blue  ? 'background:#aed8e6;font-weight:bold;' : 'background:#daeef5;';
            $red_bg  = $i_am_red   ? 'background:#f4a7b5;font-weight:bold;' : 'background:#fce4ec;';
            $bye_bg  = $i_have_bye ? 'background:#c5e1a5;font-weight:bold;' : 'background:#f5f5f5;';

            $html .= '
          <tr>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($round['name']) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $blue_bg . '">' . esc_html(implode(' / ', $blue_names)) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $red_bg . '">' . esc_html(implode(' / ', $red_names)) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $bye_bg . '">' . esc_html($bye_name) . '</td>
          </tr>';
        }

        $html .= '
        </tbody>
      </table>
    </td>
  </tr>';

        return $html;
    };

    $build_score_entry_row = function() {
        return '
  <tr>
    <td style="padding:16px 24px;">
      <div style="background:#f0f7ff;border:1px solid #3766AB;border-radius:6px;padding:14px 16px;">
        <p style="margin:0 0 8px 0;font-size:15px;font-weight:bold;color:#3766AB;">NEW -- Enter Your Scores Online</p>
        <p style="margin:0 0 6px 0;font-size:14px;">This season we are introducing online score entry. Here is how it works:</p>
        <ul style="margin:6px 0 10px 0;padding-left:20px;font-size:14px;color:#333;">
          <li style="margin-bottom:4px;">The Enter Scores link will appear in the menu once your event starts.</li>
          <li style="margin-bottom:4px;">Any player in the group can enter scores for the games.</li>
          <li style="margin-bottom:4px;">Just tap the winning team and enter the losing score -- that is all you need.</li>
          <li style="margin-bottom:4px;">For now, this works alongside the paper score sheets.</li>
        </ul>
        <p style="margin:0;text-align:center;">
          <a href="https://pickleballstouffville.ca/enter-scores/" style="display:inline-block;padding:10px 24px;background:#3766AB;color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:bold;">Enter Scores</a>
        </p>
      </div>
    </td>
  </tr>';
    };

    $build_generic_body = function() use ($schedule_url, $title) {
        return '
  <tr>
    <td style="padding:24px;">
      <p>Schedule for ' . esc_html($title) . ' is now available.</p>
      <p><a href="' . $schedule_url . '" style="color:#3766AB;">Click here to view the schedule.</a></p>
    </td>
  </tr>';
    };

    $all_players = $wpdb->get_results("
        SELECT s.user_id, s.first_name, s.last_name, s.user_phone, s.Rank,
               s.group_id, s.Crt_ID, s.time_id,
               g.GP_name, c.Crt_name, t.T_desc
        FROM Schedules s
        JOIN Groups g ON s.group_id = g.GP_ID
        JOIN Courts c ON s.Crt_ID = c.Crt_ID
        JOIN Times t ON s.time_id = t.T_ID
        WHERE s.group_id != 99
        ORDER BY s.group_id, s.Rank
    ", ARRAY_A);

    $groups     = array();
    $user_group = array();
    foreach ($all_players as $p) {
        $groups[$p['group_id']][]  = $p;
        $user_group[$p['user_id']] = $p['group_id'];
    }

    $master_players = $wpdb->get_results("
        SELECT user_id, first_name, last_name, user_email
        FROM Master
        WHERE user_email != ''
        ORDER BY last_name
    ", ARRAY_A);

    $sent_count    = 0;
    $fail_count    = 0;
    $generic_count = 0;

    foreach ($master_players as $member) {
        $uid   = $member['user_id'];
        $email = $member['user_email'];

        if ($test_mode && !in_array($email, $test_emails)) continue;

        $header = $build_header();
        $footer = $build_footer();

        if (isset($user_group[$uid])) {
            $gid      = $user_group[$uid];
            $players  = $groups[$gid];
            $pairings = count($players) >= 5 ? $pairings_5 : $pairings_4;
            $body     = $header . $build_group_table($players, $pairings, $uid) . $build_score_entry_row() . $footer;
        } else {
            $body = $header . $build_generic_body() . $footer;
            $generic_count++;
        }

        $sent_subject = $test_mode ? $subject . ' [TEST]' : $subject;
        $ok = wp_mail($email, $sent_subject, $body, $headers);
        if ($ok) $sent_count++; else $fail_count++;
    }

    if ($test_mode) {
        $test_list = implode(', ', $test_emails);
        echo '<br>' . ($sent_count ? "OK: Test email(s) sent to: {$test_list}" : 'FAILED: Test email failed') . '<br>';
    } else {
        echo "<br>OK: Personalized schedule emails sent to $sent_count players ($generic_count generic)." . ($fail_count > 0 ? " FAILED: $fail_count failed." : '') . '<br>';
    }
}
