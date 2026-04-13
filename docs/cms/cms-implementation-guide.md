# CMS Implementation Guide

**Updated:** March 2026  
**Scope:** Current-state implementation guidance for the CMS runtime, with emphasis on public page loading and render safety.

This guide is the practical companion to [cms-module.md](cms-module.md) and [cms-architecture.md](cms-architecture.md). It is intended for engineers making changes to the CMS public path, builder-integrated pages, theme rendering, and storefront-capability pages.

---

## 1. Principles

The CMS public path should preserve these properties:

- server-rendered HTML is the source of truth
- cache lookup happens before expensive render work
- public GET responses do not hold the PHP session lock longer than necessary
- shared context assembly is centralized
- theme rendering does not mutate active-theme state on the read path
- frontend behavior cannot hide already-rendered content indefinitely

If a change weakens any of those properties, it should be treated as a regression until proven otherwise.

---

## 2. Public request flow

The expected request flow for CMS public pages is:

1. Resolve route and content entity.
2. Check CMS page cache before expensive rendering.
3. Build shared render context with `cmsPublicContext()`.
4. Render through `cmsPublicRender()` or `cmsRenderThemeAwareTemplate()`.
5. Persist HTML with cache tags plus `ETag` / `Last-Modified` metadata.
6. Return the body through `cmsPublicRespond()`.

### Required convention

Public HTML handlers should not `echo` directly on their main success path. Use `cmsPublicRespond()` so the response body is sent consistently and `releaseSessionAfterRender()` can release the session lock after render.

Primary implementation files:

- `modules/cms/handlers/90-public.php`
- `modules/cms/helpers/60-cache.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/cms/helpers/40-theme-settings.php`

---

## 3. Shared context rules

`cmsPublicContext()` is the shared enrichment layer for public templates.

### Keep in `cmsPublicContext()`

- site settings needed by layouts
- menu HTML
- customizer-derived fragments
- theme-layout CSS/custom code
- capability context and capability data for entity pages
- cart gate context used by capability-driven CTAs

### Do not duplicate outside it

- per-section customizer fetches in handlers
- repeated `cmsDb()` calls for the same public request when the helper already has a DB handle
- ad hoc cart-action URL computation in templates or handlers

### Performance conventions already implemented

- preload the customizer bundle once
- reuse a shared DB handle inside the helper
- skip absent customizer sections quickly
- skip customized sidebar rendering for builder-enabled pages
- keep fragment-level timing logs opt-in with `CMS_PUBLIC_CONTEXT_TIMING_VERBOSE`

---

## 4. Theme render rules

The public theme alias is `_cms_active_theme`, with `templates/_cms_active_theme` retained as a compatibility symlink.

### Current split

- **Request-time render path**: resolve `_cms_active_theme` against the current request's active theme in code
- **Exclusive lock**: activation, reset, or explicit compatibility-symlink repair

### Rules

1. Do not depend on mutating `templates/_cms_active_theme` during normal public renders.
2. Resolve `_cms_active_theme` from request context so route-scoped or tenant-scoped theme selection cannot fight over one global symlink target.
3. Keep compatibility-symlink mutation on explicit activation/reset paths only.

Primary implementation file:

- `modules/cms/helpers/40-theme-settings.php`

This prevents read/write lock re-entry deadlocks and keeps multi-tenant theme state isolated.

---

## 5. Cache and invalidation rules

Public pages should remain cacheable server-rendered HTML.

### Requirements

- include tag-based invalidation for the affected entity/type/page
- keep `ETag` and `Last-Modified` metadata with cached HTML
- invalidate related caches when content, builder documents, menus, or relevant settings change

### When changing public output, review these invalidation sources

- `cmsCacheInvalidateContent()`
- builder publish/save invalidators
- `cms.settings.updated` listeners
- menu update invalidators if layout/menu output changes

Primary implementation files:

- `modules/cms/helpers/60-cache.php`
- `modules/cms/handlers/35-api-content.php`
- `modules/cms/handlers/50-api-settings.php`
- `modules/cms/handlers/70-menu.php`

