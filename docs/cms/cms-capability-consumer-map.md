# CMS Capability Consumer Map

Status: Baseline v1 (Phase 0 in progress)
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Map stable CMS contracts to all providers/consumers so extraction does not break integrations.

## Contract Stability Policy
- `@1` contracts are considered stable and backward compatible.
- Extraction cannot remove or change payload semantics without adapter or version bump.

## Contract Inventory
| Capability ID | Current provider | Consumer modules | Mode | Payload schema ref | Stability | Notes |
|---|---|---|---|---|---|---|
| `cms.content.get@1` | CMS | `theme-studio` + tests | first | `modules/cms/helpers/55-capabilities.php` | Stable | core read contract |
| `cms.content.list@1` | CMS | `cms` entity-view contracts + tests | first | `modules/cms/helpers/55-capabilities.php` | Stable | entity-view contracts in `modules/cms/helpers/58-entity-views.php` gate list/detail rendering |
| `cms.content.create@1` | CMS | `content-ingestion` + tests | first | `modules/cms/helpers/55-capabilities.php` | Stable | write path dependency |
| `cms.content.update@1` | CMS | `content-ingestion` + tests | first | `modules/cms/helpers/55-capabilities.php` | Stable | write/update authority remains core |

Known CMS capability dependencies (manifest):
- `kernel.auth.user@1`
- `kernel.audit.record@1`
- `ai.text.generate@1`
- `workflow.state.get@1`
- `workflow.transition@1`
- `tinymce.assets.get@1`
- `tinymce.config.get@1`
- `tinymce.html.normalize@1`
- `tinymce.html.sanitize@1`

## Consumer Criticality
| Consumer module | Capability | Critical path | Fallback exists | Break impact | Owner |
|---|---|---|---|---|---|
| `theme-studio` | `cms.content.get@1` | Theme-aware content retrieval | Partial | High | Theme Studio |
| `content-ingestion` | `cms.content.create@1` | Ingestion write flow | Limited | High | Content Ingestion |
| `content-ingestion` | `cms.content.update@1` | Ingestion update flow | Limited | High | Content Ingestion |
| `tests/*` | `cms.content.*` | Regression gate | N/A | High (quality gate) | QA/Architecture |
| `cms` entity-view contracts | `cms.content.list@1` | Public/admin list rendering gate | No | High | CMS Core |

## Planned Contract Additions
| Planned capability | Intended provider | Why needed | Earliest phase | Backward compatibility strategy |
|---|---|---|---|---|
| `cms.publication.transition@1` | CMS Core | Isolate publication state | Phase 1 | Additive |
| `cms.render.resolve@1` | Presentation provider | Decouple theme/builder render decisions | Phase 2 | Additive with fallback to current resolver |
| `cms.media.resolve@1` | Media gateway | Remove direct media table coupling | Phase 2-7 | Adapter then provider switch |

## Migration Adapters Required
| Old behavior | New provider | Adapter path | Removal condition |
|---|---|---|---|
| Direct table read | capability call | module adapter | all consumers migrated |
| CMS->Tinymce direct assumptions | `editor.*@1` provider family | editor adapter layer | TinyMCE optional and replaceable |
| CMS->Builder document direct parsing | presentation provider + ARK contract | builder adapter | CMS core no longer parses builder schema |

## Exit Criteria Evidence
- [x] Baseline public CMS capability consumer map exists for core contracts.
- [x] High-impact consumers are identified for immediate compatibility testing.
- [x] Adapter direction is documented for high-risk extractions.

## Gaps to close before Phase 1 exit
- Expand consumer discovery for `cms.content.list@1` beyond tests.
- Add explicit payload schema references for each core contract in documentation.
- Add module-level contract compatibility tests for `theme-studio` and `content-ingestion`.

## Capability-to-Test Traceability Matrix (baseline)
| Capability | Provider mapping evidence | Known module consumers | Baseline tests | Contract freeze readiness |
|---|---|---|---|---|
| `cms.content.get@1` | `modules/cms/helpers/55-capabilities.php:8`, `:42` | `theme-studio` | `tests/cms_crud_test.php`, `tests/cms_content_access_scope_test.php`, `tests/stress_architecture_test.php` | Medium (needs payload schema doc freeze) |
| `cms.content.list@1` | `modules/cms/helpers/55-capabilities.php:20`, `:80` | none confirmed beyond tests | `tests/cms_crud_test.php`, `tests/kernel_load_test.php`, `tests/stress_architecture_test.php` | Medium-Low (consumer map expansion needed) |
| `cms.content.create@1` | `modules/cms/helpers/55-capabilities.php:21`, `:345` | `content-ingestion` | `tests/cms_crud_test.php`, `tests/stress_architecture_test.php` | Medium (external consumer compatibility tests needed) |
| `cms.content.update@1` | `modules/cms/helpers/55-capabilities.php:22`, `:451` | `content-ingestion` | `tests/stress_architecture_test.php` | Medium (adapter path for extraction phases must be codified) |

## Phase 1 Contract Freeze Checklist
- [x] Freeze payload schema snapshots for `cms.content.get@1`, `cms.content.list@1`, `cms.content.create@1`, `cms.content.update@1`.
- [x] Add module-level compatibility tests for `content-ingestion` create/update call paths.
- [x] Add module-level compatibility tests for `theme-studio` read path.
- [x] Define deprecation and version-bump policy for non-additive contract changes.

Phase 1 baseline artifacts:
- `docs/cms/cms-contract-freeze-v1.md`
- `tests/cms_contract_freeze_v1_test.php`
- `tests/cms_content_consumer_compat_test.php`
- `tests/cms_optional_module_disablement_test.php`
