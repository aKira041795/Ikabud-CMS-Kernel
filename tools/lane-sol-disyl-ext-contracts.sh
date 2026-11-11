#!/usr/bin/env bash
#
# Lane: sol-disyl-ext-contracts - slice D: the extension consumes the CLI contracts.
#
# Until now extension.ts hardcodes DISYL_FILTERS (22 real entries), GOVERNED_COMPONENTS (31)
# and regex-parses human lint output (parseLintOutput, cyclomatic complexity 28).
# Measured drift: 17 working filters missing, 4 components missing (incl ikb_entity_view),
# and one PHANTOM filter advertised (`json_attr` is not in FilterRegistry at all).
#
# Both contracts now exist and are verified:
#   php ikabud disyl:describe --json  -> filters(38) components(35) constructs(41)
#   php ikabud disyl:lint <f> --json  -> diagnostics[] with stable DISYL_* codes, 0-based lines

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Make the DiSyL VS Code extension consume the two CLI JSON contracts instead of hardcoded
arrays and regex-parsed human output.

Read docs/disyl/editor-tooling-plan.md (slice D) first.

# VERIFIED CONTRACT SHAPES (run these yourself, do not trust the text)
  php ikabud disyl:describe --json
    { "schemaVersion":1, "tool":"disyl:describe",
      "filters":[ "<name>", ... ],           // 38, from FilterRegistry::names()
      "components":[ "ikb_section", ... ],   // 35, from ComponentRenderer's catalogue
      "constructs":[ {"id":..,"kind":..}, ..] }
  php ikabud disyl:lint <file> --json
    { "schemaVersion":1, "tool":"disyl:lint", "status":"ok|invalid",
      "diagnostics":[ { "file":.., "range":{"start":{"line":0-based,"character":0},...},
                        "severity":"error|warning", "code":"DISYL_*", "message":.. } ] }
  NOTE: range.start.line is 0-BASED (VS Code convention). Do not add 1.

# MEASURED DRIFT THIS MUST FIX (do not preserve it)
- extension.ts DISYL_FILTERS has 22 usable filters; the registry has 38 ->
  17 are missing from completion (esc_html, esc_attr, esc_url, esc_js, capitalize, title,
  count, join, abs, round, floor, ceil, unique, split, replace, base64, md5).
- extension.ts advertises `json_attr`, which is NOT a registered filter. Suggestion must stop.
- extension.ts GOVERNED_COMPONENTS has 31; the rendering catalogue has 35 ->
  missing ikb_component, ikb_entity_view, ikb_region, ikb_slot.

# WHAT TO BUILD
1. NEW `extensions/disyl-lsp/src/disylCli.ts` - PURE, unit-testable, no `vscode` import:
   - `parseDescribe(stdout: string): { filters: string[]; components: string[] }`
     Accept filters/components as strings or objects exposing `name`. Throw a clear Error on
     invalid JSON or a schemaVersion !== 1.
   - `parseLint(stdout: string): Array<{ line: number; character: number; severity: 'error'|'warning'; code: string; message: string }>`
     Reads `diagnostics[].range.start.{line,character}`, severity, code, message.
     Throw on invalid JSON / wrong schemaVersion.
2. Rewire `extensions/disyl-lsp/src/extension.ts`:
   - DELETE the hardcoded `DISYL_FILTERS` and `GOVERNED_COMPONENTS` arrays. Load them from
     `disyl:describe --json` once at activation (lazy is fine) and fall back to an empty list
     with a warning if the CLI fails - never to a stale hardcoded copy.
   - Replace the regex `parseLintOutput` path for `--json` data with `parseLint`. Keep the
     in-process `validator.ts` (instant syntax fallback) exactly as it is.
   - Filter completion must still filter by the typed prefix, and must only offer REAL filters
     (so `json_attr` disappears).
3. NEW `.ai/tmp/disyl-ext-test/test-cli-parser.js` - a plain Node script (no framework) that
   requires the COMPILED `extensions/disyl-lsp/out/disylCli.js` and asserts, feeding REAL CLI
   output captured by shelling out:
   - parseDescribe on live `describe --json` -> 38 filters incl `esc_html`, 35 components incl
     `ikb_entity_view`, and NO `json_attr`.
   - parseLint on live `lint --json` for `.ai/tmp/disyl-json-fixture.disyl` -> one diagnostic,
     code `DISYL_UNCLOSED_BLOCK`, severity error, line === 1 (0-based for human L2).
   - both throw on `"{}"` and on `{"schemaVersion":2}`.
   Print PASS/FAIL per assertion and exit non-zero on any failure.
   (A `vscode` stub already exists at `.ai/tmp/disyl-ext-test/vscode-stub.js`; disylCli.ts must
   not need it - that is the point of keeping the parsers pure.)

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  cd extensions/disyl-lsp && ./node_modules/.bin/tsc -p ./ && node ../../.ai/tmp/disyl-ext-test/test-cli-parser.js
It must exit 0. (It fails now: disylCli.ts and the test do not exist.)

# ALSO REQUIRED
- `node --check` every compiled out/*.js you touched.
- No regressions: php tests/disyl_parity_test.php (195/195),
  php tests/disyl_engine_test.php (Result: 348 passed),
  php tools/disyl-conformance-check.php (promoted=41 partial=0, disagreements: none).
- Do NOT change any PHP behaviour. This lane is extension-side only.
- Do NOT commit or push. Leave modules/healthcare and modules/sms untouched.

# SCOPE
- Edit ONLY: extensions/disyl-lsp/src/disylCli.ts (new), extensions/disyl-lsp/src/extension.ts,
  extensions/disyl-lsp/out/* (build output), .ai/tmp/disyl-ext-test/ (new test),
  docs/disyl/editor-tooling-plan.md
- If a hardcoded list cannot be removed without breaking a provider, STOP and report BLOCKED
  with the specific provider.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
modules_added: the exported signatures of disylCli.ts
removed_lists: confirm DISYL_FILTERS and GOVERNED_COMPONENTS arrays are gone, and what replaced them
phantom_filter: confirm `json_attr` can no longer be suggested
acceptance: the command output and exit code
test_assertions: each assertion the Node test makes and its result
suites: the three summary lines
not_done: anything deliberately left (e.g. source/view completion, which is slice C)
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-ext-contracts
