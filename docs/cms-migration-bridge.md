# CMS Migration Bridge

## Overview

The **CMS Migration Bridge** is a transitional integration layer that lets tenants who are accustomed to WordPress (or, in a later phase, Joomla) continue editing content in a familiar CMS admin interface while ApplicationOS remains the canonical content store and the only public renderer.

The bridge is **not a permanent embedded CMS runtime**. It exists to ease migration from legacy CMS platforms into the ApplicationOS CMS module. Every piece of content that passes through the bridge is destined for full CMS-module ownership. The bridge tracks provenance, enforces single-authority rules, and includes an explicit decommission path.

Strategically, the bridge is also a **controlled content ingestion pipeline with lifecycle governance** — a pattern that generalizes beyond WordPress. The event schema, provenance tracking, conflict detection, and idempotency guarantees established here are reusable for any external content source (Joomla, POS systems, mobile apps, third-party APIs). WordPress is the first adapter; the ingestion pipeline is the lasting infrastructure.

---

## Why This Exists

ApplicationOS already ships a full CMS module with content management, a page builder, theming, media handling, and ecommerce entity integration. There is no architectural need for a second content system.

However, some tenants migrating from WordPress have years of muscle memory, existing editorial workflows, and familiarity with the WordPress admin dashboard. Forcing an immediate cold-switch to a new CMS admin surface creates friction.

The migration bridge addresses this by:

1. Giving legacy users a **temporary, familiar editing surface** (WordPress admin) scoped strictly to content creation/editing.
2. Synchronizing authored content into the ApplicationOS CMS tables through **structured capability-based writes**, not raw SQL.
3. Tracking **provenance metadata** so every migrated piece of content carries its origin story.
4. Providing a **staged decommission path** that retires the WordPress admin once the tenant is comfortable editing natively.

---

## Problem Statement

Migrating users from WordPress to ApplicationOS CMS faces two opposing pressures:

- **Architectural clarity** demands a single content authority. Dual-source content ownership creates conflict, drift, and maintenance cost.
- **User adoption** benefits from gradual transitions. Users who know WordPress well are more productive if they can keep using its admin UI during the transition period.

The migration bridge resolves this tension by making WordPress a **write surface only** — a controlled input channel — while ApplicationOS CMS remains the sole authority for content storage, rendering, and public delivery.

---

## Architectural Position

### Source of Truth

ApplicationOS CMS (`cms_content`, `cms_content_meta`, `cms_media`) is the **only** source of truth for content. WordPress tables are a staging area. Content does not "live" in WordPress — it passes through WordPress on its way to the CMS module.

This follows the same authority model defined in the [Integration Bridge](integration-bridge.md): one owner per entity, no bidirectional sync, no dual ownership.

### Public Rendering

WordPress **never** renders public pages. `WP_USE_THEMES` is set to `false`. All public content delivery goes through ApplicationOS CMS templates, themes, and the page builder. There is no WordPress theme active, no WordPress frontend routing, and no WordPress plugin output on the public site.

### Scope

The bridge covers:
- Posts, pages, and custom post types (content migration)
- Categories and tags (taxonomy mapping)
- Featured images and inline media (media migration)
- Author attribution (user mapping)

The bridge does **not** cover WordPress plugins, widgets, shortcodes, WooCommerce, or theme-specific functionality.

### Plugin and Theme Transition Strategy

WordPress users often choose the platform not for its CMS core, but because **plugins exist for specific tasks** and **themes enforce design**. The migration bridge deliberately does not replicate this ecosystem. Instead, it addresses the concern head-on:

**Why plugins are not bridged:**

WordPress plugins solve horizontal problems (SEO, forms, ecommerce, analytics, booking, etc.) through a shared-nothing architecture — each plugin brings its own schema, rendering, and admin UI. ApplicationOS solves the same problems through **capability-driven modules** with shared kernel infrastructure, event routing, and single-authority data ownership. The overlap is in the *problem space*, not the *solution architecture*.

