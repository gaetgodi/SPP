<?php
/* =========================================================
   Ace/Queen of the Courts — End Event confirmation + Reopen
   Version: 1.1.0
   Date: 2026-10-18

   Changes from 1.0.0 -- 60-minute window instead of "same Toronto day":
   - Reopen is allowed until ended_at + 60 minutes (SPP_KQ_REOPEN_WINDOW_
     SECONDS), checked server-side against the stored reopen_until
     (written by End as ended_at + 60 min); refused from that second on.
   - Finalization runs once, at or after reopen_until, from a new
     5-minute WP-cron sweep ('spp_kq_finalize_sweep', schedule
     'spp_kq_five_minutes'). The 1.0.0 hourly 'spp_kq_finalize_due'
     event is unscheduled.
   - An end-state row with status 'ended' but no reopen_until (written by
     1.0.0) is never finalized automatically and can't be reopened -- it
     needs an administrator. None existed at deploy.
   - A run left 'finalizing' for 15+ minutes (SPP_KQ_FINALIZE_STALL_
     SECONDS) sends ONE alert email to the site admin_email
     (spp_kq_send_stall_alert(), stall_alerted_at recorded first, under
     the lock). The run itself is never retried automatically.
   - spp_kq_end_finalize_pending() now means "ended, not finalized yet"
     ('ended' or 'finalizing') -- used by the doubleheader check in
     inc/spp-kq-format-ranking.php 1.6.0.
   - Complete screen: "You can reopen until 4:42 pm"; after the window,
     "Results are being processed" / "Results were processed at …". The
     page reloads itself when the window closes. End warning wording
     updated to match.

   1.0.0 -- new. A moderator ended a live event by mistake while also
   trying to fix wrongly entered scores; End Event was a one-click
   confirm() and could not be undone.

   END (typed confirmation):
   - The Overview's End Event button opens a short warning (what ending
     does, how many courts of the current round have no score) and a
     box where the moderator must type END (case-insensitive, trimmed).
     spp_kq_handle_post_actions()'s 'end_event' case checks the typed
     word server-side (spp_kq_end_confirm_matches()), on top of the
     nonce; anything else ends nothing.
   - The form also carries spp_kq_end_gen (how many times the event has
     been reopened). A stale End page resubmitted after a Reopen has the
     old count and is refused.

   DEFERRED FINALIZATION -- why:
   End used to run, in the same request: permanent spp_kq_history rows,
   one recap email per player, the Glicko Club Rating publish, the
   Ace/Queen format-ranking update (EMA averages + inactivity misses,
   all usermeta) and a membership-table rebuild. None of that can be
   cleanly undone (emails are sent; the format EMA has no snapshot; the
   Glicko rollback aborts once a later event builds on it). So End only
   flips phase organizing -> complete and records who/when; all of the
   above runs later (spp_kq_run_event_finalization()), once the Reopen
   window has closed for good. Reopen therefore only has to flip phase
   back. Cancel Event is unchanged (finalizes immediately, no Reopen).

   REOPEN: complete -> organizing, same current_round (CAS on both),
   only inside the window, only for an event ended through this file.
   Same gate as End: spp_kq_can_facilitate(). The form carries the
   ended_at it was rendered with; a mismatch (ended again since) is
   refused. A second click after a successful Reopen is a silent no-op.

   STORE: one row per occurrence in wp_options, name
   spp_kq_end_state_{occurrence_id}, JSON, autoload off -- read and
   written with direct SQL (never get_option()/update_option(): the
   Redis object cache could hand back a stale copy inside the lock).
   Holds status (ended / reopened / finalizing / finalized), round,
   event_date, ended_at/ended_by/ended_day/reopen_until, finalize
   result, stall_alerted_at, and a log of every end/reopen/finalize.

   CONCURRENCY: End, Reopen, the finalizer's claim and the stall alert's
   claim all run under one MySQL named lock (spp_kq_end_lock()), and End/
   Reopen still use the events row CAS. The finalizer marks a row
   'finalizing' under the lock before doing any work, so it runs at
   most once; a crash mid-run leaves it 'finalizing' (never retried --
   no double emails) and triggers the one alert email.

   TEST HOOKS (tests/spp-kq-end-reopen-test.php only): the store table
   ('spp_kq_end_store_table'), the clock ('spp_kq_end_now') and the KQ
   table names ('spp_kq_table_name', inc/spp-kq-schema.php) are
   filterable; SPP_KQ_NO_CRON_SCHEDULE skips the cron registration.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

const SPP_KQ_END_CONFIRM_WORD        = 'END';
const SPP_KQ_END_STATE_PREFIX        = 'spp_kq_end_state_';
const SPP_KQ_REOPEN_WINDOW_SECONDS   = 3600;
const SPP_KQ_FINALIZE_STALL_SECONDS  = 900;

// =============================================================
// Store, clock, lock
// =============================================================

function spp_kq_end_store_table() : string {
    global $wpdb;
    return (string) apply_filters( 'spp_kq_end_store_table', $wpdb->options );
}

/** Current time as a real Unix timestamp (filterable for tests). */
function spp_kq_end_now() : int {
    return (int) apply_filters( 'spp_kq_end_now', time() );
}

