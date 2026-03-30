---
description: Repo-facing implementation spec for the ecommerce customizer-driven entity-view POC
---

# Ecommerce Customizer Entity-View Implementation Spec

## Purpose

This document converts the existing architecture references into an implementation-ready plan for the ecommerce customizer-driven entity-view proof of concept.

It is intentionally repo-facing:

- grounded in the current code paths
- split into actionable phases
- scoped to the POC we actually want to build now
- explicit about what is deferred

The central policy is:

- ecommerce POC work moves toward canonical CMS entity views plus customizer-driven presentation
- native traditional ecommerce theming is not expanded in this iteration
- traditional ecommerce theming will be addressed later, when a true entity-based customizer theme exists

## Primary References

- `docs/theme-entity-view-primer.md`
- `docs/capability-driven-theme-design.md`
- `docs/entity-view-block-schema.md`
- `docs/cms-theme-design-architecture.md`
- `docs/cms-implementation-guide.md`

## Problem Statement

The current codebase can already render ecommerce storefront pages through CMS public rendering and customizer-aware shell logic.

That is useful, but it is still too easy for the runtime to drift into a hybrid mode where:

- ecommerce routes behave like a separate storefront template family
- CMS public shell and customizer logic are partially reused
- theme behavior is split between traditional template assumptions and entity-view assumptions

That hybrid mode is the source of repeated confusion and regressions.

The implementation direction for this POC is to stop treating ecommerce customizer behavior as a compatibility layer and instead make it a first-class public rendering mode.

## Scope

### In Scope

- customizer-driven ecommerce public rendering
- canonical entity-view product detail rendering
- canonical entity-list storefront/category rendering
- approved customizer controls for ecommerce entity presentation
- theme-package support for design defaults, shell styling, and approved block variants
- runtime guardrails that prevent hybrid traditional-plus-entity-view storefront behavior

### Out of Scope

- expanding or polishing traditional native ecommerce template families
- introducing a second storefront render tree owned by themes
- freeform customizer controls that can mutate entity structure arbitrarily
- route-by-route hacks that bypass `cmsPublicContext()` or the CMS-owned public shell pipeline

## Current Code Reality

The current implementation already contains the main seams this plan will build on.

### CMS public render assembly

Current owner:

- `modules/cms/helpers/78-public-context.php`

Current behavior:

- `cmsPublicContext()` preloads customizer state
- public menus, header, footer, sidebar, shell theme settings, canonical `entity_presentation_settings`, and capability data are assembled there
- `entity_presentation_settings` are merged into `theme_settings` only as a downstream compatibility bridge
- customizer fragment work is expected to stay centralized there

### Ecommerce public render bridge

Current owner:

- `modules/ecommerce/helpers/00-init.php`

Current behavior:

- `ecRender()` detects public ecommerce templates
- public ecommerce rendering already delegates into `cmsPublicContext()` and `cmsRender()` inside CMS module context
- this is the correct ownership boundary, but it is still a bridge rather than a mode model

### Canonical entity routes already exist in CMS

Current owner:

- `modules/cms/handlers/90-public.php`

Current behavior:

- `cmsPublicEntityView()` renders through `public/entity.view.disyl`
- `cmsPublicEntityList()` renders through `public/entity.list.disyl`
- entity presentation config is derived from theme/customizer settings

### Ecommerce customizer scope already exists

Current owner:

- `modules/cms/helpers/80-customizer.php`

Current behavior:

- active themes can declare `customizer_scope: ecommerce`
- the customizer helper already distinguishes `native` vs `ecommerce`
- storefront-aware header/footer/search behavior already exists
- canonical `entity_presentation` owns entity-view and entity-list presentation defaults for both native and ecommerce scopes
- Theme Settings is the shell workspace only; canonical list, article, and detail presentation belongs in the `Entities` workspace

### Regression coverage already exists

Current tests:

- `tests/cms_theme_test.php`
- `tests/cms_entity_capability_view_test.php`
- `tests/ecommerce_storefront_media_test.php`

These should remain the primary regression harnesses as the POC hardens.

## Desired End State

At the end of this implementation track:

