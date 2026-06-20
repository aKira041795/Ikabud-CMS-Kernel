# ADR-002: CMS is a Module, Not the Kernel

## Status
Accepted (2025-01)

## Context
The Ikabud project began as a response to WordPress bloat. Early versions managed WordPress, Joomla, and Drupal instances under a shared supervisory layer. As the supervisory layer grew more capable, a paradox emerged: the Kernel wanted to govern the CMS, but the CMS still expected to govern parts of the request lifecycle (login, admin, routing, theme resolution).

The question was: should the Kernel remain an accessory to a CMS, or should the CMS become a participant inside the Kernel?

## Decision
The CMS is a module — one governed participant among many — not the sovereign platform.

The Ikabud CMS module (`modules/cms`) operates under the same manifest, capability, event, and routing contracts as every other module. It does not own the kernel lifecycle, tenant resolution, authentication, or cross-module communication.

## Alternatives Considered
- **CMS as first-class citizen**: Give the CMS special kernel hooks, bypassing module contracts. Rejected — this would make the CMS irreplaceable and contradict the architecture's governed-composition model.
- **No native CMS**: Rely entirely on WordPress/Joomla/Drupal bridges. Rejected — bridges force the kernel to accommodate foreign assumptions; a native CMS proves the architecture works on its own terms.
- **Multiple peer CMS engines**: Support Ikabud CMS, WordPress bridge, Joomla bridge, and Drupal bridge simultaneously. Partially accepted — bridges remain as migration/convenience tools, but the native CMS is the reference implementation.

## Consequences

### Positive
- The CMS is replaceable — another content management approach can be installed without rewriting the kernel
- The CMS uses the same capability bus, event bus, and ModuleDB contracts as ecommerce, WMS, guidance, and ledger modules
- Content can be presented through DiSyL entity views alongside products, courses, cases, and reports
- The CMS admin interface is a module concern, not a kernel concern — kernel admin routes are separate and stable

### Negative
- Some legacy CMS assumptions (post as center of everything) must be explicitly rejected in documentation and module design
- The CMS module has grown large (~40 owned tables, complex builder UI) — it tests the limits of the module-as-participant model
- Cross-module ownership questions arise (e.g., ecommerce co-owns `cms_content_types`)

## Related ADRs
- ADR-003: `reads_tables` Alongside Capabilities
- `co-owns-tables-policy.md`
