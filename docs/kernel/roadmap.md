---
description: Kernel OS — Public Execution Roadmap
---

# Ikabud Public Execution Roadmap

> **Last updated:** August 2026
> **Release:** Kernel OS 6.1.0 (intercoherence), DiSyL 4.7.0
> See [kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md) for the detailed implementation status.

This roadmap is organized into four lanes so contributors, adopters, and
evaluators can see what exists, what is being hardened, and what they can
participate in.

**Legend:** ✅ Shipped · 🟡 In progress · 🔴 Not started · 🧊 On hold

---

## Lane 1: Kernel — Security, tenancy, contracts, reliability

| Item | Status | Evidence | Owner | Contribution | Target |
|---|---|---|---|---|---|
| Capability contracts (phases 0–4) | ✅ | `kernel/Capabilities/`, `tests/*capability*` | Kernel maintainer | Community (MIT) | Shipped |
| Multi-provider dispatch (first/pipeline/fanout) | ✅ | `CapabilityBus.php`, `tests/*capability*` | Kernel maintainer | Community (MIT) | Shipped |
| Kernel core contracts (`kernel.auth.user@1`, `kernel.audit.record@1`, etc.) | ✅ | `kernel/Capabilities/` | Kernel maintainer | Community (MIT) | Shipped |
| Enable-time dependency validation | ✅ | `module-manager.php` | Kernel maintainer | Community (MIT) | Shipped |
| Tenant isolation (fail-closed DB) | ✅ | `tests/tenant_chaos_test.php`, `tests/tenant_db_fail_closed_test.php` | Kernel maintainer | Community (MIT) | Shipped |
| Tenant entry router (host→tenant resolution) | ✅ | `kernel/Http/TenantEntryRouter.php` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Module table ownership enforcement | ✅ | `kernel/Contracts/ModuleDB.php`, `KernelPDO` | Kernel maintainer | Community (MIT) | Shipped |
| CSRF enforcement on browser-mutating routes | ✅ | `kernel/Http/*` | Kernel maintainer | Community (MIT) | Shipped |
| JWT auth (Bearer + cookie dual path) | ✅ | `kernel/JWT.php` | Kernel maintainer | Community (MIT) | Shipped |
| Request ID generation + propagation | ✅ | `bootstrap.php`, structured logs | Kernel maintainer | Community (MIT) | Shipped |
| CLI tools (9 commands) | ✅ | `ikabud` commands | Kernel maintainer | Community (MIT) | Shipped |
| Workflow engine (event-triggered state machine) | ✅ | `kernel/WorkflowEngine.php`, `kernel/WorkflowRuntime.php` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Job queue | ✅ | `kernel/Services/*` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Observability (22 superadmin APIs) | ✅ | `public/index.php` superadmin routes | Kernel maintainer | Enterprise (CLA) | Shipped |
| Capability ACLs (caller allow/deny) | 🟡 | Design phase | Kernel maintainer | Community (MIT) | Q4 2026 |
| Circuit breaker for capability calls | 🟡 | Design phase | Kernel maintainer | Enterprise (CLA) | Q4 2026 |
| Schema validation for capability payloads | 🔴 | Not started | TBD | Community (MIT) | Q1 2027 |
| Product suite + extension model (C12/C13) | ✅ | `docs/architecture/product-suite-extension-adr.md`, `src/helpers/manifest-validation.php` (`validateModuleSuiteContractV1`) | Kernel maintainer | Enterprise (CLA) | Shipped |

---

## Lane 2: DiSyL and ARK — Rendering, components, builder integration

