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

## Template — New Decision

**Decision**: [What was decided]

**Rationale**: [Why this choice]

**Alternatives considered**:
- [Option A] — [Why rejected]
- [Option B] — [Why rejected]
