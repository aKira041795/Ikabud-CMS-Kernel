# Moodle Integration Module

## Purpose

`moodle-integration` turns Moodle into a plug-in learning engine for the Ikabud CMS/Kernel stack without modifying Moodle core. The module owns discovery, enrollment handoff, cached dashboards, and SSO launch. Moodle remains the system that delivers lessons, assessments, and grades.

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
        └── 002_moodle_enrollment_requests.sql

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
- `owns_tables`: `moodle_courses_cache`, `moodle_user_progress`, `moodle_sync_queue`, `moodle_enrollment_requests`
- `settings_fields`: `moodle_url`, `api_token`, `sso_secret`, `tenant_mode`, `sync_interval`, `shared_category_map_json`
- `hooks`: CMS admin nav, editor block types, builder renderers, CMS public rendered-content filter for shortcodes
- `schedules`: one dispatcher handler registered for 15-minute, hourly, and daily frequencies; the handler filters itself against the saved `sync_interval`

## Database Schema

### `moodle_courses_cache`

- local read model for public course discovery
- unique per `tenant_id + moodle_course_id`
- stores title, summary, image, and sync timestamps

### `moodle_user_progress`

- local progress/grade read model keyed by `tenant_id + user_id + course_id`
- stores normalized `progress_percent`, `grade`, `status`, and `last_synced`

### `moodle_sync_queue`

- module-owned sync ledger and retry state
- stores `tenant_id`, operation `type`, request `payload_json`, `status`, `retries`, `last_error`, and processing timestamps
- complements the generic `kernel_jobs` queue rather than replacing it

### `moodle_enrollment_requests`

- learner-facing review workflow keyed by `tenant_id + user_id + moodle_course_id`
- stores `pending_review`, `approved`, `rejected`, and `revoked` states separately from sync execution
- tracks reviewer identity, review notes, timestamps, and the last related sync queue row

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

The current generic admin renderer does not support masked secret inputs, so `api_token` and `sso_secret` are stored as regular manifest-backed fields for now.

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

It then redirects to a Moodle-side endpoint such as:

```text
https://lms.example.com/local/applicationos/sso.php?token=...&course=123
```

This assumes a Moodle-side local/auth plugin or equivalent endpoint. Moodle core is not modified.

### `SyncService`

Handles:

- course cache refresh
- approval-triggered Moodle enrollment and progress sync
- scheduled refresh of existing progress rows
- queue state updates inside `moodle_sync_queue`
- tenant-explicit DB access through `app()->dbForTenant($tenantId)` when running in queued jobs

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
- `[moodle-course-detail course_id="123"]`
- `[moodle-my-courses title="My Learning"]`
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
2. Signed-in learners submit a `pending_review` request instead of direct Moodle enrollment
3. Admin reviews the request from `/admin/moodle-integration` and approves, rejects, or later revokes it
4. Approval triggers Moodle enrollment sync and creates local progress rows when Moodle confirms access
5. Only after access is ready does `LaunchController` build the signed SSO launch URL
6. Browser is redirected to the Moodle-side SSO endpoint and Moodle validates the token

## Failure Handling

- blank config: module is inactive, cached pages still render
- Moodle offline during public page render: cache-only output remains available
- approval-provisioning failure: the request remains reviewed, the queue row captures the failure, and the learner sees the failed provisioning state locally
- queued sync failure: queue row moves to failed state with `last_error`, and `kernel_jobs` retry semantics still apply

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
