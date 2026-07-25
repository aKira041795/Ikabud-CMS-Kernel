# EHR Suite Shell

Tenant-facing EHR authentication, branding, and admin shell. Entry point for the healthcare module suite.

## Features

- EHR-branded login and authentication
- Admin shell with EHR-specific navigation
- Cross-module nav integration (patient-registry, scheduling, encounters, clinical-notes, orders, results, documents)
- Version 1.0.0

## Dependencies

- `ehr-core` — shared contracts and status catalogs
- `audit` — audit log search
- `reporting` — operational reporting
- `billing-bridge` — billing charge generation

## Files

- Manifest: [`module.json`](module.json)
