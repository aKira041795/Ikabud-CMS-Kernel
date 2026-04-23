<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/moodle-integration' => 'moodle-integration:pageMoodleIntegrationAdmin',
        '/courses' => 'moodle-integration:pageMoodleIntegrationCourses',
        '/cms/courses' => 'moodle-integration:pageMoodleIntegrationCourses',
        '/courses/{id}' => 'moodle-integration:pageMoodleIntegrationCourseDetail',
        '/cms/courses/{id}' => 'moodle-integration:pageMoodleIntegrationCourseDetail',
        '/course/{id}/enroll' => 'moodle-integration:pageMoodleIntegrationEnroll',
        '/cms/course/{id}/enroll' => 'moodle-integration:pageMoodleIntegrationEnroll',
        '/my-courses' => 'moodle-integration:pageMoodleIntegrationMyCourses',
        '/cms/my-courses' => 'moodle-integration:pageMoodleIntegrationMyCourses',
        '/course/{id}/launch' => 'moodle-integration:pageMoodleIntegrationLaunch',
        '/cms/course/{id}/launch' => 'moodle-integration:pageMoodleIntegrationLaunch',
        '/api/v1/moodle-integration/status/{id}' => 'moodle-integration:apiMoodleIntegrationCourseStatus',
    ],
    'POST' => [
        '/api/v1/moodle-integration/enroll/{id}' => 'moodle-integration:apiMoodleIntegrationEnroll',
        '/api/v1/moodle-integration/sync' => 'moodle-integration:apiMoodleIntegrationQueueSync',
        '/api/v1/moodle-integration/jobs/sync-courses' => 'moodle-integration:moodleIntegrationSyncCoursesJob',
        '/api/v1/moodle-integration/jobs/sync-progress' => 'moodle-integration:moodleIntegrationSyncProgressJob',
        '/admin/moodle-integration/requests/{id}/decision' => 'moodle-integration:postMoodleIntegrationEnrollmentDecision',
    ],
];