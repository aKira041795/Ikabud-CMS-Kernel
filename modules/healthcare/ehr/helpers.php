<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleDB;

app()->registerAuthTable('ehr', 'ehr_users');

if (function_exists('app') && method_exists(app(), 'hooks')) {
    app()->hooks()->on('kernel.home_url', static function (?string $url, string $role, ?array $user = null) {
        if (!is_array($user) || (($user['source'] ?? '') !== 'ehr')) {
            return $url;
        }

        if (in_array((string)($user['role'] ?? ''), ['admin'], true)) {
            return '/admin/ehr';
        }

        return $url;
    }, 80);
}

function ehr_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'ehr_cap_kernel_auth_authenticate_1',
    ];
}

function ehrBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function ehrExternalBaseUrl(): string
{
    return external_base_url();
}

function ehrDb(): ModuleDB
{
    $ctx = module('ehr');
    if (!$ctx) {
        throw new RuntimeException('EHR module context unavailable');
    }

    return $ctx->db();
}

function ehrCookieName(): string
{
    return 'ehr_token';
}

function ehrSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    if (headers_sent()) {
        return;
    }

    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(ehrCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function ehrClearAuthCookie(): void
{
    if (headers_sent()) {
        return;
    }

    clearAuthCookie(ehrCookieName());
}

function ehrCurrentUser(): ?array
{
    $user = app()->user();
    return is_array($user) ? $user : null;
}

function ehrIsModuleUser(?array $user): bool
{
    return is_array($user) && (($user['source'] ?? '') === 'ehr');
}

function ehrUserHasAdminAccess(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    $source = (string)($user['source'] ?? '');
    $role = (string)($user['role'] ?? '');
    if ($source === 'ehr' && $role === 'admin') {
        return true;
    }
    if ($source === 'kernel' && in_array($role, ['admin', 'superadmin'], true)) {
        return true;
    }
    if ($source === 'cms' && function_exists('cmsRoleAtLeast')) {
        return cmsRoleAtLeast($role, 'administrator');
    }

    return false;
}

function ehrRequireAdmin(): array
{
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isApiRoute = str_starts_with($requestUri, '/api/');
    $user = ehrCurrentUser();

    if (!is_array($user)) {
        if ($isApiRoute) {
            app()->json(['ok' => false, 'error' => 'Auth required'], 401);
            exit;
        }

        app()->redirect('/login');
    }

    if (!ehrUserHasAdminAccess($user)) {
        if ($isApiRoute) {
            app()->json(['ok' => false, 'error' => 'Access denied'], 403);
            exit;
        }

        http_response_code(403);
        if (function_exists('cmsRender')) {
            echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        } else {
            echo 'Access denied';
        }
        exit;
    }

    return $user;
}

function ehrSettingsDefaults(): array
{
    return [
        'app_name' => 'EHR Suite',
        'login_subtitle' => 'Clinical operations, records access, and compliance workflows in one secure workspace.',
        'logo_url' => '',
        'favicon_url' => '',
    ];
}

function ehrModuleSettings(bool $refresh = false): array
{
    static $cache = [];

    $tenantKey = (string)(app()->tenant()->current() ?? 0);
    if ($refresh || !isset($cache[$tenantKey])) {
        $cache[$tenantKey] = array_merge(ehrSettingsDefaults(), getModuleSettings('ehr'));
    }

    return $cache[$tenantKey];
}

function ehrPersistModuleSettings(array $settings): bool
{
    if ($settings === []) {
        return true;
    }

    saveModuleSettings('ehr', $settings);
    $fresh = ehrModuleSettings(true);
    foreach ($settings as $key => $expected) {
        if (($fresh[$key] ?? null) !== $expected) {
            return false;
        }
    }

    return true;
}

function ehrAppName(): string
{
    $name = trim((string)(ehrModuleSettings()['app_name'] ?? ''));
    return $name !== '' ? $name : 'EHR Suite';
}

function ehrLoginSubtitle(): string
{
    $subtitle = trim((string)(ehrModuleSettings()['login_subtitle'] ?? ''));
    return $subtitle !== ''
        ? $subtitle
        : 'Clinical operations, records access, and compliance workflows in one secure workspace.';
}

function ehrBrandInitial(): string
{
    $parts = preg_split('/\s+/', trim(ehrAppName())) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr((string)$part, 0, 1));
    }

    return $initials !== '' ? $initials : 'EH';
}

