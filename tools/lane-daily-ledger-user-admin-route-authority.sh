#!/usr/bin/env bash
#
# Lane: daily-ledger-user-admin-route-authority — 6.3 Authority Enforcement Coverage SLICE 3.
# Declare route authority for the five daily-ledger user/role administration write routes
# (all gated by dlCurrentUser(['admin']) — EXACT role membership via dlRequireAuth) and
# prove enforcement end-to-end. Declarations + tests only; NO handler changes.
#
# Criterion (fails on base: all 5 operations undeclared, write_enforced=0):
#   scoped GovernanceCensus scan('daily-ledger') -> write_enforced >= 5
#   (scoped census runtime ~53s)

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 3: declare route authority for the five daily-ledger user/role administration write routes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (already verified by the harness — use it, do not re-derive)
- GovernanceCensus parses BOTH declaration shapes (object canonical, string legacy) since commit 660b36cb. Declare in the OBJECT form.
- AuthorityDispatchGuard::decide() (kernel/Http/AuthorityDispatchGuard.php): object declaration => capability id from `capability`|`id`; roles from declaration `allowed_roles` else policy `allowed_roles|allow_roles`; undeclared/malformed/roleless => PERMITTED (missing config); wrong role => 403 `role_not_allowed` configured=true; denial shape via executeModuleHandler = API JSON {ok:false,error:Forbidden,state:route_authority_denied} or plain "Forbidden"; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
- The guard checks the actor ROLE only (`$user['role']` as passed at src/helpers/module-manager.php:3443).
- The five target handlers gate with `dlCurrentUser(['admin'])` (modules/daily-ledger/handlers.php:19499,19664,19832,19898,14431). `dlCurrentUser` delegates to `dlRequireAuth($roles)` (handlers.php:7531), which is EXACT membership: `in_array($role, $roles, true)` — no hierarchy. `dlCurrentUser` then adds module-specific rules that remain DOWNSTREAM and unchanged: kernel-admin opt-in (source=kernel+role=admin requires `allow_kernel_admin` module setting, default OFF => 403 'Forbidden'), and the production_in_charge path allowlist (irrelevant to these paths). Therefore the declaration mirrors the gate EXACTLY with allowed_roles ["admin"]: every caller the handler admits has role 'admin'; every other role is refused by the handler today too (as 401 session_expired JSON or login redirect — the refusal SHAPE changes to 403 route_authority_denied at dispatch; that is the accepted program semantics, note it in design_calls).
- Capability id for the declarations: `kernel.auth.authenticate@1` — the module's own settled capability for its auth surface (declared in its exposes AND policy block with allow_callers [daily-ledger, kernel]; handlers.php manages exactly that surface's accounts). The declaration carries inline allowed_roles, so the policy is a dormant fallback. Do NOT invent new capability ids.

# READ FIRST
- docs/kernel/authority-and-governance.md — declaration format + semantics.
- kernel/Http/AuthorityDispatchGuard.php — decide() and routeKey() (exact pattern-key match).
- tests/gui_settings_route_authority_declaration_test.php (slice 1) and tests/cms_entity_capability_route_authority_declaration_test.php (slice 2) — the established test pattern (real-manifest pins incl. exact key set, must-allow matrix with verified tenant scope, per-route teeth, executeModuleHandler pre-handler proof with marker, parity, rollback falsifier header). MIRROR this structure.
- modules/daily-ledger/module.json + modules/daily-ledger/routes.php (POST group, lines ~166-190) + the five handlers above.

# MEASURED (base, scoped census): daily-ledger write_total=70 write_enforced=0, read_total=70 read_enforced=0. The five target routes register as exactly:
  POST /daily-ledger/api/v1/admin/dl-users                  (undeclared)
  POST /daily-ledger/api/v1/admin/dl-users/update           (undeclared)
  POST /daily-ledger/api/v1/admin/dl-users/delete           (undeclared)
  POST /daily-ledger/api/v1/admin/dl-users/restore          (undeclared)
  POST /daily-ledger/api/v1/admin/settings/permissions      (undeclared)

# REQUIRED OUTCOME
1. modules/daily-ledger/module.json — add `capabilities.routes` with EXACTLY these five declarations (keys byte-identical to the patterns above); each:
     "capability": "kernel.auth.authenticate@1"
     "allowed_roles": ["admin"]
   Touch NOTHING else in the manifest. Do NOT declare any other daily-ledger route.
2. NEW tests/daily_ledger_user_admin_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the `capabilities.routes` map contains EXACTLY these five keys (guards against over-declaration), each allowed_roles exactly ["admin"], capability exactly "kernel.auth.authenticate@1".
   b. MUST-ALLOW matrix: per route × supported identity shapes — daily-ledger admin (source='daily-ledger', role='admin'), kernel-source admin (source='kernel', role='admin' — allowed by the GUARD; note in the check label that the module's kernel-admin opt-in gate remains downstream and unchanged) — each with a VERIFIED tenant scope (reuse the slice-1 resolver pattern); assert allowed && configured && reason=allowed. For shapes not supported for these routes: print "not applicable: <reason>" (e.g. service tokens — daily-ledger has no service-token identity; impersonation — no such path for these routes) — never silently skip.
   c. MUST-REFUSE PER ROUTE (teeth): daily-ledger roles supervisor, cashier, production_in_charge, auditor, viewer on EACH declared route => denied, reason role_not_allowed. Structure per-route so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-1 test exactly): declared route + below-minimum actor (e.g. role 'cashier') => 403 and the fixture handler marker stays UNSET (refusal precedes the handler body); role 'admin' => handler executes (marker set). Restore all state in finally (env, globals, tenant, currentUser, fixture dir).
   e. PARITY: an undeclared daily-ledger write route remains permitted — POST /daily-ledger/api/v1/commissary/run with a cashier actor => allowed && !configured && reason=no_authority_declared.
3. Test header comment must document the ROLLBACK FALSIFIER: any caller the handler admits today (role 'admin' from a daily-ledger session, or kernel admin with allow_kernel_admin enabled) denied at dispatch under the same valid identity/tenant context => roll back the declaration (no-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false).

# CONSTRAINTS
- Change ONLY: modules/daily-ledger/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do NOT git add / git commit. Do not touch tools/ or .ai/ (pre-existing harness artifacts).
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/daily_ledger_user_admin_route_authority_declaration_test.php   -> all green (state the check count)
2. bash tools/acceptance-daily-ledger-user-admin-coverage.sh                -> write_enforced=5 read_enforced=0, exit 0 (~53s run; run it once)
3. php tests/workbench_governance_census_test.php                           -> PASS (unchanged)
4. php tests/authority_census_contract_test.php                             -> 12 passed, 0 failed
5. php tests/authority_dispatch_enforcement_test.php                        -> 4 passed, 0 failed
6. php tests/capability_authority_audit_test.php                            -> 26 passed, 0 failed
7. php tests/gui_settings_route_authority_declaration_test.php              -> 24 passed, 0 failed (slice 1 stays green)
8. php tests/cms_entity_capability_route_authority_declaration_test.php     -> green (slice 2 stays green)
9. php tools/settings-contract-lint.php                                     -> exit 0
10. git status --porcelain
   -> exactly M modules/daily-ledger/module.json and ?? tests/daily_ledger_user_admin_route_authority_declaration_test.php
      (plus the pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; how the pre-handler proof works; how per-route teeth are structured; the refusal-shape change note; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-daily-ledger-user-admin
