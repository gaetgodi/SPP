<?php
/**
 * Ace/Queen of the Courts — one-shot concurrency probe for the
 * SCORE-SUBMIT path specifically (Stage 3): two of these launched in
 * parallel, each submitting a DIFFERENT court's score for the same
 * round, rendezvous via a mutual-readiness file barrier (same
 * technique as tests/spp-kq-concurrency-probe.php in Stage 2) so both
 * fire as close to simultaneously as this environment allows, with
 * bootstrap-time jitter already absorbed before either touches its
 * marker file.
 *
 * Run only via spp-kq-score-concurrency-test.sh, which seeds/cleans
 * up the fake occurrence around it.
 */

define( 'WP_USE_THEMES', false );
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';

$occurrence_id = (int) $argv[1];
$round         = (int) $argv[2];
$user_id       = (int) $argv[3];
$red_score     = (int) $argv[4];
$black_score   = (int) $argv[5];
$my_marker     = $argv[6];
$other_marker  = $argv[7];

touch( $my_marker );
$deadline = microtime( true ) + 5.0;
while ( ! file_exists( $other_marker ) ) {
    if ( microtime( true ) > $deadline ) {
        echo json_encode( array( 'error' => 'timed out waiting for the other process' ) ) . "\n";
        exit( 1 );
    }
}

$fire_time = microtime( true );
$result    = spp_kq_submit_court_score( $occurrence_id, $round, $red_score, $black_score, $user_id );
$done_time = microtime( true );

echo json_encode( array(
    'user_id'     => $user_id,
    'fired_at'    => $fire_time,
    'finished_at' => $done_time,
    'result'      => $result,
) ) . "\n";