The transition path for plugin-dependent users:

| WordPress plugin category | ApplicationOS equivalent | Migration path |
|---|---|---|
| WooCommerce / commerce | Ecommerce module | Product data import via bridge; orders start native |
| Contact Form 7 / forms | Contact Form module | Rebuild in native module (no data migration needed) |
| Yoast / SEO | CMS SEO fields + meta | SEO metadata syncs as `cms_content_meta` during content migration |
| Page builders (Elementor, etc.) | Page Builder module | Content body imports as HTML; rebuild layouts natively |
| Analytics (GA, etc.) | Theme-level script injection | Reconfigure in CMS theme settings |
| Booking / scheduling | Capability modules (future) | Defer until native module exists |

**Why themes are not bridged:**

WordPress themes control public rendering. Since the bridge sets `WP_USE_THEMES=false` and all public rendering goes through ApplicationOS CMS themes and the page builder, WordPress themes have no role. The transition:

1. During bridge period: public site uses ApplicationOS CMS theme. WordPress admin uses default WP admin chrome (no frontend theme needed).
2. Users who want visual parity: use the CMS page builder to recreate their preferred layout. Content structure (headings, images, body) transfers through the bridge; visual design is rebuilt natively.
3. Users who want something better: the CMS theme system and page builder offer capabilities WordPress themes cannot (entity-aware rendering, capability-driven blocks, builder-level animation/style control).

The honest pitch: **the bridge migrates your content; ApplicationOS replaces your plugin stack**. Users who adopt ApplicationOS gain a unified system instead of a plugin patchwork. The bridge buys them time to transition, not permission to stay.

---

## Recommended Phase 1: WordPress

WordPress is the phase 1 target because:

1. The repo already contains a full WordPress importer module (`modules/wordpress-importer`) with WXR parsing, status normalization, slug deduplication, category/tag mapping, and author resolution.
2. The ikabud project (`/var/www/html/ikabud`) proves that shared-core WordPress boot is technically feasible with `ABSPATH` override, per-instance `WP_CONTENT_DIR`, and selective plugin loading.
3. WordPress has the largest market share among legacy CMS platforms that ApplicationOS tenants are likely migrating from.

Joomla follows the same migration-bridge pattern conceptually, but WordPress should ship first because the normalization seams already exist in the codebase.

---

## Operating Model

### 1. Shared Core Storage

WordPress core files are stored once per version in a shared directory:

```
storage/bridge-cores/
  wordpress-6.8.3/       ← shared, read-only WordPress core
```

Per-tenant instance directories hold only tenant-specific configuration and uploads:

```
storage/bridge-instances/
  tenant-{id}/
    wp-config.php          ← tenant DB credentials, ABSPATH override
    wp-content/
      uploads/             ← tenant media (temporary, migrated to CMS media)
      plugins/             ← minimal: only bridge-sync plugin
```

This mirrors the pattern proven in `ikabud/backend/src/Core/WordPressEnvironment.php`: `ABSPATH` points to the shared core, `WP_CONTENT_DIR` points to the instance directory.

### 2. Admin-Only Boot

WordPress boots only for authenticated admin requests on a dedicated route (e.g., `/bridge/wp-admin`). The boot path:

1. Sets `WP_USE_THEMES = false` (no frontend rendering).
2. Sets `ABSPATH` to the shared core directory.
3. Sets `WP_CONTENT_DIR` to the tenant instance directory.
4. Loads `wp-settings.php` under kernel supervision.
5. Uses selective plugin loading — only kernel-approved bridge plugins are active.

WordPress **never** boots during public page requests. The kernel route that mounts the WordPress admin is gated behind CMS authentication and a module-level feature flag.

**Plugin boundary enforcement (hard rule):**

> No arbitrary plugins. Only kernel-approved bridge plugins.

On every boot, the bridge validates the tenant's `wp-content/plugins/` directory against an allowlist. If an unauthorized plugin is detected, boot fails and logs the violation. This is non-negotiable — allowing even one unvetted plugin makes bridge behavior unpredictable and expands the security surface.

