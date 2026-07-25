# Ikabud Architecture — Two-Page Explainer

## Page 1: The Big Picture

```
┌──────────────────────────────────────────────────────────────────┐
│                        REQUEST LIFECYCLE                         │
│                                                                  │
│  Browser ──→ Apache ──→ index.php ──→ bootstrap.php ──→ App()   │
│                              │                                       │
│                    Tenant Resolution                                │
│                    Auth + CSRF Check                                │
│                    Route Match ──→ Module Handler                   │
│                                         │                           │
│                              Capability Bus                          │
│                              DiSyL Render                            │
│                              Response ──→ Browser                   │
└──────────────────────────────────────────────────────────────────┘
```

### What the kernel owns

| Layer | Responsibility | Evidence |
|---|---|---|
| Bootstrap | Env loading, error handler, autoloading, request ID | `bootstrap.php` |
| Routing | URL matching, module dispatch, 1,129 registered routes | `public/index.php` |
| Tenancy | Host→tenant resolution, isolated databases, fail-closed | `TenantResolver.php`, chaos tests |
| Auth | JWT (Bearer + cookie), CSRF enforcement, role checks | `kernel/JWT.php`, `kernel/Http/*` |
| Capabilities | Typed, versioned contracts between modules | `kernel/Capabilities/` |
| Database | Table-access enforcement via `KernelPDO` | `kernel/Contracts/ModuleDB.php` |
| Rendering | DiSyL templates, entity views, component registry | `kernel/DiSyL/` |
| Security | CSP headers, rate limiting, IP blocking | `src/helpers/security.php` |

### What modules own

| Layer | Responsibility |
|---|---|
| Business logic | Domain rules, calculations, validations |
| Data | Owned database tables (declared in `owns_tables`) |
| UI | DiSyL templates in `templates/modules/<module-id>/` |
| Capabilities | Typed contracts exposed to other modules |
| Settings | Module-specific configuration |

### The governing principle

> A module cannot read a table it has not declared. A module cannot call
> another module's code directly — only through capability contracts. The
> kernel enforces these boundaries at runtime.

---

## Page 2: Key Concepts

### Modules

A module is a self-contained business capability with:

```
modules/my-module/
├── module.json      # Manifest: identity, tables, capabilities, routes
├── routes.php       # URL → handler mapping
├── handlers.php     # Route handler functions
├── helpers.php      # Auto-loaded utilities
└── database/        # SQL migrations
```

Modules communicate through the **capability bus**, not through direct imports.

### Capabilities

A capability is a typed, versioned contract:

```
ecommerce.orders.tracking.sync@1
├── namespace: ecommerce
├── entity:    orders.tracking
├── action:    sync
└── version:   1 (breaking changes → @2)
```

Modules declare which capabilities they expose and which they depend on in
`module.json`. The kernel validates these at module-enable time.

### DiSyL

DiSyL (Declarative Ikabud Syntax Language) is the rendering contract:

- Server-rendered templates with compilation
- 32 governed components (`ikb_entity_list`, `ikb_stat_card`, etc.)
- Entity views — render any registered entity type with one tag
- Framework bridges (Alpine.js, HTMX, custom)
- Async Fibers scheduler for concurrent HTTP operations
- LSP extension for VS Code

### Tenants

Each tenant gets an isolated database. The control-plane database holds the
tenant registry and shared module catalog. Tenant-local databases hold
application data. If a tenant database is unreachable, the kernel fails
closed — it does not serve corrupted or cross-tenant data.

### Modules as the integration surface

```
Module A ──capability call──→ Module B
    │                            │
    └── kernel bus enforces ────┘
         versioning, auth, ACLs
```

Modules do not import each other's classes. They call capabilities by ID.
This means a module can be replaced, upgraded, or removed without affecting
other modules, as long as the capability contract is preserved.

### Where to go next

| If you want to… | Go here |
|---|---|
| Install Ikabud | [Installation Guide](../kernel/installation.md) |
| Build a module | [Module Quickstart](../kernel/module-quickstart.md) |
| Read the full architecture | [ARCHITECTURE.md](../kernel/ARCHITECTURE.md) |
| See stable contracts | [kernel-stable-contracts.md](../kernel/kernel-stable-contracts.md) |
| Evaluate for adoption | [Adopter Guide](../kernel/adopter-guide.md) |
