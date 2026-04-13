# CMS Architecture — 2026 Review

This document reviews the CMS as it is actually implemented today and highlights practical optimization opportunities.

## 1. Executive summary

The CMS is now a substantial, working module rather than an MVP. Its strongest architectural properties are:

- clear kernel/module separation
- broad HTTP API surface
- real builder + public rendering pipeline
- theme and customizer support
- tag-based caching on public responses
- granular CMS-local permission enforcement

The main risks are no longer “missing CMS architecture”. The main risks are now **consistency, hardening, and contract drift**.

---

## 2. Runtime composition

### Kernel boundary

The kernel owns routing, auth infrastructure, hooks, event delivery, caching, rendering, and module loading. The CMS consumes those services; it does not bypass them.

### CMS runtime layers

1. `routes.php` maps request paths to handler functions
2. handlers perform auth, permissions, request validation, database work, and rendering/API output
3. helpers centralize theme resolution, builder rendering, cache operations, extension hooks, SEO/public context, and customizer output
4. templates provide admin and public views
5. active themes override only the public view layer through `_cms_active_theme`, which is resolved against the current request's active theme in the helper/template layer; the legacy filesystem symlink is mutated only for explicit activation and compatibility flows

This is a sound layering model and should be preserved.

---

## 3. Public rendering architecture

### Standard content path

Public rendering for posts/pages follows this shape:

1. resolve content from `cms_content`
2. merge meta, taxonomy, media, and settings context
3. resolve the correct template through `cmsResolveTemplate()` / `cmsResolveContentTemplate()`
4. build shared context through `cmsPublicContext()`
5. inject theme/customizer HTML and CSS
6. optionally transform output via CMS hooks
7. cache the response, send HTTP cache validators, and finish through `cmsPublicRespond()` so session locks are released consistently after public renders

### Public-loading conventions now in force

- public GET handlers should render once and return through `cmsPublicRespond()` rather than `echo` directly
- `cmsPublicContext()` is the shared enrichment point and should stay the only place where public theme/customizer fragments are assembled
- `cmsPublicContext()` now resolves shell `theme_settings` and canonical `entity_presentation_settings` separately, then merges entity presentation into `theme_settings` only for compatibility
- `cmsEntityCapabilityRuntimeState()` is now the shared bridge for attached capability rows plus entity-context registry profiles; public handlers, canonical list rendering, builder entity contexts, and entity capability APIs should reuse it instead of diverging on active-flag logic
- customizer data should be preloaded in one pass, then reused for the request; avoid per-section duplicate reads on the hot path
- builder-enabled pages should skip sidebar work that cannot affect the final layout
- public theme rendering should resolve `_cms_active_theme` from the current request context without mutating the legacy symlink; symlink mutation belongs only to explicit activation or repair paths
- animation or page-transition concealment must ship a no-JS / stalled-JS reveal fallback so the page never remains blank after the backend render finishes
- critical CTA or availability decisions in DiSyL should use simple nested branches instead of brittle compound boolean expressions

### Builder path

For builder-enabled pages:

1. builder document is loaded from `cms_builder_documents`
2. builder render helpers convert the document graph to deterministic server HTML
3. output is wrapped in the same public layout and customizer/theme context
4. previews and published output stay aligned because both rely on the server renderer

This is a good SEO-safe architecture.

---

## 4. Theme architecture

The CMS has a working theme system with these rules:

- themes live in `storage/cms-themes/{slug}`
- the active theme is addressed through the `_cms_active_theme` alias, which resolves to the current request's active theme and is still mirrored by `templates/_cms_active_theme` for compatibility
- active theme selection is persisted through module settings, with tenant-specific overrides when multi-tenancy is active
- only public templates are overridden
- theme public assets are copied to `public/assets/cms/themes/{slug}` on install/upgrade
- theme customizer output is not theme-source code; it is database-driven runtime decoration

This model is intentionally lighter than WordPress and fits the module architecture well.

### Current theme pipeline strengths

- override resolution is simple
- activation is explicit and reversible
- themes stay out of admin surfaces
- customizer adds header/footer/sidebar/shell-layout controls plus canonical `entity_presentation` controls without requiring theme authors to rebuild everything
- the customizer `Entities` workspace is now schema-driven from registry examples/capability metadata, so admin controls stay aligned with runtime entity behavior without introducing a second persistence format
- request-scoped `_cms_active_theme` alias resolution keeps normal public renders off the shared symlink path, while the compatibility symlink remains lock-guarded for activation/reset work

### Current theme pipeline weaknesses

- asset URLs are not yet fully abstracted by active theme
- manifest schema is inconsistent for custom page templates (`templates` vs `pageTemplates`)
- installer validation is weaker than it should be for ZIP extraction

---

## 5. Integration architecture

### Declared capabilities

The CMS currently exposes only a narrow set of kernel-callable capabilities:

