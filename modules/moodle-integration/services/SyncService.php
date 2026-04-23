<?php

declare(strict_types=1);

namespace MoodleIntegration\Services;

use PDO;
use RuntimeException;
use Throwable;

final class SyncService
{
    private const MODULE_ID = 'moodle-integration';

    public function syncCourses(array $payload): void
    {
        $tenantId = $this->resolveTenantId($payload);
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant context is required for Moodle course sync.');
        }

        $queueId = (int)($payload['sync_queue_id'] ?? 0);
        $db = $this->tenantDb($tenantId);
        $startedAt = microtime(true);
        $this->markQueue($db, $tenantId, $queueId, 'processing');

        try {
            $service = new MoodleService($tenantId);
            if (!$service->isConfigured()) {
                $this->markQueue($db, $tenantId, $queueId, 'completed');
                return;
            }

            $result = $service->getCourses();
            if (empty($result['ok'])) {
                if ((int)($result['http_code'] ?? 0) === 429) {
                    throw new RuntimeException('THROTTLED:' . (string)($result['error'] ?? 'Rate limited fetching courses'));
                }
                throw new RuntimeException((string)($result['error'] ?? 'Course sync failed.'));
            }

            $syncedMoodleCourseIds = [];
            foreach ((array)($result['courses'] ?? []) as $course) {
                if (!is_array($course)) {
                    continue;
                }
                $this->upsertCourseCache($db, $tenantId, $course);
                $syncedMoodleCourseIds[] = (int)($course['id'] ?? 0);
            }

            // Soft-deactivate learning resources for Moodle courses no longer returned by the API.
            $this->deactivateMissingResources($db, $tenantId, $syncedMoodleCourseIds);

            $this->markQueue($db, $tenantId, $queueId, 'completed');
            $this->recordMetric($db, $tenantId, 'courses', true, $startedAt);
        } catch (Throwable $e) {
            if ($this->isThrottleException($e)) {
                $this->delayQueue($db, $tenantId, $queueId, 60);
                $this->recordMetric($db, $tenantId, 'courses', false, $startedAt, 'Rate limited; re-queued with 60s delay');
                return;
            }
            $this->markQueue($db, $tenantId, $queueId, 'failed', $e->getMessage());
            $this->recordMetric($db, $tenantId, 'courses', false, $startedAt, $e->getMessage());
            throw $e;
        }
    }

    public function syncProgress(array $payload): void
    {
        $tenantId = $this->resolveTenantId($payload);
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant context is required for Moodle progress sync.');
        }

        $queueId = (int)($payload['sync_queue_id'] ?? 0);
        $db = $this->tenantDb($tenantId);
        $startedAt = microtime(true);
        $syncType = trim((string)($payload['action'] ?? 'refresh')) === 'enroll' ? 'progress_enroll' : 'progress_refresh';
        $this->markQueue($db, $tenantId, $queueId, 'processing');

        try {
            $service = new MoodleService($tenantId);
            if (!$service->isConfigured()) {
                $this->markQueue($db, $tenantId, $queueId, 'completed');
                return;
            }

            $action = trim((string)($payload['action'] ?? 'refresh'));
            if ($action === 'enroll') {
                $this->handleEnrollmentSync($db, $service, $tenantId, $payload);
            } elseif ($action === 'targeted_refresh') {
                // Reconciliation: refresh progress for one specific user + course.
                // Dispatched when a learner's progress data exceeds the staleness threshold.
                $this->handleTargetedProgressRefresh($db, $service, $tenantId, $payload);
            } else {
                $this->refreshExistingProgressRows($db, $service, $tenantId);
            }

            $this->markQueue($db, $tenantId, $queueId, 'completed');
            $this->recordMetric($db, $tenantId, $syncType, true, $startedAt);
        } catch (Throwable $e) {
            if ($this->isThrottleException($e)) {
                $this->delayQueue($db, $tenantId, $queueId, 60);
                $this->recordMetric($db, $tenantId, $syncType, false, $startedAt, 'Rate limited; re-queued with 60s delay');
                return;
            }
            $this->markQueue($db, $tenantId, $queueId, 'failed', $e->getMessage());
            $this->recordMetric($db, $tenantId, $syncType, false, $startedAt, $e->getMessage());
            throw $e;
        }
    }

    private function handleEnrollmentSync(PDO $db, MoodleService $service, int $tenantId, array $payload): void
    {
        $userId = (int)($payload['user_id'] ?? 0);
        $moodleCourseId = (int)($payload['moodle_course_id'] ?? 0);

        // Pre-flight: verify the enrollment request is still in an approved state before
        // writing any progress data or touching the Moodle LMS.
        if ($userId > 0 && $moodleCourseId > 0) {
            $reqStmt = $db->prepare(
                'SELECT status FROM moodle_enrollment_requests WHERE tenant_id = :tenant_id AND user_id = :user_id AND moodle_course_id = :moodle_course_id LIMIT 1'
            );
            $reqStmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':moodle_course_id' => $moodleCourseId]);
            $reqStatus = $reqStmt->fetchColumn();

            if ($reqStatus !== false && !in_array((string)$reqStatus, ['approved', 'auto_approved'], true)) {
                $this->recordMetric(
                    $db,
                    $tenantId,
                    'enrollment_drift',
                    false,
                    microtime(true),
                    "Enrollment request status '{$reqStatus}' is not approved; skipping Moodle enrollment sync for user {$userId} course {$moodleCourseId}"
                );
                \write_log("moodle-integration: skipping enrollment sync — enrollment request status is '{$reqStatus}' for user {$userId} course {$moodleCourseId} tenant {$tenantId}", 'warning');
                return;
            }
        }

        $localUser = $this->loadLocalUser($db, $userId);
        if ($localUser === null) {
            throw new RuntimeException('Local user not found for Moodle enrollment.');
        }

        $courseResult = $service->getCourseById((int)($payload['moodle_course_id'] ?? 0));
        if (empty($courseResult['ok']) || !is_array($courseResult['course'] ?? null)) {
            if ((int)($courseResult['http_code'] ?? 0) === 429) {
                throw new RuntimeException('THROTTLED:' . (string)($courseResult['error'] ?? 'Rate limited fetching course'));
            }
            throw new RuntimeException((string)($courseResult['error'] ?? 'Unable to fetch Moodle course before enrollment.'));
        }

        $cacheResult = $this->upsertCourseCache($db, $tenantId, $courseResult['course']);
        $courseRowId = $cacheResult['cache_id'];
        $learningResourceId = (int)($payload['learning_resource_id'] ?? $cacheResult['resource_id']);

        $moodleUser = $service->resolveOrCreateMoodleUser($localUser);
        if (empty($moodleUser['ok']) || !is_array($moodleUser['user'] ?? null)) {
            if ((int)($moodleUser['http_code'] ?? 0) === 429) {
                throw new RuntimeException('THROTTLED:' . (string)($moodleUser['error'] ?? 'Rate limited resolving Moodle user'));
            }
            throw new RuntimeException((string)($moodleUser['error'] ?? 'Unable to create Moodle user.'));
        }

        $moodleUserId = (int)($moodleUser['user']['id'] ?? 0);
        $moodleCourseId = (int)($courseResult['course']['id'] ?? 0);

        $enrollResult = $service->enrollUser($moodleUserId, $moodleCourseId);
        if (empty($enrollResult['ok'])) {
            if ((int)($enrollResult['http_code'] ?? 0) === 429) {
                throw new RuntimeException('THROTTLED:' . (string)($enrollResult['error'] ?? 'Rate limited enrolling user'));
            }
            throw new RuntimeException((string)($enrollResult['error'] ?? 'Moodle enrollment failed.'));
        }

        $grades = $service->getUserGrades($moodleUserId, $moodleCourseId);
        $progress = $service->getUserProgress($moodleUserId, $moodleCourseId);
        $this->upsertUserProgress(
            $db,
            $tenantId,
            (int)$localUser['id'],
            $courseRowId,
            $learningResourceId,
            $this->normalizeProgressPercent($progress['progress'] ?? []),
            $this->normalizeGrade($grades['grades'] ?? []),
            $this->normalizeStatus($progress['progress'] ?? [])
        );
    }

    private function refreshExistingProgressRows(PDO $db, MoodleService $service, int $tenantId): void
    {
        $stmt = $db->prepare(
            'SELECT p.user_id, p.course_cache_id, c.moodle_course_id, c.resource_id AS learning_resource_id
             FROM moodle_user_progress p
             JOIN moodle_courses_cache c ON c.id = p.course_cache_id AND c.tenant_id = p.tenant_id
             WHERE p.tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $localUser = $this->loadLocalUser($db, (int)($row['user_id'] ?? 0));
            if ($localUser === null) {
                continue;
            }

            // Skip progress refresh for users whose enrollment has been explicitly revoked or rejected.
            $enrollmentStatus = $this->getEnrollmentRequestStatus($db, $tenantId, (int)$row['user_id'], (int)$row['moodle_course_id']);
            if ($enrollmentStatus !== null && in_array($enrollmentStatus, ['rejected', 'revoked', 'cancelled'], true)) {
                continue;
            }

            $moodleUser = $service->resolveOrCreateMoodleUser($localUser);
            if (empty($moodleUser['ok']) || !is_array($moodleUser['user'] ?? null)) {
                continue;
            }

            $moodleUserId = (int)($moodleUser['user']['id'] ?? 0);
            $moodleCourseId = (int)($row['moodle_course_id'] ?? 0);
            if ($moodleUserId <= 0 || $moodleCourseId <= 0) {
                continue;
            }

            $grades = $service->getUserGrades($moodleUserId, $moodleCourseId);
            $progress = $service->getUserProgress($moodleUserId, $moodleCourseId);

            // Partial sync: if we're throttled mid-batch, skip this user and continue with the rest.
            if ((int)($grades['http_code'] ?? 0) === 429 || (int)($progress['http_code'] ?? 0) === 429) {
                \write_log("moodle-integration: throttled during progress refresh for user {$moodleUserId} course {$moodleCourseId}; skipping row", 'warning');
                continue;
            }

            $newProgressPercent = $this->normalizeProgressPercent($progress['progress'] ?? []);
            $newStatus = $this->normalizeStatus($progress['progress'] ?? []);

            // Reconciliation audit: record discrepancy if scheduled sync finds different
            // data than what the webhook last wrote. Webhook = fast path; this batch is truth.
            $resourceId = (int)($row['learning_resource_id'] ?? 0);
            if ($resourceId > 0) {
                $this->recordDiscrepancyIfChanged($db, $tenantId, (int)($row['user_id'] ?? 0), $resourceId, $newProgressPercent, $newStatus);
            }

            $this->upsertUserProgress(
                $db,
                $tenantId,
                (int)($row['user_id'] ?? 0),
                (int)($row['course_cache_id'] ?? 0),
                (int)($row['learning_resource_id'] ?? 0),
                $newProgressPercent,
                $this->normalizeGrade($grades['grades'] ?? []),
                $newStatus
            );
        }
    }

    private function upsertCourseCache(PDO $db, int $tenantId, array $course): array
    {
        $courseId = (int)($course['id'] ?? 0);
        $title = trim((string)($course['fullname'] ?? $course['displayname'] ?? $course['shortname'] ?? 'Untitled Course'));
        $categoryId = (int)($course['categoryid'] ?? 0);
        $categoryKey = $this->normalizeCategoryKey((string)($course['categoryname'] ?? $course['category'] ?? ($categoryId > 0 ? 'category-' . $categoryId : '')));
        $resourceId = $this->ensureLearningResource($db, $tenantId, $courseId, $title, [
            'moodle_course_id' => $courseId,
            'moodle_category_id' => $categoryId > 0 ? $categoryId : null,
            'moodle_category_key' => $categoryKey !== '' ? $categoryKey : null,
            'shortname' => (string)($course['shortname'] ?? ''),
        ], [
            'description' => (string)($course['summary'] ?? ''),
            'tags_json' => isset($course['customfields']) ? json_encode($course['customfields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
        $stmt = $db->prepare(
            'INSERT INTO moodle_courses_cache (tenant_id, resource_id, moodle_course_id, moodle_category_id, moodle_category_key, title, summary, image, updated_at, created_at)
             VALUES (:tenant_id, :resource_id, :moodle_course_id, :moodle_category_id, :moodle_category_key, :title, :summary, :image, NOW(), NOW())
             ON DUPLICATE KEY UPDATE resource_id = VALUES(resource_id), moodle_category_id = VALUES(moodle_category_id), moodle_category_key = VALUES(moodle_category_key), title = VALUES(title), summary = VALUES(summary), image = VALUES(image), updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':resource_id' => $resourceId > 0 ? $resourceId : null,
            ':moodle_course_id' => $courseId,
            ':moodle_category_id' => $categoryId > 0 ? $categoryId : null,
            ':moodle_category_key' => $categoryKey !== '' ? $categoryKey : null,
            ':title' => $title,
            ':summary' => (string)($course['summary'] ?? ''),
            ':image' => (string)($course['courseimage'] ?? $course['overviewfiles'][0]['fileurl'] ?? ''),
        ]);

        $lookup = $db->prepare('SELECT id FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND moodle_course_id = :moodle_course_id LIMIT 1');
        $lookup->execute([
            ':tenant_id' => $tenantId,
            ':moodle_course_id' => (int)($course['id'] ?? 0),
        ]);
        return ['cache_id' => (int)($lookup->fetchColumn() ?: 0), 'resource_id' => $resourceId];
    }

    private function ensureLearningResource(PDO $db, int $tenantId, int $moodleCourseId, string $title, array $metadata, array $catalog = []): int
    {
        if ($tenantId <= 0 || $moodleCourseId <= 0) {
            return 0;
        }

        $description = isset($catalog['description']) ? (string)$catalog['description'] : null;
        $tagsJson = isset($catalog['tags_json']) ? (string)$catalog['tags_json'] : null;

        $stmt = $db->prepare(
            'INSERT INTO learning_resources (tenant_id, provider, provider_id, title, description, tags_json, metadata_json, created_at, updated_at)
             VALUES (:tenant_id, :provider, :provider_id, :title, :description, :tags_json, :metadata_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), tags_json = VALUES(tags_json), metadata_json = VALUES(metadata_json), updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider' => 'moodle',
            ':provider_id' => (string)$moodleCourseId,
            ':title' => $title,
            ':description' => $description !== '' ? $description : null,
            ':tags_json' => $tagsJson !== '' ? $tagsJson : null,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $lookup = $db->prepare('SELECT id FROM learning_resources WHERE tenant_id = :tenant_id AND provider = :provider AND provider_id = :provider_id LIMIT 1');
        $lookup->execute([
            ':tenant_id' => $tenantId,
            ':provider' => 'moodle',
            ':provider_id' => (string)$moodleCourseId,
        ]);

        return (int)($lookup->fetchColumn() ?: 0);
    }

    private function isThrottleException(Throwable $e): bool
    {
        return str_starts_with($e->getMessage(), 'THROTTLED:');
    }

    /**
     * Re-queue a sync job to run after a delay by resetting its status to 'pending'
     * and advancing available_at. Used when the upstream Moodle API rate-limits us:
     * the job is not failed, just deferred, so retries and error counts are not incremented.
     */
    private function delayQueue(PDO $db, int $tenantId, int $queueId, int $delaySeconds = 60): void
    {
        if ($queueId <= 0) {
            return;
        }

        $stmt = $db->prepare(
            'UPDATE moodle_sync_queue
             SET status = \'pending\',
                 available_at = NOW() + INTERVAL :delay_seconds SECOND,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':delay_seconds' => $delaySeconds, ':tenant_id' => $tenantId, ':id' => $queueId]);
        \write_log("moodle-integration: throttled — re-queued job {$queueId} with {$delaySeconds}s delay for tenant {$tenantId}", 'info');
    }

    private function deactivateMissingResources(PDO $db, int $tenantId, array $activeMoodleCourseIds): void
    {
        // Guard: if the sync returned zero courses (e.g. empty Moodle site or category filter
        // produced no results), we skip deactivation to avoid wiping all resources due to a
        // transient API condition.
        if ($activeMoodleCourseIds === []) {
            return;
        }

        $stmt = $db->prepare(
            'SELECT lr.id AS resource_id, c.moodle_course_id
             FROM learning_resources lr
             LEFT JOIN moodle_courses_cache c ON c.resource_id = lr.id AND c.tenant_id = lr.tenant_id
             WHERE lr.tenant_id = :tenant_id AND lr.provider = :provider AND lr.status = :status'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':provider' => 'moodle', ':status' => 'active']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $deactivated = 0;
        foreach ($rows as $row) {
            $moodleCourseId = (int)($row['moodle_course_id'] ?? 0);
            if ($moodleCourseId <= 0 || in_array($moodleCourseId, $activeMoodleCourseIds, true)) {
                continue;
            }
            $update = $db->prepare(
                'UPDATE learning_resources SET status = \'inactive\', updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND status = \'active\''
            );
            $update->execute([':id' => (int)$row['resource_id'], ':tenant_id' => $tenantId]);
            if ($update->rowCount() > 0) {
                $deactivated++;
            }
        }

        if ($deactivated > 0) {
            \write_log("moodle-integration: deactivated {$deactivated} learning_resources no longer present in Moodle for tenant {$tenantId}", 'info');
        }
    }

    private function getEnrollmentRequestStatus(PDO $db, int $tenantId, int $userId, int $moodleCourseId): ?string
    {
        if ($tenantId <= 0 || $userId <= 0 || $moodleCourseId <= 0) {
            return null;
        }

        try {
            $stmt = $db->prepare('SELECT status FROM moodle_enrollment_requests WHERE tenant_id = :tenant_id AND user_id = :user_id AND moodle_course_id = :moodle_course_id LIMIT 1');
            $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':moodle_course_id' => $moodleCourseId]);
            $status = $stmt->fetchColumn();
            return $status !== false ? (string)$status : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function normalizeCategoryKey(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function recordMetric(PDO $db, int $tenantId, string $syncType, bool $successful, float $startedAt, ?string $error = null): void
    {
        if ($tenantId <= 0 || $syncType === '') {
            return;
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $now = date('Y-m-d H:i:s');

        // last_full_sync_at advances only on a successful course sync.
        // last_progress_sync_at advances only on a successful progress sync.
        // Using COALESCE in ON DUPLICATE KEY ensures we never overwrite a real timestamp with NULL.
        $lastFullSyncAt = ($successful && $syncType === 'courses') ? $now : null;
        $lastProgressSyncAt = ($successful && str_starts_with($syncType, 'progress')) ? $now : null;

        $stmt = $db->prepare(
            'INSERT INTO moodle_sync_metrics (tenant_id, sync_type, success_count, failure_count, avg_duration_ms, last_run, last_full_sync_at, last_progress_sync_at, last_error, created_at, updated_at)
             VALUES (:tenant_id, :sync_type, :success_count, :failure_count, :avg_duration_ms, NOW(), :last_full_sync_at, :last_progress_sync_at, :last_error, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 avg_duration_ms = ROUND((((success_count + failure_count) * avg_duration_ms) + VALUES(avg_duration_ms)) / (success_count + failure_count + 1), 2),
                 success_count = success_count + VALUES(success_count),
                 failure_count = failure_count + VALUES(failure_count),
                 last_run = VALUES(last_run),
                 last_full_sync_at = COALESCE(VALUES(last_full_sync_at), last_full_sync_at),
                 last_progress_sync_at = COALESCE(VALUES(last_progress_sync_at), last_progress_sync_at),
                 last_error = VALUES(last_error),
                 updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':sync_type' => $syncType,
            ':success_count' => $successful ? 1 : 0,
            ':failure_count' => $successful ? 0 : 1,
            ':avg_duration_ms' => $durationMs,
            ':last_full_sync_at' => $lastFullSyncAt,
            ':last_progress_sync_at' => $lastProgressSyncAt,
            ':last_error' => $successful ? null : substr((string)$error, 0, 4000),
        ]);
    }

    /**
     * Refresh progress for a single user + course pair.
     * Used by targeted reconciliation: when a learner opens /my-courses or /learning/{rid}
     * and their cached progress is older than staleness_threshold_minutes, a queue job
     * is dispatched with action=targeted_refresh to run this path instead of the full batch.
     * Webhook = fast path; scheduled sync = authoritative reconciliation.
     */
    private function handleTargetedProgressRefresh(PDO $db, MoodleService $service, int $tenantId, array $payload): void
    {
        $userId = (int)($payload['user_id'] ?? 0);
        $moodleCourseId = (int)($payload['moodle_course_id'] ?? 0);
        $learningResourceId = (int)($payload['learning_resource_id'] ?? 0);

        if ($userId <= 0 || $moodleCourseId <= 0) {
            return;
        }

        // Skip if enrollment is in a terminal non-active state.
        $enrollmentStatus = $this->getEnrollmentRequestStatus($db, $tenantId, $userId, $moodleCourseId);
        if ($enrollmentStatus !== null && in_array($enrollmentStatus, ['rejected', 'revoked', 'cancelled'], true)) {
            return;
        }

        $cacheStmt = $db->prepare('SELECT id FROM moodle_courses_cache WHERE tenant_id = :tid AND moodle_course_id = :cid LIMIT 1');
        $cacheStmt->execute([':tid' => $tenantId, ':cid' => $moodleCourseId]);
        $courseRowId = (int)($cacheStmt->fetchColumn() ?: 0);
        if ($courseRowId <= 0) {
            return;
        }

        $localUser = $this->loadLocalUser($db, $userId);
        if ($localUser === null) {
            return;
        }

        $moodleUser = $service->resolveOrCreateMoodleUser($localUser);
        if (empty($moodleUser['ok']) || !is_array($moodleUser['user'] ?? null)) {
            if ((int)($moodleUser['http_code'] ?? 0) === 429) {
                throw new RuntimeException('THROTTLED:' . (string)($moodleUser['error'] ?? 'Rate limited'));
            }
            return;
        }

        $moodleUserId = (int)($moodleUser['user']['id'] ?? 0);

        $grades = $service->getUserGrades($moodleUserId, $moodleCourseId);
        $progress = $service->getUserProgress($moodleUserId, $moodleCourseId);

        if ((int)($grades['http_code'] ?? 0) === 429 || (int)($progress['http_code'] ?? 0) === 429) {
            throw new RuntimeException('THROTTLED: Rate limited during targeted progress refresh');
        }

        $newProgress = $this->normalizeProgressPercent($progress['progress'] ?? []);
        $newGrade = $this->normalizeGrade($grades['grades'] ?? []);
        $newStatus = $this->normalizeStatus($progress['progress'] ?? []);

        // Record a discrepancy when Moodle data diverges meaningfully from what is cached.
        // This is the concrete enforcement of: webhook = fast path, scheduled sync = truth.
        if ($learningResourceId > 0) {
            $this->recordDiscrepancyIfChanged($db, $tenantId, $userId, $learningResourceId, $newProgress, $newStatus);
        }

        $this->upsertUserProgress($db, $tenantId, $userId, $courseRowId, $learningResourceId, $newProgress, $newGrade, $newStatus);
    }

    /**
     * If the scheduled sync finds meaningfully different progress than what is cached
     * (delta >= 1% or status change), record a discrepancy row for audit and debugging.
     * Non-fatal: errors here never block the sync write path.
     */
    private function recordDiscrepancyIfChanged(PDO $db, int $tenantId, int $userId, int $learningResourceId, float $newProgress, string $newStatus): void
    {
        if ($learningResourceId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare(
                'SELECT progress_percent, status FROM moodle_user_progress
                 WHERE tenant_id = :tid AND user_id = :uid AND learning_resource_id = :rid
                 LIMIT 1'
            );
            $stmt->execute([':tid' => $tenantId, ':uid' => $userId, ':rid' => $learningResourceId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                return; // No cached row yet — nothing to compare against
            }

            $cachedProgress = (float)($existing['progress_percent'] ?? 0);
            $cachedStatus = (string)($existing['status'] ?? '');
            $delta = abs($newProgress - $cachedProgress);

            if ($delta < 1.0 && $cachedStatus === $newStatus) {
                return; // No meaningful change; skip
            }

            $db->prepare(
                'INSERT INTO moodle_sync_discrepancies
                     (tenant_id, user_id, learning_resource_id, source, cached_progress, actual_progress, cached_status, actual_status, delta_percent, detected_at)
                 VALUES (:tid, :uid, :rid, :source, :cached_progress, :actual_progress, :cached_status, :actual_status, :delta, NOW())'
            )->execute([
                ':tid' => $tenantId,
                ':uid' => $userId,
                ':rid' => $learningResourceId,
                ':source' => 'scheduled_sync',
                ':cached_progress' => $cachedProgress,
                ':actual_progress' => $newProgress,
                ':cached_status' => $cachedStatus,
                ':actual_status' => $newStatus,
                ':delta' => round($delta, 2),
            ]);
        } catch (Throwable $e) {
            // Discrepancy tracking is best-effort; never block the sync write path.
        }
    }

    private function upsertUserProgress(PDO $db, int $tenantId, int $userId, int $courseId, int $learningResourceId, float $progressPercent, ?float $grade, string $status): void
    {
        $stmt = $db->prepare(
            'INSERT INTO moodle_user_progress (tenant_id, user_id, learning_resource_id, course_cache_id, progress_percent, grade, status, last_synced, created_at, updated_at)
             VALUES (:tenant_id, :user_id, :learning_resource_id, :course_cache_id, :progress_percent, :grade, :status, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE learning_resource_id = VALUES(learning_resource_id), progress_percent = VALUES(progress_percent), grade = VALUES(grade), status = VALUES(status), last_synced = NOW(), updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':learning_resource_id' => $learningResourceId > 0 ? $learningResourceId : null,
            ':course_cache_id' => $courseId,
            ':progress_percent' => $progressPercent,
            ':grade' => $grade,
            ':status' => $status,
        ]);
    }

    private function markQueue(PDO $db, int $tenantId, int $queueId, string $status, ?string $error = null): void
    {
        if ($queueId <= 0) {
            return;
        }

        $stmt = $db->prepare(
            'UPDATE moodle_sync_queue
             SET status = :status,
                 retries = CASE WHEN :failed_status = "failed" THEN retries + 1 ELSE retries END,
                 last_error = :last_error,
                 processed_at = CASE WHEN :processed_status IN ("completed", "failed") THEN NOW() ELSE processed_at END,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':failed_status' => $status,
            ':processed_status' => $status,
            ':last_error' => $error !== null ? substr($error, 0, 4000) : null,
            ':tenant_id' => $tenantId,
            ':id' => $queueId,
        ]);
    }

    private function loadLocalUser(PDO $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        foreach ([
            'SELECT id, username, email, full_name FROM users WHERE id = :id LIMIT 1',
            'SELECT id, username, email, display_name AS full_name FROM cms_users WHERE id = :id LIMIT 1',
        ] as $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([':id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    return $row;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function resolveTenantId(array $payload): int
    {
        $tenantId = (int)($payload['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            return $tenantId;
        }

        return \moodleIntegrationCurrentTenantId();
    }

    private function tenantDb(int $tenantId): PDO
    {
        $db = \moodleIntegrationTenantDb($tenantId);
        if (!$db instanceof PDO) {
            throw new RuntimeException('Tenant database unavailable for Moodle sync.');
        }

        return $db;
    }

    private function normalizeProgressPercent(mixed $progress): float
    {
        if (is_array($progress) && isset($progress['completions']) && is_array($progress['completions'])) {
            $total = count($progress['completions']);
            if ($total > 0) {
                $complete = 0;
                foreach ($progress['completions'] as $item) {
                    if (is_array($item) && !empty($item['complete'])) {
                        $complete++;
                    }
                }
                return round(($complete / $total) * 100, 2);
            }
        }

        if (is_array($progress) && isset($progress['completed']) && isset($progress['aggregation'])) {
            return !empty($progress['completed']) ? 100.0 : 0.0;
        }

        return 0.0;
    }

    private function normalizeGrade(mixed $grades): ?float
    {
        if (is_array($grades)) {
            if (isset($grades[0]['gradeitems'][0]['graderaw'])) {
                return round((float)$grades[0]['gradeitems'][0]['graderaw'], 2);
            }
            if (isset($grades['grade'])) {
                return round((float)$grades['grade'], 2);
            }
        }

        return null;
    }

    private function normalizeStatus(mixed $progress): string
    {
        if (is_array($progress) && !empty($progress['completed'])) {
            return 'completed';
        }

        if ($this->normalizeProgressPercent($progress) > 0) {
            return 'in_progress';
        }

        return 'not_started';
    }
}