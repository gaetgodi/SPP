<?php
/**
 * Ace/Queen of the Courts — End Event / Cancel Event behavioral tests.
 *
 * Bootstraps WordPress (these functions use $wpdb) and exercises real
 * writes against a disposable fake occurrence_id (999999003), fully
 * cleaned up at both start and end regardless of pass/fail. Not a
 * concurrency test -- see spp-kq-concurrency-test.sh for the
 * same-instant-race style of test; this one is sequential, checking
 * behavior/data correctness for each transition on its own.
 *
 * Run: php tests/spp-kq-end-cancel-test.php
 */

define( 'WP_USE_THEMES', false );
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';

global $wpdb;
$OCC = 999999003;

function kqe_cleanup( int $occ ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gl_registrations WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_events_table() . " WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_assignments_table() . " WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_scores_table() . " WHERE occurrence_id = %d", $occ ) );
}

function kqe_seed_registrations( int $occ, array $user_ids ) {
    global $wpdb;
    foreach ( $user_ids as $uid ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}gl_registrations (occurrence_id, user_id, status, registered_at, updated_at) VALUES (%d, %d, 'confirmed', NOW(), NOW())",
            $occ, $uid
        ) );
    }
}

/** Draw every remaining slot for round 1, sequentially (no race needed here). */
function kqe_draw_all( int $occ, array $user_ids ) {
    foreach ( $user_ids as $uid ) {
        $r = spp_kq_draw_card( $occ, $uid );
        if ( ! $r['success'] ) {
            throw new Exception( "draw failed for user {$uid}: " . $r['error'] );
        }
    }
}

