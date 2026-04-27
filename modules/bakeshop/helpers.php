<?php

declare(strict_types=1);

app()->registerAuthTable('bakeshop', 'bakeshop_users');

function bakeshop_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'bakeshop_cap_kernel_auth_authenticate_1',
        'bakeshop.read@1' => 'bakeshop_cap_bakeshop_read_1',
        'bakeshop.manage@1' => 'bakeshop_cap_bakeshop_manage_1',
        'bakeshop.product.read@1' => 'bakeshop_cap_bakeshop_product_read_1',
        'bakeshop.ingredient.usage.read@1' => 'bakeshop_cap_bakeshop_ingredient_usage_read_1',
    ];
}

function bakeshopBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function bakeshopCookieName(): string
{
    return 'bakeshop_token';
}

function bakeshopSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(bakeshopCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function bakeshopClearAuthCookie(): void
{
    setcookie(bakeshopCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function bakeshopSupportsTokenVersion(): bool
{
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }

    try {
        bakeshopDb()->query('SELECT token_version FROM bakeshop_users LIMIT 1');
        $supported = true;
    } catch (Throwable $e) {
        $supported = false;
    }

    return $supported;
}

function bakeshopUserTokenVersion(int $userId): int
{
    if ($userId <= 0 || !bakeshopSupportsTokenVersion()) {
        return 0;
    }

    try {
        $stmt = bakeshopDb()->prepare(
            'SELECT COALESCE(token_version, 0) AS token_version FROM bakeshop_users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['token_version'] ?? 0) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function bakeshopIsKernelSuperadmin(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return (($user['source'] ?? '') === 'kernel') && (($user['role'] ?? '') === 'superadmin');
}

function bakeshopIsModuleUser(?array $user): bool
{
    return is_array($user) && (($user['source'] ?? '') === 'bakeshop');
}

function bakeshopBootstrapUsername(): string
{
    return 'bakeshopadmin';
}

function bakeshopBootstrapEmail(): string
{
    return 'admin@bakeshop.local';
}

function bakeshopLegacyBootstrapPasswordHash(): string
{
    return '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
}

function bakeshopBootstrapPasswordResetMarker(): string
{
    return '!bakeshop-bootstrap-password-reset-required!';
}

function bakeshopUserHasUnsafeBootstrapPassword(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    $username = strtolower(trim((string)($user['username'] ?? '')));
    $passwordHash = (string)($user['password_hash'] ?? '');

    return $username === bakeshopBootstrapUsername()
        && $passwordHash !== ''
        && in_array($passwordHash, [
            bakeshopLegacyBootstrapPasswordHash(),
            bakeshopBootstrapPasswordResetMarker(),
        ], true);
}

function bakeshopUserHasLegacyBootstrapPassword(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return strtolower(trim((string)($user['username'] ?? ''))) === bakeshopBootstrapUsername()
        && hash_equals(bakeshopLegacyBootstrapPasswordHash(), (string)($user['password_hash'] ?? ''));
}

function bakeshopIsBootstrapUser(?array $user): bool
{
    if (!bakeshopIsModuleUser($user)) {
        return false;
    }

    return strtolower(trim((string)($user['username'] ?? ''))) === bakeshopBootstrapUsername();
}

function bakeshopBootstrapOnboardingState(): array
{
    $state = [
        'required' => false,
        'needs_successor_admin' => false,
        'can_retire_bootstrap' => false,
        'password_setup_required' => false,
        'other_admin_count' => 0,
        'bootstrap_user' => null,
    ];

    try {
        $stmt = bakeshopDb()->prepare(
            'SELECT id, username, email, full_name, role, is_active
             FROM bakeshop_users
             WHERE username = ?
             LIMIT 1'
        );
        $stmt->execute([bakeshopBootstrapUsername()]);
        $bootstrapUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bootstrapUser) || (int)($bootstrapUser['is_active'] ?? 0) !== 1 || (string)($bootstrapUser['role'] ?? '') !== 'admin') {
            return $state;
        }

        $otherAdminStmt = bakeshopDb()->prepare(
            "SELECT COUNT(*)
             FROM bakeshop_users
             WHERE role = 'admin' AND is_active = 1 AND id <> ?"
        );
        $otherAdminStmt->execute([(int)($bootstrapUser['id'] ?? 0)]);
        $otherAdminCount = (int)$otherAdminStmt->fetchColumn();

        return [
            'required' => true,
            'needs_successor_admin' => $otherAdminCount === 0,
            'can_retire_bootstrap' => $otherAdminCount > 0,
            'password_setup_required' => bakeshopUserHasUnsafeBootstrapPassword($bootstrapUser),
            'other_admin_count' => $otherAdminCount,
            'bootstrap_user' => $bootstrapUser,
        ];
    } catch (Throwable $e) {
        return $state;
    }
}

function bakeshopShouldForceBootstrapOnboarding(?array $user, ?array $state = null): bool
{
    $state = $state ?? bakeshopBootstrapOnboardingState();

    return bakeshopIsBootstrapUser($user) && (($state['required'] ?? false) === true);
}

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (!in_array($role, ['admin', 'supervisor', 'superadmin'], true)) {
        return $url;
    }

    if (!bakeshopIsModuleUser($user) && !bakeshopIsKernelSuperadmin($user)) {
        return $url;
    }

    if (!function_exists('tenantEntryModuleIdForTenant')) {
        return $url;
    }

    try {
        $tenantId = app()->tenant()->current();
    } catch (Throwable $e) {
        return $url;
    }

    if ($tenantId === null || tenantEntryModuleIdForTenant((int)$tenantId) !== 'bakeshop') {
        return $url;
    }

    if (bakeshopShouldForceBootstrapOnboarding($user)) {
        return '/admin/bakeshop?onboarding=bootstrap';
    }

    return '/admin/bakeshop';
}, 90);