function ehrDefaultFaviconUrl(): string
{
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230f766e'/%3E%3Cpath d='M15 7h2v7h7v2h-7v7h-2v-7H8v-2h7z' fill='%23fff'/%3E%3C/svg%3E";
}

function ehrNormalizeBrandAssetUrl(mixed $value, string $label = 'Brand asset URL'): string
{
    $assetUrl = trim((string)$value);
    if ($assetUrl === '') {
        return '';
    }

    if ((function_exists('mb_strlen') ? mb_strlen($assetUrl) : strlen($assetUrl)) > 2048) {
        throw new InvalidArgumentException($label . ' must be 2048 characters or fewer.');
    }

    $scheme = strtolower((string)parse_url($assetUrl, PHP_URL_SCHEME));
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException($label . ' must use http, https, or a relative path.');
    }

    return $assetUrl;
}

function ehrLogoUrl(): string
{
    try {
        return ehrNormalizeBrandAssetUrl(ehrModuleSettings()['logo_url'] ?? '', 'Logo URL');
    } catch (Throwable $e) {
        return '';
    }
}

function ehrFaviconUrl(): string
{
    try {
        return ehrNormalizeBrandAssetUrl(ehrModuleSettings()['favicon_url'] ?? '', 'Favicon URL');
    } catch (Throwable $e) {
        return '';
    }
}

function ehrResolvedFaviconUrl(): string
{
    $faviconUrl = ehrFaviconUrl();
    return $faviconUrl !== '' ? $faviconUrl : ehrDefaultFaviconUrl();
}

function ehrBrandAssetUploadMaxBytes(): int
{
    if (function_exists('cmsMediaMaxUploadBytes')) {
        return max(262144, (int)cmsMediaMaxUploadBytes());
    }

    return 2 * 1024 * 1024;
}

function ehrBrandAssetFallbackPath(): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return BASE_PATH . '/public/uploads/ehr' . $tenantSegment;
}

function ehrBrandAssetFallbackUrl(string $relativePath): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return '/uploads/ehr' . $tenantSegment . '/' . ltrim($relativePath, '/');
}

function ehrUploadBrandAsset(string $assetType, array $file): array
{
    $assetType = strtolower(trim($assetType));
    $labels = [
        'logo' => 'Logo',
        'favicon' => 'Favicon',
    ];
    if (!isset($labels[$assetType])) {
        throw new InvalidArgumentException('Unsupported branding asset type.');
    }

    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload a ' . strtolower($labels[$assetType]) . ' image first.');
    }

    $tmpPath = trim((string)($file['tmp_name'] ?? ''));
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file is not available.');
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException($labels[$assetType] . ' upload did not arrive through the HTTP upload pipeline.');
    }

    $originalName = trim((string)($file['name'] ?? ($assetType . '.png')));
    $declaredSize = (int)($file['size'] ?? 0);
    if ($declaredSize <= 0) {
        $declaredSize = (int)(@filesize($tmpPath) ?: 0);
    }
    if ($declaredSize <= 0) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file is empty.');
    }
    if ($declaredSize > ehrBrandAssetUploadMaxBytes()) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file exceeds the maximum allowed size.');
    }

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if ($assetType === 'favicon') {
        $allowedMimeTypes[] = 'image/x-icon';
        $allowedMimeTypes[] = 'image/vnd.microsoft.icon';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string)($finfo->file($tmpPath) ?: ''));
    if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new InvalidArgumentException($labels[$assetType] . ' must be a JPG, PNG, GIF, WEBP, SVG, or ICO image.');
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if ($assetType === 'favicon') {
        $allowedExtensions[] = 'ico';
    }
    if (!in_array($ext, $allowedExtensions, true)) {
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }

    $filename = $assetType . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $subDir = 'branding/' . date('Y') . '/' . date('m');
    $relativePath = $subDir . '/' . $filename;

    $destinations = [];
    if (function_exists('cmsUploadsPath') && function_exists('cmsResolveUploadUrl')) {
        $destinations[] = [
            'dir' => cmsUploadsPath() . '/ehr/' . $subDir,
            'path' => cmsUploadsPath() . '/ehr/' . $subDir . '/' . $filename,
            'url' => cmsResolveUploadUrl('ehr/' . $relativePath),
        ];
    }
    $destinations[] = [
        'dir' => ehrBrandAssetFallbackPath() . '/' . $subDir,
        'path' => ehrBrandAssetFallbackPath() . '/' . $subDir . '/' . $filename,
        'url' => ehrBrandAssetFallbackUrl($relativePath),
    ];

    foreach ($destinations as $destination) {
        if (!kernelEnsureDirectory($destination['dir'])) {
            continue;
        }
        if (!kernelCopyFile($tmpPath, $destination['path'])) {
            continue;
        }

        if ($mimeType === 'image/svg+xml' && function_exists('cmsSanitizeSvgContent')) {
            $svg = (string)@file_get_contents($destination['path']);
            if ($svg !== '') {
                kernelWriteFile($destination['path'], cmsSanitizeSvgContent($svg));
            }
        }

        return [
            'asset_type' => $assetType,
            'asset_url' => $destination['url'],
            'asset_path' => $destination['path'],
        ];
    }

    throw new RuntimeException('Unable to persist the uploaded branding asset.');
}

