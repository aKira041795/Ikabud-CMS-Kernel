<?php

declare(strict_types=1);

namespace MoodleIntegration\Controllers;

require_once __DIR__ . '/../services/MoodleService.php';
require_once __DIR__ . '/../services/SyncService.php';

use MoodleIntegration\Services\MoodleService;
use MoodleIntegration\Services\SyncService;

final class EnrollmentController
{
    public function enroll(int $moodleCourseId, array $user): array
    {
        if ($moodleCourseId <= 0) {
            return ['ok' => false, 'error' => 'Invalid course id', 'http_code' => 422];
        }

        $course = \moodleIntegrationCachedCourseByMoodleId($moodleCourseId);
        if ($course === null) {
            return ['ok' => false, 'error' => 'Course not found', 'http_code' => 404];
        }

        $existingStatus = \moodleIntegrationCourseStatusPayload((int)($user['id'] ?? 0), $moodleCourseId);
        if (!empty($existingStatus['ready_to_launch'])) {
            return [
                'ok' => true,
                'queued' => false,
                'ready_to_launch' => true,
                'status' => $existingStatus,
            ];
        }

        $tenantId = \moodleIntegrationCurrentTenantId();
        $request = \moodleIntegrationSaveEnrollmentRequestForTenant(
            $tenantId,
            (int)($user['id'] ?? 0),
            $moodleCourseId,
            'pending_review',
            [
                'requested_by_source' => (string)($user['source'] ?? 'kernel'),
                'review_notes' => '',
            ]
        );

        if ($request === null) {
            return ['ok' => false, 'error' => 'Enrollment request could not be created.', 'http_code' => 500];
        }

        return [
            'ok' => true,
            'queued' => false,
            'request_id' => (int)($request['id'] ?? 0),
            'ready_to_launch' => false,
            'status' => \moodleIntegrationCourseStatusPayload((int)($user['id'] ?? 0), $moodleCourseId),
        ];
    }

    public function reviewRequest(int $requestId, string $decision, array $reviewer, string $reviewNotes = ''): array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected', 'revoked'], true)) {
            return ['ok' => false, 'error' => 'Unsupported decision', 'http_code' => 422];
        }

        $tenantId = \moodleIntegrationCurrentTenantId();
        $request = \moodleIntegrationEnrollmentRequestById($requestId, $tenantId);
        if ($request === null) {
            return ['ok' => false, 'error' => 'Enrollment request not found', 'http_code' => 404];
        }

        $userId = (int)($request['user_id'] ?? 0);
        $moodleCourseId = (int)($request['moodle_course_id'] ?? 0);
        $reviewedByUserId = (int)($reviewer['id'] ?? 0);

        if ($decision === 'rejected') {
            $saved = \moodleIntegrationSaveEnrollmentRequestForTenant($tenantId, $userId, $moodleCourseId, 'rejected', [
                'review_notes' => $reviewNotes,
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'sync_queue_id' => 0,
            ]);
            \moodleIntegrationDeleteUserProgressForCourse($tenantId, $userId, $moodleCourseId);
            return ['ok' => $saved !== null, 'request' => $saved];
        }

        if ($decision === 'revoked') {
            $service = new MoodleService($tenantId);
            $localUser = [
                'id' => $userId,
                'email' => (string)($request['learner_email'] ?? ''),
                'username' => (string)($request['learner_email'] !== '' ? ($request['learner_email'] ?? '') : ($request['learner_name'] ?? '')),
                'full_name' => (string)($request['learner_name'] ?? ''),
                'source' => 'cms',
            ];
            $moodleUser = $service->resolveOrCreateMoodleUser($localUser);
            if (empty($moodleUser['ok']) || !is_array($moodleUser['user'] ?? null)) {
                return [
                    'ok' => false,
                    'error' => (string)($moodleUser['error'] ?? 'Unable to resolve the Moodle learner account for revoke.'),
                    'http_code' => (int)($moodleUser['http_code'] ?? 502),
                ];
            }

            $unenrollResult = $service->unenrollUser((int)($moodleUser['user']['id'] ?? 0), $moodleCourseId);
            if (empty($unenrollResult['ok'])) {
                return [
                    'ok' => false,
                    'error' => (string)($unenrollResult['error'] ?? 'Moodle unenrollment failed.'),
                    'http_code' => (int)($unenrollResult['http_code'] ?? 502),
                ];
            }

            \moodleIntegrationDeleteUserProgressForCourse($tenantId, $userId, $moodleCourseId);
            $saved = \moodleIntegrationSaveEnrollmentRequestForTenant($tenantId, $userId, $moodleCourseId, 'revoked', [
                'review_notes' => $reviewNotes,
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'sync_queue_id' => 0,
            ]);

            return ['ok' => $saved !== null, 'request' => $saved];
        }

        $queueId = \moodleIntegrationQueueTableInsertForTenant($tenantId, 'enrollment', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'moodle_course_id' => $moodleCourseId,
            'source' => 'cms',
        ]);
        $saved = \moodleIntegrationSaveEnrollmentRequestForTenant($tenantId, $userId, $moodleCourseId, 'approved', [
            'review_notes' => $reviewNotes,
            'reviewed_by_user_id' => $reviewedByUserId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'sync_queue_id' => $queueId,
        ]);

        $payload = [
            'sync_queue_id' => $queueId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'moodle_course_id' => $moodleCourseId,
            'action' => 'enroll',
            'source' => 'cms',
        ];

        try {
            $sync = new SyncService();
            $sync->syncProgress($payload);
            return [
                'ok' => true,
                'request' => $saved,
                'status' => \moodleIntegrationCourseStatusPayload($userId, $moodleCourseId),
            ];
        } catch (\Throwable $e) {
            $jobId = 0;
            if (function_exists('kernelDispatchJob')) {
                $jobId = (int)\kernelDispatchJob('moodle-integration:moodleIntegrationSyncProgressJob', $payload, 'moodle', 0, 3);
            }

            return [
                'ok' => $jobId > 0,
                'request' => $saved,
                'warning' => $e->getMessage(),
                'job_id' => $jobId,
                'status' => \moodleIntegrationCourseStatusPayload($userId, $moodleCourseId),
                'http_code' => $jobId > 0 ? 200 : 502,
                'error' => $jobId > 0 ? null : $e->getMessage(),
            ];
        }
    }
}