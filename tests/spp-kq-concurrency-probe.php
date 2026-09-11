<?php
/**
 * Ace/Queen of the Courts — one-shot concurrency probe.
 *
 * Launched twice, in parallel, by tests/spp-kq-concurrency-test.sh --
 * both copies rendezvous via a mutual-readiness file barrier (argv[2]/
 * argv[3]: this process's own marker file, and the marker file it
 * must see appear before firing) so both fire immediately after WP
 * bootstrap is ALREADY complete for both of them, rather than racing
 * against a predicted wall-clock instant -- a first version of this
 * probe used a shared target microtime() instead, and in practice
 * process-startup jitter (WP bootstrap time varying between the two)
 * was large enough that one process sometimes finished its entire
 * transition before the other even started, silently testing
 * sequential correctness instead of real concurrency. This barrier
 * removes that startup-time variance from the equation entirely --
 * by the time either process touches its marker file, its own
 * bootstrap is done and it is doing nothing but polling for the
 * other's marker.
 *
 * NOT a permanent regression test (unlike tests/spp-kq-movement-test.php) --
 * this one exercises real database writes against a disposable fake
 * occurrence_id and is meant to be run on demand, by hand, when the
 * concurrency mechanism itself changes.
 */

define( 'WP_USE_THEMES', false );
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';

$occurrence_id = (int) $argv[1];
$my_marker     = $argv[2];
$other_marker  = $argv[3];

// Signal readiness, then spin until the other process signals too.
// Both processes' WP bootstrap (the slow, variable part) is already
// fully done by this point -- only the poll loop below still runs.
touch( $my_marker );
$deadline = microtime( true ) + 5.0; // safety valve, should never be hit
while ( ! file_exists( $other_marker ) ) {
    if ( microtime( true ) > $deadline ) {
        echo json_encode( array( 'error' => 'timed out waiting for the other process' ) ) . "\n";
        exit( 1 );
    }
}

$fire_time = microtime( true );
$result    = spp_kq_transition_start_round1( $occurrence_id );
$done_time = microtime( true );

echo json_encode( array(
    'pid'         => getmypid(),
    'fired_at'    => $fire_time,
    'finished_at' => $done_time,
    'result'      => $result,
) ) . "\n";