$pass = 0; $fail = 0;
function kqe_test( string $name, callable $fn ) {
    global $pass, $fail;
    try {
        $fn();
        echo "  PASS  {$name}\n";
        $pass++;
    } catch ( Throwable $e ) {
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
        $fail++;
    }
}
function kqe_assert( bool $cond, string $msg ) {
    if ( ! $cond ) throw new Exception( $msg );
}
function kqe_assert_same( $expected, $actual, string $msg ) {
    if ( $expected !== $actual ) {
        throw new Exception( "{$msg} -- expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) );
    }
}

// Real user_ids, reused across scenarios purely as filler -- content
// doesn't matter for these tests, only counts and slot occupancy.
$user_ids_8 = array( 2484, 2442, 2883, 3131, 2542, 2164, 3039, 2157 );

kqe_cleanup( $OCC ); // in case a prior interrupted run left something behind

try {

echo "=== End Event ===\n";

kqe_test( 'End Event succeeds from organizing, lands on complete', function() use ( $OCC, $user_ids_8 ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    $start = spp_kq_transition_start_round1( $OCC );
    kqe_assert( $start['won'], 'expected to win start-round1: ' . json_encode( $start ) );
    kqe_draw_all( $OCC, $user_ids_8 );

    $end = spp_kq_transition_end_event( $OCC, 1 );
    kqe_assert( $end['won'], 'expected End Event to win from organizing' );

    $state = spp_kq_get_event_state( $OCC );
    kqe_assert_same( 'complete', $state['phase'], 'phase should be complete' );
    kqe_assert_same( 1, (int) $state['current_round'], 'current_round should be unchanged at 1' );

    kqe_cleanup( $OCC );
} );

kqe_test( 'End Event no-ops (does not throw, does not change state) when phase is not organizing', function() use ( $OCC, $user_ids_8 ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    spp_kq_transition_start_round1( $OCC );
    kqe_draw_all( $OCC, $user_ids_8 );
    // Still 'organizing' (draw just completed, Start Play not yet tapped) --
    // move it further so it's genuinely NOT organizing, then try End Event.
    $played = spp_kq_transition_start_play( $OCC, 1 );
    kqe_assert( $played['won'], 'setup: expected Start Play to win' );

    $end = spp_kq_transition_end_event( $OCC, 1 );
    kqe_assert_same( false, $end['won'], 'End Event should not win from in_play' );
    kqe_assert_same( null, $end['error'], 'a lost/inapplicable transition is a silent no-op, not an error' );

    $state = spp_kq_get_event_state( $OCC );
    kqe_assert_same( 'in_play', $state['phase'], 'phase should remain in_play, untouched by the failed End Event attempt' );

    kqe_cleanup( $OCC );
} );

kqe_test( 'Start Play vs End Event race for the same (occurrence, round): exactly one wins, phase is one of the two, never anything else', function() use ( $OCC, $user_ids_8 ) {
    $results = array();
    for ( $trial = 0; $trial < 8; $trial++ ) {
        kqe_seed_registrations( $OCC, $user_ids_8 );
        spp_kq_transition_start_round1( $OCC );
        kqe_draw_all( $OCC, $user_ids_8 );
        // Both attempts target the exact same (occurrence_id=OCC, current_round=1,
        // phase='organizing') row. No real thread-level race is needed to prove
        // the CAS logic is sound here -- unlike the identical-transition race
        // (which needs genuine simultaneity to prove the DB arbitrates ties),
        // two DIFFERENT target phases sharing one WHERE clause can only ever let
        // the first-executed one win by construction; what this test actually
        // checks is that the LOSER correctly sees phase already changed and
        // backs off cleanly, in both possible firing orders.
        if ( $trial % 2 === 0 ) {
            $a = spp_kq_transition_start_play( $OCC, 1 );
            $b = spp_kq_transition_end_event( $OCC, 1 );
        } else {
            $a = spp_kq_transition_end_event( $OCC, 1 );
            $b = spp_kq_transition_start_play( $OCC, 1 );
        }
        $results[] = array( 'a_won' => $a['won'], 'b_won' => $b['won'] );

        $state = spp_kq_get_event_state( $OCC );
        kqe_assert( in_array( $state['phase'], array( 'in_play', 'complete' ), true ), "phase should be in_play or complete, got '{$state['phase']}'" );
        kqe_assert( $a['won'] xor $b['won'], "exactly one of the two competing transitions should win a given firing order -- trial {$trial}: " . json_encode( array( $a, $b ) ) );

        kqe_cleanup( $OCC );
    }
    echo "        (8 trials, both firing orders exercised: " . json_encode( $results ) . ")\n";
} );

echo "\n=== Cancel Event ===\n";

kqe_test( 'Cancel Event no-ops when phase is not in_play', function() use ( $OCC, $user_ids_8 ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    spp_kq_transition_start_round1( $OCC );
    kqe_draw_all( $OCC, $user_ids_8 );
    // Still organizing -- never reached in_play.
    $cancel = spp_kq_transition_cancel_event( $OCC, 1 );
    kqe_assert_same( false, $cancel['won'], 'Cancel Event should not win from organizing' );
    $state = spp_kq_get_event_state( $OCC );
    kqe_assert_same( 'organizing', $state['phase'], 'phase should remain organizing' );
    kqe_cleanup( $OCC );
} );

kqe_test( 'Cancel Event: reported court keeps its data, unreported court is fully discarded', function() use ( $OCC, $user_ids_8, $wpdb ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    $start = spp_kq_transition_start_round1( $OCC ); // 8 players -> Aces + Kings
    kqe_assert_same( array( 'Aces', 'Kings' ), $start['courts'], 'setup sanity check' );
    kqe_draw_all( $OCC, $user_ids_8 );
    $played = spp_kq_transition_start_play( $OCC, 1 );
    kqe_assert( $played['won'], 'setup: expected Start Play to win' );

    // Aces reports a real score BEFORE cancellation; Kings never reports.
    $wpdb->query( $wpdb->prepare(
        "UPDATE " . spp_kq_scores_table() . " SET red_score = 21, black_score = 15 WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Aces'",
        $OCC
    ) );

    $cancel = spp_kq_transition_cancel_event( $OCC, 1 );
    kqe_assert( $cancel['won'], 'expected Cancel Event to win from in_play' );
    kqe_assert_same( array( 'Kings' ), $cancel['discarded_courts'], 'only Kings (unreported) should be discarded' );

    $state = spp_kq_get_event_state( $OCC );
    kqe_assert_same( 'cancelled', $state['phase'], 'phase should be cancelled' );

    // Aces: score row intact with the real values, all 4 assignment rows intact.
    $aces_score = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM " . spp_kq_scores_table() . " WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Aces'",
        $OCC
    ), ARRAY_A );
    kqe_assert( $aces_score !== null, 'Aces score row should still exist' );
    kqe_assert_same( 21, (int) $aces_score['red_score'], 'Aces red_score should be preserved' );
    kqe_assert_same( 15, (int) $aces_score['black_score'], 'Aces black_score should be preserved' );
    $aces_players = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_assignments_table() . " WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Aces'",
        $OCC
    ) );
    kqe_assert_same( 4, $aces_players, 'Aces should still have all 4 assignment rows' );

    // Kings: both the score row and all assignment rows are gone entirely.
    $kings_score_exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_scores_table() . " WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Kings'",
        $OCC
    ) );
    kqe_assert_same( 0, $kings_score_exists, 'Kings score row should be deleted entirely, not left NULL' );
    $kings_players = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_assignments_table() . " WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Kings'",
        $OCC
    ) );
    kqe_assert_same( 0, $kings_players, 'Kings assignment rows should be deleted entirely' );

    kqe_cleanup( $OCC );
} );

