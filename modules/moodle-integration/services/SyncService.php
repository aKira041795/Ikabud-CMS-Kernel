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
        $this->markQueue($db, $tenantId, $queueId, 'processing');

        try {
            $service = new MoodleService($tenantId);
            if (!$service->isConfigured()) {
                $this->markQueue($db, $tenantId, $queueId, 'completed');
                return;
            }

            $result = $service->getCourses();
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['error'] ?? 'Course sync failed.'));
            }

            foreach ((array)($result['courses'] ?? []) as $course) {
                if (!is_array($course)) {
                    continue;
                }
                $this->upsertCourseCache($db, $tenantId, $course);
            }

            $this->markQueue($db, $tenantId, $queueId, 'completed');
        } catch (Throwable $e) {
            $this->markQueue($db, $tenantId, $queueId, 'failed', $e->getMessage());
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
            } else {
                $this->refreshExistingProgressRows($db, $service, $tenantId);
            }

            $this->markQueue($db, $tenantId, $queueId, 'completed');
        } catch (Throwable $e) {
            $this->markQueue($db, $tenantId, $queueId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function handleEnrollmentSync(PDO $db, MoodleService $service, int $tenantId, array $payload): void
    {
        $localUser = $this->loadLocalUser($db, (int)($payload['user_id'] ?? 0));
        if ($localUser === null) {
            throw new RuntimeException('Local user not found for Moodle enrollment.');
        }

        $courseResult = $service->getCourseById((int)($payload['moodle_course_id'] ?? 0));
        if (empty($courseResult['ok']) || !is_array($courseResult['course'] ?? null)) {
            throw new RuntimeException((string)($courseResult['error'] ?? 'Unable to fetch Moodle course before enrollment.'));
        }

        $courseRowId = $this->upsertCourseCache($db, $tenantId, $courseResult['course']);
        $moodleUser = $service->resolveOrCreateMoodleUser($localUser);
        if (empty($moodleUser['ok']) || !is_array($moodleUser['user'] ?? null)) {
            throw new RuntimeException((string)($moodleUser['error'] ?? 'Unable to create Moodle user.'));
        }

        $moodleUserId = (int)($moodleUser['user']['id'] ?? 0);
        $moodleCourseId = (int)($courseResult['course']['id'] ?? 0);

        $enrollResult = $service->enrollUser($moodleUserId, $moodleCourseId);
        if (empty($enrollResult['ok'])) {
            throw new RuntimeException((string)($enrollResult['error'] ?? 'Moodle enrollment failed.'));
        }

        $grades = $service->getUserGrades($moodleUserId, $moodleCourseId);
        $progress = $service->getUserProgress($moodleUserId, $moodleCourseId);
        $this->upsertUserProgress(
            $db,
            $tenantId,
            (int)$localUser['id'],
            $courseRowId,
            $this->normalizeProgressPercent($progress['progress'] ?? []),
            $this->normalizeGrade($grades['grades'] ?? []),
            $this->normalizeStatus($progress['progress'] ?? [])
        );
    }

    private function refreshExistingProgressRows(PDO $db, MoodleService $service, int $tenantId): void
    {
        $stmt = $db->prepare(
            'SELECT p.user_id, p.course_id, c.moodle_course_id
             FROM moodle_user_progress p
             JOIN moodle_courses_cache c ON c.id = p.course_id AND c.tenant_id = p.tenant_id
             WHERE p.tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $localUser = $this->loadLocalUser($db, (int)($row['user_id'] ?? 0));
            if ($localUser === null) {
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

            $this->upsertUserProgress(
                $db,
                $tenantId,
                (int)($row['user_id'] ?? 0),
                (int)($row['course_id'] ?? 0),
                $this->normalizeProgressPercent($progress['progress'] ?? []),
                $this->normalizeGrade($grades['grades'] ?? []),
                $this->normalizeStatus($progress['progress'] ?? [])
            );
        }
    }

    private function upsertCourseCache(PDO $db, int $tenantId, array $course): int
    {
        $stmt = $db->prepare(
            'INSERT INTO moodle_courses_cache (tenant_id, moodle_course_id, title, summary, image, updated_at, created_at)
             VALUES (:tenant_id, :moodle_course_id, :title, :summary, :image, NOW(), NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), image = VALUES(image), updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':moodle_course_id' => (int)($course['id'] ?? 0),
            ':title' => trim((string)($course['fullname'] ?? $course['displayname'] ?? $course['shortname'] ?? 'Untitled Course')),
            ':summary' => (string)($course['summary'] ?? ''),
            ':image' => (string)($course['courseimage'] ?? $course['overviewfiles'][0]['fileurl'] ?? ''),
        ]);

        $lookup = $db->prepare('SELECT id FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND moodle_course_id = :moodle_course_id LIMIT 1');
        $lookup->execute([
            ':tenant_id' => $tenantId,
            ':moodle_course_id' => (int)($course['id'] ?? 0),
        ]);
        return (int)($lookup->fetchColumn() ?: 0);
    }

    private function upsertUserProgress(PDO $db, int $tenantId, int $userId, int $courseId, float $progressPercent, ?float $grade, string $status): void
    {
        $stmt = $db->prepare(
            'INSERT INTO moodle_user_progress (tenant_id, user_id, course_id, progress_percent, grade, status, last_synced, created_at, updated_at)
             VALUES (:tenant_id, :user_id, :course_id, :progress_percent, :grade, :status, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE progress_percent = VALUES(progress_percent), grade = VALUES(grade), status = VALUES(status), last_synced = NOW(), updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':course_id' => $courseId,
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