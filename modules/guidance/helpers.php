<?php

declare(strict_types=1);

function guidance_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'guidance_cap_kernel_auth_authenticate_1',
    ];
}

function guidanceDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('guidance');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx->db();
}

function guidanceCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('guidance');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function guidanceUser(): ?array
{
    return guidanceCtx()->user();
}

function guidanceInput(?string $key = null, mixed $default = null): mixed
{
    return guidanceCtx()->input($key, $default);
}

function guidanceRender(string $template, array $context = []): string
{
    return guidanceCtx()->render($template, $context);
}

function guidanceRedirect(string $url, int $status = 302): void
{
    guidanceCtx()->redirect($url, $status);
}

function guidanceIsHtmx(): bool
{
    return guidanceCtx()->isHtmx();
}

function guidanceHtmxResponse(array $headers = []): void
{
    guidanceCtx()->htmxResponse($headers);
}

function guidanceFireEvent(string $event, array $payload = []): void
{
    $ctx = module('guidance');
    if (!$ctx) {
        return;
    }

    $ctx->fireEvent($event, $payload);
}

function guidanceGetSetting(string $key, ?string $default = null): ?string
{
    try {
        $stmt = guidanceDb()->prepare("SELECT setting_value FROM gm_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return $default;
        }
        return (string)$raw;
    } catch (Throwable $e) {
        return $default;
    }
}

function guidanceTinyMceAssets(string $context = 'guidance.session', string $profile = 'default'): array
{
    try {
        $result = app()->cap()->call('tinymce.assets.get@1', [
            'context' => $context,
            'profile' => $profile,
        ], 'first', null, 'guidance');
        if (is_array($result) && !empty($result['ok']) && is_array($result['data'] ?? null)) {
            return $result['data'];
        }
    } catch (Throwable $e) {
    }

    return [
        'version' => null,
        'js_urls' => [],
        'css_urls' => [],
    ];
}

function guidanceTinyMceConfig(string $context = 'guidance.session', string $profile = 'default', bool $readonly = false): array
{
    try {
        $result = app()->cap()->call('tinymce.config.get@1', [
            'context' => $context,
            'profile' => $profile,
            'readonly' => $readonly,
        ], 'first', null, 'guidance');
        if (is_array($result) && !empty($result['ok']) && is_array($result['data'] ?? null)) {
            return $result['data'];
        }
    } catch (Throwable $e) {
    }

    return [
        'selector' => '[data-tinymce-editor]',
        'menubar' => true,
        'branding' => false,
        'height' => 420,
        'plugins' => [],
        'toolbar' => '',
        'readonly' => $readonly,
    ];
}

function guidanceEditorNormalizeHtml(string $html, string $context = 'guidance.session'): string
{
    try {
        $result = app()->cap()->call('tinymce.html.normalize@1', [
            'html' => $html,
            'context' => $context,
        ], 'first', null, 'guidance');
        if (is_array($result) && !empty($result['ok'])) {
            return (string)($result['html'] ?? $html);
        }
    } catch (Throwable $e) {
    }

    return trim($html);
}

function guidanceEditorSanitizeHtml(string $html, string $context = 'guidance.session'): string
{
    try {
        $result = app()->cap()->call('tinymce.html.sanitize@1', [
            'html' => $html,
            'context' => $context,
        ], 'first', null, 'guidance');
        if (is_array($result) && !empty($result['ok'])) {
            return (string)($result['html'] ?? $html);
        }
    } catch (Throwable $e) {
    }

    return trim($html);
}

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (($user['source'] ?? null) !== 'guidance') {
        return $url;
    }

    if (in_array($role, ['admin', 'supervisor', 'counselor'], true)) {
        return '/admin/guidance';
    }

    return $url;
}, 80);

function guidance_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    // Username prefix policy: non-kernel providers must require @provider:username to avoid collisions.
    // Guidance provider accepts only usernames prefixed with '@guidance:'.
    $prefix = '@guidance:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    // Guidance uses email-based login in its standalone app.
    // Here we accept either email or username in the suffix.
    try {
        $stmt = guidanceDb()->prepare(
            "SELECT id, email, password, first_name, last_name, role, is_active\n"
            . "FROM gm_users\n"
            . "WHERE (email = :u OR SUBSTRING_INDEX(email, '@', 1) = :u_local) AND deleted_at IS NULL\n"
            . "LIMIT 1"
        );
        $stmt->execute([
            ':u' => $username,
            ':u_local' => $username,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || empty($row['is_active'])) {
            return null;
        }

        if (!password_verify($password, (string)($row['password'] ?? ''))) {
            return null;
        }

        $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));

        // Normalize to kernel auth user shape expectations.
        $user = [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['email'] ?? ''),
            'full_name' => $fullName !== '' ? $fullName : (string)($row['email'] ?? ''),
            'role' => (string)($row['role'] ?? 'counselor'),
        ];

        return ['user' => $user, 'source' => 'guidance'];
    } catch (Throwable $e) {
        // Non-fatal: auth provider returns null and lets pipeline continue.
        return null;
    }
}
