# ADR-003: `reads_tables` Alongside Capabilities

## Status
Accepted (2025-06)

## Context
The manifesto's ideal architecture channels all cross-module data access through capabilities. In practice, many modules read each other's tables directly via declared `reads_tables` in `module.json`. Example: ecommerce reads `wms_warehouses`; CMS reads `ec_stores`.

This creates a tension: the architecture says "capabilities govern cross-module access," but the manifests say "these modules can read these tables directly."

## Decision
`reads_tables` is accepted as a first-class governed contract alongside capabilities, with explicit rules:

1. **Reads, never writes.** A module declaring `reads_tables` may SELECT from those tables. INSERT/UPDATE/DELETE are denied by ModuleDB enforcement.

2. **Declared, not discovered.** A module must declare every table it reads in `module.json`. Undeclared reads throw a RuntimeException via ModuleDB.

3. **Prefer capabilities when they exist.** If a module provides a capability that returns the same data (e.g., `wms.inventory.summary@1`), consumers should use the capability rather than direct table reads. The `reads_tables` declaration is a fallback, not a preference.

4. **Deprecation is possible.** When a module migrates and changes a table schema, `reads_tables` consumers may break. This is acceptable — the consumer declared a dependency on the table structure. Future work should add schema snapshots and deprecation warnings.

5. **Co-ownership is separate.** `co_owns_tables` grants full CRUD on shared tables and requires explicit coordination (see `co-owns-tables-policy.md`).

## Why Not Capabilities Only?

Converting every cross-module read to a capability call would:
- Add latency (each read becomes a capability dispatch with circuit breaker, metrics, and serialization overhead)
- Duplicate SQL (the provider would execute the same query the consumer could have run directly, adding no architectural value)
- Create capability explosion (one capability per query pattern rather than one capability per business operation)
- Be premature without a capability catalog and SDK ecosystem

The pragmatic position: capabilities for business operations (reserve inventory, place order, close case); `reads_tables` for read-only data access (list warehouses, read store config, check product availability). This preserves the architectural boundary where it matters — mutation authority — without creating ceremony where it doesn't.

## Consequences

### Positive
- Low-friction cross-module data access for legitimate read patterns
- Clear enforcement via ModuleDB (no undeclared reads possible)
- Preserves capability-mediated mutation authority (writes always go through capabilities)

### Negative
- Schema coupling between modules (a table change in the provider module can break consumers)
- The boundary between "read that should be `reads_tables`" and "read that should be a capability" requires judgment
- Externalizes table schemas as implicit contracts without versioning

## Future Direction
- Schema snapshots on module load to detect breaking changes in `reads_tables`
- Deprecation warnings via lint/composer when a capability exists for a declared `reads_tables` table
- Gradual migration of high-value cross-module reads (e.g., ecommerce→WMS warehouse list) to capabilities as the capability catalog matures
