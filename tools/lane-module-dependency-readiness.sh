#!/usr/bin/env bash
#
# Lane: module-dependency-readiness (Sol leads)
#
# OWNER: "parent modules with which other parent modules can use thru capability bus. This is
# not well defined with the ikabud kernel. It's high time this is properly managed."
#
# CHAIR (answer D/E, .ai/consult/kernel-ownership-capability.md):
#   - depends becomes [{id, required}]; legacy string entries migrate as required.
#   - The existing @1 suffix IS the major contract version; no general semver ranges yet.
#   - Three outcomes: ready / provider-unavailable / authorization-denied -> BLOCKED when the
#     dependency is required, DEGRADED when optional. "The kernel reports reasons; the
#     application owns fallback behavior."
#   - "Reuse existing suite validation and capability-bus decisions; do not write parallel
#     policy logic inside the resolver."
#   - Avoid automatic provider activation, recursive installation, runtime dependency
#     substitution, and elaborate dependency solvers.
#
# WHY IT MATTERS NOW: kernel/Capabilities/ has zero readiness/reason-code machinery (verified
# 2026-10-11). Nothing can answer "why is this dependency unsatisfied", and depends cannot
# distinguish "cannot work without" from "better with".
#
# ACCEPTANCE (authored before dispatch, fails on this tree: "0 passed, 2 failed"):
#   php tests/module_dependency_readiness_test.php   -> "Result: N passed, 0 failed", exit 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a KERNEL contract in the Ikabud repo at /var/www/html/applicationostest.
Implementation, not review. The design is settled; implement it exactly.

PHP 8.5 locally; production is Bluehost MySQL 5.7. No new dependencies.

## WHAT EXISTS TODAY (read these first)

- src/helpers/module-manager.php:2285 `validateModuleCapabilities()` — requires every entry of
  `capabilities.depends` to be a STRING with a valid capability id, and returns
  ['ok','exposes','depends','policy'].
- src/helpers/module-manager.php around 5490-5535 (inside `installModuleFromZip`) — after a
  successful install it iterates `$capCheck['depends']`, and if ANY capability is not
  `app()->capabilities()->has($capId)` it leaves the module DISABLED with 'missing'.
- kernel/Capabilities/CapabilityBus.php:182 `call()` — the real dispatch order is:
  `registry->resolve()` -> `registry->providers()` -> `applyPolicy($capabilityId, $providers,
  $callerModule)` -> `applyAuthorizationRegistry($capabilityId, $providers, $caller, $options,
  $provider)`. BOTH must pass. Use those same two public methods; do not reimplement policy.
- kernel/Capabilities/CapabilityCatalog.php — read-model; `inspect($capabilityId)` returns
  provider counts and provider entries.
- `app()->capabilities()` is the bus.

## WHAT TO BUILD (in src/helpers/module-manager.php)

### 1. moduleCapabilityRequirements(array $manifest): array

Normalises `capabilities.depends` into a list of ['id' => string, 'required' => bool].

- a plain string entry            -> required: true
- ['id' => X, 'required' => true] -> required: true
- ['id' => X, 'required' => false]-> required: false
- ['id' => X]  (no 'required')    -> required: TRUE  (omitting it must give the SAFE answer)
- anything malformed (no id, non-string id, invalid capability id) -> DROPPED, never trusted
- order preserved; no duplicates dropped silently - keep them, the caller sees what was declared

### 2. moduleDependencyReadiness(array $manifest, array $options = []): array

Read-only. Returns:

    [
      'module_id' => string,
      'state' => 'ready' | 'degraded' | 'blocked',
      'requirements' => [
        ['id' => string, 'required' => bool,
         'state' => 'ready' | 'provider_unavailable' | 'authorization_denied',
         'reason_code' => string,          // same vocabulary as state, plus 'ready'
         'detail' => string,               // one human sentence
         'provider_count' => int],
        ...
      ],
    ]

Options: 'caller_module' (string, defaults to the manifest id) - the identity whose permission
to call is being evaluated. Never read a caller id from a payload; this is a server-side probe.

