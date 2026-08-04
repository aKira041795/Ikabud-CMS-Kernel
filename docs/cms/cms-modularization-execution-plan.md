# CMS Modularization Execution Plan (Phase 0 -> Phase 10)

Status: Approved execution blueprint template
Scope: End-to-end roadmap implementation path
Mode: Incremental, tenant-safe, contract-first

## Execution Principle
Do not treat this as a single release refactor.
Implement one phase at a time with hard gates and rollback before advancing.

## Global Release Gates (apply to every phase)
1. `php ikabud architecture:check` passes.
2. Manifest validators pass for changed modules.
3. Tenant-isolation regression checks pass.
4. Capability backward-compatibility checks pass.
5. No new criticals in app/error logs.
6. Rollback procedure tested in non-production environment.

## Phase 0 — Baseline and Inventory
### Code actions
- Complete and populate:
  - `docs/cms/cms-current-state-inventory.md`
  - `docs/cms/cms-table-ownership-matrix.md`
  - `docs/cms/cms-capability-consumer-map.md`
  - `docs/cms/cms-route-inventory.md`
  - `docs/cms/cms-risk-register.md`
### Gate
- Every CMS table/route/capability has ownership + consumer mapping.

## Phase 1 — CMS Core Contract Definition
### Code actions
- Freeze stable contract set in docs + tests:
  - `cms.content.*`, `cms.type.*`, `cms.taxonomy.*`, `cms.revision.*`, `cms.publication.*`, `cms.query.*`
- Add compatibility assertions for `@1` payloads.
### Gate
- Core content lifecycle works with optional features disabled.

## Phase 2 — Internal Provider Boundaries
### Code actions
- Introduce provider interfaces in CMS internals:
  - `MediaGateway`, `EditorProvider`, `PresentationProvider`, `ThemeProvider`, `NavigationProvider`, `SeoProvider`, `SearchIndexer`, `WorkflowProvider`, `IdentityResolver`
- Route all optional subsystem calls through providers.
### Gate
- Optional subsystem disablement tests succeed with provider fallback behavior.

## Phase 3 — Extract Low-Risk Modules (AI, SEO)
### Code actions
- Extract AI orchestration to `cms-ai-assistant`.
- Extract SEO ownership to `cms-seo`.
- Keep core content authority in CMS.
### Gate
- CMS publish/read paths remain stable when AI/SEO modules are disabled.

## Phase 4 — Editor Provider Architecture
### Code actions
- Implement editor contracts:
  - `editor.render@1`, `editor.normalize@1`, `editor.sanitize@1`, `editor.validate@1`, `editor.assets@1`
- Make TinyMCE an implementation, not a core assumption.
### Gate
- TinyMCE can be disabled; plain fallback editing still works.

## Phase 5 — Builder Extraction via ARK Boundary
### Code actions
- Move builder ownership to `cms-builder`.
- Keep CMS content canonical; store presentation references only.
- Validate builder output against ARK contracts.
### Gate
- CMS content works without builder; fallback rendering present.

## Phase 6 — Theme and Navigation Extraction
### Code actions
- Move theme ownership to `cms-theme`.
- Move menu/navigation ownership to `cms-navigation`.
- Keep route ownership and content IDs stable.
### Gate
- Headless profile works without theme/navigation modules.

## Phase 7 — Media Ownership Consolidation
### Code actions
- Move media authority to media module.
- Convert CMS media direct access to capability contracts.
- Preserve media references and usage history.
### Gate
- Content remains readable without upload availability.

## Phase 8 — Identity Separation from CMS
### Code actions
- Move credential authority to `users` module.
- Keep CMS editorial role bindings tenant-scoped.
- Preserve historical author attribution mapping.
### Gate
- CMS stores no passwords; attribution/history unaffected.

## Phase 9 — Search, Workflow, API Modules
### Code actions
- Implement/finish:
  - `cms-search-adapter`
  - `cms-workflow`
  - `cms-api`
- Ensure CMS remains publication authority while extensions orchestrate.
### Gate
- Headless profile passes without theme/builder/editor/navigation/SEO admin.

## Phase 10 — Product Profiles + Unified Admin
### Code actions
- Deliver installable profiles:
  - `cms-profile-minimal`
  - `cms-profile-standard`
  - `cms-profile-visual`
  - `cms-profile-headless`
- Add unified extension health + ownership visibility in admin.
### Gate
- Workbench certifies all profiles and legacy paths are removable.

## Profile-Level Acceptance (mandatory)
| Profile | Required modules | Must pass | Degraded allowed | Stop trigger |
|---|---|---|---|---|
| Minimal | CMS Core only | Content CRUD + public render + revision integrity | No builder/theme/navigation/AI/workflow admin features | Core content lifecycle broken without optional modules |
| Standard | CMS Core + theme + navigation + editor | Core + theme customizer + menu rendering + editor sanitize/normalize | AI/workflow advanced packs can be absent | Public route regressions or menu/theme ownership drift |
| Visual | Standard + builder + media + theme-studio | Standard + builder document read/write + ARK contract validation | AI/workflow can degrade with explicit UX messaging | Builder schema drift without rollback path |
| Headless | CMS Core + API delivery + publication/query contracts | API parity for content/query/publication + tenant-safe authz | Theme/navigation/builder UI unavailable | API contract regression or tenant leakage |

## Performance and Reliability Controls
| Control | Budget | Validation gate | Fallback expectation |
|---|---|---|---|
| Capability hop overhead | <= 20 ms p95 per optional call boundary | Phase benchmark diff against baseline | Core path continues when optional provider is unavailable |
| Public render latency | <= 15% p95 regression per phase | Route-level render benchmark suite | Default template + safe content render fallback |
| Optional module failure blast radius | Single concern only (no cross-domain outage) | Disablement simulation per module | Feature-unavailable response without core write/read loss |
| Cache safety | Tenant-scoped keys only | tenant isolation cache tests | Cache miss fallback to canonical data source |

## Recommendation Gate (Go / Revise / Stop)
| Decision | When to choose | Required evidence |
|---|---|---|
| Go | Phase gates all pass and rollback rehearsal succeeded | architecture check + focused compatibility tests + log-clean run + rollback transcript |
| Revise | Non-blocking gaps remain but no data-safety risk | mitigation tasks scheduled with owner/date and no P0 risk open |
| Stop | P0 risk open, rollback unproven, or tenant leakage observed | incident-class evidence and failed mandatory gate artifacts |

## Recommended Commit Strategy
- One phase per PR (preferred).
- Never combine identity/media extraction with unrelated phases.
- Require migration replay evidence for every ownership move.

## Stop Conditions
- Cross-tenant leakage detected.
- Public contract regression without adapter.
- Non-recoverable migration drift.
- No proven rollback.

## Akira Bootstrap Reference
- `docs/cms/cms-akira-capability-namespace-and-adapter-plan.md` defines transition namespace policy (`cms.*` + `akira.*`) and adapter direction for bootstrap and Phase B.
