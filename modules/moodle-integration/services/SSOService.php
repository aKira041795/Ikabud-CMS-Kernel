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

    /**
     * Build the Moodle-side SSO launch URL.
     *
     * SECURITY NOTE: The token is currently passed as a URL query parameter.
     * This means it may be logged in server access logs, browser history, and
     * HTTP Referer headers. A future iteration should switch to:
     *   1. Store the token server-side, pass only a short-lived opaque reference
     *      in the URL, and resolve the token on the Moodle side via back-channel.
     *   2. Or use a POST-based auto-submit form with the token in a hidden field.
     *
     * The token is consume-once with a 60-second expiry, which limits the
     * exposure window significantly.
     */
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

        // Validate JWT structure and algorithm before DB lookup.
        // This prevents algorithm confusion attacks and malformed tokens
        // from reaching the database.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiry
        $now = time();
        if (isset($payload['exp']) && (int)$payload['exp'] < $now) {
            return null;
        }

        // Check issuer
        if (($payload['iss'] ?? '') !== 'applicationos') {
            return null;
        }

        // Check audience
        if (($payload['aud'] ?? '') !== 'moodle-integration') {
            return null;
        }

        // Verify HMAC signature
        $settings = $this->settings();
        $secret = trim((string)($settings['sso_secret'] ?? ''));
        if ($secret === '') {
            return null;
        }

        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true)
        );
        if (!hash_equals($parts[2], $expectedSig)) {
            return null;
        }

        // Atomically consume the token (consume-once enforcement)
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

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}