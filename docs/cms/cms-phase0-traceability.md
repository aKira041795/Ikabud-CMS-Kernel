# CMS Phase 0 Traceability and Evidence

Status: Baseline v1
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Provide a single evidence map connecting roadmap claims to concrete implementation artifacts (routes, handlers, capability mappings, and tests).

## A) Core Content Route Evidence
| Route | Route declaration | Handler function | Primary tests |
|---|---|---|---|
| `GET /api/v1/cms/content` | `modules/cms/routes.php:50` | `modules/cms/handlers/35-api-content.php:5` | `tests/cms_crud_test.php` |
| `GET /api/v1/cms/content/{id}` | `modules/cms/routes.php:51` | `modules/cms/handlers/35-api-content.php:156` | `tests/cms_crud_test.php`, `tests/cms_content_access_scope_test.php` |
| `POST /api/v1/cms/content` | `modules/cms/routes.php:135` | `modules/cms/handlers/35-api-content.php:1303` | `tests/cms_crud_test.php`, `tests/stress_architecture_test.php` |
| `POST /api/v1/cms/content/{id}` | `modules/cms/routes.php:139` | `modules/cms/handlers/35-api-content.php:1542` | `tests/stress_architecture_test.php` |

## B) Public Render Route Evidence
| Route | Route declaration | Handler function | Primary tests |
|---|---|---|---|
| `GET /cms/blog/{slug}` | `modules/cms/routes.php:102` | `modules/cms/handlers/90-public.php:1152` | `tests/poc_render_test.php` |
| `GET /cms/page/{slug}` | `modules/cms/routes.php:103` | `modules/cms/handlers/90-public.php:1264` | `tests/poc_render_test.php` |

## C) Optional-Concern Route Evidence
| Concern | Route | Handler | Baseline tests | Extraction candidate |
|---|---|---|---|---|
| Builder | `GET /api/v1/cms/content/{id}/builder` | `modules/cms/handlers/20-api-builder.php:5` | `tests/stress_architecture_test.php` | `cms-builder` |
| Builder | `POST /api/v1/cms/content/{id}/builder` | `modules/cms/handlers/20-api-builder.php:76` | `tests/stress_architecture_test.php` | `cms-builder` |
| Theme | `GET /api/v1/cms/customizer/{scope}/{section}` | `modules/cms/handlers/80-customizer.php:174` | `tests/theme_manifest_integration_test.php` | `cms-theme` |
| Navigation | `GET /api/v1/cms/menus` | `modules/cms/handlers/70-menu.php:37` | `tests/poc_render_test.php` | `cms-navigation` |
| AI | `GET /api/v1/cms/ai/plans` | `modules/cms/handlers/86-ai-automation.php:32` | `tests/stress_architecture_test.php` | `cms-ai-assistant` |
| Workflow | `POST /api/v1/cms/content/{id}/workflow/transition` | `modules/cms/handlers/35-api-content.php:247` | `tests/stress_architecture_test.php` | `cms-workflow` |

## D) Core Capability Evidence
| Capability | Provider map | Provider function | Known module consumer | Baseline tests |
|---|---|---|---|---|
| `cms.content.get@1` | `modules/cms/helpers/55-capabilities.php:8` | `modules/cms/helpers/55-capabilities.php:42` | `theme-studio` | `tests/cms_crud_test.php`, `tests/cms_content_access_scope_test.php` |
| `cms.content.list@1` | `modules/cms/helpers/55-capabilities.php:20` | `modules/cms/helpers/55-capabilities.php:80` | pending expansion | `tests/cms_crud_test.php`, `tests/kernel_load_test.php` |
| `cms.content.create@1` | `modules/cms/helpers/55-capabilities.php:21` | `modules/cms/helpers/55-capabilities.php:345` | `content-ingestion` | `tests/cms_crud_test.php`, `tests/stress_architecture_test.php` |
| `cms.content.update@1` | `modules/cms/helpers/55-capabilities.php:22` | `modules/cms/helpers/55-capabilities.php:451` | `content-ingestion` | `tests/stress_architecture_test.php` |

## E) Review Gaps (must close before Phase 1 sign-off)
1. Add explicit payload schema snapshots for all frozen `cms.content.*` contracts.
2. Add module-level compatibility tests for `theme-studio` and `content-ingestion`. (Closed by `tests/cms_content_consumer_compat_test.php`)
3. Add optional-module disablement tests for builder/theme/navigation/AI/workflow concerns. (Partially closed by `tests/cms_optional_module_disablement_test.php` for existing optional modules: theme-studio, ai-orchestrator, workflow, tinymce; builder/navigation remain CMS-internal until extraction modules exist.)
