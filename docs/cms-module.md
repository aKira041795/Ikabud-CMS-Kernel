# CMS Module — Current State Guide

**Updated:** March 2026  
**Module root:** `modules/cms/`

This document is the authoritative current-state guide for the CMS module as it exists today.

## 1. What the CMS is

The CMS is a full application module that provides:

- CMS authentication integrated into the kernel auth pipeline
- Content CRUD for posts, pages, and custom content types
- Dynamic field definitions and content-type registry
- Media library with upload, edit, delete, thumbnails, and usage tracking
- Categories, tags, menus, redirects, import/export, revisions, and permissions
- A dedicated React page builder for page content
- Public website rendering with theme overrides, customizer output, caching, RSS, sitemap, and search
- Theme upload/activation and CMS-only sub-module installation

The CMS is **not** a kernel subsystem. It is a module that extends the platform through declared routes, capabilities, events, hooks, templates, and owned tables.

---

## 2. Current feature map

### Admin surfaces

Implemented admin areas include:

- Dashboard
- Content list and editor
- React builder create/edit flow
- Media library
- Users
- Settings
- Content types and field definitions
- Categories and tags
- Menus and menu locations
- Redirects
- Import / export
- Permissions and role overrides
- Theme customizer
- Theme manager
- CMS sub-module manager
- **AI Content Automation** (`/cms/admin/ai-automation`) — manage content plans, view run history, configure search grounding

Primary route definitions live in `modules/cms/routes.php`. Primary admin handlers live in `modules/cms/handlers/15-admin.php`, `modules/cms/handlers/80-customizer.php`, `modules/cms/handlers/82-permissions.php`, and `modules/cms/handlers/84-extensions.php`.

### Content and builder

Implemented content features include:

- Create, update, autosave, duplicate, bulk actions, trash, restore, publish, scheduled publish
- Dynamic custom fields via `cms_content_types`, `cms_field_definitions`, and `cms_content_fields`
- Revisions for classic content and builder documents
- Builder drafts, published documents, reusable sections, starter templates, dynamic sources, and server-rendered preview

### Public website

Implemented public features include:

- Home page and blog archive
- Static pages and single posts
- Category and tag archives
- Search
- Sitemap XML and RSS feed
- Slug redirects
- SEO head output and structured-data helpers
- Theme-based template overrides
- Customizer-driven header, footer, sidebar, layout, colors, fonts, and custom code
- Tag-based cache plus `ETag` / `Last-Modified`

---

## 3. Module architecture

### Entry points

- `modules/cms/module.json` — manifest, owned tables, migrations, capabilities, events, nav
- `modules/cms/routes.php` — route map
- `modules/cms/handlers.php` — handler loader
- `modules/cms/helpers.php` — helper loader

### Split file organization

#### Handlers

- `handlers/10-auth.php` — login bridge and auth-adjacent flows
- `handlers/15-admin.php` — dashboard and core admin screens
- `handlers/20-admin-builder.php` / `20-api-builder.php` — page builder UI + APIs
- `handlers/30-api-content-types.php` — content type + field registry
- `handlers/35-api-content.php` / `36-api-content-actions.php` — CRUD, publish, autosave, duplicate, bulk
- `handlers/40-api-media.php` — media APIs
- `handlers/45-api-users.php` — CMS users
- `handlers/50-api-settings.php` — settings persistence
- `handlers/60-taxonomy.php` — categories and tags
- `handlers/70-menu.php` — menus and locations
- `handlers/72-saved-blocks.php` — saved blocks
- `handlers/74-revisions.php` — revisions
- `handlers/80-customizer.php` — theme customizer
- `handlers/82-permissions.php` — granular role permissions
- `handlers/84-extensions.php` — theme installer and CMS sub-module installer
- `handlers/90-public.php` — public routes and headless APIs

#### Helpers

- `helpers/05-permissions.php` — capability map, role checks, `cmsRequireCap()`
- `helpers/40-theme-settings.php` — theme discovery, symlink activation, template resolution, settings defaults
- `helpers/50-builder.php` — builder document registry, widgets, rendering, templates, dynamic sources
- `helpers/55-capabilities.php` — CapabilityBus adapters exposed by the module
- `helpers/60-cache.php` — cache helpers and invalidation
- `helpers/65-taxonomy.php`, `70-menu.php`, `74-revisions.php` — domain helpers
- `helpers/76-extensions-editor.php` — extension hooks for editor/public/builder integration
- `helpers/78-public-context.php` — shared public render context
- `helpers/80-customizer.php` — customizer rendering and CSS/HTML generation

---

## 4. Data ownership

The CMS owns a large but still bounded data model. Main table groups include:

- Content: `cms_content`, `cms_content_meta`, `cms_content_types`, `cms_field_definitions`, `cms_content_fields`
- Builder: `cms_builder_documents`, `cms_builder_revisions`, `cms_builder_reusable_sections`, `cms_builder_templates`, `cms_saved_blocks`
- Taxonomy/navigation: `cms_categories`, `cms_tags`, `cms_content_categories`, `cms_content_tags`, `cms_menus`, `cms_menu_items`, `cms_menu_locations`
- Users/media: `cms_users`, `cms_media`, `cms_media_usage`
- Operations/theme: `cms_revisions`, `cms_slug_redirects`, `cms_theme_customizer`, `tenant_module_settings`

Cross-module dependencies are declared in `module.json`. The CMS also reads `workflow_definitions`, `kernel_event_triggers`, and `rate_limits`, and depends on kernel capabilities plus AI / workflow / TinyMCE module contracts.

---

## 5. Theme system summary

### Theme storage and activation

