#!/usr/bin/env bash
#
# Acceptance criterion for lane dl-tranche3-output-posting-invariant.
#
# Client concern #1/#8: branch stock must not appear without a dispatch and a receipt.
#
# Asserts OUTCOMES:
#   A  an `output` addressed to a BRANCH with no DR is REFUSED under the formal delivery
#      workflow (the guard exists and is scoped so it cannot fire on a commissary-self output)
#   B  the guard is scoped by $isCommissaryDirectOutput, so the Daily Sheet "Record Addition"
#      caller (handlers.php ~4624, destination = the commissary itself, no DR) still works
#   C  the existing withdrawal DR guard is untouched
#   D  the formal-OFF legacy path still reaches the direct-credit else-branch
#
# Verified RED on base 2026-11-09 before dispatch: the output guard did not exist.

set -u
cd "$(dirname "$0")/../.." || exit 2

H=modules/daily-ledger/handlers.php
[ -f "$H" ] || { echo "FAIL: $H missing"; exit 2; }

rc=0
fail() { echo "FAIL: $1"; rc=1; }

BODY="$(awk 'index($0,"function dl_processProductionMovement")==1{p=1} p{print} p&&/^}$/{exit}' "$H")"
[ -n "$BODY" ] || { echo "FAIL: could not read dl_processProductionMovement"; exit 2; }

# A + B — one guard, correctly scoped. The conjunction of the output type, the empty-DR test and
# the commissary-self exclusion must appear together; that is what makes the fix both effective
# and safe. Accept any formatting/ordering by testing the pieces against the same guard region.
GUARD="$(printf '%s\n' "$BODY" | grep -n "isCommissaryDirectOutput" | head -20)"
if printf '%s\n' "$BODY" | grep -qE "isCommissaryDirectOutput[^;]*&&[^;]*drNumber === ''|drNumber === ''[^;]*&&[^;]*isCommissaryDirectOutput|!\s*\\\$isCommissaryDirectOutput[^;]*drNumber"; then
  echo "ok   A/B output-without-DR guard exists and is scoped by isCommissaryDirectOutput"
else
  # Fall back to a multi-line scan: the guard may be formatted across lines.
  if printf '%s\n' "$BODY" | tr '\n' ' ' | grep -qE "\!\s*\\\$isCommissaryDirectOutput\s*&&\s*\\\$drNumber === ''"; then
    echo "ok   A/B output-without-DR guard exists and is scoped by isCommissaryDirectOutput"
  else
    fail "A/B no output-without-DR guard scoped by isCommissaryDirectOutput"
  fi
fi

# The refusal must tell the operator to supply a DR. Assert the OUTCOME (a second DR requirement
# now exists, beyond the pre-existing withdrawal one), NOT the phrasing: the first version of this
# check demanded the literal words "is required for production (output|delivery)" and scored a
# clearer message ("Enter a Delivery Receipt number ...") as a failure. That is the third instance
# of the same false-red class — an oracle that encodes wording instead of behaviour.
DR_MENTIONS="$(printf '%s\n' "$BODY" | grep -c 'Delivery Receipt number')"
if [ "${DR_MENTIONS:-0}" -ge 2 ]; then
  echo "ok   A2 a second DR requirement exists for output (${DR_MENTIONS} DR requirements in the function)"
else
  fail "A2 no output-specific DR requirement (only ${DR_MENTIONS:-0} DR mention(s), i.e. just the pre-existing withdrawal guard)"
fi

# C — the pre-existing withdrawal guard must survive verbatim.
if printf '%s\n' "$BODY" | grep -q "Delivery Receipt number is required for production withdrawal"; then
  echo "ok   C existing withdrawal DR guard intact"
else
  fail "C existing withdrawal DR guard was removed or reworded"
fi

# D — the legacy direct-credit path must still exist (formal-OFF tenants depend on it).
if printf '%s\n' "$BODY" | grep -q "dl_applyLedgerDelta(\$destinationBranchId"; then
  echo "ok   D legacy direct-credit branch still present for formal-OFF tenants"
else
  fail "D the direct-credit branch was deleted (would break formal-OFF tenants)"
fi

exit "$rc"
