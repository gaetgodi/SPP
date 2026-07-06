<?php
/**
 * SPP Switch Players
 *
 * Shortcode: [spp_switch_players]
 *
 * Admin/editor tool for switching two players between groups in the
 * published schedule. Three-stage flow:
 *   Stage 1 — dropdown form: select two players to swap
 *   Stage 2 — confirmation: show both players' current group/court/time
 *   Stage 3 — execute swap, send notifications to both affected groups
 *             and a summary to the convenor
 *
 * Notifications use the same email format as GL Publish Schedule:
 * personalized group table + pairings, with a "Your group has been
 * updated" banner instead of "You are playing tonight!".
 *
 * Version: 1.0.0
 * Date:    2026-07-06
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_switch_players', 'spp_switch_players_shortcode' );

function spp_switch_players_shortcode() {
    if ( ! spp_is_admin_or_editor() ) {
        return '<p>You do not have permission to access this tool.</p>';
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // ── Event and convenor details ────────────────────────────────────────────
    $event_id = (int) get_option( 'spp_current_event', 0 );
    $occ = $event_id ? $wpdb->get_row( $wpdb->prepare(
        "SELECT title, event_date, convenor_id
         FROM {$prefix}gl_event_occurrences WHERE id = %d",
        $event_id
    ), ARRAY_A ) : null;

    $event_date     = $occ ? date( 'F d, Y', strtotime( $occ['event_date'] ) ) : date( 'F d, Y' );
    $convenor_email = 'abrooks@rogers.com'; // fallback
    if ( $occ && $occ['convenor_id'] ) {
        $ce = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_email FROM membership WHERE user_id = %d", $occ['convenor_id']
        ) );
        if ( $ce ) $convenor_email = $ce;
    }
    // ─────────────────────────────────────────────────────────────────────────

    // ── Stage detection ───────────────────────────────────────────────────────
    $is_post    = ( $_SERVER['REQUEST_METHOD'] === 'POST' );
    $pl1        = $is_post ? intval( $_POST['Player1'] ?? 0 ) : 0;
    $pl2        = $is_post ? intval( $_POST['Player2'] ?? 0 ) : 0;
    $confirmed  = $is_post && isset( $_POST['sp_confirm'] ) && $_POST['sp_confirm'] === '1';
    $nonce_ok   = $is_post ? wp_verify_nonce( $_POST['sp_nonce'] ?? '', 'spp_switch_players' ) : false;
    // ─────────────────────────────────────────────────────────────────────────

    // ── Pairings tables (same as Publish Schedule) ────────────────────────────
    $pairings_5 = array(
        array( 'name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => 4  ),
        array( 'name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,4), 'bye' => 3  ),
        array( 'name' => 'Round 3', 'blue' => array(0,3), 'red' => array(2,4), 'bye' => 1  ),
        array( 'name' => 'Round 4', 'blue' => array(0,4), 'red' => array(1,3), 'bye' => 2  ),
        array( 'name' => 'Round 5', 'blue' => array(1,2), 'red' => array(3,4), 'bye' => 0  ),
    );
    $pairings_4 = array(
        array( 'name' => 'Round 1', 'blue' => array(0,1), 'red' => array(2,3), 'bye' => -1 ),
        array( 'name' => 'Round 2', 'blue' => array(0,2), 'red' => array(1,3), 'bye' => -1 ),
        array( 'name' => 'Round 3', 'blue' => array(0,3), 'red' => array(1,2), 'bye' => -1 ),
    );
    // ─────────────────────────────────────────────────────────────────────────

    // ── Email helpers ─────────────────────────────────────────────────────────
    $logo_url     = 'https://pickleballstouffville.ca/wp-content/uploads/2024/03/SPP_Logo-96DPI-8X-3.52-480x211.png';
    $schedule_url = 'https://pickleballstouffville.ca/gl-player-schedule-view/';
    $headers      = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Stouffville Pickleball Players <pb@pickleballstouffville.ca>',
    );

    $build_email_header = function() use ( $logo_url ) {
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

    $build_email_footer = function() use ( $schedule_url ) {
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

    // Build the group table block for one group (used in player emails and convenor summary)
    $build_group_block = function( $players, $pairings, $my_user_id = 0, $updated_banner = true ) {
        $p0   = $players[0];
        $html = '';

        if ( $updated_banner ) {
            $html .= '
  <tr>
    <td style="padding:16px 24px 4px 24px;">
      <div style="background:#e8f5e9;border:2px solid #27ae60;border-radius:6px;padding:10px 14px;margin-bottom:12px;">
        <p style="margin:0;font-size:15px;font-weight:bold;color:#27ae60;">&#10003; Your group has been updated</p>
        <p style="margin:4px 0 0 0;font-size:13px;color:#555;">A last-minute player switch has been made. Your updated group details are below.</p>
      </div>
    </td>
  </tr>';
        }

        $html .= '
  <tr>
    <td style="padding:4px 24px 8px 24px;">
      <div style="background:#2c3e50;color:#ffffff;padding:8px 12px;border-radius:6px 6px 0 0;font-weight:bold;font-size:15px;">
        ' . esc_html( $p0['GP_name'] ) . ' &mdash; ' . esc_html( $p0['Crt_name'] ) . ' &mdash; ' . esc_html( $p0['T_desc'] ) . '
      </div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;margin-bottom:12px;">
        <thead>
          <tr style="background:#3766AB;">
            <td style="color:#fff;padding:5px 8px;font-weight:bold;width:40px;">Rank</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;">Name</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;">Phone</td>
          </tr>
        </thead>
        <tbody>';

        foreach ( $players as $i => $p ) {
            $is_me  = ( $my_user_id && (int) $p['user_id'] === (int) $my_user_id );
            $me_tag = $is_me ? ' (me)' : '';
            $bg     = $is_me
                ? 'background:#e8f5e9;font-weight:bold;'
                : ( $i % 2 == 0 ? 'background:#f9f9f9;' : 'background:#ffffff;' );
            $html .= '
          <tr style="' . $bg . '">
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['Rank'] ) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . $me_tag . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;">' . esc_html( $p['user_phone'] ) . '</td>
          </tr>';
        }

        $html .= '
        </tbody>
      </table>

      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;">
        <thead>
          <tr style="background:#3766AB;">
            <td style="color:#fff;padding:5px 8px;font-weight:bold;width:70px;">Round</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;background:#5b8dd9;">Blue Team</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;background:#c0607a;">Red Team</td>
            <td style="color:#fff;padding:5px 8px;font-weight:bold;">Bye</td>
          </tr>
        </thead>
        <tbody>';

        $my_pos = -1;
        foreach ( $players as $i => $p ) {
            if ( $my_user_id && (int) $p['user_id'] === (int) $my_user_id ) {
                $my_pos = $i;
                break;
            }
        }

        foreach ( $pairings as $round ) {
            $blue_names = array_map( function( $i ) use ( $players ) {
                return isset( $players[$i] ) ? $players[$i]['first_name'] : '';
            }, $round['blue'] );
            $red_names = array_map( function( $i ) use ( $players ) {
                return isset( $players[$i] ) ? $players[$i]['first_name'] : '';
            }, $round['red'] );
            $bye_name = ( $round['bye'] >= 0 && isset( $players[$round['bye']] ) )
                ? $players[$round['bye']]['first_name'] : '-';

            $i_am_blue  = in_array( $my_pos, $round['blue'] );
            $i_am_red   = in_array( $my_pos, $round['red'] );
            $i_have_bye = ( $round['bye'] === $my_pos );

            $blue_bg = $i_am_blue  ? 'background:#aed8e6;font-weight:bold;' : 'background:#daeef5;';
            $red_bg  = $i_am_red   ? 'background:#f4a7b5;font-weight:bold;' : 'background:#fce4ec;';
            $bye_bg  = $i_have_bye ? 'background:#c5e1a5;font-weight:bold;' : 'background:#f5f5f5;';

            $html .= '
          <tr>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html( $round['name'] ) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $blue_bg . '">' . esc_html( implode( ' / ', $blue_names ) ) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $red_bg . '">' . esc_html( implode( ' / ', $red_names ) ) . '</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;' . $bye_bg . '">' . esc_html( $bye_name ) . '</td>
          </tr>';
        }

        $html .= '
        </tbody>
      </table>
    </td>
  </tr>';

        return $html;
    };
    // ─────────────────────────────────────────────────────────────────────────

    // ── Fetch all scheduled players for dropdown ──────────────────────────────
    $all_players = $wpdb->get_results(
        "SELECT user_id, first_name, last_name, group_id, Crt_ID, time_id, Rank
         FROM Schedules
         WHERE group_id != 99
         ORDER BY first_name, last_name",
        ARRAY_A
    );
    // ─────────────────────────────────────────────────────────────────────────

    ob_start();
    ?>
    <style>
        .sp-wrap { max-width:700px; margin:20px auto; font-family:Arial,sans-serif; font-size:15px; }
        .sp-heading { font-size:1.2rem; font-weight:bold; color:#2c3e50; margin-bottom:16px; border-bottom:2px solid #3766AB; padding-bottom:8px; }
        .sp-row { margin-bottom:14px; }
        .sp-row label { display:block; font-weight:bold; font-size:0.9rem; color:#555; margin-bottom:4px; }
        .sp-row select { padding:8px 10px; font-size:0.95rem; border:1px solid #ccc; border-radius:4px; width:100%; max-width:500px; box-sizing:border-box; }
        .sp-btn { padding:10px 20px; border:none; border-radius:5px; font-size:0.95rem; cursor:pointer; color:#fff; margin-right:8px; }
        .sp-btn-confirm { background:#3766AB; }
        .sp-btn-confirm:hover { background:#2a5290; }
        .sp-btn-execute { background:#c0392b; }
        .sp-btn-execute:hover { background:#a93226; }
        .sp-cancel { margin-left:16px; color:#888; font-size:14px; text-decoration:none; }
        .sp-confirm-box { background:#fdf3f2; border:2px solid #c0392b; border-radius:6px; padding:20px; margin:16px 0; }
        .sp-confirm-box h3 { color:#c0392b; margin:0 0 12px; }
        .sp-player-detail { background:#f0f7ff; border:1px solid #3766AB; border-radius:6px; padding:12px 16px; margin:8px 0; font-size:14px; }
        .sp-player-detail strong { color:#2c3e50; }
        .sp-arrow { text-align:center; font-size:24px; color:#3766AB; margin:4px 0; }
        .sp-msg-ok  { background:#d4edda; border:1px solid #28a745; color:#155724; padding:12px 16px; border-radius:6px; margin:12px 0; }
        .sp-msg-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; padding:12px 16px; border-radius:6px; margin:12px 0; }
    </style>

    <div class="sp-wrap">
        <div class="sp-heading">Switch Players in Schedule</div>
    <?php

    // ── Stage 3: Execute swap ─────────────────────────────────────────────────
    if ( $is_post && $confirmed && $nonce_ok && $pl1 && $pl2 ) {

        if ( $pl1 === $pl2 ) {
            echo '<div class="sp-msg-err">&#9888; Cannot swap a player with themselves.</div>';
        } else {
            $sched1 = $wpdb->get_row( $wpdb->prepare(
                "SELECT s.user_id, s.first_name, s.last_name, s.group_id, s.Crt_ID, s.time_id,
                        g.GP_name, c.Crt_name, t.T_desc
                 FROM Schedules s
                 JOIN Groups g ON s.group_id = g.GP_ID
                 JOIN Courts c ON s.Crt_ID = c.Crt_ID
                 JOIN Times t ON s.time_id = t.T_ID
                 WHERE s.user_id = %d AND s.group_id != 99", $pl1
            ), ARRAY_A );

            $sched2 = $wpdb->get_row( $wpdb->prepare(
                "SELECT s.user_id, s.first_name, s.last_name, s.group_id, s.Crt_ID, s.time_id,
                        g.GP_name, c.Crt_name, t.T_desc
                 FROM Schedules s
                 JOIN Groups g ON s.group_id = g.GP_ID
                 JOIN Courts c ON s.Crt_ID = c.Crt_ID
                 JOIN Times t ON s.time_id = t.T_ID
                 WHERE s.user_id = %d AND s.group_id != 99", $pl2
            ), ARRAY_A );

            if ( ! $sched1 || ! $sched2 ) {
                echo '<div class="sp-msg-err">&#9888; One or both players not found in schedule.</div>';
            } else {
                // Execute swap
                $r1 = $wpdb->query( $wpdb->prepare(
                    "UPDATE Schedules SET group_id = %d, Crt_ID = %d, time_id = %d WHERE user_id = %d",
                    $sched2['group_id'], $sched2['Crt_ID'], $sched2['time_id'], $pl1
                ) );
                $r2 = $wpdb->query( $wpdb->prepare(
                    "UPDATE Schedules SET group_id = %d, Crt_ID = %d, time_id = %d WHERE user_id = %d",
                    $sched1['group_id'], $sched1['Crt_ID'], $sched1['time_id'], $pl2
                ) );

                if ( $r1 === false || $r2 === false ) {
                    echo '<div class="sp-msg-err">&#9888; Swap failed — database error.</div>';
                } else {
                    $name1 = $sched1['first_name'] . ' ' . $sched1['last_name'];
                    $name2 = $sched2['first_name'] . ' ' . $sched2['last_name'];
                    echo '<div class="sp-msg-ok">&#10003; Players swapped successfully: '
                        . esc_html( $name1 ) . ' &#8596; ' . esc_html( $name2 ) . '</div>';

                    // ── Fetch updated groups ──────────────────────────────────
                    $gid1 = $sched2['group_id']; // pl1 is now in group 2
                    $gid2 = $sched1['group_id']; // pl2 is now in group 1

                    $fetch_group = function( $gid ) use ( $wpdb ) {
                        return $wpdb->get_results( $wpdb->prepare(
                            "SELECT s.user_id, s.first_name, s.last_name, s.Rank, s.group_id,
                                    m.user_email, m.user_phone,
                                    g.GP_name, c.Crt_name, t.T_desc
                             FROM Schedules s
                             JOIN Groups g ON s.group_id = g.GP_ID
                             JOIN Courts c ON s.Crt_ID = c.Crt_ID
                             JOIN Times t ON s.time_id = t.T_ID
                             LEFT JOIN membership m ON s.user_id = m.user_id
                             WHERE s.group_id = %d
                             ORDER BY s.Rank",
                            $gid
                        ), ARRAY_A );
                    };

                    $group1_players = $fetch_group( $gid1 );
                    $group2_players = $fetch_group( $gid2 );

                    $get_pairings = function( $players ) use ( $pairings_4, $pairings_5 ) {
                        return count( $players ) >= 5 ? $pairings_5 : $pairings_4;
                    };

                    $subject = "Stouffville Pickleball Players -- Schedule Change for {$event_date}";

                    $sent   = 0;
                    $failed = 0;

                    // Send to all players in both affected groups
                    foreach ( array( $group1_players, $group2_players ) as $group_players ) {
                        $pairings = $get_pairings( $group_players );
                        foreach ( $group_players as $player ) {
                            if ( empty( $player['user_email'] ) ) continue;
                            $body = $build_email_header()
                                  . $build_group_block( $group_players, $pairings, $player['user_id'], true )
                                  . $build_email_footer();
                            $ok = wp_mail( $player['user_email'], $subject, $body, $headers );
                            if ( $ok ) $sent++; else $failed++;
                        }
                    }

                    // Send convenor summary with both groups
                    $p1 = $get_pairings( $group1_players );
                    $p2 = $get_pairings( $group2_players );
                    $convenor_body = $build_email_header()
                        . '
  <tr>
    <td style="padding:16px 24px 4px 24px;">
      <p style="font-size:15px;font-weight:bold;color:#2c3e50;margin:0 0 4px 0;">Schedule Change Summary</p>
      <p style="font-size:14px;color:#555;margin:0 0 12px 0;">'
        . esc_html( $name1 ) . ' and ' . esc_html( $name2 ) . ' have been switched.</p>
    </td>
  </tr>'
                        . $build_group_block( $group1_players, $p1, 0, false )
                        . $build_group_block( $group2_players, $p2, 0, false )
                        . $build_email_footer();

                    $ok_convenor = wp_mail( $convenor_email, $subject . ' [SUMMARY]', $convenor_body, $headers );

                    echo '<p style="color:#555;font-size:14px;">Notifications sent to '
                        . $sent . ' player(s)'
                        . ( $failed ? ' (' . $failed . ' failed)' : '' )
                        . '. Convenor summary '
                        . ( $ok_convenor ? 'sent to ' . esc_html( $convenor_email ) : 'FAILED' )
                        . '.</p>';
                }
            }
        }

        // Show form again after execution
        echo '<p><a href="' . esc_url( $_SERVER['REQUEST_URI'] ) . '">&#8592; Make another switch</a></p>';

    // ── Stage 2: Confirmation ─────────────────────────────────────────────────
    } elseif ( $is_post && $nonce_ok && $pl1 && $pl2 && ! $confirmed ) {

        if ( $pl1 === $pl2 ) {
            echo '<div class="sp-msg-err">&#9888; Cannot swap a player with themselves.</div>';
        } else {
            $sched1 = $wpdb->get_row( $wpdb->prepare(
                "SELECT s.user_id, s.first_name, s.last_name, s.group_id,
                        g.GP_name, c.Crt_name, t.T_desc
                 FROM Schedules s
                 JOIN Groups g ON s.group_id = g.GP_ID
                 JOIN Courts c ON s.Crt_ID = c.Crt_ID
                 JOIN Times t ON s.time_id = t.T_ID
                 WHERE s.user_id = %d AND s.group_id != 99", $pl1
            ), ARRAY_A );

            $sched2 = $wpdb->get_row( $wpdb->prepare(
                "SELECT s.user_id, s.first_name, s.last_name, s.group_id,
                        g.GP_name, c.Crt_name, t.T_desc
                 FROM Schedules s
                 JOIN Groups g ON s.group_id = g.GP_ID
                 JOIN Courts c ON s.Crt_ID = c.Crt_ID
                 JOIN Times t ON s.time_id = t.T_ID
                 WHERE s.user_id = %d AND s.group_id != 99", $pl2
            ), ARRAY_A );

            if ( ! $sched1 || ! $sched2 ) {
                echo '<div class="sp-msg-err">&#9888; One or both players not found in schedule.</div>';
            } else {
                $name1 = $sched1['first_name'] . ' ' . $sched1['last_name'];
                $name2 = $sched2['first_name'] . ' ' . $sched2['last_name'];
                ?>
                <div class="sp-confirm-box">
                    <h3>Confirm Player Switch</h3>
                    <p>You are about to swap these two players:</p>

                    <div class="sp-player-detail">
                        <strong><?php echo esc_html( $name1 ); ?></strong><br>
                        Currently: <?php echo esc_html( $sched1['GP_name'] . ' &mdash; ' . $sched1['Crt_name'] . ' &mdash; ' . $sched1['T_desc'] ); ?>
                    </div>
                    <div class="sp-arrow">&#8597;</div>
                    <div class="sp-player-detail">
                        <strong><?php echo esc_html( $name2 ); ?></strong><br>
                        Currently: <?php echo esc_html( $sched2['GP_name'] . ' &mdash; ' . $sched2['Crt_name'] . ' &mdash; ' . $sched2['T_desc'] ); ?>
                    </div>

                    <p style="margin-top:14px;color:#c0392b;font-weight:bold;">
                        All players in both affected groups will be notified by email.
                    </p>
                </div>
                <form method="post">
                    <?php wp_nonce_field( 'spp_switch_players', 'sp_nonce' ); ?>
                    <input type="hidden" name="Player1" value="<?php echo esc_attr( $pl1 ); ?>">
                    <input type="hidden" name="Player2" value="<?php echo esc_attr( $pl2 ); ?>">
                    <input type="hidden" name="sp_confirm" value="1">
                    <button type="submit" class="sp-btn sp-btn-execute">Yes, Switch Players and Notify</button>
                    <a href="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" class="sp-cancel">Cancel</a>
                </form>
                <?php
            }
        }

    // ── Stage 1: Dropdown form ────────────────────────────────────────────────
    } else {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'spp_switch_players', 'sp_nonce' ); ?>

            <div class="sp-row">
                <label for="sp-player1">Player 1</label>
                <select name="Player1" id="sp-player1">
                    <option value="0">-- Select player --</option>
                    <?php foreach ( $all_players as $p ) : ?>
                        <option value="<?php echo esc_attr( $p['user_id'] ); ?>">
                            <?php echo esc_html( $p['first_name'] . ' ' . $p['last_name'] . ' (Rank ' . $p['Rank'] . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sp-row">
                <label for="sp-player2">Player 2</label>
                <select name="Player2" id="sp-player2">
                    <option value="0">-- Select player --</option>
                    <?php foreach ( $all_players as $p ) : ?>
                        <option value="<?php echo esc_attr( $p['user_id'] ); ?>">
                            <?php echo esc_html( $p['first_name'] . ' ' . $p['last_name'] . ' (Rank ' . $p['Rank'] . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sp-row">
                <button type="submit" class="sp-btn sp-btn-confirm">Review Switch</button>
            </div>
        </form>
        <?php
    }

    echo '</div>'; // sp-wrap
    return ob_get_clean();
}
