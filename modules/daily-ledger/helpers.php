<?php

declare(strict_types=1);

/**
 * Returns the base URL for the Daily Ledger module.
 */
function dlGetBaseUrl(): string
{
    return '/daily-ledger';
}

function dlExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function daily_ledger_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'daily_ledger_cap_kernel_auth_authenticate_1',
    ];
}

function dlCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('daily-ledger');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function dlInput(): array
{
    $input = dlCtx()->input();
    return is_array($input) ? $input : [];
}

function dlAppName(): string
{
    $settings = dlModuleSettings();
    $name = trim((string)($settings['app_name'] ?? ''));
    return $name !== '' ? $name : 'Daily Ledger';
}

function dlLoginPageContext(array $overrides = []): array
{
    $baseUrl = dlGetBaseUrl();
    $appName = dlAppName();
    $logoUrl = dlLogoUrl();
    $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $brandMarkHtml = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $escapedAppName . ' logo">'
        : '<span>DL</span>';

    return array_merge([
        'page_title' => $appName . ' Sign In',
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => dlFaviconUrl(),
        'resolved_favicon_url' => dlResolvedFaviconUrl(),
        'brand_mark_html' => $brandMarkHtml,
        'login_logo_html' => $brandMarkHtml,
        'login_brand_text' => $appName,
        'login_brand_html' => $escapedAppName,
        'login_subtitle' => 'Sign in to continue',
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/auth/login',
        'login_button_text' => 'Sign In',
        'login_loading_text' => 'Signing in...',
        'login_forgot_url' => $baseUrl . '/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'gui' => [
            'app_name' => $appName,
            'app_name_accent' => $appName,
            'app_name_rest' => '',
            'font_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#b45309',
            'color_primary_hover' => '#92400e',
            'color_primary_light' => 'rgba(180, 83, 9, 0.18)',
            'color_bg' => 'linear-gradient(145deg, #fff7ed 0%, #fef3c7 46%, #fde68a 100%)',
            'color_surface' => 'rgba(255, 252, 247, 0.96)',
            'color_border' => '#f3d7a5',
            'color_text' => '#422006',
            'color_text_muted' => '#7c5a32',
            'css_overrides' => '.login-card{max-width:420px;border:1px solid rgba(180,83,9,.18);box-shadow:0 28px 80px rgba(120,53,15,.18)}.login-logo h1{font-family:"Fraunces", Georgia, serif;font-size:2.15rem;letter-spacing:-.04em}.login-logo p{max-width:30ch;margin:10px auto 0;font-size:14px;line-height:1.5}.login-mark{font-family:"Fraunces", Georgia, serif;font-size:24px;font-weight:700}.form-label{text-transform:uppercase;letter-spacing:.08em;font-size:11px}.form-input{background:rgba(255,255,255,.88)}.btn-login{box-shadow:0 14px 30px rgba(180,83,9,.22)}body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at top left, rgba(251,191,36,.22), transparent 36%),radial-gradient(circle at bottom right, rgba(217,119,6,.16), transparent 34%);pointer-events:none}',
        ],
    ], $overrides);
}

function dlDefaultFaviconUrl(): string
{
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23b45309'/%3E%3Cpath d='M8 7h10a5 5 0 010 10H8V7zm0 10h10a5 5 0 010 10H8V17z' fill='none' stroke='%23fff' stroke-width='2' stroke-linejoin='round'/%3E%3C/svg%3E";
}

function dlNormalizeBrandAssetUrl(mixed $value, string $label = 'Brand asset URL'): string
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