Decision rules, in this order, per requirement:
 1. No runtime provider registered for the capability -> state 'provider_unavailable',
    reason_code 'provider_unavailable'. Obtain this from the same source dispatch uses
    (the capability registry via the bus/catalog), not from manifest text.
 2. Otherwise evaluate authorization EXACTLY as dispatch does: `applyPolicy()` then
    `applyAuthorizationRegistry()` on the provider list for that caller. If either leaves no
    provider -> state 'authorization_denied', reason_code 'authorization_denied'.
 3. Otherwise 'ready'.

Aggregate state:
 - any requirement with state !== 'ready' AND required === true   -> 'blocked'
 - otherwise any requirement with state !== 'ready'               -> 'degraded'
 - otherwise                                                      -> 'ready'

Rules:
 - MUST NOT throw. A bus/registry failure for one capability becomes provider_unavailable for
   that requirement with the exception message in `detail`. Readiness must never take a page down.
 - MUST NOT change any authorization outcome, register anything, enable anything, or call a
   capability. It only reads decisions.
 - MUST agree with dispatch: if readiness says ready, a dispatch as the same caller must not be
   denied. If you cannot evaluate authorization, report 'authorization_denied' rather than ready
   (an unproven permission is not a permission).

### 3. validateModuleCapabilities() must accept the object form

Keep returning the SAME shape and error codes for everything that worked before. Add:
 - object entries are valid when they carry a valid 'id' and, if present, a boolean 'required';
 - a non-boolean 'required' is an error: 'Invalid capabilities.depends entry';
 - the returned 'depends' MUST remain a flat list of capability-id STRINGS for backward
   compatibility (existing callers iterate strings); normalised requirements are available from
   moduleCapabilityRequirements().
 - Add the normalised list under a new key 'requirements' so callers that want it do not have to
   parse twice.

### 4. Install must honour required vs optional

In installModuleFromZip()'s post-install enablement block, ONLY a missing REQUIRED dependency may
leave the module disabled. A missing OPTIONAL dependency must ENABLE the module and report it:
 - keep 'missing' for required-missing (existing callers rely on it),
 - add 'degraded' => [ids] and set 'warning' text naming the optional ones,
 - do not change the return keys that already exist, and do not change behaviour when every
   dependency is present.

Nothing else in the install path may change. Do not touch modules/cms/**.

## HARD CONSTRAINTS

- No dispatch behaviour change: no new authorization, no auto-activation, no dependency solver,
  no provider substitution, no semver ranges. The @1 suffix is the version.
- Do not edit kernel/Capabilities/CapabilityBus.php or CapabilityAuthorizationRegistry.php.
- Do not edit src/helpers/module-ownership.php or src/helpers/updates.php.
- Keep `php -l` clean. Docblock every new function, stating what it REFUSES and why.

## VERIFY BEFORE YOU REPORT

    php tests/module_dependency_readiness_test.php     # must end: Result: N passed, 0 failed
    php -l src/helpers/module-manager.php
    php tests/module_ownership_resolver_test.php       # 38 passed
    php tests/module_install_provenance_test.php       # 23 passed
    php tests/module_update_from_zip_test.php          # 30 passed
    php tests/module_scan_cache_test.php               # 19 passed
    php tests/cms_module_admin_surface_test.php        # 28 passed

tests/infrastructure_test.php is ALREADY broken on HEAD (never requires module-manager, so
moduleInstallTargetDirForId() is undefined at line 313). Do not fix it.

## REPORT - tight

    STATUS: PASS | PARTIAL | BLOCKED
    CHANGED: files + line ranges
    CONTRACT NOTE: how readiness reuses dispatch's two policy functions instead of copying them
    EVIDENCE: verbatim final line of the acceptance test + the readiness of a real module
    GATES: what it refuses, one line each
    UNVERIFIED: anything you could not check
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/module-dependency-readiness
rc=$?
echo "lane: module-dependency-readiness — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