Allowlist is defined in the bridge module settings, not in WordPress configuration.

**Sandboxing requirements:**

WordPress boot is treated as a sandboxed process, not part of the kernel application:

- **Isolation**: WordPress config is strictly tenant-scoped. No kernel globals leak into WP context; no WP globals persist after the request.
- **Logging**: Every WordPress admin boot is logged with tenant ID, request ID, memory usage, and execution time.
- **Resource limits**: Memory and time per WP request are measured and capped. On shared hosting, WP admin requests that exceed thresholds are terminated and logged.
- **Security surface**: WP admin route inherits kernel CSRF and auth protections. WordPress's own auth is secondary — kernel auth gates the route before WP boots.

### 3. Event-Driven Ingestion

Content moves from WordPress to ApplicationOS CMS through **events and structured capability calls**, not raw database copies or direct sync. Internally, every content transfer is modeled as an event — even during Phase 1 import-only mode.

The ingestion pipeline:

1. Reads from WordPress tables (`wp_posts`, `wp_terms`, `wp_postmeta`).
2. Normalizes the data using the same transformation logic already in `modules/wordpress-importer/handlers/10-wordpress-importer.php`:
   - `wordpressImporterNormalizeStatus()` → maps WP status to CMS status enum
   - `wordpressImporterEnsureUniqueSlug()` → deduplicates slugs against existing CMS content
   - `wordpressImporterResolveAuthorId()` → maps WP user IDs to CMS user IDs
3. **Emits a kernel event** with the normalized payload (see Event Schema below).
4. Writes into CMS tables via the `cms.content.create@1` capability (defined in `modules/cms/helpers/55-capabilities.php`), preserving the CMS module's write boundary.
5. Syncs categories and tags via `cmsSyncContentCategories()` and `cmsSyncContentTags()`.
6. Sanitizes HTML body content via `cmsEditorSanitizeHtml()`.
7. **Emits a result event** (`cms.migration.content.completed`) with provenance and outcome.

The existing importer functions become **event normalizers**, not just data mappers. This aligns the CMS bridge with the WMS and ecommerce event model (`wms.order.picked`, `ecommerce.order.created`, etc.) and makes the bridge inspectable, replayable, and extensible through the standard `kernel_integrations` / `kernel_integration_logs` infrastructure.

**Event schema:**

```json
{
  "event": "cms.migration.content.upserted",
  "source": "wordpress",
  "external_id": 42,
  "external_modified": "2026-04-10T09:15:00Z",
  "payload": {
    "title": "My Post",
    "slug": "my-post",
    "body": "<p>...</p>",
    "excerpt": "...",
    "type": "post",
    "status": "published",
    "categories": ["news"],
    "tags": ["update"],
    "author_external_id": 1,
    "featured_image_external_url": "https://..."
  }
}
```

Events are declared in the bridge module's `module.json` `events[]` array with `available_vars` so the kernel event registry knows the payload contract. The Integration Bridge can then route these events to any downstream capability without custom wiring.

Ingestion modes:
- **On-demand**: Tenant triggers ingestion manually from a bridge admin UI.
- **Deferred**: Cron-based periodic ingestion (e.g., every 15 minutes while bridge is active).
- **On publish**: WordPress `publish_post` hook emits an event for immediate ingestion of the published item.

### 4. Provenance Tracking

Every content item that enters the CMS through the bridge carries provenance metadata in `cms_content_meta`:

| meta_key | Example value | Purpose |
|---|---|---|
| `bridge_source` | `wordpress` | Identifies the origin platform |
| `bridge_source_id` | `42` | Original WP post ID |
| `bridge_synced_at` | `2026-04-11T14:30:00Z` | Last sync timestamp |
| `bridge_source_modified` | `2026-04-10T09:15:00Z` | WP `post_modified` at sync time |
| `bridge_status` | `external-managed` | Current lifecycle state |

### 5. Conflict Policy

Each content item in the bridge has one of four lifecycle states:

