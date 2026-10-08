#!/usr/bin/env bash
#
# Lane: consignee-destinations-slice2 — the audit surfaces + verification.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-destinations-slice2.contract.md
#
# WHY THIS EXISTS: slice 1 created the data path and left the audit loop open. The owner's ask:
# "admin can then mark verified at Deliveries with the paper DR as evidence that the consignee indeed
# received the delivery" + "at production daily sheets, a separate tab for consignees" + "at admin
# deliveries, separate tab for consignees too".
#
# THE ONE RULE: verification is EVIDENCE. It must not post, credit, or move any quantity. The credit
# already happened at dispatch. "Mark verified == post" is the tempting implementation and it is wrong.
#
# MEASURED BEFORE DISPATCH (do not re-derive): apiReviewDeliveryProvenance is ALREADY a pure evidence
# update with zero ledger writes — reuse it. It refuses a consignee delivery only because
# dl_isPaperDrCapturedDelivery() demands remarks '[captured-from-paper-dr]' while a cashier dispatch
# carries '[cashier-dispatch]' (measured: HTTP 422). apiListDeliveries ALREADY returns consignees when
# asked. So this slice EXTENDS; it does not rebuild.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing slice 2 of the consignee feature in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-destinations-slice2.contract.md FIRST — it is the authority, including the
prohibited list and the measured-facts table. Read .ai/consignee-destinations.contract.md (slice 1)
for the model. Slice 1 is committed and working; do not disturb it.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally — label every 5.7 claim
INSPECTION-ONLY.

## THE ONE RULE
Verification is EVIDENCE ONLY. It must not post, credit, or move any quantity — the credit already
happened at dispatch. The gate pins this. Do not implement "verify == post".

## WHAT ALREADY EXISTS — REUSE IT, DO NOT REBUILD
- apiReviewDeliveryProvenance (handlers-deliveries.php:1248) is already a pure evidence update:
  sets provenance_status / provenance_reviewed_by / provenance_reviewed_at / provenance_review_note,
  action set accepted|discrepant|reopen, roles admin|supervisor|production_in_charge
  (production_in_charge may only accept), and writes dl_auditLog. ZERO ledger writes. KEEP IT SO.
- dl_deliveries.provenance_status enum('none','paper_dr_pending','accepted','discrepant') and the
  reviewed_by/_at/_note columns already exist.
- apiListDeliveries ALREADY returns consignee deliveries when asked
  (?destination_type=consignee -> HTTP 200 with destination_label). Do not write a parallel endpoint.
- dl_delivery_variance_flags already exists with receiving_id NULLABLE, which suits consignees.
- dlDeliveryProvenanceStatusMeta() / dlDeliveryProvenanceStatusOptions() in helpers.php give the
  label + badge classes. Reuse them.
- admin/branches.disyl has the Branches|Consignees tab pattern from slice 1. Mirror its visual language.

## MEASURED GAP (this is the work)
dl_isPaperDrCapturedDelivery() requires remarks === '[captured-from-paper-dr]'. A consignee dispatch
carries '[cashier-dispatch]'. So verifying a consignee returns 422 "Only captured paper-DR deliveries
can be checked here." (MEASURED). Fix that NARROWLY: scope the new eligibility to
destination_type='consignee'. A BRANCH cashier dispatch must STILL be refused by provenance — branches
have the receiving flow and widening that gate would let someone "verify" a delivery that still needs a
receiver. Write the negative test for exactly that.

## WORK (in this order)
R1 narrow provenance eligibility for consignees (contract R1). Keep the branch path unchanged.
R2 admin Deliveries: tabs [Branches | Consignees] in templates/modules/daily-ledger/admin/deliveries.disyl,
   same visual language as admin/branches.disyl. Consignee rows show origin branch, consignee code+name,
   DR number, date, shift, sent quantity, provenance state, and the verify control. Note the file already
   has a review-provenance fetch (~:626) gated by is_paper_dr_exception — extend it, do not duplicate.
R3 Mark verified works from a consignee row; the row updates in place; reopen stays available to the
   same roles.
