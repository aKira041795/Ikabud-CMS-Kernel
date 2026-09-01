# Agent Registry — Ikabud Application

This file defines the available custom agents, their assigned models, and delegation rules. The orchestrator (default agent) delegates tasks to the best-fit agent+model based on task type.

> **Token budget reference**: See `.github/token-budget.md` for model context windows, optimization rules, and the delegation decision tree.

## Execution Harness — Pi

Pi (MutableAI, `@earendil-works/pi-coding-agent` v0.84.2) is the configured
execution harness per the AI Development Execution & Handoff Directive
(`.github/instructions/ai-development-execution-handoff.instructions.md`). It
provides the files, commands, execution, and agent-runtime tooling for the
`/implement` stage.

**Install location** (user-local, no root required):
- Package: `~/.npm-global/lib/node_modules/@earendil-works/pi-coding-agent`
- Launcher: `~/.local/bin/pi` — runs the package CLI with a user-local Node 22
  (`~/.local/node-v22.23.2-linux-x64/bin/node`) because the system Node is v18
  and Pi requires Node >= 22.19.0.
- Verify with `pi --version`.

**Usage**: `pi --print "..."` for non-interactive execution, or `pi` for the
interactive TUI. Configure providers/credentials with `pi auth` and `pi config`.

**Model assignment** (defaults in `~/.pi/agent/settings.json`; provider
`deepseek`, default model `deepseek-v4-flash`):
- `deepseek-v4-pro` and Codex `gpt-5.6-sol` — reasoning, architecture,
  `/release-gate`. Invoke explicitly: `pi --model deepseek-v4-pro ...` or
  `pi --model openai-codex/gpt-5.6-sol ...`.
- `deepseek-v4-flash` — `/implement` and `/review` (also the default).
  `pi --model deepseek-v4-flash ...` or omit `--model`.
- Codex is the ChatGPT Pro subscription provider (`openai-codex`, OAuth via
  `/login`); subscription models include `gpt-5.6-sol`, `gpt-5.6-terra`,
  `gpt-5.6-luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.3-codex-spark`.
- Cycle between models in the TUI with Ctrl+P (`enabledModels`).
- Ox Alpha (`stealth/ox-alpha` via OpenRouter) is registered in
  `~/.pi/agent/models.json` but the free route is unreliable for agentic
  multi-turn work — experimental only; prefer DeepSeek/Codex.

**Architecture peer review workflow** (part of the harness — see
`tools/pi-arch-review.sh`):
- Runs **DeepSeek Pro** + **Codex Sol** (`openai-codex/gpt-5.6-sol`) as
  independent peer reviewers/architects on a task contract, so a "firm task
  generation" is cross-checked by two strong models before implementation.
- Usage: `bash tools/pi-arch-review.sh [task-file] [outdir]`
  (default task `.ai/current-task.md`, default outdir `test_results`).
- Wired into the harness CLI: `tools/ai-task "<task>"` now prints the
  peer-review step between `/architect` and `/implement`.
- Outputs: `<outdir>/arch-dspro.txt` and `<outdir>/arch-codexsol.txt`
  (plus raw `.jsonl` traces). Merge both reviews into the task contract
  before handing off to `/implement`.
- **Debate mode** (`python3 tools/pi-arch-debate.py "<intent>"`): the debate is ALWAYS
  argued by **two different LLM models** — one per side (Side A drafts/revises, Side B
  critiques; override with `DEBATE_MODEL_A` / `DEBATE_MODEL_B`, legacy aliases
  `DEBATE_CODEX_MODEL` / `DEBATE_DEEPSEEK_MODEL`). The runner fails closed if both sides
  resolve to the same model. The loop converges on **APPROVED**, then writes the agreed
  contract to `.ai/current-task.md`. Cap rounds with `DEBATE_MAX_ROUNDS` (default 3);
  artifacts land in `.ai/debate/`. This is the two-model "discuss -> agree ->
  create current-task" workflow.
  - `--preflight "<short intent>"` — firm up a fuzzy/short intent first via a
    cheap DeepSeek Flash brief (also auto-triggered for intents < 120 chars).
  - `--fast "<intent>"` — single-round triage.
  - `--approve` — chair-approve the last saved draft (no API calls, no
    re-debate) and write it as the task.
  - `--first codex|deepseek` — chair picks which model drafts first. Default is
    **auto**: intent-based (Codex opens for security/correctness/gap-hunting;
    DeepSeek opens for broad build/exploration), with the reason printed.
  - `DEBATE_AUTO_APPROVE=1` — auto-approve the last draft (scripted use); a
    convergence check stops early when the draft stops changing.

## Agent Roster