/** Site-local (America/Toronto) calendar day of a Unix timestamp. */
function spp_kq_end_local_day( int $ts ) : string {
    return wp_date( 'Y-m-d', $ts );
}

function spp_kq_end_state_get( int $occurrence_id ) : ?array {
    global $wpdb;
    $raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM " . spp_kq_end_store_table() . " WHERE option_name = %s",
        SPP_KQ_END_STATE_PREFIX . $occurrence_id
    ) );
    if ( $raw === null ) {
        return null;
    }
    $state = json_decode( $raw, true );
    return is_array( $state ) ? $state : null;
}

function spp_kq_end_state_put( int $occurrence_id, array $state ) : bool {
    global $wpdb;
    $name = SPP_KQ_END_STATE_PREFIX . $occurrence_id;
    $ok = $wpdb->query( $wpdb->prepare(
        "INSERT INTO " . spp_kq_end_store_table() . " (option_name, option_value, autoload) VALUES (%s, %s, 'off')
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        $name, wp_json_encode( $state )
    ) );
    wp_cache_delete( $name, 'options' );
    return $ok !== false;
}

/** Every end-state row: occurrence_id => state. */
function spp_kq_end_state_all() : array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_name, option_value FROM " . spp_kq_end_store_table() . " WHERE option_name LIKE %s",
        $wpdb->esc_like( SPP_KQ_END_STATE_PREFIX ) . '%'
    ), ARRAY_A );
    $out = array();
    foreach ( $rows as $r ) {
        $occurrence_id = (int) substr( $r['option_name'], strlen( SPP_KQ_END_STATE_PREFIX ) );
        $state = json_decode( $r['option_value'], true );
        if ( $occurrence_id > 0 && is_array( $state ) ) {
            $out[ $occurrence_id ] = $state;
        }
    }
    return $out;
}

function spp_kq_end_lock_name() : string {
    return substr( 'spp_kq_end_reopen:' . spp_kq_end_store_table(), 0, 64 );
}

function spp_kq_end_lock() : bool {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 10)", spp_kq_end_lock_name() ) ) === '1';
}

function spp_kq_end_unlock() : void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", spp_kq_end_lock_name() ) );
}

/** How many times this event has been reopened (the End form's spp_kq_end_gen). */
function spp_kq_end_reopen_count( ?array $end_state ) : int {
    if ( ! $end_state || empty( $end_state['log'] ) ) {
        return 0;
    }
    return count( array_filter( $end_state['log'], fn( $e ) => ( $e['action'] ?? '' ) === 'reopen' ) );
}