- ecommerce public pages render through one canonical entity-based presentation path
- the active customizer controls approved ecommerce entity-view presentation decisions
- Theme Settings remains a shell-only control surface while `entity_presentation` owns canonical list/detail/article/product presentation
- the active theme package supplies shell and design-system defaults, not a second application tree
- CMS and ecommerce public pages share one design language under the same active theme
- traditional native ecommerce theming remains a separate deferred track instead of contaminating the POC path

## Authoritative Ownership Model

### CMS owns

- public shell assembly
- theme and customizer state resolution
- canonical entity and entity-list render contracts
- approved presentation profiles and block-variant selection
- migration of legacy theme-owned list/detail presentation settings into canonical `entity_presentation`

### Ecommerce owns

- product, cart, checkout, and storefront domain behavior
- pricing, inventory, and storefront-specific business rules
- route intent for shop/category/product pages

### Theme packages own

- shell styling
- token defaults
- approved block-variant templates
- compatibility with customizer-generated markup

### Themes do not own

- storefront behavior
- entity structure
- canonical list/detail/article presentation state
- a separate ecommerce entity template family for this POC

## Required Architectural Rule

The runtime must choose one public presentation mode for ecommerce storefront requests.

For this POC, the only target mode is:

- `entity_view`

The deferred mode remains:

- `traditional`

The critical rule is that a request must not partially enter both.

## Phase Plan

## Phase 1 — Public Presentation Mode Resolver

### Objective

Create an authoritative runtime decision for ecommerce storefront rendering mode.

### Why first

Without this phase, later work will continue to accumulate as special cases inside `ecRender()`, ecommerce handlers, or theme files.

### Deliverables

- an explicit resolver for ecommerce public presentation mode
- one policy gate that decides whether storefront pages render through canonical entity views
- guardrails that reject or bypass hybrid assumptions cleanly

### Primary file targets

- `modules/ecommerce/helpers/00-init.php`
- `modules/ecommerce/handlers/10-public-shop.php`
- `modules/cms/helpers/80-customizer.php`
- `modules/cms/helpers/78-public-context.php`

### Implementation tasks

1. Add a public mode resolver helper for ecommerce storefront requests.
2. Derive mode from active theme/customizer capability instead of ad hoc route logic.
3. Ensure public ecommerce handlers pass enough route intent into CMS public context for downstream render behavior.
4. Refactor `ecRender()` so it uses the resolver instead of only checking template path prefixes.

### Acceptance criteria

- every public ecommerce request resolves to one declared presentation mode
- no storefront request depends on mixed traditional and entity-view shell assumptions
- mode decisions are traceable in code and testable without UI inspection

### Validation

- extend `tests/cms_theme_test.php` for explicit mode expectations
- add focused tests for mode resolution on shop, category, and product routes

## Phase 2 — Product Detail Cutover To Canonical Entity View

### Objective

Make ecommerce product detail the first fully canonical ecommerce entity-view path.

### Deliverables

- product detail routes render through CMS `entity.view` semantics
- ecommerce business behavior remains intact through capability data and action rules
- customizer-selected `commerce` profile becomes the default product presentation for the POC

### Primary file targets

- `modules/ecommerce/handlers/10-public-shop.php`
- `modules/cms/handlers/90-public.php`
- `modules/cms/helpers/56-entity-capabilities.php`
- `templates/modules/cms/public/entity.view.disyl`
- `storage/cms-themes/entity-commerce-poc/public/entity.view.disyl`
- `storage/cms-themes/entity-commerce-poc/public/blocks/*.disyl`

### Implementation tasks

1. Normalize ecommerce product route context so it maps cleanly onto the CMS entity contract.
2. Remove any remaining product-detail-only assumptions that bypass canonical entity-view rendering.
3. Make sure capability data remains the source of product pricing, inventory, and gallery behavior.
4. Keep CTA and inventory rules explicit and test-backed.

### Acceptance criteria

- product detail markup is produced through the canonical entity-view path
- media ordering follows the documented storefront rule: featured image, then gallery item, then placeholder
- pricing and inventory behavior remain ecommerce-correct
- theme behavior stays limited to styling and approved block/template overrides

### Validation

