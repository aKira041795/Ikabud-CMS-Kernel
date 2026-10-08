#!/usr/bin/env bash
#
# ACCEPTANCE GATE — the dispatch reconciliation must be unable to pass vacuously.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-dispatch-reconciliation.contract.md
#
# Why this gate exists. The invariant
#
#     dl_commissary_product_ledger.dispatched_qty  ==  dl_commissaryDepartedQtyByProduct()
#
# is asserted in that helper's docblock ("is kept equal to it") and enforced nowhere that
# can fail. tools/lane-consignee-depletion-probe.php criteria B, C and D are each written
# `$rows === [] || <no mismatches>`, so a run that compares NOTHING reports a full green
# PASS. Measured 2026-10-08: pointed at a date with no projection rows, the unmodified gate
# printed PASS on A-I and exited 0.
#
# So the gate that certified the consignee-depletion work would also certify an EMPTY
# database. This gate exists to end that.
#
# The three criteria, and what each is worth:
#
#   1 HEALTHY   the reconciliation runs and actually compares something (checked >= 1)
#   2 REFUSES   an empty comparison may NOT report success  <-- the repair
#   3 UNCORRUPTED a second healthy run still passes, AFTER the lane's perturbation proof,
#                 which is what makes the rollback provable rather than promised
#
# A guard never observed refusing is not a guard, so criterion 2 is the discriminating one:
# it FAILS on the unchanged tree and can only PASS once the vacuity is removed.
#
# Usage:  bash tools/lane-reconcile-dispatch-acceptance.sh
# Exit 0 = all criteria hold.  Exit 1 = at least one does not.

set -u

cd /var/www/html/applicationostest || exit 1

GATE="tools/lane-consignee-depletion-probe.php"
REAL_DATE='2026-10-07'
EMPTY_DATE='1999-01-01'   # no projection rows exist here; a real, runnable empty case

fails=0

note()  { printf '  %s\n' "$*"; }
pass()  { printf '  PASS  %s\n' "$*"; }
fail()  { printf '  FAIL  %s\n' "$*"; fails=$((fails + 1)); }

echo "== acceptance: the dispatch reconciliation cannot pass vacuously =="

# ---------------------------------------------------------------------------------------------
# 0. Preconditions. A gate that cannot run proves nothing, so say so rather than passing.
# ---------------------------------------------------------------------------------------------
if [ ! -f "$GATE" ]; then
  fail "the gate does not exist: $GATE"
  echo
  echo "ACCEPTANCE: FAIL (${fails} criterion/criteria)"
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  fail "php is not on PATH; nothing can be measured"
  echo
  echo "ACCEPTANCE: FAIL (${fails} criterion/criteria)"
  exit 1
fi

# ---------------------------------------------------------------------------------------------
# A helper that runs the REAL gate file against an OVERRIDDEN date, without editing the repo.
#
# The gate hard-codes its date, and the chair must not hold a second writer on a file the lane
# may be editing. sed into /tmp keeps this gate read-only with respect to the working tree.
# The basePath rewrite is required: `dirname(__DIR__)` from /tmp resolves to `/`.
# ---------------------------------------------------------------------------------------------
run_gate_at() {
  local date="$1" outfile="$2"
  sed -e "s/\$DATE = '$REAL_DATE'/\$DATE = '$date'/" \
      -e "s|^\$basePath = dirname(__DIR__);|\$basePath = '/var/www/html/applicationostest';|" \
      "$GATE" > "$outfile.php" 2>/dev/null || return 2
  php "$outfile.php" > "${outfile}.log" 2>&1
  return $?
}

# ---------------------------------------------------------------------------------------------
# 1 HEALTHY — the reconciliation runs and compares at least one value.
# ---------------------------------------------------------------------------------------------
healthy_out=/tmp/acc-reconcile-healthy
run_gate_at "$REAL_DATE" "$healthy_out"
healthy_code=$?

