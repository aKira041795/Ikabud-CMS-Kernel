#!/usr/bin/env bash
#
# Lane: sol-disyl-sources - slice C: entity source/view contracts + context completion.
#
# The highest-volume authoring task is "make a page that lists X", answered by
# {ikb_entity_list source="..." view="..."}. 62 such tags exist and NEITHER the source
# nor the view name is completable today - they are invisible, which is the single
# biggest authoring gap for a mid-level author or an AI writing templates.
#
# Authority: EntityViewResolver::registeredViewContracts() (kernel/EntityContext/).

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Slice C of docs/disyl/editor-tooling-plan.md: make entity `source` and `view` names
discoverable, and complete them in the editor.

# PART 1 - CLI contract (gate)
Extend `php ikabud disyl:list-sources` in `ikabud` with `--json`:
  {
    "schemaVersion": 1,
    "tool": "disyl:list-sources",
    "sources": [ { "name": "<source>", "views": ["compact","table", ...] }, ... ]
  }
- Preserve the existing human output exactly when `--json` is absent.
- `--json` must write ONLY JSON to stdout (suppress `banner()`/`out()`; slices A and B
  already solved this - reuse the same approach, do not invent a second one).
- Views must come from EntityViewResolver::registeredViewContracts()
  (kernel/EntityContext/EntityViewResolver.php:~609), NOT a new hardcoded list. Read that
  method first and report exactly what shape it returns; if it is keyed in a way that does
  not map cleanly to source -> views, say so and adapt rather than guessing.
- A source with no registered views must still appear with `"views": []`.
- At least one source must end up with at least one view, or the contract checker fails.

# PART 2 - extension completion
In extensions/disyl-lsp:
- Add completion for the attribute values `source="` and `view="` inside `{ikb_entity_list ...}`
  (and `{ikb_entity_detail ...}` if it takes the same attributes - check the renderer).
- `view` completion must be filtered by the `source` already written on that tag when it can
  be determined from the current line.
- Reuse the pure-parser module `src/disylCli.ts` (already exists, created in slice D) by adding
  `parseSources(stdout)` with the same defensive style (throw on invalid JSON / schemaVersion != 1).
- Do NOT reintroduce hardcoded source or view names anywhere.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php ikabud disyl:list-sources --json 2>/dev/null | php .ai/tmp/disyl-sources-contract.php

# ALSO REQUIRED
- Add `.ai/tmp/disyl-ext-test/test-source-parser.js` (plain Node, no framework) that shells out
  to the LIVE CLI (`spawnSync('php', ['ikabud','disyl:list-sources','--json'])`), requires the
  COMPILED `out/disylCli.js`, and asserts: parseSources returns >=1 source, every entry has an
  array of views, at least one has a non-empty view list, and it throws on `{}` and on
  `{"schemaVersion":2}`. Print PASS/FAIL per assertion; exit non-zero on any failure.
  Run it as part of your verification and report its output.
- `cd extensions/disyl-lsp && ./node_modules/.bin/tsc -p ./` must succeed; `node --check` every
  compiled out/*.js you touched.
- No regressions:
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php

# SCOPE
- Edit ONLY: `ikabud`, extensions/disyl-lsp/src/disylCli.ts, extensions/disyl-lsp/src/extension.ts,
  extensions/disyl-lsp/out/*, .ai/tmp/disyl-ext-test/*, docs/disyl/editor-tooling-plan.md
- Do NOT change PHP rendering behaviour, modules/healthcare, or modules/sms.
- Do not commit or push.
- If registeredViewContracts() cannot be mapped to source -> views without changing kernel
  behaviour, STOP and report BLOCKED with what it actually returns.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
views_source: what registeredViewContracts() returns (shape), and how you mapped it
sources_found: count, and how many have views
completion_wired: which attributes now complete, and whether view is filtered by source
acceptance: the checker output and exit code
parser_test: the Node test output
suites: each summary line
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-sources
