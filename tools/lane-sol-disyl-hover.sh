#!/usr/bin/env bash
#
# Lane: sol-disyl-hover - close the last open item: component hover coverage.
#
# extension.ts holds a hardcoded hover map covering 31 of the 35 governed components.
# ikb_component, ikb_entity_view, ikb_region and ikb_slot resolve to NO hover at all.
# Patching four entries in would create a FOURTH drifting list - this workstream has
# already hit surface drift three times (components, filters x2).
#
# So: COVERAGE comes from the contract (disyl:describe --json), RICHNESS comes from the
# existing map treated as an enrichment source. A new component appears in hover
# automatically, even before anyone writes rich docs for it.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Give every governed DiSyL component hover documentation, driven by the contract rather
than a hand-maintained list.

# CURRENT STATE (verify it)
extensions/disyl-lsp/src/extension.ts contains a hover map covering 31 component names.
`php ikabud disyl:describe --json` returns 35 components. The 4 with no hover are:
  ikb_component, ikb_entity_view, ikb_region, ikb_slot
Hovering them today shows nothing.

# WHAT TO BUILD
1. NEW `extensions/disyl-lsp/src/componentDocs.ts` - PURE, no `vscode` import, unit testable:
     export function hoverForComponent(name: string, catalogue: Record<string, {category?: string, description?: string, attributes?: string[]}>): string
   Returns MARKDOWN that ALWAYS includes the component name.
     - when the catalogue has an entry: include category, description and the attribute list
     - otherwise: a short, HONEST fallback. It must say the component is governed/unknown-
       to-the-docs-rather-than-invented: do NOT fabricate attributes or a description.
   Never throw for an unknown name - hovering a typo must stay silent, not error.
2. Wire the existing hover provider in `extension.ts` to use it. The catalogue for the
   EXTENSION should be the enrichment map you already have, keyed by name; coverage comes
   from the `describe` component list loaded at activation (already done for completion in
   slice D). So a component present in describe but absent from the map still gets the
   honest fallback instead of nothing.
3. Do NOT delete the existing descriptions - they become enrichment, and must still render.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  node .ai/tmp/disyl-ext-test/test-hover-coverage.js
It uses the LIVE `describe --json` list and asserts: every component has non-empty hover
containing its name; the four previously-undocumented ones now have hover; rich hover
includes attributes when the catalogue supplies them; an unknown name does not throw.

# ALSO REQUIRED
- `cd extensions/disyl-lsp && ./node_modules/.bin/tsc -p ./` must succeed.
- `node --check` every compiled out/*.js you touched.
- Existing extension tests must still pass:
    node .ai/tmp/disyl-ext-test/test-cli-parser.js
    node .ai/tmp/disyl-ext-test/test-source-parser.js
- No PHP regressions: php tests/disyl_parity_test.php (209/209),
  php tests/disyl_engine_test.php (348 passed),
  php tools/disyl-conformance-check.php (promoted=41 partial=0).

# SCOPE
- Edit ONLY: extensions/disyl-lsp/src/componentDocs.ts (new),
  extensions/disyl-lsp/src/extension.ts, extensions/disyl-lsp/out/*,
  docs/disyl/editor-tooling-plan.md
- Do NOT edit .ai/tmp/* (the criteria). Outside your touches on purpose.
- Do NOT change any PHP file. Do not commit or push.
- Leave modules/healthcare and modules/sms untouched.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
coverage: how the describe list reaches the hover provider
fallback_text: the exact honest fallback wording for an undocumented component
rich_unchanged: confirm existing descriptions still render
acceptance: the checker output and exit code
existing_tests: the two parser test results
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-hover
