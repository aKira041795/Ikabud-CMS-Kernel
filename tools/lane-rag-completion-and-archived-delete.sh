#!/usr/bin/env bash
#
# Lane: rag-completion-and-archived-delete
#
# TWO owner requests, both measured by the chair first.
#
# (A) COMPLETE RAG — it must compound.
#     Retrieval now reaches every ChatGPT surface (advisor, debate, chair consult, /to-chair)
#     with path:line citations. What it does NOT do is learn from what was already concluded:
#     .ai/consult/*.md and .ai/debate/plan-*.md are written and never read back, and the
#     harness's own durable decision memory (harpp_client.memory_search) never reaches a prompt.
#     So the same ground is re-argued every time.
#
# (B) ARCHIVED MESSENGER CHATS MUST BE DELETABLE.
#     Everything appears to exist already:
#       route    modules/harpp/routes.php:158  DELETE /api/v1/harpp/conversations/{id}
#       handler  modules/harpp/handlers.php:437 harppConversationDelete
#       service  HarppMessagingService::deleteConversation (requires archived_at, soft-deletes)
#       UI       modules/harpp/assets/messenger.js:75-95 renders Delete in the archived view
#     Yet the owner reports deletion does NOT work for archived chats. REPRODUCE IT and fix the
#     real cause — do not "add" what is already there.
#
# THE ORACLE IS NOT YOURS TO EDIT: tools/harpp-bridge/tests/test_context_pack.py is the chair's
# contract (3 new tests, currently RED). Make it pass without changing it.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You have TWO tasks in /var/www/html/applicationostest. Report PASS / PARTIAL / BLOCKED for each
with real command output — never a summary of what you believe you did.

# TASK A — complete RAG so it compounds

Today retrieval finds repository facts (docs, module manifests, git grep hits, commit subjects) and
feeds every ChatGPT-facing prompt with `path:line` citations. It cannot see what was already decided.

A1. tools/harpp-bridge/context_pack.py — add a source for the chair's own prior conclusions:
    - `.ai/consult/*.md` (saved consultations) and `.ai/debate/plan-*.md` (approved plans).
    - Read them DIRECTLY from the workspace (they are usually untracked, so git grep will not see
      them). Neither this source nor the pack may require git to exist for them.
    - Rank this source ABOVE generic docs matches: the chair's earlier verdict on the same topic is
      the most valuable context a later discussion can get; a bare doc that merely shares a word is
      not. Ties inside the source: most recently modified first.
    - Keep every existing guarantee: deterministic for the same query+tree, `path:line`-style
      citations (use `<relpath>:<line>`), hard budget with a truncation marker, "" for an empty
      query or a missing workspace, never raises, never emits secrets.

A2. tools/harpp-bridge/context_pack.py — accept prefetched durable memory:
        build_context_pack(query, workspace, budget_chars=6000, extra_facts="")
    `extra_facts` is injected verbatim (bounded by the same budget) so the pack itself stays
    deterministic and offline. Empty/None => unchanged behaviour.

A3. tools/harpp-bridge/harpp_wake.py — prefetch the harness's decision memory and pass it in:
    - In build_advisor_prompt() and in _handoff_repo_facts(), call
      harpp_client.memory_search(<the query>) best-effort, render the returned ADR/decision
      title+body into a compact block, and pass it as extra_facts.
    - A memory search that returns nothing, answers 404, or throws MUST be silent: log at most one
      line and build the prompt anyway. The advisor must never fail because memory is unavailable.

# TASK B — archived messenger chats: make delete work (reproduce first)

Everything looks implemented: the DELETE route exists (routes.php:158 -> harppConversationDelete ->
HarppMessagingService::deleteConversation), the service requires `archived_at` and soft-deletes
(`deleted_at`, history retained), and messenger.js renders the Delete button in the archived view.

B1. REPRODUCE BEFORE CHANGING ANYTHING. Exercise the real path (the app on a local .test host, or the
    most faithful harness available — the browser journey suite in tests/browser/modules/harpp/ can
    create a disposable tenant). Capture: the exact HTTP status of the DELETE, the response body, the
    matching line from storage/logs/app.log, and what the UI does (console/network). State the
    failing link explicitly. Do NOT guess; if you cannot reproduce it, report BLOCKED with what you
    tried — that is a valid and useful answer.
    Likely suspects to CHECK, not assume: the CSRF token not being sent on a body-less DELETE;
    the role check (owner/admin) versus the acting user; canAccessConversation/visibility; the
    archived list not returning the row; method dispatch for DELETE in public/index.php.
B2. Fix the actual cause with the smallest change.
    - Do NOT weaken the guard: deleting a NON-archived chat must still be refused.
    - Do NOT turn it into a hard delete: rows are soft-deleted (`deleted_at`), history retained.
    - Keep CSRF enforcement on: fix the CLIENT so it sends the token rather than removing the check.
B3. Add a regression test that would fail on the broken behaviour and passes now, covering BOTH
    directions: an archived chat deletes; a non-archived chat is still refused.

## HARD CONSTRAINTS
- **Do NOT edit tools/harpp-bridge/tests/test_context_pack.py** (the chair's oracle).
- No new dependencies. MySQL 5.7 compatible. No schema change unless genuinely required (say so).
- Do not change lane isolation, the risk gate, or the /to-chair handoff semantics.
- Keep green: cd tools/harpp-bridge && python3 -m unittest tests.test_harpp_wake tests.test_harpp_bridge
  (note: 3 pre-existing errors appear when test_chatgpt_discussion runs in the same process as
  test_harpp_wake — that is a KNOWN pre-existing defect, not yours; run suites separately as above).

## ACCEPTANCE (show actual output)
A. cd tools/harpp-bridge && python3 -m unittest tests.test_context_pack   => OK (18 tests)
   (RED before your change: the 3 RagCompletionTest tests fail.)
B. cd tools/harpp-bridge && python3 -m unittest tests.test_harpp_wake tests.test_harpp_bridge => OK
C. Show one real pack that includes a `.ai/consult/*.md` entry, e.g.
   python3 -c "import sys; sys.path.insert(0,'tools/harpp-bridge'); import context_pack;
   print(context_pack.build_context_pack('stop button cancellation', '.', 2500))"
   and paste it, so the chair can read what the models will now see.
D. Task B: the before/after reproduction (status codes + log line) and the new test passing.
E. php -l on every PHP file you touched; both storage/logs/app.log and error.log checked and clean
   of new findings.

Report each task's status separately. Do not push.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-rag-completion
rc=$?
echo "completed by: ${LANE_MODEL_USED:-none}"
exit $rc
