# App Decomposition Roadmap

## Problem

`Ikabud\Kernel\App` has accumulated 20+ responsibilities over six major kernel versions. It serves as the composition root, service locator, and god object simultaneously. While this pattern is common in early-stage PHP frameworks, it creates several risks:

- **Testability**: Mocking `App` is impractical — tests couple to the full kernel lifecycle
- **Reasoning**: Reading a method call `app()->something()` gives no hint about what subsystem is involved
- **Coupling**: Every module depends on the full `App` surface, making module boundaries porous
- **Parallel work**: Multiple developers cannot work on different subsystems without merge conflicts in `App.php`

## Constraint

**Do not rewrite `App`.** The assessment explicitly states this is a progressive reduction of authority, not a replacement. Every subsystem extraction must maintain backward compatibility with existing `app()->*` calls during the transition period.

## Current State (v6.1.0)

`App` owns these responsibilities:

| # | Responsibility | Method / property | Risk level |
|---|---------------|-------------------|------------|
| 1 | Lifecycle bootstrap | `__construct()`, `boot()` | Core — do not extract |
| 2 | Request/response | `handleRequest()`, `response()` | Low — already isolated in Http/ |
| 3 | Database (tenant) | `db()`, `dbForTenant()` | Medium — widely called |
| 4 | Database (control plane) | `controlDb()` | Medium |
| 5 | Auth / session | `user()`, `login()`, `logout()` | Medium |
| 6 | Config | `config()` | Low |
| 7 | Logging | `log()`, `write_log()` | Low |
| 8 | Rendering (DiSyL) | `render()`, `templateEngine()` | Medium |
| 9 | Entity views | `entityViewResolver()`, `entityRenderer()` | Medium |
| 10 | Entity authority | `entityAuthority()` | Medium |
| 11 | Capability bus | `capabilities()`, `cap()` | Medium |
| 12 | Event bus | `events()`, `emit()` | Low — EventBus is already standalone |
| 13 | Hooks | `hooks()` | Low |
| 14 | Workflow engine | `workflowEngine()`, `workflowRuntime()` | Low |
| 15 | Triggers | `triggers()` | Low |
| 16 | Integration bridge | `integrationBridge()` | Low |
| 17 | Cache | `cache()` | Low |
| 18 | Crypto / JWT | `crypto()`, `jwt()` | Low |
| 19 | Module manager | `moduleManager()`, `modules()` | Medium |
| 20 | Tenant resolver | `tenant()`, `tenantId()` | Medium |
| 21 | Migration runner | `migrationRunner()` | Low |
| 22 | Boot profiles | `isCli()`, `isWeb()` | Core — keep in App |

## Target State

`App` becomes a **composition root only** — it wires providers together at boot time but does not serve as a runtime service locator for domain logic.

```php
// Target: narrow, typed injection
class ProjectService {
    public function __construct(
        private TenantDB $db,
        private CapabilityBus $capabilities,
        private EventBus $events,
    ) {}
}

// Instead of today:
class ProjectService {
    public function update(int $id, array $data): ServiceResult {
        $db = app()->db(); // ← service locator anti-pattern
        ...
    }
}
```

## Migration Steps

### Step 1: Extract contracts (v6.2)

Define narrow interfaces for each subsystem. These already exist for some (e.g., `ModuleDB` contract) but not all.

```php
namespace Ikabud\Kernel\Contracts;

interface TenantDatabase {
    public function query(string $sql, array $params = []): \PDOStatement;
    public function execute(string $sql, array $params = []): int;
    public function lastInsertId(): string;
}
```

**Files to create**: Contracts for Database, Auth, Config, Rendering, EntityViews.

### Step 2: Implement typed providers (v6.2-v6.3)

Each subsystem gets a concrete provider implementing its contract. Providers are registered with `App` during bootstrap.

```php
// kernel/Providers/DatabaseProvider.php
class DatabaseProvider implements TenantDatabase { ... }

// kernel/App.php — boot()
$this->registerProvider(TenantDatabase::class, new DatabaseProvider($this->config));
```

### Step 3: Inject narrow interfaces (v6.3-v6.4)

Module services accept typed interfaces in their constructors. `App` resolves them from the provider registry.

```php
// New pattern
class ProjectService {
    public function __construct(
        private TenantDatabase $db,
        private CapabilityBus $capabilities,
    ) {}
}

// App resolves via provider registry
$service = $app->make(ProjectService::class);
```

### Step 4: Deprecate app()->* in domain logic (v6.4-v6.5)

Add `@deprecated` annotations to `app()->db()`, `app()->capabilities()`, etc. when called from module service classes. PHPStan rule flags them.

### Step 5: Remove service locator methods (v7.0)

Once all domain logic uses injection, remove the service locator methods from `App`, keeping only composition methods (`make()`, `registerProvider()`, `boot()`).

## Boot Profiles

Different execution contexts need different provider sets:

| Profile | Providers |
|---------|-----------|
| **web** | All providers |
| **cli** | Database, Config, Logging, ModuleManager (no HTTP stack) |
| **worker** | Database, Config, Logging, JobQueue, Cache (no request/response) |
| **test** | Database, Config, Logging (minimal — test controls everything else) |
| **installer** | Database, Config (no modules, no auth) |

## Guardrails

1. **No provider bypasses `App` for registration** — all providers go through `registerProvider()`
2. **No module touches `App` directly in domain logic** — use injected contracts
3. **New subsystems start as providers** — no new `app()->*` methods
4. **Existing `app()->*` calls continue to work** — they delegate to the registered provider internally
5. **No DI container library** — keep it explicit; `App` is the container

## Timeline

| Version | Milestone |
|---------|-----------|
| v6.2 | Contracts defined for all subsystems |
| v6.3 | Provider implementations for Database, Config, Logging |
| v6.4 | Provider implementations for Auth, Rendering, EntityViews |
| v6.5 | PHPStan rule enforcing no `app()->*` in modules/ |
| v7.0 | Service locator methods removed from App |
