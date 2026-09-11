<?php
/**
 * Ace/Queen of the Courts — Movement Algorithm Test Harness
 *
 * Plain PHP, no WordPress bootstrap, no database. Exercises
 * inc/spp-kq-movement.php directly (that file is itself pure --
 * this harness only defines ABSPATH so its `defined('ABSPATH') || exit;`
 * guard doesn't fire).
 *
 * Run: php tests/spp-kq-movement-test.php
 */

define( 'ABSPATH', __DIR__ . '/' ); // satisfy the guard; nothing else in the target file uses it.
require __DIR__ . '/../inc/spp-kq-movement.php';

// ── Tiny assertion harness ───────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function kq_test( string $name, callable $fn ) {
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

function kq_assert( bool $cond, string $msg ) {
    if ( ! $cond ) {
        throw new Exception( $msg );
    }
}

function kq_assert_same( $expected, $actual, string $msg ) {
    if ( $expected !== $actual ) {
        throw new Exception( "{$msg} -- expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) );
    }
}

/** Sort a set of user_ids for order-independent comparison. */
function kq_uid_set( array $rows ) : array {
    $uids = array_map( fn( $r ) => (int) $r['user_id'], $rows );
    sort( $uids );
    return $uids;
}

/** Group next-round rows by court -> ['red'=>[uid,uid], 'black'=>[uid,uid]]. */
function kq_by_court( array $rows ) : array {
    $out = array();
    foreach ( $rows as $r ) {
        $out[ $r['court_name'] ][ $r['team_color'] ][] = (int) $r['user_id'];
    }
    foreach ( $out as $court => $teams ) {
        foreach ( $teams as $color => $uids ) {
            sort( $out[ $court ][ $color ] );
        }
    }
    return $out;
}

/** Build a flat history row set from a round's assignments. */
function kq_history_rows( int $round_number, array $assignments ) : array {
    $rows = array();
    foreach ( $assignments as $a ) {
        $rows[] = array(
            'round_number' => $round_number,
            'court_name'   => $a['court_name'],
            'team_color'   => $a['team_color'],
            'user_id'      => $a['user_id'],
        );
    }
    return $rows;
}

function kq_assign( array $red, array $black, string $court ) : array {
    $rows = array();
    foreach ( $red as $uid ) $rows[] = array( 'user_id' => $uid, 'court_name' => $court, 'team_color' => 'red' );
    foreach ( $black as $uid ) $rows[] = array( 'user_id' => $uid, 'court_name' => $court, 'team_color' => 'black' );
    return $rows;
}

echo "=== spp_kq_choose_split() -- isolated pairing-choice tests ===\n";

kq_test( '4 players, no history at all -> deterministic cross-split 1', function() {
    $result = spp_kq_choose_split( array( 10, 20 ), array( 30, 40 ), array() );
    kq_assert_same( array( array( 10, 30 ), array( 20, 40 ) ), $result, 'expected cross-split 1' );
} );

kq_test( 'all 3 possible splits already seen -> picks the stalest (least-bad) repeat', function() {
    // pair1=[1,2], pair2=[3,4]. Splits: same(1,2)(3,4); cross1(1,3)(2,4); cross2(1,4)(2,3).
    $pair_last_round = array(
        '1:2' => 5, '3:4' => 5,   // "same as incoming" split -- cost max(5,5)=5
        '1:3' => 3, '2:4' => 7,   // cross1 -- cost max(3,7)=7
        '1:4' => 2, '2:3' => 2,   // cross2 -- cost max(2,2)=2  <- lowest, should win
    );
    $result = spp_kq_choose_split( array( 1, 2 ), array( 3, 4 ), $pair_last_round );
    kq_assert_same( array( array( 1, 4 ), array( 2, 3 ) ), $result, 'expected cross2 (staleest repeat)' );
} );

kq_test( 'one split fresh, others stale -> the fresh one always wins regardless of order', function() {
    $pair_last_round = array(
        '1:2' => 1, '3:4' => 1, // same-as-incoming: seen
        '1:3' => 2, '2:4' => 2, // cross1: seen
        // cross2 (1,4)(2,3) never seen -- must win
    );
    $result = spp_kq_choose_split( array( 1, 2 ), array( 3, 4 ), $pair_last_round );
    kq_assert_same( array( array( 1, 4 ), array( 2, 3 ) ), $result, 'expected the only never-seen split' );
} );

kq_test( 'spp_kq_build_pair_history ignores an incomplete team (defensive)', function() {
    $rows = array(
        array( 'round_number' => 1, 'court_name' => 'Aces', 'team_color' => 'red', 'user_id' => 1 ),
        // no partner row for round 1 / Aces / red -- incomplete group, must not crash or pollute lookup
        array( 'round_number' => 1, 'court_name' => 'Aces', 'team_color' => 'black', 'user_id' => 3 ),
        array( 'round_number' => 1, 'court_name' => 'Aces', 'team_color' => 'black', 'user_id' => 4 ),
    );
    $hist = spp_kq_build_pair_history( $rows );
    kq_assert_same( array( '3:4' => 1 ), $hist, 'only the complete pair should be recorded' );
} );

echo "\n=== spp_kq_compute_next_round() -- shape/validation errors ===\n";

kq_test( 'tied score throws', function() {
    $courts = array( 'Aces', 'Kings' );
    $assign = array_merge(
        kq_assign( array( 1, 2 ), array( 3, 4 ), 'Aces' ),
        kq_assign( array( 5, 6 ), array( 7, 8 ), 'Kings' )
    );
    $scores = array(
        array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 21 ),
        array( 'court_name' => 'Kings', 'red_score' => 21, 'black_score' => 10 ),
    );
    try {
        spp_kq_compute_next_round( $courts, $assign, $scores );
        throw new Exception( 'expected SPP_KQ_Movement_Error, none thrown' );
    } catch ( SPP_KQ_Movement_Error $e ) {
        kq_assert( str_contains( $e->getMessage(), 'tied' ), 'error message should mention the tie: ' . $e->getMessage() );
    }
} );