R4 production daily sheet: tabs [Branches | Consignees] in admin/commissary.disyl, consignees supplied by
   the selected commissary, read from dl_consignee_ledger, same layout language as the branch sheet
   (per-product beginning / additions / withdrawals / ending) for the same date. ADDITIVE — the branch
   sheet must render byte-identically to today.
R5 discrepancy path: record a discrepancy against the delivery in dl_delivery_variance_flags, and correct
   it with a REVERSING/ADJUSTING movement on dl_consignee_ledger via dl_applyConsigneeLedgerDelta /
   dl_reverseConsigneeDeliveryCredits, recording a dl_consignee_ledger_effects row so it stays idempotent
   and reversible. NEVER UPDATE a posted quantity in place. A discrepancy must be recordable WITHOUT
   blocking the verify.
R6 any movement caused must pass dl_assertLedgerMutationMutable so finalized shifts stay immutable.

## NOT IN THIS SLICE
Do NOT touch the Add/Edit branch type selector or the Products "Show in Consignees" tab. The owner
asked for those on 2026-10-08 and they are slice 3 (.ai/consignee-destinations-slice3.contract.md).
Mixing a destination-management change with a ledger change in one diff is exactly what this split
avoids.

## PROHIBITED
- verification must not post anything
- do not widen provenance eligibility for destination_type='branch'
- do not edit posted dl_consignee_ledger quantities in place
- do not rebuild apiListDeliveries / apiReviewDeliveryProvenance — extend them
- do not touch the slice-1 dispatch/modal path
- do not change the consignee->commissary scoping rule (the chair handles that data)
- do not weaken, skip or delete an existing test; no new dependencies

## ORACLE (required)
Extend tests/daily-ledger/daily_ledger_consignee_isolation_test.php or add a sibling in tests/daily-ledger/.
Assets over one fixture with a consignee delivery:
  - a branch cashier dispatch is STILL refused by provenance  (the R1 negative guard)
  - verify sets accepted + reviewer and leaves every dl_consignee_ledger quantity UNCHANGED
  - reopen returns it to paper_dr_pending
  - a discrepancy is recorded and corrected by a reversing movement, net unchanged
  - the branch production sheet still renders (no regression from the consignee tab)
Label each case discriminating or pin and say what each pin defends. A case you did not run is not a
guard. Clean up every fixture row you create.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log
- php ikabud tenant:migrate 207 daily-ledger TWICE if you add a migration (idempotency)
- slice-1 oracles stay green: daily_ledger_b2b_accountability_test.php 14/14,
  daily_ledger_b2b_capture_context_test.php 10/10, daily_ledger_consignee_isolation_test.php 9/9,
  daily_ledger_handlers_test.php 229/229, daily_ledger_routes_test.php 82/82
- KNOWN PRE-EXISTING, NOT YOURS: eight daily-ledger suites are red with counts identical at HEAD
  (branch_cell_entry 28/30, branch_order 10/14, defect_fixes_s13 37/49, delivery_variance_visibility
  14/20, dispatch_enforcement 12/15, finding_type_enum 23/27, preserve_cashier_variance 25/29,
  shared_account_latest_holder 22/28), and four abort in CLI on a www-data-owned compiled-template cache
  directory the CLI user cannot write. Do not fix them; do not use them to excuse a failure you introduce.
- DiSyL TRAP you must respect in any <script> you touch: a JS object literal is consumed as a DiSyL tag
  when '{' is IMMEDIATELY followed by an identifier (no space) AND the braces contain a ? : ternary.
  That shipped `JSON.stringify(0)` and a 500 in slice 1. Keep such literals multi-line, or move the
  ternary into a variable. See /memories/repo/disyl-script-ternary-brace-eaten-2026-10-08.md.
- If the contract is wrong or incomplete, report BLOCKED with the precise reason rather than improvising
  around it. A lane that reports BLOCKED with a real reason is doing its job.
- Report status PASS | PARTIAL | BLOCKED; files changed; per-case discrimination; the exactly-once and
  "verify posts nothing" evidence; anything unverified; and anywhere the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-slice2
rc=$?
echo "lane: consignee-slice2 — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