- `cms.content.get@1`
- `cms.content.list@1`
- `cms.content.create@1`
- `kernel.auth.authenticate@1`

That is smaller than the runtime feature set.

### Hooks

The CMS has real extension seams for:

- builder widgets, templates, and dynamic sources
- editor sidebar fields and block types
- content template registration
- admin navigation extension
- public head/content transformation
- public query customization

This hook surface is useful and already practical.

### Events

The CMS emits content, media, settings, and builder lifecycle events. However, not all runtime-emitted events are declared in `module.json`. That is a contract gap and should be corrected.

---

## 6. Performance architecture

### Current strengths

- public caching exists and is real
- cache invalidation is tag-based
- public pages use `ETag` and `Last-Modified`
- runtime settings and active-theme lookups use lightweight request-level caching
- public handlers now centralize final output through `cmsPublicRespond()`, which releases the PHP session lock after render
- public-context assembly now preloads the customizer bundle, reuses a shared DB handle, and skips absent sections on the request hot path
- theme renders now resolve the active alias from request context without relying on symlink mutation in the normal public hot path, removing a deadlock and lock-contention class from public requests
- timing diagnostics keep total request timings available while detailed fragment timings stay opt-in
- public layouts include reveal fallbacks so frontend animation gating cannot leave the page blank when JS stalls

### Current opportunities

1. Add more targeted cache tags for taxonomy archives, search pages, and menu-dependent pages
2. Add a documented manual cache flush operation in the admin
3. Add cache-busting for theme assets after install/upgrade
4. Keep narrowing remaining repeated theme/customizer reads outside `cmsPublicContext()` and render helpers

---

## 7. Security architecture

### Role hierarchy

`CMS_ROLES` maps role names to numeric levels used by `cmsRoleAtLeast()`. Higher number = more access.

| Role            | Level | CMS Admin Access | Notes                                             |
|-----------------|-------|------------------|---------------------------------------------------|
| `superadmin`    | 100   | ✅ Full          | Kernel-level, cross-tenant                        |
| `administrator` | 90    | ✅ Full          |                                                   |
| `editor`        | 70    | ✅ Full          |                                                   |
| `author`        | 50    | ✅ Limited       |                                                   |
| `contributor`   | 30    | ✅ Limited       |                                                   |
| `subscriber`    | 10    | ✅ `dashboard.view` only | Blog or manual account user          |
| `customer`      | 8     | ❌ Blocked       | Ecommerce-only; can access orders & downloads     |

**Customer role semantics:**
- `customer` (level 8) is below `subscriber` (level 10), so any CMS capability requiring `subscriber` or above automatically blocks customers.
- `ecRequireLogin()` in the ecommerce module requires `cmsRequireRole('customer')` (level ≥ 8), which passes for both customers and all CMS content roles.
- After login, `kernel.home_url` sends customers to `/ecommerce/my-orders` instead of `/cms/admin`.
- Customers can: view their orders, download purchased digital files, access the public storefront.
- Customers cannot: access `/cms/admin`, create content, view the CMS dashboard.

### Current strengths

- permission gates are broad and granular
- admin APIs use CSRF protections
- CMS module installer is now isolated from kernel/application modules
- media uploads have size and MIME restrictions

### Highest-value hardening work

1. **ZIP extraction safety** for theme/module installers
2. **SVG sanitization** or stricter SVG policy
3. **Customizer code policy** for multi-tenant scenarios
4. **Manifest validation** for uploaded themes/modules
5. **Declared event/capability parity** so integrations are auditable

---

## 8. Maintainability review

### What is working well

- split handlers/helpers by concern
- explicit route map
- domain-specific helper files
- public rendering concerns consolidated in the right places

### Main maintainability hotspots

- `helpers/80-customizer.php` is large and should be split by section or output type
- theme asset resolution logic is spread across templates and installer logic
- builder creation and first-save orchestration relies heavily on client behavior
- documentation previously mixed current-state contracts with planned features

---

## 9. Prioritized enhancement plan

### High

1. Normalize theme template manifest support (`templates` and `pageTemplates`)
2. Introduce an active-theme asset helper and remove hardcoded theme slugs from layouts
3. Harden ZIP extraction and module/theme upload validation
4. Sanitize SVG or narrow accepted SVG content
5. Align `module.json` with actual builder events emitted in code

### Medium

1. Expose more CMS features as formal capabilities where cross-module reuse is expected
2. Split customizer helper into smaller concerns
3. Improve builder shell creation / first-save reliability
4. Add theme metadata/schema validation and optional screenshots

### Low

1. Improve admin mobile navigation parity
2. Add preview endpoints for more customizer sections
3. Add theme asset manifests with versioning/cache-busting

---

## 10. Recommended operating principle

The CMS should now be treated as a **working platform** with three priorities:

1. harden what already exists
2. make contracts explicit and reliable
3. improve theme and extension ergonomics without widening the kernel boundary

That is the fastest path to a stable multi-tenant CMS platform.
