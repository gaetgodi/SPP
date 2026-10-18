<?php
/* =========================================================
   Ace/Queen of the Courts — Save All + Fix a mistake
   Version: 1.0.0
   Date: 2026-10-18

   1.0.0 -- new. Score entry and correction improvements.

   Principles (Gaetan): a game that has been played stays as played --
   no correction ever changes a later round's scores, and no round that
   has a score is rebuilt (spp_kq_transition_rebuild_round() refuses it,
   inc/spp-kq-live.php 1.16.0). Calm, plain wording; red only when
   something actually failed. Permissions unchanged:
   spp_kq_can_facilitate() everywhere.

   SAVE ALL (In-Play screen): spp_kq_save_scores_bulk() saves every court
   with a complete, valid score through the unchanged per-court path
   (spp_kq_submit_court_score(): same in_play/current-round guard, same
   client_ts ordering guard, same last-court advance). A court with a
   problem gets a plain message in place and is not saved; blank courts
   are skipped. Courts that already had a score are written first, so
   the round can only advance on the last newly filled court, after
   every overwrite in the same batch has landed. A repeat of the same
   batch (double click) finds the values already stored and reports
   them as saved.

   AFTER A CORRECTION OR A SWAP in round N (spp_kq_next_round_followup()):
   - round N+1 not set up yet: nothing to do;
   - N+1 has a score (played): left untouched; the courts the current
     round-N data would give are compared with what was played, and the
     moderator is told either "Later rounds are not affected." or to use
     Swap players on Round N+1 if different players actually played;
   - N+1 has no score and hasn't started (organizing): rebuilt from the
     corrected data if the courts would differ (same Rebuild Round
     transition), otherwise left as is;
   - N+1 has no score but Start Play was already pressed (in_play): NOT
     rebuilt automatically -- people are on court. The message offers
     "Set up Round N+1 again" on Fix a mistake.

   FIX A MISTAKE (?kq_view=fix; inside the live app it is swapped in as
   a fragment like the Full Scoreboard, so sound/timers survive): one
   screen reachable from In-Play, Overview and Full Scoreboard with
   correct a score / swap players / set up the next round again / replay
   a round / throw out a round, each with a one-line "use this when".
   Every action posts through the existing handlers (spp_kq_correct_
   score AJAX, spp_kq_handle_post_actions() via spp_kq_live_action) and
   shows its result plus what to do next.

   ENDED EVENTS: spp_kq_render_ended_fix_note() -- no corrections while
   ended; inside the 60-minute window it points to Reopen, after it to
   the administrator (Complete screen and Fix a mistake).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

// =============================================================
// Helpers
// =============================================================

/** True if any non-cancelled court of round $n has a real score. */
function spp_kq_round_has_any_score( int $occurrence_id, int $n ) : bool {
    return spp_kq_get_round_progress( $occurrence_id, $n )['reported'] > 0;
}

/**
 * Order-independent description of a lineup: court => sorted list of
 * "a:b" pairs. Two lineups with the same signature put the same people
 * on the same courts with the same partners (team colour ignored).
 */
function spp_kq_lineup_signature( array $rows ) : array {
    $teams = array();
    foreach ( $rows as $r ) {
        if ( empty( $r['user_id'] ) ) {
            continue;
        }
        $teams[ $r['court_name'] ][ $r['team_color'] ][] = (int) $r['user_id'];
    }
    $sig = array();
    foreach ( $teams as $court => $by_color ) {
        $pairs = array();
        foreach ( $by_color as $ids ) {
            sort( $ids );
            $pairs[] = implode( ':', $ids );
        }
        sort( $pairs );
        $sig[ $court ] = $pairs;
    }
    ksort( $sig );
    return $sig;
}

/**
 * Would round $n's current data produce exactly the stored round $n+1?
 *
 * @return bool|null null if the comparison can't be made (e.g. a tie).
 */
