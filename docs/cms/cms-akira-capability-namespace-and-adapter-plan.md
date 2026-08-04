# CMS Akira Capability Namespace and Adapter Plan

Status: Bootstrap baseline (Phase A)
Owner: CMS Akira Composer-First Track
Date updated: 2026-08-04

## Purpose
Define the initial capability namespace strategy for CMS Akira while preserving `cms.content.*` compatibility during migration.

## Namespace policy
- Existing stable namespace remains authoritative during transition:
  - `cms.content.*@1`
- New Akira namespace is introduced in parallel:
  - `akira.content.*@1`
  - `akira.type.*@1`
  - `akira.taxonomy.*@1`
  - `akira.revision.*@1`
  - `akira.publication.*@1`
  - `akira.query.*@1`

## Compatibility contract
- `cms.content.*@1` remains backward compatible and must not break.
- Adapter mapping is required before any ownership shift.
- Non-additive schema changes require new version tags (`@2+`) plus adapters.

## Adapter direction (bootstrap)
| Legacy contract | Akira target | Adapter mode | Phase |
|---|---|---|---|
| `cms.content.get@1` | `akira.content.get@1` | pass-through (legacy->akira) | Phase B |
| `cms.content.list@1` | `akira.content.list@1` | pass-through (legacy->akira) | Phase B |
| `cms.content.create@1` | `akira.content.create@1` | pass-through (legacy->akira) | Phase B |
| `cms.content.update@1` | `akira.content.update@1` | pass-through (legacy->akira) | Phase B |

## Provider boundary alignment
Akira adapters must route through provider boundaries and must not bypass module contracts:
- MediaGateway
- EditorProvider
- PresentationProvider
- ThemeProvider
- NavigationProvider
- SeoProvider
- SearchIndexer
- WorkflowProvider
- IdentityResolver

## Validation gates
- `php ikabud architecture:check` passes.
- Existing compatibility tests remain green:
  - `tests/cms_contract_freeze_v1_test.php`
  - `tests/cms_content_consumer_compat_test.php`
  - `tests/cms_optional_module_disablement_test.php`
- Akira scaffold tests pass syntax and bootstrap checks.

## Out of scope for this phase
- No high-risk ownership migration (identity/media/builder data moves).
- No kernel boundary redesign.
- No direct cross-module table coupling.
