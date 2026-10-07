#!/usr/bin/env bash
#
# Lane: consignee-destinations (Slice 1 — the data path)
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-destinations.contract.md
# Consult that shaped it:    .ai/consult/consignee-destination-model-2026-10-08.md
#
# WHY THIS EXISTS: the client wants a branch to be able to dispatch stock to a CONSIGNEE - a third-party
# recipient supplied by a commissary (RIZAL-COMMIS), with NO user account and NO Receive Stock step.
# The owner's decided flow: "we can refer to the branches flow but the only difference is that no
# receiving of stock flow, thus it is AUTO CREDITED but verifiable by admin."
#
# THE TWO WAYS THIS GOES WRONG, and they are why the fixture matters more than the feature:
#   1. DOUBLE-CREDIT   - a retry/replay credits the consignee twice. Silent; surfaces months later.
#   2. LEAKAGE         - a consignee appears where a branch is assumed (incoming, receiving, reports).
# The chair's first instinct was "put them in dl_branches with a kind column" and "leave destination_id
# NULL so they are invisible by construction". BOTH WERE REJECTED: 68 uncentralised reads would each
# need an exclude filter, and a dedicated column removes ID COLLISIONS - it does NOT remove presentation
# leakage. Do not re-litigate either decision.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "dispatch to Sol"). Highest-risk slice of the week: a NEW ledger, a new writer, and
# a claim (isolation) that cannot be proven by inspection.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the consignee destination data path in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-destinations.contract.md FIRST - it is the authority, including the prohibited list.
Read .ai/consult/consignee-destination-model-2026-10-08.md for the reasoning behind the shape.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY. The ENUM ALTER and FK type matching are the specific 5.7 risks.

## MEASURED FACTS (verified by the chair; rely on them, but verify what you build on)
- dl_branches has NO kind discriminator; it is read DIRECTLY in 68 places with no central helper; 20
  tables hold FKs into it.            -> consignees do NOT go in dl_branches.
- dl_deliveries.destination_type is ENUM(branch, own_account, reseller, customer, event, wastage,
  internal_use, adjustment).          -> needs an ALTER to admit 'consignee'.
- dl_deliveries.destination_id is NULLABLE.   -> consignee deliveries leave it NULL.
- ALL 131 existing deliveries are destination_type='branch' - no non-branch path has EVER run.
- The cashier dispatch HARD-REFUSES non-branch today: 'Invalid destination type.' (~handlers.php:8699).
- The incoming query that feeds Receive Stock ALREADY filters destination_type='branch' AND
  destination_id (~handlers.php:4802) - verify it is complete before trusting it.
- dl_daily_ledger.branch_id is NOT NULL with an FK to dl_branches.  -> a consignee CANNOT be posted
  there; it needs its own ledger table.
- dl_applyLedgerDelta(int $branchId, int $productId, string $ledgerDate, int $delta, int $actorId,
  string $column, string $shift) is branch-keyed and CARRIES THE FINALIZED-SHIFT GUARD.
- dl_delivery_ledger_effects is the established IDEMPOTENT + REVERSIBLE effect pattern (applied/
  reversed, before/after qty, FOR UPDATE, audit-logged). Mirror it for the consignee credit.
- Commissary in tenant 207: RIZAL-COMMIS id 18, is_commissary=1.
- apiProductionDestinations returns dl_branches as {id, code, name} scoped by accessible branches.

## THE MODEL
- dl_consignees: id, code, name, assigned_commissary_id (FK dl_branches, must be is_commissary=1),
  is_active, created_at, updated_at.
- dl_deliveries: destination_type += 'consignee'; NEW nullable consignee_id INT UNSIGNED (FK
  dl_consignees - match the type EXACTLY, MySQL 5.7 rejects a width/signedness mismatch).
- dl_consignee_ledger: the consignee sheet's backing store, mirroring dl_daily_ledger MINUS the
  branch/commissary/shift-ending/POS columns.
- INVARIANT, enforced in the handler AND in the schema where 5.7 permits:
    branch    -> destination_id required, consignee_id NULL
    consignee -> destination_id NULL,     consignee_id required

## THE FLOW
A dispatch to a consignee = debit the source branch (ALREADY HAPPENS at ~handlers.php:8813) PLUS credit
the consignee ledger, IN THE SAME TRANSACTION. No receiving step, no receiver - the dispatch IS the
transfer. Mark-verified is evidence of receipt ONLY and must NOT post anything (that UI is Slice 2, but
do not build anything that would make verify-equals-credit tempting). Where the credit is not exact, the
correction is a REVERSING/ADJUSTING movement, never an edit of a posted row.