kq_test( 'a court with 3 players instead of 4 throws', function() {
    $courts = array( 'Aces' );
    $assign = kq_assign( array( 1, 2 ), array( 3 ), 'Aces' ); // only 3 total
    $scores = array( array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 10 ) );
    try {
        spp_kq_compute_next_round( $courts, $assign, $scores );
        throw new Exception( 'expected SPP_KQ_Movement_Error, none thrown' );
    } catch ( SPP_KQ_Movement_Error $e ) {
        kq_assert( str_contains( $e->getMessage(), 'expected 4' ), 'error message should mention player count: ' . $e->getMessage() );
    }
} );

kq_test( 'a user_id duplicated across two courts throws', function() {
    $courts = array( 'Aces', 'Kings' );
    $assign = array_merge(
        kq_assign( array( 1, 2 ), array( 3, 4 ), 'Aces' ),
        kq_assign( array( 1, 6 ), array( 7, 8 ), 'Kings' ) // user 1 also here
    );
    $scores = array(
        array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 10 ),
        array( 'court_name' => 'Kings', 'red_score' => 21, 'black_score' => 10 ),
    );
    try {
        spp_kq_compute_next_round( $courts, $assign, $scores );
        throw new Exception( 'expected SPP_KQ_Movement_Error, none thrown' );
    } catch ( SPP_KQ_Movement_Error $e ) {
        kq_assert( str_contains( $e->getMessage(), 'more than once' ), 'error message should mention duplication: ' . $e->getMessage() );
    }
} );

kq_test( 'an unknown court name in assignments throws', function() {
    $courts = array( 'Aces' );
    $assign = kq_assign( array( 1, 2 ), array( 3, 4 ), 'Kings' ); // not in courts_order
    $scores = array( array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 10 ) );
    try {
        spp_kq_compute_next_round( $courts, $assign, $scores );
        throw new Exception( 'expected SPP_KQ_Movement_Error, none thrown' );
    } catch ( SPP_KQ_Movement_Error $e ) {
        kq_assert( str_contains( $e->getMessage(), 'unknown court' ), 'error message should mention unknown court: ' . $e->getMessage() );
    }
} );