/** True while an ended event has not finished finalizing ('ended' or 'finalizing'). */
function spp_kq_end_finalize_pending( int $occurrence_id ) : bool {
    $s = spp_kq_end_state_get( $occurrence_id );
    return $s !== null && in_array( $s['status'] ?? '', array( 'ended', 'finalizing' ), true );
}

/** Short local time, e.g. "4:42 pm"; with the date when not today. */
function spp_kq_end_when( int $ts ) : string {
    $same_day = spp_kq_end_local_day( $ts ) === spp_kq_end_local_day( spp_kq_end_now() );
    return $same_day ? wp_date( 'g:i a', $ts ) : wp_date( 'l, F j \a\t g:i a', $ts );
}

// =============================================================
// End
// =============================================================

/** The typed confirmation: END, any case, surrounding spaces ignored. */
function spp_kq_end_confirm_matches( $raw ) : bool {
    if ( ! is_string( $raw ) ) {
        return false;
    }
    $typed = preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', wp_unslash( $raw ) );
    return is_string( $typed ) && strtoupper( $typed ) === SPP_KQ_END_CONFIRM_WORD;
}

/** What is still unfinished in the current round, for the End warning. */
function spp_kq_end_unfinished_summary( int $occurrence_id, int $round ) : array {
    $p = spp_kq_get_round_progress( $occurrence_id, $round );
    return array(
        'round'    => $round,
        'total'    => $p['total'],
        'reported' => $p['reported'],
        'missing'  => $p['total'] - $p['reported'],
    );
}

/**
 * End Event with the typed confirmation already checked by the caller.
 * Only the phase flip + the store record; finalization is deferred (see
 * file header). If the record can't be written the phase flip is undone,
 * so an event can never sit 'complete' with nothing to finalize it.
 *
 * @return array ['won'=>bool, 'error'=>?string, 'reopen_until'=>int]
 */
function spp_kq_end_event_confirmed( int $occurrence_id, int $expected_round, string $event_date, int $user_id, int $expected_gen ) : array {
    if ( ! spp_kq_end_lock() ) {
        return array( 'won' => false, 'error' => 'The event is busy -- please try again.', 'reopen_until' => 0 );
    }
    try {
        $end_state = spp_kq_end_state_get( $occurrence_id );
        if ( $expected_gen !== spp_kq_end_reopen_count( $end_state ) ) {
            return array( 'won' => false, 'error' => 'This page was out of date. The event was not ended -- check the screen and try again.', 'reopen_until' => 0 );
        }

        $r = spp_kq_transition_end_event( $occurrence_id, $expected_round );
        if ( ! $r['won'] ) {
            return array( 'won' => false, 'error' => null, 'reopen_until' => 0 );
        }

        $now   = spp_kq_end_now();
        $state = $end_state ?? array();
        unset( $state['stall_alerted_at'], $state['finalize_started_at'] );
        $state['status']       = 'ended';
        $state['round']        = $expected_round;
        $state['event_date']   = $event_date;
        $state['ended_at']     = $now;
        $state['ended_by']     = $user_id;
        $state['ended_day']    = spp_kq_end_local_day( $now );
        $state['reopen_until'] = $now + SPP_KQ_REOPEN_WINDOW_SECONDS;
        $state['log']          = $state['log'] ?? array();
        $state['log'][]        = array( 'action' => 'end', 'by' => $user_id, 'at' => $now, 'round' => $expected_round );

        if ( ! spp_kq_end_state_put( $occurrence_id, $state ) ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . spp_kq_events_table() . " SET phase = 'organizing'
                 WHERE occurrence_id = %d AND current_round = %d AND phase = 'complete'",
                $occurrence_id, $expected_round
            ) );
            return array( 'won' => false, 'error' => 'The event could not be ended -- please try again.', 'reopen_until' => 0 );
        }
        return array( 'won' => true, 'error' => null, 'reopen_until' => $state['reopen_until'] );
    } finally {
        spp_kq_end_unlock();
    }
}

