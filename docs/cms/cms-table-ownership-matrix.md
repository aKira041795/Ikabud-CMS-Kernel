# CMS Table Ownership Matrix

Status: Baseline v1 (Phase 0 in progress)
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Define authoritative ownership for all CMS-adjacent tables and expose cross-module access risks before extraction.

## Rules
- One canonical owner per table.
- Cross-module access must be capability/event/adapter based.
- Any direct table coupling must be marked as migration debt.

## Matrix
| Table | Canonical owner module | Current writer(s) | Current reader(s) | Tenant scoped | Planned owner (post-extraction) | Risk | Action |
|---|---|---|---|---|---|---|---|
| `cms_content` | CMS | CMS | CMS, API consumers | Yes | CMS Core | Low | Keep |
| `cms_content_types` | CMS | CMS | CMS, builder, integrations | Yes | CMS Core | Low | Keep |
| `cms_field_definitions` | CMS | CMS | CMS, renderer, builder | Yes | CMS Core | Low | Keep |
| `cms_revisions` | CMS | CMS | CMS | Yes | CMS Core | Low | Keep |
| `cms_slug_redirects` | CMS | CMS | CMS/public delivery | Yes | SEO (candidate) | Medium | extract with compatibility adapter |
| `cms_media` | CMS | CMS | CMS, storefront | Yes | Media | High | Plan migration adapter |
| `cms_media_usage` | CMS | CMS | CMS, storefront | Yes | Media | High | move with media authority |
| `cms_builder_documents` | CMS | CMS | CMS builder/public | Yes | Builder | High | Introduce provider boundary |
| `cms_builder_revisions` | CMS | CMS | CMS builder | Yes | Builder | High | move after versioned schema |
| `cms_builder_templates` | CMS | CMS | CMS builder | Yes | Builder | Medium | move with builder module |
| `cms_menus` | CMS | CMS | Public renderer | Yes | Navigation | Medium | Extract with stable refs |
| `cms_menu_items` | CMS | CMS | Public renderer | Yes | Navigation | Medium | extract with content ID refs |
| `cms_menu_locations` | CMS | CMS | Public renderer | Yes | Navigation | Medium | extract with route compatibility |
| `cms_theme_customizer` | CMS | CMS | Public renderer | Yes | Theme | Medium | Extract after theme provider |
| `cms_ai_content_plans` | CMS | CMS | CMS AI automation | Yes | AI Assistant | Medium | extract low-risk first with content authority preserved |
| `cms_ai_content_runs` | CMS | CMS | CMS AI automation | Yes | AI Assistant | Medium | move orchestration state only |
| `cms_users` | CMS / Users (shared lifecycle today) | CMS, Users | CMS, Users, auth paths | Yes | Users + CMS role bindings | High | high-risk identity separation phase |

## Cross-Module Direct Access Findings
| Accessing module | Target table | Access type | Allowed today | Should remain direct | Replacement contract |
|---|---|---|---|---|---|
| CMS | `rate_limits` | Read | Yes | No | `kernel.auth.*` and dedicated rate-limit capability |
| CMS | `workflow_definitions` | Read | Yes | No | `workflow.state.get@1` + transition contracts |
| CMS | `kernel_event_triggers` | Read | Yes | No | event/trigger service interface |

Baseline status:
- `php ikabud architecture:check` currently reports no cross-module table access violations.
- This matrix still tracks planned ownership moves for extraction phases.

## Ownership Decisions Log
| Decision ID | Domain | Chosen owner | Alternatives rejected | Rationale | Reviewer sign-off |
|---|---|---|---|---|---|
| OWN-001 | Content | CMS Core | Builder-owned content | Canonical lifecycle authority | Pending |
| OWN-002 | Media assets | Media module | keep under CMS | single media authority, transformations centralization | Pending |
| OWN-003 | Builder docs | Builder module | keep under CMS | decouple CMS core from builder format/runtime | Pending |
| OWN-004 | Themes/customizer | Theme module | keep under CMS | optional presentation profile support | Pending |
| OWN-005 | Navigation trees | Navigation module | keep under CMS | headless/minimal profile compatibility | Pending |
| OWN-006 | Identity credentials | Users module | keep under CMS users table | tenant-safe identity authority and role-binding split | Pending |

## Exit Criteria Evidence
- [x] Baseline canonical owner map exists for key CMS extraction domains.
- [x] Shared/kernel-access tables are identified with replacement contract direction.
- [ ] High-risk ownership moves need per-phase migration SQL and rollback drill evidence.
