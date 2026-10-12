#!/usr/bin/env bash
#
# Lane: cms-entity-capability-route-authority — 6.3 Authority Enforcement Coverage SLICE 2.
# Declare route authority for the three role-gated cms write API routes (the module's
# ONLY write routes gated by cmsRequireRole rather than a capability) and prove
# enforcement end-to-end. Declarations + tests only; NO handler changes.
#
# Criterion (fails on base: all 3 operations undeclared, write_enforced=0):
#   scoped GovernanceCensus scan('cms') -> write_enforced >= 3
#   (scoped census runtime ~164s — under the harness 300s cap)

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 2: declare route authority for the three role-gated cms write API routes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (already verified by the harness — use it, do not re-derive)
- GovernanceCensus parses BOTH declaration shapes (object canonical, string legacy) since commit 660b36cb. Declare in the OBJECT form.
- AuthorityDispatchGuard::decide() (kernel/Http/AuthorityDispatchGuard.php): object declaration => capability id from `capability`|`id`; roles from declaration `allowed_roles` else policy `allowed_roles|allow_roles`; undeclared/malformed/roleless => PERMITTED (missing config); wrong role => 403 `role_not_allowed` configured=true; denial shape via executeModuleHandler = API JSON {ok:false,error:Forbidden,state:route_authority_denied} or plain "Forbidden"; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
- The guard checks the actor ROLE only (`$user['role']` as passed at src/helpers/module-manager.php:3443). Actor = app()->user(), overridden by the verified module cookie user when present.
- cms handler gates for the three target routes = `cmsRequireRole('editor')` (modules/cms/handlers/88-entity-capabilities.php:134, :166, :188 — attach/detach/preset). cmsRequireRole (modules/cms/helpers/10-core.php:83-131) admits: source=kernel+role=admin (kernel admin), or source=cms with roleAtLeast(role,'editor') via CMS_ROLES hierarchy (modules/cms/helpers/00-bootstrap.php:58: superadmin 100, administrator 90, editor 70, author 50, contributor 30, subscriber 10, customer 8). It has NO service-capability bypass — a service token (source=cms, is_service=true, role=configurable default 'editor') passes only via its role. Therefore the handler's effective rule = role in {editor, administrator, superadmin} OR kernel admin (role 'admin'). This set is STATIC (the role hierarchy constant is not tenant-overridable; only cms CAPABILITY minimums are overridable, and these routes are role-gated).
- The new declaration must EXACTLY mirror that: allowed_roles ["editor","administrator","superadmin","admin"].

# READ FIRST
- docs/kernel/authority-and-governance.md — declaration format + semantics.
- kernel/Http/AuthorityDispatchGuard.php — decide() and routeKey() (exact pattern-key match).
- tests/gui_settings_route_authority_declaration_test.php — the slice-1 test pattern (real-manifest pins, must-allow matrix incl. verified tenant scope, per-route teeth, executeModuleHandler pre-handler proof with marker, parity, rollback falsifier header). MIRROR this structure.
- modules/cms/module.json + modules/cms/routes.php (POST section) + the three handlers above.

# MEASURED (base, scoped census): cms write_total=86 write_enforced=0, read_total=101 read_enforced=0. The three target routes register as exactly:
  POST /api/v1/cms/content/{id}/capabilities            (undeclared)
  POST /api/v1/cms/content/{id}/capabilities/preset     (undeclared)
  POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach (undeclared)

# REQUIRED OUTCOME
1. modules/cms/module.json — add `capabilities.routes` with EXACTLY these three declarations (keys byte-identical to the patterns above); each:
     "capability": "cms.content.update@1"      (existing exposed capability — do NOT invent ids)
     "allowed_roles": ["editor", "administrator", "superadmin", "admin"]
   Touch NOTHING else in the manifest. Do NOT declare any other cms route (the rest are capability-gated and are NOT in scope).
2. NEW tests/cms_entity_capability_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the `capabilities.routes` map contains EXACTLY these three keys (guards against over-declaration), each allowed_roles exactly ["editor","administrator","superadmin","admin"], capability exactly "cms.content.update@1".
   b. MUST-ALLOW matrix: per route × supported identity shapes — cms editor, cms administrator, cms superadmin (source=cms), kernel-source admin (source=kernel, role=admin), service token (normalized actor: source=cms, is_service=true, role=editor, service_caps=[...]) — each with a VERIFIED tenant scope (reuse the slice-1 resolver pattern); assert allowed && configured && reason=allowed. For shapes not supported: print "not applicable: <reason>" — never silently skip.
   c. MUST-REFUSE PER ROUTE (teeth): cms roles author, contributor, subscriber, customer on EACH declared route => denied, reason role_not_allowed. Structure per-route so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-1 test exactly): declared route + below-minimum actor => 403 and the fixture handler marker stays UNSET (refusal precedes the handler body); editor => handler executes (marker set). Restore all state in finally (env, globals, tenant, currentUser, fixture dir).
   e. PARITY: an undeclared cms write route remains permitted — POST /api/v1/cms/settings with an author actor => allowed && !configured && reason=no_authority_declared.
3. Test header comment must document the ROLLBACK FALSIFIER: any caller the handler admits today (cms role >= editor, kernel admin) denied at dispatch under the same valid identity/tenant context => roll back the declaration (no-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false).

# CONSTRAINTS
- Change ONLY: modules/cms/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do NOT git add / git commit. Do not touch tools/ or .ai/ (pre-existing harness artifacts).
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/cms_entity_capability_route_authority_declaration_test.php   -> all green (state the check count)
2. bash tools/acceptance-cms-entity-capability-coverage.sh                -> write_enforced=3 read_enforced=0, exit 0 (~164s run; run it once)
3. php tests/workbench_governance_census_test.php                         -> PASS (unchanged)
4. php tests/authority_census_contract_test.php                           -> 12 passed, 0 failed
5. php tests/authority_dispatch_enforcement_test.php                      -> 4 passed, 0 failed
6. php tests/capability_authority_audit_test.php                          -> 26 passed, 0 failed
7. php tests/gui_settings_route_authority_declaration_test.php            -> 24 passed, 0 failed (slice 1 stays green)
8. php tools/settings-contract-lint.php                                   -> exit 0
9. git status --porcelain
   -> exactly M modules/cms/module.json and ?? tests/cms_entity_capability_route_authority_declaration_test.php
      (plus the pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; how the pre-handler proof works; how per-route teeth are structured; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-cms-entity-capability