| Item | Status | Evidence | Owner | Contribution | Target |
|---|---|---|---|---|---|
| DiSyL grammar + parser (v4.7) | ✅ | `kernel/DiSyL/Grammar.php`, `kernel/DiSyL/v4/Parser.php` | Kernel maintainer | Community (MIT) | Shipped |
| Compiled template mode (default) | ✅ | `TemplateEngine.php` | Kernel maintainer | Community (MIT) | Shipped |
| Per-block parser error recovery | ✅ | `kernel/DiSyL/v4/Parser.php` | Kernel maintainer | Community (MIT) | Shipped |
| Component registry (32 governed components) | ✅ | `kernel/DiSyL/ComponentRegistry.php` | Kernel maintainer | Community (MIT) | Shipped |
| Entity view system (resolver + renderer) | ✅ | `kernel/EntityContext/`, `DefaultEntityRenderer.php` | Kernel maintainer | Enterprise (CLA) | Shipped |
| 16 registered entity views across 8 modules | ✅ | Module manifests, `en*_views` capabilities | Module owners | Mixed | Shipped |
| Async Fibers scheduler | ✅ | `kernel/DiSyL/Async/Scheduler.php` | Kernel maintainer | Community (MIT) | Shipped |
| LSP extension (VS Code) | ✅ | `extensions/disyl-lsp/` | Kernel maintainer | Community (MIT) | Shipped |
| Linter (583 templates, 0 errors) | ✅ | `php ikabud disyl:lint` | Kernel maintainer | Community (MIT) | Shipped |
| ARK — Architectural Rendering Kit | ✅ | `docs/themes/*`, 55 block definitions, 16 layout slots | Kernel maintainer | Enterprise (CLA) | Shipped |
| Theme customizer orchestrator v2 | ✅ | Theme integration | Kernel maintainer | Enterprise (CLA) | Shipped |
| Framework bridges (Alpine.js, HTMX, custom) | ✅ | `kernel/DiSyL/Component/*` bridge system | Kernel maintainer | Community (MIT) | Shipped |
| Progressive hydration | ✅ | `kernel/DiSyL/Hydration/` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Visual page builder (React/Vite) | ✅ | `modules/cms/builder-ui/` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Reactive template blocks | ✅ | `kernel/DiSyL/Reactive/` | Kernel maintainer | Enterprise (CLA) | Shipped |
| Inline editing support | 🟡 | RFC phase | Kernel maintainer | Community (MIT) | Q1 2027 |
| Component testing framework | 🔴 | Not started | TBD | Community (MIT) | Q2 2027 |

---

## Lane 3: Modules — CMS, Guidance, Ledger, WMS, Ecommerce

| Module | Status | Evidence | Owner | Contribution | Pilot-ready |
|---|---|---|---|---|---|
| **CMS** | ✅ Production | 24 docs, visual builder, 13 entity views, Moodle bridge | Kernel maintainer | Enterprise (CLA) | ✅ |
| **CMS Akira** | ✅ Suite (14 submodules) | `modules/cms-akira/` — decomposed CMS suite (core + seo/ai/editor/theme/navigation/workflow/search-adapter/media/builder + 4 profiles), dynamic `admin_contributions` sidebar; see `docs/architecture/product-suite-extension-adr.md` | Kernel maintainer | Enterprise (CLA) | ✅ |
| **Daily Ledger** | ✅ Production | Android app, offline sync, 5 user roles, variance tracking | Kernel maintainer | Enterprise (CLA) | ✅ |
| **Users** | ✅ Production | Accounts, roles, permissions | Kernel maintainer | Community (MIT) | ✅ |
| **Media** | ✅ Production | File uploads, metadata, library | Kernel maintainer | Community (MIT) | ✅ |
| **Contact Form** | ✅ Production | Lightweight forms, page-builder block | Kernel maintainer | Community (MIT) | ✅ |
| **Anti-spam** | ✅ Production | Honeypot, rate limiting, keyword blocklist | Kernel maintainer | Community (MIT) | ✅ |
| **Search** | ✅ Production | Cross-module search index | Kernel maintainer | Community (MIT) | ✅ |
| **GUI Settings** | ✅ Production | Frontend appearance customization | Kernel maintainer | Community (MIT) | ✅ |
| **Tinymce** | ✅ Production | Shared editor service | Kernel maintainer | Community (MIT) | ✅ |
| **Bakeshop** | 🟡 Controlled pilot | Bakery production tracking, supervisor tooling | Kernel maintainer | Enterprise (CLA) | ⚠️ |
| **Guidance** | 🟡 Controlled pilot | Case management, appointments, public booking, SMS | Kernel maintainer | Enterprise (CLA) | ⚠️ |
| **Ecommerce** | 🟡 Controlled pilot | Products, cart, checkout, orders, POS | Kernel maintainer | Enterprise (CLA) | ⚠️ |
| **WMS** | 🔴 Prototype | Warehouse operations | Kernel maintainer | Enterprise (CLA) | ❌ |
| **EHR** | 🔴 Prototype | Healthcare records | Kernel maintainer | Enterprise (CLA) | ❌ |
| **AI** | 🔴 Prototype | AI summaries, drafts, governance | Kernel maintainer | Enterprise (CLA) | ❌ |
| **Security** | 🔴 Prototype | File integrity, IP allowlist | Kernel maintainer | Enterprise (CLA) | ❌ |
| **Ticketing** | 🟡 Controlled pilot | Ticket tracking | Kernel maintainer | Enterprise (CLA) | ⚠️ |
| **Workflow** | 🟡 Controlled pilot | Workflow compatibility shell | Kernel maintainer | Enterprise (CLA) | ⚠️ |

