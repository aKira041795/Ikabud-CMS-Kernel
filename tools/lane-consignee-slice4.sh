#!/usr/bin/env bash
#
# Lane: consignee-destinations-slice4 — Activity (Encoder Activity) alignment + human readability.
#
# CONTRACT (AUTHORITATIVE): .ai/consignee-destinations-slice4.contract.md
#
# THE OWNER'S HEADLINE (2026-10-08): "at activity, take note of the details and changes, it must be
# human readable and not code/json texts" and "the branch filter must be updated too with the
# consignees list".
#
# MEASURED ON THE BASE (do not re-derive): the rendered Details/Changes cells contain RAW JSON —
#   Ledger Reversal: {"status":"legacy_no_effect","reversed":0}
# 46 JSON object literals in the readable markup, 180 duplicated record labels
# ("... Deliveries #2000005955 Deliveries #2000005955"), and the Branch filter offers no consignees.
# Root cause: $formatValue (handlers.php:14774) special-cases a few array shapes and then FALLS THROUGH
# TO json_encode for anything else; the lookup maps cover products/materials/branches but NOT consignees.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing slice 4 of the consignee feature in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/consignee-destinations-slice4.contract.md FIRST - it is the authority. It contains the
owner's exact words, the measured before-state, and the prohibited list.

PHP 8.5 local. Production is MySQL 5.7 and NO 5.7 server exists locally - label every 5.7 claim
INSPECTION-ONLY.

## THE HEADLINE REQUIREMENT
Owner: "at activity, take note of the details and changes, it must be human readable and not
code/json texts".

Details and Changes must read as ENGLISH. Today they leak raw JSON:

    Details:  Status: Voided | Ledger Reversal: {"status":"legacy_no_effect","reversed":0} | Consignee Reversal: 1
    Changes:  Ledger Reversal: None -> {"status":"legacy_no_effect","reversed":0}

Root cause, measured: $formatValue (handlers.php:14774) handles a few known array shapes (items ->
"Product x qty", role permissions) and then FALLS THROUGH TO json_encode for anything else. A nested
array such as ledger_reversal therefore prints as raw JSON in the operator's face.

Fix it GENERICALLY, not key by key: render any array/object as readable "Label: value; Label: value"
prose recursively, with booleans/nulls as words not 1/0/null. Generic means the next new action does
not re-open this defect. Keep the machine-readable payload REACHABLE (e.g. a title attribute or a
collapsed disclosure) - an audit trail must not lose data, but human-readable is the DEFAULT.

## ALSO
- 180 record labels are duplicated inside one cell: "... Deliveries #2000005955 Deliveries #2000005955".
  Each label must appear once.
- The Branch filter (select name="branch_id") must ALSO list consignees, grouped like the
  Branches|Consignees optgroups already used in admin/branches.disyl and the dispatch modal, and
  selecting one must filter the list to that consignee's events and round-trip (stay selected).
  Do not keep labelling a branch-only control.
- Add consignee fields to the field-label map and a consignee lookup, so consignee_id reads as a NAME
  rather than an integer, and the payloads name the consignee.
- The consignee action family must appear in the Action filter and be findable via Search
  (consignee_created, consignee_updated, consignee_ledger_applied, consignee_ledger_reversed,
  review_delivery_provenance). VERIFY the real action names in the code rather than trusting this list.

## SCOPE DEFECT TO FIX (measured)
apiReviewDeliveryProvenance logs its audit row with
    dl_auditLog('review_delivery_provenance', (int)($delivery['destination_id'] ?? 0) ?: null, ...)
A consignee delivery has destination_id NULL, so a consignee verification is written with branch_id
NULL, which renders as "All / None" and is INVISIBLE under a branch filter - so "who verified this
consignee delivery?" cannot be answered. Scope a CONSIGNEE delivery's provenance audit to its resolved
ORIGIN branch (dl_deliveryResolvedOriginId). Do NOT change audit scoping for non-consignee rows.

## PROHIBITED
- Do NOT build a second activity view, a consignee-only feed, or a new page.
- Do NOT change audit scoping for non-consignee rows.
- Do NOT change the audit_logs schema.
- Do NOT delete the machine-readable payload to satisfy the readability requirement.
- Do NOT reintroduce a dl_branches row or a fake branch to represent a consignee.
- Do NOT weaken, skip or delete an existing test; no new dependencies.
- MySQL 5.7: no CTEs, no window functions, no JSON_TABLE; no new tables needed here.

## ORACLE (required)
Extend tests/daily-ledger/ with a suite that renders the Activity page and asserts OUTCOMES:
  - no raw JSON object literal appears in the readable markup (strip <script>/<style> first, then
    HTML-DECODE - DiSyL escapes output, so the JSON reaches the screen as {&quot;reversed&quot;:0} and a
    naive scan for '{"' is blind to it);
  - no record label is duplicated within a cell;
  - a consignee event is visible to a branch-scoped admin (the scope fix);
  - consignee ids render as names;
  - a plain branch/product row still renders (regression pin);
  - the raw payload is still reachable somewhere (so nothing was lost).
Label each case discriminating or pin and say what each pin defends. Clean up fixture rows.

## VERIFY
- php -l each changed PHP file; check BOTH storage/logs/app.log and storage/logs/error.log
- daily_ledger_admin_trace_test.php, daily_ledger_handlers_test.php, daily_ledger_routes_test.php,
  daily_ledger_consignee_isolation_test.php 9/9, daily_ledger_consignee_audit_test.php 7/7,
  b2b 14/14 and 10/10 must stay green
- KNOWN PRE-EXISTING, NOT YOURS: eight daily-ledger suites are red with counts identical at HEAD
  (branch_cell_entry, branch_order, defect_fixes_s13, delivery_variance_visibility, dispatch_enforcement,
  finding_type_enum, preserve_cashier_variance, shared_account_latest_holder); four abort in CLI on a
  www-data-owned compiled-template cache; and daily_ledger_handlers_test.php currently aborts resolving
  the BASE db (applicationostest.dl_users) - verified identical at HEAD. Do not fix them; do not use
  them to excuse a failure you introduce.
- DiSyL TRAP: a JS object literal in a <script> is consumed as a DiSyL tag when '{' is IMMEDIATELY
  followed by an identifier (no space) AND the braces contain a ? : ternary - that shipped
  JSON.stringify(0) and an HTTP 500 in slice 1. Keep such literals multi-line or hoist the ternary.
- Report BLOCKED with a precise reason rather than improvising around the contract.
- Report status PASS | PARTIAL | BLOCKED; files changed; per-case discrimination; the rendered
  before/after for Details and Changes; anything unverified; anywhere the contract is wrong.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/consignee-slice4
rc=$?
echo "lane: consignee-slice4 — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
