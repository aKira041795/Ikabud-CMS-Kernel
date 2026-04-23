<?php

declare(strict_types=1);

namespace MoodleIntegration\Services;

final class SSOService
{
    private int $tenantId;

    public function __construct(int $tenantId = 0)
    {
        $this->tenantId = $tenantId > 0 ? $tenantId : \moodleIntegrationCurrentTenantId();
    }

    public function buildLaunchUrl(array $user, array $course): ?string
    {
        $settings = $this->settings();
        $baseUrl = rtrim((string)($settings['moodle_url'] ?? ''), '/');
        $secret = trim((string)($settings['sso_secret'] ?? ''));
        if ($baseUrl === '' || $secret === '') {
            return null;
        }

        $token = $this->generateLoginToken($user, $course);
        if ($token === null) {
            return null;
        }

        return $baseUrl . '/local/applicationos/sso.php?token=' . rawurlencode($token) . '&course=' . rawurlencode((string)($course['moodle_course_id'] ?? 0));
    }

    public function generateLoginToken(array $user, array $course): ?string
    {
        $settings = $this->settings();
        $secret = trim((string)($settings['sso_secret'] ?? ''));
        if ($secret === '') {
            return null;
        }

        $fullName = trim((string)($user['full_name'] ?? $user['name'] ?? $user['display_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($user['username'] ?? $user['email'] ?? ''));
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $issuedAt = time();
        $expiresAt = $issuedAt + 60;
        $payload = [
            'iss' => 'applicationos',
            'aud' => 'moodle-integration',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
            'tenant_id' => $this->tenantId,
            'user' => [
                'id' => (int)($user['id'] ?? 0),
                'email' => (string)($user['email'] ?? ''),
                'username' => (string)($user['username'] ?? $user['email'] ?? ''),
                'full_name' => $fullName,
                'source' => (string)($user['source'] ?? 'kernel'),
            ],
            'course' => [
                'id' => (int)($course['moodle_course_id'] ?? 0),
                'title' => (string)($course['title'] ?? ''),
            ],
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        $token = implode('.', $segments);
        $recorded = \moodleIntegrationRecordSsoTokenForTenant(
            $this->tenantId,
            (int)($user['id'] ?? 0),
            (int)($course['resource_id'] ?? \moodleIntegrationLearningResourceIdByMoodleCourseId((int)($course['moodle_course_id'] ?? 0), $this->tenantId)),
            $token,
            $expiresAt - $issuedAt
        );

        return $recorded ? $token : null;
    }

    private function settings(): array
    {
        return \moodleIntegrationGetSettingsForTenant($this->tenantId);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}