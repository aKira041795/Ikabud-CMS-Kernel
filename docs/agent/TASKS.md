# Tasks — Ikabud Development Tracker

> Active objective: Repository agent settings, instructions, and workflow review/update (2026-07-07)

## Task Board

| ID | Task | Status | Priority | Owner | Notes |
|---|---|---|---|---|---|
| AGT-001 | Create `docs/agent/` task tracking files | ✅ done | P0 | Orchestrator | ~~TASKS.md, STATUS.md, DECISIONS.md, BLOCKERS.md~~ |
| AGT-002 | Fix copilot-instructions.md CSP heading (empty section) | ✅ done | P1 | Orchestrator | ~~CSP rules content was misplaced under DiSyL limitations~~ |
| AGT-003 | Update skills registry table in copilot-instructions.md | ✅ done | P1 | Orchestrator | ~~All 18 skills documented: 7 mandatory + 11 domain-specific~~ |
| AGT-004 | Fix explore.agent.md `ctx_shell` reference | ✅ done | P2 | Orchestrator | ~~Agent had [read, search] tools only; ctx_shell removed, lean-ctx tools added~~ |
| AGT-005 | Add lean-ctx MCP tools to all 6 agent definitions | ✅ done | P0 | Orchestrator | Tools scoped by role: read-only get ctx_read/search/tree, edit-capable also get ctx_edit, Test Writer gets all |
| AGT-006 | Update AGENTS.md + token-budget.md for MCP tool docs | ✅ done | P1 | Orchestrator | Roster table and Rule 5 updated with lean-ctx tools |

## Completed

| ID | Task | Completed | By |
|---|---|---|---|
| AGT-001 | Create `docs/agent/` task tracking files | 2026-07-07 | Orchestrator |
| AGT-002 | Fix copilot-instructions.md CSP heading | 2026-07-07 | Orchestrator |
| AGT-005 | Add lean-ctx MCP tools to all 6 agent definitions | 2026-07-07 | Orchestrator |
| AGT-006 | Update AGENTS.md + token-budget.md for MCP tools | 2026-07-07 | Orchestrator |
| AGT-003 | Update skills registry in copilot-instructions.md | 2026-07-07 | Orchestrator |

## Acceptance Criteria per Task

### AGT-001: Create docs/agent/ tracking files
- [x] TASKS.md created with task board
- [x] STATUS.md created with session state
- [x] DECISIONS.md created with architectural decision log
- [x] BLOCKERS.md created with blocker registry

### AGT-002: Fix CSP heading
- [x] CSP rules content moved under `## Security hardening — CSP rules` heading
- [x] DiSyL limitations section remains separate
- [x] No content lost or duplicated

### AGT-003: Update skills registry
- [x] All 18 skills documented with applyTo patterns
- [x] Distinction between mandatory (always-active) and domain-specific skills
- [x] No skill file omitted
- [x] Description-triggered skills noted

### AGT-004: Fix explore.agent.md
- [ ] `ctx_shell` reference removed or qualified with availability note
- [ ] Agent still provides clear tool optimization guidance