// =============================================================
// Reopen
// =============================================================

const SPP_KQ_REOPEN_CONTACT_ADMIN = 'This event can no longer be reopened here. Please contact the administrator.';

/**
 * Whether Reopen is allowed right now: until (not at) reopen_until.
 * Shared by the button's visibility and the server-side action so the
 * two can't disagree.
 *
 * @return array ['allowed'=>bool, 'reason'=>string]
 */
function spp_kq_reopen_status( ?array $event_state, ?array $end_state, int $now ) : array {
    if ( ! $event_state || $event_state['phase'] !== 'complete' ) {
        return array( 'allowed' => false, 'reason' => 'This event is not ended.' );
    }
    if ( ! $end_state || ( $end_state['status'] ?? '' ) !== 'ended'
        || empty( $end_state['reopen_until'] )
        || (int) ( $end_state['round'] ?? -1 ) !== (int) $event_state['current_round'] ) {
        return array( 'allowed' => false, 'reason' => SPP_KQ_REOPEN_CONTACT_ADMIN );
    }
    if ( $now >= (int) $end_state['reopen_until'] ) {
        return array( 'allowed' => false, 'reason' => 'Reopen was only possible for 60 minutes after the event ended. Please contact the administrator.' );
    }
    return array( 'allowed' => true, 'reason' => '' );
}

/**
 * Reopen an ended event: complete -> organizing, same round. Nothing else
 * to undo -- End wrote nothing else (see file header).
 *
 * @param int $expected_ended_at The ended_at the Reopen form was rendered
 *   with; a mismatch means the event was ended again since.
 * @return array ['won'=>bool, 'error'=>?string, 'round'=>int]
 */
function spp_kq_reopen_event( int $occurrence_id, int $user_id, int $expected_ended_at ) : array {
    if ( ! spp_kq_end_lock() ) {
        return array( 'won' => false, 'error' => 'The event is busy -- please try again.', 'round' => 0 );
    }
    try {
        $event_state = spp_kq_get_event_state( $occurrence_id );
        $end_state   = spp_kq_end_state_get( $occurrence_id );

        // Double click / two moderators: already reopened -> silent no-op.
        if ( $event_state && $event_state['phase'] !== 'complete' && $end_state && ( $end_state['status'] ?? '' ) === 'reopened' ) {
            return array( 'won' => false, 'error' => null, 'round' => (int) $event_state['current_round'] );
        }

        $now   = spp_kq_end_now();
        $check = spp_kq_reopen_status( $event_state, $end_state, $now );
        if ( ! $check['allowed'] ) {
            return array( 'won' => false, 'error' => $check['reason'], 'round' => 0 );
        }
        if ( (int) $end_state['ended_at'] !== $expected_ended_at ) {
            return array( 'won' => false, 'error' => 'This page was out of date. Nothing was changed -- check the screen and try again.', 'round' => 0 );
        }

        global $wpdb;
        $round    = (int) $end_state['round'];
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE " . spp_kq_events_table() . " SET phase = 'organizing'
             WHERE occurrence_id = %d AND current_round = %d AND phase = 'complete'",
            $occurrence_id, $round
        ) );
        if ( (int) $affected !== 1 ) {
            return array( 'won' => false, 'error' => null, 'round' => $round );
        }

        $end_state['status']      = 'reopened';
        $end_state['reopened_at'] = $now;
        $end_state['reopened_by'] = $user_id;
        $end_state['log'][]       = array( 'action' => 'reopen', 'by' => $user_id, 'at' => $now, 'round' => $round );

        if ( ! spp_kq_end_state_put( $occurrence_id, $end_state ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . spp_kq_events_table() . " SET phase = 'complete'
                 WHERE occurrence_id = %d AND current_round = %d AND phase = 'organizing'",
                $occurrence_id, $round
            ) );
            return array( 'won' => false, 'error' => 'The event could not be reopened -- please try again.', 'round' => 0 );
        }
        return array( 'won' => true, 'error' => null, 'round' => $round );
    } finally {
        spp_kq_end_unlock();
    }
}

