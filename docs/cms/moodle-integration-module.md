# Moodle Integration Module

## Purpose

`moodle-integration` turns Moodle into a plug-in learning engine for the Ikabud CMS/Kernel stack without modifying Moodle core. The module owns discovery, enrollment handoff, cached dashboards, and SSO launch. Moodle remains the system that delivers lessons, assessments, and grades.

The current module shape is already broader than a narrow point integration. Internally, it is moving toward a provider-agnostic learning orchestration layer where Moodle is the first provider, not the permanent application boundary. `learning_resources` is the canonical local registry for that direction, while some bridge-owned tables still retain Moodle identifiers for backward compatibility and operational handoff.

## Installation Model

This module follows the standard kernel module contract and also packages cleanly for CMS ZIP upload.

### Bundled module

1. Place the directory at `modules/moodle-integration/`
2. Run `php ikabud migrate moodle-integration`
3. Enable the module for the target tenant/environment
4. Save settings through the existing module settings UI

### CMS ZIP package

Package a ZIP with a single top-level `moodle-integration/` directory containing `module.json`.

```bash
cd /path/to/parent
zip -r moodle-integration.zip moodle-integration/
```

Upload it through `CMS Admin -> Modules` or the CMS upload API.

When the module is installed or re-enabled from the CMS installer, it bootstraps editable CMS pages for the tenant:

- `Learning Center` at `/cms/page/learning-center`
- `My Learning` at `/cms/page/my-learning`

These are regular CMS pages backed by `cms_content`, so admins can restyle them, add builder sections around them, or replace the default shortcode layout entirely.

## Folder Structure

```text
modules/moodle-integration/
├── module.json
├── routes.php
├── handlers.php
├── helpers.php
├── install.php
├── uninstall.php
├── README.md
├── controllers/
│   ├── CourseController.php
│   ├── EnrollmentController.php
│   └── LaunchController.php
├── jobs/
│   ├── SyncCoursesJob.php
│   └── SyncProgressJob.php
├── services/
│   ├── MoodleService.php
│   ├── SSOService.php
│   └── SyncService.php
└── database/
    └── migrations/
        ├── 001_moodle_integration_schema.sql
        ├── 002_moodle_enrollment_requests.sql
        └── 003_moodle_hardening_schema.sql

templates/modules/moodle-integration/
├── pages/
│   ├── admin.disyl
│   ├── courses.disyl
│   ├── course-detail.disyl
│   ├── enroll.disyl
│   ├── my-courses.disyl
│   └── launch-error.disyl
└── blocks/
    ├── course-list.disyl
    ├── course-detail.disyl
    ├── my-courses.disyl
    └── progress-dashboard.disyl
```

## Manifest Highlights

`module.json` is the authoritative install contract.

- `id`: `moodle-integration`
- `owns_tables`: `learning_resources`, `moodle_courses_cache`, `moodle_user_progress`, `moodle_sync_queue`, `moodle_enrollment_requests`, `moodle_sso_tokens`, `moodle_sync_metrics`
- `settings_fields`: `moodle_url`, `api_token`, `sso_secret`, `tenant_mode`, `enrollment_mode`, `sync_interval`, `shared_category_map_json`
- `hooks`: CMS admin nav, editor block types, builder renderers, CMS public rendered-content filter for shortcodes
- `schedules`: one dispatcher handler registered for 15-minute, hourly, and daily frequencies; the handler filters itself against the saved `sync_interval`

## Database Schema

### `moodle_courses_cache`

- local read model for public course discovery
- unique per `tenant_id + moodle_course_id`
- stores title, summary, image, tenant-safe category metadata, and a linked internal `resource_id`

### `learning_resources`

- internal provider-agnostic learning resource registry
- unique per `tenant_id + provider + provider_id`
- provides the canonical internal resource key that application-level workflows should converge on over time
- lets the bridge keep a stable internal resource id even when Moodle remains the current provider

### `moodle_user_progress`

- local progress/grade read model keyed by `tenant_id + user_id + course_id`
- `course_id` currently references `moodle_courses_cache.id`, not the raw Moodle course id; this keeps the table one step removed from the provider boundary even though the column name is still legacy
- a follow-up migration should rename this to something explicit like `course_cache_id` or move to `learning_resource_id` as the canonical internal reference
- stores normalized `progress_percent`, `grade`, `status`, and `last_synced`