### Module hardening priorities (H2 2026)

| Priority | Module | Focus |
|---|---|---|
| 1 | Ecommerce | Storefront media, product-card images, cart currency, checkout reliability |
| 2 | Guidance | Appointment stability, SMS notifications, public booking hardening |
| 3 | Bakeshop | Supervisor role tools, production voiding, report archive |
| 4 | WMS | Inventory authority, batch tracking, picklist flow |
| 5 | EHR | System design implementation, clinical safety UX |

---

## Lane 4: Ecosystem — Documentation, contributors, training, partners

| Item | Status | Evidence | Owner | Contribution | Target |
|---|---|---|---|---|---|
| Root README with audience routing | ✅ | `README.md` | Kernel maintainer | Community (MIT) | Shipped |
| Architecture documentation | ✅ | `docs/kernel/ARCHITECTURE.md` | Kernel maintainer | Community (MIT) | Shipped |
| Stable contracts documented | ✅ | `docs/kernel/kernel-stable-contracts.md` | Kernel maintainer | Community (MIT) | Shipped |
| Module development guide | ✅ | `docs/kernel/module-development-guide.md` | Kernel maintainer | Community (MIT) | Shipped |
| Module quickstart tutorial | ✅ | `docs/kernel/module-quickstart.md` | Kernel maintainer | Community (MIT) | Shipped |
| Installation guide | ✅ | `docs/kernel/installation.md` | Kernel maintainer | Community (MIT) | Shipped |
| API reference | ✅ | `docs/kernel/api-reference.md` | Kernel maintainer | Community (MIT) | Shipped |
| Database profiles documentation | ✅ | `docs/kernel/database-profiles.md` | Kernel maintainer | Community (MIT) | Shipped |
| Polyglot service guide | ✅ | `docs/kernel/polyglot-service-guide.md` | Kernel maintainer | Community (MIT) | Shipped |
| Project philosophy | ✅ | `docs/PHILOSOPHY.md` | Kernel maintainer | Community (MIT) | Shipped |
| Adopter guide | ✅ | `docs/kernel/adopter-guide.md` | Kernel maintainer | Community (MIT) | Shipped |
| Terminology policy | ✅ | `docs/TERMINOLOGY.md` | Kernel maintainer | Community (MIT) | Shipped |
| CONTRIBUTING.md | ✅ | Root-level | Kernel maintainer | Community (MIT) | Shipped |
| CODE_OF_CONDUCT.md | ✅ | Root-level | Kernel maintainer | Community (MIT) | Shipped |
| SECURITY.md | ✅ | Root-level | Kernel maintainer | Community (MIT) | Shipped |
| CLI reference | ✅ | README + `php ikabud` help | Kernel maintainer | Community (MIT) | Shipped |
| License boundary (`LICENSING.md`) | ✅ | Root-level | Kernel maintainer | N/A | Shipped |
| Public demo walkthrough | ✅ | `docs/demo/` | Kernel maintainer | Community (MIT) | Shipped |
| Case studies | ✅ | `docs/case-studies/` | Kernel maintainer | Community (MIT) | Shipped |
| Test suite (291 files) | ✅ | `tests/` | Kernel maintainer | Community (MIT) | Shipped |
| CI pipeline (3 seeded tenants) | ✅ | `.github/workflows/ci.yml` | Kernel maintainer | Community (MIT) | Shipped |
| Bluehost deployment archive | ✅ | `create-bluehost-archive.php` | Kernel maintainer | Community (MIT) | Shipped |
| Formal CLA document | 🔴 | Not drafted | Kernel maintainer | N/A | Q4 2026 |
| Newcomer issues / good-first-issue labels | 🔴 | Not created | Kernel maintainer | N/A | Q4 2026 |
| Contributor tutorials (video/written) | 🔴 | Not started | TBD | Community (MIT) | Q1 2027 |
| Training materials / bootcamp | 🔴 | Not started | TBD | Community (MIT) | Q2 2027 |
| Partner / implementation partner program | 🔴 | Not started | Kernel maintainer | N/A | 2027 |

---

## Previous content preserved

This roadmap supersedes the earlier capability-contracts-focused roadmap. All
completed phases (0–4 capability contracts, hardening phases 1–4) are now
integrated into the four lanes above. For the original detailed phase
descriptions, see [kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md).

### Historical: Capability contracts phases (0–4)

All shipped. See the original detailed descriptions in
[kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md#phase-0--spec-lock-).

### Historical: Hardening phases (1–4)

All shipped. See the original detailed descriptions in
[kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md#hardening-roadmap--app-reliability--safety).
