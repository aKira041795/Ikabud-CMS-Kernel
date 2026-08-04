# CMS Contract Freeze v1

Status: Baseline freeze candidate (Phase 1 prep)
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Freeze `cms.content.*@1` contract expectations before extraction work so internal refactors do not break consumers.

## Contract family in scope
- `cms.content.get@1`
- `cms.content.list@1`
- `cms.content.create@1`
- `cms.content.update@1`

## Provider mapping evidence
Source: `modules/cms/helpers/55-capabilities.php`

| Capability ID | Handler map entry | Provider function |
|---|---|---|
| `cms.content.get@1` | line 8 | `cms_cap_cms_content_get_1` (line 42) |
| `cms.content.list@1` | line 20 | `cms_cap_cms_content_list_1` (line 80) |
| `cms.content.create@1` | line 21 | `cms_cap_cms_content_create_1` (line 345) |
| `cms.content.update@1` | line 22 | `cms_cap_cms_content_update_1` (line 451) |

## Frozen early-validation behavior
These checks happen before deeper module orchestration and are treated as stable v1 behavior.

### `cms.content.get@1`
- invalid/missing `id` -> `{ "ok": false, "error": "id is required" }`

### `cms.content.create@1`
- non-object payload -> `{ "ok": false, "error": "payload must be an object" }`
- missing/blank title -> `{ "ok": false, "error": "title is required" }`

### `cms.content.update@1`
- non-object payload -> `{ "ok": false, "error": "payload must be an object" }`
- invalid/missing `id` -> `{ "ok": false, "error": "id is required" }`

## Current success-shape baseline
Source behavior as implemented in provider functions.

| Capability | Success shape baseline |
|---|---|
| `cms.content.get@1` | `{ ok: true, data: object }` |
| `cms.content.list@1` | `{ ok: true, data: array }` |
| `cms.content.create@1` | `{ ok: true, id: int, slug: string }` |
| `cms.content.update@1` | `{ ok: true, id: int }` |

## Compatibility policy
- Keep `@1` behavior backward compatible for existing consumers.
- Any non-additive payload change requires new contract version and adapter path.
- Extraction phases must preserve these contracts through provider or adapter boundaries.

## Verification artifact
- `tests/cms_contract_freeze_v1_test.php` validates handler mapping and frozen early-validation semantics.
