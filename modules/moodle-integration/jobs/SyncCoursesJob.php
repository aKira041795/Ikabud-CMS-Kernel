<?php

declare(strict_types=1);

namespace MoodleIntegration\Jobs;

require_once __DIR__ . '/../services/MoodleService.php';
require_once __DIR__ . '/../services/SyncService.php';

use MoodleIntegration\Services\SyncService;

final class SyncCoursesJob
{
    public function handle(array $payload): void
    {
        $service = new SyncService();
        $service->syncCourses($payload);
    }
}