## WORK
R1 migration modules/daily-ledger/database/migrations/081_consignee_destinations.sql (081 is FREE - the
   chair verified 080 is the highest in use; check again before you write it). Guarded/idempotent using
   the 072/080 information_schema + PREPARE pattern, rerun-safe. New tables: ENGINE=InnoDB DEFAULT
   CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci. Register it in module.json.
R2 admin Branches view (templates/modules/daily-ledger/admin/branches.disyl) gets tabs
   [Branches | Consignees], with create/edit for a consignee: code, name, assigned commissary (only
   is_commissary=1 selectable), active.
R3 the dispatch modal (templates/modules/daily-ledger/cashier/dispatch_modal.disyl) lists consignees
   SEPARATELY from branches - its own labelled group - scoped to the consignees supplied by the source
   branch's commissary.
R4 apiCreateCashierDispatch accepts destination_type='consignee' with consignee_id, stores
   destination_id NULL, and credits the consignee ledger in the same transaction as the source debit.
   The credit is IDEMPOTENT (a replay/retry must not double-credit) and REVERSIBLE.
R5 the consignee credit obeys the SAME guards as every other ledger write - notably the finalized-shift
   immutability rule. PROVE THIS WITH A TEST. Do not generalise by copying the guard into a parallel
   function that can drift; either give dl_applyLedgerDelta an owner parameter or route through a helper
   that applies the identical guards.
R6 a consignee delivery NEVER appears in a branch-facing surface.
R7 apiProductionDestinations returns consignees as a SEPARATE group from branches, ADDITIVELY - the
   existing consumers must not break. Slice 2 renders the tab; Slice 1 only supplies the data.

## ORACLE - the isolation fixture is the important deliverable
Extend the daily-ledger test suite (do NOT start a new framework). Build ONE fixture in a live tenant:
an ordinary branch->branch delivery AND a branch->consignee delivery from the SAME source branch and
shift, with overlapping ids and sentinel quantities. Run both through the REAL dispatch handler. Assert:
  A. the consignee delivery is ABSENT from incoming deliveries / Receive Stock for the destination branch
  B. the consignee does NOT appear as a branch in branch-facing lists or reports
  C. the consignee IS visible and identifiable in the admin Deliveries data (so it can be verified)
  D. the source branch was debited EXACTLY ONCE and the consignee credited EXACTLY ONCE
  E. re-running the same dispatch does NOT credit a second time
  F. the finalized-shift guard still REFUSES a credit into a finalized shift (R5, proven not inspected)
  G. ALSO probe queries that do NOT filter destination_type, aggregates especially - a dedicated column
     removes id collisions, NOT presentation leakage
Label each case discriminating or pin. A case you did not run is not a guard. Clean up your fixtures
(cf. the existing suites, which remove every private-id row) - a fixture that leaks rows is a defect.

## Rules
- Smallest correct change. Do NOT weaken, skip or delete an existing test to reach green.
- Do NOT make dl_daily_ledger.branch_id nullable and do NOT post a consignee into dl_daily_ledger.
- Do NOT put consignees in dl_branches. Do NOT reuse dl_selling_accounts.
- NO consignee portal, logins, settlement, commissions or sales reconciliation in this slice.
- Run: php -l on each changed PHP file; php ikabud tenant:migrate 207 daily-ledger TWICE (proves
  idempotency); the daily-ledger b2b oracles must stay green (daily_ledger_b2b_accountability_test.php
  14/14, daily_ledger_b2b_capture_context_test.php 10/10).
- Check BOTH storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).
- KNOWN PRE-EXISTING, NOT YOURS: daily_ledger_shared_account_latest_holder_test.php fails its
  log-observation assertions (functional ones pass), proven pre-existing at 3c93ad6c. Do not fix it and
  do not use it to excuse a failure you introduce.
- If the contract is wrong or incomplete, report BLOCKED with the precise reason rather than improvising
  around it - a lane that reports BLOCKED with a real reason is doing its job.
- Report status PASS | PARTIAL | BLOCKED; files changed; migration idempotency evidence; per-case
  discrimination; the exactly-once and isolation evidence; anything unverified; and anywhere this brief
  or the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-destinations
rc=$?
echo "lane: consignee-destinations — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
