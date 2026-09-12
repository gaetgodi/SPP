<?php
/**
 * Ace/Queen of the Courts — Score entry (Stage 3) tests.
 *
 * Bootstraps WordPress and exercises real writes against a disposable
 * fake occurrence_id (999999004), fully cleaned up at both start and
 * end regardless of pass/fail. Covers the submit/validation/progress
 * mechanics AND the access-restriction rule (a real, distinct,
 * non-assigned user must be refused both viewing and submitting a
 * court's score) at the same rigor as the project's earlier Tier 1/2
 * access-control work -- this is not a bare unit test of the gate
 * function in isolation, it drives the real render function and the
 * real submit function exactly as the AJAX handler and shortcode
 * dispatcher call them.
 *
 * Run: php tests/spp-kq-score-entry-test.php
 */

define( 'WP_USE_THEMES', false );
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';

global $wpdb;
$OCC = 999999004;
$ROSTER = array( 2484, 2442, 2883, 3131, 2542, 2164, 3039, 2157 ); // 8 players -> Aces + Kings
$OUTSIDER = 2205; // real user, deliberately NOT part of this occurrence's roster at all

function kqs_cleanup( int $occ ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gl_registrations WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_events_table() . " WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_assignments_table() . " WHERE occurrence_id = %d", $occ ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM " . spp_kq_scores_table() . " WHERE occurrence_id = %d", $occ ) );
}

function kqs_seed_registrations( int $occ, array $user_ids ) {
    global $wpdb;
    foreach ( $user_ids as $uid ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}gl_registrations (occurrence_id, user_id, status, registered_at, updated_at) VALUES (%d, %d, 'confirmed', NOW(), NOW())",
            $occ, $uid
        ) );
    }
}

function kqs_draw_all( int $occ, array $user_ids ) {
    foreach ( $user_ids as $uid ) {
        $r = spp_kq_draw_card( $occ, $uid );
        if ( ! $r['success'] ) throw new Exception( "draw failed for {$uid}: " . $r['error'] );
    }
}