// =============================================================
// Finalization (deferred for End, immediate for Cancel)
// =============================================================

/**
 * Everything End Event used to run inline (inc/spp-kq-screens.php 1.35.0
 * and earlier), unchanged and in the same order: history archive + recap
 * emails first (format rankings read the archive), then Club Ratings,
 * then format rankings, then the membership rebuild when either changed
 * usermeta. Also called directly by 'cancel_event'.
 */
function spp_kq_run_event_finalization( int $occurrence_id, string $event_date ) : string {
    $history_notice = spp_kq_finalize_event_history_and_recap( $occurrence_id, $event_date );
    $rating_result  = spp_kq_maybe_publish_to_club_ratings( $occurrence_id, $event_date );
    $format_result  = spp_kq_update_format_rankings( $occurrence_id, $event_date );
    if ( $rating_result['published'] || $format_result['updated'] ) {
        spp_create_membership_table();
    }
    return trim( $rating_result['notice'] . ( $format_result['notice'] !== '' ? ' ' . $format_result['notice'] : '' ) . ( $history_notice !== '' ? ' ' . $history_notice : '' ) );
}

/**
 * Finalize one ended event once its Reopen window has closed. Claims the
 * row ('finalizing') under the lock first, so it can only ever run once.
 *
 * @return string|null The finalization notice, or null if nothing ran.
 */
function spp_kq_finalize_ended_event( int $occurrence_id ) : ?string {
    if ( ! spp_kq_end_lock() ) {
        return null;
    }
    try {
        $end_state   = spp_kq_end_state_get( $occurrence_id );
        $event_state = spp_kq_get_event_state( $occurrence_id );
        if ( ! $end_state || ( $end_state['status'] ?? '' ) !== 'ended' || empty( $end_state['reopen_until'] ) ) {
            return null; // not ended, already claimed, or a 1.0.0 row (left for an administrator)
        }
        if ( ! $event_state || $event_state['phase'] !== 'complete' ) {
            return null;
        }
        $now = spp_kq_end_now();
        if ( $now < (int) $end_state['reopen_until'] ) {
            return null; // Reopen window still open
        }
        $end_state['status']              = 'finalizing';
        $end_state['finalize_started_at'] = $now;
        if ( ! spp_kq_end_state_put( $occurrence_id, $end_state ) ) {
            return null;
        }
    } finally {
        spp_kq_end_unlock();
    }

    $notice = spp_kq_run_event_finalization( $occurrence_id, (string) $end_state['event_date'] );

    // Only this run can move a 'finalizing' row on, so no lock is needed.
    $end_state = spp_kq_end_state_get( $occurrence_id ) ?? $end_state;
    $end_state['status']          = 'finalized';
    $end_state['finalized_at']    = spp_kq_end_now();
    $end_state['finalize_notice'] = $notice;
    $end_state['log'][]           = array( 'action' => 'finalize', 'by' => 0, 'at' => $end_state['finalized_at'], 'round' => (int) $end_state['round'] );
    spp_kq_end_state_put( $occurrence_id, $end_state );

    return $notice;
}

/**
 * One alert email to the site admin for a run stuck in 'finalizing' for
 * SPP_KQ_FINALIZE_STALL_SECONDS or more. stall_alerted_at is written under
 * the lock BEFORE sending, so it goes out once per event even if two
 * sweeps overlap. Never touches the run itself.
 *
 * @return bool True if this call sent (claimed) the alert.
 */
