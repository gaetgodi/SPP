<?php
/* =========================================================
   Ace/Queen of the Courts — End Event confirmation + Reopen
   Version: 1.0.0
   Date: 2026-10-18

   1.0.0 -- new. A moderator ended a live event by mistake while also
   trying to fix wrongly entered scores; End Event was a one-click
   confirm() and could not be undone.

   END (typed confirmation):
   - The Overview's End Event button now opens a short warning (what
     ending does, how many courts of the current round have no score)
     and a box where the moderator must type END (case-insensitive,
     trimmed). spp_kq_handle_post_actions()'s 'end_event' case checks
     the typed word server-side (spp_kq_end_confirm_matches()), on top
     of the existing nonce; anything else ends nothing.
   - The form also carries spp_kq_end_gen (how many times the event has
     been reopened). A stale End page resubmitted after a Reopen has the
     old count and is refused, so a browser "resubmit form" can't end
     the event a second time behind someone's back.

   DEFERRED FINALIZATION -- why:
   End used to run, in the same request: permanent spp_kq_history rows,
   one recap email per player, the Glicko Club Rating publish, the
   Ace/Queen format-ranking update (EMA averages + inactivity misses,
   all usermeta) and a membership-table rebuild. None of that can be
   cleanly undone (emails are sent; the format EMA has no snapshot; the
   Glicko rollback aborts once a later event builds on it). So End now
   only flips phase organizing -> complete and records who/when; all of
   the above runs later from spp_kq_finalize_due_events() (hourly
   WP-cron 'spp_kq_finalize_due'), once the site-local calendar day the
   event was ended on (America/Toronto) has passed -- i.e. after the
   Reopen window has closed for good. Reopen therefore only has to flip
   phase back; nothing else was written. Cancel Event is unchanged
   (still finalizes immediately, no Reopen).

   REOPEN:
   - complete -> organizing, same current_round (CAS on both), only on
     the same local day the event was ended, only for an event ended
     through this file (an older event already finalized at End time
     can't be reopened -- the message says to contact the
     administrator). Same gate as End: spp_kq_can_facilitate().
   - The form carries the ended_at it was rendered with; a Reopen whose
     ended_at no longer matches (the event was reopened and ended again)
     is refused. A second click after a successful Reopen is a silent
     no-op.

   STORE: one row per occurrence in wp_options, name
   spp_kq_end_state_{occurrence_id}, JSON, autoload off -- read and
   written with direct SQL (never get_option()/update_option(): the
   Redis object cache could hand back a stale copy inside the lock).
   Holds status (ended / reopened / finalizing / finalized), round,
   event_date, ended_at/ended_by/ended_day, finalize result, and a log
   of every end/reopen/finalize with user id and time.

   CONCURRENCY: End, Reopen and the finalizer's claim all run under one
   MySQL named lock (spp_kq_end_lock()), and each still uses the events
   row CAS. The finalizer marks a row 'finalizing' under the lock
   before doing any work, so it runs at most once; a crash mid-run
   leaves it 'finalizing' (never retried automatically -- no double
   emails), which needs an administrator to look.

   TEST HOOKS (tests/spp-kq-end-reopen-test.php only): the store table
   ('spp_kq_end_store_table'), the clock ('spp_kq_end_now') and the KQ
   table names ('spp_kq_table_name', inc/spp-kq-schema.php) are
   filterable; SPP_KQ_NO_CRON_SCHEDULE skips the cron registration.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

const SPP_KQ_END_CONFIRM_WORD  = 'END';
const SPP_KQ_END_STATE_PREFIX  = 'spp_kq_end_state_';

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

/** True while an ended event is waiting for its deferred finalization. */
function spp_kq_end_finalize_pending( int $occurrence_id ) : bool {
    $s = spp_kq_end_state_get( $occurrence_id );
    return $s !== null && ( $s['status'] ?? '' ) === 'ended';
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
 * @return array ['won'=>bool, 'error'=>?string]
 */
function spp_kq_end_event_confirmed( int $occurrence_id, int $expected_round, string $event_date, int $user_id, int $expected_gen ) : array {
    if ( ! spp_kq_end_lock() ) {
        return array( 'won' => false, 'error' => 'The event is busy -- please try again.' );
    }
    try {
        $end_state = spp_kq_end_state_get( $occurrence_id );
        if ( $expected_gen !== spp_kq_end_reopen_count( $end_state ) ) {
            return array( 'won' => false, 'error' => 'This page was out of date. The event was not ended -- check the screen and try again.' );
        }

        $r = spp_kq_transition_end_event( $occurrence_id, $expected_round );
        if ( ! $r['won'] ) {
            return array( 'won' => false, 'error' => null );
        }

        $now   = spp_kq_end_now();
        $state = $end_state ?? array();
        $state['status']     = 'ended';
        $state['round']      = $expected_round;
        $state['event_date'] = $event_date;
        $state['ended_at']   = $now;
        $state['ended_by']   = $user_id;
        $state['ended_day']  = spp_kq_end_local_day( $now );
        $state['log']        = $state['log'] ?? array();
        $state['log'][]      = array( 'action' => 'end', 'by' => $user_id, 'at' => $now, 'round' => $expected_round );

        if ( ! spp_kq_end_state_put( $occurrence_id, $state ) ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . spp_kq_events_table() . " SET phase = 'organizing'
                 WHERE occurrence_id = %d AND current_round = %d AND phase = 'complete'",
                $occurrence_id, $expected_round
            ) );
            return array( 'won' => false, 'error' => 'The event could not be ended -- please try again.' );
        }
        return array( 'won' => true, 'error' => null );
    } finally {
        spp_kq_end_unlock();
    }
}

