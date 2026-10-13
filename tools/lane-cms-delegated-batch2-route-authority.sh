#!/usr/bin/env bash
#
# Lane: cms-delegated-batch2-route-authority — 6.3 Authority Enforcement Coverage SLICE 12.
# Declare the remaining cms capability-modules-decide routes in DELEGATED mode
# ({"mode":"module-decides","capability":"<cap>"} — no allowed_roles; zero dispatch credit):
#   settings.manage    x6  POST /api/v1/cms/settings/reset, /permissions, /permissions/reset,
#                          /themes/upload, /themes/activate, /themes/{slug}/delete
#   customizer.manage  x3  POST /api/v1/cms/customizer/presets/apply,
#                          /api/v1/cms/customizer/{scope}/{section}, /api/v1/cms/customizer/{section}
# Every gate is a statically-reachable `cmsRequireCap('<cap>')` at the top of the handler (verified).
#
# Criterion (fails on base: delegated_bound=1 — gate re-proves live at dispatch; ~164s scan):
#   scoped GovernanceCensus scan('cms') -> delegated_bound=10, delegated_unbound=0,
#   delegated_invalid=0, write_enforced=3, rc 0

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Kernel OS 6.3 Authority Enforcement Coverage — SLICE 12: declare the remaining cms capability-modules-decide routes in DELEGATED mode and prove census classification + runtime transparency end-to-end. Declarations + tests only; NO handler changes.

# CONTEXT (verified by the harness — use it, do not re-derive)
- Delegated mode (established slice 4b): declaration {"mode":"module-decides","capability":"<cap>"} with NO allowed_roles. GovernanceCensus reports these as delegated_bound when the capability literal is statically reachable from THIS handler via named calls (cmsRequireCap('<cap>') at handler top — verified for all nine targets). Delegated declarations carry ZERO dispatch credit; the module predicate stays the authority (chair ruling 2026-10-13).
- AuthorityDispatchGuard::decide() on a DELEGATED declaration: allowed=true, configured=false, reason=missing_authority_configuration — the guard makes NO dispatch decision (transparent pass-through), runtime pattern proven by tests/cms_delegated_settings_route_test.php (15/0 — READ IT FIRST and model your runtime sections on it).
- Capability ids: use exactly the literals the handlers pass to cmsRequireCap.
- Multi-batch pin rule: this is the newest cms batch suite and owns the exact 13-key full-set pin (3 slice-2 role routes + 1 slice-4b delegated route + your 9). The slice-4b suite tests/cms_delegated_settings_route_test.php contains an exact-4-key totality assertion ('DECLARATION: route surface is the three slice-2 role routes plus exactly one delegated route') that MUST be converted to a SUBSET pin of its 4 keys in this same change — that conversion IS authorized here (three files total). The harness-decided precedent is decisions 155/157/158/159 (identical stale-pin class; subset pin pattern: `array_diff($expected, array_keys($declared)) === []` with per-route exact checks unchanged).

# TARGET SET (9 routes, byte-exact keys; two capability groups)
settings.manage:
  POST /api/v1/cms/settings/reset
  POST /api/v1/cms/permissions
  POST /api/v1/cms/permissions/reset
  POST /api/v1/cms/themes/upload
  POST /api/v1/cms/themes/activate
  POST /api/v1/cms/themes/{slug}/delete
customizer.manage:
  POST /api/v1/cms/customizer/presets/apply
  POST /api/v1/cms/customizer/{scope}/{section}
  POST /api/v1/cms/customizer/{section}

# REQUIRED OUTCOME
1. modules/cms/module.json — ADD these 9 declarations to the EXISTING `capabilities.routes` map, each EXACTLY {"mode":"module-decides","capability":"<cap>"} (no allowed_roles). The 4 existing declarations stay EXACTLY as they are. Touch NOTHING else.
2. NEW tests/cms_delegated_batch2_route_test.php covering:
   a. REAL-MANIFEST pins: each of the 9 routes declared exactly {mode, capability}, no allowed_roles; the map contains EXACTLY the 13 keys (full-set pin — newest suite owns totality).
   b. GUARD TRANSPARENCY: per route (or a representative superset with a per-route loop), AuthorityDispatchGuard::decide() with a NON-admin actor (e.g. cms author) => allowed=true && configured=false && reason=missing_authority_configuration (delegated makes no dispatch decision).
   c. CENSUS CLASSIFICATION: run the scoped Census scan('cms') EXECUTABLE (require the class, call scan) and assert delegated_bound=10, delegated_unbound=0, delegated_invalid=0, dispatch_enforced=3. If a full scan is too slow for the test, assert via the census parser/classifier directly on the manifest arrays instead — but it MUST be executable evidence from real code, and say in the header why the scoped call was or wasn't used.
   d. RUNTIME CAPABILITY TEETH (executable evidence): prove the module predicate still decides — following the 4b runtime pattern (cmsRequireCap → capability registry), a caller WITHOUT the capability is refused by the handler path, a caller WITH it passes the capability gate. Use the fixture/cache-restore discipline of the 4b test (restore all state in finally). Where a route's full runtime path is impractical in-process, use the guard-transparency + capability-function-level proof and mark "not applicable: <reason>".
   e. PARITY: an undeclared cms write route remains permitted — e.g. POST /api/v1/cms/redirects with an editor actor => allowed && !configured. (Pick one that stays undeclared by future known batches.)
3. Convert the 4b suite tests/cms_delegated_settings_route_test.php exact-4 totality to a SUBSET pin (pattern above; keep its per-route exact checks and runtime sections untouched).
4. Test header must document the ROLLBACK FALSIFIER: if a delegated route's handler gate stops deciding (i.e. a caller the module predicate would admit gets refused at dispatch, or refusal no longer comes from the capability layer), roll back; no-deploy recovery KERNEL_AUTHORITY_DISPATCH_ENFORCE=false. Note: delegated declarations make NO dispatch decision by design — their safety comes from the handler predicate.

# CONSTRAINTS
- Change ONLY: modules/cms/module.json, the new test file, and the authorized 4b conversion. NO handler edits, NO kernel edits, NO other modules, NO other routes. Do not touch tools/ or .ai/. Do NOT git add / git commit.
- Deterministic output. Keep every existing suite green.

# VERIFY (report the verbatim result lines)
1. php tests/cms_delegated_batch2_route_test.php                                -> all green (state the check count)
2. bash tools/acceptance-cms-delegated-batch2-coverage.sh                       -> delegated_bound=10 write_enforced=3 delegated_unbound=0 delegated_invalid=0, rc 0 (~164s; run once)
3. php tests/cms_delegated_settings_route_test.php                              -> 15/0 (subset pin green)
4. slices stay green: gui 24/0; cms entity-capability 37/0; dl 46/0 + 134/0 (+ batch suites); harpp 66/0 + 84/0; ecommerce 34/0; aw 77/0 + 84/0 + 60/0
5. php tests/workbench_governance_census_test.php -> PASS rc=0; php tests/authority_dispatch_enforcement_test.php -> 4/0; php tests/capability_authority_audit_test.php -> 26/0
6. php tools/settings-contract-lint.php                                         -> exit 0
7. git status --porcelain
   -> exactly M modules/cms/module.json and the two test files (one new ??, one modified)
      (plus pre-existing untracked tools/*.sh harness scripts — expected, do not touch)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: delegated vs role mode rationale; census evidence (executable); guard transparency; capability teeth; parity route choice; the 4-key conversion; rollback falsifier restated
verification: the commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-cms-delegated-batch2
