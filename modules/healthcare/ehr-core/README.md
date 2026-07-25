# EHR Core

Shared EHR capability contracts, status catalogs, and cross-module helpers. Foundation module for the healthcare suite — all other EHR modules depend on this.

- **No database tables, no migrations** — pure contracts and helpers
- **Capabilities**: `ehr.core.status.catalog@1` — domain enum/status catalog system
- **Settings**: 4 branding fields (app_name, login_subtitle, logo_url, favicon_url) for EHR auth pages

## Dependencies

Required by all EHR modules. Declared as dependency in `ehr/module.json`.

## Files

- Manifest: [`module.json`](module.json)
