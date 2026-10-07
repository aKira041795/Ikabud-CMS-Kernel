#!/usr/bin/env bash
#
# Lane: tenant-login-entry-module-routing
#
# CONTRACT (AUTHORITATIVE): .ai/tenant-login-entry-module-routing.contract.md
#
# WHY THIS LANE EXISTS: on baronledger.test (tenant 207, entry module daily-ledger, auth_owned) the
# tenant root is CORRECT but /login is a DEAD END - it renders the kernel sign-in form, which posts to
# /api/v1/auth/login and authenticates against kernel users. That tenant's DB has no kernel_users
# table, so EVERY attempt 401s. The password was verified correct (password_verify returned true), so
# this is the wrong door, not wrong credentials.
#
# Measured base behaviour (and how the lane proves it changed):
#   /               -> /daily-ledger/login      CORRECT (entryLandingPath)
#   /login          -> /login                   DEAD END  <- the defect
#   /forgot-password-> /forgot-password         same shape
#   /dashboard      -> /daily-ledger/dashboard  CORRECT (prefixed)
#   /api/v1/health  -> unchanged                CORRECT (must stay)
#
# THE TRAP: shouldSkipRewrite() exempts kernel auth endpoints ON PURPOSE
# ("Never rewrite kernel auth endpoints. These must remain stable across all hosts/tenants."). That is
# right for the control-plane host and wrong for a tenant host whose entry module owns auth. Do not
# simply delete the exemption - make the resolution conditional and prove both directions.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "use SOl"). Small diff in a load-bearing place, plus a second silent-failure fix
# that needs the same judgement the whole session has been spent on: fail loudly, never silently.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are fixing the tenant /login dead end in the Ikabud repo at /var/www/html/applicationostest.

Read .ai/tenant-login-entry-module-routing.contract.md FIRST. It is the authority for this lane,
including the prohibited list. Do not expand scope.

PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim
INSPECTION-ONLY. No schema change is expected in this lane.

## THE DEFECT, ALREADY MEASURED FOR YOU (do not re-diagnose, verify it yourself and proceed)
Tenant 207 (baron-001) has entry_module_id = daily-ledger and the module declares auth_owned. On host
baronledger.test:
  /                -> /daily-ledger/login      (correct: entryLandingPath)
  /login           -> /login                   (DEAD END: renders the kernel form)
  /forgot-password -> /forgot-password         (same shape)
  /dashboard       -> /daily-ledger/dashboard  (correct: prefixed with the entry module)
  /api/v1/health   -> unchanged                (correct: must stay)

kernel/Http/TenantEntryRouter.php::shouldSkipRewrite() exempts '/login', '/auth/login', '/auth/logout',
'/forgot-password', '/reset-password' and the /auth/ and /api/v1/auth/ prefixes, with the comment
"Never rewrite kernel auth endpoints. These must remain stable across all hosts/tenants." On a tenant
host whose entry module OWNS auth, that exemption produces a sign-in form that provably cannot
authenticate anyone: the tenant database has no kernel users at all.

## R1 — resolve the login surface from the entry module when that module owns auth
When the resolved tenant's entry module is auth_owned AND exposes its own login route, /login (and
/forgot-password when the module exposes one) must resolve to that module's surface. REUSE the
existing entryLandingPath() resolution - it already derives the login path from the manifest routes
via tenantEntryModuleDelegateId. Do not duplicate manifest-route scanning and do NOT hard-code any
module id.

## R2 — THE GUARD, and the most important requirement in this lane
The change must be INVISIBLE everywhere the kernel login is still correct. Prove it in BOTH
directions:
  - a host with no resolved tenant              -> /login stays the kernel login
  - a tenant whose entry module is empty/'kernel' -> stays
  - a tenant whose entry module is NOT auth_owned -> stays
  - a tenant whose auth_owned entry module exposes NO login route -> stays
  - the control-plane host                       -> stays
Keep every other shouldSkipRewrite() exemption intact: /api/, /admin/, /assets/, /superadmin/,
already-prefixed routes, /cms/, and enabled-module public routes. Removing them is out of scope.

## R3 — end the SILENT fallback (the second defect)
kernelResolveEntryModuleLoginContext() (src/http/page-handlers.php:33-60) infers the callable as
preg_replace('/[^a-z0-9]+/i','_',$moduleId) . 'LoginPageContext'. daily-ledger therefore looks for
daily_ledgerLoginPageContext, but the module defines dlLoginPageContext - which DOES return
'login_endpoint' => $baseUrl . '/auth/login', i.e. the correct endpoint. It is never called.
project-audit-ledger has the same mismatch (it defines palLoginPageContext). On a miss the resolver
returns the default context with NO log line.
So: when the entry module owns auth and no login context can be resolved, emit a
write_log(<message>, 'warning', [...]) naming host, tenant id, entry module id, and the callable that
was looked for. Do NOT log on the healthy path - no noise.

## R4 — make the convention discoverable instead of guessed
Allow the module manifest to DECLARE the login-context callable, accepted and validated in the
auth_owned block the way the existing auth_owned fields are validated in
src/helpers/module-manager.php (see the authOwned validation around lines 1949-2020). Fall back to the
current derived name so every legacy manifest keeps working. A manifest that declares a callable which
does not exist is a validation error - fail at manifest validation, not at login time.

## ORACLE
Extend or add a focused test. Cases must be discriminating - they must FAIL on the base tree:
  - R1 (**discriminating**) /login on the tenant-207 host resolves to the module login. Base returns
    '/login' -> fails.
  - R2/PIN (**pin, passes on base - label it a pin, do not claim discrimination**) the control-plane
    host, the unresolved host, the empty-entry and the no-login-route entry ALL keep the kernel login.
  - R3 (**discriminating**) the warning is emitted when an auth-owned entry module has no resolvable
    login context, and is NOT emitted on the healthy path. Restore storage/logs/app.log to its
    pre-test bytes so no residue is left for an admin to chase.
  - R4 (**discriminating**) a manifest declaring the callable is honoured; a manifest declaring a
    missing callable is rejected.
If a case passes on the base tree it is a PIN - label it as one.

## Rules
- Smallest correct change. Never weaken, skip or delete an assertion to reach green.
- Tests that write to storage/logs/app.log MUST restore it to its pre-test bytes.
- Run: php -l on each changed PHP file; the new/extended test; and check BOTH storage/logs/app.log and
  storage/logs/error.log (error.log must be 0 bytes).
- Verify live if you can: baronledger.test/login must show the Daily Ledger sign-in; baronledger.test/
  must be unchanged.
- KNOWN, PRE-EXISTING, NOT YOURS: daily_ledger_shared_account_latest_holder_test.php fails its
  LOG-OBSERVATION assertions (its functional assertions pass); proven pre-existing by the chair with
  the handler files at 3c93ad6c. Do NOT fix it and do NOT use it to excuse a failure you introduce.
- Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; base-tree discrimination
  per case; anything you could not verify; and anywhere this brief or the contract is wrong - report it
  rather than improvising around it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/tenant-login-entry-module-routing
rc=$?
echo "lane: tenant-login-entry-module-routing — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
