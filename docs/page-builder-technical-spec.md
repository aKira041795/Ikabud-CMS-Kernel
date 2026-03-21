---
description: Page Builder Technical Specification — Dedicated Visual Builder for CMS Pages
---

# Page Builder Technical Specification

This document defines the target technical architecture for a dedicated **visual page builder** inside `modules/cms/`, designed to deliver an Elementor-class editing experience while preserving Ikabud Kernel boundary rules, deterministic rendering, and upgrade safety.

## Current Implementation Status

The CMS already contains a **transitional page-builder implementation** in production code. That current implementation includes:

- dedicated admin routes:
  - `/cms/admin/page-builder/create`
  - `/cms/admin/page-builder/{id}`
- a dedicated builder template:
  - `templates/modules/cms/admin/page-builder.disyl`
- save endpoints:
  - `/api/v1/cms/page-builder`
  - `/api/v1/cms/page-builder/{id}`
- builder state stored primarily in content meta and legacy structured content fields:
  - `_builder_enabled`
  - `_builder_content`
  - `_builder_page_settings`
  - `_template`
  - fallback through `cms_content.blocks_json`
- reusable/saved block support through `cms_saved_blocks`

This existing implementation is useful and should be treated as a **transitional foundation**, not discarded history.

### Implementation Note (Mar 2026): Entrance + Hover Animation Interoperability

A production compatibility issue was resolved where entrance and hover effects could cancel each other when both targeted `transform` on the same element.

What changed:

- frontend entrance completion reset in `modules/cms/animation-definitions.php` was relaxed from `transform: none !important;` to `transform: none;`
- builder preview rendering in `modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx` now separates hover transition styles (outer wrapper) from entrance animation styles (inner wrapper)
- builder assets were rebuilt to publish the preview/runtime update

Result:

- entrance and hover effects now coexist in both builder preview and frontend runtime for the same node