- Theme source of truth: `storage/cms-themes/{slug}/`
- Active theme mount point: `templates/_cms_active_theme`
- Public assets copy target: `public/assets/cms/themes/{slug}/`
- Active theme slug is persisted in CMS settings as `active_theme`, with tenant-scoped overrides when multi-tenancy is active

### Theme behavior

- Only **public** templates are themeable
- Admin templates are not overridden by themes
- Resolution is override-first: active theme, then CMS defaults
- Theme customizer data is stored in `cms_theme_customizer`

For the full theme contract, see `docs/cms-theme-design-architecture.md`.

---

## 6. Extension model

The CMS uses four extension layers:

1. **Capabilities** — synchronous cross-module contracts
2. **Hooks** — extension seams for editor, builder, admin nav, public head, content rendering, and query args
3. **Events** — fire-and-forget notifications for content, builder, media, and settings lifecycle
4. **CMS sub-module installer** — tenant-scoped ZIP-based add-on installer with per-tenant registry

Important distinction:

- Some features are implemented as **HTTP routes only**
- A smaller subset is exposed as **CapabilityBus contracts**

Do not assume that a route-backed feature is automatically callable via a capability.

### CMS sub-module ZIP installer

CMS sub-modules are add-ons installed through the CMS admin UI (`/cms/admin/modules`) or the API (`POST /api/v1/cms/modules/upload`). Key behaviors:

- Registry is **per-tenant** — stored in `tenant_module_settings` (`module_id='cms'`, `setting_key='_installed_submodules'`).
- Enable/disable state is **per-tenant** — stored in `tenant_module_settings` (`module_id='<id>'`, `setting_key='_module_enabled'`).
- The module directory is **shared on disk** (`modules/<id>/`). A `.cms-owned` marker file is written by the installer on first extract to identify it as CMS-managed.
- In multi-tenant environments, each tenant maintains an independent registry. Deleting a module in tenant A does not remove the disk directory or affect tenant B.
- The installer guards against overwriting kernel/bundled modules: true kernel modules (no `.cms-owned` marker) cannot be overwritten via ZIP upload.
- **Superadmin visibility**: The superadmin settings page shows CMS sub-module settings per tenant. For tenants whose `entry_module_id` is `cms`, the page displays settings for the CMS module itself plus any modules listed in that tenant's `_installed_submodules` registry. This allows cross-tenant management of CMS add-on configuration without switching tenant contexts.

See `docs/module-development-guide.md` → *Packaging & Installation* for the full ZIP format spec, API reference, cross-tenant adoption details, and multi-tenant-safe delete behavior.

See `docs/cms-capability-map.md` and `docs/cms-extension-points.md` for capabilities, hooks, and events.

---

## 7. Security and tenancy notes

### Good current protections

- CSRF protection on mutating admin operations
- Granular CMS permissions via `cmsRequireCap()`
- MIME allowlist, upload size limits, thumbnail generation, and dangerous-signature checks for media
- CMS module installer now only manages **CMS-installed** sub-modules, not kernel/application modules
- Theme activation is isolated to the CMS theme mount point and module settings
- Tenant-scoped CMS settings now persist through `tenant_module_settings` with global fallback in `storage/modules.json`
- Public theme rendering uses a lock-guarded symlink flow so concurrent tenant requests do not leak active theme state
- Theme/module ZIP uploads are validated before extraction (path traversal, absolute paths, null bytes, symlink entries)
- Theme and module manifest validation now enforces required and typed fields during upload
- Installer lifecycle operations now write structured audit entries (`CMS installer audit`) to app logs
- **Cross-tenant module adoption** — when a module is installed by tenant A, subsequent installs by tenant B safely adopt the shared on-disk directory (via `.cms-owned` marker detection) instead of attempting a conflicting re-extract or being incorrectly blocked as a "kernel module"
- **Multi-tenant-safe module delete** — `cmsApiModuleDelete` only unregisters a module from the calling tenant's registry in multi-tenant mode; the shared `modules/<id>/` directory is preserved so other tenants are unaffected
- **Tenant-aware password reset links** — `cmsApiTestResetEmail` (the admin "Send Test Email" action) builds reset URLs using `cmsExternalBaseUrl()` (derives host from the HTTP request), so links sent from any tenant domain correctly point back to that tenant and not to the default `APP_URL`

### Areas that still need hardening

- SVG handling should be sanitized, not only extension-checked / signature-checked
- Arbitrary custom HTML/code in the customizer is powerful but risky in less-trusted tenant-admin environments

---

## 8. Gaps and enhancement opportunities

### High priority

1. **SVG/media hardening**
   - Add SVG sanitization or disallow unsafe SVG content entirely

2. **Extension installer observability refinement**
   - Expand installer audit payload with actor/user identifiers where available
   - Add optional admin-facing audit view for tenant operators

### Medium priority

1. Expand cross-module capabilities for media, builder, settings, and public-read use cases
2. Normalize declared events vs runtime-emitted events so `module.json` is authoritative
3. Harden first-save behavior in builder flows where shell creation depends on client-side orchestration
4. Split customizer helper further to reduce file size and regression risk

### Low priority

1. Improve mobile parity of the admin sidebar
2. Add theme asset manifest/cache-busting support
3. Expand customizer preview endpoints beyond footer-only preview

---

## 9. Documentation map

- `docs/cms-module.md` — current-state module guide
- `docs/cms-architecture.md` — architecture review, risks, and optimization plan
- `docs/cms-capability-map.md` — actual capabilities, hooks, events, and route/API map
- `docs/cms-extension-points.md` — extension hooks and examples
- `docs/cms-roadmap.md` — forward roadmap from the current baseline
- `docs/cms-rewiring-audit.md` — implementation audit and remaining debt
- `docs/cms-theme-design-architecture.md` — theme authoring and architecture guide
- `docs/cms-ai-content-automation.md` — AI content automation: plans, runs, search grounding, content modes
