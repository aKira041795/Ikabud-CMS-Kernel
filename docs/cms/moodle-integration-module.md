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
        ├── 003_moodle_hardening_schema.sql
        ├── 004_moodle_provider_capabilities_schema.sql
        ├── 005_moodle_idempotency_and_progress_fk.sql
        ├── 006_moodle_catalog_fields.sql
        ├── 007_moodle_progress_rename_course_id.sql
        └── 008_moodle_reconciliation_observability.sql

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
- `owns_tables`: `learning_providers`, `learning_resources`, `moodle_courses_cache`, `moodle_user_progress`, `moodle_sync_queue`, `moodle_enrollment_requests`, `moodle_sso_tokens`, `moodle_sync_metrics`, `moodle_rate_limit`, `moodle_sync_discrepancies`
- `settings_fields`: `moodle_url`, `api_token`, `sso_secret`, `tenant_mode`, `enrollment_mode`, `sync_interval`, `shared_category_map_json`, `max_requests_per_minute`, `burst_limit`, `staleness_threshold_minutes`
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
- `learning_resource_id` (added in migration 005) — FK to `learning_resources.id`; populated on every SyncService write and on inbound webhook events. This is now the canonical internal resource reference for progress rows. `course_cache_id` (renamed from `course_id` in migration 007) references `moodle_courses_cache.id` and is kept for backward-compat bridge joins.
- stores normalized `progress_percent`, `grade`, `status`, and `last_synced`

### `moodle_sync_queue`

- module-owned sync ledger and retry state
- stores `tenant_id`, operation `type`, request `payload_json`, `status`, `retries`, `last_error`, and processing timestamps
- `idempotency_key VARCHAR(160)` (added in migration 005) — unique per `(tenant_id, idempotency_key)`. `moodleIntegrationQueueTableInsertForTenant` uses `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` so callers that pass the same key twice receive the original row ID instead of inserting a duplicate. `EnrollmentController::approveEnrollment()` sets the key to `enroll:{tenantId}:{userId}:{moodleCourseId}`
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

`api_token` and `sso_secret` are encrypted at rest using AES-256-GCM via `kernel/Crypto.php`. The helpers `moodleIntegrationEncryptSettingValue()` and `moodleIntegrationDecryptSettingValue()` wrap the kernel `Crypto` class. `postMoodleIntegrationSettings` encrypts these fields before persisting via `saveTenantModuleSettingsForTenant`, and `moodleIntegrationGetSettingsForTenant` transparently decrypts them on read. Existing plaintext values in older tenants are returned as-is (backward compatibility passthrough).

**Encryption key policy**: when `APP_ENV=production`, `moodleIntegrationEncryptSettingValue()` throws a hard `RuntimeException` if `APP_ENCRYPTION_KEY` is missing or invalid — credentials will not be stored in plaintext. In non-production environments it falls back to plaintext with a warning log, so local setups without a key still function. Set `APP_ENCRYPTION_KEY` before enabling this module on any production tenant.

Outbound rate limiting is manifest-configurable via `max_requests_per_minute` (default `60`) and `burst_limit` (default `20`). `MoodleService` enforces both limits on every outbound call: an in-process burst counter aborts the job if a single sync run exceeds the burst cap, and a DB-backed per-minute window counter (`moodle_rate_limit`) aborts individual calls that exceed the per-minute tenant budget.

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

The service enforces two-level outbound throttling on every call: a per-job burst cap (in-process counter) and a per-tenant per-minute window cap stored in `moodle_rate_limit`. Calls that exceed either limit return a synthetic `{ok: false, http_code: 429}` immediately rather than hitting Moodle.

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

The ApplicationOS side records every issued token in `moodle_sso_tokens` with a one-minute expiry and a first-use timestamp slot. Consume-once is enforced atomically by the helper (`UPDATE ... WHERE used_at IS NULL` rowCount check). The Moodle-side plugin calls the dedicated validation endpoint before honoring any token:

```text
POST /api/v1/moodle-integration/sso/validate
```

No kernel authentication is required — the signed token itself is the credential. The handler validates `token` + `tenant_id` from the POST body, atomically consumes the token, then returns user and resource context:

Response shape:

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

Pre-flight drift checks are now in place. `handleEnrollmentSync` verifies the enrollment request is still in `approved` or `auto_approved` state before issuing any Moodle API calls or writing progress rows; mismatches are recorded in `moodle_sync_metrics` and logged. `refreshExistingProgressRows` skips any user whose enrollment request has been `rejected`, `revoked`, or `cancelled`. A full course sync also soft-deactivates any `learning_resources` row whose Moodle course ID was not present in the response set, guarding against stale cache entries after courses are removed or recategorised in Moodle.

**Rate-limit degradation**: when the upstream Moodle API returns HTTP 429, `SyncService` throws a typed `THROTTLED:` exception. Both `syncCourses()` and `syncProgress()` catch this exception with `isThrottleException()` and call `delayQueue()` — which resets the queue row to `pending` with `available_at = NOW() + 60 seconds` — rather than marking the job as failed. This means throttled jobs are transparently re-queued without incrementing the retry counter or loss of enrollment intent. Individual progress rows that hit 429 during `refreshExistingProgressRows` are skipped with a warning log so the rest of the batch continues.

**Concurrency guard in `EnrollmentController`**: `approveEnrollment()` opens with an atomic `UPDATE ... WHERE status IN ('pending_review', 'pending_payment', 'auto_approved') → rowCount check`. A second concurrent approval for the same enrollment returns `{ok: true, already_processed: true}` without double-enrolling into Moodle.

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
- `POST /api/v1/moodle-integration/sso/validate`
- `POST /api/v1/moodle-integration/events` — inbound webhook from Moodle (HMAC-SHA256 signed over raw body using `sso_secret`; maps `provider_id` → `learning_resource_id` and upserts progress)
- `POST /admin/moodle-integration/settings` — admin settings save; encrypts `api_token` and `sso_secret` at rest before persisting

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

Lifecycle soft-deactivation is implemented. If a course disappears from the Moodle API response during a full course sync, `SyncService::deactivateMissingResources()` marks the corresponding `learning_resources` row `inactive`. Historical progress, enrollment, and reporting rows keep their FK references intact. Resources are restored to `active` status automatically the next time `ensureLearningResource` upserts them.

## Current Architecture Gaps

All four originally-open code gaps have been closed as of this release. The documentation below reflects the final state.

### ~~1. Public routes still exposed Moodle course IDs~~ ✅ Resolved

New canonical resource-ID-based routes added alongside the legacy Moodle-ID routes:
- `GET /learning/{rid}`, `GET /learning/{rid}/enroll`, `GET /learning/{rid}/launch` (and `/cms/...` mirrors)
- `POST /api/v1/moodle-integration/learning/{rid}/enroll`

Handlers: `pageMoodleIntegrationCourseByResource`, `pageMoodleIntegrationEnrollByResource`, `pageMoodleIntegrationLaunchByResource`, `apiMoodleIntegrationEnrollByResource`. All resolve via `learning_resources.id` (`rid`) internally using `CourseController::detailByResourceId()` and the `moodleIntegrationMoodleCourseIdByResourceId()` bridge helper. Legacy `/course/{id}/...` routes preserved for backward compatibility.

### ~~2. SSO had no provider-agnostic interface~~ ✅ Resolved

`ProviderAuthAdapterInterface` added in `modules/moodle-integration/services/ProviderAuthAdapterInterface.php` with:
- `buildLaunchUrl(array $user, array $resource): ?string`
- `validateInboundToken(string $token, int $tenantId): ?array`

`SSOService` is no longer `final`; it implements `ProviderAuthAdapterInterface`. `LaunchController` now accepts a `?ProviderAuthAdapterInterface` constructor argument (defaults to `new SSOService()`).

