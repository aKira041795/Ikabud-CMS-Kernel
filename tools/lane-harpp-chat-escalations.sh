#!/usr/bin/env bash
#
# Lane: harpp-chat-escalations
#
# ROLE: the SECOND attempt. The first lane (harpp-chat-first) correctly reported BLOCKED
# because removing the decisions API would break the wake workflow. This lane carries the
# corrected, narrower contract, decided by the owner in chat (director scope):
#
#   "let's discard the decisions lane. harpp becomes better without it. as an away tool,
#    linked to my workstation, all i want is a chat lane, aside from the deploy and workspaces"
#
# WHAT THE FIRST LANE FOUND (verified by the chair, not taken on trust):
#   tools/harpp-bridge/harpp_wake.py escalates to the owner by creating a decision at three
#   sites: RELEASE_READY (:2132), BLOCKED (:2177), DECISION_REQUIRED (:2211), all via
#   harpp_client.harpp_notify(..., decision=decision).
#
# THE FACT THAT MAKES THE NARROWER CONTRACT SAFE (measured, chair, 2026-10-04):
#   harpp_client.harpp_notify() ALREADY sends the chat message FIRST (harpp_client.py:561-566)
#   and only THEN, for an actionable type, additionally calls submit_decision() (:567-582).
#   Measured with the chair probe on the UNCHANGED tree:
#     message_type=BLOCKED -> ['/api/v1/harpp/bridge/messages', '/api/v1/harpp/bridge/decisions']
#     message_type=INFO    -> ['/api/v1/harpp/bridge/messages']
#   So the chat lane is ALREADY the transport, and the decision is a second, duplicate record
#   on top of a message that is sent anyway. Removing the duplicate cannot silence the channel.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Canonical chain from tools/model-chain.txt via lane-model.sh: Sol, then DeepSeek Flash,
# then Terra. Not hard-coded - one exhausted provider must not end the work.
PROMPT="$(cat <<'PROMPT_EOF'
You are REDUCING the HARPP module so that its away channel is the CHAT LANE. The owner's
instruction is the contract:

  "let's discard the decisions lane. harpp becomes better without it.
   as an away tool, linked to my workstation, all i want is a chat lane, aside from the
   deploy and workspaces"

A previous attempt correctly reported BLOCKED because deleting the decisions API would break
the wake workflow. This contract is deliberately narrower. Read it before touching anything.

============================================================
PART 1 - ESCALATIONS REACH THE OWNER AS CHAT, NOT AS DECISIONS
============================================================

MEASURED ON THE BASE (chair probe, do not re-derive):
  python3 tools/harpp-escalation-probe.py         -> exit 1
     captured: ['/api/v1/harpp/bridge/messages', '/api/v1/harpp/bridge/decisions']
  python3 tools/harpp-escalation-probe.py INFO    -> exit 0
     captured: ['/api/v1/harpp/bridge/messages']

harpp_client.harpp_notify() sends the message first and then creates a decision on top.
Remove the DUPLICATE: an actionable escalation must send its chat message (and create its
notification) and must NO LONGER create a decision row.

  - Do NOT silence the message. The probe exits 2 if /messages stops being called, and that
    is the owner's only channel away from the workstation. The message body already carries
    what / why / options / recommendation / risk, so NO information is lost by dropping the
    decision row.
  - The three wake sites (harpp_wake.py RELEASE_READY :2132, BLOCKED :2177,
    DECISION_REQUIRED :2211) must keep working and keep producing the same message body.
  - Decide and REPORT whether you keep the `decision=` kwarg tolerated-but-unused in the
    signature, or remove the metadata at the three call sites. Either is acceptable; a
    dangling required argument that raises is NOT - note that harpp_notify currently RAISES
    ValueError when an actionable type has no decision title (harpp_client.py:569-570), so
    simply passing decision=None would break the channel. Handle that explicitly.
  - If a test asserts that harpp_notify creates a decision, that test asserts the behaviour
    the owner just removed. Correct it and state exactly what you changed and why.

