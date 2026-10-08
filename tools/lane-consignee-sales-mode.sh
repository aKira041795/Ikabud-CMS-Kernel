#!/usr/bin/env bash
#
# Lane: consignee-sales-mode — the admin toggle between "order" and "consignment".
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-sales-mode.contract.md
#
# Owner, 2026-10-08: "a toggle at admin settings to set either all items considered sold (as an order
# from consignee) or a consignee - bakeshop arrangement (account for sold pieces only)".
#
# WHY: the client has not confirmed whether a consignee owes for EVERYTHING received or ONLY what they
# sold. That one question decides wholesale-vs-consignment and everything downstream follows from it.
# The owner wants both behaviours selectable while the answer is pending.
#
# THE TWO WAYS THIS GOES WRONG (both pinned in the gate):
#   1. the toggle becomes a SECOND POSTING PATH and the books fork -> the ledger must be byte-identical
#      in both modes; it is a LENS on existing facts, never a writer.
#   2. in consignment mode the sheet implies the consignee owes NOTHING, when the truth is "sold pieces
#      are not recorded yet". A bare 0 is a false statement; say what is actually true.
#
# DEFAULT MUST BE 'consignment'. Never assert by default that goods are sold and money is owed.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the consignee sales-mode toggle in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-sales-mode.contract.md FIRST - it is the authority, and it states the two modes as
BEHAVIOUR (not as labels) plus the prohibited list.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## WHAT THE MODES MEAN - do not implement this as a cosmetic switch
  'order'       the consignee is treated as having BOUGHT everything sent, so the whole dispatched
                quantity is sold and the consignee is a debtor for it.
  'consignment' goods are placed in the consignee's custody; the consignee owes only for what they
                SELL. Only reported sold pieces count.

A switch that changes a label but not what the numbers MEAN is worse than no switch, because it looks
like it does something. Make the two modes genuinely different in what the operator is shown and led
to expect.

## DEFAULT
Default MUST be 'consignment'. Claiming goods are sold (and implying money is owed) when the
arrangement may be consignment would be a false statement, and consignment is today's behaviour so an
upgrade changes nothing until someone deliberately chooses.

## THE HONESTY REQUIREMENT THAT MATTERS MOST
In 'consignment' mode there is NO source of sold pieces in the system yet. The consignee sheet must
say that plainly. It must NOT present an amount for collection, and it must NOT suggest the consignee
owes nothing. "No sold pieces recorded yet" is true; a bare 0 is not. In 'order' mode, a
sold/for-collection figure must be derived from the dispatched quantity and the ALREADY-STORED
price_snapshot so it is traceable to real rows, never recomputed from a live price.

## EXISTING MECHANISM - REUSE IT
- dlSettingsDefaults() holds defaults; dlModuleSettings() merges defaults with getModuleSettings
  ('daily-ledger') and is cached; dlPersistModuleSettings() persists AND VERIFIES BY READ-BACK
  (returns false on mismatch). Use these; do not invent a settings path.
- The settings page is /daily-ledger/admin/settings -> handleAdminSettings, template
  templates/modules/daily-ledger/admin/settings.disyl.
- The consignee sheet is the Consignees tab of admin/commissary.disyl (~1738-1775), table
  #consignee-production-ledger-table, columns Consignee | Product | BEG | ADDTL | WITHDRAWALS | ENDING.
  Its rows come from `consignee_sheet_rows`, supplied by handleAdminCommissary.

## WORK
R1 add `consignee_sales_mode` to dlSettingsDefaults() defaulting to 'consignment'; accept ONLY
   'consignment' | 'order' and coerce anything else to 'consignment' - never to 'order'.
R2 add the toggle to the admin settings page with BOTH options and a one-line plain-language
   explanation of each. It must round-trip: save, reload, reflect the saved value.
R3 the consignee sheet presents the mode truthfully: a VISIBLE banner naming the active mode (the same
   numbers mean different things per mode, so the operator must not be able to misread them), the
   'order' sold/for-collection presentation, and the 'consignment' "sold pieces not recorded yet"
   statement with NO money figure.
R4 the toggle changes NOTHING about posting. Ledger rows, the dispatch credit, verification and the
   audit trail must be byte-identical in both modes. The mode is a lens, never a writer.
R5 no regression: the branch sheet, the existing consignee custody columns, and every existing setting
   behave exactly as today.

## PROHIBITED
- do NOT write, adjust or reverse any ledger row in either mode
- do NOT default to 'order'
- do NOT present an amount owed in consignment mode, and do NOT render a bare 0 where the truth is
  "not recorded yet"
- do NOT add a sold-pieces capture form - that is a separate client decision not yet made. This slice
  makes EXISTING facts interpretable and nothing more
- do NOT fork dl_applyConsigneeLedgerDelta or add a mode branch to the dispatch path
- do NOT weaken, skip or delete an existing test; no new dependencies
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE

## ORACLE (required)
Extend tests/daily-ledger/ with cases asserting OUTCOMES:
  - the setting exists and defaults to 'consignment'
  - it round-trips through dlPersistModuleSettings
  - a garbage value coerces to 'consignment', never to 'order'
  - the sheet presents the mode and, in consignment mode, presents NO amount for collection
  - in order mode a for-collection figure is shown and is traceable to the stored price_snapshot
  - THE PIN THAT MATTERS: the consignee ledger and effects are byte-identical in both modes
  - a regression pin on the branch sheet
Label each case discriminating or pin and say what each pin defends. Restore any setting you change and
assert you restored it - the suite runs against a live tenant. Clean up fixture rows.
Run fixture-driving handler calls in a CHILD PROCESS: $ctx->json() exits the process, so an in-process
call silently false-passes (measured on the slice-2 gate, rc=0 with no assertions run).

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log
- daily_ledger_manifest_test.php 125/125 and daily_ledger_routes_test.php 82/82 stay green
- no regression in daily_ledger_consignee_admin_test.php 11/11, daily_ledger_consignee_activity_test.php
  9/9, daily_ledger_consignee_isolation_test.php 9/9, b2b 14/14 and 10/10
- KNOWN PRE-EXISTING, NOT YOURS: daily_ledger_consignee_audit_test.php drops its F pin (branch
  production sheet render) because storage/cache is www-data-owned and the CLI cannot compile the
  template - the page renders correctly live. Do NOT try to fix that; do NOT call it a regression you
  caused, and do NOT rely on it. Eight further suites are red with counts identical at HEAD, and
  daily_ledger_handlers_test.php aborts resolving the base db. Do not fix them; do not use them to
  excuse a failure you introduce.
- ENVIRONMENT TRAPS you must respect when writing tests/probes:
    * information_schema is FORBIDDEN under modulePushContext ("Access to system schema
      'information_schema' is forbidden for modules"). It throws, and a try/catch turns that into a
      SILENT FALSE. Use SHOW COLUMNS.
    * DiSyL ESCAPES rendered output, so a value reaches the screen as {&quot;x&quot;:1}. HTML-decode
      before scanning markup for JSON.
    * DiSyL TRAP: a JS object literal in a <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
      followed by an identifier AND the braces contain a ? : ternary - that shipped JSON.stringify(0)
      and an HTTP 500 in slice 1.
- Report BLOCKED with a precise reason rather than improvising around the contract.
- Report status PASS | PARTIAL | BLOCKED; files changed; per-case discrimination; the rendered
  before/after for BOTH modes; the byte-identical evidence; anywhere the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-sales-mode
rc=$?
echo "lane: consignee-sales-mode — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
