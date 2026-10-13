#!/usr/bin/env bash
#
# Lane: ecommerce-product-admin-route-authority — 6.3 Authority Enforcement Coverage SLICE 6.
# Declare route authority for the ecommerce product-catalog admin write surface:
# admin form create/edit + the product API create/update. All four handlers gate with
# ecRequireAdmin() -> cmsRequireRole('administrator') (static CMS_ROLES hierarchy;
# kernel admin source-union; no tenant overrides; no service-cap bypass) -> role-mode
# allowlist ["administrator","superadmin","admin"] mirrors every admitted caller.
#
# Criterion (fails on base: all 4 operations undeclared, write_enforced=0):
#   scoped GovernanceCensus scan('ecommerce') -> write_enforced=4 rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 6: declare route authority for the ecommerce product-catalog admin write surface and prove enforcement end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- GovernanceCensus parses object role-mode declarations (inline allowed_roles => dispatch-enforced) and module-decides declarations. Declare role mode here.
- AuthorityDispatchGuard::decide(): object declaration => capability id (`capability`|`id`); roles from `allowed_roles`; wrong role => 403 `role_not_allowed` configured=true; undeclared/malformed => PERMITTED; recovery flag KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Guard checks the actor ROLE only.
- ecommerce admin gate: ecRequireAdmin() (modules/ecommerce/handlers/00-bootstrap.php:20) = cmsRequireRole('administrator') — mirror semantics from the slice-2 cms test: admits kernel admin (source=kernel role=admin); else source must be 'cms' with cmsRoleAtLeast(role,'administrator') via CMS_ROLES (modules/cms/helpers/00-bootstrap.php:58: superadmin 100, administrator 90, editor 70, ...). Service tokens are gated by the same role hierarchy (no service-cap bypass); role minimums are NOT tenant-overridable. Faithful role-mode allowlist: ["administrator","superadmin","admin"] — every caller the handler admits matches one; every other role is refused by the handler today too.
  - design_calls notes: a kernel admin passes the role-only guard and remains admitted downstream exactly as today; a wrong-role caller moves from the handler's 403/redirect to earlier uniform dispatch 403 route_authority_denied JSON. The product DELETE route is deliberately NOT in this slice (the only fitting existing capability id, ecommerce.product.upsert@1, would be a semantic stretch for delete — a later slice with a fitting id).
- Capability id: ecommerce.product.upsert@1 — EXISTS in the module's exposes AND policy block. Do NOT invent ids.

# TARGET SET (byte-exact route keys; ~55s scoped census)
  POST /ecommerce/admin/products/create       -> ecAdminProductCreate  (ecRequireAdmin)
  POST /ecommerce/admin/products/{id}/edit    -> ecAdminProductEdit    (ecRequireAdmin)
  POST /api/v1/ecommerce/products             -> ecApiProductCreate    (ecRequireAdmin)
  POST /api/v1/ecommerce/products/{id}        -> ecApiProductUpdate    (ecRequireAdmin)

# REQUIRED OUTCOME
1. modules/ecommerce/module.json — add `capabilities.routes` with EXACTLY these four declarations; each:
     "capability": "ecommerce.product.upsert@1"
     "allowed_roles": ["administrator", "superadmin", "admin"]
   Touch NOTHING else in the manifest. Do NOT declare any other ecommerce route.
2. NEW tests/ecommerce_product_admin_route_authority_declaration_test.php covering, with PER-ROUTE granularity:
   a. REAL-MANIFEST pins: exactly these four keys (guards against over-declaration); each with capability ecommerce.product.upsert@1 and allowed_roles exactly ["administrator","superadmin","admin"].
   b. MUST-ALLOW matrix: per route × shapes — CMS administrator (source='cms'), CMS superadmin, kernel admin (source='kernel', role='admin') — with a VERIFIED tenant scope (established resolver pattern); assert allowed && configured && reason=allowed. For unsupported shapes (raw cookies; impersonation; service tokens — admitted only via their role under the same hierarchy, covered by role cases), print "not applicable: <reason>" — never silently skip.
   c. MUST-REFUSE PER ROUTE (teeth): CMS editor, CMS author and a foreign role (e.g. 'cashier') on EACH declared route => denied, reason role_not_allowed. Structure per-route so REMOVING ANY ONE declaration fails exactly that route's checks.
   d. PRE-HANDLER PROOF: temp fixture module + executeModuleHandler (mirror the slice-1 test exactly): declared route + editor actor => 403 and the fixture handler marker stays UNSET; administrator => handler executes (marker set). Restore all state in finally.
   e. PARITY: an undeclared ecommerce write route remains permitted — POST /ecommerce/admin/coupons with an editor actor => allowed && !configured && reason=no_authority_declared.
3. Test header must document the ROLLBACK FALSIFIER: any caller the handler admits (CMS administrator+, kernel admin) denied at dispatch under the same valid identity/tenant context => roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.

# CONSTRAINTS
- Change ONLY: modules/ecommerce/module.json and the new test file. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/ecommerce_product_admin_route_authority_declaration_test.php      -> all green (state the check count)
2. bash tools/acceptance-ecommerce-product-admin-coverage.sh                   -> write_enforced=4 read_enforced=0 delegated_bound=0, rc 0 (~55s; run once)
3. php tests/workbench_governance_census_test.php                              -> PASS rc=0
4. slices stay green: gui_settings 24/0; cms entity-capability 37/0; cms delegated 15/0; daily-ledger 46/0; harpp 66/0
5. php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                        -> exit 0
7. git status --porcelain
   -> exactly M modules/ecommerce/module.json and ?? tests/ecommerce_product_admin_route_authority_declaration_test.php
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: identity shapes covered/not-applicable; pre-handler proof; per-route teeth; the kernel-admin nuance; the refusal-shape note; the delete-route exclusion; the rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-ecommerce-product-admin