function spp_kq_next_round_matches( int $occurrence_id, int $n ) : ?bool {
    try {
        $computed = spp_kq_compute_round_after( $occurrence_id, $n );
    } catch ( SPP_KQ_Movement_Error $e ) {
        return null;
    }
    $cancelled = spp_kq_get_cancelled_courts( $occurrence_id, $n + 1 );
    $stored    = array_values( array_filter(
        spp_kq_get_round_assignments( $occurrence_id, $n + 1 ),
        fn( $a ) => ! in_array( $a['court_name'], $cancelled, true )
    ) );
    return spp_kq_lineup_signature( $computed['next'] ) === spp_kq_lineup_signature( $stored );
}

/**
 * What happens to round $n+1 after round $n's scores or players changed.
 * Never touches a round that has a score. See file header.
 *
 * @return array ['kind' => none|same|played_same|played_differs|
 *   played_unknown|rebuilt|in_play|failed, 'message' => string]
 */
function spp_kq_next_round_followup( int $occurrence_id, int $n, string $because = 'score' ) : array {
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state || ! in_array( $state['phase'], array( 'organizing', 'in_play' ), true ) ) {
        return array( 'kind' => 'none', 'message' => '' );
    }
    $current = (int) $state['current_round'];
    $next    = $n + 1;

    if ( $next > $current ) {
        return array( 'kind' => 'none', 'message' => '' );
    }

    if ( spp_kq_round_has_any_score( $occurrence_id, $next ) ) {
        $matches = spp_kq_next_round_matches( $occurrence_id, $n );
        if ( $matches === true ) {
            return array( 'kind' => 'played_same', 'message' => 'Later rounds are not affected.' );
        }
        if ( $matches === false ) {
            $set_from = $because === 'swap' ? 'before this swap' : 'from the earlier scores';
            return array( 'kind' => 'played_differs', 'message' => "Round {$next} was played with courts set {$set_from}. If different players actually played, use Swap players on Round {$next} to put in the players who actually played." );
        }
        return array( 'kind' => 'played_unknown', 'message' => "Round {$next} was already played, so it stays as it is." );
    }

    // Round $next has no score yet.
    if ( $next !== $current || spp_kq_count_active_courts( $occurrence_id, $next ) === 0 ) {
        return array( 'kind' => 'none', 'message' => 'Later rounds are not affected.' );
    }
    $matches = spp_kq_next_round_matches( $occurrence_id, $n );
    if ( $matches === true ) {
        return array( 'kind' => 'same', 'message' => "Round {$next} stays the same: the courts don't change." );
    }
    if ( $matches === null ) {
        return array( 'kind' => 'none', 'message' => '' );
    }
    if ( $state['phase'] === 'in_play' ) {
        return array( 'kind' => 'in_play', 'message' => "Round {$next} has already started with courts set " . ( $because === 'swap' ? 'before this swap' : 'from the earlier scores' ) . ". If it should use the new courts, use \"Set up Round {$next} again\" on Fix a mistake before anyone enters a score." );
    }

    $announced = $state['courts_announced_at'] === null ? null : (int) $state['courts_announced_at'];
    $r = spp_kq_transition_rebuild_round( $occurrence_id, $next, $current, $announced );
    if ( $r['won'] ) {
        return array( 'kind' => 'rebuilt', 'message' => "Round {$next} hadn't started, so it was set up again with the right courts. Go back to the game to see them." );
    }
    return array( 'kind' => 'failed', 'message' => "Round {$next} could not be set up again automatically" . ( $r['error'] ? ': ' . $r['error'] : '.' ) . " You can do it from Fix a mistake." );
}

/**
 * Correction from Full Scoreboard / Fix a mistake: save the score (the
 * unchanged spp_kq_correct_court_score()), then the round-N+1 follow-up.
 * Later rounds' scores are never written.
 */