kq_test( 'a missing score for an active court throws', function() {
    $courts = array( 'Aces', 'Kings' );
    $assign = array_merge(
        kq_assign( array( 1, 2 ), array( 3, 4 ), 'Aces' ),
        kq_assign( array( 5, 6 ), array( 7, 8 ), 'Kings' )
    );
    $scores = array(
        array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 10 ),
        // Kings score missing entirely
    );
    try {
        spp_kq_compute_next_round( $courts, $assign, $scores );
        throw new Exception( 'expected SPP_KQ_Movement_Error, none thrown' );
    } catch ( SPP_KQ_Movement_Error $e ) {
        kq_assert( str_contains( $e->getMessage(), "No score recorded for court 'Kings'" ), $e->getMessage() );
    }
} );

echo "\n=== 4-court, 16-player scenario ===\n";

kq_test( '16 players / 4 courts: Aces-winners-stay, bottom-losers-stay, full conservation', function() {
    $courts = array( 'Aces', 'Kings', 'Queens', 'Jacks' );

    // Round 1 draw (arbitrary): 4 players per court.
    $r1 = array_merge(
        kq_assign( array( 1, 2 ),   array( 3, 4 ),   'Aces' ),
        kq_assign( array( 5, 6 ),   array( 7, 8 ),   'Kings' ),
        kq_assign( array( 9, 10 ),  array( 11, 12 ), 'Queens' ),
        kq_assign( array( 13, 14 ), array( 15, 16 ), 'Jacks' )
    );

    // Aces: red wins. Kings: black wins. Queens: red wins. Jacks: black wins.
    $scores = array(
        array( 'court_name' => 'Aces',   'red_score' => 21, 'black_score' => 12 ),
        array( 'court_name' => 'Kings',  'red_score' => 14, 'black_score' => 21 ),
        array( 'court_name' => 'Queens', 'red_score' => 21, 'black_score' => 9  ),
        array( 'court_name' => 'Jacks',  'red_score' => 10, 'black_score' => 21 ),
    );

    $history = kq_history_rows( 1, $r1 );
    $next    = spp_kq_compute_next_round( $courts, $r1, $scores, $history );

    // Shape: 16 rows total, 4 per court, 2 red + 2 black each, no duplicate uid.
    kq_assert_same( 16, count( $next ), 'expected 16 total assignment rows' );
    $by = kq_by_court( $next );
    foreach ( $courts as $c ) {
        kq_assert( isset( $by[ $c ]['red'] ) && count( $by[ $c ]['red'] ) === 2, "{$c} should have 2 red players" );
        kq_assert( isset( $by[ $c ]['black'] ) && count( $by[ $c ]['black'] ) === 2, "{$c} should have 2 black players" );
    }
    kq_assert_same( range( 1, 16 ), kq_uid_set( $next ), 'every one of the 16 players should appear exactly once next round' );

    // Aces winners were [1,2] (red). Aces winners stay -> both 1 and 2 must still be at Aces.
    $aces_next = array_merge( $by['Aces']['red'], $by['Aces']['black'] );
    sort( $aces_next );
    kq_assert( in_array( 1, $aces_next, true ) && in_array( 2, $aces_next, true ), 'Aces winners (1,2) should stay at Aces' );

    // Kings winners were [7,8] (black) -- they move UP to Aces.
    kq_assert( in_array( 7, $aces_next, true ) && in_array( 8, $aces_next, true ), 'Kings winners (7,8) should move up to Aces' );

    // Jacks (bottom court) losers were [13,14] (red) -- bottom losers stay at Jacks.
    $jacks_next = array_merge( $by['Jacks']['red'], $by['Jacks']['black'] );
    sort( $jacks_next );
    kq_assert( in_array( 13, $jacks_next, true ) && in_array( 14, $jacks_next, true ), 'Jacks (bottom) losers (13,14) should stay at Jacks' );

    // Queens (court above Jacks) losers were [11,12] (black) -- move DOWN to Jacks.
    kq_assert( in_array( 11, $jacks_next, true ) && in_array( 12, $jacks_next, true ), 'Queens losers (11,12) should move down to Jacks' );

    // Aces losers were [3,4] (black) -- move down to Kings.
    $kings_next = array_merge( $by['Kings']['red'], $by['Kings']['black'] );
    sort( $kings_next );
    kq_assert( in_array( 3, $kings_next, true ) && in_array( 4, $kings_next, true ), 'Aces losers (3,4) should move down to Kings' );

    // Kings losers were [5,6] (red) -- move down to Queens.
    $queens_next = array_merge( $by['Queens']['red'], $by['Queens']['black'] );
    sort( $queens_next );
    kq_assert( in_array( 5, $queens_next, true ) && in_array( 6, $queens_next, true ), 'Kings losers (5,6) should move down to Queens' );

    // Queens winners were [9,10] (red) -- move up to Kings.
    kq_assert( in_array( 9, $kings_next, true ) && in_array( 10, $kings_next, true ), 'Queens winners (9,10) should move up to Kings' );

    // Jacks winners were [15,16] (black) -- move up to Queens.
    kq_assert( in_array( 15, $queens_next, true ) && in_array( 16, $queens_next, true ), 'Jacks winners (15,16) should move up to Queens' );

    // No repeat partnership possible yet (round 1 is the only history) --
    // none of the round-1 pairs should reappear as a team in round 2.
    $round1_pairs = array( array( 1, 2 ), array( 3, 4 ), array( 5, 6 ), array( 7, 8 ), array( 9, 10 ), array( 11, 12 ), array( 13, 14 ), array( 15, 16 ) );
    foreach ( $by as $court => $teams ) {
        foreach ( $teams as $color => $uids ) {
            foreach ( $round1_pairs as $p ) {
                sort( $p );
                kq_assert( $uids !== $p, "round-2 {$court}/{$color} team " . implode( ',', $uids ) . " repeats a round-1 pairing" );
            }
        }
    }
} );

