#!/usr/bin/env bash
#
# tools/harness-changed-files-probe.sh
#
# THE CRITERION for "the landing record must describe THIS lane's contribution, not the
# repository's dirty state".
#
# WHY IT EXISTS. `.ai/runs/<name>.landed.json` reports `changed_files` as
# `git status --porcelain | wc -l` over the WHOLE tree. So a landing report counts other lanes'
# uncommitted work and pre-existing strays as if this lane had produced them. Measured
# 2026-10-02: a lane named `killtest` lived 9 SECONDS and its landing still reported
# `"changed_files": 11`. The number is therefore unusable for the one thing it exists for - the
# SCOPE CHECK, where the reviewer decides whether an implementation stayed inside its contract.
#
# THE AMBIGUOUS CASE IS DEFINED HERE, deliberately, rather than left to the implementation to
# guess: a file that was ALREADY dirty at dispatch and is modified AGAIN by the lane cannot be
# identified from `git status` alone. The harness must NOT claim it as the lane's work, and it
# must NOT hide it either - it reports the counts that make the ambiguity visible.
#
# DIRECTIONS:
#   D1 (must-allow, DISCRIMINATING)  pre-dirty A, lane writes B
#        -> changed_paths names B and NOT A; changed_files == 1; tree_dirty == 2; dirty_before == 1
#        Mutation that must kill the implementation: keep the whole-tree count.
#   D2 (must-hold, the DEFINED ambiguous case)  pre-dirty A, lane appends to A again
#        -> A is NOT claimed as the lane's; changed_files == 0; tree_dirty == 1; dirty_before == 1
#        The reader can SEE the ambiguity (tree dirty, lane credited with nothing) instead of the
#        harness inventing an attribution it cannot know.
#   D3 (must-allow)  clean tree, lane changes nothing -> changed_files == 0; tree_dirty == 0
#   D4 (PIN, backward compatibility)  `record` with no baseline captured -> the basis is "tree" and
#        the marker is still VALID JSON. Passes on the base tree; protects the self-hosting path
#        where the generated runner calls record with the legacy argument count.
#
# Exit 0 = the record describes the lane. Non-zero = it describes the repository.
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

RUNS="$ROOT/.ai/runs"
P="$RUNS/probe-changed"
mkdir -p "$P"

# Fixtures live at the repo ROOT so they appear in `git status --porcelain`: anything under .ai/
# is gitignored and therefore invisible to the very mechanism under test.
F_PRE="$ROOT/.probe-changed-preexisting.txt"
F_OWN="$ROOT/.probe-changed-owned.txt"
cleanup() { rm -f "$F_PRE" "$F_OWN"; }
trap cleanup EXIT
cleanup

pass=0; fail=0
ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
bad() { echo "   FAIL  $1"; fail=$((fail+1)); }
mnum() { grep -o "\"$2\": [0-9]*" "$1" 2>/dev/null | head -1 | grep -o '[0-9]*'; }
mstr() { grep -o "\"$2\": \"[^\"]*\"" "$1" 2>/dev/null | head -1 | cut -d'"' -f4; }

run_fixture() { # run_fixture <name> <lane-path>
  rm -f "$RUNS/$1.landed.json" "$RUNS/$1.log" "$RUNS/$1.tree.before"
  rmdir "$RUNS/$1.commit.lock" 2>/dev/null || true
  bash tools/lane.sh run "$1" "$2" --timeout=60 --wait-grace=2 --slice=20 \
    --acceptance='exit 1' --pass-looks-like='the fixture lane lands' > "$P/$1.mon.log" 2>&1
}

echo "== does the landing record describe the LANE, or the whole tree? =="

# ── D1: pre-dirty unrelated file must NOT be attributed to the lane ───────────
# EACH DIRECTION STARTS FROM THE SAME KNOWN BASELINE. Measured 2026-10-07: D1 leaves the file its
# fixture created dirty, so without this reset D2 saw TWO dirty paths and its 1/1 expectation was
# UNSATISFIABLE - the only way to satisfy it would be to hide prior dirty paths, which is precisely
# what R8 forbids. The implementing lane reported BLOCKED with exactly that analysis and the chair's
# criterion was the defect, for the sixth time in this repository. A probe whose directions are not
# independent is not three probes, it is one probe with two aliases.
cleanup
printf 'pre-existing stray\n' > "$F_PRE"
{ echo '#!/usr/bin/env bash'
  echo "echo own > '$F_OWN'"
  echo 'echo "status: PASS"'
} > "$P/lane-d1.sh"; chmod +x "$P/lane-d1.sh"
run_fixture probe-changed-d1 "$P/lane-d1.sh"
mD1="$RUNS/probe-changed-d1.landed.json"
cfD1=$(mnum "$mD1" changed_files); treeD1=$(mnum "$mD1" tree_dirty); beforeD1=$(mnum "$mD1" dirty_before)
# PATH-SPECIFIC, with the dirty counts only lower-bounded. These are governed lanes sharing ONE
# working tree, so other lanes' uncommitted work is normal and an absolute `tree_dirty == 2` would
# make this direction wrong more often than right - a wrong guard is worse than none. The
# discrimination now rests on the DELTA being exactly the lane's own path, and on the basis being
# "delta" at all (it does not exist on the unfixed tree, so the direction stays discriminating).
if grep -q 'probe-changed-owned' "$mD1" && ! grep -q 'probe-changed-preexisting' "$mD1" \
   && [ "${cfD1:-x}" = "1" ] && [ "$(mstr "$mD1" changed_files_basis)" = "delta" ] \
   && [ "${beforeD1:-0}" -ge 1 ]; then
  ok "D1 only the lane's own file is attributed (changed_files=$cfD1 tree_dirty=$treeD1 dirty_before=$beforeD1)"
