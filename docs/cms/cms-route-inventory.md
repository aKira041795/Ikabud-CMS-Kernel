# CMS Route Inventory

Status: Baseline v1 (Phase 0 in progress)
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Inventory current route ownership and dependencies to protect behavior during phased modular extraction.

## Route Classification
- Admin UI routes
- API routes
- Public routes
- Headless delivery routes

Observed baseline route counts (from `modules/cms/routes.php`):
- Total CMS route entries: 180
- API route entries (`/api/...`): 137
- Admin route entries (`/cms/admin...`): 25
- Public CMS entries (`/cms...` excluding admin): 18

## Route Matrix
| Method | Route | Handler | Concern | AuthZ requirement | Tenant-sensitive | Depends on module(s) | Planned owner phase |
|---|---|---|---|---|---|---|---|
| GET | `/cms/admin` | `cmsAdminDashboard` | Admin shell | CMS role/cap | Yes | CMS | Core |
| GET | `/cms/page/{slug}` | `cmsPublicPage` | Public rendering | Public | Yes | CMS, theme | Core+Theme |
| POST | `/api/v1/cms/content` | `cmsApiContentCreate` | Content lifecycle | CMS editor cap | Yes | CMS | Core |
| GET | `/api/v1/cms/content` | `cmsApiContentList` | Content list API | CMS/editor context | Yes | CMS | Core |
| GET | `/api/v1/cms/content/{id}` | `cmsApiContentGet` | Content detail API | CMS/editor context | Yes | CMS | Core |
| POST | `/api/v1/cms/content/{id}` | `cmsApiContentUpdate` | Content update API | CMS editor cap | Yes | CMS | Core |
| GET | `/cms/blog/{slug}` | `cmsPublicSingle` | Public single delivery | Public | Yes | CMS, theme, cache | Core+Theme |
| GET | `/api/v1/cms/content/{id}/builder` | `cmsApiBuilderDocumentGet` | Builder delivery | CMS/editor context | Yes | CMS, builder | Builder phase |
| GET | `/api/v1/cms/customizer/{scope}/{section}` | `cmsApiCustomizerGet` | Theme customizer API | CMS admin role | Yes | CMS, theme | Theme phase |
| GET | `/api/v1/cms/menus` | `cmsApiMenuList` | Navigation API | CMS/admin context | Yes | CMS, navigation | Navigation phase |

## Route Risk Register
| Route | Risk type | Risk description | Mitigation | Test case |
|---|---|---|---|---|
| `/cms/{type}/{slug}` | Coupling | Implicit dependency on builder/theme | add provider fallback path | public entity render test |
| `/api/v1/cms/content/{id}/builder/*` | Ownership drift | CMS still owns builder document surface | provider boundary + `cms-builder` extraction | builder disablement fallback tests |
| `/api/v1/cms/customizer/*` | Ownership drift | Theme behavior embedded in CMS | extract to `cms-theme` module with adapter | customizer read/save compatibility test |
| `/api/v1/cms/menus*` | Ownership drift | Navigation behavior embedded in CMS | extract to `cms-navigation` module | menu API compatibility + public nav render |

## Disablement Expectations
| Optional module disabled | Routes expected to remain | Routes expected to degrade | Degradation behavior |
|---|---|---|---|
| SEO | content CRUD/public basic | SEO admin APIs | returns feature unavailable with no content loss |
| Builder | content CRUD/public basic | builder APIs/routes | fallback rendering path |
| Theme | content CRUD/API/headless | customizer/theme-admin/public advanced templates | default/fallback template render |
| Navigation | content CRUD/public direct page URLs | menu APIs/nav widgets | no-menu fallback without blocking content |
| AI assistant | content CRUD/public | AI plan/run endpoints | manual authoring remains available |
| Workflow extension | basic publish/unpublish | advanced review/escalation routes | reduced publication workflow path |

## Exit Criteria Evidence
- [x] Baseline CMS route classes and representative handlers are mapped.
- [x] Route dependency concentration points are explicit.
- [x] Optional-module degradation expectations are documented.

## Next completion steps
- Add exact auth requirement labels per route group (capability-level map).
- Add tenant-specific route behavior notes for profile installs.
- Link each critical route group to an owning regression test file.

## Route-to-Test Traceability Matrix (baseline)
| Route | Route map evidence | Handler function evidence | Primary concern | Baseline test evidence | Gap |
|---|---|---|---|---|---|
| `GET /api/v1/cms/content` | `modules/cms/routes.php:50` | `modules/cms/handlers/35-api-content.php:5` | Core content list | `tests/cms_crud_test.php` | Add per-profile compatibility assertions |
| `GET /api/v1/cms/content/{id}` | `modules/cms/routes.php:51` | `modules/cms/handlers/35-api-content.php:156` | Core content get | `tests/cms_crud_test.php`, `tests/cms_content_access_scope_test.php` | Add tenant isolation explicit checks |
| `POST /api/v1/cms/content` | `modules/cms/routes.php:135` | `modules/cms/handlers/35-api-content.php:1303` | Core content create | `tests/cms_crud_test.php`, `tests/stress_architecture_test.php` | Add graceful-degradation when optional modules off |
| `POST /api/v1/cms/content/{id}` | `modules/cms/routes.php:139` | `modules/cms/handlers/35-api-content.php:1542` | Core content update | `tests/stress_architecture_test.php` | Add contract parity assertions for external consumers |
| `GET /cms/blog/{slug}` | `modules/cms/routes.php:102` | `modules/cms/handlers/90-public.php:1152` | Public single render | `tests/poc_render_test.php` | Add fallback-render test when theme/builder unavailable |
| `GET /cms/page/{slug}` | `modules/cms/routes.php:103` | `modules/cms/handlers/90-public.php:1264` | Public page render | `tests/poc_render_test.php` | Add minimal profile test path |
| `GET /api/v1/cms/content/{id}/builder` | `modules/cms/routes.php:53` | `modules/cms/handlers/20-api-builder.php:5` | Builder read API | `tests/stress_architecture_test.php` | Add builder-disabled degradation test |
| `POST /api/v1/cms/content/{id}/builder` | `modules/cms/routes.php:140` | `modules/cms/handlers/20-api-builder.php:76` | Builder write API | `tests/stress_architecture_test.php` | Add schema-version rollback compatibility checks |
| `GET /api/v1/cms/customizer/{scope}/{section}` | `modules/cms/routes.php:123` | `modules/cms/handlers/80-customizer.php:174` | Theme customizer read | `tests/theme_manifest_integration_test.php` | Add per-tenant settings boundary test |
| `GET /api/v1/cms/menus` | `modules/cms/routes.php:114` | `modules/cms/handlers/70-menu.php:37` | Navigation read | `tests/poc_render_test.php` | Add explicit nav extraction compatibility tests |
| `GET /api/v1/cms/ai/plans` | `modules/cms/routes.php:59` | `modules/cms/handlers/86-ai-automation.php:32` | AI orchestration read | `tests/stress_architecture_test.php` | Add AI-disabled graceful-degradation tests |
| `POST /api/v1/cms/content/{id}/workflow/transition` | `modules/cms/routes.php:152` | `modules/cms/handlers/35-api-content.php:247` | Workflow transition | `tests/stress_architecture_test.php` | Add workflow-module-off fallback behavior test |