echo "\n=== 3-court, 12-player scenario ===\n";

kq_test( '12 players / 3 courts: same movement rules, no Jacks court at all', function() {
    $courts = array( 'Aces', 'Kings', 'Queens' );

    $r1 = array_merge(
        kq_assign( array( 1, 2 ), array( 3, 4 ),  'Aces' ),
        kq_assign( array( 5, 6 ), array( 7, 8 ),  'Kings' ),
        kq_assign( array( 9, 10 ), array( 11, 12 ), 'Queens' )
    );

    $scores = array(
        array( 'court_name' => 'Aces',   'red_score' => 21, 'black_score' => 8  ), // red wins
        array( 'court_name' => 'Kings',  'red_score' => 9,  'black_score' => 21 ), // black wins
        array( 'court_name' => 'Queens', 'red_score' => 21, 'black_score' => 15 ), // red wins
    );

    $history = kq_history_rows( 1, $r1 );
    $next    = spp_kq_compute_next_round( $courts, $r1, $scores, $history );

    kq_assert_same( 12, count( $next ), 'expected 12 total assignment rows' );
    kq_assert_same( range( 1, 12 ), kq_uid_set( $next ), 'every one of the 12 players should appear exactly once next round' );

    $by = kq_by_court( $next );

    // Aces winners [1,2] stay; Kings winners [7,8] move up to Aces.
    $aces_next = array_merge( $by['Aces']['red'], $by['Aces']['black'] ); sort( $aces_next );
    kq_assert_same( array( 1, 2, 7, 8 ), $aces_next, 'Aces next round should be {1,2,7,8}' );

    // Queens (bottom) losers [11,12] stay; Kings (above Queens) losers [5,6] move down to Queens.
    $queens_next = array_merge( $by['Queens']['red'], $by['Queens']['black'] ); sort( $queens_next );
    kq_assert_same( array( 5, 6, 11, 12 ), $queens_next, 'Queens (bottom) next round should be {5,6,11,12}' );

    // Kings gets Aces' losers [3,4] moving down, and Queens' winners [9,10] moving up.
    $kings_next = array_merge( $by['Kings']['red'], $by['Kings']['black'] ); sort( $kings_next );
    kq_assert_same( array( 3, 4, 9, 10 ), $kings_next, 'Kings next round should be {3,4,9,10}' );
} );

echo "\n=== degenerate 1-court, 4-player scenario ===\n";

