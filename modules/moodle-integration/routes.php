<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/moodle-integration' => 'moodle-integration:pageMoodleIntegrationAdmin',
        '/courses' => 'moodle-integration:pageMoodleIntegrationCourses',
        '/cms/courses' => 'moodle-integration:pageMoodleIntegrationCourses',
        '/courses/{id}' => 'moodle-integration:pageMoodleIntegrationCourseDetail',
        '/cms/courses/{id}' => 'moodle-integration:pageMoodleIntegrationCourseDetail',
        // Canonical resource-ID-based routes (provider-agnostic public identifiers).
        '/learning/{rid}' => 'moodle-integration:pageMoodleIntegrationCourseByResource',
        '/cms/learning/{rid}' => 'moodle-integration:pageMoodleIntegrationCourseByResource',
        '/learning/{rid}/enroll' => 'moodle-integration:pageMoodleIntegrationEnrollByResource',
        '/cms/learning/{rid}/enroll' => 'moodle-integration:pageMoodleIntegrationEnrollByResource',
        '/learning/{rid}/launch' => 'moodle-integration:pageMoodleIntegrationLaunchByResource',
        '/cms/learning/{rid}/launch' => 'moodle-integration:pageMoodleIntegrationLaunchByResource',
        '/course/{id}/enroll' => 'moodle-integration:pageMoodleIntegrationEnroll',
        '/cms/course/{id}/enroll' => 'moodle-integration:pageMoodleIntegrationEnroll',
        '/my-courses' => 'moodle-integration:pageMoodleIntegrationMyCourses',
        '/cms/my-courses' => 'moodle-integration:pageMoodleIntegrationMyCourses',
        '/course/{id}/launch' => 'moodle-integration:pageMoodleIntegrationLaunch',
        '/cms/course/{id}/launch' => 'moodle-integration:pageMoodleIntegrationLaunch',
        '/api/v1/moodle-integration/status/{id}' => 'moodle-integration:apiMoodleIntegrationCourseStatus',
    ],
    'POST' => [
        '/api/v1/moodle-integration/sso/validate' => 'moodle-integration:apiMoodleIntegrationSsoValidate',
        '/api/v1/moodle-integration/events' => 'moodle-integration:apiMoodleIntegrationEvents',
        '/api/v1/moodle-integration/enroll/{id}' => 'moodle-integration:apiMoodleIntegrationEnroll',
        // Canonical resource-ID-based enroll API.
        '/api/v1/moodle-integration/learning/{rid}/enroll' => 'moodle-integration:apiMoodleIntegrationEnrollByResource',
        '/api/v1/moodle-integration/sync' => 'moodle-integration:apiMoodleIntegrationQueueSync',
        '/api/v1/moodle-integration/jobs/sync-courses' => 'moodle-integration:moodleIntegrationSyncCoursesJob',
        '/api/v1/moodle-integration/jobs/sync-progress' => 'moodle-integration:moodleIntegrationSyncProgressJob',
        '/admin/moodle-integration/settings' => 'moodle-integration:postMoodleIntegrationSettings',
        '/admin/moodle-integration/requests/{id}/decision' => 'moodle-integration:postMoodleIntegrationEnrollmentDecision',
    ],
];