| State | Meaning |
|---|---|
| `external-managed` | WordPress is the active editing surface. CMS accepts sync writes. |
| `review-required` | Both sides were modified since last sync. Manual review needed. |
| `cms-managed` | Content has been claimed by the CMS editor. Bridge sync is disabled for this item. |
| `retired` | Bridge has been decommissioned for this item. Provenance metadata is retained, sync is permanently off. |

Conflict detection: if `cms_content.updated_at` is newer than `bridge_synced_at` and a new ingestion event arrives with changes, the item transitions to `review-required` instead of overwriting.

**Field-level conflict awareness:**

The initial conflict model operates at the whole-item level. However, real scenarios create partial conflicts:

- WordPress edits the content body; CMS edits SEO metadata.
- WordPress adds a tag; CMS changes the featured image.

Blocking the entire item on any field change is too aggressive. Overwriting everything is too destructive.

The schema is designed to support **field-group-level conflict detection** in a future iteration:

| Field group | Fields | Conflict tracked independently |
|---|---|---|
| `content` | title, body, excerpt | Yes |
| `meta` | SEO fields, custom meta | Yes |
| `taxonomy` | categories, tags | Yes |
| `media` | featured image, inline images | Yes |

Phase 1 treats the item as a unit. When field-group tracking is added, the `bridge_source_modified` metadata expands to per-group timestamps (e.g., `bridge_source_modified:content`, `bridge_source_modified:meta`), and the conflict policy evaluates each group independently. This avoids overengineering now while keeping the door open.

State transitions:
- `external-managed` → `review-required` (conflict detected)
- `external-managed` → `cms-managed` (user claims item in CMS)
- `review-required` → `cms-managed` (user resolves in favor of CMS version)
- `review-required` → `external-managed` (user resolves in favor of WP version)
- Any state → `retired` (decommission)

### 6. Decommission Path

The bridge is designed to be removed. Decommission is per-tenant and follows these stages:

1. **Read-only mode**: WordPress admin is still accessible but saves are blocked. Sync stops. Users are directed to the CMS admin.
2. **Archive**: WordPress instance directory is archived and bridge routes are disabled. Content metadata retains `bridge_source` for audit.
3. **Cleanup**: Archived WordPress instance directory is deleted. Shared core remains (other tenants may still use it).

The kernel module settings track bridge state per tenant: `active`, `read-only`, `archived`, `disabled`.

### 7. Idempotency

WordPress will resend, retry, and duplicate. The bridge must be idempotent.

Every ingestion event is deduplicated by a compound key:

```
(bridge_source, bridge_source_id, bridge_source_modified)
```

If an event arrives with the same source, external ID, and source-modified timestamp as an already-processed record, the bridge **skips** it. No write, no conflict check, no event emission — just a logged skip.

This prevents:
- Duplicate content from retried syncs
- Phantom updates from WordPress auto-save
- Version drift from out-of-order event delivery

The idempotency check happens **before** any CMS capability call, making it the first guard in the ingestion pipeline.

Provenance metadata in `cms_content_meta` provides the lookup:
- `bridge_source` + `bridge_source_id` → identifies the external item
- `bridge_source_modified` → identifies the exact version

If `bridge_source_modified` is newer than the stored value → process. If equal → skip. If older → skip and log (out-of-order delivery).

### 8. Media Pipeline

Media is the most underestimated part of content migration. WordPress content contains:
- Featured images (single attachment per post)
- Inline images embedded in HTML body (`<img src="...">`)
- Gallery attachments
- File downloads

**Problems the pipeline must handle:**

1. **Broken URLs after migration**: WordPress media URLs (`/wp-content/uploads/...`) must be rewritten to CMS media paths after ingestion.
2. **Duplicate uploads**: The same image may be referenced by multiple posts. Uploading it once per reference wastes storage.
3. **Large files on shared hosting**: Blocking the content sync on large media downloads stalls the entire pipeline.

**Pipeline design:**

