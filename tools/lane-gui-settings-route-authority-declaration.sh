#!/usr/bin/env bash
#
# Lane: gui-settings-route-authority-declaration — 6.3 Authority Enforcement Coverage SLICE 1
# (chair-approved with adjustments): declare route authority for gui-settings and prove
# enforcement end-to-end. Declarations + tests only; NO handler changes.
#
# Criterion (fails on base: all 3 operations undeclared, write_enforced=0 / read_enforced=0):
#   scoped GovernanceCensus scan('gui-settings') -> write_enforced>=2 and read_enforced>=1

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 1 (chair-approved): declare route authority for the gui-settings module and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# READ FIRST
- docs/kernel/authority-and-governance.md — declaration format + semantics: declared + role mismatch => 403 refusal at dispatch; undeclared / malformed / missing policy => permitted (compatibility).
- kernel/Http/AuthorityDispatchGuard.php — decide() shape; ENV_FLAG KERNEL_AUTHORITY_DISPATCH_ENFORCE (default ON; disabling is the no-deploy recovery).
- src/helpers/module-manager.php:3420-3475 — the dispatch-time call site and the denial response shape (403; API JSON state=route_authority_denied).
- tests/module_route_declaration_runtime_test.php — the established runtime test pattern for route declarations.
- modules/gui-settings/module.json + modules/gui-settings/handlers.php — current gates: page requireAnyRole('admin'); both APIs role !== 'admin' => refusal. The declaration must MIRROR this authorization, not change it.

# MEASURED (base, scoped census): 3 operations, all undeclared — GET /admin/gui-settings (read), POST /api/v1/admin/gui-settings (write), POST /api/v1/admin/gui-settings/reset (write). write_enforced=0, read_enforced=0.

# REQUIRED OUTCOME
1. modules/gui-settings/module.json — add `capabilities.routes` with EXACTLY these three declarations; each allowed_roles ["admin"]; each capability "gui_settings.apply@1" (the module's existing exposed capability — do NOT invent ids):
   "GET /admin/gui-settings"
   "POST /api/v1/admin/gui-settings"
   "POST /api/v1/admin/gui-settings/reset"
   Touch NOTHING else in the manifest.
2. NEW tests/gui_settings_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the three declarations exist, each allowed_roles exactly ["admin"], capability gui_settings.apply@1.
   b. MUST-ALLOW matrix (chair requirement): every identity shape executeModuleHandler can pass to the guard that is SUPPORTED for these routes — tenant-session admin, kernel-source admin, token/API admin if the guard supports token identities, supported impersonation — plus a verified tenant-scoping context; each must be permitted by decide(). For any shape NOT supported for these routes, print "not applicable: <reason>" — never silently skip.
   c. MUST-REFUSE PER ROUTE (teeth): a non-admin actor on EACH declared route must be denied by decide() with reason role_not_allowed. Structure the assertion per-route so REMOVING ANY ONE declaration makes exactly that route's check fail.
   d. PRE-HANDLER PROOF (chair: "stopped before the handler"): using a temp fixture module (pattern: module_route_declaration_runtime_test.php), a declared route + non-admin actor must be refused WITHOUT the fixture handler body executing (marker unset); the admin case must reach the handler (marker set). If full executeModuleHandler invocation is not feasible in this harness, use the closest call path that still proves dispatch-level refusal precedes handler invocation and state plainly what was proven.
   e. PARITY: an undeclared route on the same module remains permitted (no_authority_declared) — unchanged from base.
3. Test header comment must document the ROLLBACK FALSIFIER: any previously authorized caller denied under the same valid identity/tenant context => roll back the declaration (temporary recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false, no deploy required).

# CONSTRAINTS
- Change ONLY: modules/gui-settings/module.json and the new test file. NO handler edits, NO other modules, NO kernel edits. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/gui_settings_route_authority_declaration_test.php     -> all green (state the check count)
2. Scoped census acceptance:
   php -r 'require "kernel/Workbench/Governance/GovernanceCensus.php"; $r=(new Ikabud\Kernel\Workbench\Governance\GovernanceCensus(getcwd()))->scan("gui-settings"); $s=$r["modules"][0]["summary"]; echo "write_enforced=".$s["dispatch_enforced"]." read_enforced=".$s["read_dispatch_enforced"]."\n"; exit(($s["dispatch_enforced"]>=2 && $s["read_dispatch_enforced"]>=1)?0:1);'
   -> write_enforced=2 read_enforced=1, exit 0
3. php tests/workbench_governance_census_test.php                  -> PASS (unchanged)
4. php tests/authority_dispatch_enforcement_test.php               -> 4 passed, 0 failed
5. php tests/capability_authority_audit_test.php                   -> 26 passed, 0 failed
6. php tests/authority_census_contract_test.php                    -> 12 passed, 0 failed
7. php tools/settings-contract-lint.php                            -> exit 0 (settings contract untouched)
8. git status --porcelain                                          -> exactly M modules/gui-settings/module.json and the new test file

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; how the pre-handler proof works; how per-route teeth are structured; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-gui-settings-authority
