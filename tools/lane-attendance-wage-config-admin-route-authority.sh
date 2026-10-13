#!/usr/bin/env bash
#
# Lane: attendance-wage-config-admin-route-authority — 6.3 Authority Enforcement Coverage SLICE 9b.
# Declare route authority for the attendance-wage configuration admin writes (holidays,
# locations, settings management). Same gate shape as 9a:
# attendanceWageGuard('attendance_wage.admin@1') -> aw_userHasRole(['admin']) (EXACT).
#
# Criterion (fails on base: write_enforced=12 after 9a):
#   scoped GovernanceCensus scan('attendance-wage') -> write_enforced=25 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 9b: declare route authority for the attendance-wage configuration admin writes (batch 2 of the module) and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- Every target handler gates with attendanceWageGuard('attendance_wage.admin@1') -> provider aw_cap_admin_1 -> aw_userHasRole(['admin']) = EXACT role, source=attendance-wage (static; capability id already exposed). Faithful allowlist: ["admin"]; source discipline stays downstream.
- This is the SAME shape slice 9a already proved (tests/attendance_wage_data_admin_route_authority_declaration_test.php — the 12 existing declarations stay untouched; you ADD these 13 to the same capabilities.routes map).

# TARGET SET (13 routes, byte-exact keys; ~0.5s scoped census)
  POST /api/v1/wage/holidays
  POST /api/v1/wage/holidays/{id}
  POST /api/v1/wage/holidays/{id}/delete
  POST /api/v1/wage/locations
  POST /api/v1/wage/locations/{id}
  POST /api/v1/wage/locations/{id}/delete
  POST /api/v1/wage/settings
  POST /api/v1/wage/settings/change-password
  POST /api/v1/wage/settings/add-user
  POST /api/v1/wage/settings/update-role
  POST /api/v1/wage/settings/toggle-user
  POST /api/v1/wage/settings/data-reset
  POST /api/v1/wage/settings/backup

# REQUIRED OUTCOME
1. modules/attendance-wage/module.json — ADD these 13 declarations to the EXISTING `capabilities.routes` map; each:
     "capability": "attendance_wage.admin@1"
     "allowed_roles": ["admin"]
   The 12 slice-9a declarations stay EXACTLY as they are. Touch NOTHING else in the manifest.
2. NEW tests/attendance_wage_config_admin_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 25 keys (12 from 9a + these 13 — full-set pin prevents over-declaration); each 9b route with capability attendance_wage.admin@1 and allowed_roles exactly ["admin"].
   b. MUST-ALLOW matrix: per route × shapes — attendance-wage admin (source='attendance-wage') and kernel-source admin (source='kernel') — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): roles supervisor, employee, and a foreign role (e.g. 'cashier') on EACH of the 13 routes => denied, reason role_not_allowed. Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + supervisor actor => 403 and the fixture handler marker stays UNSET; admin => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared attendance-wage write route remains permitted (e.g. POST /api/v1/wage/groups with a supervisor actor => allowed && !configured && reason=no_authority_declared).
3. Test header must document the ROLLBACK FALSIFIER: any attendance-wage admin caller denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the source-discipline nuance.

# CONSTRAINTS
- Change ONLY: modules/attendance-wage/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/attendance_wage_config_admin_route_authority_declaration_test.php -> all green (state the check count)
2. bash tools/acceptance-attendance-wage-config-admin-coverage.sh              -> write_enforced=25 read_enforced=0 delegated_bound=0, rc 0
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui_settings 24/0; cms entity-capability 37/0; cms delegated 15/0; daily-ledger slice-3 46/0; daily-ledger batch-2 green; harpp 66/0; ecommerce 34/0; attendance-wage 9a green
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/attendance-wage/module.json and ?? tests/attendance_wage_config_admin_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; pre-handler proof; per-route teeth; source-discipline nuance; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-attendance-wage-config-admin