$pass = 0; $fail = 0;
function kqs_test( string $name, callable $fn ) {
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
function kqs_assert( bool $cond, string $msg ) { if ( ! $cond ) throw new Exception( $msg ); }
function kqs_assert_same( $expected, $actual, string $msg ) {
    if ( $expected !== $actual ) throw new Exception( "{$msg} -- expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) );
}

/** Fresh occurrence at round 1, in_play, nothing reported yet. Returns each player's court/color map. */
function kqs_setup_in_play( int $occ, array $roster ) : array {
    kqs_cleanup( $occ );
    kqs_seed_registrations( $occ, $roster );
    $start = spp_kq_transition_start_round1( $occ );
    if ( ! $start['won'] ) throw new Exception( 'setup: start_round1 failed: ' . json_encode( $start ) );
    kqs_draw_all( $occ, $roster );
    $played = spp_kq_transition_start_play( $occ, 1 );
    if ( ! $played['won'] ) throw new Exception( 'setup: start_play failed' );

    $map = array();
    foreach ( $roster as $uid ) {
        $map[ $uid ] = spp_kq_get_my_court_assignment( $occ, 1, $uid );
    }
    return $map;
}

kqs_cleanup( $OCC );

try {

echo "=== spp_kq_get_my_court_assignment() ===\n";

kqs_test( 'a real assigned player resolves to their actual court/color', function() use ( $OCC, $ROSTER ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    kqs_assert( $map[ $someone ] !== null, 'expected a real assignment' );
    kqs_assert( in_array( $map[ $someone ]['court_name'], array( 'Aces', 'Kings' ), true ), 'expected a real court name' );
    kqs_cleanup( $OCC );
} );

kqs_test( 'an outsider (real user, not on the roster at all) has no assignment', function() use ( $OCC, $ROSTER, $OUTSIDER ) {
    kqs_setup_in_play( $OCC, $ROSTER );
    $assignment = spp_kq_get_my_court_assignment( $OCC, 1, $OUTSIDER );
    kqs_assert_same( null, $assignment, 'outsider should have no assignment' );
    kqs_cleanup( $OCC );
} );

echo "\n=== access restriction -- real render, real distinct user ===\n";

kqs_test( "the in-play SCREEN shows an assigned player their own real court/team names", function() use ( $OCC, $ROSTER ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    wp_set_current_user( $someone );
    $html = spp_kq_render_in_play_screen( $OCC, 1 );
    kqs_assert( str_contains( $html, 'Your court: ' . $map[ $someone ]['court_name'] ), 'should show their real court name' );
    kqs_assert( str_contains( $html, 'kq-score-input' ), 'should show the score entry form' );
    kqs_cleanup( $OCC );
} );

kqs_test( "the in-play SCREEN refuses to show court/score details to a real outsider -- 'not assigned' only, no form, no other court's names", function() use ( $OCC, $ROSTER, $OUTSIDER ) {
    kqs_setup_in_play( $OCC, $ROSTER );
    wp_set_current_user( $OUTSIDER );
    $html = spp_kq_render_in_play_screen( $OCC, 1 );
    kqs_assert( str_contains( $html, "not assigned to a court" ), 'should show the not-assigned message' );
    kqs_assert( ! str_contains( $html, 'kq-score-input' ), 'must NOT show a score entry form to an outsider' );
    kqs_assert( ! str_contains( $html, 'Your court:' ), 'must NOT show any court assignment to an outsider' );
    kqs_cleanup( $OCC );
} );

kqs_test( "the SUBMIT path independently refuses an outsider (same rule, called directly, not just via the screen)", function() use ( $OCC, $ROSTER, $OUTSIDER ) {
    kqs_setup_in_play( $OCC, $ROSTER );
    $r = spp_kq_submit_court_score( $OCC, 1, 21, 15, $OUTSIDER );
    kqs_assert_same( false, $r['success'], 'outsider submit should fail' );
    kqs_assert( str_contains( $r['error'], 'not assigned' ), 'error should say not assigned: ' . $r['error'] );
    kqs_cleanup( $OCC );
} );

kqs_test( "an outsider's rejected submit writes nothing -- the real assigned court's score row stays untouched", function() use ( $OCC, $ROSTER, $OUTSIDER, $wpdb ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    $court = $map[ $someone ]['court_name'];
    spp_kq_submit_court_score( $OCC, 1, 99, 1, $OUTSIDER ); // rejected -- outsider has no assignment at all, so this can't even target a real court
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM " . spp_kq_scores_table() . " WHERE occurrence_id=%d AND round_number=1 AND court_name=%s",
        $OCC, $court
    ), ARRAY_A );
    kqs_assert_same( null, $row['red_score'], 'court score should remain unreported' );
    kqs_cleanup( $OCC );
} );

echo "\n=== validation ===\n";

kqs_test( 'a tied score is rejected with a clear message, and nothing is written', function() use ( $OCC, $ROSTER, $wpdb ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    $court = $map[ $someone ]['court_name'];
    $r = spp_kq_submit_court_score( $OCC, 1, 15, 15, $someone );
    kqs_assert_same( false, $r['success'], 'tie should be rejected' );
    kqs_assert( str_contains( $r['error'], "can't be tied" ), 'error should mention the tie: ' . $r['error'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score FROM " . spp_kq_scores_table() . " WHERE occurrence_id=%d AND round_number=1 AND court_name=%s",
        $OCC, $court
    ), ARRAY_A );
    kqs_assert_same( null, $row['red_score'], 'a rejected tie must not be written' );
    kqs_cleanup( $OCC );
} );

kqs_test( 'an out-of-range score is rejected', function() use ( $OCC, $ROSTER ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    $r = spp_kq_submit_court_score( $OCC, 1, 150, 10, $someone );
    kqs_assert_same( false, $r['success'], 'out-of-range should be rejected' );
    kqs_assert( str_contains( $r['error'], 'between 0 and 99' ), $r['error'] );
    $r2 = spp_kq_submit_court_score( $OCC, 1, -1, 10, $someone );
    kqs_assert_same( false, $r2['success'], 'negative should be rejected (matches the -1 sentinel a missing POST field parses to)' );
    kqs_cleanup( $OCC );
} );

kqs_test( 'a valid submit succeeds, and a later resubmit by the same court overwrites (most recent entry wins)', function() use ( $OCC, $ROSTER, $wpdb ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $someone = $ROSTER[0];
    $court = $map[ $someone ]['court_name'];

    $r1 = spp_kq_submit_court_score( $OCC, 1, 21, 15, $someone );
    kqs_assert( $r1['success'], 'first submit should succeed' );
    kqs_assert_same( 1, $r1['reported'], 'one of two courts now reported' );
    kqs_assert_same( 2, $r1['total'], 'two courts total' );

    // A DIFFERENT teammate on the SAME court corrects the score.
    $teammate = null;
    foreach ( $ROSTER as $uid ) {
        if ( $uid !== $someone && ( spp_kq_get_my_court_assignment( $OCC, 1, $uid )['court_name'] ?? null ) === $court ) {
            $teammate = $uid; break;
        }
    }
    kqs_assert( $teammate !== null, 'setup: expected to find a teammate on the same court' );

    $r2 = spp_kq_submit_court_score( $OCC, 1, 21, 18, $teammate );
    kqs_assert( $r2['success'], 'correction submit should succeed' );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM " . spp_kq_scores_table() . " WHERE occurrence_id=%d AND round_number=1 AND court_name=%s",
        $OCC, $court
    ), ARRAY_A );
    kqs_assert_same( 21, (int) $row['red_score'], 'should reflect the corrected value' );
    kqs_assert_same( 18, (int) $row['black_score'], 'should reflect the corrected value' );

    kqs_cleanup( $OCC );
} );

