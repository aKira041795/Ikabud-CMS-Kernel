#!/usr/bin/env bash
#
# Lane: daily-ledger-ops-production-route-authority — 6.3 Authority Enforcement Coverage SLICE 11.
# Declare route authority for the daily-ledger managerial operations + production/commissary
# writes. Each route's gate is a static dlCurrentUser([...]) exact role set:
#   ['admin','supervisor']                                   x3  reopen-day, variances/update, reconciliation/save
#   ['admin','supervisor','production_in_charge']            x7  production x4, commissary run/material/dispatch
#   ['admin','supervisor','production_in_charge','auditor']  x1  commissary/carry-beginnings
#   ['admin','production_in_charge']                         x1  commissary/finalize-pm
# The production_in_charge path/entity discipline inside dlCurrentUser stays DOWNSTREAM and
# unchanged (the guard never grants; admitted PIC callers keep the handler's own discipline).
#
# Criterion (fails on base: write_enforced=21):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced=33 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 11: declare route authority for the daily-ledger managerial operations + production writes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- Each target handler's gate is `dlCurrentUser([...])` with the EXACT role set listed per group below (dlRequireAuth = EXACT in_array). Module-specific extras stay downstream and unchanged: kernel-admin opt-in (default OFF), and the production_in_charge path/entity allowlist inside dlCurrentUser — admitted PIC callers keep exactly today's per-path discipline; the guard only adds refusals for roles outside the declared sets and never grants.
- Capability id for all declarations: kernel.auth.authenticate@1 (the module's settled id, consistent with slices 3/7). This extends the 21 existing declarations — multi-batch rule: this suite owns the exact 33-key full-set pin; earlier suites stay subset.

# TARGET SET (12 routes, byte-exact keys; four role groups)
Group A, allowed_roles ["admin","supervisor"]:
  POST /daily-ledger/api/v1/admin/reopen-day
  POST /daily-ledger/api/v1/admin/variances/update
  POST /daily-ledger/api/v1/admin/reconciliation/save
Group B, allowed_roles ["admin","supervisor","production_in_charge"]:
  POST /daily-ledger/api/v1/production/withdrawal
  POST /daily-ledger/api/v1/production/output
  POST /daily-ledger/api/v1/production/reverse
  POST /daily-ledger/api/v1/production/sync-batch
  POST /daily-ledger/api/v1/commissary/run
  POST /daily-ledger/api/v1/commissary/material
  POST /daily-ledger/api/v1/commissary/dispatch
Group C, allowed_roles ["admin","supervisor","production_in_charge","auditor"]:
  POST /daily-ledger/api/v1/commissary/carry-beginnings
Group D, allowed_roles ["admin","production_in_charge"]:
  POST /daily-ledger/api/v1/commissary/finalize-pm

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — ADD these 12 declarations to the EXISTING `capabilities.routes` map; each with kernel.auth.authenticate@1 and EXACT allowed_roles of its group. The 21 existing declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/daily_ledger_ops_production_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 33 keys (full-set pin — newest suite owns totality); each new route with kernel.auth.authenticate@1 and the exact allowed_roles of its group.
   b. MUST-ALLOW matrix: per route × the roles OF ITS GROUP (Daily Ledger source rows) + kernel-source admin (guard-admitted nuance) — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): all module roles NOT in the route's group (e.g. Group A must refuse cashier, production_in_charge, auditor, viewer; Group D must refuse supervisor and auditor) + a foreign role ('cashier' is a module role — use a truly foreign one like 'technician' where needed). Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + a role outside the group => 403 and the fixture handler marker stays UNSET; an in-group role => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared daily-ledger write route remains permitted (e.g. POST /daily-ledger/api/v1/cashier/ledger/withdrawals with a cashier actor => allowed && !configured && reason=no_authority_declared).
3. Test header must document the ROLLBACK FALSIFIER: any caller the handlers admit (a role in the route's declared group) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the PIC path-discipline nuance.

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_ops_production_route_authority_declaration_test.php -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-ops-production-coverage.sh               -> write_enforced=33 read_enforced=0 delegated_bound=0, rc 0 (~53s; run once)
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui 24/0; cms 37/0 + 15/0; dl 46/0 + 134/0; harpp 66/0 + 84/0; ecommerce 34/0; aw 77/0 + 84/0 + slice-10 green
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and ?? tests/daily_ledger_ops_production_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; group role boundaries; PIC path-discipline nuance; pre-handler proof; per-route teeth; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-ops-production