1. **Hash-based deduplication**: Before ingesting a media file, compute its SHA-256 hash. If a CMS media record with the same hash exists, reuse it instead of uploading again.
2. **URL rewriting during sync**: After media is ingested into `cms_media`, scan the content body HTML for WordPress media URLs and rewrite them to the corresponding CMS media paths. This runs as a post-processing step after the content capability write.
3. **Background media ingestion**: Media download and processing runs asynchronously — it does not block the content sync. Content is written first with placeholder references; a follow-up pass resolves media URLs once downloads complete.
4. **Provenance for media**: Each ingested media item carries `bridge_source` and `bridge_source_id` metadata in `cms_media` or a related meta table, enabling dedup lookups and audit.

Phase 1 (import-only) handles media from WXR exports or direct file copies. Phase 2 (admin boot) handles live media from the WordPress uploads directory.

---

## Data Flow

```
┌─────────────────┐    emit event       ┌─────────────────┐    capability call   ┌──────────────────────┐
│  WordPress Admin │ ──────────────────► │  Ingestion       │ ──────────────────► │  ApplicationOS CMS   │
│  (editing only)  │  cms.migration.*    │  Pipeline        │  cms.content.*@1    │  (authority + render)│
│                  │                     │                  │                     │                      │
│  wp_posts        │                     │  normalize       │                     │  cms_content          │
│  wp_terms        │                     │  deduplicate     │                     │  cms_content_meta     │
│  wp_postmeta     │                     │  validate        │                     │  cms_media            │
└─────────────────┘                     │  rewrite media   │                     └──────────────────────┘
        │                                └─────────────────┘                              │
        │ admin UI only                         │                                         │ public rendering
        │ WP_USE_THEMES=false                   │ log to kernel_integration_logs           │ themes + builder
        │ no public routes                      │ emit result events                      │
        ▼                                       ▼                                         ▼
   /bridge/wp-admin                     kernel event bus                            / (public site)
   (authenticated, gated)              (inspectable, replayable)                   (CMS templates)
```

---

## Existing Repo Seams to Reuse

| Seam | Location | What it provides |
|---|---|---|
| WXR importer | `modules/wordpress-importer/handlers/10-wordpress-importer.php` | Status normalization, slug dedup, author resolution, category/tag mapping, HTML sanitization |
| Content create capability | `modules/cms/helpers/55-capabilities.php` → `cms.content.create@1` | CMS-boundary-safe content writes with full validation |
| Content list/get capabilities | Same file → `cms.content.list@1`, `cms.content.get@1` | Read access for conflict detection |
| CMS content schema | `modules/cms/database/migrations/001_cms_foundation.sql` | `cms_content`, `cms_content_meta`, `cms_media` tables |
| Integration bridge discipline | `docs/integration-bridge.md` | Authority model, anti-sync rules, fail-fast behavior |
| WordPress boot proof | `ikabud/backend/src/Core/WordPressEnvironment.php` | Shared-core boot, ABSPATH override, selective loading |
| Instance creation proof | `ikabud/install-wordpress-instance.php` | Per-tenant WP config generation from shared core |
| Kernel event bus | `kernel/EventBus.php` | `fire()` / `listen()` pattern, event naming conventions |
| Integration Bridge | `kernel/IntegrationBridge.php` | Event→capability routing, `kernel_integrations` registry, correlation logging |
| Event payload builders | `modules/ecommerce/helpers/30-products.php`, `modules/wms/helpers/30-operations.php` | Patterns for structured bridge event payloads |

---

## Non-Goals

These are explicitly out of scope for the migration bridge:

