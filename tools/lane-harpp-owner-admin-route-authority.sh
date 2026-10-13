#!/usr/bin/env bash
#
# Lane: harpp-owner-admin-route-authority — 6.3 Authority Enforcement Coverage SLICE 5.
# Declare route authority for the harpp owner/admin control surface: user administration
# (create/update/delete/reset + the sensitive user list), settings save/read, and the
# deploy request/cancel controls. All gates resolve to STATIC role sets
# {owner, admin} + kernel superadmin (harppPermissionResult map, not tenant-overridable,
# no service-token allowlists) -> role-mode declarations mirror them exactly.
#
# Criterion (fails on base: all 10 operations undeclared, write_enforced=0):
#   scoped GovernanceCensus scan('harpp') -> write_enforced=8 read_enforced=2 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 5: declare route authority for the harpp owner/admin control surface and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object declarations (role mode) and module-decides declarations; role-mode declarations with inline allowed_roles count as dispatch-enforced. Declare role mode here.
- AuthorityDispatchGuard::decide() (kernel/Http/AuthorityDispatchGuard.php): object declaration => capability id (`capability`|`id`); roles from declaration `allowed_roles` else policy fallback; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. The guard checks the actor ROLE only.
- harpp gates (modules/harpp/helpers.php:130-160 `harppPermissionResult` + providers :213-255): harpp.settings.manage / harpp.settings.read / harpp.users.manage / harpp.deploy.request all allow harpp-source users with role owner|admin, UNION kernel superadmin (`source==='kernel' && role==='superadmin'`), with a tenant-payload match. The role map is STATIC (hardcoded, not settings-overridable; no service-token allowlists on these capabilities). Therefore the faithful role-mode allowlist is ["owner","admin","superadmin"]: every caller the handler admits matches one of these roles; every other role is refused by the handler today too.
  - Nuances to note in design_calls: role 'admin' also matches a KERNEL admin actor (source=kernel) — the guard admits it, but the HANDLER still refuses it (kernel admin is not harpp-admin and not superadmin) — identical to pre-declaration behavior (the route was undeclared before, so the guard admitted it too). The guard never grants; it only adds refusals. Refusal-shape note: previously a wrong-role caller got the handler's 403 forbidden JSON; now they get dispatch 403 route_authority_denied JSON — same status, earlier, uniform.
- All capability ids used are ALREADY exposed in modules/harpp/module.json (do NOT invent ids).

# TARGET SET (byte-exact route keys; ~0.5s scoped census)
Writes (8):
  POST /api/v1/harpp/settings                     -> harpp.settings.manage@1
  POST /api/v1/harpp/users                        -> harpp.users.manage@1
  PUT /api/v1/harpp/users/{id}                    -> harpp.users.manage@1
  PATCH /api/v1/harpp/users/{id}                  -> harpp.users.manage@1
  DELETE /api/v1/harpp/users/{id}                 -> harpp.users.manage@1
  POST /api/v1/harpp/users/{id}/reset-password    -> harpp.users.manage@1
  POST /api/v1/harpp/deploys                      -> harpp.deploy.request@1
  POST /api/v1/harpp/deploys/{id}/cancel          -> harpp.deploy.request@1
Admin-gated reads (2):
  GET /api/v1/harpp/settings                      -> harpp.settings.read@1
  GET /api/v1/harpp/users                         -> harpp.users.manage@1 (the user list is sensitive)

# REQUIRED OUTCOME
1. modules/harpp/module.json — add `capabilities.routes` with EXACTLY these ten declarations; each:
     "capability": "<the id above>"
     "allowed_roles": ["owner", "admin", "superadmin"]
   Touch NOTHING else in the manifest. Do NOT declare any other harpp route.
2. NEW tests/harpp_owner_admin_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the `capabilities.routes` map contains EXACTLY these ten keys (guards against over-declaration), each with the exact capability id above and allowed_roles exactly ["owner","admin","superadmin"].
   b. MUST-ALLOW matrix: per route × identity shapes — harpp owner (source='harpp', role='owner'), harpp admin (source='harpp', role='admin'), kernel superadmin (source='kernel', role='superadmin') — each with a VERIFIED tenant scope (reuse the established slice-1..3 resolver pattern); assert allowed && configured && reason=allowed. For shapes not supported for these routes (service tokens; harpp_bridge daemons — they authenticate through the bridge-key routes, not these session routes; impersonation), print "not applicable: <reason>" — never silently skip.
   c. MUST-REFUSE PER ROUTE (teeth): role 'member' (harpp's default role) and a foreign role (e.g. 'cashier') on EACH declared route => denied, reason role_not_allowed. Structure per-route so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-1 test exactly): declared route + member actor => 403 and the fixture handler marker stays UNSET; owner => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared harpp write route remains permitted — POST /api/v1/harpp/decisions with a member actor => allowed && !configured && reason=no_authority_declared.
3. Test header comment must document the ROLLBACK FALSIFIER: any harpp owner/admin or kernel superadmin caller denied at dispatch under the same valid identity/tenant context => roll back the declaration (no-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false). Also note the kernel-admin nuance from CONTEXT in the header.

# CONSTRAINTS
- Change ONLY: modules/harpp/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/harpp_owner_admin_route_authority_declaration_test.php                            -> all green (state the check count)
2. bash tools/acceptance-harpp-owner-admin-coverage.sh                                         -> write_enforced=8 read_enforced=2 delegated_bound=0, rc 0
3. php tests/workbench_governance_census_test.php                                              -> PASS rc=0
4. php tests/gui_settings_route_authority_declaration_test.php                                 -> 24/0; php tests/cms_entity_capability_route_authority_declaration_test.php -> 37/0; php tests/cms_delegated_settings_route_test.php -> 15/0; php tests/daily_ledger_user_admin_route_authority_declaration_test.php -> 46/0
5. php tests/authority_dispatch_enforcement_test.php                                           -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/harpp/module.json and ?? tests/harpp_owner_admin_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; how the pre-handler proof works; how per-route teeth are structured; the kernel-admin nuance; the refusal-shape note; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-harpp-owner-admin