if [ "$healthy_code" -ne 0 ]; then
  fail "healthy tree: gate exited ${healthy_code} (want 0). tail:"
  tail -4 "${healthy_out}.log" | sed 's/^/        /'
else
  # The count of compared values must be positive and stated. "0 product(s)" is what the
  # old message said while reporting PASS, so search for an explicit checked count.
  checked=$(grep -oE 'checked[^0-9]*[0-9]+' "${healthy_out}.log" | grep -oE '[0-9]+' | tail -1)
  if [ -z "$checked" ]; then
    fail "healthy tree: gate exited 0 but never states how many values it compared."
    note  "an unstated count is indistinguishable from comparing nothing."
    tail -4 "${healthy_out}.log" | sed 's/^/        /'
  elif [ "$checked" -lt 1 ]; then
    fail "healthy tree: gate compared ${checked} value(s); want >= 1"
  else
    pass "healthy tree: gate compared ${checked} value(s) and agreed"
  fi
fi

# ---------------------------------------------------------------------------------------------
# 2 REFUSES — an empty comparison must NOT report success.  <-- the discriminating criterion
# ---------------------------------------------------------------------------------------------
empty_out=/tmp/acc-reconcile-empty
run_gate_at "$EMPTY_DATE" "$empty_out"
empty_code=$?

if [ "$empty_code" -eq 0 ]; then
  fail "empty comparison: gate exited 0 on a date with no projection rows."
  note  "It reported success while comparing nothing - this is the defect."
  grep -E 'PASS|FAIL' "${empty_out}.log" | sed 's/^/        /' | head -6
elif [ "$empty_code" -ge 126 ]; then
  fail "empty comparison: gate exited ${empty_code}, which is a shell/crash code, not a refusal."
  tail -4 "${empty_out}.log" | sed 's/^/        /'
else
  pass "empty comparison: gate refused it (exit ${empty_code})"
fi

# ---------------------------------------------------------------------------------------------
# 3 UNCORRUPTED — after the lane's perturbation proof, the healthy tree must STILL be healthy.
#
# This is what makes the rollback provable instead of merely promised: a perturbation that
# leaked would show up here as a mismatch.
# ---------------------------------------------------------------------------------------------
after_out=/tmp/acc-reconcile-after
run_gate_at "$REAL_DATE" "$after_out"
after_code=$?

if [ "$after_code" -ne 0 ]; then
  fail "after-perturbation: healthy tree no longer reconciles (exit ${after_code})."
  note  "A perturbation may have leaked, or a real mismatch was introduced."
  tail -4 "${after_out}.log" | sed 's/^/        /'
else
  pass "after-perturbation: healthy tree still reconciles, so nothing persisted"
fi

# ---------------------------------------------------------------------------------------------
# 4 SCOPE — this lane changes verification, not behaviour.
# ---------------------------------------------------------------------------------------------
# The two files the lane may touch, plus CHAIR-OWNED harness artefacts (contracts, gates,
# controls) which are the chair's to write and must never count against the lane, plus the
# owner's unrelated untracked doc.
unexpected=$(git status --porcelain 2>/dev/null \
  | awk '{print $NF}' \
  | grep -vE '^(tools/lane-consignee-depletion\.php|modules/daily-ledger/handlers\.php|tools/lane-reconcile[^/]*|tools/lane-consignee-depletion-probe\.php|tools/chair-[^/]*|\.ai/[^/]*|docs/reviews/windows-desktop-client-feasibility-2026-10-07\.md)$' \
  || true)
if [ -n "$unexpected" ]; then
  fail "scope: unexpected changed path(s):"
  printf '%s\n' "$unexpected" | sed 's/^/        /'
else
  pass "scope: only the two allowed files changed"
fi

echo
if [ "$fails" -eq 0 ]; then
  echo "ACCEPTANCE: PASS (the reconciliation refuses an empty comparison and the tree is clean)"
  exit 0
fi

echo "ACCEPTANCE: FAIL (${fails} criterion/criteria)"
exit 1