function spp_kq_send_stall_alert( int $occurrence_id ) : bool {
    if ( ! spp_kq_end_lock() ) {
        return false;
    }
    try {
        $end_state = spp_kq_end_state_get( $occurrence_id );
        $now       = spp_kq_end_now();
        if ( ! $end_state || ( $end_state['status'] ?? '' ) !== 'finalizing' || ! empty( $end_state['stall_alerted_at'] )
            || $now - (int) ( $end_state['finalize_started_at'] ?? $now ) < SPP_KQ_FINALIZE_STALL_SECONDS ) {
            return false;
        }
        $end_state['stall_alerted_at'] = $now;
        if ( ! spp_kq_end_state_put( $occurrence_id, $end_state ) ) {
            return false;
        }
    } finally {
        spp_kq_end_unlock();
    }

    global $wpdb;
    $started  = wp_date( 'Y-m-d g:i a', (int) $end_state['finalize_started_at'] );
    $subject  = "SPP: Ace/Queen event {$occurrence_id} ({$end_state['event_date']}) did not finish finalizing";
    $body     = "The Ace/Queen of the Courts event {$occurrence_id} ({$end_state['event_date']}) started finalizing at {$started} and has not finished.\n\n"
        . "State (option " . SPP_KQ_END_STATE_PREFIX . "{$occurrence_id}):\n" . wp_json_encode( $end_state, JSON_PRETTY_PRINT ) . "\n\n"
        . "It will NOT be retried automatically. Before re-running anything, check which steps finished:\n"
        . "1. History: SELECT COUNT(*) FROM {$wpdb->prefix}spp_kq_history WHERE occurrence_id = {$occurrence_id};\n"
        . "2. Recap emails: the mail log for \"Recap for {$end_state['event_date']}\" (sent right after history).\n"
        . "3. Club Rating: SELECT * FROM club_rating_event_log WHERE event_id = {$occurrence_id};\n"
        . "4. Format rankings: spp_kq_ace_avg / spp_kq_queen_avg usermeta of that day's players.\n"
        . "5. PHP error log around {$started}.\n"
        . "Re-running a step that already ran would double count it (or re-send emails).\n";
    wp_mail( get_option( 'admin_email' ), $subject, $body );
    return true;
}

/**
 * The 5-minute sweep: finalize every ended event whose window has closed,
 * and alert once on any run stuck in 'finalizing'.
 *
 * @return array ['finalized' => [occ => notice], 'alerted' => [occ, ...]]
 */
function spp_kq_finalize_due_events() : array {
    $done = array( 'finalized' => array(), 'alerted' => array() );
    foreach ( spp_kq_end_state_all() as $occurrence_id => $state ) {
        $status = $state['status'] ?? '';
        if ( $status === 'ended' && ! empty( $state['reopen_until'] ) && spp_kq_end_now() >= (int) $state['reopen_until'] ) {
            $notice = spp_kq_finalize_ended_event( $occurrence_id );
            if ( $notice !== null ) {
                $done['finalized'][ $occurrence_id ] = $notice;
            }
        } elseif ( $status === 'finalizing' && empty( $state['stall_alerted_at'] ) ) {
            if ( spp_kq_send_stall_alert( $occurrence_id ) ) {
                $done['alerted'][] = $occurrence_id;
            }
        }
    }
    return $done;
}
add_action( 'spp_kq_finalize_sweep', 'spp_kq_finalize_due_events' );

add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['spp_kq_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (KQ finalize sweep)' );
    return $schedules;
} );

if ( ! defined( 'SPP_KQ_NO_CRON_SCHEDULE' ) ) {
    add_action( 'init', function () {
        if ( wp_next_scheduled( 'spp_kq_finalize_due' ) ) {
            wp_clear_scheduled_hook( 'spp_kq_finalize_due' ); // 1.0.0's hourly event
        }
        if ( ! wp_next_scheduled( 'spp_kq_finalize_sweep' ) ) {
            wp_schedule_event( time(), 'spp_kq_five_minutes', 'spp_kq_finalize_sweep' );
        }
    } );
}

// =============================================================
// Screens
// =============================================================

function spp_kq_end_user_name( int $user_id ) : string {
    $u = $user_id ? get_userdata( $user_id ) : false;
    return $u ? $u->display_name : 'unknown';
}

