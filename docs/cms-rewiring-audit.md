# CMS Rewiring Audit — Current Implementation Snapshot

**Updated:** March 2026

This document records what has already been successfully rewired into the CMS module boundary and what remains as technical debt.

## 1. Rewiring status

### Completed rewires

| Area | Current state |
|---|---|
| CMS lives as a module | complete — implemented in `modules/cms/` |
| Handler/helper split | complete — large monolith split by concern |
| CMS auth into kernel pipeline | complete |
| CMS-owned settings defaults and persistence | complete |
| Public template override model | complete |
| Theme customizer rendering pipeline | complete |
| Public SEO/cache/render context helpers | complete |
| Theme upload/activation/deletion | complete |
| CMS-only sub-module installer boundary | complete |
| Granular CMS permissions UI + enforcement | complete |
| Page builder route/API/rendering integration | complete |

### Remaining rewiring debt

| Area | Gap |
|---|---|
| Theme manifest schema | runtime expects `templates`, bundled theme uses `pageTemplates` |
| Theme assets | public layouts still carry hardcoded theme-slug assumptions |
| Event declarations | builder/runtime events are not fully declared in `module.json` |
| Installer hardening | ZIP extraction needs stronger validation |
| Customizer maintainability | helper remains large and should be split further |

---

## 2. What changed materially since early CMS docs

Earlier CMS docs described theming, caching, and extension seams as gaps or future work. That is no longer accurate.

Implemented now:

- symlink-based active theme resolution
- theme storage under `storage/cms-themes`
- public asset sync to `public/assets/cms/themes`
- customizer-driven header/footer/sidebar/theme layout output
- public response caching with tag invalidation and HTTP validators
- extension hooks for builder/editor/public/admin surfaces

---

## 3. Highest-priority follow-up work

1. fix theme template manifest compatibility
2. add active-theme asset helper and remove hardcoded slug references
3. harden theme/module ZIP extraction and validation
4. declare all runtime builder events in `module.json`
5. review SVG and customizer code safety for multi-tenant environments

---

## 4. Recommended interpretation

The CMS rewiring effort has succeeded at the architectural level.

The remaining work is not about moving code into the right boundary anymore. The remaining work is about:

- hardening
- contract cleanup
- theme/platform ergonomics
- maintainability