echo "\n=== round auto-advance triggered purely by submitting scores ===\n";

kqs_test( 'submitting the LAST unreported court auto-advances the round -- via the real submit path, not by calling the transition directly', function() use ( $OCC, $ROSTER, $wpdb ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );

    // Group the 8 players by court.
    $by_court = array();
    foreach ( $ROSTER as $uid ) $by_court[ $map[ $uid ]['court_name'] ][] = $uid;
    $courts = array_keys( $by_court );
    kqs_assert_same( 2, count( $courts ), 'expected 2 courts' );

    $r1 = spp_kq_submit_court_score( $OCC, 1, 21, 15, $by_court[ $courts[0] ][0] );
    kqs_assert( $r1['success'], 'first court submit should succeed' );
    kqs_assert_same( false, $r1['advanced'], 'should not advance with 1 of 2 reported' );

    $r2 = spp_kq_submit_court_score( $OCC, 1, 21, 12, $by_court[ $courts[1] ][0] );
    kqs_assert( $r2['success'], 'second (last) court submit should succeed' );
    kqs_assert_same( true, $r2['advanced'], 'submitting the last court should trigger the advance' );

    $state = spp_kq_get_event_state( $OCC );
    kqs_assert_same( 'organizing', $state['phase'], 'phase should now be organizing for round 2' );
    kqs_assert_same( 2, (int) $state['current_round'], 'current_round should now be 2' );

    $round2_assignments = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . spp_kq_assignments_table() . " WHERE occurrence_id=%d AND round_number=2",
        $OCC
    ) );
    kqs_assert_same( 8, $round2_assignments, 'round 2 should have all 8 players freshly assigned' );

    kqs_cleanup( $OCC );
} );

kqs_test( 'submitting for a round that has already advanced is refused, not silently misapplied', function() use ( $OCC, $ROSTER ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    $by_court = array();
    foreach ( $ROSTER as $uid ) $by_court[ $map[ $uid ]['court_name'] ][] = $uid;
    $courts = array_keys( $by_court );

    spp_kq_submit_court_score( $OCC, 1, 21, 15, $by_court[ $courts[0] ][0] );
    spp_kq_submit_court_score( $OCC, 1, 21, 12, $by_court[ $courts[1] ][0] ); // triggers advance to round 2

    // A straggler's device, still showing round 1, tries to submit late.
    $r = spp_kq_submit_court_score( $OCC, 1, 5, 3, $by_court[ $courts[0] ][1] );
    kqs_assert_same( false, $r['success'], 'a stale round-1 submit after advancement should be refused' );
    kqs_assert( str_contains( $r['error'], 'no longer accepting' ), $r['error'] );

    kqs_cleanup( $OCC );
} );

kqs_test( 'spp_kq_get_round_progress reflects reality at each step', function() use ( $OCC, $ROSTER ) {
    $map = kqs_setup_in_play( $OCC, $ROSTER );
    kqs_assert_same( array( 'reported' => 0, 'total' => 2 ), spp_kq_get_round_progress( $OCC, 1 ), 'starts at 0 of 2' );

    $by_court = array();
    foreach ( $ROSTER as $uid ) $by_court[ $map[ $uid ]['court_name'] ][] = $uid;
    $courts = array_keys( $by_court );
    spp_kq_submit_court_score( $OCC, 1, 21, 15, $by_court[ $courts[0] ][0] );
    kqs_assert_same( array( 'reported' => 1, 'total' => 2 ), spp_kq_get_round_progress( $OCC, 1 ), 'reflects the first submit' );

    kqs_cleanup( $OCC );
} );

} finally {
    kqs_cleanup( $OCC );
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