function ehrLoginPageContext(array $overrides = []): array
{
    $appName = ehrAppName();
    $logoUrl = ehrLogoUrl();
    $faviconUrl = ehrResolvedFaviconUrl();
    $brandInitial = htmlspecialchars(ehrBrandInitial(), ENT_QUOTES, 'UTF-8');
    $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $loginLogoHtml = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $escapedAppName . ' logo">'
        : '<span>' . $brandInitial . '</span>';

    return array_merge([
        'page_title' => 'EHR Sign In',
        'login_logo_html' => $loginLogoHtml,
        'brand_mark_html' => $loginLogoHtml,
        'login_brand_html' => $escapedAppName,
        'login_brand_text' => $appName,
        'login_subtitle' => ehrLoginSubtitle(),
        'login_username_label' => 'Username or Email',
        'login_endpoint' => ehrBaseUrl() . '/api/v1/ehr/auth/login',
        'login_button_text' => 'Open EHR',
        'login_loading_text' => 'Opening EHR...',
        'login_forgot_url' => ehrBaseUrl() . '/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'login_page_url' => ehrBaseUrl() . '/login',
        'login_favicon_url' => $faviconUrl,
        'login_helper_title' => 'Clinical Access',
        'login_helper_html' => '<p>EHR auth is module-owned for this tenant.</p><ul><li>Use EHR Settings to update the custom name, logo, and favicon.</li><li>Forgot-password emails go to the admin address stored on the EHR auth record.</li><li>Password reset links open the same branded EHR experience.</li></ul>',
        'gui' => [
            'app_name' => $appName,
            'app_name_accent' => $appName,
            'app_name_rest' => '',
            'font_url' => 'https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Manrope:wght@400;500;600;700;800&display=swap',
            'font_family' => 'Manrope, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#0f766e',
            'color_primary_hover' => '#115e59',
            'color_primary_light' => 'rgba(15, 118, 110, 0.18)',
            'color_bg' => 'linear-gradient(155deg, #ecfeff 0%, #dbeafe 42%, #f8fafc 100%)',
            'color_surface' => 'rgba(255, 255, 255, 0.96)',
            'color_border' => '#b6dde2',
            'color_text' => '#0f172a',
            'color_text_muted' => '#475569',
            'css_overrides' => '.login-card{max-width:460px;border:1px solid rgba(15,118,110,.12);box-shadow:0 30px 80px rgba(15,23,42,.14)}.login-logo h1{font-family:"Instrument Serif", Georgia, serif;font-size:2.4rem;letter-spacing:-.04em}.login-logo p{max-width:34ch;margin:10px auto 0;font-size:14px;line-height:1.55}.form-label{text-transform:uppercase;letter-spacing:.08em;font-size:11px}.btn-login{box-shadow:0 16px 36px rgba(15,118,110,.18)}body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at top right, rgba(34,197,94,.08), transparent 32%),radial-gradient(circle at bottom left, rgba(14,165,233,.14), transparent 35%);pointer-events:none}',
        ],
    ], $overrides);
}

