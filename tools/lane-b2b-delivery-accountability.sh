#!/usr/bin/env bash
#
# Lane: b2b-delivery-accountability
#
# OWNER REQUEST (2026-10-06, verbatim): "client also asked that branch-to-branch delivery modal, also
# uses AM and PM shift by cashier. So, once received, the cashier name of the shift active will be
# captured in the logs, delivery receipt"
# OWNER SCOPE DECISION: "yes, dispatch included"
#
# CONSULTATION: the course of action was discussed with the owner's ChatGPT first; the transcript is
# saved at .ai/consult/b2b-receipt-shift.md. Its core recommendation is adopted here:
#   "Unify accountability and presentation; do not unify the inventory workflows."
#   one semantic receive event with a path discriminator, a shared receipt VIEW MODEL fed by two
#   readers, snapshot the cashier NAME (not only the id), a short non-composite entity_id, and keep
#   received_shift a business invariant rather than a schema NOT NULL.
#
# MEASURED PREMISE + full scope: .ai/b2b-delivery-accountability-contract.md (AUTHORITATIVE).
# The single most important thing in that contract: read it BEFORE grepping for missing audit calls.
# The chair already got this wrong once by searching a line range that does not exist in the file
# (handlers-deliveries.php is 2962 lines; a 8801-9056 read is empty and looks like "no audit found").
# THREE of the four paths ALREADY audit. Only the branch-to-branch RECEIVE has no audit at all.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "use SOl"). This is design + a cross-path consistency rule with two correctness
# traps in it (printing the wrong employee on a receipt, and rolling back a stock receipt because an
# audit write threw). terra is the strong fallback; flash last.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing branch-to-branch delivery accountability in the Ikabud repo at
/var/www/html/applicationostest.
PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim
INSPECTION-ONLY.

READ FIRST, and treat it as authoritative:
  .ai/b2b-delivery-accountability-contract.md
Then, only as needed:
  .ai/consult/b2b-receipt-shift.md                     (the agreed course of action)
  docs/daily-ledger/inventory-spec.md:10               (why formal and the withdrawal shortcut stay distinct)
  docs/daily-ledger/movement-scenarios.md:325-327
  modules/daily-ledger/handlers.php                    apiReceiveDelivery (8964-9175),
                                                       apiCreateCashierDispatch (8587-8746),
                                                       apiSaveCashierWithdrawals (7760-8273)
  modules/daily-ledger/handlers-deliveries.php         dl_acceptFormalDelivery (677-804), and the
                                                       receiving-detail reader (~1484)
  templates/modules/daily-ledger/admin/deliveries.disyl     the "View Receipt" panel
  templates/modules/daily-ledger/cashier/dispatch_modal.disyl  (sends shift: window.SHIFT)

## THE TRAP THAT COST THE CHAIR A WRONG CLAIM — read before you measure anything
Do NOT look for missing audit calls by searching an assumed line range. Compute the function
boundaries (an awk pass over /^function /) or you will read an empty range in a shorter file and
conclude "no audit exists". handlers-deliveries.php is 2962 lines. This error already produced a
confidently wrong statement to the owner once in this thread; do not repeat it, and if you find the
chair's table in the contract is wrong anywhere, SAY SO in your report rather than implementing
around it silently.

## WHAT IS ALREADY CORRECT — DO NOT "FIX" THESE
  * dl_cashier_withdrawals.shift exists (migration 055) and both INSERTs write it; the dispatch modal
    already sends the shift. The DISPATCH side's shift is therefore already recorded.
  * migration 072 added received_shift to BOTH dl_branch_receivings and dl_cashier_withdrawals.
  * apiReceiveDelivery already writes received_at / received_by / received_ledger_date /
    received_shift and credits dl_daily_ledger.addtl SHIFT-SCOPED.
  * a shift-BOUND cashier is FORCED to their own shift by dl_resolveLedgerShift(); never let a
    request value override that. It is the accountability control.
  * the b2b receive already refuses a replay (its guard requires received_at IS NULL).

