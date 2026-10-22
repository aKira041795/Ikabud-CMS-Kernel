#!/usr/bin/env bash
#
# Lane: daily-ledger-paper-dr-branch
#
# Client request 1 (2026-10-22): "No paper DR, can be turned off/on per branch;
# admin + supervisor always keep it."
#
# Contract: .ai/daily-ledger-client-batch-2026-10-22.contract.md
#
# MEASURED BASELINE on this tree - acceptance FAILS for the right reasons:
#   php tests/daily-ledger/daily_ledger_paper_dr_branch_test.php
#     -> 0/8 passed, exit 1
#        dl_branches.paper_dr_enabled missing
#        dl_branchPaperDrEnabled() / dl_canUsePaperDr() missing
#        all four cashier paper-DR endpoints ungated
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# OWNER DIRECTIVE: Sol is available and at our disposal for these edits.
# Chain keeps DeepSeek + terra for fallback depth so one exhausted provider cannot stall.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing ONE bounded, additive change in the Ikabud Daily Ledger module.

STEP 1 - read the contract and follow it exactly:
  /var/www/html/applicationostest/.ai/daily-ledger-client-batch-2026-10-22.contract.md
Read the "LOCKED DECISIONS" table, the "implementation seam" block and REQUEST 1. Do not implement
requests 2 or 3; they are later lanes.

THE ACCEPTANCE TEST ALREADY EXISTS AND IS THE SPECIFICATION. It MUST NOT BE MODIFIED:
  tests/daily-ledger/daily_ledger_paper_dr_branch_test.php
It currently fails with 0 executed assertions passing. Your job is to make it
14 passed / 0 failed without touching it (the base run reports only 8 executed because the 6 behaviour
assertions are skipped until the column and seams exist - 8 is NOT the target). If you believe an
assertion is wrong, STOP and report BLOCKED with the reason - do not edit the test.

WHAT TO BUILD

1. MIGRATION - modules/daily-ledger/database/migrations/094_branch_paper_dr_and_shift_mode.sql
   Add `paper_dr_enabled TINYINT(1) NOT NULL DEFAULT 1` to dl_branches.
   - Idempotent and MySQL 5.7-safe: follow the pattern in 070_production_daily_sheet_shift.sql
     (information_schema-guarded ALTER via prepared statement). Re-running must be a no-op.
   - Do NOT change the table ENGINE/CHARSET (the table already exists).
   - Include the `single_shift_mode TINYINT(1) NOT NULL DEFAULT 0` column in the SAME file now
     (request 3 will use it later; adding it now is additive and inert). Do not implement behaviour
     for it in this lane.
   Register the file in the `migrations` array in modules/daily-ledger/module.json, in numeric order.

2. SEAM - modules/daily-ledger/handlers-deliveries.php
   function dl_branchPaperDrEnabled(int $branchId): bool
     - reads dl_branches.paper_dr_enabled for that branch
     - returns TRUE when the column/row/connection cannot be read (FAIL OPEN: the switch is additive,
       so an unreadable flag must never block paper DR that works today)
     - DO NOT MEMOISE THE RESULT (contract ruling R4). The acceptance test flips the flag ON -> OFF
       inside ONE PHP request and expects the next call to observe the change, so a static cache makes
       the criterion impossible to satisfy. A single primary-key lookup per call is fine and is called
       at most once per endpoint.
     - NEVER let a query failure throw - catch Throwable and return TRUE
   function dl_canUsePaperDr(array $user, int $branchId): bool
     - role in ('admin','supervisor') -> TRUE always (owner decision 2)
     - otherwise -> dl_branchPaperDrEnabled($branchId)
   Both functions live inside the existing `if (!function_exists(...))` style guard used in this file.

3. ENFORCEMENT - the four cashier paper-DR endpoints, SPLIT ACROSS TWO FILES:
     modules/daily-ledger/handlers.php:
       apiCreateCashierDispatch      (create posted dispatch from paper DR)
       apiReceivePaperDelivery       (capture + receive from paper DR)
     modules/daily-ledger/handlers-deliveries.php:
       apiGetDeliveryByDrForEdit     (edit-by-DR prefetch)
       apiEditDeliveryByDr           (edit existing delivery by DR)
   Each MUST call dl_canUsePaperDr($user, $branchId) and, when FALSE, return 403 through the endpoint's
   own response helper, e.g.:
     $ctx->json(['ok' => false, 'error' => 'Paper DR is disabled for this branch.', 'code' => 'PAPER_DR_DISABLED'], 403);
   Rules:
   - Server-side only. Hiding UI is NOT enforcement.
   - $branchId must be the branch the request actually acts on, resolved server-side (never a
     client-supplied branch id you did not validate).
   - Refuse BEFORE any write. A blocked request must not change any row.
   - Do not change the success path, response shapes, or audit behaviour of these endpoints.

4. BRANCH ADMIN UI (additive)
   - templates/modules/daily-ledger/admin/branches.disyl: add a "Paper DR" checkbox per branch,
     bound to the branch's existing save flow, defaulting to checked.
   - The branch save path (modules/daily-ledger/helpers.php or the handler that persists branch rows)
     persists paper_dr_enabled (0/1). Validate as bool; never trust raw input. If that path does not
     currently update dl_branches, add the column to its UPDATE set only - do not restructure it.
   - Keep the existing layout/classes consistent with that template; no new design system.

5. OFFLINE PAYLOAD
   - modules/daily-ledger/handlers-offline.php: include the cashier branch's paper_dr_enabled (as a
     bool) in the bootstrap payload so an offline client cannot offer a blocked action. Follow the
     existing 'formal_delivery_enabled' precedent.
   - modules/daily-ledger/helpers.php: add the same default (true) to
     dlNormalizeCashierLedgerRenderContext so render contexts stay complete.

CONSTRAINTS (non-negotiable)
- ADDITIVE: every default preserves today's behaviour. No branch is affected until an admin unticks it.
- Do not touch shift semantics, ledger writes, variance/provenance logic, or request 2's commissary UI.
- Do not remove or rename any existing endpoint, function or route.
- Do not weaken any validation or permission check.
- MySQL 5.7 compatible SQL only (no CTEs, no window functions, no JSON_TABLE).
- Run `php -l` on every PHP file you change.

VERIFY (report the actual commands and their output)
#   a) php tests/daily-ledger/daily_ledger_paper_dr_branch_test.php        # must be 14 passed / 0 failed, exit 0
#      NOTE: the base run reports only 8 executed assertions because the 6 behaviour assertions are
#      skipped until the column and seams exist. 8 executed on the base is NOT the target number.
  b) apply the migration to the local tenant, then run it a SECOND time  # must be a clean no-op
  c) php -l on every changed PHP file
  d) REGRESSION - these must all still pass, unchanged:
       php tests/daily-ledger/daily_ledger_official_start_test.php
       php tests/daily-ledger/daily_ledger_b2b_accountability_test.php
     plus any existing test that touches deliveries or paper DR that you can find
     (grep -l "paper" tests/daily-ledger/*.php) - report each exit code.
  e) Check BOTH storage/logs/app.log and storage/logs/error.log after the runs; report any new error.

Report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:  (commands + real output)
  logs:
  risks / unresolved:

If the migration cannot be applied safely to the local tenant, or an existing test regresses for a
reason you cannot fix inside the allowed files, STOP and report BLOCKED. Never edit a test to get green.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/daily-ledger-paper-dr-branch
rc=$?
echo "lane: daily-ledger-paper-dr-branch — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