- extend `tests/cms_entity_capability_view_test.php`
- extend `tests/ecommerce_storefront_media_test.php`
- add route-level regression coverage for product detail render mode

## Phase 3 — Storefront List And Category Cutover To Canonical Entity List

### Objective

Move shop and category pages onto the canonical entity-list model.

### Deliverables

- `/ecommerce/shop` renders as an entity-list presentation, not as a separate storefront layout family
- category and search filtering still behave like ecommerce, but presentation is canonicalized
- the customizer controls storefront list presentation through approved controls only

### Primary file targets

- `modules/ecommerce/handlers/10-public-shop.php`
- `modules/cms/handlers/90-public.php`
- `templates/modules/cms/public/entity.list.disyl`
- `storage/cms-themes/entity-commerce-poc/public/entity.list.disyl`
- `modules/cms/helpers/80-customizer.php`

### Implementation tasks

1. Formalize how ecommerce passes list filters, category intent, and base URLs into the CMS entity-list path.
2. Remove hardcoded storefront layout divergences that are not part of the canonical list contract.
3. Establish approved storefront card/list variants for the POC theme.
4. Keep pagination, search, and category context deterministic.

### Acceptance criteria

- shop, category, and search list views render through one canonical list contract
- storefront cards obey the documented image fallback policy
- category and search state are preserved without reintroducing a separate template engine

### Validation

- extend `tests/cms_theme_test.php`
- extend `tests/ecommerce_storefront_media_test.php`
- add focused coverage for list search/category filter propagation

## Phase 4 — Customizer Entity-View Controls For Ecommerce

### Objective

Expose approved ecommerce entity-view presentation controls directly in the theme customizer.

### Status

Core slice implemented in March 2026. The admin `Entities` workspace now renders from entity-context registry schemas/examples while still persisting the canonical `entity_presentation` payload.

### Deliverables

- registry-backed schema payloads for entity-context catalog and example schemas
- schema-rendered entity workspace controls for layout profile, approved variants, and canonical list/detail/article presentation settings
- capability-aware preview behavior so pricing, inventory, progress, and action affordances only appear for matching contexts
- no freeform structural controls beyond the approved schema
- unchanged persistence contract: saves still target canonical `entity_presentation`

### Primary file targets

- `modules/cms/helpers/57-entity-contexts.php`
- `kernel/EntityContext/ContextRegistry.php`
- `modules/cms/helpers/80-customizer.php`
- `modules/cms/handlers/80-customizer.php`
- `templates/modules/cms/admin/theme-customizer.disyl`
- `tests/entity_context_registry_test.php`
- `tests/cms_customizer_tab_contract_test.php`
- `tests/cms_theme_test.php`
- `storage/cms-themes/entity-commerce-poc/theme.json`

### Implementation tasks

1. Emit `entity_context_catalog_json` and `entity_context_examples_json` from `modules/cms/handlers/80-customizer.php`.
2. Preserve field UI metadata in `ContextRegistry` so select/range/toggle/dependency information survives to the admin template.
3. Render the `Entities` workspace from schema sections/examples in `templates/modules/cms/admin/theme-customizer.disyl` while keeping the existing `entity_presentation` save payload.
4. Keep canonical validation and cache-clearing behavior in the existing customizer/storage path.
5. Update preview panes and tests so they are capability-aware instead of assuming every schema behaves like commerce.

### Acceptance criteria

- the ecommerce customizer can switch approved presentation controls without changing entity structure
- canonical validation continues to reject invalid profile or variant values predictably
- runtime cache behavior remains correct after customizer saves
- schema-driven admin rendering does not create a second storage format or bypass the canonical `entity_presentation` contract

### Validation

- coverage now includes `tests/cms_theme_test.php`
- coverage now includes `tests/cms_customizer_tab_contract_test.php`
- focused registry/schema coverage lives in `tests/entity_context_registry_test.php`

## Phase 5 — Variant And Profile Runtime Hardening

### Objective

Finish the runtime contract work that the docs already identify as required for safe specialization.

### Deliverables

- approved layout-profile resolution for ecommerce entity pages
- approved block-variant resolution with hard failure on invalid or missing variants
- capability contract enforcement in `cmsEntityCapabilityData()`

### Primary file targets

