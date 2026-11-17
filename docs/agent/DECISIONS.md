# Architectural Decisions — Ikabud

> Log of meaningful architectural decisions made during development.
> Format: Date, Decision, Rationale, Alternatives considered.

## 2026-07-07 — lean-ctx MCP tool scoping per agent role

**Decision**: Add lean-ctx MCP tools to all custom agent definitions, scoped by role:
- Read-only agents (Code Reviewer, Explore, Pattern Explainer): `mcp_lean-ctx_ctx_read`, `mcp_lean-ctx_ctx_search`, `mcp_lean-ctx_ctx_tree`
- Edit-capable agents (Documentation Writer, Refactoring Advisor): add `mcp_lean-ctx_ctx_patch`
- Full-capability agent (Test Writer): `lean-ctx/*` (all tools including `ctx_shell`)

**Rationale**:
- VS Code custom agents must explicitly list MCP tools in their `tools` field — they do NOT inherit MCP tools from the orchestrator
- MCP tools are registered under `mcp_<server>_<tool>` names (e.g. `mcp_lean-ctx_ctx_read`); references must use the registered name, and only tools the server advertises (standard profile) resolve — `ctx_edit` is not advertised, `ctx_patch` is the edit tool
- Without explicit MCP tool grants, agents cannot use `ctx_read`/`ctx_search`/`ctx_tree`, losing 50-99% context compression
- Agents' prompts already referenced lean-ctx tools (ctx_read, ctx_search) that they couldn't access
- Token-budget.md and AGENTS.md guidance on compressed reads was impossible for subagents to follow

**Alternatives considered**:
- `lean-ctx/*` for all agents — rejected: would give read-only agents shell/edit access, violating least-privilege
- No MCP tools, rely on native tools only — rejected: wastes context budget, contradicts established guidance
- Individual tool listing per agent — accepted: allows precise scoping

---

## 2026-08-04 — lean-ctx tool reference format correction (fix "ctx_read disabled")

**Decision**: Use the registered `mcp_lean-ctx_ctx_*` tool names in every agent `tools` grant and in `github.copilot.chat.planAgent.additionalTools`, and replace the non-advertised `ctx_edit` grant with `ctx_patch`.

**Rationale**:
- VS Code registers MCP tools as `mcp_<server>_<tool>` (e.g. `mcp_lean-ctx_ctx_read`). The plan agent's `additionalTools` used `lean-ctx_ctx_read` (missing the `mcp_` prefix), so `ctx_read` never resolved → reported as "ctx_read disabled".
- Repo agent files used the extension-tool slash format `lean-ctx/ctx_read`, which does not match registered MCP names; only the documented `<server>/*` wildcard and the full `mcp_` name resolve.
- The lean-ctx server (standard profile) does not advertise `ctx_edit`; the edit tool it advertises is `ctx_patch`.

**Files touched**: `~/.config/Code/User/settings.json`, cached `Plan.agent.md` (regenerated), all 6 `.github/agents/*.agent.md`, `.github/AGENTS.md`, `.github/token-budget.md`, `.github/mcp.json`, `.vscode/mcp.json`.

---

## 2026-07-07 — Agent tracking files location

**Decision**: Place agent task tracking files (TASKS.md, STATUS.md, DECISIONS.md, BLOCKERS.md) in `docs/agent/` rather than `.github/agent/`.

**Rationale**:
- `.github/` contains GitHub-specific configuration (agents, skills, instructions, copilot-instructions.md)
- `docs/` is the project documentation root
- Agent tracking is project documentation, not GitHub configuration
- Keeps `.github/` focused on agent/skill/instruction definitions

**Alternatives considered**:
- `.github/agent/` — would mix configuration with runtime state
- Root-level `AGENT_TASKS.md` — would clutter repo root
- `storage/agent/` — tracking docs are not runtime cache

---

## 2026-08-04/05 — Product suite and extension architecture (C12/C13)

**Decision**: Adopt a manifest-declared product-suite and extension model on top of the flat module registry: suites (`cms-akira`, ...), product cores (`kind: product-core`) that declare `extension_points`, extensions/adapters that `extends` a core and `contributes` to its points, profile bundles, and `admin_contributions` for dynamic admin surfaces. Implemented via additive suite manifest fields — the Suite Extension Contract v1 — (`suite`, `kind`, `extends`, `extension_points`, `contributes`, `admin_contributions`, `compatibility`, `uninstall`) and suite certification (C12/C13, `validateModuleSuiteContractV1()`).