function bakeshopLoginPageContext(array $overrides = []): array
{
    $baseUrl = bakeshopBaseUrl();

    return array_merge([
        'page_title' => 'Bakeshop Sign In',
        'login_subtitle' => 'Sign in with your bakeshop staff account to manage recipes, deliveries, production, and daily usage.',
        'login_username_label' => 'Bakeshop Username or Email',
        'login_endpoint' => $baseUrl . '/bakeshop/auth/login',
        'login_button_text' => 'Enter Bakeshop',
        'login_loading_text' => 'Opening workspace...',
        'login_brand_html' => '<span>Julie\'s</span> Bakeshop',
        'login_helper_title' => 'After You Sign In',
        'login_helper_html' => '<p>You will land directly in the operations workspace with your bakeshop role applied.</p><ul><li>Admins can manage staff accounts, passwords, and workspace permissions.</li><li>Supervisors can stay focused on production, deliveries, and usage reporting.</li><li>Use the print summary when you need a clean branch usage report.</li></ul>',
        'gui' => [
            'app_name' => 'Julie\'s Bakeshop',
            'app_name_accent' => 'Julie\'s',
            'app_name_rest' => 'Bakeshop',
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
            'css_overrides' => '.login-card{max-width:460px;border:1px solid rgba(180,83,9,.18);box-shadow:0 28px 80px rgba(120,53,15,.18)}.login-logo h1{font-family:"Fraunces", Georgia, serif;font-size:2.2rem;letter-spacing:-.04em}.login-logo h1 span{display:inline-block;color:#9a3412}.login-logo p{max-width:32ch;margin:10px auto 0;font-size:14px;line-height:1.5}.form-label{text-transform:uppercase;letter-spacing:.08em;font-size:11px}.form-input{background:rgba(255,255,255,.88)}.btn-login{box-shadow:0 14px 30px rgba(180,83,9,.22)}body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at top left, rgba(251,191,36,.22), transparent 36%),radial-gradient(circle at bottom right, rgba(217,119,6,.16), transparent 34%);pointer-events:none}',
        ],
    ], $overrides);
}

function bakeshopCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('bakeshop');
    if (!$ctx) {
        throw new \RuntimeException('Bakeshop module context is unavailable.');
    }

    return $ctx;
}

function bakeshopUser(): ?array
{
    return bakeshopCtx()->user();
}

function bakeshopCanManageUsers(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['admin', 'superadmin'], true);
}

function bakeshopCanManageSettings(array $user): bool
{
    if (bakeshopIsKernelSuperadmin($user)) {
        return true;
    }

    return bakeshopRoleHasPermission((string)($user['role'] ?? ''), 'bakeshop.manage');
}

