# Moodle Integration

Optional Moodle LMS integration for cached course discovery, enrollment handoff, SSO launch, and tenant-safe sync.

## Current Status

This module now ships a review-first learner flow: learners request access, admins approve or reject eligibility, approved requests provision Moodle enrollment, and launch happens only after Moodle access is ready.

## Install Modes

1. Bundled module: place this directory at `modules/moodle-integration/`, run `php ikabud migrate moodle-integration`, then enable it.
2. CMS ZIP package: zip a top-level `moodle-integration/` directory containing `module.json`, then upload through the CMS module manager.

## Settings

Configured via the standard module settings UI using manifest `settings_fields`.

- `moodle_url`
- `api_token`
- `sso_secret`
- `tenant_mode`
- `sync_interval`
- `shared_category_map_json`

If `moodle_url` is blank, the module stays inactive and serves only cached or empty-state output.

## Routes

- `GET /admin/moodle-integration`
- `GET /courses`
- `GET /courses/{id}`
- `GET /course/{id}/enroll`
- `GET /my-courses`
- `GET /course/{id}/launch`
- `POST /api/v1/moodle-integration/enroll/{id}`
- `POST /api/v1/moodle-integration/sync`

Canonical browser routes are also exposed under `/cms/...`, including `/cms/courses`, `/cms/course/{id}/enroll`, `/cms/course/{id}/launch`, and `/cms/my-courses`.

## Queues

- Review state: `moodle_enrollment_requests`
- Module-owned sync ledger: `moodle_sync_queue`
- Kernel execution queue: `kernel_jobs` using queue name `moodle`

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
- `install.php` / `uninstall.php` — package-level operator helpers