**Rationale**:
- Flat peer modules cannot express product hierarchy, extension ownership, admin composition, or install context for growing platforms (CMS Akira, PAL, AISS, ARK, EHR, Commerce).
- The manifest is the authority for logical hierarchy; physical suite folders are namespace-only.
- Additive fields keep schema-v1/legacy manifests valid (`MODULE_MANIFEST_SCHEMA_VERSION` stays `'1'`).
- Kernel validates extends targets and contribution hosts at install/certification, so a module cannot inject into an undeclared host extension point.

**Alternatives considered**:
- Deeply nested runtime loader — rejected: loader stays flat; relationships come from manifests.
- WordPress-style plugin hierarchy — rejected: needs kernel-owned host contract enforcement.
- No model (status quo) — rejected: cannot support suites as extensible product platforms.

**Reference**: `docs/architecture/product-suite-extension-adr.md` (accepted 2026-08-04); release note `docs/releases/release-notes-2026-08-05-cms-akira-product-suite.md`.

---

## 2026-11-17 — HARPP experience repository: stamp first, store later

**Decision**: Make HARPP outcomes attributable before storing more of them. Phase 1 adds
`run_id`, `git_head` and three git-blob component hashes to every autonomy ledger row, an
append-only `.ai/harpp/trials.jsonl` for change-level hypotheses, and a read-only
`tools/harpp-experience.py` (`join`, `report`, `compare`, `trial new|list|evaluate`, `stamp`).
**No database is introduced.**

**Rationale**:
- Measured before building: the evidence mostly already exists — `.ai/runs/*.landed.json` carries
  `run_id`, `acceptance`, `verdict`, `completion`, `changed_paths`; `*.events.jsonl` carries the
  model per attempt; `*.policy.before` carries file→blob-hash snapshots; contracts already carry a
  falsifiable per-task prediction (`acceptance_fails_now`, `pass_looks_like`, `probe_exit`).
- The two genuine gaps were an absent foreign key (exact ledger→lane join matched **0 of 6**;
  normalised `<task>-a<attempt>` join matched 6 of 6) and no home for a change-level hypothesis
  spanning several tasks.
- `cost_usd` is `0.0` on all 12 ledger rows and no token usage is emitted, so any cost metric is
  uncomputable today. The report states that explicitly and exposes the repeat-failure fallback
  instead — baseline **85.7%** of recorded landing failures repeat an earlier signature.
- 12 ledger rows and 263 landing records scan instantly, so SQLite/FTS would be infrastructure
  without a customer. `autonomy-ledger.py record` already accepts arbitrary `key=value` fields, so
  stamping needs no schema change.

**Alternatives considered**:
- SQLite + FTS5 repository now (the research-grounded target) — deferred: nothing to index that a
  scan cannot answer; revisit above ~5000 rows or a 2s scan.
- Per-project repositories plus a shared promoted-lessons layer — deferred: one project has no
  second customer; `project_scope` is stamped from day one so the split stays possible.
- Reusing "decision" for the new record — rejected: collides with the owner `DEC-*` decisions in
  `~/.config/harpp/decisions.json`; the change-level record is a **trial**.
- Tracking the store in git — rejected: `.gitignore:119` already ignores `.ai/*` wholesale, so a
  single tracked exception would split the tree confusingly; `git_head` on each record preserves
  ordering without tracking.

**Reference**: `docs/ai/harpp-experience-repository.md`; discussion in
`.ai/consult/harpp-experience-repo-2026-11-17.chatgpt.md` (ChatGPT) and
`.ai/consult/harpp-experience-repo-2026-11-17.claude.md` (Claude red-team/chair reply).

---

## D-2026-11-17d — SQLite + FTS5 index now; PostgreSQL deferred behind a two-function boundary

**Decision**: JSONL stays the **truth**; `.ai/harpp/experience.db` (SQLite + FTS5, porter
stemming, bm25 ranking) is a **derived, deletable, rebuildable index** behind `cmd_index` /
`cmd_search`. **PostgreSQL is not adopted.** The owner's instruction was *"db type is immaterial,
the importance is efficiency; if postgre is better suited, use it"* — so this was settled by
measurement, not preference.

