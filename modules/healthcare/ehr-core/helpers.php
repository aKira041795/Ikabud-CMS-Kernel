<?php
declare(strict_types=1);

function ehcSettingsDefaults(): array
{
    return [
        'app_name' => 'EHR Suite',
        'login_subtitle' => 'Clinical operations, records access, and compliance workflows in one secure workspace.',
        'logo_url' => '',
        'favicon_url' => '',
    ];
}

function ehcModuleSettings(bool $refresh = false): array
{
    static $cache = [];

    $tenantKey = (string)(app()->tenant()->current() ?? 0);
    if ($refresh || !isset($cache[$tenantKey])) {
        $cache[$tenantKey] = array_merge(ehcSettingsDefaults(), getModuleSettings('ehr-core'));
    }

    return $cache[$tenantKey];
}

function ehcPersistModuleSettings(array $settings): bool
{
    if ($settings === []) {
        return true;
    }

    saveModuleSettings('ehr-core', $settings);
    $fresh = ehcModuleSettings(true);
    foreach ($settings as $key => $expected) {
        if (($fresh[$key] ?? null) !== $expected) {
            return false;
        }
    }

    return true;
}

function ehcAppName(): string
{
    $name = trim((string)(ehcModuleSettings()['app_name'] ?? ''));
    return $name !== '' ? $name : 'EHR Suite';
}

function ehcLoginSubtitle(): string
{
    $subtitle = trim((string)(ehcModuleSettings()['login_subtitle'] ?? ''));
    return $subtitle !== ''
        ? $subtitle
        : 'Clinical operations, records access, and compliance workflows in one secure workspace.';
}

function ehcBrandInitial(): string
{
    $name = ehcAppName();
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr((string)$part, 0, 1));
    }

    return $initials !== '' ? $initials : 'EH';
}

function ehcDefaultFaviconUrl(): string
{
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230f766e'/%3E%3Cpath d='M15 7h2v7h7v2h-7v7h-2v-7H8v-2h7z' fill='%23fff'/%3E%3C/svg%3E";
}

function ehcNormalizeBrandAssetUrl(mixed $value, string $label = 'Brand asset URL'): string
{
    $assetUrl = trim((string)$value);
    if ($assetUrl === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($assetUrl) > 2048) {
        throw new InvalidArgumentException($label . ' must be 2048 characters or fewer.');
    }
    if (strlen($assetUrl) > 2048) {
        throw new InvalidArgumentException($label . ' must be 2048 characters or fewer.');
    }

    $scheme = strtolower((string)parse_url($assetUrl, PHP_URL_SCHEME));
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException($label . ' must use http, https, or a relative path.');
    }

    return $assetUrl;
}

