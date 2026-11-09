#!/usr/bin/env bash
#
# Acceptance criterion for lane dl-tranche1-shift-dr-audit.
#
# Exits 0 only when all three tranche-1 invariants hold. Verified RED on the base tree
# (2026-11-09) before dispatch: all three were absent, composite rc=1.
#
# A. apiFinalizePmShift resolves the branch's day-ending shift instead of hardcoding 'PM'.
# B. dl_applyLedgerDelta writes an audit row, so Activity can name the actor.
# C. A dedicated commissary-delivery view key exists (NOT a widening of
#    can_view_production_management, which also gates Reopen Shift).
#
# REVISED 2026-11-09 after the first landing. v1 asserted a MECHANISM ("dl_isLastShiftOfDay
# appears inside apiFinalizePmShift"), so a CORRECT implementation that introduced a day-ending
# resolver helper scored RED. That is the documented false-red class: assert the OUTCOME, never
# the mechanism the change replaces. This version accepts any resolver name and adds A3, which
# asserts the hardcoded PM ending validation is actually gone.

set -u
cd "$(dirname "$0")/../.." || exit 2

H=modules/daily-ledger/handlers.php
[ -f "$H" ] || { echo "FAIL: $H missing"; exit 2; }

rc=0
fail() { echo "FAIL: $1"; rc=1; }

# A — finalize must consult a per-branch day-ending shift (any resolver name is acceptable).
FIN="$(awk 'index($0,"function apiFinalizePmShift")==1{p=1} p{print} p&&/^}$/{exit}' "$H")"
CLS="$(awk 'index($0,"function apiCloseDay")==1{p=1} p{print} p&&/^}$/{exit}' "$H")"
DLT="$(awk 'index($0,"function dl_applyLedgerDelta")==1{p=1} p{print} p&&/^}$/{exit}' "$H")"

[ -n "$FIN" ] || fail "could not read apiFinalizePmShift"
[ -n "$CLS" ] || fail "could not read apiCloseDay"
[ -n "$DLT" ] || fail "could not read dl_applyLedgerDelta"

if printf '%s' "$FIN" | grep -qE 'dl_dayEndingShift|dl_isLastShiftOfDay'; then
  echo "ok   A apiFinalizePmShift resolves a per-branch day-ending shift"
else
  fail "A apiFinalizePmShift does not consult any day-ending shift resolver"
fi

# A2 — close-day must demand the day-ending shift, not PM unconditionally.
if printf '%s' "$CLS" | grep -qE 'dl_dayEndingShift|dl_isLastShiftOfDay'; then
  echo "ok   A2 apiCloseDay gates on the day-ending shift"
else
  fail "A2 apiCloseDay still demands a finalized PM shift unconditionally"
fi

# A3 — OUTCOME: the hardcoded PM ending validation must be gone.
if printf '%s' "$FIN" | grep -qE "dl_shiftMissingEndings\([^)]*'PM'"; then
  fail "A3 finalize still validates a hardcoded 'PM' ending set"
else
  echo "ok   A3 no hardcoded PM ending validation in finalize"
fi

# B
if printf '%s' "$DLT" | grep -q 'dl_auditLog'; then
  echo "ok   B dl_applyLedgerDelta writes an audit row"
else
  fail "B dl_applyLedgerDelta still writes no audit row"
fi

# B2 — the audit row must answer "who put this here", not merely exist.
for field in delta value_before value_after actor_id; do
  if printf '%s' "$DLT" | grep -q "$field"; then
    echo "ok   B2 audit payload carries $field"
  else
    fail "B2 audit payload missing $field (Activity cannot answer 'who put this here')"
  fi
done

# C
if grep -q 'can_create_commissary_delivery' "$H"; then
  echo "ok   C can_create_commissary_delivery view key present"
else
  fail "C can_create_commissary_delivery view key missing"
fi

# C-guard: the reopen-shift flag must NOT have been widened to production_in_charge.
if grep -q "canViewProductionManagement = in_array(\$role, \['admin', 'supervisor', 'production_in_charge'\]" "$H"; then
  fail "C-guard can_view_production_management was widened (grants Reopen Shift)"
else
  echo "ok   C-guard can_view_production_management not widened"
fi

exit "$rc"