### `moodle_sync_queue`

- module-owned sync ledger and retry state
- stores `tenant_id`, operation `type`, request `payload_json`, `status`, `retries`, `last_error`, and processing timestamps
- complements the generic `kernel_jobs` queue rather than replacing it

### `moodle_enrollment_requests`

- learner-facing review workflow keyed today by `tenant_id + user_id + moodle_course_id`
- stores `pending_review`, `pending_payment`, `approved`, `rejected`, and `revoked` states separately from sync execution
- tracks linked `learning_resource_id`, configured `enrollment_mode`, reviewer identity, review notes, timestamps, and the last related sync queue row
- `learning_resource_id` should be treated as the canonical local identifier; `moodle_course_id` remains as a bridge-compatibility field until provider ids are isolated to the provider-facing sync boundary

### `moodle_sso_tokens`

- one-time launch token issuance ledger
- stores a SHA-256 token hash, expiry, user, tenant, linked learning resource, and first-use timestamp
- keeps launch tokens short-lived and ready for Moodle-side consume-once validation

### `moodle_sync_metrics`

- tenant-scoped sync observability rollup
- stores success count, failure count, moving average duration, last run, and last error per sync type

## Queue Design

The module deliberately separates domain state from execution.

- `moodle_sync_queue`: durable module-owned ledger used for domain intent, queue visibility, and failure history
- `kernel_jobs`: generic execution queue used by `kernelDispatchJob()` and `php ikabud work:queue`

This mirrors the repo’s existing pattern where module-owned queue tables can exist alongside the kernel worker.

## Settings and Tenant Modes

The module reads settings through the standard manifest-backed settings flow.

- If `moodle_url` is blank, the module remains inactive and falls back to cached or empty-state output.
- `tenant_mode = per_instance`: each tenant points at its own Moodle base URL.
- `tenant_mode = shared`: one Moodle instance is shared, and `shared_category_map_json` narrows courses to tenant-specific Moodle categories.
- `enrollment_mode = manual_review`: requests wait for admin approval before sync.
- `enrollment_mode = auto_enroll`: learner request is auto-approved and queued immediately.
- `enrollment_mode = paid_then_auto`: learner request is staged locally as `pending_payment` so a later payment confirmation seam can advance it.

Shared-mode isolation is enforced in two places now:

- Moodle API reads are filtered by the configured tenant category.
- Local cache/detail lookups also reject courses whose cached category does not match the tenant mapping.

The current generic admin renderer does not support masked secret inputs, so `api_token` and `sso_secret` are stored as regular manifest-backed fields for now.

Outbound rate limiting is not yet manifest-configurable. Before large shared-instance rollouts, add explicit throttle settings such as `max_requests_per_minute` and `burst_limit` so the bridge can protect Moodle instead of amplifying traffic spikes.

## Service Layer

### `MoodleService`

Handles Moodle Web Services calls through the REST endpoint:

`/webservice/rest/server.php`

Implemented methods:

- `getCourses()`
- `getCourseById($id)`
- `createUser($user)`
- `enrollUser($userId, $courseId)`
- `getUserGrades($userId, $courseId)`
- `getUserProgress($userId, $courseId)`
- `resolveOrCreateMoodleUser($localUser)`

The service performs up to three attempts for transient failures and treats Moodle exception payloads as first-class API errors.

The service does not yet enforce tenant-aware backpressure beyond retry behavior. That is acceptable for the current single-provider rollout, but it is a real scaling gap once multiple institutions or bulk sync waves share the same upstream Moodle instance.

### Example API call

The module issues Moodle calls in the standard tokenized REST form:

```php
$result = $service->request('core_course_get_courses_by_field', [
    'field' => 'id',
    'value' => 42,
]);
```

Equivalent HTTP shape:

```text
POST https://lms.example.com/webservice/rest/server.php
Content-Type: application/x-www-form-urlencoded

wstoken=...&moodlewsrestformat=json&wsfunction=core_course_get_courses_by_field&field=id&value=42
```

### `SSOService`

Generates a short-lived HMAC-signed launch token containing:

