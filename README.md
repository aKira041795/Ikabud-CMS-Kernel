# Ikabud — Kernel OS 6.1 (ecosystem)

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

## Key Features

- **Multi-tenant by default** — Isolated databases per organization.
- **Polyglot capability dispatch** — Services in Python, Node, Go, Rust, or any language participate through ServiceProxy.
- **Module isolation enforced** — `owns_tables`/`reads_tables` contracts prevent cross-module data access.
- **Capability bus** — Typed, versioned, circuit-breaker-protected contracts between modules.
- **21 governed DiSyL components** — Build screens by describing intent: `<ikb_entity_list source="orders.recent" view="compact" />`
- **Entity-view architecture** — Define fields, actions, and views per entity type. 16 registered entity views across modules. Kernel enforces permissions via capability bus.
- **Visual builder contract composer** — React/Vite builder with governed component palette, source+view pickers, validation.
- **Export pipeline** — CSV, DOCX (PHPWord), PDF (DomPDF). One click turns any screen into a document.
- **AI governance** — Provider config, per-capability policy, redaction rules, review queue, audit trail, cost dashboard.
- **Observability** — 22 superadmin APIs for service health, circuit breakers, capability traces, entity-view debugging.
- **Workflow engine** — Multi-step state machine with event-driven triggers, idempotent step execution, and subscription management.
- **Report manager** — Templates, archive, scheduled reports, signature presets, module report packs.
- **Module certification** — 10-point checklist for all modules. CLI + API.
- **AI governance** — AI summaries and drafts with kill switch, model allowlist, cost ceilings, and human review requirements.
- **30 modules** — CMS, ecommerce, bakeshop, guidance, WMS, EHR, daily ledger, ticketing, SMS, AI orchestrator, attendance-wage, and more.
- **ARK — Architectural Rendering Kit** — The visual operating specification of Ikabud. Governs themes, renderers, builders, entity views, design tokens, accessibility, and safety policy. 55 block definitions across 10 domains, 16 layout slots, 27 component variants.
- **Bluehost shared-hosting compatible** — Runs on MySQL 5.7. JWT auth, OPcache-aware, DiSyL linter, no window functions or CTEs.

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
| `modules/` | 30 business modules (CMS, commerce, guidance, WMS, bakeshop, EHR, etc.) |
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
| Kernel OS | `6.1.0` (ecosystem) |
| DiSyL | `4.7.0` |
| ComponentRegistry | `1.0.0` |
| EntityViewResolver | `1.0.0` |
| Theme Customizer Orchestrator | `2.0.0` |

---

## Documentation

| Document | Topic |
|---|---|
| [ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) | Kernel OS system architecture |
| [kernel-os-disyl-roadmap-status.md](docs/kernel/kernel-os-disyl-roadmap-status.md) | Phase-by-phase implementation status (updated June 2026) |
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

---

## CLI Commands

```
php ikabud disyl:lint [path]          Lint DiSyL templates (583 files)
php ikabud module:certify [module]    Validate module certification
php ikabud module:certify --all       Certify all 30 modules
php ikabud tenant:migrate <id>        Run tenant database migrations
php ikabud migrate                     Run all pending kernel + module migrations
php ikabud routes                      List all 1,129 registered routes
php ikabud event:list                  List event listeners
php ikabud trigger:trace [event]       Trace trigger dispatch chain
php ikabud workflow:list               List workflow definitions
```

## Target Environment

- **PHP 8.1+** with `ext-pdo`, `ext-json`, `ext-mbstring`
- **MySQL 5.7** / MariaDB 10.1+ (Bluehost shared hosting compatible)
  - No window functions (`OVER()`), no CTEs — see [`.github/skills/bluehost-mysql-compatibility.md`](.github/skills/bluehost-mysql-compatibility.md)
  - All migrations use `ENGINE=InnoDB` with `utf8mb4_unicode_ci`
- **APCu** recommended for DiSyL compiled template cache
- **DiSyL VS Code Extension** — syntax highlighting, diagnostics, autocomplete: [`extensions/disyl-lsp/`](extensions/disyl-lsp/)

---

## Tests

291 test files with ~4,290 assertions across engine, hardening, integration, and module-level tests.

```
php tests/disyl_engine_test.php       DiSyL engine comprehensive
php tests/kernel_load_test.php        Load test — 22ms for 100 iterations
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
