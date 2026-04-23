<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/controllers/CourseController.php';
require_once __DIR__ . '/controllers/EnrollmentController.php';
require_once __DIR__ . '/controllers/LaunchController.php';
require_once __DIR__ . '/jobs/SyncCoursesJob.php';
require_once __DIR__ . '/jobs/SyncProgressJob.php';

function pageMoodleIntegrationAdmin(array $params = []): void
{
    $user = function_exists('cmsRequireCap')
        ? cmsRequireCap('settings.manage')
        : moodleIntegrationRequirePageUser('/admin/moodle-integration');
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $query = moodleIntegrationCurrentQueryParams();
    $noticeCode = trim((string)($query['notice'] ?? ''));
    $adminNotice = match ($noticeCode) {
        'approved' => ['type' => 'success', 'message' => 'Enrollment request approved. Moodle access sync started.'],
        'rejected' => ['type' => 'warning', 'message' => 'Enrollment request rejected.'],
        'revoked' => ['type' => 'warning', 'message' => 'Enrollment access revoked.'],
        'error' => ['type' => 'error', 'message' => 'Enrollment request decision could not be applied.'],
        default => null,
    };

    echo moodleIntegrationRender('pages/admin.disyl', moodleIntegrationAdminPageContext($user, 'Moodle Integration', [
        'settings' => moodleIntegrationGetSettings(),
        'is_configured' => moodleIntegrationIsConfigured(),
        'recent_courses' => $controller->list(10),
        'queue_status' => moodleIntegrationQueueStatusSummary(),
        'request_status' => moodleIntegrationEnrollmentRequestStatusSummary(),
        'pending_requests' => moodleIntegrationAdminEnrollmentRequests(['pending_review'], 25),
        'recent_reviewed_requests' => moodleIntegrationAdminEnrollmentRequests(['approved', 'rejected', 'revoked'], 25),
        'admin_notice' => $adminNotice,
        'managed_pages' => moodleIntegrationManagedCmsPages(),
        'shortcodes' => moodleIntegrationAdminShortcodes(),
    ]));
}

// ---------------------------------------------------------------------------
// Resource-ID-based route handlers (provider-agnostic public identifiers).
// These use learning_resources.id as the URL segment so Moodle course IDs
// never appear in public URLs. The moodle_course_id is resolved internally.
// ---------------------------------------------------------------------------

function pageMoodleIntegrationCourseByResource(array $params = []): void
{
    $resourceId = (int)($params['rid'] ?? 0);
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $course = $resourceId > 0 ? $controller->detailByResourceId($resourceId) : null;
    $currentUser = moodleIntegrationCurrentUser();
    $moodleCourseId = (int)($course['moodle_course_id'] ?? 0);
    $accessState = is_array($currentUser) && !empty($currentUser['id']) && $resourceId > 0
        ? moodleIntegrationLearnerCourseAccessStateByResourceId((int)$currentUser['id'], $resourceId)
        : [
            'launch_ready' => false, 'review_pending' => false, 'request_rejected' => false,
            'request_revoked' => false, 'queue_pending' => false, 'queue_failed' => false,
            'can_queue_enrollment' => false, 'message' => 'Submit this course for review before launching in Moodle.',
        ];

    if ($course === null) {
        http_response_code(404);
    }

    echo moodleIntegrationRenderPublicPage('pages/course-detail.disyl', [
        'page_title' => $course['title'] ?? 'Course Detail',
        'is_configured' => moodleIntegrationIsConfigured(),
        'course' => $course,
        'enroll_url' => moodleIntegrationPath('/cms/learning/' . $resourceId . '/enroll'),
        'is_authenticated' => $currentUser !== null,
        'launch_ready' => (bool)($accessState['launch_ready'] ?? false),
        'review_pending' => (bool)($accessState['review_pending'] ?? false),
        'queue_pending' => (bool)($accessState['queue_pending'] ?? false),
        'queue_failed' => (bool)($accessState['queue_failed'] ?? false),
        'course_access_message' => (string)($accessState['message'] ?? ''),
        'my_learning_url' => moodleIntegrationPath('/cms/page/my-learning'),
    ], [
        'header_title' => $course['title'] ?? 'Course Detail',
    ]);
}

