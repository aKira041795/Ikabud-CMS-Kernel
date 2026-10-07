#!/usr/bin/env bash
#
# Lane: tenant-login-module-auth-route-preference  (follow-up: repair a REGRESSION)
#
# The previous lane (tenant-login-entry-module-routing) reported PASS, and its own tests were green,
# but verification over REAL HTTP found its fix resolves an explicit /login to the module's ROOT
# instead of the module's LOGIN route. Measured on 2026-10-07:
#
#   juliesbakeshop.test/login -> /bakeshop (module ROOT) -> 302 back to /login -> /bakeshop -> ...
#                                INFINITE REDIRECT LOOP.  /bakeshop/login serves "Bakeshop Sign In" fine.
#   cmsnew.test/login         -> the tenant's PUBLIC frontpage ("Frontpage | Li'l Juanita"), not /cms/login
#   aiss.test/login           -> the tenant's PUBLIC blog ("Blog"), not /cms/login
#   9 other hosts             -> correct sign-in pages (they pass, and must keep passing)
#
# ROOT CAUSE: in TenantEntryRouter::entryLandingPath() the elseif chain prefers $delegateRoot over
# $delegateAuthPath. The "/" landing call and the explicit "/login" call BOTH pass $authPath='/login'
# (it is the default), so they are indistinguishable inside the chain and /login inherits the root
# preference that is only correct for "/".
#
# CONTRACT: .ai/tenant-login-entry-module-routing.contract.md (R1: /login must resolve to the module's
# login SURFACE - the root is not the login surface unless it redirects there, and for bakeshop it loops).

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are repairing a regression in the Ikabud repo at /var/www/html/applicationostest.

The previous lane fixed "tenant /login is a dead end on auth-owned entry modules". Its tests passed and
it reported PASS, but real-HTTP verification found it resolves an explicit /login to the entry module's
ROOT instead of its LOGIN route. Read .ai/tenant-login-entry-module-routing.contract.md first (R1 is
the requirement; its prohibited list still applies).

MEASURED FAILURES (reproduce them yourself before changing anything):
  juliesbakeshop.test/login  -> INFINITE LOOP. /login rewrites to /bakeshop (module root); the bakeshop
                                module 302s an unauthenticated visitor to /login; that rewrites to
                                /bakeshop again. /bakeshop/login serves "Bakeshop Sign In" perfectly.
  cmsnew.test/login          -> the tenant's PUBLIC frontpage, not /cms/login
  aiss.test/login            -> the tenant's PUBLIC blog, not /cms/login

ROOT CAUSE (already located, verify it):
  kernel/Http/TenantEntryRouter.php :: entryLandingPath(). The elseif chain prefers \$delegateRoot:
      } elseif (array_key_exists(\$delegateRoot, \$get)) { \$result = \$delegateRoot; }
      } elseif (array_key_exists(\$delegateAuthPath, \$get)) { \$result = \$delegateAuthPath; }
  rewriteUri('/') calls entryLandingPath(\$entry) with DEFAULTS, and entryOwnedAuthPath() calls it with
  \$authPath = '/login' for both '/login' and '/forgot-password'. So the "/" landing and an explicit
  "/login" are indistinguishable inside the chain, and /login inherits the root preference.

THE FIX - prefer the module's auth route when the request explicitly asked for one, and leave the "/"
landing exactly as it is today. entryOwnedAuthPath() already passes \$requireExposedAuthRoute = true;
use that (or an equally explicit discriminator) so:
  - explicit /login or /forgot-password with the module exposing that route -> the module's LOGIN route
    (/bakeshop/login, /cms/login, /wms/login, /harpp/login, ...)
  - the "/" landing -> unchanged behaviour (root preference preserved; do NOT change what / does)
Do not hard-code any module id. Keep the /forgot-password resolution working.

## THE GUARD - do not regress the hosts that already work
The acceptance gate asserts that EVERY tenant host's /login ENDS at a sign-in page. 9 hosts pass today
and must keep passing: baronledger.test, zapattendance.test, wms.test, harpp.test, ehr.test, moto.test,
akiracms.test, palsystem.test, dccafe.test. Only juliesbakeshop.test, cmsnew.test and aiss.test are red.
Note wms and harpp currently pass via a 302 onward to /<module>/login - keep them working; if they end
up resolving directly instead, that is still fine as long as the gate stays green.

## ORACLE
Extend the existing test tests/tenant_login_entry_module_routing_test.php with DISCRIMINATING cases:
  - an explicit /login for a module that exposes BOTH a root route and a /login route resolves to the
    /login route, not the root (fails before this fix)
  - the "/" landing for that same module keeps its current result (a PIN - label it as a pin)
Keep every existing case green, including the R2 pins for the other shouldSkipRewrite exemptions.
A test that writes to storage/logs/app.log must restore it to its pre-test bytes.

## VERIFY (this is the point of the lane - a rewrite-level assertion missed this)
  bash tools/lane-tenant-login-all-hosts-acceptance.sh   -> must exit 0, all 12 hosts reaching a
  sign-in page with no loop. This gate asserts the OPERATOR-VISIBLE OUTCOME over real HTTP, not the
  internal rewrite, which is exactly the level the previous lane's tests got wrong.
Then: php -l each changed file; php tests/tenant_login_entry_module_routing_test.php;
check BOTH storage/logs/app.log and storage/logs/error.log (error.log must be 0 bytes).

Report status PASS | PARTIAL | BLOCKED; files changed; tests added/changed; per-case discrimination;
and anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/tenant-login-auth-route-preference
rc=$?
echo "lane: tenant-login-module-auth-route-preference — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