/**
 * The Overview's End Event control: a <details> that opens the warning
 * and the type-END form. Works without JavaScript; the script only keeps
 * the button disabled until END is typed (the server re-checks anyway).
 */
function spp_kq_render_end_confirm( int $occurrence_id, int $round ) : string {
    $u   = spp_kq_end_unfinished_summary( $occurrence_id, $round );
    $gen = spp_kq_end_reopen_count( spp_kq_end_state_get( $occurrence_id ) );
    ob_start();
    ?>
    <details class="kq-end-confirm" style="margin-top:16px;text-align:right;">
        <summary class="kq-btn kq-btn-secondary" style="display:inline-block;list-style:none;cursor:pointer;">End Event&hellip;</summary>
        <div class="kq-warn" style="margin-top:10px;text-align:left;">
            <p style="margin:0 0 6px;"><strong>End the event?</strong></p>
            <ul style="margin:0 0 10px 18px;padding:0;">
                <li>No more rounds can be played.</li>
                <?php if ( $u['total'] > 0 && $u['reported'] === 0 ) : ?>
                    <li>Round <?php echo esc_html( $u['round'] ); ?> is set up but not played. It will not count.</li>
                <?php elseif ( $u['missing'] > 0 ) : ?>
                    <li>Round <?php echo esc_html( $u['round'] ); ?>: <?php echo esc_html( $u['missing'] ); ?> of <?php echo esc_html( $u['total'] ); ?> courts have no score. Those games will not count.</li>
                <?php endif; ?>
                <li>Scores can no longer be corrected. Fix any wrong score first (View Full Scoreboard).</li>
                <li>Ended by mistake? You can reopen it for 60 minutes.</li>
                <li>After that, results are saved, Club Ratings are updated and players get their recap email.</li>
            </ul>
            <form method="post" class="kq-end-confirm-form">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="end_event">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
                <input type="hidden" name="spp_kq_end_gen" value="<?php echo esc_attr( $gen ); ?>">
                <label style="display:block;margin-bottom:8px;">Type <strong>END</strong> to confirm<br>
                    <input type="text" name="spp_kq_end_confirm" class="kq-end-confirm-input" autocomplete="off" autocapitalize="characters" spellcheck="false" required
                           style="width:120px;padding:8px;font-size:16px;border:1px solid #bbb;border-radius:6px;margin-top:4px;">
                </label>
                <button type="submit" class="kq-btn kq-btn-danger kq-end-confirm-btn">End Event</button>
            </form>
        </div>
    </details>
    <script>
    (function() {
        var input = document.querySelector('.kq-end-confirm-input');
        var btn = document.querySelector('.kq-end-confirm-btn');
        if (!input || !btn) return;
        function sync() { btn.disabled = input.value.trim().toUpperCase() !== 'END'; btn.style.opacity = btn.disabled ? '.5' : '1'; }
        input.addEventListener('input', sync);
        sync();
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Complete screen panel: who ended it and when, Reopen while allowed,
 * then the processing status. Events ended before 1.0.0 (already
 * finalized at End) have no record and show nothing here.
 */
function spp_kq_render_ended_panel( int $occurrence_id ) : string {
    $end_state = spp_kq_end_state_get( $occurrence_id );
    if ( ! $end_state || empty( $end_state['ended_at'] ) ) {
        return '';
    }
    $event_state = spp_kq_get_event_state( $occurrence_id );
    $now         = spp_kq_end_now();
    $status      = $end_state['status'] ?? '';
    $reopens     = array_values( array_filter( $end_state['log'] ?? array(), fn( $e ) => ( $e['action'] ?? '' ) === 'reopen' ) );
    $check       = spp_kq_reopen_status( $event_state, $end_state, $now );
    $can_reopen  = $check['allowed'] && spp_kq_can_facilitate();
    $until       = (int) ( $end_state['reopen_until'] ?? 0 );

    ob_start();
    ?>
    <div class="kq-ended-panel" style="margin:0 0 14px;">
        <p class="kq-meta" style="margin:0 0 4px;">Ended at <?php echo esc_html( spp_kq_end_when( (int) $end_state['ended_at'] ) ); ?> by <?php echo esc_html( spp_kq_end_user_name( (int) $end_state['ended_by'] ) ); ?>.</p>
        <?php if ( $reopens ) : $last = end( $reopens ); ?>
            <p class="kq-hint" style="margin:0 0 4px;">Reopened earlier at <?php echo esc_html( spp_kq_end_when( (int) $last['at'] ) ); ?> by <?php echo esc_html( spp_kq_end_user_name( (int) $last['by'] ) ); ?>.</p>
        <?php endif; ?>
        <?php if ( $status === 'ended' && $check['allowed'] ) : ?>
            <p class="kq-hint" style="margin:0 0 8px;">Results, Club Ratings and recap emails go out after <?php echo esc_html( spp_kq_end_when( $until ) ); ?>.</p>
            <?php if ( $can_reopen ) : ?>
                <form method="post" class="kq-inline-form kq-reopen-form" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( 'Reopen this event? It goes back to Round ' . (int) $end_state['round'] . ' and End Event is available again.' ) ); ?>);">
                    <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                    <input type="hidden" name="spp_kq_action" value="reopen_event">
                    <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( (int) $end_state['round'] ); ?>">
                    <input type="hidden" name="spp_kq_ended_at" value="<?php echo esc_attr( (int) $end_state['ended_at'] ); ?>">
                    <span class="kq-hint" style="display:block;margin:0 0 6px;">Ended by mistake? You can reopen until <?php echo esc_html( spp_kq_end_when( $until ) ); ?>.</span>
                    <button type="submit" class="kq-btn kq-btn-primary">Reopen event</button>
                </form>
            <?php endif; ?>
            <script>
            (function() {
                // Reload when another device reopens it, and when the window closes.
                if (window.__kqEndedPoll) clearInterval(window.__kqEndedPoll);
                if (window.__kqEndedTimer) clearTimeout(window.__kqEndedTimer);
                var body = new FormData();
                body.append('action', 'spp_kq_poll_status');
                body.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>);
                body.append('occ', <?php echo wp_json_encode( (string) $occurrence_id ); ?>);
                window.__kqEndedPoll = setInterval(function() {
                    fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d && d.success && d.data.phase !== 'complete') {
                                clearInterval(window.__kqEndedPoll);
                                window.location.replace(window.location.href);
                            }
                        })
                        .catch(function() {});
                }, 10000);
                window.__kqEndedTimer = setTimeout(function() {
                    window.location.replace(window.location.href);
                }, <?php echo (int) max( 0, ( $until - $now ) * 1000 + 2000 ); ?>);
                if (window.SppKqLiveApp) {
                    window.SppKqLiveApp.cleanup = function() { clearInterval(window.__kqEndedPoll); clearTimeout(window.__kqEndedTimer); };
                }
            })();
            </script>
        <?php elseif ( $status === 'ended' && ! empty( $end_state['reopen_until'] ) ) : ?>
            <p class="kq-hint" style="margin:0;">Reopen closed at <?php echo esc_html( spp_kq_end_when( $until ) ); ?>. Results, Club Ratings and recap emails are being processed (within a few minutes).</p>
        <?php elseif ( $status === 'finalizing' ) : ?>
            <p class="kq-hint" style="margin:0;">Results, Club Ratings and recap emails are being processed now.</p>
        <?php elseif ( $status === 'finalized' ) : ?>
            <p class="kq-hint" style="margin:0;">Results were processed at <?php echo esc_html( spp_kq_end_when( (int) $end_state['finalized_at'] ) ); ?>. The event can no longer be reopened.</p>
        <?php else : ?>
            <p class="kq-hint" style="margin:0;"><?php echo esc_html( $check['reason'] ); ?></p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
