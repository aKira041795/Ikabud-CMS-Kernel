# Ikabud — Kernel OS 6.0 (ecosystem)

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
- **31 governed DiSyL components** — Build screens by describing intent: `<ikb_entity_list source="orders.recent" view="compact" />`
- **Entity-view architecture** — Define fields, actions, and views per entity type. Kernel enforces permissions.
- **Visual builder contract composer** — React/Vite builder with governed component palette, source+view pickers, validation.
- **Export pipeline** — CSV, DOCX (PHPWord), PDF (DomPDF). One click turns any screen into a document.
- **AI governance** — Provider config, per-capability policy, redaction rules, review queue, audit trail, cost dashboard.
- **Observability** — 22 superadmin APIs for service health, circuit breakers, capability traces, entity-view debugging.
- **Report manager** — Templates, archive, scheduled reports, signature presets, module report packs.
- **Module certification** — 10-point checklist for all modules. CLI + API.
- **AI governance** — AI summaries and drafts with kill switch, model allowlist, cost ceilings, and human review requirements.
- **43 modules** — CMS, ecommerce, bakeshop, guidance, WMS, EHR, daily ledger, ticketing, SMS, AI orchestrator, and more.
- **Shared-hosting friendly** — Runs on a $5/month Bluehost plan. JWT auth, OPcache-aware, DiSyL linter.

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
| `kernel/` | Core: App singleton, routing, auth, database, DiSyL, capabilities, AI |
| `modules/` | 43 business modules (CMS, commerce, guidance, WMS, bakeshop, EHR, etc.) |
| `src/` | Shared kernel helpers (module manager, tenant resolver, certification) |
| `public/` | Web root — index.php router, assets, builder bundles, installer |
| `config/` | Environment config |
| `database/` | SQL migrations and seeds |
| `templates/` | DiSyL templates (admin + public) |
| `tests/` | 333 tests — engine, hardening, POC, integration |
| `docs/` | Full documentation |

---

## Version

| Component | Version |
|---|---|
| Kernel OS | `6.0.0` (ecosystem) |
| DiSyL | `4.0.0` |
| ComponentRegistry | `1.0.0` |
| EntityViewResolver | `1.0.0` |

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
| [roadmap.md](docs/kernel/roadmap.md) | Project roadmap |

---

## CLI Commands

```
php ikabud disyl:lint [path]          Lint templates (0 errors on 398 files)
php ikabud module:certify [module]    Validate module certification
php ikabud module:certify --all       Certify all modules
php ikabud tenant:migrate <id>        Run tenant migrations
php ikabud routes                     List all routes
php ikabud event:list                 List event listeners
```

---

## Tests

```
php tests/disyl_engine_test.php       264 tests — DiSyL engine comprehensive
php tests/disyl_hardening_coverage_test.php  44 tests — hardening coverage
php tests/poc_render_test.php         35 tests — component rendering POC
php tests/cms_integration_poc.php     25 tests — CMS end-to-end pipeline
php tests/kernel_load_test.php         Load test — 22ms for 100 iterations
```

---

## License

Open-core licensing model:

- **Community Edition** (DiSyL engine, contracts, community modules) — [MIT License](LICENSE-MIT)
- **Enterprise Edition** (kernel orchestration, multi-tenant, advanced modules) — [Ikabud Commercial License](LICENSE-COMMERCIAL)

See [LICENSING.md](LICENSING.md) for the complete component-to-license boundary.

GitHub may show `Unknown and 2 other licenses found` in the repository sidebar because the repo intentionally uses a mixed open-core layout with a repository-level notice in [LICENSE](LICENSE) plus separate [LICENSE-MIT](LICENSE-MIT) and [LICENSE-COMMERCIAL](LICENSE-COMMERCIAL) texts.
