#!/usr/bin/env bash
#
# Acceptance criterion for lane dl-tranche2-areas-remarks.
#
# Asserts OUTCOMES, never mechanisms (lesson from tranche 1, where a mechanism-locked
# criterion scored a correct implementation RED).
#
#   A   dl_areas becomes WRITABLE (today it appears only in SELECTs — no row can be created,
#       which is exactly why the admin cannot create PUREGOLD under Sindangan)
#   A2  an areas route exists AND is registered in the workbench contract (both directions
#       are enforced by daily_ledger_routes_test.php)
#   B   a delivery discrepancy carries BOTH branch remarks, not only the office review_note
#   B2  a migration supplies those columns (existing rows stay NULL)
#   G   the office-only correction guard was NOT weakened
#
# Verified RED on base 2026-11-09 before dispatch.

set -u
cd "$(dirname "$0")/../.." || exit 2

H=modules/daily-ledger/handlers.php
R=modules/daily-ledger/routes.php
W=modules/daily-ledger/workbench-contract.json
MIGDIR=modules/daily-ledger/database/migrations

[ -f "$H" ] || { echo "FAIL: $H missing"; exit 2; }

rc=0
fail() { echo "FAIL: $1"; rc=1; }

# A — areas must become writable from the application.
if grep -qE 'INSERT INTO dl_areas|UPDATE dl_areas' "$H"; then
  echo "ok   A dl_areas is written by the application"
else
  fail "A dl_areas is still read-only (no INSERT/UPDATE anywhere)"
fi

# A2 — a route exists and is registered in the contract.
if grep -q 'areas' "$R"; then
  echo "ok   A2 an areas route is declared in routes.php"
else
  fail "A2 no areas route in routes.php"
fi
if grep -q 'areas' "$W"; then
  echo "ok   A2b the areas route is registered in workbench-contract.json"
else
  fail "A2b areas route missing from workbench-contract.json (routes test enforces both directions)"
fi

# B — two-sided remarks.
for col in sender_remark receiver_remark; do
  if grep -q "$col" "$H"; then
    echo "ok   B $col is present in the handler layer"
  else
    fail "B $col missing — branches still have nowhere to record what happened"
  fi
done

# B2 — a migration supplies the columns.
if grep -rqs 'sender_remark' "$MIGDIR"; then
  echo "ok   B2 a migration adds the remark columns"
else
  fail "B2 no migration under $MIGDIR adds the remark columns"
fi

# G — office-only correction authority must survive.
if awk 'index($0,"function apiResolveDeliveryVariance")==1{p=1} p{print} p&&/^}$/{exit}' "$H" \
   | grep -q "dlCurrentUser(\['admin'\])"; then
  echo "ok   G apiResolveDeliveryVariance is still admin-only"
else
  fail "G apiResolveDeliveryVariance lost or weakened its admin-only guard"
fi

exit "$rc"
