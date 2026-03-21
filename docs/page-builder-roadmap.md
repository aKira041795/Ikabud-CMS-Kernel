---
description: Page Builder Roadmap — Phased Delivery Plan for Dedicated Visual Page Composition
---

# Page Builder Roadmap

Delivery plan for a dedicated CMS page builder that targets an Elementor-class editing experience while respecting Ikabud Kernel architectural boundaries.

**Recent Renderer & UI Fixes (Mar 2026)**

- **Summary**: Fixed parity between builder preview and public/frontend render. Two related bugs were resolved: (1) server renderer applied `max-width` defaults onto every `container`, which overrode `flex-basis` and caused flex children to stack; (2) the builder UI wrappers did not forward `flex`/`flexBasis`/`order` to the outer wrapper div, so builder-preview DOM children were not participating correctly in parent flex layouts.
- **Root causes**: server defaults applied unconditionally; builder wrapper missed layout participation props.
- **What changed**: PHP renderer now applies wrapper defaults (`maxWidth`, `margin`, `padding`) contextually only when a container is not itself a flex/grid item; the React `NodeRenderer` now forwards `flex`, `flexBasis`, `order`, and `width` from node style to the wrapper so preview flex sizing works. Responsive CSS generation and various builder wiring (custom classes/IDs, animations, visibility) were added.
- **Files touched**:
  - [modules/cms/builder-renderers.php](modules/cms/builder-renderers.php)
  - [modules/cms/helpers.php](modules/cms/helpers.php)
  - [modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx](modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx)
  - [modules/cms/builder-ui/src/builder/components/PropertiesPanel.tsx](modules/cms/builder-ui/src/builder/components/PropertiesPanel.tsx)
  - [public/assets/cms/builder-public.js](public/assets/cms/builder-public.js)
  - [public/assets/cms/themes/native-default/style.css](public/assets/cms/themes/native-default/style.css)

- **Verification**: A standalone PHP render test validated the 33/67 preset now renders as two side-by-side flex items (no spurious `max-width` on children) and mobile responsive CSS is emitted. The builder UI was rebuilt to reflect the same contextual behavior.