1. **Permanent bidirectional content sync.** Content flows one way: WordPress → CMS. The CMS never writes back to WordPress tables.
2. **WooCommerce as commerce engine.** ApplicationOS has its own ecommerce module. WooCommerce products, orders, and checkout are not bridged.
3. **Public rendering through WordPress.** WordPress themes, template hierarchy, and frontend routing are never active.
4. **WordPress plugin ecosystem parity.** The bridge does not attempt to replicate or proxy WordPress plugin functionality. Only the bridge-sync plugin is loaded.
5. **Full runtime authority over WordPress internals.** PHP cannot override WordPress core functions without native extensions. The bridge monitors and constrains (admin-only boot, selective loading) but does not claim full process supervision.
6. **Joomla bridge in phase 1.** The same pattern applies conceptually, but implementation is deferred until WordPress bridge is stable.

---

## Risks

| Risk | Mitigation |
|---|---|
| WordPress security vulnerabilities in shared core | Pin core versions. Apply updates as shared-core-level operations, not per-tenant. Gate admin route behind kernel auth. |
| Tenants treat WordPress as permanent rather than transitional | Bridge state tracks lifecycle. Admin UI shows decommission timeline. Feature flag allows disabling bridge per tenant. |
| Sync conflicts cause data loss | Conflict detection transitions to `review-required` instead of overwriting. No silent merges. |
| Shared hosting resource constraints | WordPress boots only on admin requests, not on every page load. Selective plugin loading minimizes memory. |
| Bridge maintenance cost exceeds value | Bridge is a module that can be disabled globally. Decommission path ensures tenants can exit cleanly. |

---

## Configuration

All bridge settings are stored per-tenant in the module settings registry (`getModuleSettings('wordpress-bridge')` / `saveModuleSettings('wordpress-bridge', ...)`). They are configurable via **CMS Admin → WordPress Bridge → Settings**.

### Settings Reference

| Key | Type | Default | Description |
|---|---|---|---|
| `bridge_enabled` | boolean | `false` | Master on/off switch. Controls CMS sidebar visibility and ingestion API access. |
| `bridge_state` | select | `active` | Operational mode: `active` (ingestion open), `read-only` (blocks new ingestion), `archived` / `disabled` (fully locked). Managed from the Bridge Dashboard. |
| `source_site_url` | text | `""` | Base URL of the source WordPress site (e.g. `https://myblog.com`). Used for SSRF allowlisting during media fetch and for URL rewriting in migrated content bodies. |
| `source_name` | text | `""` | Human-readable label for this WordPress source (e.g. "Marketing Blog"). Appears in the ingestion log and bridge dashboard. |
| `bridge_api_token` | text | `""` | Auto-generated 64-character hex bearer token. Used by the WP companion plugin to authenticate `POST /api/v1/bridge/ingest` requests. Never entered manually — generated on first settings access and rotatable via the API. |

### bridge_enabled vs bridge_state

These are two separate control planes:

- **`bridge_enabled`** — master on/off. When `false`, the WordPress Bridge section disappears from the CMS sidebar and the ingest endpoint returns `503`. Use this to suppress the feature entirely for a tenant that has not set it up yet.
- **`bridge_state`** — operational lifecycle mode. Governs what the bridge allows when it is enabled. Transitions:  
  `active` → `read-only` → `archived` → `disabled`

---

## API Token & Live Sync

### How the Token Works

The `bridge_api_token` is a 64-character hex string (`bin2hex(random_bytes(32))`). It is compared using `hash_equals()` (constant-time comparison) to prevent timing attacks.

Requests authenticated via bearer token are treated as a system actor and bypass CSRF enforcement. Only the ingest endpoint (`POST /api/v1/bridge/ingest`) supports bearer token authentication. All other bridge admin routes require a valid CMS session.

### Token Lifecycle

1. **Auto-generated**: `wpBridgeEnsureApiToken()` generates the token on first settings page access if one does not exist.
2. **Displayed (masked)**: Only the last 6 characters are shown in the UI (e.g. `••••••••…abc123`). The full token is never rendered.
3. **Rotated**: `POST /api/v1/bridge/token/rotate` generates a new token and immediately invalidates the old one. Update the companion plugin after rotation.
4. **Downloaded with companion plugin**: `GET /api/v1/bridge/companion/download` embeds the current token into the companion plugin PHP file. Re-download after rotation.