See [Animation interoperability fix in roadmap](page-builder-roadmap.md#animation-interoperability-fix-mar-2026) for acceptance status.

## Target Architecture Status

The architecture in this document describes the **next-stage source of truth**:

- versioned builder documents
- builder-specific revisions
- builder-specific reusable sections/templates
- document/registry-based rendering and validation

During Phase 1, the new builder-document foundation should be introduced **alongside** the current transitional builder so migration can happen safely.

---

# 1. Objective

Build a **dedicated page builder subsystem** for CMS pages that:

- provides a visual drag/drop editing experience
- stores page structure as a governed, versioned document model
- renders public output server-side through trusted renderers
- integrates with themes, revisions, caching, and CMS permissions
- allows future module-driven widget extension without weakening kernel/module isolation

---

# 2. Scope

## In Scope

- dedicated builder flow for CMS pages
- page-level builder documents
- visual canvas + structure tree + inspector sidebar
- layout primitives: section, container, columns/grid
- content widgets: heading, text, image, button, gallery, embed, divider, spacer, CTA, FAQ, testimonials
- responsive controls
- reusable sections and page templates
- draft/publish/preview/revision support
- server-side public rendering
- extension through formal CMS contracts

## Out of Scope for v1

- posts builder enablement
- full theme builder (header/footer/archive builder)
- arbitrary JavaScript injection
- unrestricted custom CSS authoring
- absolute-positioned freeform design canvas
- global cross-page live sections with instant fanout updates
- third-party marketplace support

---

# 3. Architectural Principles

## 3.1 Dedicated Builder, Not a Toggle

The builder is a separate editing experience from the standard content editor.

## 3.2 Structured Source of Truth

The source of truth is a versioned **builder document**, not rendered HTML.

## 3.3 Governed Flexibility

Editors get visual control, but only through typed nodes, approved props, and theme-bound style systems.

## 3.4 Deterministic Rendering

Public output is rendered server-side from trusted renderers and stable schemas.

## 3.5 Kernel-Safe Extension

The CMS may expose builder extension points, but modules interact only through capabilities, hooks, and events.

## 3.6 Versioned Evolution

All builder documents and widgets must support schema evolution through explicit versioning and migration.

---

# 4. System Boundaries

## Kernel Responsibilities

- routing
- auth and permission infrastructure
- capability bus
- hooks/event bus
- cache primitives
- rendering infrastructure
- request context
- audit trail capability

## CMS Responsibilities

- page/content CRUD
- builder document ownership
- builder registry and validation
- builder editor UI
- public page rendering integration
- template resolution for public output
- reusable section management
- builder-specific revisions and previews

## Module Responsibilities

- optional registration of additional builder widgets via CMS-defined extension contracts
- optional dynamic data providers or template packs
- no direct access to builder internals or renderer mutation without contract

---

# 5. Domain Model

## 5.1 CMS Content Record

The existing `cms_content` row remains the top-level content entity.

Additional content-level semantics:

- `type = 'page'`
- `content_mode = 'standard' | 'builder'`
- `builder_document_id` nullable reference to active builder document
- `body` remains legacy fallback for standard pages and backward compatibility

Transitional compatibility notes:

- current code still uses `_builder_enabled` meta to decide whether builder rendering should override standard content rendering
- current code still reads builder content from `_builder_content` with fallback to `blocks_json`
- Phase 1 should preserve these compatibility paths while introducing canonical builder documents

## 5.2 Builder Document

Represents the canonical page layout tree.

Suggested fields:

- `id`
- `content_id`
- `schema_version`
- `document_version`
- `status` (`draft`, `published`)
- `title`
- `document_json`
- `render_hash`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Transitional compatibility note:

- the current system already has `cms_saved_blocks`, which should be treated as an earlier reusable-content primitive and mapped/migrated rather than ignored

## 5.3 Builder Revision

Stores immutable snapshots for rollback and history.

Suggested fields:

- `id`
- `builder_document_id`
- `revision_number`
- `snapshot_json`
- `note`
- `created_by`
- `created_at`

## 5.4 Reusable Section

Stores insertable document fragments.

Suggested fields:

- `id`
- `name`
- `slug`
- `fragment_json`
- `scope` (`personal`, `shared`, `global`)
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

---

# 6. Document Model

## 6.1 Canonical Structure

The page builder document is a nested tree:

- document
  - section[]
    - container[]
      - widget[]

Nested containers are allowed where declared by schema.

## 6.2 Node Contract

Every node must include:

- `id`
- `type`
- `kind`
- `version`
- `props`
- `style`
- `responsive`
- `children`
- `visibility`
- `meta`

## 6.3 Kinds

### Document Root

Single root wrapper containing metadata and top-level children.

### Section

Top-level layout region.

Responsibilities:

- background
- width behavior
- outer spacing
- content containment
- section presets

### Container

Nested layout wrapper.

Responsibilities:

- direction
- alignment
- gap
- width
- columns/grid settings
- child orchestration

### Widget

Atomic content/function block.

Examples:

- heading
- text
- image
- button
- gallery
- embed
- divider
- spacer
- CTA
- FAQ
- testimonial
- posts-list

## 6.4 Node Semantics

### `props`

Contains semantic configuration required by the renderer.

### `style`

Contains allowed style overrides mapped to tokens, scales, presets, or constrained values.

### `responsive`

Contains breakpoint-specific overrides for supported keys only.

### `children`

Contains child nodes only when the node definition allows nesting.

### `meta`

Contains editor-only information and must not be relied upon for public rendering semantics.

---

# 7. Widget Registry

## 7.1 Purpose

The widget registry defines what can exist in a builder document and how it behaves in the editor and renderer.

## 7.2 Required Widget Definition Fields

Each widget definition must declare:

- `id`
- `version`
- `label`
- `icon`
- `category`
- `kind`
- `supports_children`
- `allowed_parents`
- `allowed_children`
- `default_props`
- `prop_schema`
- `style_schema`
- `responsive_schema`
- `inspector_schema`
- `renderer`
- `capabilities_required`
- `availability_rules`
- `migration_handlers`

## 7.3 Initial Core Widgets

### Layout

- `section`
- `container`
- `columns`

### Basic Content

- `heading`
- `text`
- `image`
- `button`
- `divider`
- `spacer`
- `icon`

### Marketing

- `cta`
- `faq`
- `testimonial`
- `gallery`
- `video`
- `embed`

### Dynamic / Later Phase

- `posts-list`
- `featured-content`
- `breadcrumbs`
- `dynamic-field`

## 7.4 Registry Governance

- all widgets require schema validation
- all widgets require a trusted server renderer
- all widgets require upgrade semantics
- all widgets must declare allowed placement rules
- privileged widgets such as `raw-html` require explicit policy gating

---

# 8. Styling Model

## 8.1 Design System First

The builder must consume design tokens rather than create arbitrary per-page styling rules.

## 8.2 Styling Layers

### Global Tokens

Examples:

- color tokens
- typography scale
- spacing scale
- radius tokens
- shadow tokens
- container widths
- breakpoints

### Widget Presets

Examples:

- button variant: primary, secondary, outline
- section variant: default, muted, accent
- heading scale: hero, section, card-title

### Local Overrides

Allowed within constraints:

- spacing from predefined scale
- text alignment
- width/alignment options
- background/color selections from token map
- typography size selections from predefined scale

## 8.3 Explicit Restrictions

The builder must not default to:

- arbitrary CSS strings
- arbitrary class injection
- arbitrary script injection
- unrestricted breakpoint CSS authoring

---

# 9. Public Rendering Pipeline

## 9.1 Flow

1. resolve CMS page
2. detect `content_mode`
3. load active builder document if page is builder-backed
4. validate or migrate document schema version
5. construct render context
6. recursively render node tree using registry renderers
7. apply optional CMS filters
8. cache final HTML and dependency metadata

## 9.2 Rendering Requirements

- render server-side for SEO and performance
- sanitize all output-sensitive values
- avoid editor-originated DOM wrappers in final markup
- produce consistent class structures
- support theme-aware template composition

## 9.3 Backward Compatibility

If `content_mode = 'standard'` or no builder document exists, the CMS continues rendering legacy page content.

---

# 10. Validation and Migration

## 10.1 Validation Stages

### Save-Time Validation

- schema structure valid
- nodes allowed in their current parents
- required props present
- disallowed values rejected

### Publish-Time Validation

- renderer availability confirmed
- privileged widgets policy-checked
- dynamic bindings resolvable

### Load-Time Validation

- outdated schema detected
- migration candidates identified

## 10.2 Versioning Rules

- builder documents carry `schema_version`
- widgets carry `version`
- breaking widget changes must ship with migration handlers
- the public renderer must not silently reinterpret incompatible nodes

---

# 11. Security Model

## 11.1 Editor Security

- permission-gated builder routes
- CSRF enforcement on non-API mutations
- server-side validation for all save/publish actions
- audit record for publish, restore, delete, template apply, and reusable section mutation

## 11.2 Render Security

- trusted renderer map only
- sanitized HTML output
- URL allowlisting for embeds where appropriate
- restricted advanced widgets for privileged roles only

## 11.3 Raw HTML Policy

If a `raw-html` widget exists:

- disabled by default
- available only to privileged users
- sanitized aggressively
- clearly marked as advanced and risky

---

# 12. Performance Model

## 12.1 Public Performance Goals

- cacheable server-rendered HTML
- minimal frontend JS required for published pages
- bounded DOM depth
- bounded style output

## 12.2 Cache Strategy

Builder pages should integrate with CMS cache tags using builder-aware invalidation.

Suggested tags:

- `cms:page:{slug}`
- `cms:content:{id}`
- `cms:builder:document:{id}`
- `cms:builder:reusable:{id}`
- `cms:builder:template:{slug}`

## 12.3 Invalidators

Invalidate builder pages on:

- page publish/update/delete
- builder document publish/update
- reusable section update if globally linked in future
- theme switch or token changes affecting public page output

---

# 13. Extension Architecture

## 13.1 Approved Extension Surfaces

- widget registration
- widget renderer registration through CMS-controlled hooks/capabilities
- dynamic data provider registration
- reusable template pack registration
- inspector preset extension

## 13.2 Disallowed Extension Behavior

- direct mutation of builder documents outside CMS API
- direct renderer override without contract
- injection of arbitrary editor code without schema and boundary review
- direct reads/writes to other module tables outside declared contracts

---

# 14. Recommended Implementation Shape

## 14.1 CMS Module Subdomains

The builder should be decomposed into dedicated builder concerns inside `modules/cms/`.

Suggested internal layout:

- `builder/registry.php`
- `builder/validators.php`
- `builder/renderers.php`
- `builder/templates.php`
- `builder/revisions.php`
- `builder/reusable-sections.php`
- `handlers/builder.php`
- `templates/modules/cms/admin/builder.disyl`

Transitional note:

- current builder logic is still spread across `modules/cms/helpers.php`, `modules/cms/handlers.php`, `modules/cms/routes.php`, and `templates/modules/cms/admin/page-builder.disyl`
- Phase 1 should prioritize adding the new persistence and API foundation first; large internal refactors can follow once the new document model exists

## 14.2 Dependency Direction

```text
kernel
  -> routing/auth/hooks/capabilities/cache/render/audit
modules/cms
  -> owns page builder orchestration, schemas, rendering, templates, revisions
other modules
  -> register builder extensions only through CMS-defined contracts
```

---

# 15. Non-Negotiable Invariants

- builder data is module-owned by CMS
- no kernel-level builder-specific state is required for v1
- public builder rendering must be deterministic and server-side
- builder documents are versioned and validated
- extensions are contract-based, not ad hoc
- pages-first rollout remains the default path
- standard pages continue to work without builder adoption

---

# 16. Recommended Outcome

The page builder should ship as a **governed visual composition system** that gives editors an Elementor-like experience while preserving platform correctness, long-term maintainability, and kernel/module separation.
