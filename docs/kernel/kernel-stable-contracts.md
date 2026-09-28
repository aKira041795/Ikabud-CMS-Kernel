# Kernel Stable Contracts

## Purpose

This document distinguishes extension points that modules and external integrations can rely on from internals that may be reorganized during kernel refactors.

## Stable Contracts

The following should be treated as compatibility-sensitive.

### 1. Module manifest structure

The following manifest concepts are stable contracts:

- module identity fields such as `id`, `name`, `version`, and `type` (including `"type": "service-module"`)
- route and handler entry declarations
- `owns_tables` and `reads_tables`
- `co_owns_tables` for shared infrastructure tables
- migration and SQL artifact declarations
- capability `provides` and `requires`
- settings field definitions used by module settings UIs
- auth-cookie declarations used by kernel auth discovery
- `auth_owned` declarations for module-owned authentication
- `entry_module` tenant designation
- service endpoint and protocol declarations for polyglot service modules

Changing the meaning of these fields is a breaking platform change.

### 2. Route map conventions

These conventions are stable:

- route files remain declarative
- module handlers continue using `module-id:functionName`
- kernel-owned routing remains the gatekeeper for auth, tenant context, and dispatch

### 3. Capability IDs and payload contracts

Capability identifiers with version suffixes, for example `ecommerce.orders.tracking.sync@1`, are stable contracts.

Rules:

- do not change the meaning of an existing version in place
- add a new version when payload semantics change materially
- keep provider behavior compatible within a version

### 4. Hook and event names

Published hook and event names are compatibility-sensitive once used by modules or integrations.

Rules:

- do not rename hook or event identifiers casually
- do not silently remove event payload fields relied on by existing listeners
- prefer additive payload changes over destructive ones

### 5. Tenant and auth safety invariants

These behaviors are stable and must be preserved:

- fail-closed tenant DB behavior
- tenant-aware JWT rejection when multi-tenancy is enabled
- kernel-owned CSRF enforcement for browser-mutating routes
- centralized security-header application
- module manifest validation before load

### Kernel-owned table access

Kernel services that read or write kernel-owned tables during an active module request must wrap those queries in kernel DB escalation (`KernelPDO::kernelEscalationEnter()` / `kernelEscalationLeave()`). Do not query kernel-owned tables via `app()->db()` without escalation while `_activeModuleContext` is set, or the ModuleDB ownership gate will deny the access.

### 6. Module settings and entitlement helpers

These helpers are effectively part of the platform surface while modules depend on them:

- tenant settings read/write helpers
- module enable/disable helpers
- entitlement and access-request helpers
- migration synchronization helpers used during provisioning and CLI flows

Internal implementation can move, but external behavior should stay stable during decomposition.

### 7. Entity context and authority contracts (Kernel OS 4.0+)

These contracts govern how entity types resolve to renderable views and how cross-module data ownership works:

- **EntityViewResolver** — `registerView()`, `resolve()`, `viewContract()` define how entity types map to renderable views (compact, full, card, table, timeline). View registrations and builtin defaults are compatibility-sensitive.
- **ContextRegistry** — `registerSchema()`, `registerProfile()`, `registerMode()`, `registerCapability()`, `bindEntityType()` define the entity context resolution pipeline.
- **EntityAuthorityRegistry** — `registerAuthority()` declares which module owns an entity type. Authority changes affect cross-module data ownership.
- **SyncContractRegistry** — `registerContract()` defines entity sync contracts between modules.

### 8. Polyglot service wire protocol (Kernel OS 5.0+)

The ServiceProxy protocol is a stable cross-language contract:

- `POST /capability/call` with JSON `{capability_id, payload, caller}`
- Response: `{"ok": true, "data": {...}}` or `{"ok": false, "error": "..."}`
- Service manifest: `"type": "service-module"`, `service.endpoint`, `service.protocol`, `service.auth.token_env`
- Circuit breaker and retry behavior is bus-managed, not service-managed

### 9. Governed DiSyL component contracts (Kernel OS 4.0+)

The 31 governed components registered via `ComponentRegistry::registerCoreComponents()`:

- Component names (`ikb_entity_list`, `ikb_stat_card`, `ikb_export_button`, etc.)
- Attribute schemas (props, types, defaults)
- Slot contracts (named slots and expected content)

### 10. Export pipeline contracts (Kernel OS 4.0+)

- `KernelExport::register($entityType, $format, $handler)` — handler registration
- Supported formats: `csv`, `docx`, `pdf`
- `ReportManager` template, archive, and scheduled report contracts

### 11. DiSyL line of record (2026-09-27)

**This repository is the DiSyL line of record.** Its version is declared once, in
`kernel/DiSyL/Version.php` (`DISYL_VERSION`), and that declaration is authoritative.

A second, independently-developed DiSyL line exists in the sibling repository
`aKira041795/Ikabud-Kernel-OS` (working copy `/var/www/html/ikabudsix`). It is **not a fork**:

| | this repository | sibling line (Ikabud-Kernel-OS) |
|---|---|---|
| history | 2,075 commits since 2026-03-21, root `4db435a1` | 664 commits since 2026-08-20, root `f2b8e2a8` |
| shared commits | **0** — no merge base | **0** |
| tests | 485 PHP + 11 Playwright browser specs | 144 PHP + 0 browser specs |
| version declaration | `DISYL_VERSION = '4.8.0'`, one source | **none** |

**Decision.** The line that can state its own version, carries 3.4x the test coverage
including browser verification, and hosts the deployed product is the line of record. The
sibling line's DiSyL is larger (+1,355 lines) but is unreleased work of undeclared version.

