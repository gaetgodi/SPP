#!/bin/bash
# Ace/Queen of the Courts -- concurrency test for the atomic
# start-round-1 transition. Launches two PHP processes that rendezvous
# via a mutual-readiness file barrier (removing WP-bootstrap-time
# jitter from the equation) and then both attempt
# spp_kq_transition_start_round1() for the same occurrence_id as close
# to simultaneously as this environment allows. Confirms exactly one
# of them wins.
#
# Usage: spp-kq-concurrency-test.sh <fake_occurrence_id>
# The caller is responsible for seeding that occurrence_id with 4-16
# (multiple of 4) confirmed gl_registrations rows beforehand, and for
# cleanup afterward -- this script only fires the race.

set -e
OCC="$1"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -z "$OCC" ]; then
    echo "Usage: $0 <fake_occurrence_id>" >&2
    exit 1
fi

MARKER_A="/tmp/kq_ready_a_$$"
MARKER_B="/tmp/kq_ready_b_$$"
rm -f "$MARKER_A" "$MARKER_B"

php "$DIR/spp-kq-concurrency-probe.php" "$OCC" "$MARKER_A" "$MARKER_B" > /tmp/kq_probe_a_$$.json &
PID_A=$!
php "$DIR/spp-kq-concurrency-probe.php" "$OCC" "$MARKER_B" "$MARKER_A" > /tmp/kq_probe_b_$$.json &
PID_B=$!

wait "$PID_A"
wait "$PID_B"

echo "=== Process A ==="
cat /tmp/kq_probe_a_$$.json
echo "=== Process B ==="
cat /tmp/kq_probe_b_$$.json

rm -f "$MARKER_A" "$MARKER_B" /tmp/kq_probe_a_$$.json /tmp/kq_probe_b_$$.json
