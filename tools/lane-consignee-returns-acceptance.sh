#!/usr/bin/env bash
# ACCEPTANCE — consignee returns/pullouts are admin-enterable and the report tells the truth.
#
# Every check is written to FAIL on the UNCHANGED tree: a criterion that already passes
# cannot discriminate the change, so "green afterwards" would prove nothing. Measured RED
# before dispatch.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TPL="$ROOT/templates/modules/daily-ledger/admin/consignee-dispatch-report.disyl"
MIG="$ROOT/modules/daily-ledger/database/migrations/088_consignee_return_effects.sql"
FAILED=0
pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; FAILED=1; }

echo "== 1. a Returns / Pullout input column, positioned AFTER Quantity =="
if grep -q 'Returns / Pullout' "$TPL"; then
    pass "the template declares a 'Returns / Pullout' column"
else
    fail "no 'Returns / Pullout' column in $TPL"
fi
# Position matters: the owner asked for it after Quantity. Compare the byte offsets of the
# two header cells rather than trusting a visual read.
Q_AT=$(grep -bo '>Quantity<' "$TPL" | head -1 | cut -d: -f1)
R_AT=$(grep -bo 'Returns / Pullout' "$TPL" | head -1 | cut -d: -f1)
if [ -n "$Q_AT" ] && [ -n "$R_AT" ] && [ "$R_AT" -gt "$Q_AT" ]; then
    pass "the Returns column follows the Quantity column"
else
    fail "the Returns column is not after Quantity (quantity@${Q_AT:-none} returns@${R_AT:-none})"
fi
# It must be an INPUT, not a label — the whole point is that an admin can enter a figure.
grep -qE '<input[^>]*type="number"' "$TPL" \
    && pass "the template has a numeric input" \
    || fail "no numeric input in the report template"

echo
echo "== 2. header parentheticals moved UNDER the title, rendered smaller =="
if grep -q 'Unit price (snapshot)' "$TPL"; then
    fail "header still inlines 'Unit price (snapshot)'"
else
    pass "'Unit price (snapshot)' is no longer one inline title"
fi
if grep -q 'Verification (DR / provenance)' "$TPL"; then
    fail "header still inlines 'Verification (DR / provenance)'"
else
    pass "'Verification (DR / provenance)' is no longer one inline title"
fi
grep -q '(snapshot)' "$TPL"        || fail "the '(snapshot)' qualifier was DROPPED instead of moved"
grep -q '(DR / provenance)' "$TPL" || fail "the '(DR / provenance)' qualifier was DROPPED instead of moved"
grep -n '(snapshot)' "$TPL"        | grep -qE 'text-(xs|\[1[01]px\])' || fail "the moved '(snapshot)' is not smaller than its title"
grep -n '(DR / provenance)' "$TPL" | grep -qE 'text-(xs|\[1[01]px\])' || fail "the moved '(DR / provenance)' is not smaller than its title"

echo
echo "== 3. migration 088 exists, widens the effect kind, and is guarded =="
if [ -f "$MIG" ]; then
    pass "088_consignee_return_effects.sql exists"
    grep -qi "information_schema" "$MIG" && pass "the migration is guarded (idempotent on re-run)" \
        || fail "the migration is unguarded — a second run would error"
    grep -qi "'return'" "$MIG" && pass "the migration introduces the 'return' effect kind" \
        || fail "the migration does not mention a 'return' effect kind"
else
    fail "088_consignee_return_effects.sql is missing"
fi
grep -q '088_consignee_return_effects' "$ROOT/modules/daily-ledger/module.json" \
    && pass "the migration is registered in module.json" \
    || fail "the migration is NOT registered in module.json — it would never run"

echo
echo "== 4. recording a return reduces custody and the report NETS it =="
php "$ROOT/tools/lane-consignee-returns-probe.php" || FAILED=1

echo
echo "== 5. BROWSER: the column renders, sits after Quantity, and a return persists =="
# The evidence for this criterion is a RENDERED page, not a source grep: the column must be in
# the page the admin actually sees, and a value typed into it must survive a reload and move the
# reported value. The spec restores the return to 0 before it finishes.
( cd "$ROOT" && APP_URL=http://baronledger.test npx playwright test \
    tests/browser/consignee-returns-input.spec.js --reporter=line ) || FAILED=1

echo
echo "== 6. the surface is named 'Consignee Dispatch Ledger' and calls a return SPOILAGE =="
# Owner direction (contract ADDENDUM): the ledger is the PROOF of deliveries AND pullouts, so it
# is named for both. The ROUTE must NOT move — it is referenced by workbench-contract.json and
# linked from commissary.disyl, variances.disyl and the sidebar.
LP="$ROOT/templates/modules/daily-ledger/layouts/app.disyl"
grep -q 'Consignee Dispatch Ledger' "$TPL" \
    && pass "the report page names the surface 'Consignee Dispatch Ledger'" \
    || fail "the report page is not named 'Consignee Dispatch Ledger'"
grep -q 'Consignee Dispatch Ledger' "$LP" \
    && pass "the sidebar names the surface 'Consignee Dispatch Ledger'" \
    || fail "the sidebar does not name the surface 'Consignee Dispatch Ledger'"
grep -qi 'spoilage' "$TPL" \
    && pass "the report frames a return as spoilage (written off)" \
    || fail "the report does not frame a return as spoilage"
grep -q '/admin/consignee-dispatch-report' "$TPL" \
    && grep -q 'consignee-dispatch-report' "$LP" \
    && pass "the route path is unchanged (other templates still link it)" \
    || fail "the route path moved — commissary/variances/sidebar links would break"

echo
if [ "$FAILED" -eq 0 ]; then echo "ACCEPTANCE: PASS"; else echo "ACCEPTANCE: FAIL"; fi
exit "$FAILED"