function dlLogoUrl(): string
{
    try {
        return dlNormalizeBrandAssetUrl(dlModuleSettings()['logo_url'] ?? '', 'Logo URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function dlFaviconUrl(): string
{
    try {
        return dlNormalizeBrandAssetUrl(dlModuleSettings()['favicon_url'] ?? '', 'Favicon URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function dlResolvedFaviconUrl(): string
{
    $faviconUrl = dlFaviconUrl();
    return $faviconUrl !== '' ? $faviconUrl : dlDefaultFaviconUrl();
}

function dlBrandAssetUploadMaxBytes(): int
{
    if (function_exists('cmsMediaMaxUploadBytes')) {
        return max(262144, (int)cmsMediaMaxUploadBytes());
    }

    return 2 * 1024 * 1024;
}

function dlBrandAssetFallbackPath(): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return BASE_PATH . '/public/uploads/daily-ledger' . $tenantSegment;
}

function dlBrandAssetFallbackUrl(string $relativePath): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return '/uploads/daily-ledger' . $tenantSegment . '/' . ltrim($relativePath, '/');
}

function dlUploadBrandAsset(string $assetType, array $file): array
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
    if ($declaredSize > dlBrandAssetUploadMaxBytes()) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file exceeds the maximum allowed size.');
    }

    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];
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
            'dir' => cmsUploadsPath() . '/daily-ledger/' . $subDir,
            'path' => cmsUploadsPath() . '/daily-ledger/' . $subDir . '/' . $filename,
            'url' => cmsResolveUploadUrl('daily-ledger/' . $relativePath),
            'label' => 'cms',
        ];
    }
    $destinations[] = [
        'dir' => dlBrandAssetFallbackPath() . '/' . $subDir,
        'path' => dlBrandAssetFallbackPath() . '/' . $subDir . '/' . $filename,
        'url' => dlBrandAssetFallbackUrl($relativePath),
        'label' => 'fallback',
    ];

    $destinationPath = '';
    $assetUrl = '';
    $saved = false;
    foreach ($destinations as $destination) {
        if (!kernelEnsureDirectory($destination['dir'])) {
            continue;
        }
        if (!kernelCopyFile($tmpPath, $destination['path'])) {
            continue;
        }

        $destinationPath = $destination['path'];
        $assetUrl = $destination['url'];
        $saved = true;

        if ($destination['label'] === 'fallback' && function_exists('write_log')) {
            write_log('daily-ledger branding upload fell back to public module storage.', 'warning', [
                'asset_type' => $assetType,
                'relative_path' => $relativePath,
            ]);
        }
        break;
    }

    if (!$saved) {
        throw new InvalidArgumentException('Unable to save the uploaded ' . strtolower($labels[$assetType]) . '.');
    }

    if ($mimeType === 'image/svg+xml' && function_exists('cmsSanitizeSvgContent')) {
        $svg = (string)@file_get_contents($destinationPath);
        if ($svg !== '') {
            kernelWriteFile($destinationPath, cmsSanitizeSvgContent($svg));
        }
    }

    return [
        'asset_url' => $assetUrl,
        'absolute_path' => $destinationPath,
        'relative_path' => $relativePath,
        'mime_type' => $mimeType,
        'file_size' => (int)(@filesize($destinationPath) ?: $declaredSize),
    ];
}

function dl_areSellingAccountsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['selling_accounts_enabled'] ?? false);
}

function dl_arePriceGroupsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['price_groups_enabled'] ?? true);
}

function dl_defaultPriceGroupId(): ?int
{
    $ctx = module();
    if (!$ctx) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }

    $row = $ctx->db()->query('SELECT id FROM dl_price_groups WHERE is_default = 1 AND is_active = 1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    $cached = $row ? (int)$row['id'] : 0;
    return $cached ?: null;
}