- tenant id
- local user identity
- course id/title
- issue and expiry timestamps
- a unique token id (`jti`)

It then redirects to a Moodle-side endpoint such as:

```text
https://lms.example.com/local/applicationos/sso.php?token=...&course=123
```

This assumes a Moodle-side local/auth plugin or equivalent endpoint. Moodle core is not modified.

The ApplicationOS side now records every issued token in `moodle_sso_tokens` with a one-minute expiry and a first-use timestamp slot. Helper-level consume-once enforcement already exists through the local token ledger, but the public bridge contract is still incomplete: the Moodle-side endpoint should call a dedicated validation API before honoring the token.

Recommended validation contract:

```text
POST /api/v1/moodle-integration/sso/validate
```

Suggested response shape:

```json
{
    "valid": true,
    "user": {
        "id": 123,
        "email": "learner@example.com"
    },
    "resource": {
        "id": 456,
        "provider": "moodle",
        "provider_id": "42"
    }
}
```

The validation endpoint should atomically consume the token by setting `used_at = NOW()` only when the token is still valid and unused.

### `SyncService`

Handles:

- course cache refresh
- approval-triggered Moodle enrollment and progress sync
- scheduled refresh of existing progress rows
- queue state updates inside `moodle_sync_queue`
- learning resource upserts inside `learning_resources`
- sync metrics rollups inside `moodle_sync_metrics`
- tenant-explicit DB access through `app()->dbForTenant($tenantId)` when running in queued jobs

The current sync path is still mostly reactive: it records failures well, but it does not yet run a full pre-flight drift check before every enrollment or progress refresh. The next hardening step is to verify local enrollment state, cached course availability, and Moodle user existence before writing progress updates, then record those mismatches into metrics and queue history early.

## Controllers and Route Model

The repo routes to handler functions, not directly to controller classes. This module keeps that convention and uses controllers behind the handlers.

- `CourseController`: list/detail/my-courses read-model access
- `EnrollmentController`: creates learner review requests and applies admin review decisions
- `LaunchController`: builds the Moodle launch URL

Registered routes:

- `GET /admin/moodle-integration`
- `GET /courses`
- `GET /courses/{id}`
- `GET /course/{id}/enroll`
- `GET /my-courses`
- `GET /course/{id}/launch`
- `GET /api/v1/moodle-integration/status/{id}`
- `POST /api/v1/moodle-integration/enroll/{id}`
- `POST /api/v1/moodle-integration/sync`

Canonical browser-facing routes are also exposed under `/cms/...`, including `/cms/courses`, `/cms/course/{id}/enroll`, and `/cms/my-courses`.

## CMS Block Integration

The module registers four builder blocks using the same hook pattern as the contact-form module.

- `moodle_course_list`
- `moodle_course_detail`
- `moodle_my_courses`
- `moodle_progress_dashboard`

All of them read local cache/progress tables only.

## CMS Shortcodes And Auto Pages

The module also renders shortcode tags through the CMS public rendered-content filter, so admins can either keep the auto-created Moodle pages or embed Moodle surfaces inside any regular CMS page.

Supported tags:

- `[moodle-courses title="Available Courses" limit="9"]`
- `[moodle-courses title="Available Courses" limit="6" category="tesda-nc3"]`
- `[moodle-courses title="Available Courses" limit="6" category_id="42"]`
- `[moodle-course-detail course_id="123"]`
- `[moodle-my-courses title="My Learning"]`
- `[moodle-my-courses title="My Learning" status="in_progress"]`
- `[moodle-progress title="Progress Dashboard"]`

The default `Learning Center` and `My Learning` pages use these shortcodes as their initial body content.

Signed-out users who open the enroll page or learner dashboard shortcodes are prompted to sign in through `/cms/login?redirect=...`, and the CMS login page now preserves that redirect so the user returns to the Moodle flow after authentication.

### Sample block usage

```php
app()->hooks()->on('cms.builder.renderers', static function (array $map): array {
    $map['moodle_course_list'] = 'moodleIntegrationRenderCourseListBlock';
    return $map;
}, 10);
```

## Enrollment And SSO Flow