function spp_kq_correct_score_with_followup( int $occurrence_id, int $round, string $court, int $red, int $black, int $user_id ) : array {
    $before = spp_kq_get_court_score( $occurrence_id, $round, $court );
    $result = spp_kq_correct_court_score( $occurrence_id, $round, $court, $red, $black, $user_id );
    if ( ! $result['success'] ) {
        return $result;
    }
    $changed = ! $before || (int) $before['red_score'] !== $red || (int) $before['black_score'] !== $black;
    $follow  = $changed ? spp_kq_next_round_followup( $occurrence_id, $round ) : array( 'kind' => 'none', 'message' => '' );
    $result['followup']      = $follow;
    $result['followup_kind'] = $follow['kind'];
    $result['message']       = trim( "Round {$round}, {$court}: saved as Red {$red} \u{2013} Black {$black}. " . $follow['message'] );
    return $result;
}

/**
 * Calm, plain problem text for a typed score pair, or null if fine.
 * Blank-on-both-sides is not a problem (the court is simply skipped).
 */
function spp_kq_score_entry_problem( string $red, string $black ) : ?string {
    $red   = trim( $red );
    $black = trim( $black );
    if ( $red === '' && $black === '' ) {
        return null;
    }
    if ( $red === '' || $black === '' ) {
        return "Enter both teams' scores.";
    }
    if ( ! ctype_digit( $red ) || ! ctype_digit( $black ) ) {
        return 'Scores are whole numbers from 0 to 11.';
    }
    $r = (int) $red;
    $b = (int) $black;
    if ( $r > 11 || $b > 11 ) {
        return 'Scores go from 0 to 11.';
    }
    if ( $r === 11 && $b === 11 ) {
        return "Both teams can't have 11: the game ends when one team reaches 11.";
    }
    if ( $r === $b ) {
        return "The scores can't be equal: one team has to win.";
    }
    return null;
}

/**
 * Save All. $entries: court_name => ['red' => string, 'black' => string]
 * as typed. Returns per-court results: status saved|problem|blank, plus
 * a message for problems.
 *
 * @return array ['results' => [court => ['status','message','red_score',
 *   'black_score']], 'saved' => int, 'problems' => int, 'reported' => int,
 *   'total' => int, 'advanced' => bool]
 */
function spp_kq_save_scores_bulk( int $occurrence_id, int $round, array $entries, int $user_id, int $client_ts ) : array {
    $results  = array();
    $advanced = false;

    // Courts that already have a score first: the round can then only
    // advance on the last newly filled court, after every overwrite.
    $stored = array();
    foreach ( spp_kq_get_round_scores( $occurrence_id, $round ) as $s ) {
        $stored[ $s['court_name'] ] = $s;
    }
    uksort( $entries, function ( $a, $b ) use ( $stored ) {
        $ha = isset( $stored[ $a ] ) && $stored[ $a ]['red_score'] !== null ? 0 : 1;
        $hb = isset( $stored[ $b ] ) && $stored[ $b ]['red_score'] !== null ? 0 : 1;
        return $ha <=> $hb;
    } );

    foreach ( $entries as $court => $e ) {
        $court = (string) $court;
        $red   = trim( (string) ( $e['red'] ?? '' ) );
        $black = trim( (string) ( $e['black'] ?? '' ) );
        if ( $red === '' && $black === '' ) {
            $results[ $court ] = array( 'status' => 'blank', 'message' => '' );
            continue;
        }
        $problem = spp_kq_score_entry_problem( $red, $black );
        if ( $problem !== null ) {
            $results[ $court ] = array( 'status' => 'problem', 'message' => $problem );
            continue;
        }
        $r = (int) $red;
        $b = (int) $black;
        $res = spp_kq_submit_court_score( $occurrence_id, $round, $court, $r, $b, $user_id, $client_ts );
        if ( $res['success'] ) {
            $advanced = $advanced || ! empty( $res['advanced'] );
            if ( isset( $res['applied'] ) && $res['applied'] === false ) {
                $same = (int) $res['red_score'] === $r && (int) $res['black_score'] === $b;
                $results[ $court ] = array(
                    'status'      => 'saved',
                    'message'     => $same ? '' : 'A newer score was already saved for this court.',
                    'red_score'   => $res['red_score'],
                    'black_score' => $res['black_score'],
                );
            } else {
                $results[ $court ] = array( 'status' => 'saved', 'message' => '', 'red_score' => $r, 'black_score' => $b );
            }
            continue;
        }
        // Refused. Already stored with these exact values (double click,
        // or the round finished with them) counts as saved.
        $now = spp_kq_get_court_score( $occurrence_id, $round, $court );
        if ( $now && $now['red_score'] !== null && (int) $now['red_score'] === $r && (int) $now['black_score'] === $b ) {
            $results[ $court ] = array( 'status' => 'saved', 'message' => '', 'red_score' => $r, 'black_score' => $b );
            continue;
        }
        $state = spp_kq_get_event_state( $occurrence_id );
        $moved = ! $state || $state['phase'] !== 'in_play' || (int) $state['current_round'] !== $round;
        $results[ $court ] = array(
            'status'  => 'problem',
            'message' => $moved
                ? "This round has finished, so this score wasn't saved. To change it, use Fix a mistake."
                : $res['error'],
        );
    }

    $progress = spp_kq_get_round_progress( $occurrence_id, $round );
    return array(
        'results'  => $results,
        'saved'    => count( array_filter( $results, fn( $x ) => $x['status'] === 'saved' ) ),
        'problems' => count( array_filter( $results, fn( $x ) => $x['status'] === 'problem' ) ),
        'reported' => $progress['reported'],
        'total'    => $progress['total'],
        'advanced' => $advanced,
    );
}

