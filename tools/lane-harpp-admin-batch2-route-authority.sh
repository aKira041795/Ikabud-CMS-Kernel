#!/usr/bin/env bash
#
# Lane: harpp-admin-batch2-route-authority — 6.3 Authority Enforcement Coverage SLICE 8.
# Declare route authority for the harpp management write routes (decisions lifecycle,
# workspaces, projects, runner nudges) — all gated harpp.manage@1 through the static
# harppPermissionResult role map {owner, admin} + kernel superadmin (same shape as slice 5).
#
# Criterion (fails on base: write_enforced=7 from slice 5):
#   scoped GovernanceCensus scan('harpp') -> write_enforced=20 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 8: declare route authority for the harpp management write routes (batch 2) and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced). Declare role mode.
- AuthorityDispatchGuard::decide(): object declaration => capability id; roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- Every target handler gates with harppAuthenticated('harpp.manage@1') -> harppAuthorize -> bus -> harppPermissionResult('harpp.manage'): allows harpp-source users with role owner|admin, UNION kernel superadmin (source==='kernel' && role==='superadmin'), tenant-payload matched. STATIC map (not tenant-overridable; no service-token allowlists). Faithful role-mode allowlist: ["owner","admin","superadmin"] (same as slice 5).
  - Nuances for design_calls: a kernel admin passes the role-only guard and remains refused downstream exactly as today (harpp does not admit kernel admins); wrong-role callers move from the handler's forbidden JSON to dispatch 403 route_authority_denied — accepted program semantics.
- This is the SAME declaration shape slice 5 already proved (tests/harpp_owner_admin_route_authority_declaration_test.php — the existing 10 declarations stay untouched; you ADD these 13 to the same capabilities.routes map).

# TARGET SET (13 routes, byte-exact keys; ~0.5s scoped census)
  POST   /api/v1/harpp/decisions
  POST   /api/v1/harpp/decisions/{id}/apply-and-close
  DELETE /api/v1/harpp/decisions/{id}
  DELETE /api/v1/harpp/decisions/closed
  POST   /api/v1/harpp/workspaces
  POST   /api/v1/harpp/workspaces/{id}/update
  POST   /api/v1/harpp/workspaces/{id}/archive
  POST   /api/v1/harpp/workspaces/{id}/enroll
  POST   /api/v1/harpp/workspaces/{id}/projects
  POST   /api/v1/harpp/projects/{id}/update
  POST   /api/v1/harpp/projects/{id}/archive
  POST   /api/v1/harpp/projects/{id}/enroll
  POST   /api/v1/harpp/runners/{key}/nudge

# REQUIRED OUTCOME
1. modules/harpp/module.json — ADD these 13 declarations to the EXISTING `capabilities.routes` map; each:
     "capability": "harpp.manage@1"
     "allowed_roles": ["owner", "admin", "superadmin"]
   The 10 slice-5 declarations stay EXACTLY as they are. Touch NOTHING else in the manifest.
2. NEW tests/harpp_admin_batch2_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: the map contains EXACTLY the 23 keys (10 slice-5 + these 13 — full-set pin prevents over-declaration); each batch-2 route with capability harpp.manage@1 and allowed_roles exactly ["owner","admin","superadmin"].
   b. MUST-ALLOW matrix: per route × shapes — harpp owner, harpp admin, kernel superadmin — with a VERIFIED tenant scope (established resolver pattern); assert allowed && configured && reason=allowed. For unsupported shapes (service tokens; bridge daemons; impersonation), print "not applicable: <reason>".
   c. MUST-REFUSE PER ROUTE (teeth): role 'member' and a foreign role (e.g. 'cashier') on EACH of the 13 routes => denied, reason role_not_allowed. Per-route structure so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-5 test exactly): declared route + member actor => 403 and the fixture handler marker stays UNSET; owner => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared harpp write route remains permitted (e.g. POST /api/v1/harpp/conversations with a member actor => allowed && !configured && reason=no_authority_declared).
3. Test header must document the ROLLBACK FALSIFIER: any harpp owner/admin or kernel superadmin caller denied under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Also note the kernel-admin nuance.

# CONSTRAINTS
- Change ONLY: modules/harpp/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/harpp_admin_batch2_route_authority_declaration_test.php           -> all green (state the check count)
2. bash tools/acceptance-harpp-admin-batch2-coverage.sh                        -> write_enforced=20 read_enforced=2 delegated_bound=0, rc 0
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui_settings 24/0; cms entity-capability 37/0; cms delegated 15/0; daily-ledger slice-3 46/0; daily-ledger batch-2 green; harpp slice-5 66/0; ecommerce 34/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/harpp/module.json and ?? tests/harpp_admin_batch2_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; pre-handler proof; per-route teeth; kernel-admin nuance; refusal-shape note; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-harpp-admin-batch2