1. Learner opens `/course/{id}/enroll`
2. Signed-in learners submit a local enrollment request that follows the tenant’s configured `enrollment_mode`
3. Manual-review tenants approve, reject, or revoke from `/admin/moodle-integration`; auto-enroll tenants queue immediately; paid-then-auto tenants stage a `pending_payment` request for a later payment confirmation seam
4. Approval or auto-enrollment triggers Moodle enrollment sync and creates local progress rows when Moodle confirms access
5. Only after access is ready does `LaunchController` build the signed SSO launch URL
6. Browser is redirected to the Moodle-side SSO endpoint and Moodle validates the token

## Failure Handling

- blank config: module is inactive, cached pages still render
- Moodle offline during public page render: cache-only output remains available
- approval-provisioning failure: the request remains reviewed, the queue row captures the failure, and the learner sees the failed provisioning state locally
- queued sync failure: queue row moves to failed state with `last_error`, and `kernel_jobs` retry semantics still apply

Lifecycle cleanup remains an explicit follow-up item. If a course disappears from Moodle, moves categories, or a tenant disconnects the provider, the safer model is soft deactivation of the internal resource rather than hard deletion so historical progress, auditability, and reporting remain intact.

## Gap Analysis Against Current Feedback

### Accepted And Already Partially Addressed

- Resource abstraction: valid gap. The module already has `learning_resources`, `resource_id` on cached courses, and `learning_resource_id` on enrollment requests. The remaining inconsistency is that browser routes and some bridge tables still expose `moodle_course_id`, while progress still uses a legacy `course_id` column name tied to the cache row. The next migration should make `learning_resource_id` the canonical application-level foreign key everywhere and keep provider ids inside the provider-facing bridge layer only.
- SSO consume-once enforcement: valid gap, but narrower than the feedback suggests. Token hashing, expiry, first-use storage, and helper-level token consumption already exist. The missing piece is the explicit route contract for a Moodle-side plugin to validate and consume tokens through a supported HTTP API.
- Lifecycle cleanup: valid gap. The current schema and flow describe install, sync, and revoke behavior, but not the long-tail lifecycle for removed or reassigned Moodle courses. Add an inactive lifecycle state instead of deleting learning resources that already have user history.

### Accepted As The Next Scale Hardening Layer

- Provider capability layer: good future-proofing. A dedicated `learning_providers` contract with `capabilities_json` is not required for a single-provider launch, but it should be added before introducing a second LMS or any provider that lacks Moodle-style progress and grade semantics.
- Proactive sync guards: valid. Today the module is observable and recoverable, but still mostly detects issues after a failing call. Add pre-flight checks for enrollment state, course availability, and upstream user existence before mutating local progress or queue state.
- Rate limiting and backpressure: valid. Retries alone are not enough once enrollment bursts or shared upstream instances are involved. Add per-tenant or per-provider request budgeting before scaling across multiple institutions.

### Valuable But Not A Core Contract Gap

- Program-level grouping such as `[moodle-courses program="NCIII"]`: useful product expansion, but it belongs after contract stabilization. It should build on `learning_resources` and future catalog metadata rather than become a substitute for fixing the core provider boundary first.

## Recommended Next Hardening Steps

1. Make `learning_resource_id` the canonical internal reference across progress, enrollment, and launch-state lookups, leaving `moodle_course_id` as a provider-edge field only.
2. Add `POST /api/v1/moodle-integration/sso/validate` and require the Moodle-side plugin to validate and atomically consume launch tokens before granting access.
3. Introduce provider capability metadata before adding a second LMS so grade, progress, and launch assumptions become explicit instead of implicit.
4. Add pre-flight drift checks and outbound throttling to the sync layer before multi-institution rollouts.
5. Add soft-deactivation lifecycle handling for resources that disappear, move, or become tenant-ineligible upstream.

## Verification Commands

```bash
php ikabud module:validate moodle-integration
php tests/moodle_integration_module_test.php
php ikabud schedule:run --dry-run
php ikabud work:queue moodle --once
```

After queue or sync work, inspect:

- `storage/logs/app.log`
- `storage/logs/error.log`

## Scope Boundary

In scope:

- course discovery
- manual review workflow for enrollment requests
- progress caching
- SSO redirect
- CMS blocks and dashboards

Out of scope:

- Moodle quizzes or lesson UI replication
- grade computation
- Moodle core modification