============================================================
PART 2 - REMOVE THE DECISIONS PRODUCT SURFACE (the tab)
============================================================
Remove the decisions UI the owner does not want:
  - the nav entry for /harpp/decisions
  - the GET pages /harpp/decisions and /harpp/decisions/{id}, their handlers, templates and
    decision assets/JS
  - decision links and counts shown in the overview / status / notifications UI
  - update tools/harpp-ui-verify.js if it enumerates the decisions page (it does), and any
    other tool that enumerates it
After this, nothing in the HARPP UI offers decisions, and no UI links to them.

============================================================
PART 3 - DELIBERATELY KEEP THE BACKEND (read this before deleting more)
============================================================
KEEP, unchanged:
  - every /api/v1/harpp/* and /api/v1/harpp/bridge/* decision route
  - the decision services, the ADR registry (harpp_adrs is anchored to harpp_decisions via
    decision_ref and is joined by HarppMemoryService)
  - the MCP tools and tools/harpp-bridge/pi/harpp-tools.ts, and the workflow stages under
    tools/harpp-bridge/workflows/stages/
  - ALL tables and ALL rows
Rationale, so you do not "helpfully" delete more: those surfaces are invisible to the owner,
are consumed by the wake/pi/workflow subsystems, and removing them has no user-visible
benefit while being irreversible. Removing them is a SEPARATE owner decision. Report them as
deliberately kept, with the consumers you found.

HARD CONSTRAINTS
  - DO NOT drop or alter a table, and DO NOT delete a row.
  - DO NOT touch chat/messenger, deploy, or workspaces BEHAVIOUR.
  - DO NOT weaken a test covering a kept feature.
  - Pending decisions #142 and #143 are untouched - do not edit, close, or delete their rows.
  - No half-removal: no route whose handler is gone, no handler that is never routed.

============================================================
PART 4 - ACCEPTANCE (run each, paste the actual output)
============================================================
  A. python3 tools/harpp-escalation-probe.py        -> exit 0   (exit 1 on base)
  B. python3 tools/harpp-escalation-probe.py INFO   -> exit 0   (control, unchanged)
  C. the two decision PAGES are gone and nothing in the templates links to them:
       php -r '$r=require "modules/harpp/routes.php"; $g=$r["GET"]??[];
               foreach(["/harpp/decisions","/harpp/decisions/{id}"] as $p)
                 if(isset($g[$p])){fwrite(STDERR,"still routed: $p\n"); exit(1);} echo "pages gone\n";'
       grep -rn "/harpp/decisions" modules/harpp/templates templates/modules/harpp 2>/dev/null
         -> must return nothing
  D. bash modules/harpp/tests/run-all.sh                       -> ALL HARPP CHECKS PASS
  E. node tools/harpp-ui-verify.js                             -> every page 200, 0 console errors
     (update its page list if it still enumerates /harpp/decisions)
  F. node tools/harpp-message-path-probe.js                    -> message still persists
     (this is the away channel - it MUST still work)
  G. php ikabud module:validate harpp
  H. php -l on every changed .php file; python3 -m py_compile on every changed .py file
  I. both logs: storage/logs/app.log and storage/logs/error.log - say what you found

============================================================
PART 5 - REPORT COMPACTLY
============================================================
  status: PASS | FAIL | BLOCKED
  changed:            grouped: client / wake / routes / handlers / templates / assets / tests / tools
  escalations_now:    what an escalation does end-to-end after the change (message? notification? decision?)
  removed_ui:
  kept_backend:       with the consumers that made you keep it
  verification:       A-I with the actual output
  logs:
  risks / unresolved:

If removing the duplicate decision would silence the owner's channel, or if a kept consumer
turns out to depend on the decision ROW specifically (not just the message), STOP and report
BLOCKED with the call site. A blocker is a correct answer; a broken away channel is not.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harpp-chat-escalations
rc=$?
echo "lane: harpp-chat-escalations — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
