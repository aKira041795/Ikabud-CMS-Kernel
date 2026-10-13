#!/usr/bin/env bash
#
# Lane: cms-delegated-settings-authority — 6.3 SLICE 4 (chair-approved, ruling 2026-10-13,
# session coverage-declaration-modes): the FIRST delegated (module-decides) proof slice.
# ONE cms capability-gated write route declared delegated + executable evidence that the
# module predicate gates it. Declarations + tests only; NO handler changes; NO kernel edits.
#
# Criterion (fails on base: no delegated declaration, delegated_bound=0, test absent):
#   bash tools/acceptance-cms-delegated-settings-coverage.sh
#   -> delegated_bound=1 delegated_unbound=0 delegated_invalid=0 dispatch_enforced=3 (rc 0)
#   dispatch_enforced stays EXACTLY 3 — delegated coverage must not inflate dispatch.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 delegated-mode proof slice (chair-approved 2026-10-13): declare ONE cms capability-gated write route as `mode: module-decides` and provide EXECUTABLE evidence that the module's own predicate gates it. Declarations + tests only; the guard must remain untouched and transparent to this declaration.

# TARGET (verified by the harness — do not re-derive)
- Route: POST /api/v1/cms/settings -> cmsApiSettingsSave -> gate `cmsRequireCap('settings.manage')` at modules/cms/handlers/50-api-settings.php:47 (gate is IN the handler body; csrfEnforce follows it).
- cmsUserCan (modules/cms/helpers/05-permissions.php:267): kernel admin superuser; `is_service` tokens authorized by the token's capability allowlist REGARDLESS of role; otherwise capability minimums are role-hierarchy levels (CMS_DEFAULT_CAPABILITIES: settings.manage -> administrator at :97) from a TENANT-OVERRIDABLE map (settings role_permissions JSON; cmsCapabilityMap/cmsClearCapMapCache :219-268).
- cmsRequireCap (:324): 401 when unauthenticated; 403 JSON {ok:false,error:'Permission denied',required:'<cap>'} when the predicate fails.
- The census already implements delegated classification (commit ed3a3a7d): `delegated-bound` = the capability literal is reachable from THIS handler by statically resolvable named calls; summary reports delegated_bound / delegated_unbound / delegated_invalid separately and NEVER inflates dispatch_enforced. DO NOT modify the census or any kernel file.

# READ FIRST
- docs/kernel/authority-and-governance.md — the `mode: module-decides` contract, census categories, and the falsifiers (just added).
- kernel/Http/AuthorityDispatchGuard.php — decide() (a delegated declaration carries no allowed_roles => not configured => permitted; no dispatch decision is claimed).
- tests/cms_akira_route_authz_matrix_test.php, tests/cms_akira_theme_studio_save_route_security_test.php, tests/cms_cache_test.php — the established cms test patterns for predicate/handler context; REUSE whichever pattern actually gets the real cms decision path running.
- modules/cms/helpers/28-service-tokens.php:210-220 — the service-token actor shape (source=cms, is_service=true, role=token config, service_caps=[...]).

# MEASURED (base): cms scoped census write_enforced=3 (slice 2 role-mode set), delegated_bound=0. Acceptance fails on base.

# REQUIRED OUTCOME
1. modules/cms/module.json — capabilities.routes: ADD exactly ONE entry, byte-exact key:
     "POST /api/v1/cms/settings": { "mode": "module-decides", "capability": "settings.manage" }
   (no allowed_roles — not permitted in delegated mode). Keep the three existing slice-2 role-mode declarations untouched. Touch NOTHING else.
2. NEW tests/cms_delegated_settings_route_test.php:
   a. PINS: the delegated declaration exists with EXACTLY {mode, capability} and no allowed_roles; the three role-mode declarations are still present with their allowlists (anti-regression); and guard transparency — AuthorityDispatchGuard::decide('cms','POST','/api/v1/cms/settings', ..., $manifest, actor) returns allowed=true && configured=false for an actor the HANDLER would deny (e.g. cms author): the delegated declaration produces NO dispatch decision (this is correct delegated semantics, not a denial gap — say so in the check label).
   b. EXECUTABLE PREDICATE EVIDENCE (the core requirement — the effective predicate must gate the route, exercised, not asserted statically):
      - administrator (source=cms) => predicate admits ('settings.manage');
      - author (source=cms) => predicate denies;
      - service token actor (source=cms, is_service=true, role='author' — BELOW the minimum —, service_caps=['settings.manage']) => ADMITTED via the token allowlist (the role-agnostic semantics role mode could not express);
      - tenant override: save a role_permissions override lowering settings.manage to 'editor' (through the same settings store the module uses), clear cmsClearCapMapCache(), then cms editor => admitted. RESTORE the original settings value and cache state in finally — state-preservation discipline, no residue.
      - PROHIBITED CALLER CANNOT EXECUTE THE MUTATION: with a denied actor, run the real gate path (cmsRequireCap('settings.manage') within the working app/module context your chosen pattern provides) => 403 + JSON required='settings.manage', and the cms settings store snapshot is byte-identical before/after (no side effect). If invoking the full handler is infeasible in the harness, prove at the closest real call path that the deny precedes any write and state plainly what was proven vs. not.
   c. DOWNSTREAM-INTACT FALSIFIER (chair-critical): show the declaration is NOT the authorization — with the same actors, the predicate outcomes are IDENTICAL whether the declaration is present or removed (e.g. evaluate with an in-memory manifest copy without the key, and/or demonstrate the predicate path does not read capabilities.routes). Removing the declaration must not make denials disappear.
   d. ROLLBACK FALSIFIER header: any previously admitted caller (administrator+, service token holding settings.manage, tenant-override-authorized role) denied under the same valid identity/tenant context => roll back the declaration.
3. Deterministic output. Restore ALL mutated state (settings override, cap-map cache, env) in finally.

# CONSTRAINTS
- Change ONLY: modules/cms/module.json and the new test file. NO handler edits, NO kernel/census edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/cms_delegated_settings_route_test.php                    -> all green (state the check count)
2. bash tools/acceptance-cms-delegated-settings-coverage.sh           -> delegated_bound=1 delegated_unbound=0 delegated_invalid=0 dispatch_enforced=3, rc 0 (~164s census; run once — it also runs the test as executable evidence)
3. php tests/workbench_governance_census_test.php                     -> PASS rc=0
4. php tests/cms_entity_capability_route_authority_declaration_test.php -> 36 passed, 0 failed (slice 2 stays green)
5. php tests/gui_settings_route_authority_declaration_test.php        -> 24 passed, 0 failed; php tests/daily_ledger_user_admin_route_authority_declaration_test.php -> 46 passed, 0 failed
6. php tests/authority_dispatch_enforcement_test.php                  -> 4 passed, 0 failed; php tests/capability_authority_audit_test.php -> 26 passed, 0 failed
7. php tools/settings-contract-lint.php                               -> exit 0
8. git status --porcelain
   -> exactly M modules/cms/module.json and ?? tests/cms_delegated_settings_route_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: which cms test pattern was reused and how the real predicate path was exercised; what was proven for the no-mutation claim and what could not be proven; how the downstream-intact falsifier is demonstrated; identity/override shapes covered; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-cms-delegated-settings
