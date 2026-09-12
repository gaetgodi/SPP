#!/bin/bash
# Ace/Queen of the Courts -- concurrency test for the round-advance
# trigger inside the real score-submit path (Stage 3). Seeds a fake
# 16-player/4-court occurrence, sequentially reports 2 of the 4 courts
# (no race needed for those), then fires the LAST TWO courts'
# submissions as close to simultaneously as this environment allows
# via a mutual-readiness file barrier, exactly like Stage 2's own
# concurrency test but through the real submit path instead of the
# bare transition function.
#
# Usage: spp-kq-score-concurrency-test.sh
# Self-contained: seeds and cleans up its own fake occurrence_id.

set -e
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OCC=999999005
ROSTER=(2484 2442 2883 3131 2542 2164 3039 2157 2205 3046 2351 2488 2648 2541 2993 2893)

php -r "
define('WP_USE_THEMES', false);
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
global \$wpdb;
\$occ = $OCC;
\$roster = [$(IFS=,; echo "${ROSTER[*]}")];
foreach (\$roster as \$uid) {
    \$wpdb->query(\$wpdb->prepare(
        \"INSERT INTO {\$wpdb->prefix}gl_registrations (occurrence_id, user_id, status, registered_at, updated_at) VALUES (%d, %d, 'confirmed', NOW(), NOW())\",
        \$occ, \$uid
    ));
}
\$start = spp_kq_transition_start_round1(\$occ);
if (!\$start['won']) { fwrite(STDERR, 'start_round1 failed: ' . json_encode(\$start) . \"\n\"); exit(1); }
foreach (\$roster as \$uid) {
    \$r = spp_kq_draw_card(\$occ, \$uid);
    if (!\$r['success']) { fwrite(STDERR, \"draw failed for \$uid: {\$r['error']}\n\"); exit(1); }
}
\$played = spp_kq_transition_start_play(\$occ, 1);
if (!\$played['won']) { fwrite(STDERR, \"start_play failed\n\"); exit(1); }

// Map each court to one of its players, report the first two courts sequentially.
\$by_court = [];
foreach (\$roster as \$uid) {
    \$a = spp_kq_get_my_court_assignment(\$occ, 1, \$uid);
    \$by_court[\$a['court_name']][] = \$uid;
}
\$courts = array_keys(\$by_court);
echo 'COURTS:' . implode(',', \$courts) . \"\n\";
echo 'PLAYER_COURT0:' . \$by_court[\$courts[0]][0] . \"\n\";
echo 'PLAYER_COURT1:' . \$by_court[\$courts[1]][0] . \"\n\";
echo 'PLAYER_COURT2:' . \$by_court[\$courts[2]][0] . \"\n\";
echo 'PLAYER_COURT3:' . \$by_court[\$courts[3]][0] . \"\n\";

spp_kq_submit_court_score(\$occ, 1, 21, 15, \$by_court[\$courts[0]][0]);
spp_kq_submit_court_score(\$occ, 1, 21, 12, \$by_court[\$courts[1]][0]);
echo \"seeded: 2 of 4 courts reported sequentially\n\";
" > /tmp/kq_score_setup.txt

cat /tmp/kq_score_setup.txt
PLAYER_A=$(grep PLAYER_COURT2 /tmp/kq_score_setup.txt | cut -d: -f2)
PLAYER_B=$(grep PLAYER_COURT3 /tmp/kq_score_setup.txt | cut -d: -f2)
rm -f /tmp/kq_score_setup.txt

MARKER_A="/tmp/kq_score_ready_a_$$"
MARKER_B="/tmp/kq_score_ready_b_$$"
rm -f "$MARKER_A" "$MARKER_B"

php "$DIR/spp-kq-score-concurrency-probe.php" "$OCC" 1 "$PLAYER_A" 21 9  "$MARKER_A" "$MARKER_B" > /tmp/kq_score_a_$$.json &
PA=$!
php "$DIR/spp-kq-score-concurrency-probe.php" "$OCC" 1 "$PLAYER_B" 8 21  "$MARKER_B" "$MARKER_A" > /tmp/kq_score_b_$$.json &
PB=$!
wait "$PA"; wait "$PB"

echo "=== Process A (court holding player $PLAYER_A) ==="
cat /tmp/kq_score_a_$$.json
echo "=== Process B (court holding player $PLAYER_B) ==="
cat /tmp/kq_score_b_$$.json

echo "=== Final DB state ==="
php -r "
define('WP_USE_THEMES', false);
require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
global \$wpdb;
\$occ = $OCC;
\$state = spp_kq_get_event_state(\$occ);
echo 'phase=' . \$state['phase'] . ' current_round=' . \$state['current_round'] . \"\n\";
echo 'round 1 score rows: ' . \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . spp_kq_scores_table() . ' WHERE occurrence_id=%d AND round_number=1 AND red_score IS NOT NULL', \$occ)) . \" of 4 reported\n\";
echo 'round 2 assignment rows: ' . \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . spp_kq_assignments_table() . ' WHERE occurrence_id=%d AND round_number=2', \$occ)) . \" (expect 16 if advanced, 0 if not)\n\";

// cleanup
\$wpdb->query(\$wpdb->prepare('DELETE FROM ' . \$wpdb->prefix . 'gl_registrations WHERE occurrence_id=%d', \$occ));
\$wpdb->query(\$wpdb->prepare('DELETE FROM ' . spp_kq_events_table() . ' WHERE occurrence_id=%d', \$occ));
\$wpdb->query(\$wpdb->prepare('DELETE FROM ' . spp_kq_assignments_table() . ' WHERE occurrence_id=%d', \$occ));
\$wpdb->query(\$wpdb->prepare('DELETE FROM ' . spp_kq_scores_table() . ' WHERE occurrence_id=%d', \$occ));
echo \"cleaned up\n\";
"

rm -f "$MARKER_A" "$MARKER_B" /tmp/kq_score_a_$$.json /tmp/kq_score_b_$$.json
