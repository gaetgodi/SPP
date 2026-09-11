<?php
/**
 * One-shot probe: two of these, launched in parallel via
 * spp-kq-draw-concurrency-test.sh, rendezvous on a file barrier then
 * both call spp_kq_draw_card() for DIFFERENT players at (as close to)
 * the same instant as this environment allows -- confirms neither
 * loses a slot, neither double-claims, and the two players never end
 * up in the same (court, color) slot.
 */

define( 'WP_USE_THEMES', false );
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';

$occurrence_id = (int) $argv[1];
$user_id       = (int) $argv[2];
$my_marker     = $argv[3];
$other_marker  = $argv[4];

touch( $my_marker );
$deadline = microtime( true ) + 5.0;
while ( ! file_exists( $other_marker ) ) {
    if ( microtime( true ) > $deadline ) {
        echo json_encode( array( 'error' => 'timed out' ) ) . "\n";
        exit( 1 );
    }
}

$result = spp_kq_draw_card( $occurrence_id, $user_id );
echo json_encode( array( 'user_id' => $user_id, 'result' => $result ) ) . "\n";
