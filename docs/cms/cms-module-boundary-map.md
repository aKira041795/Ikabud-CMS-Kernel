# CMS Module Boundary Map

## Purpose
Define what remains in core CMS versus what belongs in CMS-adjacent modules, while preserving Ikabud's module-governed architecture.

## Non-Negotiable Boundary Rules
- CMS is a module participant, not a kernel special-case subsystem.
- Cross-module behavior must flow through capabilities, events, hooks, or documented kernel contracts.
- Route files stay declarative; orchestration stays in handlers; reusable behavior stays in helpers/services.
- List/detail rendering uses entity views by default; composite pages stay in module templates with handler-fed context.

## Core CMS Ownership (Keep in modules/cms)
- Content lifecycle core: content CRUD, revisions, taxonomy, slugs, and public routing contracts.
- Builder runtime integration: document storage/render orchestration and governed component wiring.
- Public render context assembly, cache orchestration, and theme override resolution.
- Entity context bridge for CMS-owned content types and CMS-owned entity capabilities.
- CMS auth bridge behavior specific to cms_users and CMS admin entry routes.

## Candidate Adjacent Module Ownership (Extract or Create New)
- AI content planning and run orchestration surfaces.
- Marketplace/extension catalog orchestration and non-core installer flows.
- Domain-specific workflow packs tied to industry vertical content processes.
- Optional advanced theme tooling that is not required for baseline public render contracts.

## Capability Wiring Matrix
| Concern | Core Provider | Adjacent Provider | Contract Direction |
|---|---|---|---|
| Content read/list | cms | optional vertical module | Adjacent depends on cms.content.get@1 / cms.content.list@1 |
| Builder render | cms | none by default | Adjacent may consume cms.builder.render@1 only |
| AI generation | ai module | cms-ai-* module | CMS depends on ai.text.generate@1; adjacent owns orchestration capabilities |
| Workflow transitions | workflow module | vertical module | CMS and adjacent modules depend on workflow.state.* contracts |
| Entity capability data | cms / domain module | domain module | Entity capability contracts stay explicit per capability id |

## Data Ownership Rules
- Keep existing CMS-owned tables in CMS unless extraction includes explicit migration and ownership handoff.
- New adjacent module tables must be declared in owns_tables and migrated by that module.
- Shared infrastructure reads must be declared in reads_tables.
- Never rely on undeclared table access through ModuleDB.

## Routing and Namespace Rules
- CMS keeps canonical /cms and /api/v1/cms route ownership for baseline behavior.
- New module routes must avoid overlapping CMS path ownership unless explicitly versioned/suffixed.
- Capability contracts should be the integration seam before route-level coupling.

## Tenant and Auth Rules
- If an adjacent module is auth-owned, auth_owned must include id_column and role_column.
- Tenant provisioning must not introduce transitive dependency bloat through broad capability dependencies.
- Avoid capabilities.depends on kernel.auth.authenticate@1; use kernel auth APIs directly unless a real inter-module contract is required.