// =============================================================
// Reopen
// =============================================================

const SPP_KQ_REOPEN_CONTACT_ADMIN = 'This event can no longer be reopened here. Please contact the administrator.';

/**
 * Whether Reopen is allowed right now. Shared by the button's visibility
 * and the server-side action so the two can't disagree.
 *
 * @return array ['allowed'=>bool, 'reason'=>string]
 */
function spp_kq_reopen_status( ?array $event_state, ?array $end_state, int $now ) : array {
    if ( ! $event_state || $event_state['phase'] !== 'complete' ) {
        return array( 'allowed' => false, 'reason' => 'This event is not ended.' );
    }
    if ( ! $end_state || ( $end_state['status'] ?? '' ) !== 'ended'
        || (int) ( $end_state['round'] ?? -1 ) !== (int) $event_state['current_round'] ) {
        return array( 'allowed' => false, 'reason' => SPP_KQ_REOPEN_CONTACT_ADMIN );
    }
    if ( spp_kq_end_local_day( $now ) !== ( $end_state['ended_day'] ?? '' ) ) {
        return array( 'allowed' => false, 'reason' => 'Reopen is only possible on the day the event was ended. Please contact the administrator.' );
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
 * Finalize one ended event if its Reopen day has passed. Claims the row
 * ('finalizing') under the lock first, so it can only ever run once.
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
        if ( ! $end_state || ( $end_state['status'] ?? '' ) !== 'ended' ) {
            return null;
        }
        if ( ! $event_state || $event_state['phase'] !== 'complete' ) {
            return null;
        }
        $now = spp_kq_end_now();
        if ( spp_kq_end_local_day( $now ) <= ( $end_state['ended_day'] ?? '' ) ) {
            return null; // still the Reopen day
        }
        $end_state['status']               = 'finalizing';
        $end_state['finalize_started_at']  = $now;
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

/** Hourly sweep: finalize every ended event whose Reopen day has passed. */
function spp_kq_finalize_due_events() : array {
    global $wpdb;
    $names = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM " . spp_kq_end_store_table() . " WHERE option_name LIKE %s",
        $wpdb->esc_like( SPP_KQ_END_STATE_PREFIX ) . '%'
    ) );
    $done = array();
    foreach ( $names as $name ) {
        $occurrence_id = (int) substr( $name, strlen( SPP_KQ_END_STATE_PREFIX ) );
        if ( $occurrence_id <= 0 || ! spp_kq_end_finalize_pending( $occurrence_id ) ) {
            continue;
        }
        $notice = spp_kq_finalize_ended_event( $occurrence_id );
        if ( $notice !== null ) {
            $done[ $occurrence_id ] = $notice;
        }
    }
    return $done;
}
add_action( 'spp_kq_finalize_due', 'spp_kq_finalize_due_events' );

