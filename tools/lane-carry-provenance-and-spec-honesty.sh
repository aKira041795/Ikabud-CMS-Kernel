#!/usr/bin/env bash
#
# Lane: carry-provenance-and-spec-honesty
#
# Four bounded items, all serving "clarity for admin audit/accounting". The owner confirmed
# (2026-10-05) that ordinary next-day entry is the REGULAR path and that carry-over is a deliberate
# tool for a day with a problem, and that enhancements are welcome when they sharpen audit clarity.
#
# 1. AUDIT GAP (verified by measurement): the two carry paths are not equally auditable.
#      production : POST /api/v1/commissary/carry-beginnings -> dl_carryCommissaryBeginnings()
#                   per-row audit carries source:'carry_forward' + a batch
#                   'carry_commissary_beginnings' row with an idempotency key
#      cashier    : window.dlCarryBeginningsFromPreviousShift() builds
#                   dlPendingCarryForward() => [{product_id, beg_bal}] and posts it to the NORMAL
#                   /api/v1/cashier/ledger/save-batch
#                   => in the audit trail a CARRIED beginning is indistinguishable from one TYPED.
# 2. Sol finding C: the next-day spec can pass via the seed's deliberate-reopen exemption rather
#    than proving ordinary entry. The owner confirmed ordinary entry is the regular path.
# 3. Sol finding D: nextday-entry logs the restore response but never asserts it persisted.
# 4. Sol finding E: the C1 list guard checks source text for `end_source`, not the rendered label.
#
# Items 2-4 are guards. A guard that cannot fail is worse than none - this batch already shipped
# three of them.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing four bounded items in the Daily Ledger. Items 2-4 are about making GUARDS HONEST;
item 1 is a real audit-trail gap.

================================================================================
ITEM 1 - a carried beginning must be identifiable as carried (cashier side)
================================================================================

MEASURED, not inferred:
  - The PRODUCTION carry is properly provenance-tagged. POST
    /daily-ledger/api/v1/commissary/carry-beginnings -> dl_carryCommissaryBeginnings()
    (modules/daily-ledger/handlers.php ~L2785) writes per-row audit
    'save_commissary_product_beg' with source:'carry_forward', and a batch
    'carry_commissary_beginnings' row carrying the idempotency key.
  - The CASHIER carry is not. templates/modules/daily-ledger/cashier/ledger.disyl
    ~L1161 dlPendingCarryForward() maps candidates to `{ product_id, beg_bal }` and
    dlCarryForwardBeginnings() (~L1242) posts that to the ORDINARY
    /api/v1/cashier/ledger/save-batch with NO source marker.

CONSEQUENCE: for a cashier row, an auditor cannot tell a CARRIED beginning from one TYPED by hand.

WHAT TO DO
  Make a cashier carry distinguishable in the audit trail, WITHOUT changing what is stored in the
  ledger and WITHOUT changing any amount. Preferred shape: the cashier carry posts through a path
  that records its provenance the way the production carry already does, so the audit trail can
  answer "carried or keyed?" for both sheets.
  Constraints:
    - The resulting beg_bal values must be IDENTICAL to what the current carry produces. This is a
      provenance change, not an arithmetic one.
    - Keep the existing affordance behaviour: the button hides when there is nothing to carry, and
      it must keep distinguishing "nothing to carry" from "this day/shift refuses writes".
    - Keep the refusal messages and the day-closed / shift-finalized / read-only guards.
    - The production batch audit currently stores `rows: <count>` rather than the product ids
      (handlers.php ~L2870). An auditor reading that one entry cannot see WHICH products moved.
      Include the product ids (or a bounded list) so the batch entry is self-describing, and keep
      the count.
    - Idempotency must survive: a replayed carry must not double-record.

================================================================================
ITEM 2 - the next-day spec must prove ORDINARY entry (Sol finding C)
================================================================================

tests/browser/daily-ledger-nextday-entry.spec.js is titled "an unfinalized previous day flags and
notifies the operator but does NOT hamper the next day". But database/seeds/browser_environment.php
marks TODAY deliberately reopened, which activates dl_deliberateReopenUnlocksEntryEdit(). So the
test can pass BECAUSE OF that exemption rather than proving that an ordinary open day stays
enterable while yesterday is pending.