function bakeshopCanViewHistory(array $user): bool
{
    return bakeshopCanManageUsers($user);
}

function bakeshopPageContext(array $user, string $currentPage, array $extra = []): array
{
    return array_merge([
        'user' => $user,
        'current_page' => $currentPage,
        'page_title' => 'Bakeshop Operations',
        'base_url' => bakeshopBaseUrl(),
        'csrf_token' => app()->csrfToken(),
        'can_manage_users' => bakeshopCanManageUsers($user),
        'can_manage_settings' => bakeshopCanManageSettings($user),
        'can_view_history' => bakeshopCanViewHistory($user),
    ], $extra);
}

function bakeshopDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return bakeshopCtx()->db();
}

function bakeshopTableHasColumn(string $table, string $column, bool $refresh = false): bool
{
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new InvalidArgumentException('Invalid table or column identifier.');
    }

    $tenantKey = (string)(app()->tenant()->current() ?? 'global');
    $cacheKey = $tenantKey . ':' . $table . ':' . $column;
    if (!$refresh && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = bakeshopDb()->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $cache[$cacheKey] = $stmt->fetch(PDO::FETCH_ASSOC) !== false;

    return $cache[$cacheKey];
}

function bakeshopUnitRecord(int $unitId): ?array
{
    if ($unitId <= 0) {
        return null;
    }

    $stmt = bakeshopDb()->prepare(
        'SELECT id, code, name, dimension
         FROM bakeshop_units
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function bakeshopAssertIngredientUnitCompatible(int $ingredientId, int $unitId, string $field = 'unit_id'): void
{
    if ($ingredientId <= 0 || $unitId <= 0) {
        throw new InvalidArgumentException($field . ' must reference a valid ingredient unit.');
    }

    $ingredientStmt = bakeshopDb()->prepare(
        'SELECT i.id, i.name, u.id AS default_unit_id, u.code AS default_unit_code, u.dimension AS default_unit_dimension
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         WHERE i.id = ?
         LIMIT 1'
    );
    $ingredientStmt->execute([$ingredientId]);
    $ingredient = $ingredientStmt->fetch(PDO::FETCH_ASSOC);
    $unit = bakeshopUnitRecord($unitId);

    if (!is_array($ingredient) || !is_array($unit)) {
        throw new InvalidArgumentException($field . ' must reference a valid ingredient unit.');
    }

    $ingredientDimension = strtolower(trim((string)($ingredient['default_unit_dimension'] ?? '')));
    $unitDimension = strtolower(trim((string)($unit['dimension'] ?? '')));
    if ($ingredientDimension === '' || $unitDimension === '' || $ingredientDimension === $unitDimension) {
        return;
    }

    $ingredientName = trim((string)($ingredient['name'] ?? 'ingredient'));
    $defaultUnitCode = trim((string)($ingredient['default_unit_code'] ?? ''));
    $selectedUnitCode = trim((string)($unit['code'] ?? ''));

    throw new InvalidArgumentException(
        sprintf(
            '%s must match the ingredient unit dimension for %s. Expected %s-compatible units, received %s.',
            $field,
            $ingredientName !== '' ? $ingredientName : 'this ingredient',
            $defaultUnitCode !== '' ? $defaultUnitCode : $ingredientDimension,
            $selectedUnitCode !== '' ? $selectedUnitCode : $unitDimension
        )
    );
}

function bakeshopInput(?string $key = null, mixed $default = null): mixed
{
    return bakeshopCtx()->input($key, $default);
}

function bakeshopRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/bakeshop/')
        ? $template
        : 'modules/bakeshop/' . ltrim($template, '/');

    return bakeshopCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function bakeshopAudit(string $action, ?int $branchId = null, ?string $entityType = null, ?string $entityId = null, mixed $oldData = null, mixed $newData = null, ?string $reason = null): void
{
    try {
        bakeshopCtx()->audit($action, $branchId, $entityType, $entityId, $oldData, $newData, $reason);
    } catch (Throwable $e) {
        try {
            bakeshopCtx()->log('bakeshop audit failed: ' . $e->getMessage(), 'error');
        } catch (Throwable $ignored) {
        }
    }
}

function bakeshopResolveActiveMutationAction(string $prefix, ?array $oldData, ?array $newData): string
{
    if ($oldData === null) {
        return $prefix . '.created';
    }

    if ($newData !== null && array_key_exists('is_active', $oldData) && array_key_exists('is_active', $newData)) {
        $oldActive = (int)($oldData['is_active'] ?? 1);
        $newActive = (int)($newData['is_active'] ?? 1);
        if ($oldActive !== $newActive) {
            return $prefix . ($newActive === 0 ? '.archived' : '.restored');
        }
    }

    return $prefix . '.updated';
}

function bakeshopJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function bakeshopJsonOk(array $data = [], int $status = 200): void
{
    bakeshopJson([
        'ok' => true,
        'data' => $data,
    ], $status);
}

function bakeshopJsonError(string $message, int $status = 422, array $extra = []): void
{
    bakeshopJson([
        'ok' => false,
        'error' => $message,
        'details' => $extra,
    ], $status);
}

function bakeshopSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['bakeshop'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function bakeshopSettings(): array
{
    return array_merge(bakeshopSettingsDefaults(), getModuleSettings('bakeshop'));
}

function bakeshopNormalizeUsageDecimalPlaces(mixed $value): int
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        $value = bakeshopSettingsDefaults()['usage_decimal_places'] ?? '2';
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Usage decimal places must be numeric.');
    }

    $places = (int)$value;
    if ($places < 0 || $places > 4) {
        throw new InvalidArgumentException('Usage decimal places must be between 0 and 4.');
    }

    return $places;
}

function bakeshopSupportedPrintTemplates(): array
{
    return ['standard'];
}

function bakeshopNormalizePrintTemplate(mixed $value): string
{
    $template = strtolower(trim((string)$value));
    if ($template === '') {
        $template = (string)(bakeshopSettingsDefaults()['print_template'] ?? 'standard');
    }

    if (!in_array($template, bakeshopSupportedPrintTemplates(), true)) {
        throw new InvalidArgumentException('Unsupported print template.');
    }

    return $template;
}

function bakeshopUsageDecimalPlaces(): int
{
    $settings = bakeshopSettings();
    return bakeshopNormalizeUsageDecimalPlaces($settings['usage_decimal_places'] ?? null);
}

function bakeshopPrintTemplate(): string
{
    $settings = bakeshopSettings();
    return bakeshopNormalizePrintTemplate($settings['print_template'] ?? null);
}

function bakeshop_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    $prefix = '@bakeshop:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }

    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    try {
        $stmt = bakeshopDb()->prepare(
            "SELECT id, username, email, phone, password_hash, full_name, role, is_active\n"
            . "FROM bakeshop_users\n"
            . "WHERE (username = :username OR email = :email) AND is_active = 1\n"
            . "LIMIT 1"
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $username,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || bakeshopUserHasUnsafeBootstrapPassword($row)) {
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
                'phone' => (string)($row['phone'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'supervisor'),
                'sub' => 'bakeshop:' . (int)($row['id'] ?? 0),
                'token_version' => bakeshopUserTokenVersion((int)($row['id'] ?? 0)),
            ],
            'source' => 'bakeshop',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function bakeshopCapabilityPermissionResult(string $permission, mixed $payload): array
{
    $data = is_array($payload) ? $payload : [];
    $user = is_array($data['user'] ?? null) ? $data['user'] : bakeshopUser();
    $allowed = false;

    if (bakeshopIsKernelSuperadmin($user)) {
        $allowed = true;
    } elseif (bakeshopIsModuleUser($user)) {
        $allowed = bakeshopRoleHasPermission((string)($user['role'] ?? ''), $permission);
    }

    $data['allowed'] = $allowed;
    $data['permission'] = $permission;
    return $data;
}

function bakeshop_cap_bakeshop_read_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return bakeshopCapabilityPermissionResult('bakeshop.read', $payload);
}

function bakeshop_cap_bakeshop_manage_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return bakeshopCapabilityPermissionResult('bakeshop.manage', $payload);
}

function bakeshop_cap_bakeshop_product_read_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return bakeshopCapabilityPermissionResult('bakeshop.read', $payload);
}

function bakeshop_cap_bakeshop_ingredient_usage_read_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return bakeshopCapabilityPermissionResult('bakeshop.read', $payload);
}