#!/usr/bin/env bash
#
# Lane: settings-contract-char — Gate 1 (A1) of the 6.3 Platform Truth plan:
# CHARACTERIZE the settings contract. Expands tests/manifest_settings_defaults_test.php
# with fleet-wide field invariants + checker self-tests + stored-value round-trip
# characterization. NO behavior change anywhere; tests only.
#
# Criterion (fails on base: the marker section does not exist yet):
#   grep -q "SETTINGS CONTRACT CHARACTERIZATION" tests/manifest_settings_defaults_test.php
#   && php tests/manifest_settings_defaults_test.php | grep -q "FAIL: 0"

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local; production is MySQL 5.7.

# TASK
Gate 1 of the Kernel OS 6.3 "Platform Truth" plan: CHARACTERIZE the settings contract.
Extend the EXISTING test file tests/manifest_settings_defaults_test.php with a new section
marked exactly `SETTINGS CONTRACT CHARACTERIZATION`. Tests only — zero behavior change.

# READ FIRST
- tests/manifest_settings_defaults_test.php (the file you will extend; note its helpers:
  manifestDefaults(), restoreTenantModuleSetting(), the tenant resolution and the summary format).
- docs/kernel/kernel-os-6.3-platform-truth-plan-2026-10-12.md (Track A, Gate 1 intent: establish
  existing behaviour BEFORE any normalization).

# MEASURED FACTS (2026-10-12 fleet scan — do not re-derive, but you may re-verify cheaply)
- 70 modules scanned; 27 declare settings_fields; 390 fields total.
- Type histogram: text:168, checkbox:77, select:61, number:55, textarea:16, email:5, password:4, bool:3, time:1.
- Duplicate keys: 0. Missing `type`: 0. Missing `default`: 21. select without `options`: 14.
- Handler coercion (src/http/admin-handlers.php:2810-2845 and the superadmin equivalent):
  checkbox/bool/boolean -> (bool); number/int/integer -> numeric string; select WITH options ->
  must be one of the option values else ignored; everything else -> trim(string).
- Storage: saveTenantModuleSettings() json_encodes each value into tenant_module_settings;
  read path returns json_decode('false') === false, and absent keys stay ABSENT (no merging
  with defaults in the kernel read path).

# REQUIRED ADDITIONS (all inside tests/manifest_settings_defaults_test.php)

## 1. Live-manifest checks (must PASS against today's fleet)
A) For every settings-bearing module: keys are non-empty and UNIQUE within the module.
B) For every field: declared `type` is one of: text, textarea, email, time, number, select,
   checkbox, bool, password. (Every field declares one today; keep it that way.)
C) INFORMATIONAL echo lines (NOT failures): count of fields lacking a `default`, and count of
   select fields lacking `options` — with the current numbers (21 and 14) visible in the output
   so drift is noticed by a human, not by a red suite.

## 2. Checker self-tests (must have teeth — MUST-ALLOW + MUST-REFUSE against CRAFTED fixtures)
Write small local helper functions in the test file (e.g. settingsFixtureFindings(array $fields): array)
that detect: (i) duplicate keys, (ii) empty key, (iii) select whose declared default is not among
its option values. Then test the helpers against crafted fixture arrays:
- MUST-ALLOW: a clean fixture yields zero findings.
- MUST-REFUSE: duplicate-key fixture -> flagged; empty-key fixture -> flagged;
  select-bad-default fixture -> flagged.
A checker that cannot flag crafted defects is unproven; a checker that flags a clean fixture is broken.

## 3. Stored-value round-trip characterization (tenant DB; MUST CLEAN UP)
On the test tenant, using the existing save/restore patterns in this file (see
restoreTenantModuleSetting — reuse it, including its "no original -> DELETE" branch):
- Save probe keys prefixed __g0_ : false (boolean), 0 (int), "" (empty string), "42" (numeric string), null.
- Read back and assert EXACT types preserved: false stays false (and is NOT conflated with "" or 0),
  "" stays "", "42" stays the string "42", null stays null.
- Then remove all probe rows and assert the keys are ABSENT after removal — document in the output
  the distinction: "absent" (no row) vs "false" (row with JSON false) are different states.
- The suite must be re-runnable: run it twice consecutively and both runs must be green (proof the
  cleanup is complete).

# CONSTRAINTS
- You may change ONLY tests/manifest_settings_defaults_test.php. Do NOT touch module.json files,
  handlers, kernel code, or any other test.
- Keep all existing checks passing (currently: PASS: 34 FAIL: 0).
- Do NOT git add / git commit; leave the tree dirty for the harness.
- No new dependencies. MySQL 5.7 compatible (tests talk to the local DB as the existing file does).

# VERIFY (report the verbatim result lines)
1. php tests/manifest_settings_defaults_test.php        -> PASS: N FAIL: 0 (N > 34)
2. php tests/manifest_settings_defaults_test.php        -> second consecutive run, same result
3. php -l tests/manifest_settings_defaults_test.php     -> No syntax errors
4. grep -c "__g0_" tests/manifest_settings_defaults_test.php  (expected: several — the probe keys exist)
5. After both runs: the probe rows are gone (select count from tenant_module_settings where setting_key like '__g0_%' = 0) — show the query you used

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file:line each
design_calls: how you detect the three defect classes; how you guarantee cleanup; what the informational counters print
verification: the five commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-settings-contract-char
