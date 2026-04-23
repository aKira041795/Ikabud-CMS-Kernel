<?php

declare(strict_types=1);

namespace MoodleIntegration\Controllers;

final class CourseController
{
    public function list(int $limit = 20): array
    {
        return \moodleIntegrationCachedCourses($limit);
    }

    public function detail(int $moodleCourseId): ?array
    {
        if ($moodleCourseId <= 0) {
            return null;
        }

        return \moodleIntegrationCachedCourseByMoodleId($moodleCourseId);
    }

    public function myCourses(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return \moodleIntegrationUserProgressRows($userId);
    }
}