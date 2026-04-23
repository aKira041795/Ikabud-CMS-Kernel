<?php

declare(strict_types=1);

namespace MoodleIntegration\Services;

/**
 * Contract for provider-specific authentication/SSO adapters.
 *
 * Each LMS/learning provider that supports SSO launch must implement this
 * interface. `SSOService` (Moodle adapter) is the first implementation.
 *
 * Design notes:
 * - `$user` is always a kernel-style user array: at minimum `id`, `email`, `username`.
 * - `$resource` is always a `moodle_courses_cache` / `learning_resources`-compatible
 *   row array: at minimum `resource_id`, `moodle_course_id`, `title`.
 * - Return null from `buildLaunchUrl` when launch is not possible (unconfigured,
 *   token issue, etc.) so callers can render an appropriate error page.
 * - Return null from `validateInboundToken` when the token is invalid, expired, or
 *   already consumed so callers can return 401.
 */
interface ProviderAuthAdapterInterface
{
    /**
     * Build a signed SSO launch URL for the given user + learning resource.
     * Returns null if the provider is not configured or token issuance fails.
     */
    public function buildLaunchUrl(array $user, array $resource): ?string;

    /**
     * Validate and atomically consume a single-use launch token issued by this provider.
     * Returns the token payload (user + resource context) on success, null on failure.
     */
    public function validateInboundToken(string $token, int $tenantId): ?array;
}