- **Animation interoperability fix (Mar 2026)**
  - **Summary**: Fixed Advanced-tab animation combinations so entrance and hover effects work together instead of forcing users to effectively choose one.
  - **Root cause**: entrance and hover were both animating the same wrapper element and both writing `transform`/`animation`; frontend entrance completion also used a high-priority transform reset that could override hover transforms.
  - **What changed**: frontend entrance completion no longer uses `transform: none !important`; in builder preview, entrance animation is applied to an inner wrapper layer while hover remains on the outer wrapper.
  - **Files touched**:
    - [modules/cms/animation-definitions.php](modules/cms/animation-definitions.php)
    - [modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx](modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx)
    - [public/assets/cms/builder/builder.js](public/assets/cms/builder/builder.js)
  - **Verification**: builder UI rebuilt and no compile/type errors reported in changed files.
  - **Technical spec**: See [Implementation Note in technical spec](page-builder-technical-spec.md#implementation-note-mar-2026-entrance--hover-animation-interoperability) for architectural context.

- **Next steps / Notes**:
  - If you pull these changes, rebuild the builder UI:

```bash
cd modules/cms/builder-ui
npm run build
```

  - If you'd like, I can open a PR with these changes and include a short integration test for the PHP renderer.

See below for the roadmap phases and delivery plan.

## Current Reality vs Target

The CMS already has a **transitional builder implementation** in code today:

- dedicated admin page-builder routes and template
- block/meta-based builder save flow
- `_builder_enabled`, `_builder_content`, `_builder_page_settings` meta keys
- fallback through `blocks_json`
- reusable content primitive via `cms_saved_blocks`

This roadmap describes the path from that current transitional state toward a **canonical builder-document architecture**.

---

# Scope Decisions

| Decision | Answer |
|---|---|
| Primary entry point | Dedicated builder for `page` content type |
| Rollout order | Pages first, posts builder-ready later |
| Editing model | Dedicated builder flow, not embedded in the standard editor |
| Rendering model | Server-rendered public HTML |
| Source of truth | Versioned builder document JSON |
| Extension model | CMS contracts via hooks/capabilities/events |
| Theme-governed styling | style controls must map to tokens, presets, and constrained values. |
| Backward compatibility | standard page rendering continues to work for non-builder pages. |

---

# Architecture Constraints (Non-Negotiables)

- **Module-owned data**: all builder persistence lives in `cms_*` tables only.
- **Kernel-safe integration**: the builder consumes kernel services through approved boundaries only.
- **Dedicated experience**: builder UI remains separate from standard content editing.
- **Versioned document model**: builder state is schema-versioned from day one.
- **Deterministic rendering**: public output is server-rendered using trusted widget renderers.
- **Theme-governed styling**: style controls must map to tokens, presets, and constrained values.
- **Backward compatibility**: standard page rendering continues to work for non-builder pages.

---

# Phase 0 — Spec Lock (Required)

**Status**: Complete in documentation; not yet reflected fully in code
**Timeline**: 1 week

## Objective

Freeze the builder contract before implementation.

## Deliverables

- builder document schema v1
- node taxonomy (`document`, `section`, `container`, `widget`)
- widget definition contract
- style token and preset strategy
- reusable section contract
- migration/versioning rules
- privileged widget policy (for example `raw-html`)

## Acceptance Criteria

- engineering can implement builder storage and rendering without inventing schema mid-flight
- editor/runtime/public renderer teams share one contract
- versioning rules are documented before first builder document is stored

---

# Phase 1 — Foundation: Builder Data and Public Render

**Status**: Not started in canonical builder-document form
**Timeline**: 2-3 weeks

## Objective

Make builder-backed pages possible end-to-end with minimal but correct architecture.

## Deliverables

- `content_mode` support for pages
- builder document persistence tables
- draft/published builder document lifecycle
- basic builder routes and save/load endpoints
- server-side render pipeline for builder pages
- first layout primitives: section, container, columns
- first content widgets: heading, text, image, button, divider, spacer
- preview support
- builder-aware cache invalidation

## Transitional compatibility requirements:

- preserve existing `/cms/admin/page-builder*` routes during migration
- preserve existing `_builder_*` meta rendering behavior until document migration is available
- allow existing `cms_saved_blocks` assets to coexist with future reusable-section primitives

## Acceptance Criteria

- an editor can create a builder-backed page and publish it
- public render produces clean HTML without client-side composition dependency
- save/load roundtrip is stable
- non-builder pages still work unchanged
- existing transitional builder pages still render during rollout

---

# Phase 2 — Editor Runtime MVP

**Status**: Proposed
**Timeline**: 3-4 weeks

## Objective

Reach the first genuinely usable visual editing experience.

## Deliverables

- dedicated builder admin screen
- widget inserter
- structure tree / navigator
- drag/drop placement
- select/focus state
- property inspector
- inline editing for text-capable widgets
- duplicate/delete/move controls
- desktop/tablet/mobile preview modes
- autosave and dirty-state protection

## Acceptance Criteria

- editors can assemble real landing pages visually
- common operations do not require raw JSON or HTML editing
- preview and published render stay aligned

---

# Phase 3 — Reusable Sections and Templates

**Status**: Proposed
**Timeline**: 2-3 weeks

## Objective

Increase productivity and consistency through reuse.

## Deliverables

- reusable section CRUD
- insert reusable section into pages
- page starter templates
- save current section as reusable
- builder revision integration for reusable assets
- template metadata model

## Acceptance Criteria

- editors can create a reusable hero/CTA/testimonial section once and insert it many times
- new pages can start from a template instead of blank canvas
- reusable assets remain version-safe and auditable

---

# Phase 4 — UX Maturity and Responsive Controls

**Status**: Proposed
**Timeline**: 3-4 weeks

## Objective

Push the builder from functional to competitive.

## Deliverables

- responsive per-breakpoint controls
- undo/redo history
- copy/paste within document
- block breadcrumbs / parent path navigation
- better drop indicators and empty-state UX
- widget search and category browsing
- richer widgets: gallery, embed, CTA, FAQ, testimonials, icon

## Acceptance Criteria

- mobile and tablet adjustments are intuitive
- history operations are reliable
- the editor feels fast and understandable for non-technical users

---

# Phase 5 — Dynamic Content and CMS Integration

**Status**: Proposed
**Timeline**: 2-4 weeks

## Objective

Make the builder a first-class CMS composition system rather than a static page toy.

## Deliverables

- dynamic page title/content bindings
- featured image and excerpt bindings
- posts list/query widget
- custom field-aware widgets where supported
- builder-aware public metadata/SEO integration
- capability-driven data source contract for dynamic widgets

## Acceptance Criteria

- editors can compose pages that mix static design and CMS-driven content
- dynamic widgets remain typed, validated, and permission-aware
- dynamic output works in preview and public render

---

# Phase 6 — Extension Platform

**Status**: Proposed
**Timeline**: 2-3 weeks

## Objective

Allow other modules to extend the builder safely.

## Deliverables

- `cms.builder.widgets` registration hook/capability contract
- dynamic data provider contract
- template pack contribution contract
- widget availability/policy model
- extension documentation and examples

## Acceptance Criteria

- a separate module can register a widget without editing CMS core files
- widget registration is validated and discoverable
- bad extensions fail safely and visibly

---

# Phase 7 — Hardening, Governance, and Performance

**Status**: Proposed
**Timeline**: 2-3 weeks

## Objective

Stabilize for long-term production use.

## Deliverables

- schema migration utilities for builder documents
- render performance budgets and profiling
- stricter widget policy review process
- builder audit coverage review
- cache dependency hardening
- builder health/diagnostic tooling

## Acceptance Criteria

- schema upgrades can be applied safely to old documents
- builder output stays within acceptable performance budgets
- operators can identify broken documents/widgets without manual deep debugging

---

# Delivery Timeline Summary

| Phase | Description | Timeline | Depends On |
|---|---|---|---|
| **0** | Spec lock | 1 week | - |
| **1** | Builder data + public render foundation | 2-3 weeks | 0 |
| **2** | Visual editor MVP | 3-4 weeks | 1 |
| **3** | Reusable sections + templates | 2-3 weeks | 1, 2 |
| **4** | UX maturity + responsive controls | 3-4 weeks | 2 |
| **5** | Dynamic content + CMS integration | 2-4 weeks | 1, 2 |
| **6** | Extension platform | 2-3 weeks | 1, 2, 5 |
| **7** | Hardening + governance + performance | 2-3 weeks | 1-6 |

---

# Critical Path

```text
Phase 0 (Spec Lock)
  |
  +---> Phase 1 (Data + Render Foundation)
          |
          +---> Phase 2 (Visual Editor MVP)
          |       |
          |       +---> Phase 3 (Reusable Sections + Templates)
          |       +---> Phase 4 (UX Maturity)
          |       +---> Phase 5 (Dynamic Content)
          |               |
          |               +---> Phase 6 (Extension Platform)
          |
          +---> Phase 7 (Hardening + Governance)
```

---

# Risks and Mitigations

## Risk: Overbuilding before editor usability is proven

Mitigation:

- ship a small high-quality widget set first
- prioritize drag/drop, selection, and inspector usability over long widget lists

## Risk: Schema drift during implementation

Mitigation:

- phase 0 contract freeze
- version every widget and document
- require migration handlers for breaking schema changes

## Risk: Styling chaos

Mitigation:

- token-driven controls only
- presets first, arbitrary CSS later if ever
- policy gate advanced styling capability

## Risk: Public performance degradation

Mitigation:

- server-side rendering
- cache tags for builder pages
- DOM depth and wrapper discipline
- avoid public runtime dependency on builder UI code

---

# Recommended Outcome

At the end of this roadmap, the CMS should have a dedicated page builder that is:

- intuitive for editors
- structured for developers
- safe for operators
- extensible for modules
- stable across upgrades
