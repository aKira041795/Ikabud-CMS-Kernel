#!/usr/bin/env bash
#
# Lane: daily-ledger-admin-documents-route-authority — 6.3 Authority Enforcement Coverage SLICE 11c.
# Declare route authority for the daily-ledger deliveries/receivings/price-groups writes.
# Each route's gate is a static dlCurrentUser([...]) exact role set (full function bodies verified):
#   ['admin','supervisor']                                  x8  branch-supply-rules, deliveries x6, receivings/void
#   ['admin','supervisor','production_in_charge']           x3  deliveries/review-provenance, receivings x2
#   ['admin']                                               x4  price-groups x2, assign-branch, product-prices
#
# Downstream discipline stays UNCHANGED: delivery variance/provenance rules, the price-groups
# feature flag (disabled => 403 before the user guard), kernel-admin opt-in (default OFF),
# production_in_charge path allowlists. The guard only adds refusals for roles outside the
# declared sets and never grants.
#
# Criterion (fails on base: write_enforced=44 after slice 11b — gate re-proves live at dispatch):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced=59 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 11c: declare route authority for the daily-ledger deliveries/receivings/price-groups writes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only (source discipline is downstream).
- Every target handler gates with `dlCurrentUser([...])` (EXACT in_array on role); the full function bodies were verified by the harness — the role sets below are byte-exact. Downstream discipline stays unchanged (delivery variance/provenance rules; price-groups feature flag 403 before the user guard; kernel-admin opt-in default OFF; PIC path allowlists). The guard only adds refusals for roles outside the declared sets.
- Capability id for all declarations: kernel.auth.authenticate@1 (the module's settled id, consistent with the 44 existing declarations). This extends them — multi-batch rule: this suite owns the exact 59-key full-set pin; the slice-11b suite (tests/daily_ledger_cashier_family_route_authority_declaration_test.php, 44 keys) MUST be converted to a subset pin of its keys in this same change — that conversion IS authorized here (three files total: module.json + your new test + the 11b conversion). Earlier suites are already subset pins; leave them.

# TARGET SET (15 routes, byte-exact keys; three role groups)
Group A, allowed_roles ["admin","supervisor"]:
  POST /daily-ledger/api/v1/admin/branch-supply-rules
  POST /daily-ledger/api/v1/deliveries/create
  POST /daily-ledger/api/v1/deliveries/post
  POST /daily-ledger/api/v1/deliveries/correct-consignee-discrepancy
  POST /daily-ledger/api/v1/deliveries/consignee-return
  POST /daily-ledger/api/v1/deliveries/void
  POST /daily-ledger/api/v1/admin/deliveries/change-destination
  POST /daily-ledger/api/v1/receivings/void
Group B, allowed_roles ["admin","supervisor","production_in_charge"]:
  POST /daily-ledger/api/v1/deliveries/review-provenance
  POST /daily-ledger/api/v1/receivings/create
  POST /daily-ledger/api/v1/receivings/post
Group C, allowed_roles ["admin"]:
  POST /daily-ledger/api/v1/admin/price-groups
  POST /daily-ledger/api/v1/admin/price-groups/update
  POST /daily-ledger/api/v1/admin/price-groups/assign-branch
  POST /daily-ledger/api/v1/admin/product-prices

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — ADD these 15 declarations to the EXISTING `capabilities.routes` map; each with kernel.auth.authenticate@1 and the EXACT allowed_roles of its group. The 44 existing declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/daily_ledger_admin_documents_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 59 keys (full-set pin — newest suite owns totality); each new route with kernel.auth.authenticate@1 and the exact allowed_roles of its group.
   b. MUST-ALLOW matrix: per route × the roles OF ITS GROUP (Daily Ledger source rows) + kernel-source admin (guard-admitted nuance) — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): all module roles NOT in the route's group (e.g. Group A must refuse cashier, production_in_charge, auditor, viewer; Group C must refuse supervisor, cashier, auditor) + a foreign role (e.g. 'technician'). Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + a role outside the group => 403 and the fixture handler marker stays UNSET; an in-group role => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared daily-ledger write route remains permitted — these four are permanently undeclared auth-only routes, use one: POST /daily-ledger/api/v1/cashier/ledger/withdrawals with a cashier actor => allowed && !configured && reason=no_authority_declared.
3. Convert tests/daily_ledger_cashier_family_route_authority_declaration_test.php exact-44 totality to a SUBSET pin of its 44 keys (established pattern: `array_diff($expected, array_keys($declared)) === []`, per-route exact checks unchanged, comment naming your suite as the new totality owner).
4. Test header must document the ROLLBACK FALSIFIER: any caller the handlers admit (a role in the route's declared group) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the downstream-discipline nuance.

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json, the new test file, and the authorized 11b conversion. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_admin_documents_route_authority_declaration_test.php  -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-admin-documents-coverage.sh               -> write_enforced=59 read_enforced=0 delegated_bound=0, rc 0 (~53s; run once)
3. php tests/workbench_governance_census_test.php                               -> PASS rc=0
4. slices stay green: gui 24/0; cms 37/0 + 15/0; dl 46/0 + 134/0 + slice-11 suite + 11b suite (subset pin green); harpp 66/0 + 84/0; ecommerce 34/0; aw 77/0 + 84/0 + 60/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and the two test files (one new ??, one modified)
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; group role boundaries; downstream-discipline nuances; pre-handler proof; per-route teeth; the 44-key conversion; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-admin-documents
