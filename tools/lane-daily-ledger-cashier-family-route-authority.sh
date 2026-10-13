#!/usr/bin/env bash
#
# Lane: daily-ledger-cashier-family-route-authority — 6.3 Authority Enforcement Coverage SLICE 11b.
# Declare route authority for the daily-ledger cashier-family writes (cashier ledger ops,
# delivery edit, offline device enrollment). Each route's gate is a static dlCurrentUser([...])
# exact role set:
#   ['cashier','supervisor','admin']                                    x9  cashier/ledger x5 + delivery-edit + offline x3
#   ['cashier','supervisor','admin','production_in_charge']             x1  cashier/ledger/close-day
#   ['cashier','supervisor','production_in_charge','admin']             x1  products/toggle
#
# Downstream discipline stays UNCHANGED: fine-grained role_permissions grants, production_in_charge
# method+path allowlists, kernel-admin opt-in (default OFF), offline enrollment device binding.
# The guard only adds refusals for roles outside the declared sets and never grants.
#
# Criterion (fails on base: write_enforced=33 after slice 11 — gate re-proves live at dispatch):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced=44 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 11b: declare route authority for the daily-ledger cashier-family writes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only (source discipline is downstream).
- Every target handler gates with `dlCurrentUser([...])` (EXACT in_array on role). Downstream discipline in dlCurrentUser stays unchanged: kernel-admin opt-in (`allow_kernel_admin` default OFF), supervisor/auditor admin-only path refusals, production_in_charge method+path allowlists; POS fine-grained `dl_roleHasPermission` grants; offline enrollment device binding. The guard only adds refusals for roles outside the declared sets.
- Capability id for all declarations: kernel.auth.authenticate@1 (the module's settled id, consistent with the 33 existing declarations). This extends them — multi-batch rule: this suite owns the exact 44-key full-set pin; earlier suites stay subset (the slice-11 suite pinned exact-33 totality — after your 11 additions it MUST be converted to a subset pin of its 33 keys, exact-full-set moving to yours; that conversion of tests/daily_ledger_ops_production_route_authority_declaration_test.php IS authorized in this slice).

# TARGET SET (11 routes, byte-exact keys; three role groups)
Group A, allowed_roles ["cashier","supervisor","admin"]:
  POST /daily-ledger/api/v1/cashier/ledger/save
  POST /daily-ledger/api/v1/cashier/ledger/save-batch
  POST /daily-ledger/api/v1/cashier/ledger/carry-beginnings
  POST /daily-ledger/api/v1/cashier/ledger/finalize-pm
  POST /daily-ledger/api/v1/cashier/ledger/withdrawals/edit
  POST /daily-ledger/api/v1/cashier/ledger/delivery-edit
  POST /daily-ledger/api/v1/offline/enroll
  POST /daily-ledger/api/v1/offline/revoke
  POST /daily-ledger/api/v1/offline/reconcile
Group B, allowed_roles ["cashier","supervisor","admin","production_in_charge"]:
  POST /daily-ledger/api/v1/cashier/ledger/close-day
Group C, allowed_roles ["cashier","supervisor","production_in_charge","admin"]:
  POST /daily-ledger/api/v1/products/toggle

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — ADD these 11 declarations to the EXISTING `capabilities.routes` map; each with kernel.auth.authenticate@1 and the EXACT allowed_roles of its group. The 33 existing declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/daily_ledger_cashier_family_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 44 keys (full-set pin — newest suite owns totality); each new route with kernel.auth.authenticate@1 and the exact allowed_roles of its group.
   b. MUST-ALLOW matrix: per route × the roles OF ITS GROUP (Daily Ledger source rows) + kernel-source admin (guard-admitted nuance) — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation; offline device-token contact path), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): all module roles NOT in the route's group (e.g. Group A must refuse production_in_charge, auditor, viewer, employee; Group B must refuse auditor; Group C must refuse auditor) + a foreign role (e.g. 'technician'). Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + a role outside the group => 403 and the fixture handler marker stays UNSET; an in-group role => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared daily-ledger write route remains permitted — these four are permanently undeclared auth-only routes, use one: POST /daily-ledger/api/v1/cashier/ledger/withdrawals with a cashier actor => allowed && !configured && reason=no_authority_declared.
3. Convert tests/daily_ledger_ops_production_route_authority_declaration_test.php exact-33 totality to a SUBSET pin of its 33 keys (same pattern as the earlier 9b conversion: `array_diff($expected, array_keys($declared)) === []`, per-route exact checks unchanged, comment naming your suite as the new totality owner).
4. Test header must document the ROLLBACK FALSIFIER: any caller the handlers admit (a role in the route's declared group) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the downstream-discipline nuance (role_permissions grants, PIC allowlists, device binding unchanged).

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json, the new test file, and the one authorized conversion in tests/daily_ledger_ops_production_route_authority_declaration_test.php. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_cashier_family_route_authority_declaration_test.php  -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-cashier-family-coverage.sh               -> write_enforced=44 read_enforced=0 delegated_bound=0, rc 0 (~53s; run once)
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui 24/0; cms 37/0 + 15/0; dl 46/0 + 134/0 + slice-11 suite (~green subset pin); harpp 66/0 + 84/0; ecommerce 34/0; aw 77/0 + 84/0 + 60/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and the two test files (one new ??, one modified)
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; group role boundaries; downstream-discipline nuances; pre-handler proof; per-route teeth; the 33-key conversion; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-cashier-family
