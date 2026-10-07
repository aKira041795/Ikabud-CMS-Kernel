#!/usr/bin/env bash
#
# Lane: b2b-receiving-shift-declaration
#
# Owner spec 2026-10-07: "default is same shift receiving as that of dispatch. Say, AM shift dispatch
# => same AM shift receiving BUT dispatch can select if user wants the PM shift receiving will be."
#
# CONTRACT (AUTHORITATIVE): .ai/b2b-receiving-shift-declaration.contract.md
#
# WHY THIS LANE EXISTS: the owner asked, looking at the Send to Branch modal, "there's no receiving
# option shift to use when choosing the branch receiving?" That is correct today — verified live: the
# dispatch modal's only fields are Date Sent, Paper DR #, Receiving Branch, and product lines, and the
# receiving selector defaults to the RECEIVER's own login shift.
#
# THE TRAP IN THIS SLICE IS CONFLATION. There are three shift facts and they are NOT the same thing:
#   dl_deliveries.production_shift          the SENDER's shift        (set at dispatch)
#   dl_deliveries.declared_receiving_shift  the SENDER's EXPECTATION  (NEW — set at dispatch)
#   dl_branch_receivings.received_shift     the RECEIVER's actual shift = the ledger row that receives
# The receiver's value is the FACT: dl_acceptFormalDelivery posts the ledger delta with the receiver's
# confirmed $shift (handlers-deliveries.php:776).

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "use SOl"). Small diff, but it sits on top of an accountability control, so the
# judgement that matters is not typing speed — it is not weakening the receiver's lock while adding
# the sender's declaration.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the b2b receiving-shift declaration slice in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/b2b-receiving-shift-declaration.contract.md FIRST. It is the authority for this lane,
including the prohibited list. Do not expand scope.

PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim
INSPECTION-ONLY.

## THE CONSTRAINT YOU MUST NOT BREAK
dl_resolveLedgerShift() (handlers.php:2176-2197) FORCES a shift-bound cashier (dl_users.shift set) to
their own shift: 'bound' => true, which renders SHIFT_LOCKED and replaces the receiving picker with a
locked badge. That is the accountability control: the receiving ledger row must belong to the person
actually receiving the stock. dl_acceptFormalDelivery posts the ledger delta with the receiver's
confirmed $shift (handlers-deliveries.php:776), so the receiver is the fact and the sender is an
expectation.

So the declaration PREFILLS and is RECORDED. It must never move stock into a shift whose cashier is
not the one receiving, and it must never block a receive. Do not touch dl_resolveLedgerShift,
SHIFT_LOCKED, received_shift semantics, the ledger debit, dl_applyLedgerDelta, or the audit shape.

## WORK

R1 — migration modules/daily-ledger/database/migrations/080_declared_receiving_shift.sql (080 is FREE;
     079 is the highest and 071 is already duplicated in history — do not renumber anything).
     Add dl_deliveries.declared_receiving_shift ENUM('AM','PM') NULL DEFAULT NULL, guarded exactly
     like 072_delivery_receipt_shifts.sql (information_schema.columns test + PREPARE), rerun-safe.
     Register it in modules/daily-ledger/module.json alongside the other migrations, in order.

R2 — dispatch modal (templates/modules/daily-ledger/cashier/dispatch_modal.disyl): add a
     "Receiving shift" AM/PM select beside Date Sent / Paper DR # / Receiving Branch. Default it to
     the DISPATCHER's own shift (window.SHIFT) and add it to the JSON payload built around line
     213-225 as receiving_shift. Word it as a declaration — it is the shift the SENDER expects to
     receive, not a receipt. Keep it optional: it must never be able to block the send.

R3 — apiCreateCashierDispatch (modules/daily-ledger/handlers.php, INSERT at ~8758-8787):
     store it. When receiving_shift is absent or not AM/PM, default it to the RESOLVED dispatch shift
     $shift — so 'the same shift as the dispatch' is the default for any caller that predates this
     field, including the offline path. Accept only the literal values AM|PM.

R4 — apiGetIncomingDeliveries (handlers.php ~8824-8964) returns declared_receiving_shift for the
     dl_deliveries-backed groups. The dl_cashier_withdrawals-backed groups have no such column ->
     emit null for them. ADDITIVE: do not rename or drop existing payload keys.

R5 — receive modal (templates/modules/daily-ledger/cashier/receive_modal.disyl): receivingShift is
     initialised at line 340 and re-initialised in openModal() at line 373. Pre-fill it from the
     group's declared_receiving_shift when present, else fall back to the receiver's own shift. It
     stays changeable. When SHIFT_LOCKED is true the lock wins unchanged — do not weaken it.

R6 — when the actual receiving shift differs from the declared one, show a visible NON-BLOCKING note
     naming both (e.g. the sender expected AM; this receipt posts to PM). Never disable Receive Now
     for this reason, and never refuse the write. This is the same accept-and-notify rule the owner
     set for the branch product revival: report the mismatch, do not block the operator.

R7 — the two values stay distinguishable and labelled by provenance on the receipt.

## ORACLE — extend the existing suite, do not start a new file
tests/daily-ledger/daily_ledger_b2b_accountability_test.php is currently 12/12 and must stay green.
Extend it. Cases must be discriminating — they must FAIL on the base tree:
  A2 (**discriminating**) a dispatch that REQUESTS a receiving shift DIFFERENT from the dispatcher's
     own stores the requested value in declared_receiving_shift WHILE production_shift keeps the
     dispatcher's own shift. On base the dispatch INSERT has no such column -> fails. Asserting BOTH
     columns in one case is the point: it is what proves the two facts were not conflated.
  A3 (**discriminating**) a dispatch that OMITS the field stores the dispatcher's resolved shift.
  If a case passes on the base tree, it is a PIN — label it a pin, do not claim discrimination.

## Rules
- Smallest correct change. Never weaken, skip or delete an assertion to reach green.
- A test that writes to storage/logs/app.log MUST restore the log to its pre-test bytes so it leaves
  no [error] residue an admin would chase.
- Run: php -l on each changed PHP file; php ikabud tenant:migrate 207 daily-ledger; then
  php tests/daily-ledger/daily_ledger_b2b_accountability_test.php and
  php tests/daily-ledger/daily_ledger_b2b_capture_context_test.php (currently 10/10, must stay green).
- Check BOTH storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).
- KNOWN, PRE-EXISTING, NOT YOURS: daily_ledger_shared_account_latest_holder_test.php fails its
  LOG-OBSERVATION assertions (its functional assertions pass); proven pre-existing by the chair with
  the handler files at 3c93ad6c. Do NOT fix it and do NOT use it to excuse a failure you introduce.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree discrimination
  per case; anything you could not verify; and anywhere this brief or the contract is wrong — report
  it rather than improvising around it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/b2b-receiving-shift-declaration
rc=$?
echo "lane: b2b-receiving-shift-declaration — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
