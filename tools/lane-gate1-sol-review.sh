#!/usr/bin/env bash
#
# Lane: gate1-sol-review — ADVERSARIAL REVIEW of the 6.3 Gate 0/1 night deliverables.
# Read-only. Sol must NOT commit or edit. The deliverable is a falsification report with evidence.
#
# Review lane, so no acceptance criterion to turn green.
#
# Scope (commits + working tree as found):
#   1. test(settings) A1 characterization — tests/manifest_settings_defaults_test.php (commit 44868d32)
#   2. docs+tooling (commit 072a1c97) incl. docs/kernel/6.3-settings-contract-spec-2026-10-12.md (now v0.2)
#   3. census-empty-routes fix — kernel/Workbench/Governance/GovernanceCensus.php + tests/workbench_governance_census_test.php
#   4. settings-contract-lint — tools/settings-contract-lint.php + tests/settings_contract_lint_test.php + baseline fixture
#   5. Gate 0 evidence: docs/kernel/kernel-os-6.3-platform-truth-plan-2026-10-12.md, spec v0.2

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, do NOT edit, do NOT fix. Report only, with evidence.
PHP 8.5 local; production MySQL 5.7.

# WHAT TO REVIEW (the night's work, 2026-10-12)
1. tests/manifest_settings_defaults_test.php — Gate 1/A1 settings-contract characterization
   (commit 44868d32). 34 -> 50 checks: fleet invariants (unique non-empty keys; closed type
   vocabulary), checker self-tests (MUST-ALLOW + 3 MUST-REFUSE crafted fixtures), stored-value
   round-trip distinctness (false/0/""/"42"/null) with cleanup.
   ATTACK: are any of the new checks vacuous? Does a check pass if the underlying behavior
   were broken? Is the cleanup complete under an exception mid-save? Do the must-refuse
   fixtures actually exercise the detector? Any hidden state left in tenant_module_settings?
2. kernel/Workbench/Governance/GovernanceCensus.php + tests/workbench_governance_census_test.php
   — fix for `"routes": []` (service-modules) which fataled scanAll() on the real fleet.
   ATTACK: is the normalization CONTENT-based (empty => no routes) rather than a TYPE-based
   skip (the C3b lesson)? Can a malformed/partial manifest now pass silently that used to
   throw? Are both directions tested (empty-array allowed; integer/object/non-empty-list
   refused)?
3. tools/settings-contract-lint.php + tests/settings_contract_lint_test.php +
   tests/fixtures/settings-contract-baseline.json — the advisory manifest linter.
   ATTACK: does it touch ANY runtime path (it must not)? Is output deterministic across
   runs and glob orders? Does it fail open or closed on baseline-missing? Can a crafted
   manifest with a duplicate key or unknown type pass? Does --update silently accept an
   invalid tree? Do legacy warnings (21 missing defaults / 14 select-no-options) stay
   non-failing for the REAL fleet?
4. docs/kernel/6.3-settings-contract-spec-2026-10-12.md (v0.2) — verify its claims against
   the code it cites (examples: admin-handlers.php:2810-2845 coercion branches;
   module-manager.php:1282-1318 reader; :1273-1276 invalidation; superadmin :799-801
   empty-secret skip). Flag any claim the code does not support. Check internal consistency
   with the rulings register (§7).
5. Cross-check the Gate 0 report numbers in docs/kernel/kernel-os-6.3-platform-truth-plan-2026-10-12.md
   vs the actual scan outputs: 70 modules / 27 with settings / 390 fields / types histogram /
   0 dups / 0 missing types / 21 missing defaults / 14 select-no-options; stored: 209 rows /
   string 155 / bool 50 / array 2 / int 2 / 51 empty strings. Reproduce what you can cheaply
   (re-run the linter, the census acceptance, the A1 test).

# FOR EACH FINDING
severity: red|amber|note
claim: what is wrong or unproven
evidence: the command you ran and its output lines, or file:line
falsified_by: the check that would have caught it (or the gap that shows none exists)

# ALSO ANSWER
- Which of the night's four deliverables would you REJECT if you had one vote, and why?
- Which single check, if it silently passed while the behavior were broken, worries you most?

# REPORT (exactly this shape)
status: REVIEW_COMPLETE
findings: (ordered by severity; empty findings is a valid — and suspicious — result; say what you attacked)
vote: accept|reject per deliverable (A1 test / census fix / linter / spec v0.2)
commands_run: list
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-gate1-sol-review
