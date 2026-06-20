# ADR-001: PHP as Kernel Host Language

## Status
Accepted (2024-03)

## Context
The Ikabud Kernel needed a host language for the core runtime — routing, capability dispatch, tenant resolution, DiSyL rendering, authentication, and module management. The primary deployment target was shared hosting environments (Bluehost, cPanel) where customer sites could run without dedicated server infrastructure.

## Decision
PHP remains the kernel implementation language.

## Alternatives Considered
- **Node.js**: Excellent for async I/O and real-time behavior, but no shared-hosting ecosystem; would require separate deployment infrastructure for every tenant.
- **Python**: Strong for data analysis and reporting, but weak CMS/hosting ecosystem; would add deployment complexity without solving the primary use case.
- **Go**: Excellent for high-concurrency services, but would require a complete rewrite of CMS integration, template rendering, and WordPress/Drupal compatibility layers.

## Consequences

### Positive
- Zero-dependency deploy on Bluehost/cPanel — the PHP runtime is already present
- Direct compatibility with WordPress, Joomla, and Drupal bridges via shared PHP runtime
- Existing team expertise and ecosystem of PHP libraries (Composer)
- Fast iteration cycle for business application development (forms, reports, CRUD, routing)
- Session management, cookie handling, and HTTP primitives are built into the language runtime

### Negative
- Polyglot capability providers require ServiceProxy (network hop + JSON serialization overhead)
- Native mobile DiSyL runtime requires a separate implementation (cannot compile PHP to APK/iOS)
- Long-running processes and async I/O require workarounds (Supervisor, cron, queue workers)
- PHP's shared-nothing architecture means state must be rebuilt on every request (mitigated by APCu, file cache, and compiled DiSyL templates)
- Language reputation can deter developers who prefer modern runtimes

## Mitigations
- ServiceProxy provides a zero-friction bridge for non-PHP capability providers (Python, Node, Go, Rust) via HTTP+JSON
- The compiled DiSyL mode and APCu caching reduce per-request overhead significantly
- Fast-path page cache and static asset handlers bypass the full kernel bootstrap for cacheable responses
- The manifest-driven architecture ensures the PHP boundary is well-defined — PHP hosts the contract, not the entire ecosystem
