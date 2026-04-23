<?php

declare(strict_types=1);

namespace MoodleIntegration\Services;

require_once __DIR__ . '/ProviderAuthAdapterInterface.php';

/**
 * Moodle SSO adapter.
 *
 * Implements ProviderAuthAdapterInterface for the Moodle provider.
 * Generates HMAC-HS256 signed launch tokens, records them in
 * `moodle_sso_tokens` for consume-once enforcement, and builds the
 * Moodle-side redirect URL. `validateInboundToken` atomically consumes
 * a token and returns user + resource context for the Moodle-side plugin.
 */
class SSOService implements ProviderAuthAdapterInterface
{
    private int $tenantId;

    public function __construct(int $tenantId = 0)
    {
        $this->tenantId = $tenantId > 0 ? $tenantId : \moodleIntegrationCurrentTenantId();
    }

    public function buildLaunchUrl(array $user, array $resource): ?string
    {
        $settings = $this->settings();
        $baseUrl = rtrim((string)($settings['moodle_url'] ?? ''), '/');
        $secret = trim((string)($settings['sso_secret'] ?? ''));
        if ($baseUrl === '' || $secret === '') {
            return null;
        }

        $token = $this->generateLoginToken($user, $resource);
        if ($token === null) {
            return null;
        }

        return $baseUrl . '/local/applicationos/sso.php?token=' . rawurlencode($token) . '&course=' . rawurlencode((string)($resource['moodle_course_id'] ?? 0));
    }

    public function validateInboundToken(string $token, int $tenantId): ?array
    {
        if ($token === '' || $tenantId <= 0) {
            return null;
        }

        $row = \moodleIntegrationConsumeSsoTokenForTenant($tenantId, $token);
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => (int)($row['user_id'] ?? 0),
            'learning_resource_id' => (int)($row['learning_resource_id'] ?? 0),
            'tenant_id' => $tenantId,
        ];
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