---
description: Page Builder Schema and API Proposal — Data Model, Contracts, and Endpoint Surface
---

# Page Builder Schema and API Proposal

This document defines the proposed database, document schema, capability surface, hooks, events, and HTTP endpoints for the CMS page builder subsystem.

---

# 1. Design Goals

- preserve CMS ownership of builder state
- allow builder documents to evolve safely
- support page drafts, previews, publishes, and revisions
- support reusable sections and starter templates
- provide a clean API for the builder editor runtime
- maintain compatibility with existing CMS page records

---

# 2. Database Proposal

## 2.1 Extend `cms_content`

Recommended additions:

```sql
ALTER TABLE cms_content
    ADD COLUMN content_mode VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER type,
    ADD COLUMN builder_document_id INT UNSIGNED DEFAULT NULL AFTER body,
    ADD KEY idx_content_mode (content_mode);
```

Notes:

- `content_mode` distinguishes `standard` vs `builder`
- `builder_document_id` points to the currently active builder document
- `body` remains available for legacy content and fallback rendering

## 2.2 `cms_builder_documents`

```sql
CREATE TABLE cms_builder_documents (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id       INT UNSIGNED NOT NULL,
    schema_version   VARCHAR(20) NOT NULL DEFAULT '1.0',
    document_version INT UNSIGNED NOT NULL DEFAULT 1,
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    title            VARCHAR(255) NOT NULL,
    document_json    JSON NOT NULL,
    render_hash      CHAR(64) DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    updated_by       INT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES cms_content(id) ON DELETE CASCADE,
    INDEX idx_content_status (content_id, status),
    INDEX idx_render_hash (render_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 2.3 `cms_builder_revisions`

```sql
CREATE TABLE cms_builder_revisions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    builder_document_id INT UNSIGNED NOT NULL,
    revision_number     INT UNSIGNED NOT NULL,
    snapshot_json       JSON NOT NULL,
    note                VARCHAR(255) DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (builder_document_id) REFERENCES cms_builder_documents(id) ON DELETE CASCADE,
    UNIQUE KEY uq_doc_revision (builder_document_id, revision_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 2.4 `cms_builder_reusable_sections`

```sql
CREATE TABLE cms_builder_reusable_sections (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(255) NOT NULL,
    scope         ENUM('personal','shared','global') NOT NULL DEFAULT 'shared',
    fragment_json JSON NOT NULL,
    created_by    INT UNSIGNED DEFAULT NULL,
    updated_by    INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 2.5 `cms_builder_templates`

```sql
CREATE TABLE cms_builder_templates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(255) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    category      VARCHAR(100) NOT NULL DEFAULT 'page',
    preview_image VARCHAR(255) DEFAULT NULL,
    template_json JSON NOT NULL,
    is_system     TINYINT(1) NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

# 3. Document JSON Schema Proposal

## 3.1 Root Shape

```json
{
  "schema_version": "1.0",
  "document": {
    "id": "doc_root",
    "type": "document",
    "kind": "document",
    "version": 1,
    "props": {
      "title": "Homepage"
    },
    "style": {},
    "responsive": {},
    "visibility": {},
    "meta": {},
    "children": []
  }
}
```

## 3.2 Section Example

```json
{
  "id": "sec_hero",
  "type": "section",
  "kind": "section",
  "version": 1,
  "props": {
    "variant": "default",
    "container_width": "xl"
  },
  "style": {
    "padding_y": "12",
    "background_token": "surface"
  },
  "responsive": {
    "tablet": {},
    "mobile": {}
  },
  "visibility": {},
  "meta": {},
  "children": []
}
```

## 3.3 Widget Example

```json
{
  "id": "w_heading_1",
  "type": "heading",
  "kind": "widget",
  "version": 1,
  "props": {
    "text": "Freshly baked every day",
    "level": "h1",
    "preset": "hero"
  },
  "style": {
    "align": "center"
  },
  "responsive": {
    "mobile": {
      "preset": "section"
    }
  },
  "visibility": {},
  "meta": {},
  "children": []
}
```

## 3.4 Validation Rules

- all nodes require `id`, `type`, `kind`, `version`
- only allowed `children` may appear under a given parent type
- unsupported props are rejected at save time
- `meta` is editor-only and not trusted for render semantics
- values must conform to the widget definition schema

---

# 4. Capability Proposal

## 4.1 Builder Capabilities

| Capability ID | Mode | Purpose |
|---|---|---|
| `cms.builder.document.get@1` | first | Fetch builder document for content |
| `cms.builder.document.save@1` | first | Save draft builder document |
| `cms.builder.document.publish@1` | first | Publish current builder draft |
| `cms.builder.document.validate@1` | first | Validate document payload |
| `cms.builder.document.render@1` | first | Render builder document to HTML |
| `cms.builder.revisions.list@1` | first | List builder revisions |
| `cms.builder.revisions.restore@1` | first | Restore revision into draft |
| `cms.builder.reusable.list@1` | first | List reusable sections |
| `cms.builder.reusable.save@1` | first | Create/update reusable section |
| `cms.builder.templates.list@1` | first | List starter templates |

## 4.2 Future Capabilities

| Capability ID | Purpose |
|---|---|
| `cms.builder.widgets.list@1` | Inspect available widgets for editor UI |
| `cms.builder.dynamic.resolve@1` | Resolve dynamic field sources |
| `cms.builder.migrate@1` | Migrate outdated builder documents |
| `cms.builder.audit.inspect@1` | Inspect builder usage/dependencies |

---

# 5. Hook Proposal

## 5.1 Builder Hooks

| Hook Name | Type | Default | Description |
|---|---|---|---|
| `cms.builder.widgets` | filter | `[]` | Register widget definitions |
| `cms.builder.templates` | filter | `[]` | Register page starter templates |
| `cms.builder.dynamic_sources` | filter | `[]` | Register dynamic data bindings |
| `cms.builder.render.node` | filter | `$html` | Transform rendered node HTML |
| `cms.builder.editor.panels` | filter | `[]` | Register extra editor panels |
| `cms.builder.policy.widgets` | filter | `[]` | Restrict/allow widgets by role or content type |

## 5.2 Hook Constraints

- hooks may extend builder behavior but must not mutate document storage rules
- hooks may not bypass server-side validation
- hooks should fail safely and log meaningful errors

---

# 6. Event Proposal

## 6.1 Builder Events

| Event Key | Description | Payload |
|---|---|---|
| `cms.builder.document.saved` | Draft builder document saved | `content_id, document_id, schema_version, actor_id` |
| `cms.builder.document.published` | Builder document published | `content_id, document_id, actor_id` |
| `cms.builder.document.restored` | Builder revision restored | `content_id, document_id, revision_id, actor_id` |
| `cms.builder.reusable.saved` | Reusable section saved | `reusable_id, slug, actor_id` |
| `cms.builder.template.applied` | Starter template applied to page | `content_id, template_slug, actor_id` |

---

# 7. HTTP API Proposal

## 7.1 Builder Document Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/cms/content/{id}/builder` | Fetch active builder draft/published document |
| PUT | `/api/v1/cms/content/{id}/builder` | Save builder draft |
| POST | `/api/v1/cms/content/{id}/builder/publish` | Publish builder draft |
| POST | `/api/v1/cms/content/{id}/builder/validate` | Validate builder payload without save |
| GET | `/api/v1/cms/content/{id}/builder/preview` | Render preview HTML |

## 7.2 Revision Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/cms/content/{id}/builder/revisions` | List builder revisions |
| POST | `/api/v1/cms/content/{id}/builder/revisions/{revisionId}/restore` | Restore revision to draft |

## 7.3 Reusable Section Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/cms/builder/reusable-sections` | List reusable sections |
| POST | `/api/v1/cms/builder/reusable-sections` | Create reusable section |
| PUT | `/api/v1/cms/builder/reusable-sections/{id}` | Update reusable section |
| DELETE | `/api/v1/cms/builder/reusable-sections/{id}` | Delete reusable section |

## 7.4 Template Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/cms/builder/templates` | List starter templates |
| POST | `/api/v1/cms/content/{id}/builder/templates/{slug}/apply` | Apply starter template to page |

## 7.5 Registry/Editor Support Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/cms/builder/widgets` | List available widgets and inspector contracts |
| GET | `/api/v1/cms/builder/dynamic-sources` | List dynamic data sources |

---

# 8. Request/Response Shape Guidelines

## 8.1 Save Draft Request

```json
{
  "title": "Homepage",
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

## 8.2 Standard Success Response

```json
{
  "ok": true,
  "data": {
    "document_id": 12,
    "content_id": 44,
    "schema_version": "1.0",
    "status": "draft",
    "updated_at": "2026-03-08T21:00:00Z"
  }
}
```

## 8.3 Validation Error Response

```json
{
  "ok": false,
  "error": "validation_failed",
  "issues": [
    {
      "path": "document.children[0].children[1].props.url",
      "message": "Invalid URL format"
    }
  ]
}
```

---

# 9. Cache and Invalidation Proposal

## Cache Tags

- `cms:content:{id}`
- `cms:page:{slug}`
- `cms:builder:document:{id}`
- `cms:builder:reusable:{id}`
- `cms:builder:template:{slug}`

## Invalidate On

- builder draft publish
- builder document restore
- page slug/status change
- template application
- builder widget registry changes that affect render output

---

# 10. Backward Compatibility Strategy

- existing `body`-based pages remain valid
- pages opt into builder via `content_mode = 'builder'`
- a page may keep `body` for migration fallback until builder adoption is complete
- public render path must branch cleanly between standard and builder content

---

# 11. Recommended Next Step

Implement the schema in phases beginning with:

1. builder document tables
2. content mode switch for pages
3. builder document save/load/preview API
4. trusted render pipeline
5. registry endpoint for initial widgets