| Agent | Model | Context | Tools | Token strategy |
|---|---|---|---|---|
| **Code Reviewer** | Claude Sonnet 4 (Anthropic) | ~200K | read, search, lean-ctx (read/search/tree) | Read-only — no edit/execute output to consume budget |
| **Pattern Explainer** | Claude Sonnet 4 (Anthropic) | ~200K | read, search, lean-ctx (read/search/tree) | Read-only — returns concise explanations with file:line refs |
| **Documentation Writer** | Claude Sonnet 4 (Anthropic) | ~200K | read, search, edit, lean-ctx (read/search/tree/patch) | Write-only — reads source then produces docs |
| **Test Writer** | GPT-5 (OpenAI) | ~128K | read, search, edit, execute, lean-ctx (*) | Runs tests in subprocess — output captured compressed |
| **Refactoring Advisor** | GPT-5 (OpenAI) | ~128K | read, search, edit, lean-ctx (read/search/tree/patch) | Edit-only — no shell execution overhead |
| **Explore** | Gemini 2.5 Pro (Google) | **1M** | read, search, lean-ctx (read/search/tree) | **Context champion** — all multi-file research here |

> All 6 agents are **free with GitHub Copilot** — no additional API costs. Using them saves tokens vs doing everything in the orchestrator session.

> **MCP tools**: All agents have access to lean-ctx MCP tools scoped to their role, referenced by their registered `mcp_lean-ctx_*` names. Read-only agents get `mcp_lean-ctx_ctx_read`, `mcp_lean-ctx_ctx_search`, `mcp_lean-ctx_ctx_tree`. Edit-capable agents also get `mcp_lean-ctx_ctx_patch` (the advertised lean-ctx edit tool; `ctx_edit` is not exposed by the MCP server in the standard profile). Test Writer gets all lean-ctx tools (`lean-ctx/*`) including `ctx_shell` for compressed command output. Agents should **prefer lean-ctx tools over native tools** for token efficiency — see `.github/token-budget.md`.

## Model Strengths & Delegation Rules

### Claude Sonnet 4 (Anthropic) — Analysis & Communication
**Strengths**: Nuanced reasoning, security analysis, subtle bug detection, long-form writing, explanatory clarity, pattern recognition across disparate files.

**Delegate when**:
- Reviewing code for correctness, security, or maintainability → **Code Reviewer**
- Understanding architecture decisions or design rationale → **Pattern Explainer**
- Writing documentation, guides, or READMEs → **Documentation Writer**

### GPT-5 (OpenAI) — Generation & Transformation
**Strengths**: Fast code generation from specs, deterministic pattern-following, test scaffolding, structural refactoring, creative solution generation.

**Delegate when**:
- Writing tests for new or existing features → **Test Writer**
- Cleaning up code smells, extracting functions, reducing duplication → **Refactoring Advisor**

### Gemini 2.5 Pro (Google) — Research & Context
**Strengths**: Million-token context window, fast multi-file scanning, broad codebase surveys.

**Delegate when**:
- Researching how a feature works across many files → **Explore**
- Gathering context before making changes → **Explore**
- Finding all usages of a pattern or API → **Explore**

## Agent Delegation Lessons Learned

### What worked well
1. **Detailed structured prompts** — Agents with explicit file lists + specific questions returned focused results. Vague prompts produced vague output.
2. **Tool restrictions prevent context waste** — Read-only agents (`read, search`) cannot generate edit/execute output, keeping their return values lean.
3. **Parallel delegation for independent tasks** — Firing Explore + Refactoring Advisor simultaneously for unrelated work saved time without cross-contamination.
4. **Sequential chaining for dependent tasks** — Explore → analyze → implement (each in its own isolated session) kept the orchestrator's context clean.
5. **Compressed reading** — Agents that used lean-ctx compressed modes (auto, signatures, map) returned denser, more useful results.

### Root-Cause Analysis (mandatory for all fix-oriented agents)
When fixing issues, **always find the root cause before applying a patch**. Do not fix symptoms — trace the problem to its origin.

1. **Reproduce the issue** — Read error logs (`storage/logs/app.log`, `storage/logs/error.log`), reproduce the failing scenario, confirm the exact failure point
2. **Trace upstream** — Follow the call chain, data flow, or execution path backward from the failure point to find what caused it
3. **Identify the root** — Ask "why" at least 3 times: Why did this fail? Why was that condition met? Why was that value wrong?
4. **Fix the root, not the symptom** — Apply the fix at the source of the problem, not at the point where it surfaced
5. **Verify the fix chain** — Confirm the fix propagates correctly to all downstream consumers; check that no other symptoms from the same root cause remain

### What needed fixing
1. **Empty agent returns** — Complex prompts with too many sub-requests caused some agents to return empty results. **Fix**: Keep subagent prompts to 1-2 specific tasks. Break larger analyses into multiple smaller delegations.
2. **File changes without reporting** — Some edit-capable agents modified files but didn't report what they changed. **Fix**: Always instruct agents to "Return a summary of what you changed with file:line refs."
3. **Overly optimistic assumptions** — Agents sometimes assumed features were missing when they actually existed. **Fix**: Always include "Verify your assumptions by reading the actual code" in prompts.
4. **Verbose output waste** — Some agents returned full file contents instead of summaries. **Fix**: Explicitly request "Return summaries with file:line refs, not full file dumps."

