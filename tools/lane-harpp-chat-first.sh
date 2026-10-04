#!/usr/bin/env bash
#
# Lane: harpp-chat-first
#
# REDUCES HARPP to what the owner uses. It does NOT improve the decisions feature - it
# REMOVES it. The owner's instruction is the contract, not a suggestion.
#
# MEASURED BASE (chair, 2026-10-04) - the criterion FAILS here, i.e. the surface exists:
#   bash tools/harpp-no-decisions-probe.sh   -> exit 1, listing the decision routes
#   control (same probe, needle "message")  -> exit 1 with hits, so the probe is not empty
#   git status --porcelain                  -> clean; branch master, in sync with origin
#
# WHY: the owner raised two decisions (#142, #143) that sat PENDING waiting on a decisions
# tab they do not want to use, and said so twice. The decisions subsystem is a surface the
# away-tool does not need. Escalation moves to the chat lane, which already works:
#   node tools/harpp-message-path-probe.js -> conversation + message + notification persisted.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Canonical chain from tools/model-chain.txt via lane-model.sh: Sol first (the owner asked
# for Sol for this review), then DeepSeek Flash, then Terra. NOT hard-coded - the three-model
# chain exists precisely so one exhausted provider cannot end the work.
PROMPT="$(cat <<'PROMPT_EOF'
You are REDUCING the HARPP module. You are not improving the decision feature - you are
REMOVING it. The owner's instruction is the contract.

OWNER, 2026-10-04 (verbatim - this is the requirement):
  "let's discard the decisions lane. harpp becomes better without it.
   as an away tool, linked to my workstation, all i want is a chat lane, aside from the
   deploy and workspaces"

HARPP's surface AFTER this lane is: chat/messenger, deploy, workspaces - plus auth, settings
and users as infrastructure for those three. Nothing else is a product surface.

STEP 1 - DEPENDENCY MAP BEFORE ANY DELETION (the review half; do NOT skip it)
Every consumer of the decisions feature, as a table. Search at minimum:
  - modules/harpp/routes.php, handlers.php, services/*, templates/modules/harpp/*, assets/*
  - tools/harpp-bridge/*.py (harpp_mcp.py, harpp_client.py, harpp_wake.py, deploy_*)
  - .github/workflows/*, tools/*.sh, tools/*.py, tools/*.js
  - anything outside modules/harpp/: grep harpp_decisions / harpp_adrs / decision in
    modules/ src/ kernel/ public/ scripts/ database/
  - migrations and seeds touching harpp_decisions / harpp_adrs
If a consumer OUTSIDE modules/harpp/ needs the decisions API, STOP and report BLOCKED naming
the consumer and the call site. Do not improvise a replacement for another subsystem, and do
not delete another module's code to make the removal look complete.

STEP 2 - REMOVE the decisions surface
  - routes: /harpp/decisions, /harpp/decisions/{id}, /api/v1/harpp/decisions*,
    all /api/v1/harpp/bridge/decisions*, apply-and-close, decisions/closed, and the
    artifact-bundle build/read routes that exist only for decisions
  - handlers: the decision handlers in modules/harpp/handlers.php
  - services: HarppDecisionService and its decision-specific callers
  - UI: decision templates, decision assets/JS, the nav entry, the decision page(s)
  - python: the decision tools in tools/harpp-bridge/harpp_mcp.py and harpp_client.py
  - tests: decision-specific tests; update modules/harpp/tests/run-all.sh if it enumerates a
    decision test file, and tools/harpp-ui-verify.js if it enumerates the decisions page
  - docs: where a doc presents decisions as a current HARPP feature, mark it removed with the
    date and the owner's reason. Do not rewrite history - state the removal.

STEP 3 - PRESERVE, DO NOT DESTROY (hard constraints)
  - DO NOT drop or alter any DB table, and DO NOT delete any row. harpp_decisions / harpp_adrs
    stay exactly as they are, with their data. Removing the surface is reversible; dropping
    data is not. If you believe a table must go, that is a BLOCKED report, not a migration.
  - DO NOT touch chat/messenger, deploy, or workspaces BEHAVIOUR.
  - DO NOT weaken or delete a test covering a KEPT feature. If a kept test asserted decision
    behaviour, correct it and say exactly what you changed in the report.
  - DO NOT leave a half-removed route (a route whose handler is gone), a handler that is never
    routed, or a dangling reference. Removal is complete or reported incomplete.
  - Two PENDING decisions exist (#142 and #143). They are DISCARDED with the feature. Record
    that in the report; do NOT close them and do NOT edit their rows.

STEP 4 - ACCEPTANCE (run each, paste the actual result)
  A. bash tools/harpp-no-decisions-probe.sh     -> exit 0   (exit 1 on the base)
  B. bash modules/harpp/tests/run-all.sh       -> ALL HARPP CHECKS PASS
  C. php ikabud module:validate harpp
  D. node tools/harpp-ui-verify.js             -> every page 200, 0 console errors
     (update its page list if it still enumerates /harpp/decisions)
  E. the chat lane still carries a message end-to-end:
       node tools/harpp-message-path-probe.js
  F. php -l on every changed .php file; python3 -m py_compile on every changed .py file
  G. both logs: storage/logs/app.log and storage/logs/error.log - say what you found

STEP 5 - REPORT COMPACTLY
  status: PASS | FAIL | BLOCKED
  removed:            grouped: routes / handlers / services / ui / python / tests / docs
  dependency_map:     who consumed decisions, and what they use now
  preserved:
  verification:       A-G with the actual output
  logs:
  risks / unresolved:

If a consumer outside modules/harpp/ needs the decisions API, or removal cannot be complete,
report BLOCKED with the evidence. A half-deleted subsystem is worse than a reported blocker.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harpp-chat-first
rc=$?
echo "lane: harpp-chat-first — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