add_action( 'wp_ajax_spp_kq_submit_scores_bulk', function () {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );
    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $round         = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
    $client_ts     = isset( $_POST['client_ts'] ) ? (int) $_POST['client_ts'] : 0;
    $entries       = json_decode( wp_unslash( $_POST['entries'] ?? '' ), true );
    if ( ! $occurrence_id || ! $round || ! is_array( $entries ) ) {
        wp_send_json_error( 'Missing parameters.' );
    }
    $clean = array();
    foreach ( $entries as $court => $e ) {
        if ( ! is_array( $e ) ) {
            continue;
        }
        $clean[ sanitize_text_field( (string) $court ) ] = array(
            'red'   => sanitize_text_field( (string) ( $e['red'] ?? '' ) ),
            'black' => sanitize_text_field( (string) ( $e['black'] ?? '' ) ),
        );
    }
    wp_send_json_success( spp_kq_save_scores_bulk( $occurrence_id, $round, $clean, get_current_user_id(), $client_ts ) );
} );

// =============================================================
// Ended events
// =============================================================

/** "No corrections while ended", pointing to Reopen or the administrator. */
function spp_kq_render_ended_fix_note( int $occurrence_id ) : string {
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state ) {
        return '';
    }
    if ( $state['phase'] === 'cancelled' ) {
        return '<p class="kq-hint kq-ended-fix-note" style="margin:0 0 14px;">Scores can\'t be changed for a cancelled event. If a score needs fixing, contact the administrator.</p>';
    }
    if ( $state['phase'] !== 'complete' ) {
        return '';
    }
    $end_state = spp_kq_end_state_get( $occurrence_id );
    $check     = spp_kq_reopen_status( $state, $end_state, spp_kq_end_now() );
    $text = $check['allowed']
        ? 'Scores can\'t be changed while the event is ended. To fix one, reopen the event (until ' . spp_kq_end_when( (int) $end_state['reopen_until'] ) . '), then use Fix a mistake.'
        : 'Scores can\'t be changed now that the event is ended. If a score needs fixing, contact the administrator.';
    return '<p class="kq-hint kq-ended-fix-note" style="margin:0 0 14px;">' . esc_html( $text ) . '</p>';
}

// =============================================================
// Fix a mistake screen
// =============================================================

/** Entry link, used on In-Play, Overview and Full Scoreboard. */
function spp_kq_render_fix_link() : string {
    return '<p class="kq-hint" style="margin:0 0 14px;"><a class="kq-fix-link" href="' . esc_url( add_query_arg( 'kq_view', 'fix' ) ) . '">Fix a mistake&hellip;</a> (wrong score, wrong players, replay a round)</p>';
}

