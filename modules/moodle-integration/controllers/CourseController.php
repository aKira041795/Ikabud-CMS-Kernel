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

    /**
     * Resolve a course by its canonical learning_resource_id (provider-agnostic).
     * This is the preferred lookup path for new routes; callers should not need
     * to know or pass the provider-level moodle_course_id.
     */
    public function detailByResourceId(int $resourceId): ?array
    {
        if ($resourceId <= 0) {
            return null;
        }

        return \moodleIntegrationCachedCourseByResourceId($resourceId);
    }

    public function myCourses(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return \moodleIntegrationUserProgressRows($userId);
    }
}