# CMS Akira Suite

CMS Akira is the decomposed CMS suite for Ikabud.
It keeps independently governed modules in one product family while preserving module boundaries.

## What Is New

- Capability-first composition from `cms-akira-core`.
- Optional provider modules with graceful fallback behavior.
- Profile modules for deployment shape (`minimal`, `standard`, `visual`, `headless`).
- Grouped suite layout under `modules/cms-akira/*` for cleaner extensibility.

## Suite Layout

```
modules/cms-akira/
├── cms-akira-core/
├── cms-akira-seo/
├── cms-akira-ai/
├── cms-akira-editor/
├── cms-akira-theme/
├── cms-akira-navigation/
├── cms-akira-workflow/
├── cms-akira-search-adapter/
├── cms-akira-media/
├── cms-akira-builder/
└── cms-akira-profile-*/
```

## Module Roles

- `cms-akira-core`: Content orchestration, adapter boundary, compose/enrich/runtime status.
- `cms-akira-seo`: SEO metadata provider.
- `cms-akira-ai`: AI summary/provider layer.
- `cms-akira-editor`: Content preparation/editor hooks.
- `cms-akira-theme`: Theme resolution provider.
- `cms-akira-navigation`: Navigation resolution provider.
- `cms-akira-workflow`: Workflow evaluation provider.
- `cms-akira-search-adapter`: Search document provider.
- `cms-akira-media`: Media resolution and fallback behavior.
- `cms-akira-builder`: Visual/builder integration surface.
- `cms-akira-profile-*`: Install/enable bundles for runtime posture.

## Provider Adapters (Phase B)

Provider capabilities are **true adapters** over the canonical owners — they
delegate, they do not re-implement:

| Provider | Delegates to |
|---|---|
| `akira.theme.resolve@1` | CMS theme authority (`cmsThemeRuntimeDiagnostics`, active theme, customizer scope, assets) |
| `akira.navigation.resolve@1` | CMS menus/locations (`cmsGetMenus`, `cmsGetMenuItemsTree`, `cmsGetMenuLocations`) |
| `akira.seo.meta.build@1` | CMS SEO head builder (`cmsResolveSeoTitle`, `cmsDefaultSeoHeadHtml`, `cmsStructuredDataJsonLd`) |
| `editor.normalize/sanitize@1` | CMS editor contracts (`cmsEditorNormalizeHtml`, `cmsEditorSanitizeHtml` → `tinymce.html.*`) |
| `editor.assets@1` | CMS TinyMCE resolver (`cmsTinyMceAssets` → `tinymce.assets.get@1`) |
| `akira.media.resolve@1` | CMS media resolution (`cmsResolveUploadUrl`, `cms_media` read) |
| `akira.workflow.evaluate@1` | Kernel workflow (`workflow.state.get@1` under the CMS module context) |
| `akira.search.document.build@1` | Search indexer document contract (`searchStrip`, optional `search.index.upsert@1`) |

Each adapter returns `resolved_from` (`cms` / `kernel` / `search`) and keeps a
minimal derived fallback so the provider boundary degrades gracefully when the
canonical source is unavailable. Ownership of the underlying data stays with
the canonical module until the Phase 6+ handoffs.

## Standalone Modules

Any CMS Akira module that owns users/auth is a standalone tenant-entry module, not a shared library module.

- It must declare `auth_owned` in `module.json`.
- It must be selected in Admin > Tenants for each new tenant that should receive that module bundle.
- The tenant dropdown is the provisioning gate that tells the kernel which standalone module bundle to seed.
- Do not treat an auth-owned module as an optional add-on; that bypasses the provisioning contract.

## Install and Enable

```bash
# 1) Run Akira migrations
php ikabud migrate cms-akira-core
php ikabud migrate cms-akira-seo
php ikabud migrate cms-akira-media

# 2) Enable core first, then providers/profiles
php ikabud module:enable cms-akira-core
php ikabud module:enable cms-akira-seo
php ikabud module:enable cms-akira-media
php ikabud module:enable cms-akira-profile-standard
```

## Scaffolding New Akira Submodules

Use explicit suite declaration so placement is authoritative:

```bash
php ikabud make:module cms-akira-analytics --suite=cms-akira
```

This creates:

```
modules/cms-akira/cms-akira-analytics/
```

and writes this to `module.json`:

```json
{
  "id": "cms-akira-analytics",
  "suite": "cms-akira"
}
```

## Safety Rules

- Suite container is namespace-only; it is not a shared runtime module.
- Reusable logic must stay module-owned or capability-exposed.
- Do not use suite folders to bypass manifest dependency contracts.
- If `modules/<suite>/module.json` exists, nested suite scaffolding is blocked.
- **Do not declare `admin_contributions` for a module whose admin page is still the scaffold placeholder.** The CMS sidebar only gets a contribution when the module has a real admin surface. Provider modules without one (SEO, AI, Workflow, etc.) operate purely through capability contracts and must not inject dead nav.
- Theme authority stays with the CMS module (customizer at `/cms/admin/customize`, theme library at `/cms/admin/themes`) until the Phase 6 ownership handoff. `cms-akira-theme` currently exposes `akira.theme.resolve@1` on top of it.

## Validation Commands

```bash
php tests/cms_akira_deploy_readiness_test.php
php tests/cms_akira_provider_boundary_health_test.php
php tests/cms_akira_phase5_6_compose_test.php
php tests/cms_akira_phase7_media_resilience_test.php
php tests/infrastructure_test.php
php ikabud architecture:check
```

## Related Docs

- `docs/kernel/entity-view-adoption-plan.md`
- `docs/kernel/kernel-os-disyl-roadmap-status.md`
- `docs/kernel/module-development-guide.md`