### Bearer Token Auth Flow (Ingestion Endpoint)

```
POST /api/v1/bridge/ingest
Authorization: Bearer <token>
Content-Type: application/json

{ "event": "cms.migration.content.upserted", "source": "...", ... }
```

When `Authorization: Bearer` is present, the endpoint:
1. Checks `bridge_enabled` (503 if false)
2. Reads `bridge_api_token` from settings
3. Compares via `hash_equals()` — 401 if no match
4. Proceeds with ingestion as a system actor (no CSRF required)

When no bearer token is present (admin-triggered import), the endpoint falls back to `cmsRequireCap('import_export.manage')` + CSRF enforcement.

---

## WP Companion Plugin

The companion plugin provides **live sync** from WordPress to ApplicationOS. It hooks into WordPress's `save_post` and `delete_post` actions and POSTs a normalized envelope to the bridge ingest endpoint on every publish or update.

### Installation

1. Go to **CMS Admin → WordPress Bridge → Settings**
2. Fill in **WordPress Site URL** and **Source Name**, then **Save Settings**
3. Click **Download Companion Plugin** — the downloaded file has your endpoint URL and token pre-embedded
4. Upload `applicationos-bridge-connector.php` to `wp-content/plugins/` on your WordPress site
5. Activate the plugin via **WordPress Admin → Plugins → Installed Plugins**
6. Publish or update a post in WordPress — confirm it appears in the Bridge Dashboard **Recent Activity** log

### Re-download After Token Rotation

After rotating the token via Settings → Rotate Token:
1. Click **Download Companion Plugin** again
2. Replace the old plugin file on your WordPress site with the new download
3. Deactivate and reactivate the plugin if necessary

### Non-Blocking Mode

By default the companion plugin waits for a response from ApplicationOS (blocking HTTP). To fire-and-forget (non-blocking), define `APPLOS_BRIDGE_NONBLOCKING` as `true` in the plugin file after downloading.

### Companion Plugin Security

- The downloaded file contains the full API token in plaintext. Treat it as a credential.
- Do not commit the companion plugin to public version control.
- If the token is exposed, rotate it immediately and re-download.

---

## API Reference (Bridge Module)

All routes require `import_export.manage` capability unless noted.

| Method | Route | Handler | Auth |
|---|---|---|---|
| `GET` | `/cms/admin/bridge` | `wpBridgeAdminDashboard` | CMS session |
| `GET` | `/cms/admin/bridge/settings` | `wpBridgeAdminSettings` | CMS session |
| `POST` | `/cms/admin/bridge/settings` | `wpBridgeAdminSettings` | CMS session + CSRF |
| `GET` | `/api/v1/bridge/status` | `wpBridgeApiStatus` | CMS session |
| `GET` | `/api/v1/bridge/health` | `wpBridgeApiHealth` | CMS session |
| `GET` | `/api/v1/bridge/content` | `wpBridgeApiContentList` | CMS session |
| `POST` | `/api/v1/bridge/ingest` | `wpBridgeApiIngest` | CMS session + CSRF **or** Bearer token |
| `POST` | `/api/v1/bridge/import/wxr` | `wpBridgeApiImportWxr` | CMS session + CSRF |
| `POST` | `/api/v1/bridge/token/rotate` | `wpBridgeApiTokenRotate` | CMS session + CSRF |
| `GET` | `/api/v1/bridge/companion/download` | `wpBridgeApiCompanionDownload` | CMS session |
| `PATCH` | `/api/v1/bridge/state` | `wpBridgeApiSetState` | CMS session + CSRF |
| `PATCH` | `/api/v1/bridge/content/{id}/claim` | `wpBridgeApiContentClaim` | CMS session + CSRF |
| `PATCH` | `/api/v1/bridge/content/{id}/resolve` | `wpBridgeApiContentResolve` | CMS session + CSRF |

### `GET /api/v1/bridge/health`

Returns a summary of bridge configuration status.

