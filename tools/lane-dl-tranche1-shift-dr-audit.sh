#!/usr/bin/env bash
#
# Lane: dl-tranche1-shift-dr-audit
#
# Three settled defects from the nine client concerns (2026-11-09). Design decided by the chair;
# this lane executes it. No architectural judgement remains — do not re-open the design.
#
# Contract: .ai/daily-ledger-nine-concerns-2026-11-09.contract.md  (READ IT FIRST)
#
# MEASURED BY THE CHAIR in modules/daily-ledger/handlers.php:
#   - apiFinalizePmShift (L12402) hardcodes 'PM' at FOUR points: dl_lockShiftStatusRow (12459),
#     dl_shiftMissingEndings (12469), the sales-recompute loop (12487), the finalize UPDATE (12498).
#     A single-shift branch writes ONLY 'AM' rows (dl_resolveLedgerShift, 2652), so
#     dl_shiftMissingEndings(...,'PM') reports every active product missing -> 422 PM_ENDING_MISSING.
#   - apiCloseDay (12592) requires a finalized PM shift whenever dl_isFullyManualDay() is true, and
#     that helper returns TRUE whenever POS is disabled -> a single-shift branch can never close its
#     day. Two independent blockers for one client symptom.
#   - can_view_production_management is set at 22267 as admin|supervisor ONLY, but it is ALSO the
#     gate for "Reopen Shift". The New Delivery (DR) button is gated on it at
#     templates/modules/daily-ledger/admin/commissary.disyl:1817, while apiCommissaryDispatch (24173)
#     ALREADY permits production_in_charge. Client (a commissary user) cannot see the button that the
#     server would accept.
#   - dl_applyLedgerDelta (3314) writes the ledger row and NO dl_audit_log entry. Production output,
#     receiving, office corrections and variance resolution all route through it, so "who entered
#     this" survives only as updated_by. That is why the client says
#     "sa activity dili nako makita kong kinsay ng entry".

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing three specific, already-diagnosed defects in the Daily Ledger module at
/var/www/html/applicationostest.

READ FIRST: `.ai/daily-ledger-nine-concerns-2026-11-09.contract.md` — it contains the verified
file:line evidence and the prohibitions. Do not re-diagnose; the diagnosis is measured.

Work on the tree as it stands. Do NOT `git checkout`, revert, or touch any file outside the scope
listed per task.

=====================================================================
TASK A — single-shift branches cannot finalize or close (client concern 9)
=====================================================================
Symptom reported by the client: they created single-shift accounts for Manukan and Sindangan
Branch 2, and "walay makita nga closed shift para mafinal ug macompute ang ilang sales".

Two INDEPENDENT blockers, both must be fixed:

A1. `apiFinalizePmShift()` (handlers.php ~L12402) hardcodes the shift as 'PM' in four places.
    Resolve the branch's DAY-ENDING shift instead:
        $endShift = dl_isLastShiftOfDay('PM', $branchId) ? 'PM'
                  : (dl_isLastShiftOfDay('AM', $branchId) ? 'AM' : 'PM');
    ...and use `$endShift` for:
      - dl_lockShiftStatusRow
      - dl_shiftMissingEndings
      - the sales-recompute SELECT (the `shift = 'PM'` literal in its SQL)
      - the finalize UPDATE (the `shift = 'PM'` literal in its SQL)
      - the audit row's 'shift' field and the shift-status key string
    Keep the route, the function name, and the URL unchanged — `routes.php`,
    `workbench-contract.json` and three templates reference them.
    Do NOT rename the function. A rename breaks the route contract test.

A2. `apiCloseDay()` (handlers.php ~L12592) requires a finalized 'PM' shift when
    dl_isFullyManualDay() is true. Require the DAY-ENDING shift instead, using the same resolver.
    Do not otherwise change dl_isFullyManualDay().

A3. The cashier sheet control. `templates/modules/daily-ledger/cashier/ledger.disyl` (~L169) shows
    a "Close PM Shift" button only in the PM context. On a single-shift branch the operator must
    still get a working close control, labelled honestly for what it does on that branch.
    Read the template around L160-L200 before editing. Follow
    `.github/instructions/ui-ux-conventions.instructions.md`: honest labels, action beside the work.
    Do NOT invent a new colour token; use the existing btn classes.

A4. Two-shift branches must be BYTE-IDENTICAL in behaviour. dl_isLastShiftOfDay('PM', ...) returns
    true unconditionally, so the resolver must yield 'PM' for them. ASSERT this in a test; do not
    assume it.

=====================================================================
TASK B — commissary users cannot see "New Delivery (DR)" (client concern 4)
=====================================================================
The server ALREADY permits the role: apiCommissaryDispatch (~L24173) lists
['admin','supervisor','production_in_charge']. Only the UI gate excludes them.

B1. Add a DISTINCT view-payload key `can_create_commissary_delivery` =
    in_array($role, ['admin','supervisor','production_in_charge'], true), next to the existing
    `can_view_production_management` assignment (~L23225).
