# Project Terminology

This document records key terminology decisions and conventions used across Ikabud documentation, code, and communications.

---

## Kernel OS

**Kernel OS** describes Ikabud's role as the governed runtime layer for business modules. It owns request lifecycle, tenancy, security boundaries, capability dispatch, rendering contracts, and module governance.

It does not claim to be a hardware operating system or replacement for Linux, Windows, containers, or application servers. The term is an architectural metaphor.

## Retired term: Hyperkernel

"Hyperkernel" was explored during an earlier architectural phase (pre-2026) but has been **retired**. It overstated the project's scope and could be confused with established operating-system terminology.

**Do not use "Hyperkernel" or "hyper-kernel" in current product, technical, contributor, or marketing documentation.**

### Handling historical documents

Old assessments, archived roadmaps, or audit records that retain the term should be marked clearly:

> Historical terminology: this document used "Hyperkernel," a term retired in July 2026. The current name is Ikabud Kernel OS.

Do not silently rewrite audit records where historical accuracy matters. Current guides, READMEs, website copy, diagrams, and module documents should use only the canonical terminology.

---

## Canonical positioning

Use these terms consistently:

| Use | Do not use |
|---|---|
| Ikabud Kernel OS | Hyperkernel, hyper-kernel, universal kernel |
| modular application runtime | operating system (literal) |
| application-kernel infrastructure | CMS, framework, plugin system |
| governed business application platform | multi-CMS environment |
| PHP-hosted, polyglot-capable runtime | PHP-only platform |

---

## Key architectural terms

| Term | Meaning |
|---|---|
| **Kernel** | The governed runtime: routing, tenancy, auth, capabilities, DiSyL, workflows. Owned by `kernel/` and `bootstrap.php`. |
| **Module** | A self-contained business capability with owned tables, routes, handlers, and templates. Lives under `modules/`. |
| **Capability** | A typed, versioned contract exposed by one module and callable by others via the capability bus. |
| **DiSyL** | Declarative Ikabud Syntax Language — the rendering contract for UI across modules, themes, and builders. |
| **Entity** | A typed content record with registered views, rendered via the entity-view system. |
| **Tenant** | An isolated organization with its own database, settings, and module entitlements. |
| **ARK** | Architectural Rendering Kit — the visual operating specification governing themes, renderers, and design tokens. |
| **Control plane** | The shared database layer for tenant registry, module catalog, and entitlement state. |

---

## Versioning

The project uses semantic versioning for its public APIs. See [kernel-stable-contracts.md](kernel/kernel-stable-contracts.md) for the detailed stable-contract boundary.

| Component | Version scheme |
|---|---|
| Kernel OS | `MAJOR.MINOR.PATCH` — breaking: kernel refactor; feature: new capability; patch: bugfix |
| DiSyL | `MAJOR.MINOR.PATCH` — breaking: grammar change; feature: new construct; patch: bugfix |
| Module capabilities | Capability ID with version suffix: `ecommerce.orders.tracking.sync@1` |

---

## Document conventions

- File names use kebab-case: `contributor-workflows.md`, not `contributor_workflows.md`.
- Documents in `docs/` are organized by domain: `docs/kernel/`, `docs/cms/`, `docs/themes/`, etc.
- Root-level governance files use UPPER-CASE: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`.
- All documentation is written in Markdown.
