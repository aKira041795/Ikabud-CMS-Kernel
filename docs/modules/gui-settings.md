# GUI Settings Module Status

## Summary

`modules/gui-settings` remains an active compatibility module for tenant UI settings (colors, typography, app naming).
It is not removed, but it overlaps with the newer theme-owned system (`theme-studio` + `theme.manifest.json` + `tokens.json`).

## Current Role

- Provides tenant-level UI overrides through module settings.
- Exposes `gui_settings.apply@1` capability.
- Serves admin UI at `/admin/gui-settings`.
- Generates CSS overrides consumed by legacy/admin shell paths.

## Relationship to Theme-Owned Customization

- Canonical theme design contract is now theme-owned (`theme.manifest.json` and token files), managed by theme infrastructure.
- `gui-settings` should be treated as a legacy compatibility layer for tenants that still rely on per-tenant override values.
- New theme work should target theme manifests/tokens first, not expand `gui-settings` schema.

## Deprecation Direction

Current recommendation:

1. Keep `gui-settings` operational for backward compatibility.
2. Freeze net-new feature work in `gui-settings` unless required for migration safety.
3. Route new customization capabilities to `theme-studio` and manifest/token pipelines.
4. Add migration tooling that maps `gui-settings` values to theme tokens per tenant before hard deprecation.

## Decision Record

This document records that `gui-settings` is **documented and compatibility-scoped**, not immediately removed.
A hard deprecation date should be set only after tenant migration coverage is complete.