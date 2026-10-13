#!/usr/bin/env bash
#
# Lane: attendance-wage-manage-approve-route-authority — 6.3 Authority Enforcement Coverage SLICE 10.
# Declare route authority for the wage management + approval chain:
#   manage@1  -> aw_userHasRole(['admin','supervisor'])  => allowed_roles ["admin","supervisor"]
#   approve@1 -> aw_userHasRole(['admin'])               => allowed_roles ["admin"]
# (both EXACT in_array, source=attendance-wage discipline downstream; static).
#
# Criterion (fails on base: write_enforced=25 after 9b):
#   scoped GovernanceCensus scan('attendance-wage') -> write_enforced=34 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 10: declare route authority for the attendance-wage management + approval writes and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- Gates (static, EXACT role membership via aw_userHasRole, source=attendance-wage discipline downstream — the guard never grants, only adds refusals; a foreign-source admin passes the guard and is refused by the handler exactly as today):
    attendanceWageGuard('attendance_wage.manage@1')  -> aw_cap_manage_1  -> roles ['admin','supervisor']
    attendanceWageGuard('attendance_wage.approve@1') -> aw_cap_approve_1 -> roles ['admin']
- Both capability ids are ALREADY exposed in the module manifest. This extends the 9a/9b declarations (25 keys) — multi-batch rule: this suite owns the exact 34-key full-set pin; 9a stays subset.
- Deliberately EXCLUDED (not faithfully role-declarable, document in design_calls): the auth-only routes (wage/groups x3 — any authenticated module user) and self-service routes (profile/clock/read-gated cash-advance create & benefits calculate) — role-mode cannot mirror auth-only/self-service semantics; kiosk/auth routes have their own mechanisms.

# TARGET SET (9 routes, byte-exact keys; two capability groups)
manage@1, allowed_roles ["admin","supervisor"]:
  POST /api/v1/wage/adjustments
  POST /api/v1/wage/deductions
  POST /api/v1/wage/deductions/{id}/status
approve@1, allowed_roles ["admin"]:
  POST /api/v1/wage/computations/{id}/approve
  POST /api/v1/wage/computations/{id}/pay
  POST /api/v1/wage/computations/batch/approve
  POST /api/v1/wage/computations/batch/pay
  POST /api/v1/wage/adjustments/{id}/approve
  POST /api/v1/wage/cash-advances/{id}/approve

# REQUIRED OUTCOME
1. modules/attendance-wage/module.json — ADD these 9 declarations to the EXISTING `capabilities.routes` map; each with its group's capability id and EXACT allowed_roles from the groups above. The 25 existing 9a/9b declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/attendance_wage_manage_approve_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 34 keys (full-set pin — newest suite owns totality); each new route with the exact capability + allowed_roles of its group.
   b. MUST-ALLOW matrix: per route × shapes — attendance-wage admin + kernel-source admin (guard-admitted nuance for both groups) PLUS attendance-wage supervisor for the MANAGE group only (supervisor must be in the manage allowlist, NOT in the approve allowlist) — with a VERIFIED tenant scope; assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): employee + a foreign role (e.g. 'cashier') on ALL 9 routes; ADDITIONALLY supervisor on the 6 approve routes (the approval boundary). Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler: declared route + below-minimum actor => 403 and the fixture handler marker stays UNSET; authorized actor => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared attendance-wage write route remains permitted (POST /api/v1/wage/groups with a supervisor actor => allowed && !configured && reason=no_authority_declared).
3. Test header must document the ROLLBACK FALSIFIER: any caller the handlers admit (manage: admin+supervisor; approve: admin) denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note the source-discipline and supervisor-boundary design calls.

# CONSTRAINTS
- Change ONLY: modules/attendance-wage/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/attendance_wage_manage_approve_route_authority_declaration_test.php -> all green (state the check count)
2. bash tools/acceptance-attendance-wage-manage-approve-coverage.sh              -> write_enforced=34 read_enforced=0 delegated_bound=0, rc 0
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui 24/0; cms entity-capability 37/0; cms delegated 15/0; dl slice-3 46/0; dl batch-2 134/0; harpp 66/0 + 84/0; ecommerce 34/0; aw 9a 77/0; aw 9b 84/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/attendance-wage/module.json and ?? tests/attendance_wage_manage_approve_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; supervisor boundary enforcement; pre-handler proof; per-route teeth; exclusions (auth-only/self-service routes); rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-attendance-wage-manage-approve
