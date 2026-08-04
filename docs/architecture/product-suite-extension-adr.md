# ADR: Product Suite And Extension Architecture

Status: Accepted (baseline)
Date: 2026-08-04
Decision owner: Kernel OS / Module System

## Context

Ikabud modules were designed as a mostly-flat set of peer modules. As product
platforms (CMS Akira, PAL, AISS, ARK, EHR, Commerce) grow into extensible
systems, a flat module registry no longer captures the relationships that
matter for installation, administration, compatibility, and lifecycle:

- product cores;
- installable extensions;
- optional adapters;
- presentation providers;
- profile bundles;
- modules that contribute administration surfaces to another module.

The kernel understands module dependencies but does not yet understand product
hierarchy, extension ownership, administrative composition, or installation
context.

## Decision

Introduce an explicit, manifest-declared **product suite and extension model**
on top of the existing flat module registry. Physical directory nesting is
for repository clarity only; the manifest is the authority for logical
hierarchy.

### Concepts

- **Product Suite** — a named family of modules (e.g. `cms-akira`, `pal`).
- **Product Core** — the authoritative module of a suite (`kind: product-core`).
  Declares the suite's `extension_points`.
- **Extension** — a module that extends a host core (`kind: extension`,
  `extends: <core-id>`), consuming capabilities and contributing surfaces.
- **Adapter** — a module that adapts an external provider/backend into a suite
  contract (`kind: adapter`, `extends`).
- **Profile** — an installation bundle (`kind: profile`, `installs: [...]`)
  that declares which modules form a coherent product configuration.
- **Contribution** — a manifest-declared admin/UI surface registered against a
  host's declared `extension_points` and rendered dynamically.

### Manifest fields (additive, schema-v2 layer)

- `suite` — normalized suite id.
- `kind` — one of `product-core|extension|adapter|profile|service|integration|standalone-application`.
- `extends` — host module id this module extends (required for extension/adapter).
- `extension_points` — list of point ids the host exposes.
- `contributes` — list of `{extension_point, provider}` declarations.
- `admin_contributions` — list of `{host, location, group, label, icon, route, permission, order}`.
- `compatibility` — `{kernel, suite}` semver ranges.
- `uninstall` — `{disable_safe, retain_data_by_default, supports_data_export, requires_confirmation_to_drop_data}`.

### Runtime behavior

- **Hierarchy explains relationship; capabilities execute behavior;
  contributions integrate UI; Workbench proves compliance.**
- Suite membership, extension ownership, and admin contributions are resolved
  from manifests at discovery time.
- The kernel validates extension ownership and contribution hosts during
  install and certification. A module cannot inject itself into a host
  extension point the host did not declare.
- Installation stays WordPress-simple on the surface (upload → validate →
  install → enable → register → verify) while the kernel enforces ownership
  and lifecycle contracts.

### Compatibility policy (schema-v1 → schema-v2)

- `MODULE_MANIFEST_SCHEMA_VERSION` stays `'1'` for backward compatibility.
- New suite-contract fields are **optional and additive**. Manifests that do
  not declare them are treated as `kind: standalone-application` (or legacy)
  and remain valid.
- When the new fields are present, they are validated strictly.
- Fleet-level checks (extends targets exist, contribution hosts declared,
  suite prefix consistency) run in the manifest guard and certification, never
  at tenant boot for legacy modules.

## Consequences

- Positive: authoritative hierarchy, dynamic admin composition, install-time
  compatibility gates, first-class disable/uninstall/purge semantics.
- Negative: manifest schema surface grows; requires discipline in new modules.
- Trade-off accepted: we do NOT build a deeply nested runtime loader. The
  loader stays flat; relationships come from manifests.
