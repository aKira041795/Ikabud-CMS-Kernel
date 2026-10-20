#!/usr/bin/env bash
#
# tools/lane-harness-bench.sh — one gate over the harness's own failure-mode tests.
#
# Why this exists: improvements to the lane harness (roles, events, checkpoints, model
# routing) were previously unfalsifiable - each piece had a selftest, but nothing ran
# them together, so a change could pass its own case and break another. This is the
# benchmark corpus for the HARNESS, not for a coding model: it runs deterministic checks
# that already encode historical failures, and it fails closed.
#
# Run:
#   bash tools/lane-harness-bench.sh
#
# Output is one line per case. Full logs land in .ai/runs/harness-bench-<timestamp>/.
# Exit 0 only when every case passes.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG_DIR="$ROOT/.ai/runs/harness-bench-$STAMP"
mkdir -p "$LOG_DIR"

pass=0
fail=0
failed_names=""

run_case() {
  local name="$1"; shift
  local slug log
  slug=$(printf '%s' "$name" | tr ' /' '--' | tr -cd '[:alnum:]._-')
  log="$LOG_DIR/$slug.log"
  echo "== $name =="
  if "$@" > "$log" 2>&1; then
    echo "   PASS  $name  ($log)"
    pass=$((pass + 1))
  else
    echo "   FAIL  $name  ($log)"
    tail -20 "$log" | sed 's/^/         /'
    fail=$((fail + 1))
    failed_names="$failed_names $slug"
  fi
}

# 1. Event log: contradiction detection (no_report with a report, missing logs, policy
#    violations with exit 0, completion=BLOCKED with exit 0).
run_case "lane-events unit tests" \
  python3 -m unittest discover -s "$ROOT/tools/tests" -p 'test_lane_events.py'

# 2. Role/tool/git policy: allow and refuse directions, per-file attribution.
run_case "lane policy selftest" \
  bash "$ROOT/tools/lane-policy.sh" selftest

# 3. Provider-neutral consultation: prompt composition, red-team targeting, Claude
#    CLI/API transports, and clean session history.
run_case "consult provider tests" \
  python3 -m unittest discover -s "$ROOT/tools/tests" -p 'test_consult.py'

# 4. The lane wrapper itself: landing detection, duplicate commit, attribution, legacy
#    records, non-git workspaces, and now the completion field.
run_case "lane harness selftest" \
  bash "$ROOT/tools/lane.sh" selftest

echo
echo "== harness bench: $pass passed, $fail failed =="
echo "   logs: $LOG_DIR"
if [ "$fail" -ne 0 ]; then
  echo "   failed:$failed_names"
  exit 1
fi
exit 0
