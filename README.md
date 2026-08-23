# Ikabud — Kernel OS 6.2 / DiSyL 4.8 (proof program)

**A governed, polyglot, observable, report-ready, AI-safe, extendable business operating system.**

Kernel OS governs. Modules provide capabilities. DiSyL expresses interface intent.
Themes shape presentation. AI assists through policy. Any language can participate.

---

## What is Kernel OS?

Kernel OS is not a framework, not a CMS, and not a plugin system. It is an
**operating layer for business modules** — a platform where modules can safely
expose capabilities, views, reports, workflows, and AI-assisted features through
one governed runtime. **PHP is the kernel host. Capabilities can live anywhere.**

One kernel. Many business modules. One interface language. One policy boundary.
Many languages. Many outputs.

---

## Choose Your Path

| I want to… | Start here |
|---|---|
| Understand Ikabud | [README below](#what-is-kernel-os) · [docs/PHILOSOPHY.md](docs/PHILOSOPHY.md) |
| Try it locally | [Quick Deploy](#quick-deploy-to-bluehost) · [docs/kernel/installation.md](docs/kernel/installation.md) |
| Deploy a pilot | [ADOPTER-GUIDE.md](docs/kernel/adopter-guide.md) |
| Build a module | [Module Quickstart (30 min)](docs/kernel/module-quickstart.md) · [Full Guide](docs/kernel/module-development-guide.md) |
| Contribute | [CONTRIBUTING.md](CONTRIBUTING.md) · [docs/kernel/contributor-workflows.md](docs/kernel/contributor-workflows.md) |
| Evaluate for an institution | [docs/kernel/installation.md](docs/kernel/installation.md) · [docs/kernel/ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) |

### Governance

- [CONTRIBUTING.md](CONTRIBUTING.md) — How to contribute, review expectations, CLA process
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — Community behavior expectations
- [SECURITY.md](SECURITY.md) — Vulnerability reporting and disclosure policy
- [docs/PHILOSOPHY.md](docs/PHILOSOPHY.md) — Project principles and design rationale
- [docs/TERMINOLOGY.md](docs/TERMINOLOGY.md) — Canonical terminology and retired terms

---

## Key Features

- **Multi-tenant by default** — Isolated databases per organization.
- **Polyglot capability dispatch** — Services in Python, Node, Go, Rust, or any language participate through ServiceProxy, with delivered **ServiceProxy v2** signed-request enforcement for v2-bound capabilities.
- **Module isolation enforced** — `owns_tables`/`reads_tables` contracts prevent cross-module data access.
- **Capability bus** — Typed, versioned, circuit-breaker-protected contracts between modules.
- **32 governed DiSyL components** — Build screens by describing intent: `<ikb_entity_list source="orders.recent" view="compact" />`
- **Entity-view architecture** — Define fields, actions, views, and optional `source_schema` ownership contracts per entity type. Kernel enforces permissions via capability bus.
- **Visual builder contract composer** — React/Vite builder with governed component palette, source+view pickers, validation.
- **Mobile backend platform** — JWT auth with Bearer/cookie dual path, CORS for API consumers, rate limiting (DB/APCu), push notification queue (FCM), offline sync engine (entity revisions, cursor-based pull, tombstones, conflict detection), idempotency keys for safe retries.
- **Standardized API contract** — Consistent `{ok, data, error, meta, request_id}` response envelope, rule-based input validation, offset + cursor pagination, OpenAPI 3.0 spec generation from route metadata.
- **Export pipeline** — CSV, DOCX (PHPWord), PDF (DomPDF). One click turns any screen into a document.
- **AI governance** — Provider config, per-capability policy, redaction rules, review queue, audit trail, cost dashboard.
- **Observability** — 22 superadmin APIs for service health, circuit breakers, capability traces, entity-view debugging.
- **Workflow engine** — Multi-step state machine with event-driven triggers, idempotent step execution, subscription management, and delivered retention/provenance controls.
- **Durable transactional events** — Producer-owned outbox + consumer inbox with MySQL 5.7-safe leasing, retry/backoff, dead-letter, and crash recovery.
- **Capability authorization registry** — Persisted versioned default-deny policy registry with audit logging and fail-closed behavior.
- **DiSyL conformance lane** — Canonical inventory + jurisdiction checker + CI proof lane for interpreted/compiled parity, LSP surface, and resource limits.
- **Guardrail gates** — Aggregate perf gate, module certification gate, and browser-journey seed for reproducible verification.
- **Report manager** — Templates, archive, scheduled reports, signature presets, module report packs.
- **Module certification** — 10-point checklist for all modules. CLI + API.
- **AI governance** — AI summaries and drafts with kill switch, model allowlist, cost ceilings, and human review requirements.
- **68 module manifests across 37 module folders** — CMS, ecommerce, bakeshop, guidance, WMS, EHR, daily ledger, ticketing, SMS, AI orchestrator, attendance-wage, and more — including the **CMS Akira suite** (`modules/cms-akira/`): 14 submodules (core + providers + profiles) governed as one product family. See [modules/cms-akira/README.md](modules/cms-akira/README.md).
- **Product-suite extension architecture** — Manifest-declared suites, product cores, extensions, adapters, profiles, and dynamic admin contributions, accepted 2026-08-04 in [product-suite-extension-adr.md](docs/architecture/product-suite-extension-adr.md). Scaffold a suite submodule with `php ikabud make:module <name> --suite=cms-akira`.
- **ARK — Architectural Rendering Kit** — The visual operating specification of Ikabud. Governs themes, renderers, builders, entity views, design tokens, accessibility, and safety policy. 55 block definitions across 10 domains, 16 layout slots, 27 component variants.
- **Bluehost shared-hosting compatible** — Runs on MySQL 5.7 via the Compatibility database profile. JWT auth, OPcache-aware, DiSyL linter. See [docs/kernel/database-profiles.md](docs/kernel/database-profiles.md).

---

## Quick Deploy to Bluehost

1. **Build archive** — `php create-bluehost-archive.php`
2. **Create MySQL database** — cPanel → MySQL Databases
3. **Upload ZIP** — cPanel File Manager → Extract in `public_html/`
4. **Run installer** — Navigate to `https://yourdomain.com/lock.php`
5. **Secure** — Delete `public/lock.php` after install

See [docs/kernel/installation.md](docs/kernel/installation.md) for the full guide.

---

## Repository Structure

| Path | Description |
|---|---|
| `bootstrap.php` | Application bootstrap — env, constants, global helpers |
| `kernel/` | Core: App singleton, routing, auth, database, DiSyL engine, capabilities, AI, workflows, 14 services |
| `modules/` | 68 module manifests across 37 module folders (CMS, commerce, guidance, WMS, bakeshop, EHR, etc.), incl. the CMS Akira suite (`cms-akira/`, 14 submodules) |
| `src/` | Shared kernel helpers (module manager, tenant resolver, certification) |
| `public/` | Web root — index.php router, assets, builder bundles, installer |
| `config/` | Environment config, entity presets, vhosts |
| `database/` | 186 SQL migrations across kernel + modules |
| `templates/` | 583 DiSyL templates (admin + public) |
| `tests/` | 291 test files, ~4,290 assertions — engine, hardening, integration |
| `docs/` | Full documentation — kernel, modules, themes, architecture |
| `extensions/` | DiSyL VS Code LSP extension |

---

## Version

| Component | Version |
|---|---|
| Kernel OS | `6.2` (proof-program batch) |
| DiSyL | `4.8.0` |
| ComponentRegistry | `1.0.0` |
| EntityViewResolver | `1.0.0` |
| Theme Customizer Orchestrator | `2.0.0` |

---

## Documentation

### Audience guides

| Document | For |
|---|---|
| [PHILOSOPHY.md](docs/PHILOSOPHY.md) | Understanding Ikabud's principles, design decisions, and scope limits |
| [TERMINOLOGY.md](docs/TERMINOLOGY.md) | Canonical terminology and retired terms |

### Technical reference

| Document | Topic |
|---|---|
| [ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) | Kernel OS system architecture |
| [kernel-os-disyl-roadmap-status.md](docs/kernel/kernel-os-disyl-roadmap-status.md) | Canonical 6.2 / 4.8 proof-program status with preserved 6.1 history |
| [installation.md](docs/kernel/installation.md) | Installation & deployment guide |
| [contributor-workflows.md](docs/kernel/contributor-workflows.md) | Local setup, testing, logs, refactor guardrails |
| [api-reference.md](docs/kernel/api-reference.md) | REST API reference |
| [module-development-guide.md](docs/kernel/module-development-guide.md) | Guide for building new modules |
| [kernel-stable-contracts.md](docs/kernel/kernel-stable-contracts.md) | Stable kernel extension points |
| [disyl-overview.md](docs/kernel/disyl-overview.md) | DiSyL overview — what it is and why it matters |
| [cms-module.md](docs/cms/cms-module.md) | CMS module |
| [wms-module.md](docs/wms/wms-module.md) | WMS module |
| [bakeshop-module.md](docs/bakeshop/bakeshop-module.md) | Bakeshop module |
| [guidance-module.md](docs/guidance/guidance-module.md) | Guidance module |
| [page-builder-technical-spec.md](docs/page-builder/page-builder-technical-spec.md) | Visual page builder spec |
| [cms-performance-and-scalability.md](docs/cms/cms-performance-and-scalability.md) | CMS performance benchmark + scaling analysis |
| [ark-authority-layer-plan.md](docs/themes/ark-authority-layer-plan.md) | **ARK** — Architectural Rendering Kit: the visual operating specification of Ikabud |
| [roadmap.md](docs/kernel/roadmap.md) | Project roadmap |
| [mobile-offline-db-readiness.md](docs/reviews/mobile-offline-db-readiness-review-2026-07-10.md) | Mobile-app backend capability review |
| [mobile-api-platform-plan.md](docs/reviews/mobile-api-platform-implementation-plan-2026-07-10.md) | Mobile API implementation plan |

---

## CLI Commands

```
php ikabud disyl:lint [path]          Lint DiSyL templates (583 files)
php ikabud make:module <name>          Scaffold a new module (--suite=cms-akira for suite submodules)
php ikabud module:certify [module]    Validate module certification
php ikabud module:certify --all       Certify all 68 module manifests (C12/C13 suite certification)
php ikabud tenant:migrate <id>        Run tenant database migrations
php ikabud migrate                     Run all pending kernel + module migrations
php ikabud routes                      List all 1,129 registered routes
php ikabud event:list                  List event listeners
php ikabud trigger:trace [event]       Trace trigger dispatch chain
php ikabud workflow:list               List workflow definitions
```

## Target Environment

- **PHP 8.1+** with `ext-pdo`, `ext-json`, `ext-mbstring`
- **Database**: See [Database Compatibility Profiles](docs/kernel/database-profiles.md) — MySQL 5.7 / MariaDB 10.1+ (Compatibility) or MySQL 8.0+ / MariaDB 10.11+ (Enterprise)
  - All migrations use `ENGINE=InnoDB` with `utf8mb4_unicode_ci`
- **APCu** recommended for DiSyL compiled template cache
- **DiSyL VS Code Extension** — syntax highlighting, diagnostics, autocomplete: [`extensions/disyl-lsp/`](extensions/disyl-lsp/)

---

## Tests

291 test files with ~4,290 assertions across engine, hardening, integration, and module-level tests.

### Critical workflows

```
php tools/disyl-conformance-check.php
php tools/perf-gate.php
php tools/module-certification-gate.php
php database/seeds/browser_environment.php
npx playwright test tests/browser/builder-journey.spec.js
npx playwright test tests/browser/report-approval-journey.spec.js
npx playwright test tests/browser/async-rendering-journey.spec.js
```

### Additional test entry points

```
php tests/disyl_engine_test.php       DiSyL engine comprehensive
php tests/kernel_load_test.php --json Load baseline JSON for perf gate
vendor/bin/phpunit                   PHPUnit test suite (where configured)
php ikabud test                       Run module-specific test suites
```

---

## License

Open-core licensing model:

- **Community Edition** (DiSyL engine, contracts, community modules) — [MIT License](LICENSE-MIT)
- **Enterprise Edition** (kernel orchestration, multi-tenant, advanced modules) — [Ikabud Commercial License](LICENSE-COMMERCIAL)

See [LICENSING.md](LICENSING.md) for the complete component-to-license boundary.

GitHub may show `Unknown and 2 other licenses found` in the repository sidebar because the repo intentionally uses a mixed open-core layout with a repository-level notice in [LICENSE](LICENSE) plus separate [LICENSE-MIT](LICENSE-MIT) and [LICENSE-COMMERCIAL](LICENSE-COMMERCIAL) texts.