/** Fragment version for the live app: Back to game + the screen. */
function spp_kq_render_fix_fragment( int $occurrence_id ) : string {
    ob_start();
    ?>
    <p class="kq-hint"><a href="#" id="kq-back-to-game-link">&laquo; Back to game</a></p>
    <?php echo spp_kq_render_fix_screen( $occurrence_id ); ?>
    <script>
    (function() {
        var backLink = document.getElementById('kq-back-to-game-link');
        if (!backLink) return;
        backLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.SppKqLiveApp && SppKqLiveApp.refreshNow) SppKqLiveApp.refreshNow();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

function spp_kq_render_fix_screen( int $occurrence_id ) : string {
    $state = spp_kq_get_event_state( $occurrence_id );
    ob_start();
    ?>
    <div class="kq-fix-screen">
    <p class="kq-round-label">Fix a mistake</p>
    <div class="kq-status" id="kq-fix-result" style="display:none;"></div>
    <?php
    if ( ! $state || ! in_array( $state['phase'], array( 'organizing', 'in_play' ), true ) ) {
        if ( $state && in_array( $state['phase'], array( 'complete', 'cancelled' ), true ) ) {
            echo spp_kq_render_ended_fix_note( $occurrence_id );
        } else {
            echo '<p class="kq-hint">Nothing to fix yet: no round has been played.</p>';
        }
        echo '</div>' . spp_kq_render_fix_script( $occurrence_id, 0 );
        return ob_get_clean();
    }

    $current   = (int) $state['current_round'];
    $phase     = $state['phase'];
    $announced = $state['courts_announced_at'] === null ? '' : (string) (int) $state['courts_announced_at'];

    $scored_rounds = array();
    for ( $n = $current; $n >= 1; $n-- ) {
        if ( spp_kq_round_has_any_score( $occurrence_id, $n ) ) {
            $scored_rounds[] = $n;
        }
    }
    $swap_rounds  = spp_kq_get_past_swap_rounds( $occurrence_id, $current );
    $can_rebuild  = $current >= SPP_KQ_REBUILD_ROUND_MIN
        && ! spp_kq_round_has_any_score( $occurrence_id, $current )
        && spp_kq_round_is_complete( $occurrence_id, $current - 1 );
    $reset_targets = array();
    if ( $phase === 'organizing' ) {
        for ( $n = $current - 1; $n >= SPP_KQ_RESET_ROUND_MIN; $n-- ) {
            if ( spp_kq_count_active_courts( $occurrence_id, $n ) > 0 ) {
                $reset_targets[] = $n;
            }
        }
    }
    $void_rounds = array();
    for ( $n = $current; $n >= SPP_KQ_VOID_ROUND_MIN; $n-- ) {
        if ( spp_kq_round_has_any_score( $occurrence_id, $n ) ) {
            $void_rounds[] = $n;
        }
    }
    $box = 'border:1px solid #ddd;border-radius:8px;padding:12px 14px;margin:0 0 14px;background:#fff;';
    ?>
    <p class="kq-hint">Pick what went wrong. Games that were played always stay as played.</p>

    <!-- 1. Correct a score -->
    <div class="kq-fix-section" style="<?php echo esc_attr( $box ); ?>">
        <p style="margin:0 0 4px;"><strong>Correct a score</strong></p>
        <p class="kq-hint" style="margin:0 0 8px;">Use this when a score was entered wrong. Later rounds' scores are never changed.</p>
        <?php if ( ! $scored_rounds ) : ?>
            <p class="kq-hint" style="margin:0;">No scores have been entered yet.</p>
        <?php else : ?>
            <label class="kq-duration-label">Round<br>
                <select class="kq-fix-round-select" data-group="correct">
                    <option value="">&mdash; Pick a round &mdash;</option>
                    <?php foreach ( $scored_rounds as $n ) : ?>
                        <option value="<?php echo esc_attr( $n ); ?>">Round <?php echo esc_html( $n ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach ( $scored_rounds as $n ) :
                $view   = spp_kq_get_round_court_view( $occurrence_id, $n );
                $scores = array();
                foreach ( spp_kq_get_round_scores( $occurrence_id, $n ) as $s ) {
                    $scores[ $s['court_name'] ] = $s;
                }
            ?>
                <div class="kq-fix-round" data-group="correct" data-round="<?php echo esc_attr( $n ); ?>" hidden>
                    <?php foreach ( $view as $court => $teams ) :
                        $s = $scores[ $court ] ?? null;
                        if ( ! $s || $s['red_score'] === null ) continue; // only scored courts can be corrected
                    ?>
                        <div class="kq-court-card kq-fix-correct-row" style="margin-top:8px;" data-round="<?php echo esc_attr( $n ); ?>" data-court="<?php echo esc_attr( $court ); ?>">
                            <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                            <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                            <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>
                            <div class="kq-score-row">
                                <label>Red<br><input type="number" class="kq-score-input kq-fix-red" min="0" max="11" inputmode="numeric" pattern="[0-9]*" value="<?php echo esc_attr( $s['red_score'] ); ?>"></label>
                                <label>Black<br><input type="number" class="kq-score-input kq-fix-black" min="0" max="11" inputmode="numeric" pattern="[0-9]*" value="<?php echo esc_attr( $s['black_score'] ); ?>"></label>
                                <button type="button" class="kq-btn kq-btn-primary kq-fix-correct-btn">Save</button>
                            </div>
                            <p class="kq-hint kq-fix-row-msg" style="margin:6px 0 0;display:none;"></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 2. Swap players -->
    <div class="kq-fix-section" style="<?php echo esc_attr( $box ); ?>">
        <p style="margin:0 0 4px;"><strong>Swap players in a round</strong></p>
        <p class="kq-hint" style="margin:0 0 8px;">Use this when two players played in each other's places (wrong court or wrong team). Scores stay as entered; they now count for the players who actually played.</p>
        <?php if ( ! $swap_rounds ) : ?>
            <p class="kq-hint" style="margin:0;">Not available yet.</p>
        <?php else : ?>
            <label class="kq-duration-label">Round<br>
                <select class="kq-fix-round-select" data-group="swap">
                    <option value="">&mdash; Pick a round &mdash;</option>
                    <?php foreach ( $swap_rounds as $n ) : ?>
                        <option value="<?php echo esc_attr( $n ); ?>">Round <?php echo esc_html( $n ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach ( $swap_rounds as $n ) : ?>
                <div class="kq-fix-round" data-group="swap" data-round="<?php echo esc_attr( $n ); ?>" hidden>
                    <?php echo preg_replace( '/ hidden>/', '>', spp_kq_render_past_swap_round( $occurrence_id, $n, $current ), 1 ); // show the wrapper; its swap forms stay hidden until toggled ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 3. Set up the next round again -->
    <div class="kq-fix-section" style="<?php echo esc_attr( $box ); ?>">
        <p style="margin:0 0 4px;"><strong>Set up Round <?php echo esc_html( $current ); ?> again</strong></p>
        <p class="kq-hint" style="margin:0 0 8px;">Use this when Round <?php echo esc_html( $current ); ?>'s courts came from a wrong score and nobody has entered a score in Round <?php echo esc_html( $current ); ?> yet. It works out new courts from Round <?php echo esc_html( max( 1, $current - 1 ) ); ?>'s scores.</p>
        <?php if ( $can_rebuild ) : ?>
            <form method="post" class="kq-inline-form kq-fix-form"
                  data-confirm="<?php echo esc_attr( "Set up Round {$current} again from Round " . ( $current - 1 ) . "'s scores? Everyone gets new courts, then the short rest starts." ); ?>">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="rebuild_round">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $current ); ?>">
                <input type="hidden" name="spp_kq_target_round" value="<?php echo esc_attr( $current ); ?>">
                <input type="hidden" name="spp_kq_expected_announced" value="<?php echo esc_attr( $announced ); ?>">
                <button type="submit" class="kq-btn kq-btn-primary">Set up Round <?php echo esc_html( $current ); ?> again</button>
            </form>
        <?php elseif ( $current < SPP_KQ_REBUILD_ROUND_MIN ) : ?>
            <p class="kq-hint" style="margin:0;">Not available for Round 1 (it comes from the card draw).</p>
        <?php elseif ( spp_kq_round_has_any_score( $occurrence_id, $current ) ) : ?>
            <p class="kq-hint" style="margin:0;">Not available: Round <?php echo esc_html( $current ); ?> already has scores, so it stays as played.</p>
        <?php else : ?>
            <p class="kq-hint" style="margin:0;">Not available: Round <?php echo esc_html( $current - 1 ); ?> doesn't have all its scores.</p>
        <?php endif; ?>
    </div>

    <!-- 4. Replay a round -->
    <div class="kq-fix-section" style="<?php echo esc_attr( $box ); ?>">
        <p style="margin:0 0 4px;"><strong>Play a round again</strong></p>
        <p class="kq-hint" style="margin:0 0 8px;">Use this only when a round has to be replayed on court (for example everyone had the wrong partners). Its courts stay the same, its scores are cleared, and every round after it is removed, scores included.</p>
        <?php if ( $reset_targets ) : ?>
            <form method="post" class="kq-inline-form kq-fix-form" data-confirm-template="Play Round {n} again? Its scores are cleared and every round after it is removed, scores included.">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="reset_round">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $current ); ?>">
                <label class="kq-duration-label">Round<br>
                    <select name="spp_kq_reset_target" class="kq-fix-target">
                        <?php foreach ( $reset_targets as $n ) : ?>
                            <option value="<?php echo esc_attr( $n ); ?>">Round <?php echo esc_html( $n ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="kq-btn kq-btn-secondary">Play it again</button>
            </form>
        <?php else : ?>
            <p class="kq-hint" style="margin:0;"><?php echo $phase === 'in_play' ? 'Available between rounds.' : 'Not available yet.'; ?></p>
        <?php endif; ?>
    </div>

    <!-- 5. Throw out a round -->
    <div class="kq-fix-section" style="<?php echo esc_attr( $box ); ?>">
        <p style="margin:0 0 4px;"><strong>Don't count a round</strong></p>
        <p class="kq-hint" style="margin:0 0 8px;">Use this only when a round should not count at all and won't be replayed. That round and every round after it stop counting (history, Club Ratings, rankings). This can't be undone.</p>
        <?php if ( $void_rounds ) : ?>
            <form method="post" class="kq-inline-form kq-fix-form" data-confirm-template="Stop counting Round {n} and every round after it? This can't be undone.">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="void_round">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $current ); ?>">
                <label class="kq-duration-label">Round<br>
                    <select name="spp_kq_target_round" class="kq-fix-target">
                        <?php foreach ( $void_rounds as $n ) : ?>
                            <option value="<?php echo esc_attr( $n ); ?>">Round <?php echo esc_html( $n ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="kq-btn kq-btn-secondary">Don't count it</button>
            </form>
        <?php else : ?>
            <p class="kq-hint" style="margin:0;">Not available: only rounds 2 and later that have scores.</p>
        <?php endif; ?>
    </div>
    </div>
    <?php
    echo spp_kq_render_fix_script( $occurrence_id, $current );
    return ob_get_clean();
}

/** The Fix a mistake screen's script (also used for the ended/empty states). */
function spp_kq_render_fix_script( int $occurrence_id, int $current ) : string {
    ob_start();
    ?>
    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ     = <?php echo (int) $occurrence_id; ?>;
        var resultEl = document.getElementById('kq-fix-result');

        function showResult(text, failed) {
            if (!resultEl) return;
            resultEl.textContent = text;
            resultEl.className = failed ? 'kq-warn' : 'kq-status';
            resultEl.style.display = 'block';
            resultEl.scrollIntoView({ block: 'center' });
        }
        // After a successful action: keep the message visible (outside the
        // live app it survives the screen changing), then show the fresh state.
        function done(text) {
            if (window.SppKqLiveApp && SppKqLiveApp.showScoreboard) {
                if (SppKqLiveApp.notify) SppKqLiveApp.notify(text);
                SppKqLiveApp.showScoreboard({ kq_view: 'fix' });
            } else {
                try { sessionStorage.setItem('kqFixMsg', text); } catch (e) {}
                window.location.reload();
            }
        }
        try {
            var saved = sessionStorage.getItem('kqFixMsg');
            if (saved) { sessionStorage.removeItem('kqFixMsg'); showResult(saved, false); }
        } catch (e) {}

        document.querySelectorAll('.kq-fix-round-select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var g = sel.getAttribute('data-group');
                document.querySelectorAll('.kq-fix-round[data-group="' + g + '"]').forEach(function(el) {
                    el.hidden = el.getAttribute('data-round') !== sel.value;
                });
            });
        });

        // Correct a score
        document.querySelectorAll('.kq-fix-correct-row').forEach(function(row) {
            var btn = row.querySelector('.kq-fix-correct-btn');
            var msg = row.querySelector('.kq-fix-row-msg');
            btn.addEventListener('click', function() {
                btn.disabled = true;
                msg.style.display = 'none';
                var data = new FormData();
                data.append('action', 'spp_kq_correct_score');
                data.append('nonce', nonce);
                data.append('occ', occ);
                data.append('round', row.dataset.round);
                data.append('court_name', row.dataset.court);
                data.append('red_score', row.querySelector('.kq-fix-red').value);
                data.append('black_score', row.querySelector('.kq-fix-black').value);
                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if (!res.success) { msg.textContent = res.data || 'Not saved.'; msg.style.display = 'block'; return; }
                        done(res.data.message);
                    })
                    .catch(function() { btn.disabled = false; showResult('Network problem: nothing was saved. Please try again.', true); });
            });
        });

        // Swap players (the shared per-round picker)
        document.querySelectorAll('.kq-fix-screen .kq-past-swap-toggle').forEach(function(b) {
            b.addEventListener('click', function() {
                var f = document.getElementById(this.dataset.target);
                if (f) f.hidden = !f.hidden;
            });
        });
        document.querySelectorAll('.kq-fix-screen .kq-past-swap-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var select = form.querySelector('.kq-swap-select');
                if (!select.value) return;
                var opt = select.options[select.selectedIndex];
                var n = form.querySelector('[name="spp_kq_swap_target_round"]').value;
                var wrap = form.closest('.kq-past-swap-round');
                var reported = (wrap.getAttribute('data-reported') || '').split('|');
                if (reported.indexOf(form.dataset.court) !== -1 || reported.indexOf(opt.dataset.court) !== -1) {
                    if (!confirm('Round ' + n + ' already has scores. The scores stay the same; they will count for the players who actually played.')) return;
                }
                post(form);
            });
        });

        // Set up again / play again / don't count
        document.querySelectorAll('.kq-fix-screen .kq-fix-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var t = form.querySelector('.kq-fix-target');
                var n = t ? t.value : '';
                var q = form.getAttribute('data-confirm') || (form.getAttribute('data-confirm-template') || '').replace(/\{n\}/g, n);
                if (q && !confirm(q)) return;
                post(form);
            });
        });

        function post(form) {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            var data = new FormData(form);
            data.append('action', 'spp_kq_live_action');
            data.append('occ', occ);
            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (btn) btn.disabled = false;
                    if (!res.success) { showResult(res.data || 'That didn\'t work. Nothing was changed.', true); return; }
                    var notice = res.data && res.data.notice ? res.data.notice : '';
                    // An empty notice means the action didn't run: the
                    // screen was out of date (someone else acted first).
                    done(notice || 'Nothing was changed: the screen was out of date. Here is the current state.');
                })
                .catch(function() { if (btn) btn.disabled = false; showResult('Network problem: nothing was changed. Please try again.', true); });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