function ehcLogoUrl(): string
{
    try {
        return ehcNormalizeBrandAssetUrl(ehcModuleSettings()['logo_url'] ?? '', 'Logo URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function ehcFaviconUrl(): string
{
    try {
        return ehcNormalizeBrandAssetUrl(ehcModuleSettings()['favicon_url'] ?? '', 'Favicon URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function ehcResolvedFaviconUrl(): string
{
    $faviconUrl = ehcFaviconUrl();
    return $faviconUrl !== '' ? $faviconUrl : ehcDefaultFaviconUrl();
}

function ehcBrandAssetUploadMaxBytes(): int
{
    if (function_exists('cmsMediaMaxUploadBytes')) {
        return max(262144, (int)cmsMediaMaxUploadBytes());
    }

    return 2 * 1024 * 1024;
}

function ehcBrandAssetFallbackPath(): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return BASE_PATH . '/public/uploads/ehr-core' . $tenantSegment;
}

function ehcBrandAssetFallbackUrl(string $relativePath): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return '/uploads/ehr-core' . $tenantSegment . '/' . ltrim($relativePath, '/');
}

function ehcUploadBrandAsset(string $assetType, array $file): array
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
    if ($declaredSize > ehcBrandAssetUploadMaxBytes()) {
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
            'dir' => cmsUploadsPath() . '/ehr-core/' . $subDir,
            'path' => cmsUploadsPath() . '/ehr-core/' . $subDir . '/' . $filename,
            'url' => cmsResolveUploadUrl('ehr-core/' . $relativePath),
        ];
    }
    $destinations[] = [
        'dir' => ehcBrandAssetFallbackPath() . '/' . $subDir,
        'path' => ehcBrandAssetFallbackPath() . '/' . $subDir . '/' . $filename,
        'url' => ehcBrandAssetFallbackUrl($relativePath),
    ];

    foreach ($destinations as $destination) {
        if (!kernelEnsureDirectory($destination['dir'])) {
            continue;
        }
        if (!kernelCopyFile($tmpPath, $destination['path'])) {
            continue;
        }

        return [
            'asset_type' => $assetType,
            'asset_url' => $destination['url'],
            'asset_path' => $destination['path'],
        ];
    }

    throw new RuntimeException('Unable to persist the uploaded branding asset.');
}

function ehr_coreLoginPageContext(array $overrides = []): array
{
    $baseUrl = external_base_url();
    $appName = ehcAppName();
    $subtitle = ehcLoginSubtitle();
    $logoUrl = ehcLogoUrl();
    $faviconUrl = ehcResolvedFaviconUrl();
    $brandInitial = htmlspecialchars(ehcBrandInitial(), ENT_QUOTES, 'UTF-8');
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
        'login_subtitle' => $subtitle,
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/api/v1/auth/login',
        'login_button_text' => 'Open EHR',
        'login_loading_text' => 'Opening EHR...',
        'login_forgot_url' => $baseUrl . '/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'login_page_url' => $baseUrl . '/login',
        'login_favicon_url' => $faviconUrl,
        'login_helper_title' => 'Clinical Access',
        'login_helper_html' => '<p>Password resets now follow the kernel recovery flow for EHR tenants.</p><ul><li>Use EHR Settings to update the custom name, logo, and favicon.</li><li>Forgot-password emails go to the kernel admin account stored for this tenant.</li><li>Reset links open the same branded EHR auth experience.</li></ul>',
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

function ehcStatusCatalog(): array
{
    return [
        'patient' => ['active', 'inactive', 'deceased', 'archived'],
        'appointment' => ['scheduled', 'checked-in', 'waiting', 'roomed', 'completed', 'no-show', 'canceled'],
        'encounter' => ['planned', 'open', 'completed', 'canceled'],
        'note' => ['draft', 'signed', 'amended'],
        'order' => ['requested', 'in-progress', 'completed', 'canceled'],
        'result' => ['entered', 'verified', 'released', 'corrected'],
        'prescription' => ['issued', 'canceled', 'completed'],
    ];
}

function ehcGenerateRecordKey(string $prefix): string
{
    $prefix = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($prefix)), '-');
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function ehcCurrentUser(): ?array
{
    $user = app()->cap()->call('kernel.auth.user@1');
    return is_array($user) ? $user : null;
}

function ehcAudit(string $moduleId, string $action, ?string $entityType = null, string|int|null $entityId = null, array $newData = [], array $oldData = []): void
{
    if (!app()->capabilities()->has('kernel.audit.record@1')) {
        return;
    }

    app()->cap()->call('kernel.audit.record@1', [
        'module' => $moduleId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId !== null ? (string)$entityId : null,
        'new_data' => $newData !== [] ? $newData : null,
        'old_data' => $oldData !== [] ? $oldData : null,
    ]);
}

function ehcRestrictedAccessDecision(array $options): array
{
    $patientId = (int)($options['patient_id'] ?? 0);
    $objectType = trim((string)($options['object_type'] ?? 'patient')) ?: 'patient';
    $objectId = trim((string)($options['object_id'] ?? (string)$patientId));
    $callerModule = trim((string)($options['caller_module'] ?? 'ehr-core')) ?: 'ehr-core';
    $consentDocumentId = isset($options['document_id']) ? (int)$options['document_id'] : null;
    $consentRequired = !empty($options['consent_required']);
    $breakGlassRequired = !empty($options['break_glass_required']);
    $allowIfAny = array_key_exists('allow_if_any', $options) ? !empty($options['allow_if_any']) : (!$consentRequired && !$breakGlassRequired);
    $fallbackReason = trim((string)($options['fallback_reason'] ?? 'restricted_record')) ?: 'restricted_record';
    $consentActive = false;
    $breakGlassActive = false;

    if ($patientId > 0 && app()->capabilities()->has('ehr.consent.active@1')) {
        $consentPayload = ['patient_id' => $patientId];
        if ($consentDocumentId !== null && $consentDocumentId > 0) {
            $consentPayload['document_id'] = $consentDocumentId;
        }
        $consent = app()->cap()->call('ehr.consent.active@1', $consentPayload, ['caller_module' => $callerModule]);
        $consentActive = is_array($consent) && !empty($consent['ok']) && !empty($consent['active']);
    }

    if ($patientId > 0 && app()->capabilities()->has('ehr.break_glass.active@1')) {
        $breakGlass = app()->cap()->call('ehr.break_glass.active@1', [
            'patient_id' => $patientId,
            'object_type' => $objectType,
            'object_id' => $objectId,
        ], ['caller_module' => $callerModule]);
        $breakGlassActive = is_array($breakGlass) && !empty($breakGlass['ok']) && !empty($breakGlass['active']);
    }

    if ($consentRequired && $breakGlassRequired) {
        return [
            'allowed' => $consentActive && $breakGlassActive,
            'reason' => 'consent_and_break_glass_required',
            'consent_active' => $consentActive,
            'break_glass_active' => $breakGlassActive,
        ];
    }
    if ($breakGlassRequired) {
        return [
            'allowed' => $breakGlassActive,
            'reason' => 'break_glass_required',
            'consent_active' => $consentActive,
            'break_glass_active' => $breakGlassActive,
        ];
    }
    if ($consentRequired) {
        return [
            'allowed' => $consentActive,
            'reason' => 'consent_required',
            'consent_active' => $consentActive,
            'break_glass_active' => $breakGlassActive,
        ];
    }

    return [
        'allowed' => $allowIfAny ? ($consentActive || $breakGlassActive) : true,
        'reason' => $fallbackReason,
        'consent_active' => $consentActive,
        'break_glass_active' => $breakGlassActive,
    ];
}

function ehr_core_cap_ehr_core_status_catalog_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $catalog = ehcStatusCatalog();
    $req = is_array($payload) ? $payload : [];
    $domain = strtolower(trim((string)($req['domain'] ?? '')));

    if ($domain === '') {
        return ['ok' => true, 'catalog' => $catalog];
    }

    if (!isset($catalog[$domain])) {
        return ['ok' => false, 'error' => 'Unknown status domain'];
    }

    return ['ok' => true, 'domain' => $domain, 'statuses' => $catalog[$domain]];
}