**Rules that follow from this decision.**

1. **Never `git merge`, `git rebase`, or `git cherry-pick` between the two lineages.** They
   share no ancestry; a merge would fabricate history and could silently revert either side.
2. **Port sibling-line work as reviewed deltas**, one change at a time, each with its own
   test, provenance docblock naming the source repo, and a note that the lineages are
   unmergeable. Copy file contents; do not move commits.
3. **A change is not ported until it is proven on this line.** Arriving from the sibling
   line confers no review status.
4. **If the two engines disagree on behaviour**, this line's behaviour wins unless a
   deliberate decision recorded here says otherwise.
5. **Guards ported from the sibling line must be wired to enforce, not merely to report.**
   A census that computes and logs without refusing is not a boundary. See the
   authority/governance port contract for the must-refuse / must-allow requirement.

**Reconciliation of the two engines is a separate, deliberate task** and must not be folded
into feature work.

### 12. Rendering precedence: page-builder output outranks the theme (2026-09-27)

**The rule.** Authored page-builder content takes precedence in **function, CSS, and
structure**. Everything that is *not* builder-authored falls back to the active theme
(ARK today, or whichever theme is activated).

Stated as an ownership boundary:

| Surface | Owner | The theme may |
|---|---|---|
| Nodes the builder renders (`cms-builder-*`, `cms-kb-*`, `cms-lightbox*`) | **the builder** | style *around* them, never restyle them |
| Non-builder content (prose, module templates, entity views, chrome) | **the theme** | style freely |

**Why this is a rule and not a preference.** The builder is an authoring surface: a person
placed a slideshow and set full-bleed. The theme is a presentation default. When the theme
wins a dispute over builder output, an author's explicit instruction is silently overridden —
and the author cannot see why, because the losing declaration is not in their document.

**Violations found in the current tree (measured 2026-09-27):**

1. `storage/cms-themes/ark/style.css:231` — `.ark-main { overflow-x: clip; }`
   The builder's full-bleed escape (`cmsBuilderApplyFullWidth()`, "expand to 100vw and bleed
   past the column") uses negative margins. `overflow-x: clip` cuts the breakout off.
   Measured on `/` at a 1600px viewport: the slideshow's image occupies x≈192→1440 and the
   160px bands on both sides render white. The layout box reports 1600px, so this is invisible
   to geometry checks and only visible in rendered pixels.
2. `storage/cms-themes/ark/style.css:1097` — `.ark-content .cms-builder-slide h3 { color: inherit }`
   The theme restyling a builder node.
3. `storage/cms-themes/ark/style.css:1103` — `.cms-builder-node--text[style*="--b-color:#3B82F6"]`
   The theme patching a *builder-authored colour* to raise contrast. The accessibility goal is
   correct; the location is wrong. A contrast defect in builder output must be fixed in the
   builder (or in the authored value), not by a theme rule that quietly changes what the author
   chose.

**Consequences for reviewers.**

- A fix for a defect **inside** builder output belongs in the builder, even when a theme rule
  would be a smaller patch. A theme-side patch is a precedent that erodes the boundary.
- A theme rule whose selector names a `cms-builder-*` / `cms-kb-*` class is a **finding**, not
  a fix, unless it is provably scoped to decoration the builder cannot express (e.g. print).
- Removing `overflow-x: clip` is not automatically safe — it exists to prevent horizontal
  scrollbars. Prefer making it not clip builder breakouts over deleting it.
- Verify with **rendered pixels**, not `getBoundingClientRect()`. Clipped elements still report
  their unclipped layout geometry, so a bounding-box assertion passes while the page is visibly
  wrong.

## What is NOT Stable (Deprecated or Internal)

The following internals may be reorganized or removed without notice:

- **KernelPDO internals** — `KernelPDO` class internals including `isDirectModuleCaller()`, `enforceModuleAccess()`, and the `$moduleOriginCache`. Use `KernelPDO::setActiveModule()` / `getActiveModule()` for module context.
- **`debug_backtrace()` fallback in KernelPDO** — **DEPRECATED**. The backtrace-based module origin detection in `KernelPDO::isDirectModuleCaller()` and `enforceModuleAccess()` is a fallback for callers that have not set explicit module context. It logs a warning when triggered. Will be removed after a full caller audit confirms all paths set active module.
- **DiSyL parser internals** — The DiSyL parser (Parser, Lexer, ExpressionEvaluator) internal token format and AST structure. Use the public `TemplateEngine::render()` / `renderString()` API.
- **Compiled template cache format** — The `.php` files in `storage/cache/compiled/` are internal artifacts. Do not read or modify them directly. Invalidation logic may change.
- **KernelPDO escalation internals** — `KernelPDO::kernelEscalationEnter()`/`kernelEscalationLeave()` are stable in behavior but the counter implementation (`$escalationDepth`) is internal. Use the public API only.

## Internal Implementation Details

The following can be reorganized as long as stable behavior remains unchanged:

- file placement of helper implementations
- service extraction from `kernel/App.php`
- front-controller helper extraction from `public/index.php`
- decomposition of `src/helpers/module-manager.php`
- caching strategy details that do not alter externally visible behavior

## Refactor Rule

When in doubt:

1. preserve IDs, names, and payload shapes
2. move implementation behind compatibility shims
3. update docs and tests before removing an old path

## Validation Expectations

Changes touching stable contracts should rerun:

- request dispatch integration coverage
- tenant isolation and fail-closed tests
- manifest and module settings defaults coverage
- any feature-specific bridge or module tests affected by the contract