function dl_branchPriceGroupId(int $branchId): ?int
{
    $ctx = module();
    if (!$ctx || $branchId <= 0) {
        return dl_defaultPriceGroupId();
    }

    static $cache = [];
    if (array_key_exists($branchId, $cache)) {
        return $cache[$branchId];
    }

    $stmt = $ctx->db()->prepare('SELECT price_group_id FROM dl_branches WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $branchId]);
    $value = $stmt->fetchColumn();
    $cache[$branchId] = ($value !== false && $value !== null) ? (int)$value : dl_defaultPriceGroupId();
    return $cache[$branchId];
}

function dl_resolveBranchProductPrice(int $branchId, int $productId, ?string $atDate = null): float
{
    return dl_resolveProductPrice($productId, dl_branchPriceGroupId($branchId), $atDate);
}

function dl_resolveProductPrice(int $productId, ?int $priceGroupId = null, ?string $atDate = null): float
{
    $ctx = module();
    if (!$ctx) {
        return 0.0;
    }

    $atDate = $atDate ?: date('Y-m-d');
    $priceGroupId = $priceGroupId ?: dl_defaultPriceGroupId();

    if (dl_arePriceGroupsEnabled() && $priceGroupId !== null) {
        $stmt = $ctx->db()->prepare(
            'SELECT selling_price FROM dl_product_prices
              WHERE product_id = :p AND price_group_id = :g AND is_active = 1
                AND effective_from <= :d1
                AND (effective_to IS NULL OR effective_to >= :d2)
              ORDER BY effective_from DESC
              LIMIT 1'
        );
        $stmt->execute([':p' => $productId, ':g' => $priceGroupId, ':d1' => $atDate, ':d2' => $atDate]);
        $price = $stmt->fetchColumn();
        if ($price !== false && $price !== null) {
            return (float)$price;
        }
    }

    $stmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :p');
    $stmt->execute([':p' => $productId]);
    return (float)($stmt->fetchColumn() ?: 0.0);
}

function dlRender(string $template, array $context = []): string
{
    if (!array_key_exists('app_name', $context)) {
        $context['app_name'] = dlAppName();
    }
    if (!array_key_exists('logo_url', $context)) {
        $context['logo_url'] = dlLogoUrl();
    }
    if (!array_key_exists('favicon_url', $context)) {
        $context['favicon_url'] = dlFaviconUrl();
    }
    if (!array_key_exists('resolved_favicon_url', $context)) {
        $context['resolved_favicon_url'] = dlResolvedFaviconUrl();
    }
    // Always supply layout-level feature flags so the sidebar can gate links
    // consistently across every admin page, even handlers that do not explicitly
    // pass them in. Existing values in $context win.
    if (function_exists('dl_layoutFlags')) {
        foreach (dl_layoutFlags() as $k => $v) {
            if (!array_key_exists($k, $context)) {
                $context[$k] = $v;
            }
        }
    }
    return dlCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function dlNormalizeLoginRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Daily Ledger Sign In',
        'app_name' => 'Daily Ledger',
        'logo_url' => '',
        'favicon_url' => '',
        'resolved_favicon_url' => dlDefaultFaviconUrl(),
        'login_forgot_url' => '/daily-ledger/forgot-password',
        'login_username_label' => 'Username or Email',
    ], ['page_title', 'app_name'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierLedgerRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
        'branch_id' => 0,
        'branch_name' => '',
        'ledger_date' => '',
        'today' => '',
        'day_status' => '',
        'branches' => [],
        'is_cashier' => false,
        'reference_only' => false,
        'can_ledger_override' => false,
        'business_date_label' => '',
        'close_of_day_time' => '',
        'auto_close_enabled' => false,
        'operating_timezone' => '',
        'operating_region' => '',
    ], ['page_title', 'user_name', 'user_role', 'current_page', 'base_url', 'branch_id', 'branch_name', 'ledger_date', 'today', 'day_status', 'branches', 'is_cashier'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierRowsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'rows' => [],
        'branch_id' => 0,
        'ledger_date' => '',
        'day_status' => '',
        'reference_only' => false,
    ], ['rows', 'branch_id', 'ledger_date', 'day_status'], $missingKeys, $typeMismatches);
}

function dlNormalizeAdminRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'app_name' => 'Daily Ledger',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
        'logo_url' => '',
        'favicon_url' => '',
        'resolved_favicon_url' => dlDefaultFaviconUrl(),
    ], ['page_title', 'app_name', 'user_name', 'user_role', 'current_page', 'base_url'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('daily-ledger.page.login', [
    'template' => 'modules/daily-ledger/pages/login.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeLoginRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.ledger', [
    'template' => 'modules/daily-ledger/cashier/ledger.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierLedgerRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.rows', [
    'template' => 'modules/daily-ledger/cashier/partials/ledger-rows.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierRowsRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.admin.shell', [
    'prefix' => 'modules/daily-ledger/admin/',
    'priority' => 20,
    'normalize' => 'dlNormalizeAdminRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

function dlRedirect(string $url, int $status = 302): void
{
    dlCtx()->redirect($url, $status);
}

function dlJson(array $data, int $status = 200): void
{
    dlCtx()->json($data, $status);
}

/**
 * Daily Ledger Module — helpers
 *
 * Module-local utilities only. Cross-module integration is via capability contracts.
 */

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (($user['source'] ?? null) !== 'daily-ledger') {
        return $url;
    }

    if ($role === 'cashier') {
        return '/daily-ledger/ledger';
    }

    if ($role === 'production_in_charge') {
        return '/daily-ledger/admin/production-output';
    }

    if (in_array($role, ['admin', 'supervisor'], true)) {
        return '/daily-ledger/admin/dashboard';
    }

    return $url;
}, 80);

function daily_ledger_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
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
    // Daily-ledger provider accepts only usernames prefixed with '@daily-ledger:'.
    $prefix = '@daily-ledger:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    $ctx = module('daily-ledger');
    if (!$ctx) {
        return null;
    }

    $row = dlFindActiveUserByIdentity($username);
    if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
        return null;
    }

    $id = (int)($row['id'] ?? 0);
    $role = (string)($row['role'] ?? '');
    if ($id <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge'], true)) {
        return null;
    }

    return [
        'user' => [
            // IMPORTANT: do not collide with kernel users.id (used by audit_logs FK).
            // The actual dl_users id is encoded in `sub` and parsed by dl_getActorUserId().
            'id' => 0,
            'sub' => $role . ':' . $id,
            'username' => (string)($row['username'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => $role,
        ],
        'source' => 'daily-ledger',
    ];
}

function dlFindActiveUserByIdentity(string $identity): ?array
{
    $identity = trim($identity);
    if ($identity === '') {
        return null;
    }

    $ctx = module('daily-ledger');
    if (!$ctx) {
        return null;
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, username, email, password_hash, full_name, role
             FROM dl_users
             WHERE (username = :username OR email = :email)
               AND is_active = 1
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':username' => $identity,
            ':email' => $identity,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — CSV Import / Export Helpers
// ─────────────────────────────────────────────────────────────────────────

function dlCsvResponse(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, $ordered);
    }

    fclose($stream);
    exit;
}

function dlCsvNormalizeHeader(string $header): string
{
    $normalized = strtolower(trim($header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function dlCsvRowsFromString(string $csvContent): array
{
    $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
    $csvContent = trim($csvContent);
    if ($csvContent === '') {
        throw new RuntimeException('CSV content is required.');
    }

    $lines = preg_split('/\r\n|\n|\r/', $csvContent) ?: [];
    $headers = null;
    $rows = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $values = str_getcsv($line);
        if ($headers === null) {
            $headers = array_map(static fn(string $header): string => dlCsvNormalizeHeader($header), $values);
            continue;
        }

        $values = array_pad($values, count($headers), null);
        $rows[] = array_combine($headers, array_map(
            static fn(mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $values
        ));
    }

    if ($headers === null) {
        throw new RuntimeException('CSV header row is required.');
    }

    return $rows;
}

function dlImportReadUploadedCsv(string $field, int $maxBytes = 5242880): array
{
    $file = kernelUploadedFile($field);
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 422, 'error' => 'Upload a valid CSV file first.'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is not available.'];
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'CSV upload did not arrive through the HTTP upload pipeline.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file exceeds the maximum allowed size.'];
    }

    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }

    return ['ok' => true, 'file' => $file, 'raw' => $raw];
}

function dlCsvNullableFloat(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!is_numeric($normalized)) {
        throw new RuntimeException('Expected a numeric decimal value.');
    }

    return round((float)$normalized, 2);
}

function dlCsvNullableInt(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^-?\d+$/', $normalized)) {
        throw new RuntimeException('Expected an integer value.');
    }

    return (int)$normalized;
}