**Rationale (measured 2026-11-17 on this machine)**:
- SQLite **3.45.1 with FTS5** is present; `psql`/`postgres` are **not installed**.
- Real store (293 FTS rows): full rebuild **442 ms**, query **0.05 ms**, file **116 KB**.
- Projected **100,000 rows**: query **0.07 ms**, file **44 MB**, bulk load 1.35 s.
- So the engine is ~6000× away from being a bottleneck. PostgreSQL adds a TCP round trip (~0.5–2 ms
  per query — *slower* than the measurement above), plus a daemon to install, secure, back up and
  keep alive: a new failure mode in which HARPP cannot record experience because its database is
  down.
- The real defect was **retrieval, not storage**: the first index had no stemming and no ranking, so
  a lesson containing `reversed` was invisible to the query `reversing`, and matches came back in
  insertion order. Fixed with `tokenize='porter unicode61'` + `ORDER BY bm25(...)`; pinned by
  `test_search_finds_a_lesson_through_a_different_word_form`.

**Reversal triggers** (any one ⇒ revisit, and the interface does not change):
- a **second writer** (another machine or agent host) or a **second project** writing the same store
  ⇒ PostgreSQL (`jsonb` + `tsvector`/GIN) or MySQL, both of which have FTS5 equivalents;
- index rebuild exceeding **2 s** still holding ⇒ move the index to MySQL, already running here;
- > **5000** rows **and** scoped keyword retrieval measurably returning the wrong lessons ⇒
  embeddings.

**Alternatives considered**:
- PostgreSQL now — rejected on the measurements above: slower per query for this size, new daemon,
  new outage mode, nothing to gain.
- MySQL now — rejected: a server dependency for a dev-tooling file, and it couples harness data to
  the application's database lifecycle. Remains the preferred swap if a server is needed.
- Keeping grep-over-JSONL only — rejected: cannot express stemming or relevance, which is what makes
  recorded experience findable.

**Reference**: `tools/harpp-experience.py` (`cmd_index`, `_fts_query`, `cmd_search`);
`docs/ai/harpp-cognitive-architecture.md` §7.

---

## D-2026-11-17e — Lessons move to a git-tracked, evidence-weighted memory store

**Decision**: lessons live in `harpp-memory/` as **one immutable JSON file per event**, tracked in
git, replayed into state on read. `.ai/harpp/hints.json` becomes a read-only fallback used only
while the store is empty. Injection is **bounded to the 6 highest-activation lessons**.

**Rationale**:
- The old store was gitignored, so every lesson died on a fresh clone — the exact failure of the
  owner's "we should not waste experiences" directive.
- The owner rejected promoting lessons into a tracked markdown document: *"we cannot bloat a
  document as we gain experiences."* A prose file also makes one lesson unreadable at scale.
- Chair consult (`neural-experience-2026-11-17`) ruled: **build the memory system, not the neural
  metaphor** — immutable addressable records, disposable index, bounded working set, memories
  advisory and never authoritative. No vector DB, no embeddings, no daemon, no MySQL.
- Activation `(1+confirm)/(1+contradict) * 0.5**(age/120)` gives reinforcement, inhibition and
  forgetting without deleting evidence; the prompt stays six lines however large the store grows.

**Two defects found and fixed while implementing** (both only visible against real replay):
- whole-second event stamps let an approval sort *before* its own proposal, silently reverting an
  approved lesson to `proposed` — fixed with microsecond stamps plus a propose-first tie-break;
- content-dedupe applied to every event type made a retired lesson impossible to re-activate,
  because the second approval was byte-identical to the first — now only `propose` is idempotent.

**Alternatives considered**:
- Tracked markdown/instruction file — rejected by the owner (bloat, unreadable at scale).
- One append-only JSONL in git — rejected: unreadable when large, shared-file conflicts, and one
  corrupt line breaks replay of everything after it.
- MySQL / PostgreSQL / vector DB — rejected: no demonstrated problem that git + SQLite does not
  solve for a single-machine, single-owner harness.

**Falsifier**: if a memory-enabled harness does not make better decisions on unseen tasks than the
same harness with memory disabled once the store is large, the memory is technical debt.

**Reference**: `docs/ai/harpp-memory.md`, `harpp-memory/schema.json`,
`.ai/consult/neural-experience-2026-11-17.md`.

---

## Template — New Decision

**Decision**: [What was decided]

**Rationale**: [Why this choice]

**Alternatives considered**:
- [Option A] — [Why rejected]
- [Option B] — [Why rejected]
