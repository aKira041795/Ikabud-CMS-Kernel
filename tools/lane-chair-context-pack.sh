#!/usr/bin/env bash
#
# Lane: chair-context-pack
#
# WHY (measured by the chair, 2026-10-06):
#   HARPP's advisor prompt ends with "This page backend cannot see the repository. Do not
#   invent repository facts; state plainly what you cannot verify." The debate tool feeds
#   templates + intent/draft/critique only. So every idea is analysed in a vacuum: the
#   models cannot ground anything in HARPP's actual architecture.
#
#   What IS retrieved today is harness-internal: durable decisions, the chair ledger
#   (6000 chars), conversation context (4000 chars). Nothing from the repository.
#
# OWNER DECISION (2026-10-06): grounding is ON BY DEFAULT — the chair must not have to
#   remember to call it. And "whenever there's discussion needed, you as chair can invoke
#   chatgpt" — so a chair-initiated consult needs a first-class, scriptable path.
#
# THE ORACLE IS NOT YOURS TO EDIT: tools/harpp-bridge/tests/test_context_pack.py was
#   written by the chair and is the contract. Make it pass without changing it.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a RETRIEVAL layer for HARPP at /var/www/html/applicationostest.

## The gap
HARPP's advisor lane (tools/harpp-bridge/harpp_wake.py, ADVISOR_PAGE_PROMPT and
_advisor_page_pass) tells ChatGPT in its own prompt: "This page backend cannot see the
repository. Do not invent repository facts." The debate tool (tools/pi-arch-debate.py)
likewise feeds no repository facts. Ideas are therefore discussed in a vacuum.

## Your task — build the context pack and wire it in ON BY DEFAULT

### 1. tools/harpp-bridge/context_pack.py  (new)
    build_context_pack(query: str, workspace: str | None, budget_chars: int = 6000) -> str

Deterministic, dependency-free (git grep + stdlib only), and it MUST never raise.

Sources, ranked, deduped, each rendered with `path:line` citations:
  1. docs/**/*.md — matching headings plus the nearest snippet
  2. modules/*/module.json — capabilities/routes for modules the query or docs name
  3. `git grep` hits — top N files, a few lines each, as `path:line`
  4. `git log -- <matched paths>` — recent commit subjects, so "why it is like this" is visible
  5. .ai/chair/ledger.md and .ai/debate/plan-*.md, deduped against the above

Rules that matter:
  - Follow the QUERY. Two different intents must not get the same pack.
  - Respect budget_chars exactly; mark truncation rather than cutting silently.
  - Same query + same tree => byte-identical output (no timestamps, no dict ordering drift).
  - Empty/None query or a missing workspace => return "" (no exception).
  - NEVER include secrets: .env, bridge keys, passwords (git-tracked files only).

### 2. tools/harpp-bridge/harpp_wake.py — grounding is the DEFAULT
Extract the advisor prompt assembly into a PURE function (no subprocess, testable):

    build_advisor_prompt(*, plan, workspace, decisions="", context="", ledger="", budget_chars=6000) -> str

It must keep today's structure and sections, ADD a clearly headed
`# RETRIEVED REPOSITORY FACTS` block carrying the pack with its `path:line` citations, and
REPLACE the "cannot see the repository" sentence with instructions to use the retrieved
facts, to say exactly what is missing rather than invent, and to cite paths it relies on.
`_advisor_page_pass` must call this function (so the lane is grounded on every advisor run,
not only when someone remembers). Expose the size as `advisor.context_pack_chars` config
(default 6000) so it can be dialled down.

### 3. tools/harpp-bridge/chair_consult.py  (new)
The chair invokes ChatGPT on demand — this is the scriptable path, NOT another lane:
    python3 tools/harpp-bridge/chair_consult.py --query "<the idea/intent>" [--instruction "..."] \
        [--workspace <path>] [--budget 6000] [--dry-run] [--json] [--out FILE]
  - composes the grounded prompt (query + instruction + context pack) and, unless --dry-run,
    drives the existing page lane via tools/harpp-bridge/chatgpt_page.js (reuse it; do not
    reimplement browser automation),
  - --dry-run writes/exits without a browser (the oracle drives this),
  - --out FILE saves the prompt (and reply, when not dry) under .ai/consult/ by default,
  - --json prints one machine-readable line: {"ok":bool,"prompt":str,"citations":int,"reply":str}
  - exit 0 on success, non-zero on failure; never raise a traceback at the user.

## HARD CONSTRAINTS (violating any fails the task)
- **Do NOT edit tools/harpp-bridge/tests/test_context_pack.py.** It is the chair's oracle.
- **No new dependencies.** No embeddings, no vector store, no pip installs.
- Read-only retrieval: do not write outside .ai/consult/ and do not change any store.
- Do NOT change lane isolation, the risk gate, autoprocess, or the /to-chair handoff.
- Do NOT weaken the existing advisor/debate context blocks (decisions/ledger/context);
  they stay, and dedupe your hits against them rather than dropping them.
- Keep every existing test green: tests.test_harpp_wake, tests.test_harpp_bridge,
  tests.test_lane_isolation, tests.test_advisor_readonly_context, tests.test_debate_overhaul.

## METHOD
1. FIRST capture RED: `cd tools/harpp-bridge && python3 -m unittest tests.test_context_pack`
   and show the failure (import error today).
2. Implement the three pieces.
3. Re-run until GREEN, then re-run the suites above.
4. In your report, show the ACTUAL command output you relied on (no summary claims).

## ACCEPTANCE
A. `cd tools/harpp-bridge && python3 -m unittest tests.test_context_pack` => OK (10 tests).
B. `cd tools/harpp-bridge && python3 -m unittest tests.test_harpp_wake tests.test_harpp_bridge`
   => OK, unchanged count (276 tests before this lane).
C. `python3 tools/harpp-bridge/chair_consult.py --dry-run --json --query "stop button for runs"
   --workspace .` prints one JSON line with citations > 0 and exit 0.
D. Show one real pack: `python3 -c "import sys; sys.path.insert(0,'tools/harpp-bridge');
   import context_pack; print(context_pack.build_context_pack('shift reconciliation',
   '.', 2000))"` — paste it in the report so the chair can read what the models will see.

Report status as PASS / PARTIAL / BLOCKED with the evidence above. Do not push.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-chair-context-pack
rc=$?
echo "completed by: ${LANE_MODEL_USED:-none}"
exit $rc