### ~~3. `learning_resources` was a registry, not a catalog~~ ✅ Resolved

Migration `006_moodle_catalog_fields.sql` adds: `description TEXT`, `program VARCHAR(191)`, `difficulty_level VARCHAR(50)`, `duration_minutes INT UNSIGNED`, `tags_json LONGTEXT`, `visibility ENUM('public','enrolled_only','hidden') DEFAULT 'public'`. `SyncService::ensureLearningResource()` now populates `description` and `tags_json` from Moodle API response fields on every sync.

### 4. Webhook delivery is fast-path only; reconciliation is implicit

The `POST /api/v1/moodle-integration/events` handler processes inbound Moodle events immediately but makes no delivery guarantee — a network drop or Moodle outage silently loses the update. The scheduled sync jobs (`moodleIntegrationDispatchScheduledWork` → `SyncCoursesJob` / `SyncProgressJob`) do act as a periodic backstop, but this contract is not explicit anywhere in the code or documentation.

The correct framing — **webhook = fast path, scheduled sync = source of truth / reconciliation** — should be documented and enforced. Practically this means the progress refresh job should be understood as the authoritative sync that would catch anything the webhook missed, not just an optional periodic update.

### ~~5. `course_id` column name in `moodle_user_progress`~~ ✅ Resolved

Migration `007_moodle_progress_rename_course_id.sql` renames `course_id` → `course_cache_id` to clearly distinguish the provider bridge cache reference from the canonical `learning_resource_id`. All PHP helpers (`moodleIntegrationUserProgressRows`, `moodleIntegrationUserCourseProgressRow`), `SyncService`, and `handlers.php` event INSERT updated to use `course_cache_id`.

## Implemented Hardening Steps

Original five items from gap analysis:

1. **SSO validation endpoint** — `POST /api/v1/moodle-integration/sso/validate` is live.
2. **Provider capability registry** — `learning_providers` table with `capabilities_json`; helper surface via `moodleIntegrationProviderSupports()` and `moodleIntegrationGetProviderCapabilities()`.
3. **Pre-flight drift checks** — enrollment state verified in `handleEnrollmentSync`; revoked/rejected enrollments skipped in `refreshExistingProgressRows`; drift mismatches recorded in metrics.
4. **Outbound throttling** — `max_requests_per_minute` and `burst_limit` settings enforce two-level backpressure in `MoodleService`; DB-backed via `moodle_rate_limit`.
5. **Soft-deactivation lifecycle** — courses that disappear from Moodle API responses are automatically marked `inactive` in `learning_resources`; restored to `active` on re-sync.

Second-round hardening (P0/P1/P2/P3):

6. **Queue idempotency** (migration 005) — `idempotency_key` column on `moodle_sync_queue`; `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` in `moodleIntegrationQueueTableInsertForTenant`; `EnrollmentController` stamps `enroll:{tenant}:{user}:{course}` key. Double-submit returns the original queue row ID.
7. **Concurrent approval guard** — `EnrollmentController::approveEnrollment()` uses an atomic UPDATE with row-count check before any Moodle API call; second concurrent approval returns `{already_processed: true}` without a duplicate enrollment.
8. **Secrets encryption at rest** — `api_token` and `sso_secret` encrypted via `moodleIntegrationEncryptSettingValue()` (AES-256-GCM, kernel Crypto) before persistence; `getSettingsForTenant` transparently decrypts on read. `postMoodleIntegrationSettings` handler encrypts on save.
9. **`learning_resource_id` canonical in progress** (migration 005) — `moodle_user_progress.learning_resource_id` column added; SyncService populates it on every write path; `upsertUserProgress` signature updated to require it.
10. **429 rate-limit degradation in SyncService** — `syncCourses`, `handleEnrollmentSync`, and `refreshExistingProgressRows` throw typed `THROTTLED:` exceptions on 429; catch blocks call `delayQueue()` (re-queues with 60 s delay) instead of failing; per-row throttle skip in progress refresh batch.
11. **Inbound events webhook** — `POST /api/v1/moodle-integration/events` handler with HMAC-SHA256 signature verification, `provider_id → learning_resource_id` resolution, local user lookup by email, and progress upsert.
12. **Settings save handler** — `POST /admin/moodle-integration/settings` handler with CSRF enforcement, secret field encryption, and manifest-backed persistence via `saveTenantModuleSettingsForTenant`.

