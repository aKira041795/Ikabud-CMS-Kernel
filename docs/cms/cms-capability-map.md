# CMS Capability Map — Actual Contracts vs Route APIs

This document separates what the CMS **actually exposes through contracts** from what it currently exposes only through HTTP routes.

## 1. Declared CapabilityBus contracts

The following capabilities are declared by `modules/cms/module.json` and implemented in `modules/cms/helpers/55-capabilities.php`.

| Capability | Mode | Purpose |
|---|---|---|
| `cms.content.get@1` | `first` | Fetch a content item by ID / request payload |
| `cms.content.list@1` | `first` | List CMS content with filters |
| `cms.content.create@1` | `first` | Create CMS content |
| `kernel.auth.authenticate@1` | `pipeline` | Register CMS authentication into the kernel auth pipeline |
| Capability | Mode | Purpose |
|---|---|---|
| `cms.content.get@1` | `first` | Fetch a content item by ID |
| `cms.content.list@1` | `first` | List CMS content with filters (type, status, limit, offset) |
| `cms.content.create@1` | `first` | Create new CMS content item |
| `cms.media.list@1` | `first` | List media files with search and type filtering |
| `cms.media.upload@1` | `first` | Upload media file (base64-encoded data via capability bus) |
| `cms.builder.get@1` | `first` | Fetch builder document by ID with parsed JSON structure |
| `cms.builder.render@1` | `first` | Render builder document to HTML (preview or publish mode) |
| `cms.settings.get@1` | `first` | Get CMS settings (specific key or all settings) |
| `cms.themes.list@1` | `first` | List available themes (active status + metadata) |
| `kernel.auth.authenticate@1` | `pipeline` | Register CMS authentication into the kernel auth pipeline |

### Declared capability policy

The CMS restricts callers for its content capabilities through `module.json` policy entries.

Allowed callers for `cms.content.get@1` and `cms.content.list@1` currently include:

- `media`
- `search`
- `workflow`

Allowed callers for `cms.content.create@1` and `cms.content.update@1` currently include:

- `content-ingestion`
- `media`
- `search`
- `users`
- `workflow`

### Caller-aware content scope

The content read capabilities now enforce the same ownership rules used by the CMS admin and HTTP API layers when a caller user context is supplied through the capability bus.

- CMS contributors, authors, and other non-editor CMS users are author-scoped.
- CMS editors and above can read any CMS content.
- kernel admin can read any CMS content.

This means `cms.content.get@1` and `cms.content.list@1` should not be treated as public bypasses when they are invoked by allowed modules such as `workflow`.

---

## 2. Consumed capabilities

The CMS depends on these capabilities from other modules / kernel services:

| Capability | Use |
|---|---|
| `kernel.auth.user@1` | current authenticated user context |
| `kernel.audit.record@1` | audit logging |
| `ai.text.generate@1` | AI-generated summary / SEO suggestions |
| `workflow.state.get@1` | workflow state lookup |
| `workflow.transition@1` | workflow transitions |
| `tinymce.assets.get@1` | TinyMCE asset resolution |
| `tinymce.config.get@1` | TinyMCE config resolution |
| `tinymce.html.normalize@1` | TinyMCE HTML normalization |
| `tinymce.html.sanitize@1` | TinyMCE HTML sanitization |

---

## 3. Actual CMS extension hooks

The following hook/filter surfaces are implemented in helper code today.

| Hook | Purpose |
|---|---|
| `cms.builder.widgets` | register builder widgets |
| `cms.builder.dynamic_sources` | register builder dynamic data sources |
| `cms.builder.templates` | register builder starter templates |
| `cms.content.templates` | register public content templates |
| `cms.editor.block_types` | register editor block types |
| `cms.editor.sidebar_fields` | inject extra sidebar fields |
| `cms.admin.nav_items` | extend the CMS admin sidebar |
| `cms.public.head` | inject public `<head>` output |
| `cms.public.render_content` | transform rendered content HTML |
| `cms.content.query_args` | alter public list/query behavior |

See `docs/cms/cms-extension-points.md` for signatures and examples.

---

## 4. Declared events in `module.json`

These events are formally documented by the module manifest.

| Event | Purpose |
|---|---|
| `cms.content.created` | content item created |
| `cms.content.published` | content item published |
| `cms.content.updated` | content item updated |
| `cms.content.deleted` | content item trashed |
| `cms.media.uploaded` | media uploaded |
| `cms.user.created` | CMS user created |
| `cms.settings.updated` | CMS settings changed |

---

## 5. Runtime-emitted events not yet fully declared

The code currently emits additional builder lifecycle events that should also be declared in `module.json` for contract clarity.

| Runtime event | Current source |
|---|---|
| `cms.builder.document.saved` | builder save flow |
| `cms.builder.document.published` | builder publish flow |
| `cms.builder.document.restored` | builder revision restore flow |
| `cms.builder.reusable.saved` | reusable section save flow |
| `cms.content.bulk` | bulk content action flow |

These are real runtime events, but the manifest is not yet fully authoritative for them.

---

## 6. HTTP route/API surface

The CMS implements a much broader HTTP API surface than its CapabilityBus surface.

### Admin pages

- `/cms/admin`
- `/cms/admin/content`
- `/cms/admin/content/create`
- `/cms/admin/content/edit/{id}`
- `/cms/admin/page-builder/create`
- `/cms/admin/page-builder/{id}`
- `/cms/admin/media`
- `/cms/admin/users`
- `/cms/admin/settings`
- `/cms/admin/content-types`
- `/cms/admin/categories`
- `/cms/admin/menus`
- `/cms/admin/customize`
- `/cms/admin/redirects`
- `/cms/admin/import-export`
- `/cms/admin/permissions`
- `/cms/admin/themes`
- `/cms/admin/modules`

- content CRUD, autosave, duplicate, bulk, scheduled publish
- media upload/edit/delete/list
- CMS users
---
- settings save/reset
- content types and field definitions
- categories, tags, menus, menu locations
- saved blocks
- customizer sections and footer preview
- CMS sub-module upload/toggle/delete


Implemented public routes cover:

- public JSON APIs for posts, pages, and content-by-type

---

## 7. Current contract gaps

### Documented too broadly in older docs

Older CMS docs described builder/media/theme capability contracts as if they were already formal bus contracts. They are not.

The CMS runtime is broader than its declared capability surface.

That is acceptable for internal admin routes, but it becomes a problem when:

- emitted runtime events are not declared in the manifest

---
## 8. Recommended next contract work

Priority additions if cross-module reuse is needed:

1. `cms.media.list@1`
2. `cms.media.upload@1`
5. `cms.settings.get@1`
6. `cms.themes.list@1`

2. decide whether bulk-content events are public contract or internal implementation detail
3. keep docs explicit about the difference between route APIs and bus contracts