B2. Gate the "New Delivery (DR)" button (admin/commissary.disyl ~L1817) on the NEW key.
B3. **DO NOT widen `can_view_production_management`.** It is also the gate for "Reopen Shift"
    (~L1809). Widening it would silently grant production_in_charge a reopen right it does not
    have. This is the single most important constraint in Task B.
B4. Review the Deliveries nav gate (admin/commissary.disyl ~L1153, `feature_formal_delivery &&
    (admin || supervisor)`) and align it with `can_create_delivery_docs` if and only if that is
    consistent — report what you found and what you chose. If `can_create_delivery_docs` is not
    already true for production_in_charge, leave the nav alone and say so.

=====================================================================
TASK C — ledger movements are not audited (client concern 1, first half)
=====================================================================
Client: "sa activity dili nako makita kong kinsay ng entry/ngbutang sa mga additional".

C1. `dl_applyLedgerDelta()` (handlers.php ~L3314) must write an audit row for every mutation it
    performs — both the INSERT branch and the UPDATE branch.
C2. Mirror the existing convention. `apiReceiveDelivery` and the three receive paths already call
    `dl_auditLog(...)` inside their transactions; use the same signature and style. Read one first.
C3. The audit row must let an operator answer "who put this additional here": include branch_id,
    product_id, ledger_date, shift, column ('addtl'|'withdraw'), delta, value_before, value_after,
    actor_id. Use an action name in the existing vocabulary style, e.g. 'ledger_delta_applied'.
C4. This must be a TRAIL, not a control: it must NOT change any ledger value, must NOT throw on
    audit failure in a way that turns a working write into a failed write (check how dl_auditLog
    behaves on error before relying on it), and must NOT alter `dl_requireAuditRow()` semantics on
    the paths that already enforce it.
C5. `dl_applyLedgerDelta` is called in loops (batch save, receiving per item). One audit row per
    call is correct and intended — do not de-duplicate or batch them.

=====================================================================
HARD CONSTRAINTS — violating any of these fails the task
=====================================================================
1. NO new migration. NO schema change.
2. Do NOT change `$shouldAutoCreateFormalDelivery` or the posting decision at handlers.php ~L5932.
   That is a separate, later tranche with an owner decision attached. Touching it here fails the task.
3. Do NOT edit historical rows.
4. Do NOT widen `can_view_production_management` (see B3).
5. Do NOT weaken or delete an existing test to reach PASS. If an existing test contradicts your
   change, report PARTIAL with the conflict instead of editing the test.
6. MySQL 5.7 compatible SQL only: no CTEs, no window functions, no JSON_TABLE. Every new/changed
   query must be justifiable on MySQL 5.7.
7. Do NOT create a new route. The route<->workbench-contract test enforces both directions.
8. DiSyL: no `{...}` inside inline HTML event handlers — DiSyL eats the braces. Use delegated
   addEventListener in a `{block scripts}` if you need JS.

=====================================================================
TESTS YOU MUST ADD
=====================================================================
Under `tests/daily-ledger/`, following the existing harness pattern (read
`daily_ledger_single_shift_mode_test.php` for the shape, including the snapshot-and-restore-in-finally
pattern for any branch flag you flip).

- T-A1: a single-shift branch's day-ending shift resolves to 'AM'; a two-shift branch resolves to 'PM'.
- T-A2: `apiFinalizePmShift`'s source no longer contains a hardcoded PM-only path for the four
  points in A1 (a source-level assertion is acceptable and expected here, since the endpoint needs
  an HTTP context).
- T-A3: `apiCloseDay`'s PM requirement is expressed through the day-ending resolver.
- T-A4: two-shift parity — the resolver returns 'PM' for a two-shift branch.
- T-B1: `can_create_commissary_delivery` is present in the view payload and is true for
  production_in_charge; `can_view_production_management` is still FALSE for production_in_charge.
- T-C1: calling `dl_applyLedgerDelta` writes exactly one audit row with the actor and the delta,
  and leaves the ledger value exactly equal to what it would have been without the audit.

Each test file must restore any global state it mutates. Run each twice to show determinism.

=====================================================================
VERIFICATION YOU MUST RUN AND REPORT
=====================================================================
- `php -l` on every changed PHP file.
- Your new tests: report RED on base, GREEN after. Actually demonstrate the RED by reasoning about
  the base source, since you are editing in place.
- `php tests/daily-ledger/daily_ledger_single_shift_mode_test.php` — must stay green.
- `php tests/daily-ledger/daily_ledger_shift_target_test.php` and
  `php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php` if they run in your environment.
- Check BOTH `storage/logs/app.log` AND `storage/logs/error.log` after every run.
- Note any pre-existing failures explicitly as pre-existing, with evidence.

=====================================================================
REPORT
=====================================================================
status (PASS / FAIL / PARTIAL / BLOCKED), the exact files changed, per-task evidence,
test pass counts, what you could NOT verify, and any place where the contract was ambiguous.
If you cannot satisfy an item without breaking a hard constraint, report BLOCKED with the precise
reason. Never reach PASS by weakening a guard, a test, or an authorization check.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/dl-tranche1
rc=$?
echo "lane: dl-tranche1-shift-dr-audit — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
