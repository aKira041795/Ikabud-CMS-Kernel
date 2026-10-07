#!/usr/bin/env bash
#
# Acceptance probe for lane harness-criterion-level (contract .ai/harness-criterion-level.contract.md).
#
# For a HARNESS change the operator-visible outcome IS the dispatcher's decision, so the correct level
# here is to invoke the real entry point (tools/lane.sh run) and observe what it decides. Asserting that
# some string appears in lane.sh would be a mechanism assertion - exactly the failure class this work
# exists to fix.
#
# Both directions, because a gate that refuses everything is a wrong guard rather than a strict one:
#   A. must-refuse : --required-level=http --criterion-level=unit  -> rc=2, attributable to the LEVEL
#   B. must-allow  : --required-level=http --criterion-level=http  -> dispatch proceeds (rc != 2)
#
# ATTRIBUTABILITY: both dispatches pass --no-acceptance-gate, so the acceptance gate cannot produce the
# rc=2 itself. Any rc=2 in case A can only come from the level gate. Without this the probe would pass
# on the unchanged tree for the wrong reason.
#
# MEASURED on the unchanged tree 2026-10-07 BEFORE the change: the unknown flags are ignored, so case A
# dispatches (rc != 2) and this probe FAILS. The gate discriminates.

cd /var/www/html/applicationostest || exit 1

RUNS=".ai/runs"
PROBE_TMP=$(mktemp -d)
FIXTURE="$PROBE_TMP/lane-level-gate-fixture.sh"
FAILED=0

# A harmless lane: it does nothing and exits. On the fixed tree the level gate must refuse BEFORE this
# script is ever executed, which is itself part of the claim.
cat > "$FIXTURE" <<'FIXTURE_EOF'
#!/usr/bin/env bash
# Level-gate probe fixture. Never intended to do work; the refusal must happen before dispatch.
exit 0
FIXTURE_EOF
chmod +x "$FIXTURE"

# ── A. must-refuse: the criterion observes LESS than the obligation requires ──────────────────────
A_LOG="$PROBE_TMP/a.log"
bash tools/lane.sh run lvlgate-a "$FIXTURE" --wait-grace=2 \
    --required-level=http --criterion-level=unit \
    --no-acceptance-gate='probe: the acceptance gate must not decide this case' \
    > "$A_LOG" 2>&1
rc_a=$?

a_names_levels=0
grep -q 'http' "$A_LOG" && grep -q 'unit' "$A_LOG" && a_names_levels=1

if [ "$rc_a" -eq 2 ] && [ "$a_names_levels" -eq 1 ]; then
    echo "  PASS  must-refuse: a unit criterion is refused for an http obligation (rc=$rc_a), naming both levels"
else
    echo "  FAIL  must-refuse: expected rc=2 naming both levels; got rc=$rc_a, levelsNamed=$a_names_levels"
    sed -n '1,8p' "$A_LOG" | sed 's/^/        /'
    FAILED=1
fi

# ── B. must-allow: the criterion observes AT LEAST what the obligation requires ────────────────────
B_LOG="$PROBE_TMP/b.log"
bash tools/lane.sh run lvlgate-b "$FIXTURE" --wait-grace=2 \
    --required-level=http --criterion-level=http \
    --no-acceptance-gate='probe: the acceptance gate must not decide this case' \
    > "$B_LOG" 2>&1
rc_b=$?

if [ "$rc_b" -ne 2 ]; then
    echo "  PASS  must-allow: a matching http criterion is not refused by the level gate (rc=$rc_b)"
else
    echo "  FAIL  must-allow: the level gate refused a criterion that meets the requirement (rc=$rc_b)"
    sed -n '1,8p' "$B_LOG" | sed 's/^/        /'
    FAILED=1
fi

# ── cleanup: leave no test residue for a later reader to trip over ────────────────────────────────
rm -rf "$PROBE_TMP"
# Clear ALL lvlgate-* rather than only this probe's own a/b names: the selftest's level-gate cases
# (S9f/S9g/S9h) also dispatch real fixture lanes under this prefix, so they recreate artefacts on
# every selftest run. Two things learned the hard way here:
#   - commit.lock is a DIRECTORY and deliberately persistent in lane.sh, so `rm -f` fails on it
#     ("Is a directory") and a suppressed stderr hides that. Use -rf.
#   - a cleanup that cannot fail silently is the point: report residue rather than leaving it.
rm -rf "$RUNS"/lvlgate-*
residue=$(ls -1 "$RUNS" 2>/dev/null | grep -c '^lvlgate-' || true)
if [ "$residue" != "0" ]; then
    echo "  WARN  test residue remains in $RUNS ($residue):"
    ls -1 "$RUNS" | grep '^lvlgate-' | sed 's/^/        /'
fi

if [ "$FAILED" -ne 0 ]; then
    echo "FAIL: the criterion level gate does not refuse insufficient evidence" >&2
    exit 1
fi

echo "PASS: the level gate refuses a criterion below the required level and admits one at or above it"
exit 0
