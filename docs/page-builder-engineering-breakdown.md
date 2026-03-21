---
description: Page Builder Engineering Breakdown — Implementation-Ready File Map, Migrations, Routes, and Responsibilities
---

# Page Builder Engineering Breakdown

This document converts the page builder architecture into an implementation-ready engineering plan grounded in the **current CMS codebase**.

---

# 1. Current Code Reality

The current CMS already contains a transitional page-builder flow:

- admin routes:
  - `/cms/admin/page-builder/create`
  - `/cms/admin/page-builder/{id}`
- save endpoints:
  - `POST /api/v1/cms/page-builder`
  - `POST /api/v1/cms/page-builder/{id}`
- admin template:
  - `templates/modules/cms/admin/page-builder.disyl`
- helpers in `modules/cms/helpers.php`:
  - `cmsPageBuilderEnabled()`
  - `cmsPageBuilderSettings()`
  - `cmsPageBuilderContentJson()`
  - `cmsPageBuilderBlocks()`
  - `cmsPageBuilderRenderedHtml()`
- transitional persistence:
  - `_builder_enabled` meta
  - `_builder_content` meta
  - `_builder_page_settings` meta
  - `_template` meta
  - `blocks_json` fallback
- reusable primitive:
  - `cms_saved_blocks`

## Engineering Decision

Phase 1 should **add canonical builder documents without breaking the transitional builder**.

---

# 2. Phase 1 Outcome

At the end of Phase 1, the codebase should support:

- `content_mode` on `cms_content`
- canonical `cms_builder_documents`
- canonical `cms_builder_revisions`
- canonical `cms_builder_reusable_sections`
- canonical `cms_builder_templates`
- builder document load/save/publish endpoints
- compatibility bridge from current `_builder_*` meta to builder documents
- public render path that can prefer builder documents while preserving old builder/meta pages

---

# 3. Database / Migration Plan

## New Migration File

Recommended next migration:

- `modules/cms/database/migrations/011_cms_builder_documents.sql`

## Responsibilities

### Extend `cms_content`

Add:

- `content_mode VARCHAR(20) NOT NULL DEFAULT 'standard'`
- `builder_document_id INT UNSIGNED DEFAULT NULL`
- index for `content_mode`

### Create `cms_builder_documents`

Purpose:

- canonical builder document persistence
- draft/published separation
- schema/document versioning
- render hash tracking

### Create `cms_builder_revisions`

Purpose:

- builder-specific revision snapshots
- revision restore target for canonical builder documents

### Create `cms_builder_reusable_sections`

Purpose:

- canonical reusable fragment library
- future replacement/evolution of `cms_saved_blocks`

### Create `cms_builder_templates`

Purpose:

- starter page templates
- system and custom templates

## Module Manifest Changes

Update `modules/cms/module.json`:

- add new owned tables
- add migration `011_cms_builder_documents.sql`
- add builder events/capabilities as they become real

---

# 4. File-Level Implementation Plan

## 4.1 `modules/cms/database/migrations/011_cms_builder_documents.sql`

Add:

- content mode columns
- builder document tables
- indexes and FKs

## 4.2 `modules/cms/module.json`

Add to:

- `owns_tables`
  - `cms_builder_documents`
  - `cms_builder_revisions`
  - `cms_builder_reusable_sections`
  - `cms_builder_templates`
- `migrations`
  - `database/migrations/011_cms_builder_documents.sql`
- `capabilities.exposes`
  - builder document capabilities if implemented in this phase
- `events`
  - builder document lifecycle events if implemented in this phase

## 4.3 `modules/cms/helpers.php`

Add new canonical helper layer.

Recommended new helpers:

- `cmsBuilderDefaultDocument()`
- `cmsBuilderNormalizeDocument()`
- `cmsBuilderValidateDocument()`
- `cmsBuilderLoadDraftDocument(int $contentId)`
- `cmsBuilderLoadPublishedDocument(int $contentId)`
- `cmsBuilderSaveDraftDocument(...)`
- `cmsBuilderPublishDocument(...)`
- `cmsBuilderCreateRevision(...)`
- `cmsBuilderRenderDocument(array $document, array $context = [])`
- `cmsBuilderRenderNode(array $node, array $context = [])`
- `cmsBuilderEnsureCompatibilityDocument(array $contentRow, array $meta)`

Compatibility bridge helpers:

- map `_builder_content` / `blocks_json` into a temporary canonical document shape
- map `cms_saved_blocks` into future reusable section shape where needed

## 4.4 `modules/cms/handlers.php`

Keep existing transitional handlers in place, but add canonical builder endpoints.

Recommended new handlers:

- `cmsApiBuilderDocumentGet()`
- `cmsApiBuilderDocumentSave()`
- `cmsApiBuilderDocumentPublish()`
- `cmsApiBuilderDocumentPreview()`
- `cmsApiBuilderRevisionList()`
- `cmsApiBuilderRevisionRestore()`
- `cmsApiBuilderReusableList()`