else
  bad "D1 attribution is wrong (changed_files='${cfD1:-}' basis='$(mstr "$mD1" changed_files_basis)' dirty_before='${beforeD1:-}', claims_preexisting=$(grep -c 'probe-changed-preexisting' "$mD1" 2>/dev/null))"
fi

# ── D2: the ambiguous case, DEFINED - dirty before AND touched by the lane ────
cleanup
printf 'pre-existing stray\n' > "$F_PRE"
{ echo '#!/usr/bin/env bash'
  echo "echo also-touched >> '$F_PRE'"
  echo 'echo "status: PASS"'
} > "$P/lane-d2.sh"; chmod +x "$P/lane-d2.sh"
run_fixture probe-changed-d2 "$P/lane-d2.sh"
mD2="$RUNS/probe-changed-d2.landed.json"
cfD2=$(mnum "$mD2" changed_files); treeD2=$(mnum "$mD2" tree_dirty); beforeD2=$(mnum "$mD2" dirty_before)
# The observable claim is: a path that was ALREADY dirty is never claimed as the lane's work, and
# the run still reports a delta basis with the ambiguity visible (dirty_before >= 1, and tree_dirty
# exceeding changed_files). Not invented, not hidden.
if ! grep -q 'probe-changed-preexisting' "$mD2" && [ "${cfD2:-x}" = "0" ] \
   && [ "$(mstr "$mD2" changed_files_basis)" = "delta" ] && [ "${beforeD2:-0}" -ge 1 ] \
   && [ "${treeD2:-0}" -ge "${beforeD2:-0}" ]; then
  ok "D2 the ambiguous case is reported, not invented (changed_files=$cfD2 tree_dirty=$treeD2 dirty_before=$beforeD2)"
else
  bad "D2 the ambiguous case was mis-attributed (changed_files='${cfD2:-}' basis='$(mstr "$mD2" changed_files_basis)' tree_dirty='${treeD2:-}' dirty_before='${beforeD2:-}')"
fi

# ── D3: a lane that changes nothing, from a clean tree ───────────────────────
cleanup
{ echo '#!/usr/bin/env bash'
  echo 'echo "status: PASS"'
} > "$P/lane-d3.sh"; chmod +x "$P/lane-d3.sh"
run_fixture probe-changed-d3 "$P/lane-d3.sh"
mD3="$RUNS/probe-changed-d3.landed.json"
cfD3=$(mnum "$mD3" changed_files)
if [ "${cfD3:-x}" = "0" ] && [ "$(mstr "$mD3" changed_files_basis)" = "delta" ]; then
  ok "D3 a lane that changed nothing is credited with nothing (changed_files=$cfD3)"
else
  bad "D3 an idle lane was credited (changed_files='${cfD3:-}' basis='$(mstr "$mD3" changed_files_basis)')"
fi

# ── D4: PIN - the legacy record path must still produce valid JSON ───────────
# The generated runner that records THIS lane's landing was written before the change and calls
# record with four arguments. That path must keep working.
#
# The commit lock MUST be cleared first, and this was a real defect in this probe: commit_landing
# deliberately keeps `$RUNS/<name>.commit.lock` as proof that a dispatch already committed, so on
# the SECOND and every later run it refuses and writes NO marker - and D4 then fails because
# `php json_decode` reads a file that does not exist. Measured 2026-10-07: D4 passed on the first
# ever run and failed on the gate's run 60 seconds later, which is the signature of a guard that is
# wrong more often than it is right. It would have reported a FALSE RED on correct work.
rm -f "$RUNS/probe-changed-d4.landed.json"
rmdir "$RUNS/probe-changed-d4.commit.lock" 2>/dev/null || true
bash tools/lane.sh record probe-changed-d4 "$RUNS/probe-changed-d4.log" 0 > "$P/d4.rec.log" 2>&1
rcD4=$?
mD4="$RUNS/probe-changed-d4.landed.json"
basisD4=$(mstr "$mD4" changed_files_basis)
if [ "$rcD4" -eq 0 ] && php -r 'exit(json_decode(file_get_contents($argv[1]))===null?1:0);' "$mD4" 2>/dev/null; then
  ok "D4 PIN: a legacy record with no baseline is valid JSON (basis='${basisD4:-unset}')"
else
  bad "D4 the legacy record path broke (rc=$rcD4 basis='${basisD4:-unset}')"
fi

echo
echo "== probe: $pass passed, $fail failed =="
[ "$fail" -eq 0 ] || exit 1
exit 0
