#!/usr/bin/env bash
#
# Lane: sol-disyl-script-escape - close the last failing engine test.
#
# The failing test is STALE, not the code. `escapeScriptValue()` is deliberate
# anti-XSS hardening (documented "escape only script/style breakout and
# literal-delimiter bytes", with |raw as the escape hatch). Weakening it to make
# an old expectation pass would be a security regression, so the test is what
# moves - and the hardening gets pinned so nobody quietly removes it later.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# CONTEXT: this is the last failing test in tests/disyl_engine_test.php
  Command: php tests/disyl_engine_test.php
  Result:  347 passed, 1 FAILED (out of 348)

  ✗ variables in script are raw (no HTML-escape)
    Expected: "<script>const x = \"Tom & Jerry\";</script>"
    Actual:   "<script>const x = \"Tom \\u0026 Jerry\";</script>"
  Case: $engine->renderString('<script>const x = "{title}";</script>', ['title' => 'Tom & Jerry'])

# THE ANALYSIS YOU MUST NOT GET BACKWARDS
`\\u0026` is NOT a leak or a bug. kernel/DiSyL/TemplateEngine.php::escapeScriptValue()
(line ~1347) deliberately escapes the script/style breakout and literal-delimiter bytes
  \\  <  >  &  '  "
as unicode escapes, and its own docblock says: "Escape only script/style breakout and
literal-delimiter bytes." processScriptVariables() (line ~1360) documents: "Values use
script-literal escaping by default; |raw restores the legacy output."

Inside a JS string literal `\\u0026` IS `&`, so runtime behaviour is unchanged - the page
behaves identically while being resistant to `</script>` breakout. This is deliberate
hardening that a stale test never caught up with.

DO NOT weaken escapeScriptValue() to satisfy the old expectation. Removing those
escapes is a security regression and will fail the task.

# TASK
1. Update the stale expectation in tests/disyl_engine_test.php so it asserts the HARDENED
   contract: the script-context value renders as `Tom \\u0026 Jerry`.
2. ADD tests that PIN the hardening, so a future "cleanup" cannot silently remove it:
   - each of the delimiter bytes (\\ < > & ' ") is escaped in script context
   - a value containing the literal sequence `</script>` cannot break out of the block
   - `|raw` restores the legacy unescaped output (the documented escape hatch)
   - the same value is HTML-escaped, not script-escaped, in normal HTML context
3. Rename the test so its name states the real contract (it currently claims "raw",
   which is the opposite of what is asserted). Keep it in the same style/section.

# OPTIONAL - only if you can PROVE it with a test
While analysing the above you may notice that escaping `"` as `\\u0022` is only valid
INSIDE a JS string literal; emitted bare (e.g. <script>const x = {title};</script> with
title='abc') it could produce a JS syntax error. If - and only if - you can demonstrate
that with a failing case, note it in your report. Do NOT change the escaping to fix it
in this lane; report it as a finding for a separate decision.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php tests/disyl_engine_test.php 2>&1 | grep -qE 'Result: 348 passed'
It must exit 0 when you are done.

# ALSO REQUIRED
- php tests/disyl_parity_test.php must stay at 195/195 passed.
- php tools/disyl-conformance-check.php must stay promoted=41 partial=0, disagreements: none.
- php -l on every file you touch.

# SCOPE
- Edit ONLY tests/disyl_engine_test.php.
- You may READ kernel/DiSyL/* freely to justify the contract.
- If you believe kernel code must change, STOP and report BLOCKED with the evidence and
  why the hardening is wrong - do not weaken it unilaterally.
- Do not commit or push. Leave modules/healthcare and modules/sms untouched.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
contract: one sentence stating what script-context escaping guarantees
changed_files: list
tests_changed: the rename plus each newly added pin
acceptance: the command output and exit code
suites: the parity and conformance summary lines
finding: the optional `\\u0022`-bare-emission observation, with evidence or "not demonstrated"
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-script-escape
