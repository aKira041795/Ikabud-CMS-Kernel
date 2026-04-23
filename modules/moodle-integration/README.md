# Moodle Integration

Optional Moodle LMS integration for cached course discovery, enrollment handoff, SSO launch, and tenant-safe sync.

## Current Status

This module now ships a mode-driven learner flow: tenants can keep the review-first workflow, auto-enroll immediately, or stage requests for a later paid confirmation. Approved access still provisions Moodle enrollment only after the local bridge is ready to hand off safely.

## Install Modes

1. Bundled module: place this directory at `modules/moodle-integration/`, run `php ikabud migrate moodle-integration`, then enable it.
2. CMS ZIP package: zip a top-level `moodle-integration/` directory containing `module.json`, then upload through the CMS module manager.

## Settings

Configured via the standard module settings UI using manifest `settings_fields`.

- `moodle_url`
- `api_token`
- `sso_secret`
- `tenant_mode`
- `enrollment_mode`
- `sync_interval`
- `shared_category_map_json`

If `moodle_url` is blank, the module stays inactive and serves only cached or empty-state output.

Shared tenant mode now enforces local category ownership in addition to Moodle-side filtering. If a tenant/category mapping is missing or mismatched, the module returns no shared-mode courses instead of trusting a remote category filter alone.

## Routes

- `GET /admin/moodle-integration`
- `GET /courses`
- `GET /courses/{id}`
- `GET /course/{id}/enroll`
- `GET /my-courses`
- `GET /course/{id}/launch`
- `GET /api/v1/moodle-integration/status/{id}`
- `POST /api/v1/moodle-integration/enroll/{id}`
- `POST /api/v1/moodle-integration/sync`

Canonical browser routes are also exposed under `/cms/...`, including `/cms/courses`, `/cms/course/{id}/enroll`, `/cms/course/{id}/launch`, and `/cms/my-courses`.

## Queues

- Review state: `moodle_enrollment_requests`
- Resource abstraction: `learning_resources`
- Module-owned sync ledger: `moodle_sync_queue`
- SSO token issuance ledger: `moodle_sso_tokens`
- Sync observability rollup: `moodle_sync_metrics`
- Kernel execution queue: `kernel_jobs` using queue name `moodle`

The current hardening backlog is focused on contract tightening rather than new UI: make `learning_resource_id` the canonical internal reference, add a supported SSO validation endpoint for the Moodle-side plugin, introduce outbound throttling for shared-instance scale, and add soft-deactivation lifecycle handling for resources that disappear upstream.

Use:

```bash
php ikabud schedule:run --dry-run
php ikabud work:queue moodle --once
```

## Files

- `module.json` — manifest, settings, schedules, and table declarations
- `routes.php` — public/admin/API route map
- `helpers.php` — scoped helpers, settings defaults, CMS hooks, and cache readers
- `handlers.php` — route handlers and queue job entrypoints
- `database/migrations/001_moodle_integration_schema.sql` — tenant-safe local cache/progress/queue schema
- `database/migrations/002_moodle_enrollment_requests.sql` — manual review workflow state
- `database/migrations/003_moodle_hardening_schema.sql` — learning resource mapping, one-time SSO token storage, sync metrics, and shared-mode hardening
- `install.php` / `uninstall.php` — package-level operator helpers