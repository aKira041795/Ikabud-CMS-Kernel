#!/usr/bin/env bash
#
# Lane: daily-ledger-admin-batch2-route-authority — 6.3 Authority Enforcement Coverage SLICE 7.
# Declare route authority for the remaining daily-ledger exact-admin write routes
# (admin data management: products, branches, consignees, variance/delivery/receiving
# resolutions, branding asset, commissary endings controls). All gates are
# dlCurrentUser(['admin']) => dlRequireAuth EXACT membership — the same shape as slice 3.
#
# Criterion (fails on base: write_enforced=5 from slice 3):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced=21 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 7: declare route authority for the remaining daily-ledger exact-admin write routes (batch 2) and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- Every target handler's FIRST gate is `dlCurrentUser(['admin'])` (module-owned auth; `dlRequireAuth` = EXACT `in_array($role, $roles, true)` — no hierarchy). Module-specific extras stay downstream and unchanged (kernel-admin opt-in via allow_kernel_admin, default OFF; production_in_charge path allowlist irrelevant to these paths). Faithful allowlist: ["admin"].
  - Nuances for design_calls: kernel admin passes the role-only guard and is refused downstream by the opt-in gate exactly as today; wrong-role API callers move from the handler's 401 session_expired shape to dispatch 403 route_authority_denied — accepted program semantics (same as slice 3).
- This is the SAME declaration shape slice 3 already proved for 5 routes (see tests/daily_ledger_user_admin_route_authority_declaration_test.php — the existing slice-3 declarations stay untouched; you ADD these 16 to the same capabilities.routes map).

# TARGET SET (16 routes, byte-exact keys; ~53s scoped census)
  POST /daily-ledger/api/v1/admin/products
  POST /daily-ledger/api/v1/admin/products/update
  POST /daily-ledger/api/v1/admin/products/reprice
  POST /daily-ledger/api/v1/admin/products/import-csv
  POST /daily-ledger/api/v1/admin/branches
  POST /daily-ledger/api/v1/admin/branches/update
  POST /daily-ledger/api/v1/admin/consignees
  POST /daily-ledger/api/v1/admin/consignees/products
  POST /daily-ledger/api/v1/admin/branches/products
  POST /daily-ledger/api/v1/admin/variances/decide-delivery
  POST /daily-ledger/api/v1/admin/deliveries/resolve-origin
  POST /daily-ledger/api/v1/admin/receivings/resolve-count
  POST /daily-ledger/api/v1/admin/settings/branding-asset
  POST /daily-ledger/api/v1/commissary/settle-endings
  POST /daily-ledger/api/v1/commissary/verify-endings
  POST /daily-ledger/api/v1/commissary/revert-endings

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — ADD these 16 declarations to the EXISTING `capabilities.routes` map; each:
     "capability": "kernel.auth.authenticate@1"
     "allowed_roles": ["admin"]
   The 5 slice-3 declarations stay EXACTLY as they are. Touch NOTHING else in the manifest.
2. NEW tests/daily_ledger_admin_batch2_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 21 keys (5 slice-3 + these 16 — full-set pin prevents over-declaration); each batch-2 route with capability kernel.auth.authenticate@1 and allowed_roles exactly ["admin"].
   b. MUST-ALLOW matrix: per route × shapes — Daily Ledger admin (source='daily-ledger', role='admin'), kernel-source admin (source='kernel', role='admin') — with a VERIFIED tenant scope (established resolver pattern); assert allowed && configured && reason=allowed. For unsupported shapes (service tokens — Daily Ledger has none; impersonation — no such path), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): supervisor, cashier, production_in_charge, auditor, viewer on EACH of the 16 routes => denied, reason role_not_allowed. Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-3 test exactly): declared route + cashier actor => 403 and the fixture handler marker stays UNSET; admin => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared daily-ledger write route remains permitted (e.g. POST /daily-ledger/api/v1/admin/variances/update with a cashier actor => allowed && !configured && reason=no_authority_declared).
3. Test header must document the ROLLBACK FALSIFIER: any Daily Ledger admin (or kernel admin with allow_kernel_admin enabled) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_admin_batch2_route_authority_declaration_test.php   -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-admin-batch2-coverage.sh                -> write_enforced=21 read_enforced=0 delegated_bound=0, rc 0 (~53s; run once)
3. php tests/workbench_governance_census_test.php                             -> PASS rc=0
4. slices stay green: gui_settings 24/0; cms entity-capability 37/0; cms delegated 15/0; daily-ledger slice-3 46/0; harpp 66/0; ecommerce 34/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                       -> exit 0
7. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and ?? tests/daily_ledger_admin_batch2_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; pre-handler proof; per-route teeth; refusal-shape note; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-admin-batch2