THE OWNER HAS CONFIRMED: "the ordinary next day entry is the regular path. Carry over data is a tool
... to use when there's an issue the previous day." So the ordinary path is what must be proven.

WHAT TO DO
  Make the spec prove ordinary entry: an open next day, NOT deliberately reopened, must still accept
  entry while the prior day is unfinalized and flagged.
  If the fixture currently needs reopened_at for the spec's OWN restore to be accepted, the fix is
  to change how the spec leaves the data (for example restore before the exemption is needed, or
  assert without needing to write back) - do NOT weaken the assertion and do NOT re-pin anything.
  Prove it by mutation: the spec must FAIL if ordinary entry is blocked while a reopened day stays
  editable. Report the mutation you used and the failure you observed.

================================================================================
ITEM 3 - assert the restore actually persisted (Sol finding D)
================================================================================

daily-ledger-nextday-entry.spec.js (~L149-157) logs the restore response but never asserts it, and
never reloads to confirm the value came back.
Mutation that must now fail: make the restore return 403, or return 200 without persisting.
Assert the restore's success AND reload to confirm the restored value. Do not leave the run
mutating data.

================================================================================
ITEM 4 - the C1 list guard must check the RENDERED label (Sol finding E)
================================================================================

tests/daily-ledger/daily_ledger_c1_derived_ending_test.php (~L126-135) asserts the sales LIST query
SOURCE CONTAINS 'end_source'. Mutation that passes it while C1 is blind: `SELECT NULL AS end_source`.
Make the guard observe the OUTCOME: a derived, unverified ending on a finalized shift must render
the provisional label (and not display money) on the list path, not merely be present in the SQL
text. If observing the list path end-to-end is impractical, say so and propose the closest honest
outcome check rather than keeping a source grep.

================================================================================
CONSTRAINTS
================================================================================
  - No change to any amount, total, bucket, predicate or ladder. reporting_test 76/76 pins the
    bucket split and must stay 76/76.
  - Do NOT re-introduce any clock pinning. The tenant clock must remain Asia/Manila / 23:59 and
    verification must show it unchanged across a seed run:
        php tools/read-dl-clock.php
  - APP_URL=http://baronledger.test IS REQUIRED for playwright.
  - Do NOT weaken or delete an assertion to reach green. If you believe one is wrong, say so with
    evidence and leave it alone.

ACCEPTANCE
  APP_URL=http://baronledger.test npx playwright test \
    tests/browser/daily-ledger-reopen-edit.spec.js tests/browser/daily-ledger-nextday-entry.spec.js \
    tests/browser/daily-ledger-close-failure-guidance.spec.js tests/browser/daily-ledger-settled-endings.spec.js \
    tests/browser/daily-ledger-prior-ledger-link.spec.js tests/browser/daily-ledger-sales-pending-marker.spec.js \
    --reporter=line                                            -> 9 passed
  php tests/daily-ledger/daily_ledger_c1_derived_ending_test.php   -> 7/7
  php tests/daily-ledger/daily_ledger_pending_not_monetised_test.php   -> 15/15
  php tests/daily-ledger/daily_ledger_sales_pending_marker_test.php    -> 21/21
  php tests/daily-ledger/daily_ledger_reporting_test.php               -> 76/76
  php tests/daily-ledger/daily_ledger_production_controls_test.php     -> 70/70
  php tests/daily-ledger/daily_ledger_handlers_test.php                -> 229/229
  php tests/daily-ledger/daily_ledger_overview_test.php                -> 104/104
  php tests/daily-ledger/daily_ledger_routes_test.php                  -> 82/82
  php ikabud module:validate daily-ledger
  clock unchanged across a seed run (before == after)

Check BOTH logs and report what you found.

REPORT COMPACTLY:
  status: PASS | FAIL | BLOCKED
  changed:
  ITEM 1: how a cashier carry is now identifiable in the audit trail, with the audit action/payload,
          and confirmation the beg_bal values are unchanged
  ITEM 2: the mutation you used and the failure it produced
  ITEM 3: what the restore assertion now proves
  ITEM 4: what the guard observes now (outcome, not source text)
  verification: exact commands and numbers
  logs:
  risks / unresolved:

BLOCKED is correct if item 1 cannot add provenance without changing a stored value, or if the
ordinary-entry proof is impossible without a product change.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/carry-provenance-and-spec-honesty
rc=$?
echo "lane: carry-provenance-and-spec-honesty — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
