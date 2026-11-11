#!/usr/bin/env bash
#
# Lane: sol-disyl-describe-json - slice B of docs/disyl/editor-tooling-plan.md
#
# Adds `php ikabud disyl:describe --json`: one machine-readable surface for filters,
# governed components and the 41-construct inventory, so the editor AND an AI author
# read the same authoritative source instead of the hardcoded arrays in extension.ts
# (DISYL_FILTERS, GOVERNED_COMPONENTS, BLOCK_KEYWORDS) drifting apart.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an IMPLEMENTER in the Ikabud repo at /var/www/html/applicationostest.

# TASK
Add `php ikabud disyl:describe [--json]` to the `ikabud` CLI.

Read docs/disyl/editor-tooling-plan.md (slice B) first. This is slice A's sibling:
slice A gave diagnostics a machine-readable surface; this gives CAPABILITIES one.

# WHY IT MATTERS
extensions/disyl-lsp/src/extension.ts hardcodes DISYL_FILTERS (23), GOVERNED_COMPONENTS (31)
and BLOCK_KEYWORDS. Those lists drift from the engine the moment a filter or component is added.
An AI authoring agent also cannot discover what DiSyL supports. `describe` must emit the lists
from the AUTHORITATIVE sources, not a new hardcoded copy.

# AUTHORITATIVE SOURCES (verify these by reading them)
- Filters: kernel/DiSyL/v4/FilterRegistry.php. It holds a private `array<string, callable> $filters`
  populated by registerDefaults(). It currently exposes register/get/has/apply but NO enumeration.
  ADD a small public accessor (e.g. `public function names(): array { return array_keys($this->filters); }`).
  Do not duplicate the filter list in the CLI.
- Components: kernel/DiSyL/ComponentRegistry.php already has `all()` and `list()`. Use it.
  Governed components are the `ikb_*` set.
- Constructs: config/disyl-feature-inventory.json (41 entries, each with id/kind/syntax/
  interpreted/compiled/renderable/lsp). Emit these verbatim (or a projection that keeps at least
  id, kind, lsp, interpreted, compiled). Do NOT hand-copy the 41 entries.
- Block keywords: derive from wherever the engine defines its block tags. If there is no single
  authoritative PHP source, OMIT block keywords and say so in your report rather than hardcoding
  them in the CLI - a third copy is worse than a missing key.

# OUTPUT CONTRACT (--json)
Write ONLY JSON to stdout. Suppress `banner()` and `out()` in json mode (slice A already solved
this same problem - reuse that approach/helper rather than inventing a second one).

  {
    "schemaVersion": 1,
    "tool": "disyl:describe",
    "filters":    [ "<name>", ... ]  or [ {"name": "...", ...}, ... ],
    "components": [ "ikb_section", ... ],
    "constructs": [ { "id": "...", "kind": "...", ... }, ... ]
  }

Without `--json`, print a short human-readable summary (counts + the lists). There is no
existing human contract for this command, so you are free to design the text output.

# ACCEPTANCE - the lane gate runs exactly this; it FAILS on the current tree
  php ikabud disyl:describe --json 2>/dev/null | php .ai/tmp/disyl-describe-contract.php
The checker requires: valid JSON on stdout, schemaVersion 1, tool "disyl:describe", filters
non-empty (>=20, containing upper/lower/date/json/default), every component starting `ikb_`,
and >=40 constructs each exposing id and kind. It must exit 0.

# ALSO VERIFY AND REPORT
  # the filter list must match the registry, not a copy: count from the class itself
  php -r 'require "bootstrap.php"; $r=new Ikabud\Kernel\DiSyL\v4\FilterRegistry(); echo count($r->names()),"\n";'
  # that number must equal the JSON count
  php ikabud disyl:describe --json 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["filters"]),"\n";'
  # no regressions
  php tests/disyl_parity_test.php         (expect PARITY RESULTS: 195/195 passed)
  php tests/disyl_engine_test.php         (expect Result: 348 passed)
  php tools/disyl-conformance-check.php   (expect promoted=41 partial=0, disagreements: none)
  # slice A must still work
  php ikabud disyl:lint .ai/tmp/disyl-json-fixture.disyl --json 2>/dev/null | php .ai/tmp/disyl-json-contract.php

# SCOPE
- Edit ONLY: `ikabud`, kernel/DiSyL/v4/FilterRegistry.php, docs/disyl/editor-tooling-plan.md
- Do NOT touch the extension yet (slice D wires it up), modules/healthcare, or modules/sms.
- Do NOT add a hardcoded filter/component/construct list anywhere.
- Do not commit or push.
- If enumerating components or filters requires changing runtime behaviour, STOP and report BLOCKED.

# REPORT - exactly this shape
status: PASS | FAIL | BLOCKED
sources: each list -> the exact class/method or file it was read from
block_keywords: sourced from where, or explicitly omitted and why
acceptance: the checker output and exit code
registry_parity: the two counts and whether they match
suites: the four summary lines above
not_done: anything deliberately left
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-describe-json
