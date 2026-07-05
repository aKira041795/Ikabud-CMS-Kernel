# Content Ingestion Module

## Purpose

`modules/content-ingestion` is the ApplicationOS bridge for importing external CMS content into the native CMS domain.
The current production adapter is WordPress, with a normalized event pipeline that can be extended to additional sources.

## Scope and Ownership

- Module ID: `content-ingestion`
- Owns tables: `bridge_ingestion_log`, `bridge_media_log`, `bridge_content_map`
- Reads CMS tables: `cms_content`, `cms_content_meta`, `cms_users`, `cms_categories`, `cms_tags`, `cms_media`
- Declared capability exposure: `content_ingestion.import_content@1`

## Pipeline Summary

1. Source content is submitted to `POST /api/v1/bridge/ingest`.
2. `wpBridgeApiIngest` validates bridge state + token, then normalizes payload.
3. `wpBridgeHandleContentUpserted` performs idempotency/provenance checks, then writes/updates CMS content records.
4. Media references are processed by `wpBridgeFetchAllMedia` and logged to `bridge_media_log`.
5. Lifecycle handlers (`claim`, `resolve`, `state`) govern whether imported content remains externally managed or tenant-managed.

## Runtime Surface

Admin routes:

- `GET /cms/admin/bridge`
- `GET|POST /cms/admin/bridge/settings`

Bridge APIs:

- `GET /api/v1/bridge/status`
- `GET /api/v1/bridge/content`
- `GET /api/v1/bridge/health`
- `GET /api/v1/bridge/companion/download`
- `POST /api/v1/bridge/ingest`
- `POST /api/v1/bridge/source/sync`
- `POST /api/v1/bridge/import/wxr`
- `POST /api/v1/bridge/token/rotate`
- `PATCH /api/v1/bridge/state`
- `PATCH /api/v1/bridge/content/{id}/claim`
- `PATCH /api/v1/bridge/content/{id}/resolve`

## Bridge State Contract

`bridge_state` setting controls behavior:

- `active`: ingest + admin operations enabled
- `read-only`: content can be read and reviewed; no write-side ingestion
- `archived`: bridge effectively retired, kept for history/audit
- `disabled`: ingestion endpoints and bridge operations blocked

## CMS Integration Points

- Imported records are written to CMS entities directly, then tracked through provenance metadata and bridge logs.
- This module is an ingestion bridge, not a rendering layer.
- Final frontend output should use CMS/entity-view rendering (`{ikb_entity_list}` / `{ikb_entity_detail}`) from CMS/theme templates.

## Verification and Test Coverage

Current integration tests:

- `tests/wordpress_bridge_ingestion_test.php`
- `tests/wordpress_bridge_media_test.php`
- `tests/wordpress_bridge_lifecycle_test.php`
- `tests/wordpress_bridge_settings_test.php`

These tests are the baseline contract for ingestion, media fetch, bridge lifecycle controls, and admin/settings behavior.