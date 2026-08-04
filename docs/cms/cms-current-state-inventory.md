# CMS Current-State Inventory

Status: Baseline v1 (Phase 0 in progress)
Owner: CMS Architecture Review Track
Date started: 2026-08-04
Date updated: 2026-08-04

## Purpose
Capture the complete current-state footprint of CMS behavior before extraction work begins.

## Scope
- Module: `modules/cms`
- Related modules: `users`, `media`, `search`, `tinymce`, `workflow`, `ai`, `ecommerce`
- Surfaces: routes, handlers, helpers, templates, assets, jobs, settings, capabilities, events

## Inventory Checklist
- [x] Files inventoried by concern (baseline counts captured)
- [x] Tables inventoried with current owner and access mode (module manifest baseline)
- [x] Routes inventoried with owner and auth requirements (baseline route counts captured)
- [x] Settings inventoried with tenant scope and defaults (manifest defaults baseline)
- [x] Capability contracts inventoried with providers and consumers (core contracts + known consumers)
- [ ] Event emits/subscriptions fully mapped (baseline count captured, full map pending)
- [ ] Scheduled/async jobs fully mapped (AI automation identified, scheduler map pending)
- [x] Public/admin critical flows listed and mapped to tests (baseline mapping)

## 1) File Inventory by Concern
| Concern | Path | Type | Owner | Notes |
|---|---|---|---|---|
| Auth bridge | `modules/cms/handlers/10-auth.php` | Handler | CMS | |
| Public rendering | `modules/cms/handlers/90-public.php` | Handler | CMS | |
| Capabilities | `modules/cms/helpers/55-capabilities.php` | Helper | CMS | |
| Entity contexts | `modules/cms/helpers/57-entity-contexts.php` | Helper | CMS | |
| Content API | `modules/cms/handlers/35-api-content.php` | Handler | CMS | Contains create/list/get/update entry points |
| Builder API | `modules/cms/handlers/20-api-builder.php` | Handler | CMS | Candidate for provider-boundary extraction |
| Theme/customizer | `modules/cms/handlers/80-customizer.php` | Handler | CMS | Candidate for `cms-theme` extraction |
| Menu/navigation | `modules/cms/handlers/70-menu.php` | Handler | CMS | Candidate for `cms-navigation` extraction |

Observed structure counts:
- Split handlers loaded by `modules/cms/handlers.php`: 27
- Split helpers loaded by `modules/cms/helpers.php`: 28

## 2) Jobs / Async / Automation
| Job name | Trigger | Scheduler/Event | Module owner | Failure impact | Fallback |
|---|---|---|---|---|---|
| AI plan run | Manual/API | API trigger | CMS/AI | Content assist unavailable | Manual editing |
| Builder autosave | API request | request-driven | CMS | draft-save degradation | manual save fallback |
| Search index sync (integration) | content updates | event/capability dependent | CMS/Search | stale search results | direct CMS query |

## 3) Settings Inventory
| Setting key | Source module | Tenant-scoped | Default | Used by | Migration sensitivity |
|---|---|---|---|---|---|
| `active_theme` | CMS | Yes | theme slug | public render | High |
| `settings_fields` entries | CMS | Yes | 35 defaults in manifest | CMS admin + public behavior | Medium |

## 4) Surface Summary
| Surface | Count | Notes |
|---|---:|---|
| Module-level dependencies | 3 | users, media, search |
| Owned tables | 36 | from `modules/cms/module.json` |
| Read tables | 3 | kernel_event_triggers, rate_limits, workflow_definitions |
| Migration files | 27 | from `modules/cms/module.json` |
| Route entries (total) | 180 | line-counted from `modules/cms/routes.php` |
| API route entries | 137 | `/api/...` prefix |
| Admin route entries | 25 | `/cms/admin...` prefix |
| Public cms routes | 18 | `/cms...` excluding admin |
| Capability exposes | 25 | from `capabilities.exposes` |
| Capability depends | 9 | from `capabilities.depends` |
| Event declarations | 7 | `events` list length in manifest |

## 5) Critical Flows (Must Not Break)
| Flow | Entry route/capability | Dependencies | Current tests | Gap |
|---|---|---|---|---|
| Create + publish content | `/api/v1/cms/content` | auth, revision, publication | `tests/cms_crud_test.php` | publish-transition coverage depth by workflow profile |
| Public page render | `/cms/page/{slug}` | theme, cache, entity context | `tests/poc_render_test.php` | degradation test when theme/builder disabled |
| Contract-based content get | `cms.content.get@1` | capability bus, content authority | `tests/cms_content_access_scope_test.php` | module-level consumer contract tests beyond theme-studio |
| Contract-based content update | `cms.content.update@1` | capability bus, write authority | `tests/stress_architecture_test.php` | explicit rollback simulation coverage |

## Exit Criteria Evidence
- [x] Baseline production-critical behavior map exists.
- [x] Critical flow coverage map exists (baseline).
- [x] Unknown ownership areas are explicitly flagged in extraction candidates.

## Known extraction concentration points (baseline)
- `cms_media` domain is still CMS-owned and intersects with media module concerns.
- `cms_builder_documents` and builder revisions are still CMS-owned and must move behind ARK/provider boundary.
- theme/customizer and navigation are still CMS-owned and should move to dedicated optional modules.
- identity/credential data still appears in CMS-adjacent ownership and requires high-risk phase gating.
