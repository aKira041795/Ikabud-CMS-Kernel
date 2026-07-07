# Architectural Decisions — Ikabud

> Log of meaningful architectural decisions made during development.
> Format: Date, Decision, Rationale, Alternatives considered.

## 2026-07-07 — lean-ctx MCP tool scoping per agent role

**Decision**: Add lean-ctx MCP tools to all custom agent definitions, scoped by role:
- Read-only agents (Code Reviewer, Explore, Pattern Explainer): `lean-ctx/ctx_read`, `lean-ctx/ctx_search`, `lean-ctx/ctx_tree`
- Edit-capable agents (Documentation Writer, Refactoring Advisor): add `lean-ctx/ctx_edit`
- Full-capability agent (Test Writer): `lean-ctx/*` (all tools including `ctx_shell`)

**Rationale**:
- VS Code custom agents must explicitly list MCP tools in their `tools` field — they do NOT inherit MCP tools from the orchestrator
- Without explicit MCP tool grants, agents cannot use `ctx_read`/`ctx_search`/`ctx_tree`, losing 50-99% context compression
- Agents' prompts already referenced lean-ctx tools (ctx_read, ctx_search) that they couldn't access
- Token-budget.md and AGENTS.md guidance on compressed reads was impossible for subagents to follow

**Alternatives considered**:
- `lean-ctx/*` for all agents — rejected: would give read-only agents shell/edit access, violating least-privilege
- No MCP tools, rely on native tools only — rejected: wastes context budget, contradicts established guidance
- Individual tool listing per agent — accepted: allows precise scoping

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

## Template — New Decision

**Decision**: [What was decided]

**Rationale**: [Why this choice]

**Alternatives considered**:
- [Option A] — [Why rejected]
- [Option B] — [Why rejected]