kq_test( '4 players / 1 court: winners and losers both "stay" (nowhere else to go), just re-split', function() {
    $courts = array( 'Aces' );
    $r1     = kq_assign( array( 1, 2 ), array( 3, 4 ), 'Aces' );
    $scores = array( array( 'court_name' => 'Aces', 'red_score' => 21, 'black_score' => 15 ) );

    $next = spp_kq_compute_next_round( $courts, $r1, $scores, kq_history_rows( 1, $r1 ) );
    kq_assert_same( 4, count( $next ), 'expected 4 rows' );
    kq_assert_same( array( 1, 2, 3, 4 ), kq_uid_set( $next ), 'same 4 players, nowhere else to go' );

    $by = kq_by_court( $next );
    // Only 3 possible splits of {1,2,3,4}; round 1 was {1,2}/{3,4}. Round 2 should
    // pick a fresh split (either cross), never repeat {1,2}/{3,4} on the first move.
    $round2_red = $by['Aces']['red'];
    kq_assert( $round2_red !== array( 1, 2 ) && $round2_red !== array( 3, 4 ), 'round 2 should not repeat the round-1 pairing when a fresh split exists' );
} );

echo "\n=== multi-round simulation: repeat-avoidance across rounds 1-8 (8 players / 2 courts) ===\n";

kq_test( '8 players / 2 courts across 8 rounds: no partnership repeats until genuinely forced, and it never throws', function() {
    $courts = array( 'Aces', 'Kings' );

    $round = 1;
    $assignments = array_merge(
        kq_assign( array( 1, 2 ), array( 3, 4 ), 'Aces' ),
        kq_assign( array( 5, 6 ), array( 7, 8 ), 'Kings' )
    );
    $history = kq_history_rows( $round, $assignments );
    $seen_pairs_ever = array(); // pair_key => first round seen, for reporting only

    $record_pairs = function( array $assignments, int $round ) use ( &$seen_pairs_ever ) {
        $by = array();
        foreach ( $assignments as $a ) $by[ $a['court_name'] ][ $a['team_color'] ][] = (int) $a['user_id'];
        $repeats_this_round = array();
        foreach ( $by as $court => $teams ) {
            foreach ( $teams as $color => $uids ) {
                sort( $uids );
                $key = implode( ':', $uids );
                if ( isset( $seen_pairs_ever[ $key ] ) ) {
                    $repeats_this_round[] = $key . " (first seen round {$seen_pairs_ever[$key]})";
                } else {
                    $seen_pairs_ever[ $key ] = $round;
                }
            }
        }
        return $repeats_this_round;
    };

    // Fixed, deterministic "who wins" pattern per round: red always wins on both
    // courts. Simple and sufficient to drive real movement through several rounds.
    $fixed_scores = array(
        array( 'court_name' => 'Aces',  'red_score' => 21, 'black_score' => 10 ),
        array( 'court_name' => 'Kings', 'red_score' => 21, 'black_score' => 10 ),
    );

    $all_repeats = array();
    for ( $round = 1; $round <= 8; $round++ ) {
        if ( $round > 1 ) {
            $assignments = spp_kq_compute_next_round( $courts, $assignments, $fixed_scores, $history );
            $history = array_merge( $history, kq_history_rows( $round, $assignments ) );
        }
        $repeats = $record_pairs( $assignments, $round );
        if ( ! empty( $repeats ) ) {
            $all_repeats[ $round ] = $repeats;
        }
    }

    // With only 8 players permanently cycling through just 2 courts, a repeat
    // eventually becomes mathematically unavoidable (there are only finitely many
    // ways to partner 8 people two at a time) -- traced by hand (see conversation)
    // this specific fixed "red always wins" pattern goes SEVEN full rounds with
    // zero repeats and only cycles back to round 1's exact pairing at round 8.
    // The real assertion isn't "exactly round 8" (that number is an artifact of
    // this particular win pattern, not a algorithm guarantee) -- it's that the
    // algorithm holds off as long as real alternatives exist, and degrades
    // gracefully (a valid round, never an exception) once they're truly gone.
    for ( $r = 2; $r <= 7; $r++ ) {
        kq_assert( ! isset( $all_repeats[ $r ] ), "round {$r} should have zero repeat partnerships (fresh splits were still available)" );
    }
    kq_assert( isset( $all_repeats[8] ), 'round 8 is expected to be forced into a repeat with only 8 players -- if this is empty, either the algorithm changed or this scenario is no longer a valid "unavoidable" case' );
    echo "        (first forced repeat at round 8, exactly as traced by hand; full round-by-round log: " . json_encode( $all_repeats ) . ")\n";
} );

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
