# Ikabud Open-Core Licensing

Ikabud uses an **open-core licensing model**. Different components of this repository are licensed under different terms.

---

## Summary

| Component | License | File |
|-----------|---------|------|
| Community Edition (core engine, contracts, community modules) | MIT | [LICENSE](LICENSE) |
| Enterprise Edition (orchestration, multi-tenant, advanced modules) | Ikabud Commercial License | [LICENSE-COMMERCIAL](LICENSE-COMMERCIAL) |

---

## Community Edition — MIT License

The following components are licensed under the [MIT License](LICENSE) and may be freely used, modified, and redistributed:

### Kernel — Open Components

| Path | Description |
|------|-------------|
| `kernel/DiSyL/TemplateEngine.php` | DiSyL interpreted template engine |
| `kernel/DiSyL/Grammar.php` | DiSyL grammar definitions |
| `kernel/DiSyL/ComponentRegistry.php` | Built-in component registry |
| `kernel/DiSyL/Component/` | Component implementations |
| `kernel/DiSyL/Exceptions/` | Engine exception classes |
| `kernel/Contracts/` | Module SPI interfaces (the public contract surface) |
| `kernel/Hooks.php` | Kernel→module hook/filter system |
| `kernel/EventBus.php` | Module→module event bus |
| `kernel/Cache.php` | File-based caching layer |
| `kernel/JWT.php` | JWT token handling |
| `kernel/Database/` | Database abstraction layer |

### Community Modules

| Module | Description |
|--------|-------------|
| `modules/anti-spam/` | Honeypot, rate limiting, keyword blocklist |
| `modules/contact-form/` | Lightweight contact form + page-builder block |
| `modules/daily-ledger/` | Daily sales report encoding |
| `modules/example-notes/` | Reference module for developers |
| `modules/gui-settings/` | Frontend appearance customization |
| `modules/media/` | Media library (uploads, metadata) |
| `modules/search/` | Cross-module search index |
| `modules/tinymce/` | Shared editor service |
| `modules/users/` | User accounts and role management |
| `modules/wordpress-importer/` | WordPress WXR import |

### Other Open Components

| Path | Description |
|------|-------------|
| `templates/` | Base DiSyL templates |
| `src/helpers/` | Shared kernel helpers (routing, request) |
| `config/` | Configuration structure |
| `database/migrations/` | Database schema migrations |
| `database/seeds/` | Seed data |
| `docs/` | Documentation |
| `tests/` | Test suite |
| `scripts/` | CLI scripts and tooling |

---

## Enterprise Edition — Ikabud Commercial License

The following components are licensed under the [Ikabud Commercial License](LICENSE-COMMERCIAL) and require a commercial license for production use:

### Kernel — Proprietary Components

| Path | Description |
|------|-------------|
| `kernel/App.php` | Application orchestrator and kernel singleton |
| `kernel/TenantResolver.php` | Multi-tenant context resolution |
| `kernel/Crypto.php` | Encryption services |
| `kernel/IntegrationBridge.php` | Cross-module integration bridge |
| `kernel/WorkflowRuntime.php` | Workflow execution engine |
| `kernel/TriggerService.php` | Event trigger execution |
| `kernel/EventTriggers.php` | Trigger definitions |
| `kernel/Capabilities/` | Capability bus and registry system |
| `kernel/ControlPlane/` | Control plane integration catalog |
| `kernel/DiSyL/Compiler/` | Compiled template pipeline (AST → PHP) |
| `kernel/DiSyL/v4/` | v4 parser, AST, render context |
| `kernel/DiSyL/CMS/` | CMS adapter bridge for templates |
| `kernel/DiSyL/Hydration/` | Client-side hydration system |
| `kernel/DiSyL/Reactive/` | Reactive template blocks |
| `kernel/EntityAuthority/` | Entity authority registry |
| `kernel/EntityContext/` | Entity context profiles |
| `kernel/Http/` | Security headers, tenant entry router |
| `kernel/Services/` | Tenant provisioner, API key auth, locale resolver |

### Enterprise Modules

| Module | Description |
|--------|-------------|
| `modules/ai/` | AI automation and suggestions |
| `modules/cms/` | Full CMS with visual page builder |
| `modules/content-ingestion/` | Event-driven content pipeline |
| `modules/ecommerce/` | Products, cart, checkout, orders, POS, inventory |
| `modules/guidance/` | School guidance case management (freemium) |
| `modules/guidance-sms/` | Guidance SMS notifications (paid) |
| `modules/security/` | File integrity, audit logging, IP allowlist |
| `modules/sms/` | Multi-provider SMS notifications |
| `modules/ticketing/` | Ticket/issue tracking system |
| `modules/wms/` | Warehouse management system |
| `modules/workflow/` | Workflow engine compatibility shell |

### Infrastructure

| Path | Description |
|------|-------------|
| `public/index.php` | Production request router and tenant dispatch |
| `src/helpers/module-manager.php` | Module discovery, enable/disable, capability checks |
| `bootstrap.php` | Application bootstrap and global infrastructure |

---

## What This Means

### For developers and contributors

- You can freely use, fork, and build on all **Community Edition** components
- Community modules can be extended, modified, and redistributed under MIT
- The `kernel/Contracts/` interfaces define the stable module development API

### For production deployments

- Self-hosted production use of **Enterprise Edition** components requires a commercial license
- Contact noah2.omamalin@gmail.com for commercial licensing terms
- Evaluation, development, and testing use of all components is permitted

### For module developers

- Build modules against the MIT-licensed `kernel/Contracts/` interfaces
- Your modules are yours — Ikabud's license does not infect module code that only uses the public contract surface
- Modules that embed or modify Enterprise components must respect the commercial license

---

## Transition Note

This repository was previously licensed under GPL-3.0-only. As of April 2026, the project adopts an open-core model. All prior contributions remain available under the terms they were contributed under. New contributions to Enterprise Edition components are accepted under the Ikabud Commercial License via the standard CLA process.
