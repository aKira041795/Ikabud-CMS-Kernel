#!/usr/bin/env bash
#
# Lane: settings-contract-lint — 6.3 Track A, the chair's "smallest next enforcement step":
# an ADVISORY, manifest-only settings contract linter + committed normalized baseline.
# Non-invasive: touches no runtime read/write path. Fails only on NEW unambiguously-invalid
# declarations or an unreviewed snapshot change; legacy classes warn.
#
# Criterion (fails on base: the linter does not exist):
#   php tools/settings-contract-lint.php   (exit 0 over the fleet; baseline committed)

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are working in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5 local.

# TASK
Build the settings contract linter, the chair-approved FIRST enforcement step of Kernel OS 6.3
Track A: advisory, manifest-only, non-invasive. It must touch NO runtime read/write path.

# DELIVERABLES
1. tools/settings-contract-lint.php — read-only, deterministic, no DB, no bootstrap. CLI:
     php tools/settings-contract-lint.php [--root=<dir>] [--update]
   Scans `modules/*/module.json` and `modules/*/*/module.json` for settings_fields and checks:
   - HARD FAIL (exit 1):
       * a field whose key is empty or duplicated within its module;
       * a field missing `type`, or with a type outside the CLOSED vocabulary:
         text, textarea, email, time, number, select, checkbox, bool, password;
       * SNAPSHOT MISMATCH: the normalized contract differs from the committed baseline
         (added/removed fields, type changes, default added/removed/changed, options changes).
         The message must say: "contract snapshot changed — review the diff; run --update to
         accept it in this commit if intentional."
   - WARNINGS ONLY (exit still 0): fields lacking a default (fleet baseline: 21); select fields
     lacking options (fleet baseline: 14; allowed legacy/dynamic exceptions — list them).
   - `--update` regenerates the baseline file; without it, mismatch fails.
   - Output must be deterministic (sorted) and human-readable; print counts summary + the
     baseline path. Exit 0 = ok/warnings; exit 1 = hard fail(s); exit 2 = usage/baseline missing.
2. tests/fixtures/settings-contract-baseline.json — generated with --update against the REAL
   fleet (do not hand-write it; run the tool).
3. tests/settings_contract_lint_test.php — fixtures in temp dirs (self-contained; copy the
   pattern of tests/workbench_governance_census_test.php: build fixture modules in a temp root,
   pass --root). MUST-ALLOW: clean fixture -> exit 0 with warnings only. MUST-REFUSE: new
   duplicate key -> exit 1; missing type -> exit 1; unknown type -> exit 1; changed default
   -> exit 1 until --update, then exit 0. A checker that cannot refuse crafted defects is
   unproven.
4. composer.json — add a script entry: "contract:lint": "php tools/settings-contract-lint.php"
   (scripts section only; do not touch dependencies).

# CONSTRAINTS
- Create ONLY the files listed; edit ONLY composer.json (scripts). NO changes to module.json
  files, kernel code, tests other than the new one. Do NOT git add / git commit.
- Deterministic output; no reliance on glob order. No new dependencies. MySQL-free.
- The linter validates DECLARATIONS only — it must say so in its header comment (declaration
  quality is not runtime compatibility; that gate is separate).

# VERIFY (report the verbatim result lines)
1. php -l tools/settings-contract-lint.php        -> No syntax errors
2. php tools/settings-contract-lint.php            -> exit 0, warnings only, on the real fleet
3. php tests/settings_contract_lint_test.php       -> all green (list count)
4. git status --porcelain                          -> shows exactly: the 3 new files + composer.json
5. Re-run 2 -> identical output (determinism)

# REPORT (exactly this shape)
status: PASS|FAIL|PARTIAL|BLOCKED
changed: file list
design_calls: exit-code mapping; snapshot normalization; how legacy warnings stay non-failing
verification: the five commands' result lines, verbatim
unresolved:
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-settings-contract-lint
