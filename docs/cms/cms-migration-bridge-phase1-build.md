# CMS Migration Bridge — Phase 1 Build Reference

## Build Scope

Phase 1 is **import-only with event + idempotency foundation**. No WordPress runtime boot, no admin UI beyond the existing importer page, no lifecycle management UI. The goal is a **boringly reliable ingestion pipeline**.

---

## Build Order (Strict)

### 1. Module Scaffold (`modules/content-ingestion/`)

```
modules/content-ingestion/
  module.json          ← manifest: id, depends, events, owns_tables
  routes.php           ← POST /api/v1/bridge/ingest
  handlers.php         ← loader
  handlers/
    10-ingestion.php   ← event ingestion endpoint + idempotency + event→capability handler
  helpers.php          ← bridge helpers (idempotency check, provenance write/read)
  database/
    migrations/
      001_bridge_provenance.sql  ← bridge_ingestion_log table
```

### 2. Event Ingestion Endpoint

```
POST /api/v1/bridge/ingest

Body:
{
  "event": "cms.migration.content.upserted",
  "source": "wordpress",
  "external_id": "42",
  "external_modified": "2026-04-10T09:15:00Z",
  "payload": { ... }
}
```

Responsibilities ONLY:
- Validate schema (required fields, types)
- Enforce idempotency (first guard)
- Log event to `bridge_ingestion_log`
- Dispatch internally to handler

No business logic in the endpoint itself.

### 3. Idempotency Guard (First-Class)

Compound key: `(source, external_id, external_modified)`

```
if exists with same key → SKIP (200, logged)
if older than stored    → SKIP + log (out-of-order)
if newer than stored    → PROCESS
if no record exists     → PROCESS (first import)
```

Lookup via `bridge_ingestion_log` table, not `cms_content_meta` queries. Clean, dedicated table.

### 4. Event → Capability Mapping (Handler)

```
cms.migration.content.upserted
  → normalize payload (reuse wordpress-importer functions)
  → call cms.content.create@1 (or find-and-update)
  → write provenance to cms_content_meta
  → emit result event: cms.migration.content.completed
  → log outcome to bridge_ingestion_log
```

### 5. WordPress Importer → Event Emitter

Modify `wordpressImporterImportStructuredPayload()`:

Instead of direct DB writes, emit events per content item:
```php
kernelEmitEvent('cms.migration.content.upserted', $normalizedPayload, 'content-ingestion');
```

The bridge module's event handler catches these and runs them through the idempotency → capability pipeline.

**Important**: The importer still handles WXR parsing and bulk orchestration. Only the per-item write path changes to emit events.

---

## Database Schema

### `bridge_ingestion_log`

```sql
CREATE TABLE IF NOT EXISTS bridge_ingestion_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source          VARCHAR(50)  NOT NULL,          -- 'wordpress', 'joomla', etc.
    external_id     VARCHAR(100) NOT NULL,          -- WP post ID (as string for flexibility)
    external_modified VARCHAR(30) NOT NULL,         -- source timestamp at ingest time
    event_name      VARCHAR(100) NOT NULL,          -- 'cms.migration.content.upserted'
    status          ENUM('processed','skipped','failed') NOT NULL DEFAULT 'processed',
    cms_content_id  INT UNSIGNED NULL,              -- resulting cms_content.id (if processed)
    payload_json    LONGTEXT     NULL,              -- full normalized payload (for replay)
    error_message   TEXT         NULL,              -- if failed
    request_id      VARCHAR(50)  NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bridge_ingest (source, external_id, external_modified),
    KEY idx_bridge_status (status),
    KEY idx_bridge_source (source),
    KEY idx_bridge_cms_content (cms_content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Provenance in `cms_content_meta`

After successful ingestion, write:
- `bridge_source` = 'wordpress'
- `bridge_source_id` = '42'
- `bridge_synced_at` = NOW()
- `bridge_source_modified` = external_modified value
- `bridge_status` = 'external-managed'

---

## Module Manifest (module.json)

```json
{
    "id": "content-ingestion",
    "name": "Content Ingestion",
    "version": "1.0.0",
    "description": "CMS migration bridge — event-driven content ingestion from WordPress into ApplicationOS CMS.",
    "author": "Ikabud Kernel Team",
    "depends": ["cms", "wordpress-importer"],
    "owns_tables": ["bridge_ingestion_log"],
    "reads_tables": ["cms_content", "cms_content_meta", "cms_users"],
    "events": [
        {
            "key": "cms.migration.content.upserted",
            "description": "Emitted when normalized WordPress content is ready for CMS ingestion.",
            "available_vars": [
                "source", "external_id", "external_modified",
                "title", "slug", "body", "excerpt", "type", "status",
                "categories", "tags", "author_external_id"
            ]
        },
        {
            "key": "cms.migration.content.completed",
            "description": "Emitted after CMS ingestion succeeds or fails for a single content item.",
            "available_vars": [
                "source", "external_id", "cms_content_id", "status", "outcome"
            ]
        }
    ],
    "capabilities": {
        "exposes": []
    },
    "settings": {
        "bridge_enabled": {
            "type": "boolean",
            "default": false,
            "label": "Enable Content Ingestion"
        }
    },
    "nav": []
}
```

---

## What NOT to Build

- ❌ Admin boot (`/bridge/wp-admin`)
- ❌ Plugin allowlist enforcement
- ❌ Full lifecycle UI (read-only mode, decommission)
- ❌ Field-level conflict resolution
- ❌ Joomla support
- ❌ Generalized adapter framework
- ❌ Media pipeline (Phase 1 uses existing importer media handling)
- ❌ Deferred/cron sync modes

---

## First Test (Validation Criteria)

Take a messy WordPress WXR export and run it through the new event pipeline. Verify:

1. No duplicates (re-import same file → all items skipped, idempotency working)
2. Slugs remain unique (multiple items with similar titles)
3. Authors mapped correctly (missing/invalid author IDs fall back)
4. Content appears correctly in `cms_content` with proper status normalization
5. Provenance metadata exists in `cms_content_meta` for every imported item
6. `bridge_ingestion_log` reflects every item: processed, skipped, or failed
7. Events were emitted and logged (check `kernel_integration_logs` if bridge is wired)
8. Re-import with modified timestamps → updates processed, old timestamps skipped