---

## 6. Frontend visibility rules

The backend may finish rendering correctly while the page still appears blank if CSS or JS keeps content hidden.

### Required conventions

- if a layout uses animation gating such as `[data-animate]` or a `body:not(.cz-loaded)` concealment pattern, it must also include a synchronous reveal fallback
- include a `<noscript>` reveal rule for no-JS and stalled-JS paths
- include a small inline fallback script that marks the page visible at `DOMContentLoaded`

Primary layout files:

- `templates/modules/cms/layouts/public.disyl`
- `storage/cms-themes/native-default/layouts/public.disyl`

Any new theme layout should preserve the same visibility guarantees.

---

## 7. DiSyL template rules for critical states

Critical availability or CTA decisions should avoid complex boolean expressions.

### Prefer

- small nested `if` branches
- explicit in-stock and out-of-stock cases
- conditions that mirror the exact shape of capability data

### Avoid

- compound expressions mixing `not`, `and`, and nested property reads for critical CTAs
- logic that depends on multiple negations in one expression

This rule exists because the storefront CTA bug reproduced with correct inventory data but an incorrectly evaluated compound DiSyL condition.

Primary templates affected:

- `templates/modules/cms/public/blocks/action.block.disyl`
- `templates/modules/ecommerce/public/product.disyl`

---

## 8. Capability-driven storefront rules

Ecommerce storefront pages can render through CMS universal entity views.

That means product stock, price, media, and CTA behavior may depend on CMS capability providers, not only ecommerce-native templates.

### Implication

When debugging storefront rendering, inspect both:

- ecommerce helpers and routes
- CMS capability-data providers and universal blocks

In particular, inventory state for CMS-driven product pages must follow ecommerce threshold rules.

Primary implementation files:

- `modules/ecommerce/handlers/10-public-shop.php`
- `modules/cms/helpers/56-entity-capabilities.php`
- `templates/modules/cms/public/blocks/inventory.block.disyl`
- `templates/modules/cms/public/blocks/action.block.disyl`

---

## 9. Observability rules

Use timing logs to debug public performance without leaving production logs noisy by default.

### Available flags

- `APP_TIMING_LOGS`
- `APP_TIMING_THRESHOLD_MS`
- `CMS_PUBLIC_RENDER_TIMING`
- `CMS_PUBLIC_RENDER_TIMING_THRESHOLD_MS`
- `CMS_PUBLIC_CONTEXT_TIMING`
- `CMS_PUBLIC_CONTEXT_TIMING_THRESHOLD_MS`
- `CMS_PUBLIC_CONTEXT_TIMING_VERBOSE`
- `CMS_THEME_TIMING_LOGS`
- `CMS_THEME_TIMING_THRESHOLD_MS`

### Conventions

- keep total timing logs available for high-level diagnosis
- use `CMS_PUBLIC_CONTEXT_TIMING_VERBOSE=true` only when you need fragment-by-fragment breakdowns
- correlate production incidents with `storage/logs/app.log`, `storage/logs/error.log`, and request IDs

---

## 10. Implementation checklist

Before merging a change that touches CMS page loading, confirm:

- the public response path still ends at `cmsPublicRespond()`
- cache lookup still happens before the expensive render path
- no extra customizer round-trips were introduced outside `cmsPublicContext()`
- theme read paths do not mutate the active symlink
- builder pages still render without depending on JS for basic visibility
- critical CTA logic uses explicit branches
- related cache invalidation still fires
- targeted tests cover the changed render path

Useful tests:

- `tests/cms_cache_test.php`
- `tests/cms_theme_test.php`
- `tests/cms_entity_capability_view_test.php`
- `tests/ecommerce_storefront_media_test.php`

---

## 11. When to update this guide

Update this document when the CMS public-loading contract changes, including:

- response lifecycle changes
- cache/invalidation behavior changes
- theme lock behavior changes
- builder/layout visibility changes
- capability-driven CTA or storefront conventions

If the runtime changes and this guide is not updated, the guide is wrong.