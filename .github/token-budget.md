# Token Budget & Optimization — Ikabud Agent System

## Model Native Context Windows

| Model | Input Context | Max Output | Provider | Best-fit Agent |
|---|---|---|---|---|
| **Claude Sonnet 4** (Anthropic) | ~200K tokens | ~64K tokens | Copilot | Code Reviewer, Pattern Explainer, Documentation Writer |
| **GPT-5** (OpenAI) | ~128K tokens | ~16K tokens | Copilot | Test Writer, Refactoring Advisor |
| **Gemini 2.5 Pro** (Google) | **1,048,576 tokens (1M)** | 65,536 tokens | Copilot | **Explore** — research & multi-file context |

> Native API limits. Copilot/VS Code may apply additional per-request caps — these are best-effort maximums.

> All agents listed are **free with GitHub Copilot** — they use your existing subscription, not separate API billing. Using agents with `read, search` only (Explore, Code Reviewer, Pattern Explainer) costs the least in terms of context budget because they produce no edit/execute tool output.

## How Context Gets Consumed

| Consumer | Typical tokens | Optimization |
|---|---|---|
| System prompt + agent instructions | 2K–8K | Keep instructions lean, remove boilerplate |
| `copilot-instructions.md` | 3K–6K | Already loaded; keep focused |
| Active skill/instruction files | 1K–5K each | Only load relevant ones via `applyTo` |
| File reads (ctx_read auto mode) | 0.1K–3K per file | Use compressed mode for large files |
| Tool call results (shell output) | 0.5K–20K | Use `ctx_shell` (auto-compressed) |
| Previous conversation turns | 1K–10K per turn | Delegate deep research to subagents |
| Subagent return values | 0.5K–5K | Keep output concise — summaries, not dumps |

## Subagent Prompt Crafting — Optimize Token Usage

A well-crafted prompt saves tokens in TWO ways: the prompt itself is shorter, AND the agent's output is more focused.

### Do: Be specific
```
❌ "Research the ARK theme and tell me about it"
   → Agent reads everything, returns full file contents = 20K+ tokens wasted
✅ "Read storage/cms-themes/ark/theme.manifest.json. Return:
   1. List declared slots
   2. Which surfaces are supported
   3. The shell layout path"
   → Agent reads 1 file, returns 3 bullet points = 500 tokens
```

### Do: Limit scope to 1-2 tasks per delegation
```
❌ "Review the code, fix the bugs, write tests, and document everything"
   → Agent's context fills up, may return empty results
✅ Step 1: "Review this code for bugs. Return issues with file:line refs."
✅ Step 2: "Fix these 3 bugs. Return what changed."
✅ Step 3: "Write tests for the fixed code."
```

### Do: Request output format explicitly
```
❌ "What do you think about the parser code?"
   → 5 paragraphs of prose
✅ "Analyze the parser's pipe handling. Return:
   - Where pipes are detected (file:line)
   - What precedence issue exists
   - Suggested fix with file:line"
   → Structured, actionable output
```

### Do: Force validation of assumptions
```
❌ "Is feature X implemented?"
   → Agent guesses based on docs, may be wrong
✅ "Read the actual source code at file:line to verify if feature X is implemented.
   Show me the relevant code."
   → Agent reads real code, returns accurate answer
```

### Don't: Bundle too many requests
When a prompt has 5+ distinct tasks, agents often complete only the first 2-3 or return empty. Break into separate delegations.

### Don't: Expect agents to chain without instructions
Agents don't automatically pass results between each other. The orchestrator must read Agent A's output, then pass relevant context to Agent B. Always include context from previous steps when delegating downstream.

## Token Optimization Rules

### Rule 1: Delegate context-heavy work to subagents
Subagents run in **isolated sessions** with their own context budget. They don't consume the orchestrator's budget beyond their return value.

```
❌ Orchestrator reads 50 files directly → 100K+ tokens used in main session
✅ Orchestrator delegates to Explore (Gemini, 1M budget) → 2K tokens returned
```

### Rule 2: Use Explore (Gemini) for any multi-file research
Gemini 2.5 Pro's 1M context can handle 50+ files without compression loss. Always delegate broad research to Explore.

### Rule 3: Keep subagent output concise
Subagents should return structured summaries (file:line references), not full file contents. The orchestrator re-reads files it needs to edit.

### Rule 4: Prefer lean-ctx compressed reads
- Use `ctx_read(path, mode: auto)` — auto-selects compression level based on file size
- Use `ctx_read(path, mode: signatures)` for getting method/class overviews
- Use `ctx_read(path, mode: map)` for very large files (500+ lines)
- Avoid `mode: full` unless about to edit

### Rule 5: Tool restriction per agent
| Tools | Context saved | Agents |
|---|---|---|
| `read, search, lean-ctx` (read/search/tree) | No edit/execute tool outputs | Code Reviewer, Pattern Explainer, Explore |
| `read, search, edit, lean-ctx` (read/search/tree/edit) | No execute/shell outputs | Documentation Writer, Refactoring Advisor |
| `read, search, edit, execute, lean-ctx/*` | Full toolset (runs tests, compressed shell) | Test Writer |

> **lean-ctx MCP tools are now available to all agents.** Agents should prefer `ctx_read`/`ctx_search`/`ctx_tree` over native `read_file`/`grep_search`/`list_dir` — this saves 50-99% context tokens per operation. See [lean-ctx docs](https://leanctx.com/docs) for mode reference.

### Rule 6: Sequential delegation for large tasks
When a task spans multiple categories, delegate **sequentially**, not in parallel:
1. Explore (research) → returns summary
2. Code Reviewer (validate existing) → returns issues
3. Pattern Explainer (understand design) → returns guidance
4. Test Writer (write tests) → returns test files
5. Refactoring Advisor (cleanup) → returns changes
6. Documentation Writer (document) → returns docs

Each step gets its own context budget. Parallel subagents compete for the same context window.

### Rule 7: Output format matters
Agents should prefer structured output:
- **File:line references** instead of pasting full code blocks
- **Summary tables** instead of prose paragraphs
- **Bullet lists** instead of full sentences

## Delegation Decision Tree

```
Task arrives
├── Does task require terminal execution (commands, builds, tests)?
│   → Use Test Writer (execute tools) or do it directly
│   → Read-only agents (Code Reviewer, Explore, Pattern Explainer) CANNOT run commands
├── Needs research across 5+ files? → Explore (Gemini, 1M budget)
├── Needs code review? → Code Reviewer (Claude, 200K budget)
├── Needs architecture explanation? → Pattern Explainer (Claude, 200K budget)
├── Needs tests written? → Test Writer (GPT, 128K budget)
├── Needs refactoring? → Refactoring Advisor (GPT, 128K budget)
├── Needs documentation? → Documentation Writer (Claude, 200K budget)
├── Needs code generation/editing? → Orchestrator (default model)
└── Agent returned empty?
    → Invoke Empty/Stale Agent Return Recovery Protocol
    → Do NOT assume silence means success
```

## Monitored Metrics

| Metric | Target | What to watch |
|---|---|---|
| Subagent return size | < 5K tokens | Trim verbose output formatting |
| File reads per task | < 20 files | Use compressed modes for large batches |
| Parallel subagent calls | 0 for research-heavy tasks | Chain sequentially instead |
| Agent instruction length | < 3K tokens per agent | Remove redundant boilerplate |
