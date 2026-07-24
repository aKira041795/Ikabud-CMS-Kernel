# ADR-001: Cross-Module Communication via Capability Contracts

## Status

**Accepted** (2026-07-24)

## Context

Ikabud has 30+ modules that need to interact with each other. Examples:

- Ecommerce needs to check inventory levels in WMS
- Guidance needs to record payments through Daily Ledger
- Attendance-Wage needs to read employee records from HR
- Bakeshop needs to push production orders to WMS

Without a defined communication pattern, modules could:
1. Directly query another module's database tables (breaking table ownership)
2. Call another module's PHP functions directly (breaking encapsulation)
3. Import classes across module boundaries (creating hidden dependency graphs)

## Decision

**All cross-module communication MUST go through capability contracts only.**

A "capability contract" is a named, versioned interface registered by the providing module in its `module.json` and resolved through `Ikabud\Kernel\Capabilities\CapabilityBus`.

```
Module A (consumer)                Module B (provider)
     │                                    │
     │  app()->capabilities()             │  module.json:
     │    ->call('b:getStock',            │    capabilities:
     │      ['sku' => 'ABC'])             │      exposes:
     │                                    │        b:getStock@1
     │                                    │
     ▼                                    ▼
          CapabilityBus
          ┌──────────────────────┐
          │ Resolve 'b:getStock' │
          │ → Find handler in    │
          │   module B's helpers │
          │ → Validate contract  │
          │ → Audit log call     │
          │ → Return result      │
          └──────────────────────┘
```

## Rules

1. **No direct table access across modules** — Each module owns its tables. Other modules access data only through capability contracts.
2. **No direct function calls across modules** — No `require_once` of another module's files. No `use` imports of another module's classes (except kernel contracts).
3. **Capability IDs are namespaced** — Format: `<module-id>:<capability-name>@<version>`. Example: `wms:getStock@1`.
4. **Capability versions are immutable** — Once published, a capability contract cannot change behavior. New behavior requires a new version (`@2`).
5. **Every capability call is audited** — The CapabilityBus logs caller module, capability ID, timestamp, and result to `kernel_audit_logs`.
6. **Capabilities declare dependencies** — If `ecommerce:createOrder@1` calls `wms:reserveStock@1`, `ecommerce` must declare `wms` as a dependency in its `module.json`.

## Consequences

### Positive

- **Clear dependency graph** — The module manager can validate all capability dependencies at load time
- **Testable contracts** — Each capability can be mocked in tests without loading the provider module
- **Capability-based authorization** — The capability bus enforces that the caller has the right to invoke a capability
- **Safe refactoring** — A module's internals can change as long as its capability contracts stay stable
- **Module isolation** — A module can be disabled, and the capability bus returns a clear error to consumers

### Negative

- **More boilerplate** — Every cross-module interaction requires a capability definition, handler, and audit
- **Performance overhead** — Capability resolution adds a lookup/audit step vs direct function calls
- **Versioning burden** — Maintaining backward compatibility across capability versions requires discipline
- **Migration friction** — Existing modules with direct cross-module calls will need refactoring

## Alternatives Considered

### Direct function calls
**Rejected** — Creates hidden dependency graphs. Impossible to test modules in isolation. Breaks module ownership.

### Event-driven communication (events only)
**Rejected for request/response patterns** — Events are fire-and-forget. Many cross-module interactions need a synchronous response (e.g., "is this SKU in stock?"). Events remain the pattern for asynchronous side effects.

### Shared kernel library
**Rejected** — Would require all modules to depend on the shared library version. Upgrading the library forces all modules to upgrade simultaneously. Violates independent module versioning.

## Compliance

The `architecture:check` CI step validates:
- No module PHP file `require`s or `include`s a file from another module
- No module uses another module's namespace in `use` statements
- All declared capability dependencies in `module.json` are satisfied
- All capability IDs used in `capabilities()->call()` match a declared `capabilities.depends`

## References

- Capability Bus: `kernel/Capabilities/CapabilityBus.php`
- Module manifest schema: `docs/kernel/module-manifest-schema.md`
- Module manager: `src/helpers/module-manager.php`
- Architecture check: `php ikabud architecture:check`