function ehrRender(string $template, array $context = []): string
{
    $resolvedTemplate = str_starts_with($template, 'modules/')
        ? $template
        : 'modules/ehr/' . ltrim($template, '/');

    if (function_exists('kernelPrepareRenderContext')) {
        $context = kernelPrepareRenderContext($resolvedTemplate, $context);
    }

    return app()->render($resolvedTemplate, $context);
}

function ehrAdminNavItems(array $user): array
{
    $role = trim((string)($user['role'] ?? ''));
    if ($role === '') {
        return [];
    }

    $items = [];
    foreach (getEnabledModules() as $module) {
        $moduleId = (string)($module['id'] ?? '');
        foreach (($module['nav'] ?? []) as $item) {
            $roles = is_array($item['roles'] ?? null) ? $item['roles'] : [];
            if (!in_array($role, $roles, true) && !in_array('*', $roles, true)) {
                continue;
            }

            $rawUrl = trim((string)($item['url'] ?? ''));
            if ($rawUrl === '' || !str_starts_with($rawUrl, '/admin/ehr')) {
                continue;
            }

            $label = trim((string)($item['label'] ?? ''));
            $key = trim((string)($item['key'] ?? ''));
            $description = trim((string)($item['description'] ?? ''));
            if ($label === '' || $key === '' || $description === '') {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'url' => $rawUrl,
                'description' => $description,
                'icon' => (string)($item['icon'] ?? 'box'),
                'module' => $moduleId,
                'order' => (int)($item['order'] ?? ($key === 'ehr_settings' ? 900 : 500)),
            ];
        }
    }

    usort($items, static function (array $left, array $right): int {
        $orderCompare = ((int)($left['order'] ?? 500)) <=> ((int)($right['order'] ?? 500));
        if ($orderCompare !== 0) {
            return $orderCompare;
        }

        return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
    });

    return $items;
}

function ehrDashboardNavGroups(array $navItems): array
{
    $workspace = [];
    $administration = [];
    $workspaceGroups = [];

    $sidebarGroups = ehrSidebarNavGroups($navItems);
    foreach ($sidebarGroups as $group) {
        $groupKey = (string)($group['key'] ?? '');
        $groupTitle = (string)($group['title'] ?? '');
        $groupItems = is_array($group['items'] ?? null) ? array_values($group['items']) : [];

        if ($groupKey === 'overview') {
            continue;
        }

        if ($groupKey === 'administration') {
            $administration = $groupItems;
            continue;
        }

        $workspace = array_merge($workspace, $groupItems);
        $workspaceGroups[] = [
            'key' => $groupKey,
            'title' => $groupTitle,
            'description' => match ($groupKey) {
                'patient_flow' => 'Coordinate appointments, patient identity, and active visits.',
                'clinical_workspace' => 'Review notes, orders, results, prescriptions, and chart documents.',
                default => 'Track consent, audit, reporting, and downstream billing signals.',
            },
            'items' => $groupItems,
        ];
    }

    return [
        'workspace' => $workspace,
        'workspace_groups' => $workspaceGroups,
        'administration' => $administration,
    ];
}