if ( ! defined( 'SPP_KQ_NO_CRON_SCHEDULE' ) && ! wp_next_scheduled( 'spp_kq_finalize_due' ) ) {
    wp_schedule_event( time(), 'hourly', 'spp_kq_finalize_due' );
}

// =============================================================
// Screens
// =============================================================

function spp_kq_end_user_name( int $user_id ) : string {
    $u = $user_id ? get_userdata( $user_id ) : false;
    return $u ? $u->display_name : 'unknown';
}

/** Short local time for "ended at": time only today, date + time otherwise. */
function spp_kq_end_when( int $ts ) : string {
    $same_day = spp_kq_end_local_day( $ts ) === spp_kq_end_local_day( spp_kq_end_now() );
    return $same_day ? wp_date( 'g:ia', $ts ) : wp_date( 'l, F j \a\t g:ia', $ts );
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
                <li>After midnight tonight, results are saved, Club Ratings are updated and players get their recap email.</li>
                <li>Ended by mistake? You can reopen it until midnight tonight.</li>
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
 * Complete screen panel: who ended it and when, and Reopen while allowed.
 * Legacy events (ended before 1.0.0, already finalized at End) have no
 * record and show nothing here.
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

    ob_start();
    ?>
    <div class="kq-ended-panel" style="margin:0 0 14px;">
        <p class="kq-meta" style="margin:0 0 4px;">Ended at <?php echo esc_html( spp_kq_end_when( (int) $end_state['ended_at'] ) ); ?> by <?php echo esc_html( spp_kq_end_user_name( (int) $end_state['ended_by'] ) ); ?>.</p>
        <?php if ( $reopens ) : $last = end( $reopens ); ?>
            <p class="kq-hint" style="margin:0 0 4px;">Reopened earlier at <?php echo esc_html( spp_kq_end_when( (int) $last['at'] ) ); ?> by <?php echo esc_html( spp_kq_end_user_name( (int) $last['by'] ) ); ?>.</p>
        <?php endif; ?>
        <?php if ( $status === 'ended' ) : ?>
            <p class="kq-hint" style="margin:0 0 8px;">Results, Club Ratings and recap emails go out after midnight.</p>
            <?php if ( $can_reopen ) : ?>
                <form method="post" class="kq-inline-form kq-reopen-form" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( 'Reopen this event? It goes back to Round ' . (int) $end_state['round'] . ' and End Event is available again.' ) ); ?>);">
                    <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                    <input type="hidden" name="spp_kq_action" value="reopen_event">
                    <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( (int) $end_state['round'] ); ?>">
                    <input type="hidden" name="spp_kq_ended_at" value="<?php echo esc_attr( (int) $end_state['ended_at'] ); ?>">
                    <span class="kq-hint" style="display:block;margin:0 0 6px;">Ended by mistake? You can reopen it until midnight tonight.</span>
                    <button type="submit" class="kq-btn kq-btn-primary">Reopen event</button>
                </form>
                <script>
                (function() {
                    // Another device may reopen it: go back to the live screen when it does.
                    if (window.__kqEndedPoll) clearInterval(window.__kqEndedPoll);
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
                    if (window.SppKqLiveApp) {
                        window.SppKqLiveApp.cleanup = function() { clearInterval(window.__kqEndedPoll); };
                    }
                })();
                </script>
            <?php else : ?>
                <p class="kq-hint" style="margin:0;"><?php echo esc_html( $check['reason'] ); ?></p>
            <?php endif; ?>
        <?php elseif ( $status === 'finalizing' ) : ?>
            <p class="kq-hint" style="margin:0;">Results are being saved now.</p>
        <?php elseif ( $status === 'finalized' ) : ?>
            <p class="kq-hint" style="margin:0;">Results were processed <?php echo esc_html( spp_kq_end_when( (int) $end_state['finalized_at'] ) ); ?>. The event can no longer be reopened.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