```json
{
  "ok": true,
  "bridge_enabled": true,
  "bridge_state": "active",
  "source_site_url": "https://myblog.com",
  "source_name": "Marketing Blog",
  "token_configured": true,
  "last_ingested_at": "2026-04-11 12:34:56",
  "last_outcome": "processed"
}
```

### `POST /api/v1/bridge/token/rotate`

Generates a new API token and invalidates the old one.

**Request:** Any (CSRF token required in session-based calls)  
**Response:**
```json
{
  "ok": true,
  "token_masked": "••••••••••••••••••••••••••••••••••••••••••••••••••••••••••abc123",
  "message": "Token rotated. Update your WP companion plugin configuration."
}
```

---

## Suggested Implementation Phases

### Phase 1: Import-Only Bridge (with event + idempotency foundation)
- Extend existing `wordpress-importer` module with provenance tracking (`bridge_source`, `bridge_synced_at`, etc.)
- Add `bridge_status` lifecycle to `cms_content_meta`
- **Add idempotency guard**: deduplicate by `(bridge_source, bridge_source_id, bridge_source_modified)` before any write
- **Wrap ingestion as events**: emit `cms.migration.content.upserted` and `cms.migration.content.completed` events even in import-only mode, log to `kernel_integration_logs` for traceability and future replay
- Build conflict detection logic (compare `updated_at` vs `bridge_synced_at`)
- **Add media pipeline foundation**: hash-based deduplication, URL rewriting in content body, provenance metadata for ingested media
- Add bridge admin UI for triggering manual import and reviewing conflicts
- No WordPress runtime boot — import from WXR exports or direct DB reads
- **Stress test with real content**: messy WordPress exports with inconsistent authors, lots of inline media, broken shortcodes, mixed encodings

### Phase 2: Admin Boot Bridge
- Add shared-core storage management (download, version-pin, integrity-check)
- Implement per-tenant instance provisioning (generate `wp-config.php`, create `wp-content` directory)
- **Plugin boundary enforcement**: allowlist validation on boot, fail on unauthorized plugins
- **Sandboxing**: memory/time measurement, isolated config, per-request logging
- Mount WordPress admin on `/bridge/wp-admin` behind kernel auth
- Create bridge-sync WordPress plugin (hooks `save_post`, `publish_post` to emit ingestion events)
- Add on-publish and deferred ingestion modes
- Live media pipeline: background download from WP uploads → CMS media with hash dedup

### Phase 3: Lifecycle Management
- Implement read-only mode (disable WP saves, show migration banner)
- Build decommission workflow (archive instance, disable routes, retain provenance)
- Add tenant-level bridge state management in module settings
- Dashboard reporting: items synced, items in review, items claimed by CMS

### Phase 4: Joomla Bridge (Future)
- Apply same pattern with Joomla shared core and admin boot
- Implement Joomla-specific content normalization (articles, categories, modules)
- Reuse provenance tracking and lifecycle management from WordPress bridge
- Reuse the same ingestion pipeline; Joomla is a new **adapter**, not a new pipeline

### Phase 5: Generalized Ingestion Pipeline (Future)
- Extract WordPress/Joomla-agnostic ingestion core into a shared kernel helper
- Define adapter interface: `normalize()` → event → capability write → provenance
- Enable ingestion from POS systems, mobile apps, third-party content APIs
- The bridge module becomes a thin adapter layer; the pipeline is kernel infrastructure

---

## Recommendation

Start with **Phase 1 (import-only)** because it requires no WordPress runtime and builds directly on the existing `wordpress-importer` module. The provenance and conflict-detection infrastructure established in Phase 1 is prerequisite for all later phases.

Phase 2 (admin boot) should only proceed if real tenant demand justifies the runtime cost of booting WordPress within ApplicationOS. The import-only bridge may be sufficient for most migration scenarios.

The strategic outcome is not just "WordPress migration" — it is a **controlled content ingestion pipeline with lifecycle governance** that unifies how ApplicationOS accepts content from any external source. WordPress is the first adapter. The pipeline is the product.