## Remaining Follow-up Items

Items are ordered by architectural impact.

1. ~~**Make `learning_resource_id` the public route identifier.**~~ ✅ Done — new `/learning/{rid}/...` canonical routes added alongside legacy routes.

2. ~~**Extract `ProviderAuthAdapterInterface`.**~~ ✅ Done — `ProviderAuthAdapterInterface` created; `SSOService` implements it; `LaunchController` accepts adapter injection.

3. ~~**Promote catalog fields on `learning_resources`.**~~ ✅ Done — migration 006 adds catalog columns; `SyncService` populates them.

4. ~~**Make the reconciliation contract explicit.**~~ ✅ Done — see **Reconciliation Contract** section below.

5. ~~**Retire `course_id` column name in `moodle_user_progress`.**~~ ✅ Done — migration 007 renames to `course_cache_id`; all PHP queries updated.

## Reconciliation Contract

This section codifies the data-correctness guarantee for learning data under imperfect conditions.

### Rule

> **Webhook = fast path. Scheduled sync + targeted refresh = authoritative reconciliation layer.**

The `POST /api/v1/moodle-integration/events` handler processes inbound Moodle progress events immediately. This keeps the UI fresh under normal conditions. However, it carries no delivery guarantee: a network drop or Moodle outage silently drops the update.

The scheduled sync jobs (`SyncCoursesJob`, `SyncProgressJob`) are the source of truth. They will catch anything the webhook missed. Correctness of the system does not depend on webhook delivery.

### Staleness SLA

Progress data older than `staleness_threshold_minutes` (default: 60 minutes) is considered stale. The threshold is configurable per tenant via the module settings UI.

### Observability

`moodle_sync_metrics` now carries two success-only timestamps:

- `last_full_sync_at` — updated only when a `courses` sync completes successfully
- `last_progress_sync_at` — updated only when a `progress_*` sync completes successfully

Unlike `last_run` (which is set on every attempt), these columns only advance on success. Use `moodleIntegrationSyncFreshnessForTenant()` to read both values with computed `minutes_since_*` and `*_stale` flags. The admin dashboard exposes this via the `sync_freshness` context key.

### Targeted Reconciliation

When a learner opens `/my-courses` or `/learning/{rid}`, the system checks whether their cached progress exceeds the staleness threshold. If stale, a `targeted_refresh` queue job is dispatched immediately — before the page renders. The page shows cached data; the worker picks up the job and the next page load shows fresh data.

Two helper entry points:
- `moodleIntegrationMaybeDispatchUserProgressSync($tenantId, $userId, $resourceId, $thresholdMinutes)` — single resource
- `moodleIntegrationMaybeDispatchStaleProgressForUser($tenantId, $userId, $thresholdMinutes)` — all stale rows for a user (capped at 10 per call, oldest-first)

Both use per-minute idempotency keys so rapid page reloads don't flood the queue.

### Discrepancy Audit

`moodle_sync_discrepancies` records rows when the scheduled sync or targeted refresh finds a meaningful difference (≥ 1% progress delta or status change) compared to what is currently cached. This is populated by `SyncService::recordDiscrepancyIfChanged()` during `refreshExistingProgressRows()` and `handleTargetedProgressRefresh()`. It is best-effort (never blocks the write path) and is intended for debugging data integrity issues and building institutional trust in the system.

### Correctness Invariant

The system must behave correctly even if the webhook is completely disabled. If that statement is not true, the reconciliation contract is broken. Scheduled batch sync + targeted on-demand refresh together guarantee eventual consistency independent of webhook delivery.

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
