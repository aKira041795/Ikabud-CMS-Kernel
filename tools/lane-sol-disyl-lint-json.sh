#!/usr/bin/env bash
#
# Lane: sol-disyl-lint-json - slice A of docs/disyl/editor-tooling-plan.md
#
# Adds `--json` to `php ikabud disyl:lint` with stable rule codes, so the VS Code
# extension can stop regex-parsing human output (parseLintOutput, cyclomatic
# complexity 28) and an AI author can loop against a machine-readable signal.
#
# ADDITIVE ONLY: the human output must stay byte-identical. This lane adds a surface;
# it does not change any lint CHECK behaviour.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Add a `--json` mode to `php ikabud disyl:lint` in the `ikabud` CLI script.

Read docs/disyl/editor-tooling-plan.md first (slice A). The rationale: the VS Code extension
currently shells out and regex-parses human text with cyclomatic complexity 28, so a wording
change silently breaks editor diagnostics; and an AI authoring agent needs a machine-readable
signal to self-correct instead of guessing.

# WHERE THE CODE IS
- `ikabud`, case `'disyl:lint'` (around line 7405), ending around line 7950 before
  `case 'disyl:list-sources'`.
- Issues are built as: ['type' => 'error'|'warning', 'line' => int, 'msg' => string, 'file' => string]
  There are exactly 10 `$issues[] =` sites. Each corresponds to a distinct CHECK.
- The command calls `banner()` at the start and `out()` throughout, then exits
  `$totalErrors > 0 ? 1 : 0`.

# HARD REQUIREMENTS

1. **`--json` writes ONLY the JSON payload to stdout.** `banner()` prints box art and `out()`
   prints coloured text; both must be suppressed in JSON mode, or the payload is unparseable.
   Send anything non-payload to STDERR.

2. **Envelope** (exactly these keys):
   {
     "schemaVersion": 1,
     "tool": "disyl:lint",
     "status": "ok" | "invalid",
     "diagnostics": [ { ... } ]
   }
   - `status` is "invalid" when at least one diagnostic has severity "error", else "ok".
   - `diagnostics` is always an array — empty when the file is clean, never absent.
   - Include WARNINGS as well as errors; do not filter to errors only.

3. **Each diagnostic**:
   {
     "file": "<path relative to repo root>",
     "range": { "start": { "line": <int>, "character": 0 },
                "end":   { "line": <int>, "character": 0 } },
     "severity": "error" | "warning",
     "code": "DISYL_...",
     "message": "<the existing human msg text>"
   }
   - **`range.start.line` is 0-BASED** (VS Code / LSP convention) while the human output stays
     1-based as it is today. State this in your report; getting it wrong is a silent off-by-one.
   - `character` is 0 for both ends for now: the analyzer tracks LINE ONLY and does not record
     columns. Do not fabricate a column. Document this limitation in the help text.
   - `code` must be a STABLE identifier derived from the CHECK, never from the message wording.
     Add a `code` to ALL 10 `$issues[]` sites. Suggested codes (rename if a check is better
     described differently, but keep the `DISYL_` prefix and the stability rule):
       DISYL_UNCLOSED_BLOCK, DISYL_EXTRA_CLOSE_TAG, DISYL_ELSEIF_OUTSIDE_IF,
       DISYL_UNCLOSED_COMPONENT, DISYL_INCLUDE_NOT_FOUND, DISYL_LAYOUT_NOT_FOUND,
       DISYL_EMPTY_CONTROL_BODY, plus codes for the remaining sites you find.

4. **Human output is byte-identical when `--json` is absent.** Do not reword, recolour, reorder,
   or change counts. This is the contract every existing caller relies on.

5. **Exit codes are unchanged**: 1 when there is at least one error, 0 otherwise — including in
   `--json` mode, so CI semantics do not shift.

6. `--json` with no path argument (lint ALL templates) must also emit valid JSON.

7. Add `--json` to the `disyl:lint` help line (near line 1060) and record what shipped under
   slice A in docs/disyl/editor-tooling-plan.md.

# DO NOT
- Do not change any lint CHECK logic. This slice adds a serialisation surface only. If a check
  looks wrong, report it; do not fix it here.
- Do not add columns/spans. That is a separate slice and requires the analyzer to track them.
- Do not touch the extension, modules/healthcare, or modules/sms.
- Do not commit or push.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php
The fixture `.ai/tmp/disyl-json-fixture.disyl` contains an unclosed `{if}` (reported today as
"L2 error: 1 unclosed {if} tag(s)"). The checker validates the envelope, the code format
(/^DISYL_[A-Z0-9_]+$/), severity, and that range.start.line is an int. It must exit 0.

# ALSO VERIFY AND REPORT (the gate does not cover these)
  # human output unchanged
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl | grep -q 'unclosed' ; echo "human_rc=$?"
  # exit code 1 in json mode when errors exist
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json >/dev/null 2>&1 ; echo "exit_rc=$?"
  # clean file still yields valid JSON with an empty diagnostics array
  #   (create your own clean fixture; status must be "ok")
  # lint-all mode still emits valid JSON
  php ikabud disyl:lint --json 2>/dev/null | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);' ; echo "all_rc=$?"
  # no regressions
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)

# SCOPE
- Edit ONLY: `ikabud`, `docs/disyl/editor-tooling-plan.md`, and `.ai/tmp/*` fixtures/tests.
- If the 10 issue sites cannot all get a stable code without changing check behaviour, STOP and
  report BLOCKED with the specific site.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
command_shape: how --json suppresses banner/out and where the payload is written
codes: every check site -> its stable code
line_convention: confirm 0-based range.start.line, 1-based human output, character 0 (why)
acceptance: the checker output and exit code
extra_verifications: human_rc, exit_rc, clean-file result, all_rc
suites: the three summary lines
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-lint-json
