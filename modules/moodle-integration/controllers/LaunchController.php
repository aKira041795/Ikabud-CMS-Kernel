<?php

declare(strict_types=1);

namespace MoodleIntegration\Controllers;

require_once __DIR__ . '/../services/SSOService.php';

use MoodleIntegration\Services\SSOService;

final class LaunchController
{
    public function launch(int $moodleCourseId, array $user): ?string
    {
        $course = \moodleIntegrationCachedCourseByMoodleId($moodleCourseId);
        if ($course === null) {
            return null;
        }

        $service = new SSOService(\moodleIntegrationCurrentTenantId());
        return $service->buildLaunchUrl($user, $course);
    }
}