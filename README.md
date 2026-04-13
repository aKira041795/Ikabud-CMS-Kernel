# Ikabud CMS Kernel

A multi-tenant application kernel and CMS platform powered by the DiSyL templating engine, with a modular architecture supporting CMS with visual page builder, daily ledger, workflow automation, contact forms, media management, and more.

## Key Features

- **Multi-tenant architecture** — Single codebase serving multiple tenants with isolated databases
- **Module system** — Manifest-driven module discovery with capability contracts and dependency validation
- **CMS with visual page builder** — React/Vite builder with server-side DiSyL rendering
- **Superadmin panel** — Per-tenant module enable/disable with toggle UI and DB connectivity checks
- **15+ modules** — CMS, daily ledger, workflow engine, contact form, media, search, ticketing, SMS, AI, and more
- **DiSyL templating** — Custom template engine with layouts, blocks, filters, and reactive client blocks
- **JWT authentication** — Secure token-based auth with role hierarchy (superadmin, admin, manager, viewer)
- **OPcache-aware** — Automatic code cache flushing on module enable/disable, theme installs, and deployments

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Apache with `mod_rewrite`
- Composer

## Quick Deploy to Bluehost

1. **Build archive** — Run `php create-bluehost-archive.php` to generate a deployment ZIP
2. **Create MySQL database** — cPanel → MySQL Databases → Create database + user, grant ALL privileges
3. **Upload ZIP** — cPanel File Manager → Upload ZIP to `public_html/` → Extract in place
4. **Run installer** — Navigate to `https://yourdomain.com/lock.php` → Enter DB credentials + admin account
5. **Secure** — Delete `public/lock.php` after verifying the app works

See [docs/kernel/installation.md](docs/kernel/installation.md) for the full guide.

## Repository Structure

| Path | Description |
|---|---|
| `bootstrap.php` | Application bootstrap — env loading, constants, global helpers |
| `kernel/` | Core kernel: App singleton, routing, auth, database, DiSyL, capabilities |
| `modules/` | All application modules (CMS, ledger, workflow, etc.) |
| `src/` | Shared kernel helpers (module manager, tenant resolver, etc.) |
| `public/` | Web root — index.php router, assets, lock.php installer |
| `config/` | Environment config (app, database) |
| `database/` | SQL migrations and seeds |
| `templates/` | DiSyL templates (admin + public) |
| `create-bluehost-archive.php` | CLI script to regenerate the deployment archive |
| `docs/` | Full project documentation |

## Architecture

See [docs/kernel/ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) for the full Kernel OS architecture — request lifecycle, module system, extension model, multi-tenancy, and authentication.

## Documentation Index

| Document | Topic |
|---|---|
| [ARCHITECTURE.md](docs/kernel/ARCHITECTURE.md) | Kernel OS system architecture |
| [installation.md](docs/kernel/installation.md) | Installation & deployment guide |
| [contributor-workflows.md](docs/kernel/contributor-workflows.md) | Local setup, testing, logs, and refactor guardrails |
| [api-reference.md](docs/kernel/api-reference.md) | REST API reference |
| [module-development-guide.md](docs/kernel/module-development-guide.md) | Guide for building new modules |
| [kernel-stable-contracts.md](docs/kernel/kernel-stable-contracts.md) | Stable kernel extension points versus refactorable internals |
| [cms-module.md](docs/cms/cms-module.md) | CMS module documentation |
| [wms-module.md](docs/wms/wms-module.md) | WMS module documentation |
| [cms-architecture.md](docs/cms/cms-architecture.md) | CMS module architecture |
| [page-builder-technical-spec.md](docs/page-builder/page-builder-technical-spec.md) | Visual page builder spec |
| [evaluations/ikabud-kernel-refactor-baseline-2026-04-10.md](docs/evaluations/ikabud-kernel-refactor-baseline-2026-04-10.md) | Baseline metrics and required regression gates for kernel refactors |
| [roadmap.md](docs/kernel/roadmap.md) | Project roadmap |

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE).
