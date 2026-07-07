# CMS Roadmap — From Working Module to Stable Platform

**Updated:** 2026-07-07

This roadmap starts from the CMS as it exists today. It is no longer an MVP roadmap.

## Current baseline

Already implemented:

- content CRUD, autosave, duplicate, bulk actions, scheduled publish
- content types and field definitions
- media library and metadata editing
- categories, tags, menus, redirects, import/export, revisions, permissions
- public home/blog/page/post/search/feed/sitemap
- React page builder with reusable sections, templates, dynamic sources, and server rendering
- theme discovery, upload, activation, deletion, and customizer
- CMS-only sub-module installer
- public caching with tag invalidation and HTTP validators

The next phase is about **hardening, contract clarity, and theme/platform ergonomics**.

---

## Phase 1 — Hardening and contract cleanup

### Goals

- make current features safer for multi-tenant use
- make runtime contracts match documentation
- remove theme-system inconsistencies

### Work items

1. Add safe ZIP extraction validation for theme/module installers
2. Add SVG sanitization or narrow SVG policy
3. Normalize theme manifest support for `templates` and `pageTemplates`
4. Remove hardcoded theme asset slugs from public layouts
5. Declare currently emitted builder events in `module.json`

### Exit criteria

- installer blocks traversal and invalid archive entries
- theme template registration works consistently for bundled and third-party themes
- public layouts resolve assets through active-theme helpers
- manifest docs and runtime declarations are aligned

---

## Phase 2 — Cross-module contract expansion

### Goals

- expose stable interfaces for other modules
- reduce reliance on internal HTTP coupling for module-to-module integrations

**✅ Done (shipped in CMS 3.0 / Kernel OS 6.1)**

### Candidate additions

- `cms.media.list@1`
- `cms.media.upload@1`
- `cms.builder.get@1`
- `cms.builder.render@1`
- `cms.settings.get@1`
- `cms.themes.list@1`

### Exit criteria

- new capabilities are versioned and documented
- capability policy entries exist where required
- docs clearly distinguish declared contracts from internal HTTP routes

---

## Phase 3 — Builder and editor reliability

### Goals

- harden authoring workflows
- reduce client-side fragility in first-save / draft creation flows

### Work items

1. Server-side builder shell bootstrap for first-save reliability
2. Better publish/preview parity checks
3. More builder validation diagnostics
4. Better template selection and starter-theme compatibility

### Exit criteria

- builder create/save flow works without hidden client orchestration assumptions
- preview and public output match for supported node types

---

## Phase 4 — Theme platform maturity

### Goals

- make third-party theme authoring predictable
- reduce theme-specific brittleness

### Work items

1. Add active-theme asset helper functions
2. Define and enforce a canonical `theme.json` schema
3. Add optional screenshot/support metadata
4. Add asset versioning/cache-busting support
5. Publish a starter theme skeleton

### Exit criteria

- third-party themes can be authored without copying native theme assumptions
- theme docs, manifest schema, and runtime behavior agree

---

## Phase 5 — Multi-tenant operations and policy

### Goals

- make CMS operation safer in tenant-admin environments
- improve observability and operator control

### Work items

1. configurable customizer code policy by tenant/role
2. manual cache flush and cache health tooling
3. improved audit logs for theme/module install/activate/delete flows
4. better tenant-aware restrictions for dangerous admin features

### Exit criteria

- risky features are policy-controlled
- operational actions are auditable and supportable

---

## Phase 6 — Future product features

Not yet first-class today, but viable future modules/features include:

- comments
- form builder or marketing widgets
- commerce/product catalog
- richer search integration
- SEO module as a separate extension package

These should preferably be built as CMS extensions or companion modules, not merged into the CMS core without clear contracts.
