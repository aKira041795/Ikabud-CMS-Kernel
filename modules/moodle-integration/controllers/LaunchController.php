<?php

declare(strict_types=1);

namespace MoodleIntegration\Controllers;

require_once __DIR__ . '/../services/ProviderAuthAdapterInterface.php';
require_once __DIR__ . '/../services/SSOService.php';

use MoodleIntegration\Services\ProviderAuthAdapterInterface;
use MoodleIntegration\Services\SSOService;

final class LaunchController
{
    private ?ProviderAuthAdapterInterface $adapter;

    public function __construct(?ProviderAuthAdapterInterface $adapter = null)
    {
        $this->adapter = $adapter;
    }

    public function launch(int $moodleCourseId, array $user): ?string
    {
        $course = \moodleIntegrationCachedCourseByMoodleId($moodleCourseId);
        if ($course === null) {
            return null;
        }

        $service = $this->adapter ?? new SSOService(\moodleIntegrationCurrentTenantId());
        return $service->buildLaunchUrl($user, $course);
    }
}