Existing handlers to adapt carefully:

- `cmsAdminPageBuilderCreate()`
- `cmsAdminPageBuilderEdit()`
- `cmsApiPageBuilderSave()`
- public render handlers that currently rely on `cmsPageBuilderRenderedHtml()`

## 4.5 `modules/cms/routes.php`

Add canonical builder routes without removing transitional ones.

Recommended GET routes:

- `/api/v1/cms/content/{id}/builder`
- `/api/v1/cms/content/{id}/builder/preview`
- `/api/v1/cms/content/{id}/builder/revisions`
- `/api/v1/cms/builder/reusable-sections`
- `/api/v1/cms/builder/templates`

Recommended POST routes:

- `/api/v1/cms/content/{id}/builder`
- `/api/v1/cms/content/{id}/builder/publish`
- `/api/v1/cms/content/{id}/builder/revisions/{revisionId}/restore`
- `/api/v1/cms/builder/reusable-sections`

Compatibility routes to keep:

- `/api/v1/cms/page-builder`
- `/api/v1/cms/page-builder/{id}`

## 4.6 `templates/modules/cms/admin/page-builder.disyl`

Phase 1 recommendation:

- keep the current template
- gradually switch its JS load/save target to the new canonical endpoints
- avoid a UI rewrite until canonical persistence exists and is stable

---

# 5. Responsibility Split

## Persistence Layer

Owned by helpers/services in `modules/cms/helpers.php` first, then refactor out later.

Responsibilities:

- load/save draft documents
- publish current draft
- create revision snapshots
- bridge old meta-based builder data

## Validation Layer

Responsibilities:

- validate root shape
- validate required node fields
- validate supported node types
- reject malformed/non-array children

Phase 1 validation should be intentionally minimal but strict enough to protect persistence.

## Rendering Layer

Responsibilities:

- render canonical document trees
- allow compatibility mapping from old blocks to canonical nodes
- preserve current public behavior during rollout

## API Layer

Responsibilities:

- expose canonical document endpoints
- return stable `{ok, data|error}` shape
- enforce permissions
- keep transitional endpoints functioning

---

# 6. Minimal Canonical Document for Phase 1

Recommended shape:

```json
{
  "schema_version": "1.0",
  "document": {
    "id": "doc_root",
    "type": "document",
    "kind": "document",
    "version": 1,
    "props": {},
    "style": {},
    "responsive": {},
    "visibility": {},
    "meta": {},
    "children": []
  }
}
```

## Supported Phase 1 Node Types

- `document`
- `section`
- `container`
- `heading`
- `text`
- `image`
- `button`
- `divider`
- `spacer`

Compatibility mapping can still convert older block types into a closest canonical shape.

---

# 7. API Plan for Phase 1

## Canonical Endpoints to Implement Now

### GET `/api/v1/cms/content/{id}/builder`

Returns:

- content metadata
- current draft document if present
- compatibility-generated document if only old builder data exists

### POST `/api/v1/cms/content/{id}/builder`

Purpose:

- save canonical draft document
- set `content_mode = 'builder'` for pages

### POST `/api/v1/cms/content/{id}/builder/publish`

Purpose:

- publish builder draft
- update `builder_document_id`
- create revision snapshot
- emit publish event

### GET `/api/v1/cms/content/{id}/builder/preview`

Purpose:

- render preview HTML from canonical document
- avoid forcing a publish just to inspect output

---

# 8. Compatibility Strategy

## Keep These Working During Phase 1

- current page-builder admin screen
- current `_builder_enabled` page render path
- current `_builder_content` and `blocks_json` fallback logic
- current saved block records

## Introduce These Alongside Them

- canonical builder document tables
- canonical builder APIs
- canonical builder render helpers
- content mode tracking

## Later Migration Path

- migrate existing `_builder_content` into `cms_builder_documents`
- optionally convert `cms_saved_blocks` into `cms_builder_reusable_sections`
- retire `_builder_enabled` and related meta after compatibility window

---

# 9. Recommended Order of Code Changes

## Step 1

Create migration `011_cms_builder_documents.sql`

## Step 2

Update `modules/cms/module.json`

## Step 3

Add canonical builder helpers in `modules/cms/helpers.php`

## Step 4

Add canonical builder API routes in `modules/cms/routes.php`

## Step 5

Add canonical builder handlers in `modules/cms/handlers.php`

## Step 6

Wire current page-builder UI to read/write canonical documents while preserving compatibility

## Step 7

Update public render path to prefer canonical builder documents over legacy meta-based builder content

---

# 10. Definition of Done for Phase 1

Phase 1 is done when:

- pages can be marked `content_mode = 'builder'`
- canonical builder draft documents can be saved and loaded
- builder draft can be published
- public rendering can consume canonical builder documents
- existing transitional builder pages still render correctly
- no abrupt removal of the current builder flow is required
