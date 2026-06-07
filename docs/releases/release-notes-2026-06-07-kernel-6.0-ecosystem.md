# Kernel OS 6.0 (ecosystem) — Release Notes

**Date:** June 7, 2026
**Previous:** 5.0.0 (nexus)
**Commit:** `6d71d4d`

---

## Summary

Kernel OS 6.0 is the **ecosystem release**. It makes the platform extendable by
other developers through improved scaffolding, example modules, developer SDK,
compatibility matrix, and comprehensive documentation.

The platform is now a **governed, polyglot, observable, report-ready, AI-safe,
extendable business operating system**.

---

## What's New in 6.0

### Module Scaffolding Improvements
- **`php ikabud make:service-module <name>`** — scaffolds a complete polyglot
  service module with Python service stub, module.json, and capability wire protocol
- **`php ikabud make:example`** — creates two example modules in `modules/_examples/`:
  - `hello-world` — minimal PHP module (routes, handlers, nav, events)
  - `random-facts` — Python polyglot service (health endpoint, capability dispatch)

### Developer SDK
- **Polyglot Service Developer Guide** — `docs/kernel/polyglot-service-guide.md`
  with Python and Node.js examples, wire protocol spec, testing patterns
- **Module Development Guide** — `docs/kernel/module-development-guide.md`
  comprehensive reference for PHP module development
- **Module Quickstart** — `docs/kernel/module-quickstart.md`
  30-minute tutorial to build a working module

### Compatibility Matrix
- Module certification system (`php ikabud module:certify`) validates 10-point
  checklist for all modules
- `GET /api/v1/cms/marketplace/catalog` — module catalog with certification scores
- Service-module type validated with endpoint checks (certification C10)

### Documentation Site (docs/)
| Doc | Purpose |
|---|---|
| `kernel-os-disyl-roadmap-status.md` | Complete phase-by-phase status (1–9) |
| `polyglot-service-guide.md` | Build services in any language |
| `module-development-guide.md` | Full PHP module reference |
| `module-quickstart.md` | 30-minute module tutorial |
| `cross-module-playbook.md` | Events, capabilities, triggers, hooks |
| `kernel-stable-contracts.md` | Stable kernel API contracts |
| `ARCHITECTURE.md` | System architecture overview |
| `installation.md` | Installation instructions |
| `production-deployment-guide.md` | Production deployment |
| `security-checklist.md` | Security hardening |
| `release-notes-2026-06-07-kernel-5.0-nexus.md` | 5.0 release notes |

### Example Modules
- `modules/_examples/hello-world/` — PHP module template
- `modules/_examples/random-facts/` — Python polyglot service
- `modules/weather-service/` — Production polyglot service (Python + wttr.in)

### Test Harness
- `tests/service_proxy_test.php` — 20/20 ServiceProxy unit tests
- `tests/polyglot_weather_test.php` — 17/17 polyglot E2E tests
- `tests/cms_weather_e2e.php` — 15/15 CMS entity-view integration tests
- `tests/cms_integration_poc.php` — 25/25 CMS integration POC
- `tests/poc_render_test.php` — 35/35 component rendering POC
- `tests/kernel_load_test.php` — 6 benchmarks, 22ms/100 iterations

---

## Platform Summary (Post-6.0)

| Layer | Status | Key Files |
|---|---|---|
| Kernel OS 6.0 | ✅ | `kernel/App.php` |
| DiSyL 4.0 | ✅ | `kernel/DiSyL/` |
| ComponentRegistry 1.0 (31 components) | ✅ | `kernel/DiSyL/ComponentRegistry.php` |
| EntityViewResolver 1.0 | ✅ | `kernel/EntityContext/EntityViewResolver.php` |
| CapabilityBus (circuit breakers, metrics) | ✅ | `kernel/Capabilities/CapabilityBus.php` |
| ServiceProxy (polyglot dispatch) | ✅ | `kernel/Capabilities/ServiceProxy.php` |
| KernelExport (CSV/DOCX/PDF) | ✅ | `kernel/Services/KernelExport.php` |
| ReportManager (templates, archive, schedule) | ✅ | `kernel/Services/ReportManager.php` |
| AIGovernance (provider, policy, audit) | ✅ | `kernel/DiSyL/AI/AIGovernance.php` |
| Superadmin observability APIs (22 endpoints) | ✅ | `src/http/superadmin-observability-handlers.php` |
| Builder contract composer (Phase 7) | ✅ | `modules/cms/builder-ui/` |
| Module certification (Phase 9) | ✅ | `src/helpers/module-manager.php` |

---

## Quality

| Metric | Value |
|---|---|
| Tests | 385 total (308 regression + 25 CMS + 52 polyglot) |
| Linter | 0 errors, 398 templates scanned |
| Load test | 22ms for 100 iterations across 6 paths |
| Superadmin APIs | 22 observability + governance endpoints |
| Example modules | 3 (hello-world, random-facts, weather-service) |
| Documentation | 30+ markdown files in docs/ |
| error.log | Clean |

---

## Upgrade Notes

- **Version bump:** `KERNEL_VERSION = '6.0.0'`, `KERNEL_CODENAME = 'ecosystem'`
- **New dependencies:** `dompdf/dompdf ^3.1` (PDF export)
- **New CLI commands:** `make:service-module`, `make:example`
- **New superadmin APIs:** 22 endpoints under `/api/v1/superadmin/`
- **Example modules:** Run `php ikabud make:example` to create them

---

## What's Next

The forward roadmap (5.1→6.0) is complete. Remaining items are deferred:

| Item | Priority |
|---|---|
| Marketplace UI | 🔴 Deferred — API exists, needs frontend |
| DiSyL language server | 🔴 Deferred — needs LSP implementation |
| VS Code extension | 🔴 Deferred — needs extension packaging |
| Report approval workflows | 🟡 Needs workflow runtime integration |
| Module install/update UI flow | 🔴 Deferred — needs frontend |