function ehrSidebarNavGroups(array $navItems): array
{
    $groups = [
        'overview' => [
            'key' => 'overview',
            'title' => 'Overview',
            'items' => [],
        ],
        'patient_flow' => [
            'key' => 'patient_flow',
            'title' => 'Patient Flow',
            'items' => [],
        ],
        'clinical_workspace' => [
            'key' => 'clinical_workspace',
            'title' => 'Clinical Workspace',
            'items' => [],
        ],
        'governance_revenue' => [
            'key' => 'governance_revenue',
            'title' => 'Governance & Revenue',
            'items' => [],
        ],
        'administration' => [
            'key' => 'administration',
            'title' => 'Administration',
            'items' => [],
        ],
    ];

    foreach ($navItems as $item) {
        $key = (string)($item['key'] ?? '');
        $groupKey = match ($key) {
            'ehr_dashboard' => 'overview',
            'ehr_scheduling', 'ehr_patient_registry', 'ehr_encounters' => 'patient_flow',
            'ehr_clinical_notes', 'ehr_orders', 'ehr_results', 'ehr_prescriptions', 'ehr_documents' => 'clinical_workspace',
            'ehr_settings' => 'administration',
            default => 'governance_revenue',
        };

        $groups[$groupKey]['items'][] = $item;
    }

    return array_values(array_filter($groups, static fn(array $group): bool => !empty($group['items'])));
}

function ehrPatientSummary(int $patientId, string $callerModule = 'ehr'): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $result = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => $callerModule]);
    if (!is_array($result) || empty($result['ok']) || !is_array($result['patient'] ?? null)) {
        return null;
    }

    $patient = $result['patient'];
    return [
        'id' => (int)($patient['id'] ?? 0),
        'patient_uuid' => (string)($patient['patient_uuid'] ?? ''),
        'first_name' => (string)($patient['first_name'] ?? ''),
        'last_name' => (string)($patient['last_name'] ?? ''),
        'birth_date' => (string)($patient['birth_date'] ?? ''),
        'status' => (string)($patient['status'] ?? ''),
    ];
}

function ehrEncounterSummary(int $encounterId, string $callerModule = 'ehr'): ?array
{
    if ($encounterId <= 0) {
        return null;
    }

    $result = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => $callerModule]);
    if (!is_array($result) || empty($result['ok']) || !is_array($result['encounter'] ?? null)) {
        return null;
    }

    $encounter = $result['encounter'];
    return [
        'id' => (int)($encounter['id'] ?? 0),
        'encounter_uuid' => (string)($encounter['encounter_uuid'] ?? ''),
        'encounter_type' => (string)($encounter['encounter_type'] ?? ''),
        'service_line' => (string)($encounter['service_line'] ?? ''),
        'status' => (string)($encounter['status'] ?? ''),
        'start_at' => (string)($encounter['start_at'] ?? ''),
    ];
}

function ehrHydrateRecordSummaries(array $rows, string $callerModule, string $patientIdKey = 'patient_id', string $encounterIdKey = 'encounter_id'): array
{
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }

        $row['patient_summary'] = ehrPatientSummary((int)($row[$patientIdKey] ?? 0), $callerModule);
        $row['encounter_summary'] = ehrEncounterSummary((int)($row[$encounterIdKey] ?? 0), $callerModule);
    }
    unset($row);

    return $rows;
}

function ehrAdminContext(array $user, string $currentPage, array $extra = []): array
{
    $displayName = (string)($user['full_name'] ?? $user['display_name'] ?? $user['name'] ?? $user['username'] ?? 'User');
    $role = (string)($user['role'] ?? 'admin');

    return array_merge([
        'user' => $user,
        'page_title' => 'EHR Workspace',
        'current_page' => $currentPage,
        'base_url' => ehrBaseUrl(),
        'logout_url' => ehrBaseUrl() . '/ehr/logout',
        'csrf_token' => app()->csrfToken(),
        'csrf_field' => app()->csrfField(),
        'nav_items' => ehrAdminNavItems($user),
        'sidebar_nav_groups' => ehrSidebarNavGroups(ehrAdminNavItems($user)),
        'user_display' => $displayName,
        'user_role' => ucfirst($role),
        'brand_name' => ehrAppName(),
        'brand_initial' => ehrBrandInitial(),
    ], $extra);
}

function ehrAuthHintPresent(): bool
{
    $kernelJwtCookie = (string)config('app.jwt.cookie', 'token');

    return isset($_SERVER['HTTP_AUTHORIZATION'])
        || isset($_COOKIE[ehrCookieName()])
        || isset($_COOKIE[$kernelJwtCookie]);
}

