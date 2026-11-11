#!/usr/bin/env bash
#
# Lane: sol-disyl-describe-components - correct slice B's component source.
#
# Slice B shipped `describe.components` from ComponentRegistry::all(). That source is
# WRONG for editor completion, and the error is measurable:
#   - ComponentRegistry ALLOWS control tags (if, for, include) as "components"
#   - and OMITS components that demonstrably render (ikb_button, ikb_badge).
# Probe: {ikb_button}Save{/ikb_button} renders a real <button>; ComponentRegistry::has()
# returns false for it.
# ComponentRenderer::renderComponent() is the authority; its getKnownGovernedComponents()
# list already exists "for typo suggestions".
#
# Shipping the registry list into the extension would DELETE 13 working components from
# completion, including ikb_entity_view.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Correct the `components` list emitted by `php ikabud disyl:describe --json`.

Slice B (already shipped, do not rebuild it) sourced components from
`ComponentRegistry::all()`. That is the wrong authority. Replace the source.

# THE EVIDENCE (reproduce it yourself)
- `ComponentRegistry::all()` returns 25 entries and INCLUDES `if`, `for`, `include` —
  control tags, not components.
- `ComponentRegistry::has('ikb_button')` returns FALSE, yet
  `{ikb_button}Save{/ikb_button}` renders a real `<button>`. Same for `ikb_badge`.
  So the registry is partial metadata (used by the Visual Builder), not a validity gate.
- The rendering authority is `kernel/DiSyL/Component/ComponentRenderer.php` :
  `renderComponent()` holds the match block, and `getKnownGovernedComponents()`
  (around line 100) is an explicit list of known governed component names built
  "for typo suggestions" — exactly the completion use case. It currently appends any
  custom components from `$this->engine->internalComponents()`.

# WHAT TO DO
1. Expose the rendering catalogue from ComponentRenderer so the CLI can read it.
   `getKnownGovernedComponents()` is private and instance-based. Either add a public
   accessor that returns it, or expose the built-in list as a public constant and have the
   method append custom components. Keep the existing typo-suggestion behaviour working -
   do not delete or bypass `findClosestComponent()`.
2. Change `disyl:describe` to source `components` from that catalogue.
3. Leave `filters` (38, from FilterRegistry::names()) and `constructs` (41, from the
   inventory) exactly as they are - they are already correct.
4. The emitted list must contain ONLY component names: no `if`, `for`, `include`.

# EXPECTED RESULT
At least 34 built-in components including, at minimum:
  ikb_button, ikb_badge, ikb_entity_view, ikb_region, ikb_slot, ikb_section,
  ikb_entity_list, ikb_entity_detail, ikb_report, ikb_signature_block
and none of: if, for, include.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
It now requires ikb_button, ikb_badge, ikb_entity_view, ikb_region, ikb_slot and
ikb_section to be present, >=34 components, only ikb_* (or `island`), and rejects
if/for/include. It must exit 0.

# ALSO VERIFY AND REPORT
  # the component count must equal the renderer's catalogue, not the registry's 25
  php ikabud disyl:describe --json 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo "components=",count($d["components"]),"\n";'
  # slice A must still pass
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php
  # no regressions
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)

# SCOPE
- Edit ONLY: `ikabud`, kernel/DiSyL/Component/ComponentRenderer.php,
  docs/disyl/editor-tooling-plan.md
- Do NOT change rendering output. This is a read/expose change only; the HTML produced for
  any component must be byte-identical.
- Do NOT touch the extension, modules/healthcare, or modules/sms.
- Do not commit or push.
- If exposing the catalogue requires changing render behaviour, STOP and report BLOCKED.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
authority: the method/constant you exposed and how the CLI reads it
components_count: the emitted count and the renderer's count, and whether they match
control_tags_absent: confirm if/for/include are excluded
rendering_unchanged: how you verified render output is byte-identical
acceptance: the checker output and exit code
suites: the four summary lines
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-describe-components
