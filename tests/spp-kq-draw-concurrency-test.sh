#!/bin/bash
# Fires two spp_kq_draw_card() calls for two different players at the
# same occurrence, as simultaneously as possible via a file barrier.
# Usage: spp-kq-draw-concurrency-test.sh <occurrence_id> <user_id_a> <user_id_b>
set -e
OCC="$1"; UID_A="$2"; UID_B="$3"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MARKER_A="/tmp/kq_draw_ready_a_$$"
MARKER_B="/tmp/kq_draw_ready_b_$$"
rm -f "$MARKER_A" "$MARKER_B"

php "$DIR/spp-kq-draw-concurrency-probe.php" "$OCC" "$UID_A" "$MARKER_A" "$MARKER_B" > /tmp/kq_draw_a_$$.json &
PA=$!
php "$DIR/spp-kq-draw-concurrency-probe.php" "$OCC" "$UID_B" "$MARKER_B" "$MARKER_A" > /tmp/kq_draw_b_$$.json &
PB=$!
wait "$PA"; wait "$PB"

cat /tmp/kq_draw_a_$$.json
cat /tmp/kq_draw_b_$$.json
rm -f "$MARKER_A" "$MARKER_B" /tmp/kq_draw_a_$$.json /tmp/kq_draw_b_$$.json