function pageMoodleIntegrationEnrollByResource(array $params = []): void
{
    $resourceId = (int)($params['rid'] ?? 0);
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $course = $resourceId > 0 ? $controller->detailByResourceId($resourceId) : null;
    $currentUser = moodleIntegrationCurrentUser();
    $query = moodleIntegrationCurrentQueryParams();
    $canonicalPath = moodleIntegrationCanonicalPublicPath('/learning/' . $resourceId . '/enroll');
    $accessState = is_array($currentUser) && !empty($currentUser['id']) && $resourceId > 0
        ? moodleIntegrationLearnerCourseAccessStateByResourceId((int)$currentUser['id'], $resourceId)
        : [
            'launch_ready' => false, 'review_pending' => false, 'request_rejected' => false,
            'request_revoked' => false, 'queue_pending' => false, 'queue_failed' => false,
            'can_queue_enrollment' => false, 'message' => 'Submit this course for review before launching in Moodle.',
        ];

    if ($course === null) {
        http_response_code(404);
    }

    if ($currentUser !== null) {
        moodleIntegrationAssignUserService((int)($currentUser['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_enroll_page']);
    }

    echo moodleIntegrationRenderPublicPage('pages/enroll.disyl', [
        'page_title' => $course['title'] ?? 'Enroll',
        'course' => $course,
        'is_authenticated' => $currentUser !== null,
        'authenticated_user_name' => trim((string)($currentUser['name'] ?? $currentUser['display_name'] ?? $currentUser['username'] ?? 'Learner')),
        'authenticated_user_email' => trim((string)($currentUser['email'] ?? '')),
        'show_registered_notice' => ((string)($query['registered'] ?? '')) === '1',
        'show_launch_requires_enrollment_notice' => ((string)($query['launch_requires_enrollment'] ?? '')) === '1',
        'show_review_requested_notice' => ((string)($query['requested'] ?? '')) === '1',
        'login_url' => moodleIntegrationLoginUrl($canonicalPath),
        'register_url' => moodleIntegrationPath('/cms/register?redirect=' . urlencode($canonicalPath)),
        'launch_url' => moodleIntegrationPath('/cms/learning/' . $resourceId . '/launch'),
        'enroll_api_url' => moodleIntegrationPath('/api/v1/moodle-integration/learning/' . $resourceId . '/enroll'),
        'my_learning_url' => moodleIntegrationPath('/cms/page/my-learning'),
        'launch_ready' => (bool)($accessState['launch_ready'] ?? false),
        'review_pending' => (bool)($accessState['review_pending'] ?? false),
        'request_rejected' => (bool)($accessState['request_rejected'] ?? false),
        'request_revoked' => (bool)($accessState['request_revoked'] ?? false),
        'queue_pending' => (bool)($accessState['queue_pending'] ?? false),
        'queue_failed' => (bool)($accessState['queue_failed'] ?? false),
        'can_queue_enrollment' => (bool)($accessState['can_queue_enrollment'] ?? false),
        'course_access_message' => (string)($accessState['message'] ?? ''),
    ], [
        'header_title' => $course['title'] ?? 'Enroll',
    ]);
}

function pageMoodleIntegrationLaunchByResource(array $params = []): void
{
    $resourceId = (int)($params['rid'] ?? 0);
    $canonicalPath = moodleIntegrationCanonicalPublicPath('/learning/' . $resourceId . '/launch');
    $user = moodleIntegrationRequirePageUser($canonicalPath);
    moodleIntegrationAssignUserService((int)($user['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_launch']);
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $course = $resourceId > 0 ? $controller->detailByResourceId($resourceId) : null;
    $moodleCourseId = (int)($course['moodle_course_id'] ?? 0);

    if ($course === null || $moodleCourseId <= 0) {
        http_response_code(404);
        echo moodleIntegrationRenderPublicPage('pages/course-detail.disyl', [
            'page_title' => 'Course Not Found',
            'is_configured' => moodleIntegrationIsConfigured(),
            'course' => null,
        ], ['header_title' => 'Course Not Found']);
        return;
    }

    $accessState = moodleIntegrationLearnerCourseAccessStateByResourceId((int)($user['id'] ?? 0), $resourceId);
    if (empty($accessState['launch_ready'])) {
        if (!empty($accessState['review_pending']) || !empty($accessState['queue_pending'])) {
            header('Location: ' . moodleIntegrationPath('/cms/page/my-learning?queued=1&rid=' . $resourceId . '&launch_blocked=1'), true, 302);
            exit;
        }

        header('Location: ' . moodleIntegrationPath('/cms/learning/' . $resourceId . '/enroll?launch_requires_enrollment=1'), true, 302);
        exit;
    }

    $launchController = new \MoodleIntegration\Controllers\LaunchController();
    $redirectUrl = $launchController->launch($moodleCourseId, $user);
    if ($redirectUrl === null) {
        http_response_code(503);
        echo moodleIntegrationRenderPublicPage('pages/launch-error.disyl', [
            'page_title' => 'Moodle Launch Unavailable',
            'course' => $course,
        ], ['header_title' => 'Moodle Launch Unavailable']);
        return;
    }

    header('Location: ' . $redirectUrl, true, 302);
    exit;
}

function apiMoodleIntegrationEnrollByResource(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    app()->csrfEnforce();
    $user = moodleIntegrationRequireUser();
    moodleIntegrationAssignUserService((int)($user['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_enroll_api']);
    $resourceId = (int)($params['rid'] ?? 0);
    $moodleCourseId = moodleIntegrationMoodleCourseIdByResourceId($resourceId);
    if ($moodleCourseId <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Course not found.']);
        return;
    }

    $controller = new \MoodleIntegration\Controllers\EnrollmentController();
    $result = $controller->enroll($moodleCourseId, $user);
    if (empty($result['ok'])) {
        http_response_code((int)($result['http_code'] ?? 422));
    }

    echo json_encode($result);
}

function pageMoodleIntegrationCourses(array $params = []): void
{
    moodleIntegrationRedirectToCanonicalPublicPath(moodleIntegrationCanonicalPublicPath('/courses'));
    $controller = new \MoodleIntegration\Controllers\CourseController();

    echo moodleIntegrationRenderPublicPage('pages/courses.disyl', [
        'page_title' => 'Courses',
        'is_configured' => moodleIntegrationIsConfigured(),
        'courses' => $controller->list(50),
    ]);
}

function pageMoodleIntegrationCourseDetail(array $params = []): void
{
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $moodleCourseId = (int)($params['id'] ?? 0);
    moodleIntegrationRedirectToCanonicalPublicPath(moodleIntegrationCanonicalPublicPath('/courses/' . $moodleCourseId));
    $course = $moodleCourseId > 0 ? $controller->detail($moodleCourseId) : null;
    $currentUser = moodleIntegrationCurrentUser();
    $accessState = is_array($currentUser) && !empty($currentUser['id']) && $moodleCourseId > 0
        ? moodleIntegrationLearnerCourseAccessState((int)$currentUser['id'], $moodleCourseId)
        : [
            'launch_ready' => false,
            'review_pending' => false,
            'request_rejected' => false,
            'request_revoked' => false,
            'queue_pending' => false,
            'queue_failed' => false,
            'can_queue_enrollment' => false,
            'message' => 'Submit this course for review before launching in Moodle.',
        ];
    if ($course === null) {
        http_response_code(404);
    }

    echo moodleIntegrationRenderPublicPage('pages/course-detail.disyl', [
        'page_title' => $course['title'] ?? 'Course Detail',
        'is_configured' => moodleIntegrationIsConfigured(),
        'course' => $course,
        'enroll_url' => moodleIntegrationPath('/cms/course/' . $moodleCourseId . '/enroll'),
        'is_authenticated' => $currentUser !== null,
        'launch_ready' => (bool)($accessState['launch_ready'] ?? false),
        'review_pending' => (bool)($accessState['review_pending'] ?? false),
        'queue_pending' => (bool)($accessState['queue_pending'] ?? false),
        'queue_failed' => (bool)($accessState['queue_failed'] ?? false),
        'course_access_message' => (string)($accessState['message'] ?? ''),
        'my_learning_url' => moodleIntegrationPath('/cms/page/my-learning'),
    ], [
        'header_title' => $course['title'] ?? 'Course Detail',
    ]);
}

function pageMoodleIntegrationEnroll(array $params = []): void
{
    $controller = new \MoodleIntegration\Controllers\CourseController();
    $moodleCourseId = (int)($params['id'] ?? 0);
    $canonicalPath = moodleIntegrationCanonicalPublicPath('/course/' . $moodleCourseId . '/enroll');
    moodleIntegrationRedirectToCanonicalPublicPath($canonicalPath);
    $course = $moodleCourseId > 0 ? $controller->detail($moodleCourseId) : null;
    $currentUser = moodleIntegrationCurrentUser();
    $query = moodleIntegrationCurrentQueryParams();
    $accessState = is_array($currentUser) && !empty($currentUser['id']) && $moodleCourseId > 0
        ? moodleIntegrationLearnerCourseAccessState((int)$currentUser['id'], $moodleCourseId)
        : [
            'launch_ready' => false,
            'review_pending' => false,
            'request_rejected' => false,
            'request_revoked' => false,
            'queue_pending' => false,
            'queue_failed' => false,
            'can_queue_enrollment' => false,
            'message' => 'Submit this course for review before launching in Moodle.',
        ];
    if ($course === null) {
        http_response_code(404);
    }

    echo moodleIntegrationRenderPublicPage('pages/enroll.disyl', [
        'page_title' => $course['title'] ?? 'Enroll',
        'course' => $course,
        'is_authenticated' => $currentUser !== null,
        'authenticated_user_name' => trim((string)($currentUser['name'] ?? $currentUser['display_name'] ?? $currentUser['username'] ?? 'Learner')),
        'authenticated_user_email' => trim((string)($currentUser['email'] ?? '')),
        'show_registered_notice' => ((string)($query['registered'] ?? '')) === '1',
        'show_launch_requires_enrollment_notice' => ((string)($query['launch_requires_enrollment'] ?? '')) === '1',
        'show_review_requested_notice' => ((string)($query['requested'] ?? '')) === '1',
        'login_url' => moodleIntegrationLoginUrl($canonicalPath),
        'register_url' => moodleIntegrationPath('/cms/register?redirect=' . urlencode($canonicalPath)),
        'launch_url' => moodleIntegrationPath('/cms/course/' . $moodleCourseId . '/launch'),
        'enroll_api_url' => moodleIntegrationPath('/api/v1/moodle-integration/enroll/' . $moodleCourseId),
        'my_learning_url' => moodleIntegrationPath('/cms/page/my-learning'),
        'launch_ready' => (bool)($accessState['launch_ready'] ?? false),
        'review_pending' => (bool)($accessState['review_pending'] ?? false),
        'request_rejected' => (bool)($accessState['request_rejected'] ?? false),
        'request_revoked' => (bool)($accessState['request_revoked'] ?? false),
        'queue_pending' => (bool)($accessState['queue_pending'] ?? false),
        'queue_failed' => (bool)($accessState['queue_failed'] ?? false),
        'can_queue_enrollment' => (bool)($accessState['can_queue_enrollment'] ?? false),
        'course_access_message' => (string)($accessState['message'] ?? ''),
    ], [
        'header_title' => $course['title'] ?? 'Enroll',
    ]);

    if ($currentUser !== null) {
        moodleIntegrationAssignUserService((int)($currentUser['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_enroll_page']);
    }
}

function pageMoodleIntegrationMyCourses(array $params = []): void
{
    $canonicalPath = moodleIntegrationCanonicalPublicPath('/my-courses');
    moodleIntegrationRedirectToCanonicalPublicPath($canonicalPath);
    $user = moodleIntegrationRequirePageUser($canonicalPath);
    moodleIntegrationAssignUserService((int)($user['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_my_courses']);
    $controller = new \MoodleIntegration\Controllers\CourseController();

    echo moodleIntegrationRenderPublicPage('pages/my-courses.disyl', [
        'page_title' => 'My Courses',
        'is_configured' => moodleIntegrationIsConfigured(),
        'courses' => $controller->myCourses((int)$user['id']),
    ]);
}

function pageMoodleIntegrationLaunch(array $params = []): void
{
    $moodleCourseId = (int)($params['id'] ?? 0);
    $canonicalPath = moodleIntegrationCanonicalPublicPath('/course/' . $moodleCourseId . '/launch');
    moodleIntegrationRedirectToCanonicalPublicPath($canonicalPath);
    $user = moodleIntegrationRequirePageUser($canonicalPath);
    moodleIntegrationAssignUserService((int)($user['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_launch']);
    $launchController = new \MoodleIntegration\Controllers\LaunchController();
    $courseController = new \MoodleIntegration\Controllers\CourseController();
    $course = $moodleCourseId > 0 ? $courseController->detail($moodleCourseId) : null;

    if ($course === null) {
        http_response_code(404);
        echo moodleIntegrationRenderPublicPage('pages/course-detail.disyl', [
            'page_title' => 'Course Not Found',
            'is_configured' => moodleIntegrationIsConfigured(),
            'course' => null,
        ], [
            'header_title' => 'Course Not Found',
        ]);
        return;
    }

    $accessState = moodleIntegrationLearnerCourseAccessState((int)($user['id'] ?? 0), $moodleCourseId);
    if (empty($accessState['launch_ready'])) {
        if (!empty($accessState['review_pending']) || !empty($accessState['queue_pending'])) {
            $redirectUrl = moodleIntegrationPath('/cms/page/my-learning?queued=1&course_id=' . $moodleCourseId . '&launch_blocked=1');
            header('Location: ' . $redirectUrl, true, 302);
            exit;
        }

        $redirectUrl = moodleIntegrationPath('/cms/course/' . $moodleCourseId . '/enroll?launch_requires_enrollment=1');
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }

    $redirectUrl = $launchController->launch($moodleCourseId, $user);
    if ($redirectUrl === null) {
        http_response_code(503);
        echo moodleIntegrationRenderPublicPage('pages/launch-error.disyl', [
            'page_title' => 'Moodle Launch Unavailable',
            'course' => $course,
        ], [
            'header_title' => 'Moodle Launch Unavailable',
        ]);
        return;
    }

    header('Location: ' . $redirectUrl, true, 302);
    exit;
}

function postMoodleIntegrationEnrollmentDecision(array $params = []): void
{
    $user = function_exists('cmsRequireCap')
        ? cmsRequireCap('settings.manage')
        : moodleIntegrationRequirePageUser('/admin/moodle-integration');

    $requestId = (int)($params['id'] ?? 0);
    $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

    $controller = new \MoodleIntegration\Controllers\EnrollmentController();
    $result = $controller->reviewRequest($requestId, $decision, $user, $reviewNotes);

    $notice = !empty($result['ok'])
        ? ($decision === 'approved' ? 'approved' : ($decision === 'rejected' ? 'rejected' : 'revoked'))
        : 'error';

    header('Location: ' . moodleIntegrationPath('/admin/moodle-integration?notice=' . urlencode($notice)), true, 302);
    exit;
}

function apiMoodleIntegrationEnroll(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    app()->csrfEnforce();
    $user = moodleIntegrationRequireUser();
    moodleIntegrationAssignUserService((int)($user['id'] ?? 0), 'elearning', true, ['origin' => 'moodle_enroll_api']);
    $controller = new \MoodleIntegration\Controllers\EnrollmentController();
    $result = $controller->enroll((int)($params['id'] ?? 0), $user);
    if (empty($result['ok'])) {
        http_response_code((int)($result['http_code'] ?? 422));
    }

    echo json_encode($result);
}

function apiMoodleIntegrationSsoValidate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = moodleIntegrationInput();
    $token = trim((string)($input['token'] ?? ''));
    $tenantId = (int)($input['tenant_id'] ?? 0);

    if ($token === '' || $tenantId <= 0) {
        http_response_code(422);
        echo json_encode(['valid' => false, 'error' => 'token and tenant_id are required']);
        return;
    }

    $row = moodleIntegrationConsumeSsoTokenForTenant($tenantId, $token);
    if ($row === null) {
        http_response_code(401);
        echo json_encode(['valid' => false, 'error' => 'Token is invalid, expired, or already used']);
        return;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    $user = null;
    $resource = null;

    if ($db instanceof \PDO) {
        $userId = (int)($row['user_id'] ?? 0);
        foreach ([
            'SELECT id, username, email, full_name FROM users WHERE id = :id LIMIT 1',
            'SELECT id, username, email, display_name AS full_name FROM cms_users WHERE id = :id LIMIT 1',
        ] as $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([':id' => $userId]);
                $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($userRow)) {
                    $user = $userRow;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $learningResourceId = (int)($row['learning_resource_id'] ?? 0);
        if ($learningResourceId > 0) {
            $stmt = $db->prepare('SELECT id, provider, provider_id, title, status FROM learning_resources WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            $stmt->execute([':id' => $learningResourceId, ':tenant_id' => $tenantId]);
            $resourceRow = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($resourceRow)) {
                $resource = $resourceRow;
            }
        }
    }

    echo json_encode([
        'valid' => true,
        'user' => $user !== null ? [
            'id' => (int)($user['id'] ?? 0),
            'email' => (string)($user['email'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'full_name' => (string)($user['full_name'] ?? ''),
        ] : null,
        'resource' => $resource !== null ? [
            'id' => (int)($resource['id'] ?? 0),
            'provider' => (string)($resource['provider'] ?? ''),
            'provider_id' => (string)($resource['provider_id'] ?? ''),
            'title' => (string)($resource['title'] ?? ''),
            'status' => (string)($resource['status'] ?? 'active'),
        ] : null,
    ]);
}

function apiMoodleIntegrationCourseStatus(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    $user = moodleIntegrationRequireUser();
    $moodleCourseId = (int)($params['id'] ?? 0);
    if ($moodleCourseId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid course id']);
        return;
    }

    echo json_encode(moodleIntegrationCourseStatusPayload((int)($user['id'] ?? 0), $moodleCourseId));
}

function apiMoodleIntegrationQueueSync(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    moodleIntegrationRequireUser();

    $input = moodleIntegrationInput();
    $type = trim((string)($input['type'] ?? 'courses'));
    if (!in_array($type, ['courses', 'progress'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unsupported sync type']);
        return;
    }

    $tenantId = moodleIntegrationCurrentTenantId();
    $queueId = moodleIntegrationQueueTableInsertForTenant($tenantId, $type . '_sync', [
        'requested_by' => (int)(app()->user()['id'] ?? 0),
        'tenant_id' => $tenantId,
    ]);

    $handler = $type === 'progress'
        ? 'moodle-integration:moodleIntegrationSyncProgressJob'
        : 'moodle-integration:moodleIntegrationSyncCoursesJob';

    if (function_exists('kernelDispatchJob')) {
        kernelDispatchJob($handler, [
            'sync_queue_id' => $queueId,
            'requested_by' => (int)(app()->user()['id'] ?? 0),
            'tenant_id' => $tenantId,
        ], 'moodle', 0, 3);
    }

    echo json_encode(['ok' => true, 'queue_id' => $queueId, 'handler' => $handler]);
}

function moodleIntegrationDispatchScheduledWork(array $payload = []): void
{
    $settings = moodleIntegrationGetSettings();
    $frequency = trim((string)($payload['_frequency'] ?? ''));
    $expected = trim((string)($settings['sync_interval'] ?? 'hourly'));
    if ($frequency !== '' && $expected !== '' && $frequency !== $expected) {
        return;
    }

    $tenantIds = [];
    $currentTenantId = (int)($payload['tenant_id'] ?? moodleIntegrationCurrentTenantId());
    if ($currentTenantId > 0) {
        $tenantIds[] = $currentTenantId;
    } elseif (function_exists('app') && method_exists(app(), 'controlDb')) {
        try {
            $stmt = app()->controlDb()->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id ASC");
            $tenantIds = array_map('intval', $stmt ? ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) : []);
        } catch (\Throwable $e) {
            $tenantIds = [];
        }
    }

    if ($tenantIds === [] && $currentTenantId > 0) {
        $tenantIds[] = $currentTenantId;
    }

    foreach ($tenantIds as $tenantId) {
        $queueId = moodleIntegrationQueueTableInsertForTenant($tenantId, 'scheduled_sync', [
            'frequency' => $frequency,
            'scheduled' => true,
            'tenant_id' => $tenantId,
        ]);

        if (function_exists('kernelDispatchJob')) {
            kernelDispatchJob('moodle-integration:moodleIntegrationSyncCoursesJob', ['sync_queue_id' => $queueId, 'tenant_id' => $tenantId], 'moodle', 0, 3);
            kernelDispatchJob('moodle-integration:moodleIntegrationSyncProgressJob', ['sync_queue_id' => $queueId, 'tenant_id' => $tenantId], 'moodle', 30, 3);
        }
    }
}

function moodleIntegrationSyncCoursesJob(array $payload = []): void
{
    $job = new \MoodleIntegration\Jobs\SyncCoursesJob();
    $job->handle($payload);
}

function moodleIntegrationSyncProgressJob(array $payload = []): void
{
    $job = new \MoodleIntegration\Jobs\SyncProgressJob();
    $job->handle($payload);
}

/**
 * Inbound webhook endpoint for Moodle-push events.
 * Verifies HMAC-SHA256 signature over the raw request body using the tenant's sso_secret,
 * maps the provider_id to a learning_resource_id, and upserts the user's progress record.
 * Disabled by default; tenants must have the sso_secret configured before this is usable.
 */
function apiMoodleIntegrationEvents(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $rawBody = (string)file_get_contents('php://input');
    $input = moodleIntegrationInput();
    $tenantId = (int)($input['tenant_id'] ?? 0);

    if ($tenantId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
        return;
    }

    $settings = moodleIntegrationGetSettingsForTenant($tenantId);
    $secret = trim((string)($settings['sso_secret'] ?? ''));
    if ($secret === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Webhook intake is not configured for this tenant']);
        return;
    }

    // Verify HMAC-SHA256 signature. Moodle sends X-Moodle-Signature: sha256=<hex>.
    $signatureHeader = trim((string)($_SERVER['HTTP_X_MOODLE_SIGNATURE'] ?? $input['signature'] ?? ''));
    $expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($expectedSig, $signatureHeader)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Signature mismatch']);
        return;
    }

    $event = trim((string)($input['event'] ?? ''));
    $provider = trim((string)($input['provider'] ?? 'moodle'));
    $providerId = trim((string)($input['provider_id'] ?? ''));
    $userPayload = is_array($input['user'] ?? null) ? $input['user'] : [];
    $userEmail = trim((string)($userPayload['email'] ?? ''));

    if ($event === '' || $providerId === '' || $userEmail === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'event, provider_id, and user.email are required']);
        return;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Tenant database unavailable']);
        return;
    }

    // Resolve local user by email.
    $localUser = null;
    foreach ([
        'SELECT id FROM users WHERE email = :email LIMIT 1',
        'SELECT id FROM cms_users WHERE email = :email LIMIT 1',
    ] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([':email' => $userEmail]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $localUser = $row;
                break;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }

    if ($localUser === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        return;
    }

    $userId = (int)$localUser['id'];

    // Resolve learning_resource_id.
    $resourceId = 0;
    try {
        $stmt = $db->prepare('SELECT id FROM learning_resources WHERE tenant_id = :tenant_id AND provider = :provider AND provider_id = :provider_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId, ':provider' => $provider, ':provider_id' => $providerId]);
        $resourceId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        // continue; upsert without resource FK if lookup fails
    }

    // Resolve course cache id for the legacy course_id FK in user_progress.
    $courseId = 0;
    try {
        $stmt = $db->prepare('SELECT id FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND moodle_course_id = :moodle_course_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId, ':moodle_course_id' => (int)$providerId]);
        $courseId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        // continue
    }

    $progressPercent = match ($event) {
        'course_completed' => 100.0,
        default => 0.0,
    };
    $progressStatus = match ($event) {
        'course_completed' => 'completed',
        'quiz_submitted', 'assignment_submitted' => 'in_progress',
        default => 'in_progress',
    };

    try {
        $db->prepare(
            'INSERT INTO moodle_user_progress (tenant_id, user_id, learning_resource_id, course_cache_id, progress_percent, grade, status, last_synced, created_at, updated_at)
             VALUES (:tenant_id, :user_id, :learning_resource_id, :course_cache_id, :progress_percent, NULL, :status, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE learning_resource_id = VALUES(learning_resource_id), progress_percent = VALUES(progress_percent), status = VALUES(status), last_synced = NOW(), updated_at = NOW()'
        )->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':learning_resource_id' => $resourceId > 0 ? $resourceId : null,
            ':course_cache_id' => $courseId > 0 ? $courseId : null,
            ':progress_percent' => $progressPercent,
            ':status' => $progressStatus,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Progress upsert failed: ' . $e->getMessage()]);
        return;
    }

    // Record metric.
    try {
        $db->prepare(
            'INSERT INTO moodle_sync_metrics (tenant_id, sync_type, success_count, failure_count, avg_duration_ms, last_run, last_error, created_at, updated_at)
             VALUES (:tenant_id, :sync_type, 1, 0, 0, NOW(), NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE success_count = success_count + 1, last_run = NOW(), updated_at = NOW()'
        )->execute([':tenant_id' => $tenantId, ':sync_type' => 'webhook_event_' . $event]);
    } catch (\Throwable $e) {
        // non-fatal
    }

    write_log("moodle-integration: webhook event '{$event}' processed for user {$userId} provider_id {$providerId} tenant {$tenantId}", 'info');
    echo json_encode(['ok' => true, 'event' => $event, 'user_id' => $userId, 'resource_id' => $resourceId]);
}

/**
 * Module-level settings save handler that encrypts api_token and sso_secret at rest
 * before persisting through the kernel settings layer.
 */
function postMoodleIntegrationSettings(array $params = []): void
{
    $user = function_exists('cmsRequireCap')
        ? cmsRequireCap('settings.manage')
        : moodleIntegrationRequirePageUser('/admin/moodle-integration');

    app()->csrfEnforce();

    $allowedKeys = ['moodle_url', 'api_token', 'sso_secret', 'tenant_mode', 'enrollment_mode', 'sync_interval', 'max_requests_per_minute', 'burst_limit', 'shared_category_map_json'];
    $toSave = [];
    foreach ($allowedKeys as $key) {
        if (isset($_POST[$key])) {
            $toSave[$key] = trim((string)$_POST[$key]);
        }
    }

    // Encrypt secrets before persisting. Empty values are not overwritten so operators
    // can submit the form without clearing their existing credentials.
    foreach (['api_token', 'sso_secret'] as $secretKey) {
        if (isset($toSave[$secretKey]) && $toSave[$secretKey] !== '') {
            $toSave[$secretKey] = moodleIntegrationEncryptSettingValue($toSave[$secretKey]);
        } else {
            unset($toSave[$secretKey]); // keep the existing encrypted value
        }
    }

    $tenantId = moodleIntegrationCurrentTenantId();
    $saved = false;
    if ($tenantId > 0 && function_exists('saveTenantModuleSettingsForTenant')) {
        $current = function_exists('getModuleSettingsForTenant') ? (array)(getModuleSettingsForTenant('moodle-integration', $tenantId) ?? []) : [];
        $saved = saveTenantModuleSettingsForTenant('moodle-integration', $tenantId, array_merge($current, $toSave));
    } elseif (function_exists('saveTenantModuleSettings')) {
        $saved = saveTenantModuleSettings('moodle-integration', $toSave);
    }

    $notice = $saved ? 'settings_saved' : 'settings_error';
    header('Location: ' . moodleIntegrationPath('/admin/moodle-integration?notice=' . urlencode($notice)), true, 302);
    exit;
}

function moodleIntegrationQueueStatusSummary(): array
{
    $db = moodleIntegrationDb();
    $stmt = $db->prepare('SELECT status, COUNT(*) AS count_rows FROM moodle_sync_queue WHERE tenant_id = :tenant_id GROUP BY status');
    $stmt->execute([':tenant_id' => moodleIntegrationCurrentTenantId()]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $summary = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'pending');
        $summary[$status] = (int)($row['count_rows'] ?? 0);
    }

    return $summary;
}

function moodleIntegrationBuildLaunchUrl(array $user, array $course): ?string
{
    require_once __DIR__ . '/services/SSOService.php';
    $service = new MoodleIntegration\Services\SSOService();
    return $service->buildLaunchUrl($user, $course);
}