function ehrRedirectAuthenticatedAuthUser(): bool
{
    if (!ehrAuthHintPresent()) {
        return false;
    }

    $user = app()->user();
    if (!is_array($user)) {
        return false;
    }

    $home = kernelResolveAuthenticatedHomeRedirect($user, true) ?? '/admin/ehr';
    app()->redirect($home);
    return true;
}

function ehrPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function ehrForgotPasswordRateLimitSnapshot(string $scope, string $value): array
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'ehr_forgot_password:' . $scope . ':' . sha1($normalized);
    $cached = app()->cache()->get('security_rate_limits', $key);
    if (!is_array($cached)) {
        return ['key' => $key, 'count' => 0];
    }

    return [
        'key' => $key,
        'count' => max(0, (int)($cached['count'] ?? 0)),
    ];
}

function ehrForgotPasswordRateLimitExceeded(string $ip, string $identity): bool
{
    $policy = kernel_password_reset_policy();
    $ipState = ehrForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown');
    if ((int)$ipState['count'] >= (int)$policy['forgot_rate_limit_ip_max']) {
        return true;
    }

    $identityState = ehrForgotPasswordRateLimitSnapshot('identity', $identity);
    return (int)$identityState['count'] >= (int)$policy['forgot_rate_limit_identity_max'];
}

function ehrForgotPasswordRateLimitRecord(string $ip, string $identity): void
{
    $policy = kernel_password_reset_policy();
    $entries = [
        ehrForgotPasswordRateLimitSnapshot('ip', $ip !== '' ? $ip : 'unknown'),
        ehrForgotPasswordRateLimitSnapshot('identity', $identity),
    ];

    foreach ($entries as $entry) {
        app()->cache()->set(
            'security_rate_limits',
            (string)$entry['key'],
            ['count' => ((int)($entry['count'] ?? 0)) + 1],
            (int)$policy['forgot_rate_limit_window_seconds']
        );
    }
}

function ehrResetPasswordRateLimitExceeded(string $ip): bool
{
    $policy = kernel_password_reset_policy();
    $key = 'ehr_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    return is_array($cached) && (int)($cached['count'] ?? 0) >= (int)$policy['reset_rate_limit_ip_max'];
}

function ehrResetPasswordRateLimitRecord(string $ip): void
{
    $policy = kernel_password_reset_policy();
    $key = 'ehr_reset_password:ip:' . sha1($ip !== '' ? $ip : 'unknown');
    $cached = app()->cache()->get('security_rate_limits', $key);
    $count = is_array($cached) ? max(0, (int)($cached['count'] ?? 0)) : 0;
    app()->cache()->set('security_rate_limits', $key, ['count' => $count + 1], (int)$policy['reset_rate_limit_window_seconds']);
}

function ehrResetTokenIsValid(string $token): bool
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    try {
        $stmt = ehrDb()->prepare(
            'SELECT id
             FROM ehr_password_resets
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => ehrPasswordResetTokenHash($token)]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ehrUserHasUnsafeBootstrapPassword(array $row): bool
{
    $passwordHash = (string)($row['password_hash'] ?? '');
    return in_array($passwordHash, [
        '!ehr-bootstrap-password-reset-required!',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    ], true);
}

function ehrUserTokenVersion(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    try {
        $stmt = ehrDb()->prepare('SELECT COALESCE(token_version, 0) AS token_version FROM ehr_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['token_version'] ?? 0) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function ehr_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    $prefix = '@ehr:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }

    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    try {
        $stmt = ehrDb()->prepare(
            "SELECT id, username, email, password_hash, full_name, role, is_active\n"
            . "FROM ehr_users\n"
            . "WHERE (username = :username OR email = :email) AND is_active = 1\n"
            . "LIMIT 1"
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $username,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || ehrUserHasUnsafeBootstrapPassword($row)) {
            return null;
        }

        if (!password_verify($password, (string)($row['password_hash'] ?? ''))) {
            return null;
        }

        return [
            'user' => [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'admin'),
                'sub' => 'ehr:' . (int)($row['id'] ?? 0),
                'token_version' => ehrUserTokenVersion((int)($row['id'] ?? 0)),
            ],
            'source' => 'ehr',
        ];
    } catch (Throwable $e) {
        return null;
    }
}