## C1 — snapshot the acting cashier into the audit, both ends
(a) b2b dispatch, (b) b2b receive (currently NO audit at all), (c) the formal receive (audited, but
    add the acting cashier). The audit row must answer "which cashier, which shift, which branch,
    when" WITHOUT joining back to the mutable row: snapshot the user id AND the display name as it
    read at that moment, plus the resolved shift, both branch ids, the timestamp and the line count.
    Copy the existing precedent: dl_cashier_withdrawals.liable_user_name is snapshotted at write time
    via dl_userDisplayNameById() (see handlers.php ~8215), and the read path COALESCEs the snapshot
    before the live join (handlers.php ~7697).
    Derive the cashier from the AUTHENTICATED actor. Never from a POST field.
    Keep every existing payload key and action name (additive only — other code may read them).

## C2 — a branch-to-branch transfer gets a working DELIVERY RECEIPT
The admin "View Receipt" must show, for an informal branch->branch transfer: products + received
quantities, the receiving cashier's name, the received shift, the received timestamp, and the
source/destination branches. Source it from dl_cashier_withdrawals.
  - PREFER a shared receipt VIEW MODEL (one small normalised array) produced by a formal reader and
    a branch-transfer reader, rendered by ONE template/partial, so a future receipt field cannot be
    added to one path and forgotten on the other.
  - Do NOT UNION the two persistence models into one query, and do NOT manufacture
    dl_branch_receivings/dl_deliveries rows for an informal transfer.
  - The receiver identity must come from the field representing the receiving ACTOR (received_by).
    The formal reader currently joins the displayed name through br.posted_by; both columns are set
    to the same $userId today, so this is a LATENT trap, not a present wrong-name bug. Fix the reader
    and describe it accurately.

## C3 — the receive shift is a required business invariant
A completed receive must not commit without a resolvable AM/PM shift: resolve, then refuse if
unresolved. Do NOT make the column NOT NULL. Historical NULL rows keep rendering "not captured
(historical)" and are never backfilled.

## ORACLE — required, with base-tree discrimination on every claim
Tests via the TestHarness pattern; fixtures with private ids (never real branch 8); full cleanup in
finally. For EVERY case, state whether it fails on the base tree:
  D1 (**discriminating**) a b2b RECEIVE writes an audit row carrying the receiving cashier's NAME and
      the received shift. Base: no audit row at all -> fails on base.
  D2 (**discriminating**) a b2b DISPATCH audit carries the dispatching cashier's name and the shift.
      Base: audited but with neither -> fails on base.
  D3 (**discriminating**) the receipt reader returns items + receiving cashier + received shift for a
      b2b transfer. Base: no such reader / "No receipt record found" -> fails on base.
  D4 (**discriminating**) a receive that cannot resolve a shift is REFUSED and writes nothing.
  D5 (pin) a shift-BOUND cashier receiving with a conflicting shift still lands on their own shift.
  D6 (pin) a replayed receive does not double-credit, does not overwrite the receiver/shift, and
      emits no second success audit.
  D7 (pin) the formal receive still audits, and now carries the receiving cashier.
If a case also passes on base, LABEL it a pin — do not present it as a win.

## MEASURE AND STATE — do not assume
Does dl_auditLog() participate in the caller's transaction? It has NO $db parameter (it goes through
$ctx->audit()) and it swallows every Throwable with a "// Non-fatal" comment. So today a failed audit
is INVISIBLE while the stock still credits. Determine whether module()->audit() writes on the
caller's connection, STATE the answer with evidence, and say explicitly which direction you chose if
atomicity is not achievable. Do not imply atomicity you have not shown.

## Rules
- Smallest correct change. No refactor, no new dependency.
- Additive only on audit: never rename, remove or repurpose an existing action name. audit_logs
  .entity_id is varchar(50) and a past composite id silently destroyed every carry audit row
  (SQLSTATE[22001]) — entity_id is the short source record id ONLY.
- Never weaken, skip, or delete an assertion to reach green.
- Run: php -l on each changed PHP file; php scripts/run-tests.php --dir=tests/daily-ledger; then check
  BOTH storage/logs/app.log and storage/logs/error.log (error.log must stay 0 bytes).
- The suite's known transient noise is `kernel_state_cache: module_registry rebuilt` from the runner
  deleting storage/modules.json per test. Do NOT excuse a real failure with an old "known failures"
  list — the previously-claimed pre-existing failures (dispatch_enforcement,
  preserve_cashier_variance, delivery_record_authz) PASS.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; the base-tree
  discrimination for every case; the atomicity finding; anything you could not verify; and any place
  the chair's contract table is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/b2b-delivery-accountability
rc=$?
echo "lane: b2b-delivery-accountability — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