kqe_test( 'REGRESSION: spp_kq_get_cancellation_summary() lists the discarded court too, not just the kept one -- caught by real end-to-end testing (2026-09-11): spp_kq_determine_courts_order() derived the event\'s court set from round 1\'s OWN assignment rows, which Cancel Event had just partially deleted, silently undercounting to "1 court total" instead of the real 2', function() use ( $OCC, $user_ids_8, $wpdb ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    spp_kq_transition_start_round1( $OCC ); // 8 players -> Aces + Kings
    kqe_draw_all( $OCC, $user_ids_8 );
    spp_kq_transition_start_play( $OCC, 1 );

    $wpdb->query( $wpdb->prepare(
        "UPDATE " . spp_kq_scores_table() . " SET red_score = 21, black_score = 15 WHERE occurrence_id = %d AND round_number = 1 AND court_name = 'Aces'",
        $OCC
    ) );
    spp_kq_transition_cancel_event( $OCC, 1 );

    $summary = spp_kq_get_cancellation_summary( $OCC, 1 );
    kqe_assert_same( array( 'Aces' => array( 'red' => 21, 'black' => 15 ) ), $summary['kept'], 'kept should show Aces with its real score' );
    kqe_assert_same( array( 'Kings' ), $summary['discarded'], 'discarded should still list Kings even though its rows are gone -- this is exactly what the bug lost' );

    kqe_cleanup( $OCC );
} );

kqe_test( 'Cancel Event where EVERY court is unreported: all current-round data discarded, event still lands on cancelled', function() use ( $OCC, $user_ids_8, $wpdb ) {
    kqe_seed_registrations( $OCC, $user_ids_8 );
    spp_kq_transition_start_round1( $OCC );
    kqe_draw_all( $OCC, $user_ids_8 );
    spp_kq_transition_start_play( $OCC, 1 );

    $cancel = spp_kq_transition_cancel_event( $OCC, 1 );
    kqe_assert( $cancel['won'], 'expected Cancel Event to win' );
    sort( $cancel['discarded_courts'] );
    kqe_assert_same( array( 'Aces', 'Kings' ), $cancel['discarded_courts'], 'both courts should be discarded' );

    $remaining_assignments = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_assignments_table() . " WHERE occurrence_id = %d AND round_number = 1",
        $OCC
    ) );
    kqe_assert_same( 0, $remaining_assignments, 'no assignment rows should remain for round 1' );
    $remaining_scores = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_scores_table() . " WHERE occurrence_id = %d AND round_number = 1",
        $OCC
    ) );
    kqe_assert_same( 0, $remaining_scores, 'no score rows should remain for round 1' );

    $state = spp_kq_get_event_state( $OCC );
    kqe_assert_same( 'cancelled', $state['phase'], 'phase should still correctly be cancelled even with nothing left to keep' );

    kqe_cleanup( $OCC );
} );

} finally {
    kqe_cleanup( $OCC ); // always leave the DB clean, pass or fail
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
