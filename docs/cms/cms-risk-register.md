# CMS Modularization Risk Register

Status: Baseline v1 (Phase 0 in progress)
Owner: CMS Architecture Review Track
Date updated: 2026-08-04

## Purpose
Track architecture, migration, operational, security, and tenant risks per roadmap phase with actionable mitigation and release gates.

## Severity Scale
- P0: Release blocker / data-loss or tenant-leak risk
- P1: High risk, must be mitigated before phase exit
- P2: Important, track and mitigate in-phase
- P3: Advisory / optimization

## Risk Log
| ID | Phase | Severity | Category | Risk | Trigger | Impact | Mitigation | Validation evidence | Owner | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| R-001 | 0-2 | P0 | Architecture | Distributed monolith after split | module extraction without provider boundaries | hidden coupling and regressions | enforce provider boundary milestone before extraction | dependency + integration tests | CMS Architecture | Open |
| R-002 | 3+ | P1 | Migration | Revision/URL history loss | incorrect data move | broken public links/history | dual-write adapter + migration replay test | migration dry-run report | CMS + Data Migration | Open |
| R-003 | 5-8 | P0 | Tenant isolation | cross-tenant cache/settings leakage | shared keyspace | data exposure | tenant-keyed cache/event/settings policy | tenant isolation test suite | Tenant Safety WG | Open |
| R-004 | 4-7 | P1 | Performance | capability hop overhead | chatty sync dependencies | latency + timeout | batching/caching budget and SLO checks | benchmark diff per phase | Perf WG | Open |
| R-005 | 8 | P0 | Identity | attribution break during users split | bad id mapping | audit/legal risk | immutable author mapping migration plan | attribution integrity test | Identity WG | Open |
| R-006 | 0-1 | P1 | Contracts | capability dependency overreach in manifests | broad `depends` declarations | transitive module pull and unsafe rollout | architecture manifest policy checks + remediation | `php ikabud architecture:check` pass | Platform | Mitigated |
| R-007 | 0-1 | P1 | Auth metadata | missing `auth_owned` key metadata | incomplete manifests | tenant admin push/provision mismatch | enforce id/role columns in architecture checks | architecture check pass + manifest remediation | Platform | Mitigated |
| R-008 | 1 | P1 | Graceful degradation | core content path breaks when optional modules are disabled | extraction prep without disablement checks | false phase certification | add focused optional-module disablement tests for existing optional modules and preserve core content contracts | `tests/cms_optional_module_disablement_test.php` + clean logs | CMS Architecture | Mitigated (phase-1 baseline) |

## Phase Gate Checklist
| Phase | Must-have mitigation complete | Rollback validated | Workbench gate passed | Go/No-go |
|---|---|---|---|---|
| Phase 0 | Partial (baseline complete, full maps pending) | N/A | Pending | Go for contract-freeze prep |
| Phase 1 | Yes/No | Yes/No | Yes/No | ___ |
| Phase 2 | Yes/No | Yes/No | Yes/No | ___ |
| Phase 3 | Yes/No | Yes/No | Yes/No | ___ |

## Current Status Notes
- Architecture guardrails and manifest policy checks are active and passing.
- Known blocker class for auth_owned metadata and dependency overreach has been remediated in current manifests.
- Remaining top risks are migration-heavy phases: builder extraction, media authority split, and identity separation.

## Phase-Entry Preconditions (recommended)
- Phase 1 cannot start without completed capability consumer map sign-off.
- Phase 3 cannot start without provider boundary integration tests from Phase 2.
- Phase 8 cannot start without attribution-preservation migration rehearsal and rollback drill.

## Open Questions for Third-Party Review
1. Are risk severities correctly classified for extraction order?
2. Are rollback gates sufficient for media and identity phases?
3. Are tenant isolation controls complete for module-scale extraction?
