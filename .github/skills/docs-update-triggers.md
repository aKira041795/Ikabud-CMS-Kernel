---
name: docs-update-triggers
description: When and what documentation to update after changes
---

# Docs Update Triggers

## When to update docs
After ANY of these, open the relevant doc and update it:

| Trigger | Docs to update |
|---|---|
| New entity type added | `docs/entity-views/` — add entity type, fields, renderers, actions |
| New migration | `README.md` migration table + `docs/architecture/data-model.md` if schema changes |
| New DiSyL feature/fix | `docs/disyl/` — add feature docs + update engine-first-fix skill |
| New capability | `docs/architecture/capability-registry.md` — add capability ID, provider, args |
| Route added/changed | Module `README.md` route table + relevant docs |
| Schema change (column added) | Update migration history in `README.md`, update entity docs if entity view affected |
| Bug found + fixed | Update relevant docs with the gotcha + update skill files |
| New module created | Create `docs/modules/{module-name}/` with architecture, routes, capabilities, migrations |

## Doc files to maintain
- Module `README.md` — migrations table, routes, capabilities, key helpers
- `docs/architecture/data-model.md` — table schemas, relationships
- `docs/architecture/capability-registry.md` — all capabilities per module
- `docs/disyl/` — DiSyL features, filters, control structures
- `.github/skills/` — agent-accessible knowledge (update when patterns change)
- `.github/copilot-instructions.md` — high-level project conventions

## Skill file maintenance
- Skills in `.github/skills/` are loaded into agent context automatically
- When a new pattern emerges (e.g. "always use NULLIF in CONCAT_WS"), add it to the relevant skill
- When a workaround becomes unnecessary (because DiSyL was fixed), remove it from the skill
- Keep skills concise — bullet points and tables, not prose