### Empty/Stale Agent Return — Recovery Protocol
If a subagent returns empty (no output, or no meaningful content), DO NOT assume silence means success. Follow this recovery protocol:

1. **Check output immediately** — If the agent returned empty, do NOT proceed as if work was done
2. **Retry with a simpler prompt** — Split the original task into 1-2 smaller pieces. The most common cause of empty returns is a prompt with 3+ sub-tasks
3. **Switch agent type if needed** — If Code Reviewer returned empty, try Explore to gather the raw data first, then analyze it yourself
4. **Last resort: do it yourself** — If retries fail, perform the analysis directly in the orchestrator session
5. **Log the failure** — Record which agent + prompt combo failed in repo memory so the pattern can be addressed

#### Common empty-return scenarios and their fixes
| Scenario | Likely cause | Fix |
|---|---|---|
| Agent returns nothing at all | Prompt too complex (5+ sub-tasks) | Split into 2-3 smaller delegations |
| Agent returns "I don't have access to that" | Tool restriction prevents needed action | Switch to an agent with the right tools, or do it directly |
| Agent returns after reading but no analysis | Read-only agent can't execute commands | Pre-read the data yourself, then ask for analysis only |
| Agent starts work but returns incomplete | Context window filled by large files | Request compressed reads (`ctx_read mode: auto`) |
| Agent returns file contents instead of summary | Lacked output format instruction | Explicitly say "Return a summary with file:line refs, not full file dumps" |

### Prompt crafting rules for delegating
When sending a task to any agent, ALWAYS include:
1. **What files to read first** — "Read these files: [list]. Then..."
2. **What to produce** — "Return a summary with file:line refs" or "Return the complete code changes"
3. **What NOT to do** — "Do NOT make edits" / "Do NOT run tests"
4. **Verify assumptions** — "Read the actual code before reporting"
5. **Return explicitly** — "Return your findings — do NOT return empty. If you can't complete all tasks, return what you have."
6. **Limit scope** — Maximum 2 sub-tasks per delegation prompt. For larger work, chain multiple sequential delegations.

## Task Delegation Protocol

When the orchestrator receives a request, it should:

1. **Classify** the task type (review, explain, write, test, refactor, explore, debug, or code)
2. **If the task is a runtime bug report** ("data not saved", "form doesn't work", "button does nothing") → follow the **Debug-First Protocol** (below) BEFORE reading code or delegating
3. **Check if research-heavy** (5+ files needed) → delegate to **Explore** first (Gemini's 1M context)
4. **Check whether tool restrictions block the task** — if the task needs terminal commands (e.g. running `php ikabud`), delegate to an agent with `execute` tools (Test Writer) or do it directly. Read-only agents (Code Reviewer, Explore, Pattern Explainer) cannot run commands.
5. **Delegate** to the appropriate agent — subagents run in isolated sessions with their own token budget
6. **Await result** — agents return structured output, not files (unless they have edit permissions)
7. **Verify agent returned output** — if empty, invoke the Empty/Stale Agent Return Recovery Protocol instead of proceeding blindly
8. **Act on output** — the orchestrator implements changes based on agent findings if needed
9. **Verify** — check logs, run `php -l`, validate results

### Debug-First Protocol (mandatory for runtime bug reports)

When the task is a runtime bug report, do NOT read source code or delegate until you have:

1. **Reproduced** — asked the user for exact URL, steps, what they saw vs expected
2. **Checked both logs** — `storage/logs/app.log` AND `storage/logs/error.log`
3. **Narrowed the layer** — JS frontend? HTTP routing? PHP handler? SQL? Based on log evidence and user's description
4. **Isolated with a direct test** — a one-line PHP CLI call that tests the suspect function directly

Only AFTER these four steps, proceed to read code and make changes. See `.github/skills/runtime-debug-workflow/SKILL.md` for the full protocol.

**Anti-pattern**: Reading 500+ lines of backend PHP code for a JS/frontend symptom. If the user says "no toast appears" and "page reloads", the problem is in the browser, not in `ProjectService::update()`.

### Delegation priority
For tasks that span multiple categories, delegate in this order:
1. **Explore first** — gather context before acting (Gemini's 1M context absorbs the largest reads)
2. **Code review** — validate existing code before changing
3. **Pattern explainer** — understand architecture before designing
4. **Test writer** — write tests alongside or before implementation
5. **Refactoring advisor** — clean up after implementation
6. **Documentation writer** — document after implementation

### Token optimization rules
- **Sequential delegation** for large tasks — each subagent gets its own budget, chaining saves orchestrator context
- **Keep subagent output ≤ 5K tokens** — return summaries with file:line refs, not full file dumps
- **Explore absorbs all file reads** — don't read 20+ files in the orchestrator session
- **Compressed reads** — prefer `ctx_read(mode: auto)` over raw file reads
- **Parallel subagents**: OK only when each returns small output (< 2K tokens) — otherwise chain sequentially