- `modules/cms/helpers/56-entity-capabilities.php`
- `modules/cms/helpers/40-theme-settings.php`
- `modules/cms/helpers.php` or the helper file that owns block/profile resolution
- `templates/modules/cms/public/blocks/*.disyl`
- `storage/cms-themes/entity-commerce-poc/public/blocks/*.disyl`

### Implementation tasks

1. Register approved layout profiles in runtime code, matching the schema doc.
2. Register and validate approved block variants in runtime code, matching the design doc.
3. Enforce capability response contracts before template render.
4. Fail loudly on invalid variant selection rather than silently drifting.

### Acceptance criteria

- layout-profile selection is deterministic and documented
- block variants are allowlisted and test-backed
- capability contract drift is visible at the data boundary instead of inside templates

### Validation

- extend `tests/cms_entity_capability_view_test.php`
- add contract validation coverage for invalid provider responses
- keep `tests/cms_theme_test.php` green under the POC theme

## Phase 6 — Theme Package Cleanup For The POC

### Objective

Remove remaining theme-level structural patterns that imply a second storefront application layer.

### Deliverables

- POC theme remains design-system driven
- theme templates align to canonical entity and entity-list contracts
- customizer-generated shell markup remains first-class in theme CSS

### Primary file targets

- `storage/cms-themes/entity-commerce-poc/theme.json`
- `storage/cms-themes/entity-commerce-poc/style.css`
- `storage/cms-themes/entity-commerce-poc/public/*.disyl`
- `public/assets/cms/themes/entity-commerce-poc/*`

### Implementation tasks

1. Remove or reduce theme-specific storefront structure that duplicates canonical CMS contracts.
2. Keep only approved entity/list overrides, shell components, and styling assets.
3. Ensure published asset copies remain in sync with source theme assets.

### Acceptance criteria

- the POC theme reads as a design-system layer, not a separate storefront engine
- customizer-generated shell/header/footer markup is fully styled without bespoke runtime hacks
- no theme asset or template assumes the old hybrid mode

### Validation

- extend `tests/cms_theme_test.php`
- perform focused visual verification after build or asset sync if needed

## Explicit Deferral Boundary

The following work is deferred on purpose:

- expanding native traditional ecommerce layout/template coverage
- polishing traditional storefront-only templates beyond stability fixes
- treating traditional ecommerce theming as a second active roadmap in this POC

If a requirement appears during this track that only makes sense in traditional mode, it should be documented and deferred unless it is required to keep the current code stable.

## Recommended PR Sequence

To keep the implementation reviewable, the work should land in this order:

1. Phase 1 only
2. Phase 2 only
3. Phase 3 only
4. Phase 4 plus required cache/test updates
5. Phase 5 plus test hardening
6. Phase 6 cleanup

Do not combine Phases 2 through 5 into one batch. The contracts need to harden incrementally.

## Test Strategy

### Keep as primary regression suite

- `tests/cms_theme_test.php`
- `tests/cms_entity_capability_view_test.php`
- `tests/ecommerce_storefront_media_test.php`

### Add focused tests where needed

- ecommerce presentation-mode tests
- customizer entity-view profile validation tests
- block-variant validation tests
- category/search propagation tests for entity-list storefront pages

### Logging discipline

For every phase that changes public rendering:

- clear and inspect `storage/logs/app.log`
- clear and inspect `storage/logs/error.log`
- verify no new ModuleDB denial or customizer-cache regressions are introduced

## Definition Of Done

This implementation track is complete when:

- ecommerce customizer-driven public rendering is a first-class canonical path
- product and listing pages render through canonical entity contracts
- approved customizer controls govern presentation without mutating structure
- the POC theme acts as a design-system layer rather than a second storefront engine
- the runtime no longer drifts into a hybrid traditional-plus-entity-view storefront model

## Summary

This POC should be implemented as a convergence effort, not as a coexistence strategy between two storefront rendering models.

The repo already contains most of the needed seams:

- CMS public context
- canonical entity templates
- ecommerce-to-CMS public render delegation
- scoped customizer behavior
- a POC theme with ecommerce customizer scope

The remaining job is to make that path authoritative, incremental, and test-backed.