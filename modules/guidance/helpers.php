<?php

declare(strict_types=1);

function guidance_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'guidance_cap_kernel_auth_authenticate_1',
        'entity.list.guidance_case@1' => 'gm_cap_entity_list_case_1',
        'entity.get.guidance_case@1' => 'gm_cap_entity_get_case_1',
        'entity.list.guidance_appointment@1' => 'gm_cap_entity_list_appointment_1',
        'entity.get.guidance_appointment@1' => 'gm_cap_entity_get_appointment_1',
        'guidance.case.status.update@1' => 'gm_cap_case_status_update_1',
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
    $resolvedTemplate = str_starts_with($template, 'modules/guidance/')
        ? $template
        : 'modules/guidance/' . ltrim($template, '/');

    return guidanceCtx()->render($resolvedTemplate, kernelPrepareRenderContext($resolvedTemplate, $context));
}

function guidanceNormalizePageRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'base_url' => '/admin/guidance',
        'current_page' => '',
        'is_pro' => false,
        'user_name' => '',
        'user_role' => '',
        'user_initials' => '',
        'today_date' => '',
        'hour' => 0,
    ], ['page_title'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('guidance.page.shell', [
    'prefix' => 'modules/guidance/pages/',
    'priority' => 20,
    'normalize' => 'guidanceNormalizePageRenderContext',
    'log_event' => 'guidance.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('guidance.template.base', [
    'prefixes' => ['modules/guidance/partials/', 'modules/guidance/modals/'],
    'priority' => 20,
    'defaults' => ['base_url' => '/admin/guidance'],
    'log_event' => 'guidance.render_context.contract_mismatch',
]);

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
    if (function_exists('kernelEmitEvent')) {
        try {
            kernelEmitEvent($event, $payload, 'guidance');
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log('guidanceFireEvent error: ' . $e->getMessage(), 'warning', ['event' => $event]);
            }
        }
        return;
    }

    $ctx = module('guidance');
    if (!$ctx) {
        return;
    }

    try {
        $ctx->fireEvent($event, $payload);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('guidanceFireEvent fallback error: ' . $e->getMessage(), 'warning', ['event' => $event]);
        }
    }
}

// ---------------------------------------------------------------------------
// Cache helpers — call these from mutation handlers to invalidate stale data
// ---------------------------------------------------------------------------

/** Invalidate appointment stats + dashboard stats caches. */
function guidanceClearAppointmentStatsCache(): void
{
    app()->cache()->clearByTags('guidance', ['guidance:appointment-stats', 'guidance:stats']);
}

/** Invalidate case stats + dashboard stats caches. */
function guidanceClearCaseStatsCache(): void
{
    app()->cache()->clearByTags('guidance', ['guidance:case-stats', 'guidance:stats']);
}

/** Invalidate tracker list cache. */
function guidanceClearTrackerCache(): void
{
    app()->cache()->clearByTag('guidance', 'guidance:trackers');
}

function guidanceGetSetting(string $key, ?string $default = null): ?string
{
    // No per-request static cache: gm_settings is a small table and settings
    // can be mutated mid-request (tests upsert settings between reads; the
    // settings admin UI saves values that must be visible to the very next
    // read). A static batch cache made those writes invisible until process
    // restart, causing stale notification_channel / email_notifications etc.
    // after a settings save within the same request. Read the DB directly.
    try {
        $stmt = guidanceDb()->prepare("SELECT setting_value FROM gm_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        $value = ($raw !== false && $raw !== null) ? (string)$raw : $default;
    } catch (Throwable $e) {
        $value = $default;
    }

    return $value;
}

function guidanceAllowedFormTypes(): array
{
    return ['case', 'booking', 'appointment'];
}

function guidanceParseFormFieldOptions(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $raw), static function (string $value): bool {
            return $value !== '';
        }));
    }

    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return guidanceParseFormFieldOptions($decoded);
    }

    return array_values(array_filter(array_map(static function (string $value): string {
        return trim($value);
    }, explode(',', $raw)), static function (string $value): bool {
        return $value !== '';
    }));
}

function guidanceNormalizeFormFieldOptions(mixed $raw): ?string
{
    $options = guidanceParseFormFieldOptions($raw);
    if ($options === []) {
        return null;
    }

    return json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
}

function guidanceGetFormFields(string $formType): array
{
    if (!in_array($formType, guidanceAllowedFormTypes(), true)) {
        return [];
    }

    // Per-request cache per form type: form field definitions don't change
    // within a request and are often read multiple times (validation + render).
    static $cache = [];
    if (array_key_exists($formType, $cache)) {
        return $cache[$formType];
    }

    try {
        $stmt = guidanceDb()->prepare(
            'SELECT * FROM gm_form_fields WHERE form_type = ? AND is_enabled = 1 ORDER BY sort_order, id'
        );
        $stmt->execute([$formType]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $cache[$formType] = [];
        return [];
    }

    foreach ($fields as &$field) {
        $field['parsed_options'] = guidanceParseFormFieldOptions($field['field_options'] ?? null);
    }
    unset($field);

    $cache[$formType] = $fields;
    return $fields;
}

function guidanceFilterFormFields(array $fields, array $options = []): array
{
    $includeGroups = array_values(array_filter(array_map('strval', (array)($options['include_groups'] ?? []))));
    $excludeGroups = array_values(array_filter(array_map('strval', (array)($options['exclude_groups'] ?? []))));
    $includeFields = array_values(array_filter(array_map('strval', (array)($options['include_fields'] ?? []))));
    $excludeFields = array_values(array_filter(array_map('strval', (array)($options['exclude_fields'] ?? []))));

    return array_values(array_filter($fields, static function (array $field) use ($includeGroups, $excludeGroups, $includeFields, $excludeFields): bool {
        $group = (string)($field['field_group'] ?? '');
        $name = (string)($field['field_name'] ?? '');

        if ($includeGroups !== [] && !in_array($group, $includeGroups, true)) {
            return false;
        }
        if ($excludeGroups !== [] && in_array($group, $excludeGroups, true)) {
            return false;
        }
        if ($includeFields !== [] && !in_array($name, $includeFields, true)) {
            return false;
        }
        if ($excludeFields !== [] && in_array($name, $excludeFields, true)) {
            return false;
        }

        return true;
    }));
}

function guidanceGetFormFieldsGrouped(string $formType, array $options = []): array
{
    $fields = guidanceFilterFormFields(guidanceGetFormFields($formType), $options);
    $groups = [];

    foreach ($fields as $field) {
        $group = trim((string)($field['field_group'] ?? ''));
        if ($group === '') {
            $group = 'General';
        }

        if (!isset($groups[$group])) {
            $groups[$group] = [];
        }

        $groups[$group][] = $field;
    }

    return $groups;
}

function guidanceFindFormField(string $formType, string $fieldName): ?array
{
    foreach (guidanceGetFormFields($formType) as $field) {
        if ((string)($field['field_name'] ?? '') === $fieldName) {
            return $field;
        }
    }

    return null;
}

function guidanceGetRequiredFormFields(string $formType): array
{
    return array_values(array_map(static function (array $field): string {
        return (string)$field['field_name'];
    }, array_filter(guidanceGetFormFields($formType), static function (array $field): bool {
        return !empty($field['is_required']);
    })));
}

function guidanceValidateFormInput(string $formType, array $input, array $options = []): array
{
    $ignoreFields = array_values(array_filter(array_map('strval', (array)($options['ignore_fields'] ?? []))));
    $errors = [];

    foreach (guidanceGetRequiredFormFields($formType) as $fieldName) {
        if (in_array($fieldName, $ignoreFields, true)) {
            continue;
        }

        $value = $input[$fieldName] ?? null;
        $missing = false;

        if (is_array($value)) {
            $missing = count(array_filter($value, static function ($item): bool {
                return trim((string)$item) !== '';
            })) === 0;
        } elseif (!array_key_exists($fieldName, $input)) {
            $missing = true;
        } else {
            $missing = trim((string)$value) === '';
        }

        if ($missing) {
            $errors[] = ucfirst(str_replace('_', ' ', $fieldName)) . ' is required';
        }
    }

    return $errors;
}

function guidanceRenderFormField(array $field, mixed $value = null, array $extra = []): string
{
    $name = (string)($field['field_name'] ?? '');
    if ($name === '') {
        return '';
    }

    $type = strtolower(trim((string)($field['field_type'] ?? 'text')));
    $label = htmlspecialchars((string)($field['field_label'] ?? $name), ENT_QUOTES, 'UTF-8');
    $placeholder = htmlspecialchars((string)($field['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8');
    $required = !empty($field['is_required']);
    $requiredAttr = $required ? ' required' : '';
    $requiredStar = $required ? ' *' : '';

    if ($value === null || $value === '') {
        $value = $field['default_value'] ?? null;
    }

    $inputId = $name === 'college_id'
        ? 'college-select'
        : 'guidance-field-' . preg_replace('/[^a-z0-9_\-]+/i', '-', $name);

    $baseClass = 'w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
    $escapedValue = htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    $html = '<div>';

    if ($type === 'hidden') {
        return '<input type="hidden" id="' . $inputId . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . $escapedValue . '">';
    }

    if ($type === 'checkbox') {
        $checked = !empty($value) ? ' checked' : '';
        $html .= '<label class="flex items-center">';
        $html .= '<input type="checkbox" id="' . $inputId . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"' . $checked . $requiredAttr . '>';
        $html .= '<span class="ml-2 text-sm text-gray-700">' . $label . $requiredStar . '</span>';
        $html .= '</label>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<label class="block text-sm font-medium text-gray-700 mb-1" for="' . $inputId . '">' . $label . $requiredStar . '</label>';

    if ($type === 'textarea') {
        $html .= '<textarea id="' . $inputId . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" rows="3" placeholder="' . $placeholder . '" class="' . $baseClass . '"' . $requiredAttr . '>' . htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea>';
        $html .= '</div>';
        return $html;
    }

    if ($type === 'select') {
        $html .= '<select id="' . $inputId . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="' . $baseClass . '"' . $requiredAttr . '>';
        $html .= '<option value="">Select...</option>';

        if ($name === 'college_id' && !empty($extra['colleges']) && is_array($extra['colleges'])) {
            foreach ($extra['colleges'] as $college) {
                if (!is_array($college)) {
                    continue;
                }
                $optionValue = (string)($college['id'] ?? '');
                if ($optionValue === '') {
                    continue;
                }
                $optionLabel = trim((string)($college['code'] ?? '') . ' - ' . (string)($college['name'] ?? ''));
                $selected = (string)$value === $optionValue ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') . '</option>';
            }
        } else {
            foreach ((array)($field['parsed_options'] ?? []) as $option) {
                $optionValue = trim((string)$option);
                if ($optionValue === '') {
                    continue;
                }
                $selected = (string)$value === $optionValue ? ' selected' : '';
                $optionLabel = ucfirst(str_replace('_', ' ', $optionValue));
                $html .= '<option value="' . htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') . '</option>';
            }
        }

        $html .= '</select>';
        $html .= '</div>';
        return $html;
    }

    $inputType = in_array($type, ['date', 'email', 'tel', 'number', 'time'], true) ? $type : 'text';
    $html .= '<input type="' . $inputType . '" id="' . $inputId . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . $escapedValue . '" placeholder="' . $placeholder . '" class="' . $baseClass . '"' . $requiredAttr . '>';
    $html .= '</div>';
    return $html;
}

function guidanceRenderFormFields(string $formType, array $values = [], array $extra = [], array $options = []): string
{
    $grouped = guidanceGetFormFieldsGrouped($formType, $options);
    if ($grouped === []) {
        return '';
    }

    $showGroupHeadings = !array_key_exists('show_group_headings', $options) || !empty($options['show_group_headings']);

    $groupIcons = [
        'Student Information' => 'fa-user-graduate',
        'Case Details' => 'fa-clipboard-list',
        'Parent/Guardian' => 'fa-users',
        'Personal Information' => 'fa-user',
        'Appointment Details' => 'fa-calendar-alt',
        'Schedule' => 'fa-clock',
        'Details' => 'fa-info-circle',
        'General' => 'fa-list',
    ];

    $html = '';
    foreach ($grouped as $groupName => $fields) {
        $icon = $groupIcons[$groupName] ?? 'fa-list';
        $regularFields = [];
        $checkboxFields = [];

        foreach ($fields as $field) {
            if ((string)($field['field_type'] ?? '') === 'checkbox') {
                $checkboxFields[] = $field;
            } else {
                $regularFields[] = $field;
            }
        }

        $html .= '<div>';
        if ($regularFields !== []) {
            if ($showGroupHeadings) {
                $html .= '<h3 class="text-lg font-medium text-gray-800 mb-4">';
                $html .= '<i class="fas ' . $icon . ' mr-2 text-indigo-500"></i> ' . htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8');
                $html .= '</h3>';
            }
            $html .= '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            foreach ($regularFields as $field) {
                $name = (string)($field['field_name'] ?? '');
                $value = $values[$name] ?? null;
                $colClass = (string)($field['grid_column'] ?? 'full') === 'full' ? 'md:col-span-2' : '';
                $html .= '<div class="' . $colClass . '">';
                $html .= guidanceRenderFormField($field, $value, $extra);
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        if ($checkboxFields !== []) {
            $html .= '<div class="mt-4 flex flex-wrap items-center gap-6">';
            foreach ($checkboxFields as $field) {
                $name = (string)($field['field_name'] ?? '');
                $value = $values[$name] ?? null;
                $html .= guidanceRenderFormField($field, $value, $extra);
            }
            $html .= '</div>';
        }

        $html .= '</div>';
    }

    return $html;
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

    $row = guidanceFindActiveUserByIdentity($username);
    if (!is_array($row) || empty($row['is_active'])) {
        return null;
    }

    if (!password_verify($password, (string)($row['password'] ?? ''))) {
        return null;
    }

    $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));

    $user = [
        'id' => (int)($row['id'] ?? 0),
        'username' => (string)($row['email'] ?? ''),
        'full_name' => $fullName !== '' ? $fullName : (string)($row['email'] ?? ''),
        'role' => (string)($row['role'] ?? 'counselor'),
    ];

    return ['user' => $user, 'source' => 'guidance'];
}

function guidanceFindActiveUserByIdentity(string $identity): ?array
{
    $identity = strtolower(trim($identity));
    if ($identity === '') {
        return null;
    }

    $localPart = $identity;
    if (str_contains($identity, '@')) {
        $parts = explode('@', $identity, 2);
        $localPart = strtolower(trim((string)($parts[0] ?? '')));
    }

    try {
        $stmt = guidanceDb()->prepare(
            "SELECT id, email, password, first_name, last_name, role, is_active\n"
            . "FROM gm_users\n"
            . "WHERE (LOWER(email) = :identity OR LOWER(SUBSTRING_INDEX(email, '@', 1)) = :local_part)\n"
            . "  AND deleted_at IS NULL\n"
            . "LIMIT 1"
        );
        $stmt->execute([
            ':identity' => $identity,
            ':local_part' => $localPart,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get the current entitlement tier for the tenant running this guidance module.
 * Returns 'free' or 'pro'.
 */
function guidanceTenantTier(): string {
    $tenantId = (int)(app()->tenant()->current() ?? 0);
    $entitlement = moduleTenantEntitlementRow('guidance', $tenantId);
    
    // Safely extract tier, default to free
    if (!$entitlement || empty($entitlement['tier'])) {
        return 'free';
    }
    
    return strtolower((string)$entitlement['tier']) === 'pro' ? 'pro' : 'free';
}

/**
 * Check if the tenant is on the PRO tier for the guidance module.
 */
function guidanceIsPro(): bool {
    return guidanceTenantTier() === 'pro';
}

function guidanceNormalizeExternalUrl(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $host = \Ikabud\Kernel\TenantResolver::normalizeHost((string)($parts['host'] ?? ''));
    if ($host === '') {
        return '';
    }

    $scheme = strtolower(trim((string)($parts['scheme'] ?? 'https')));
    if ($scheme === '') {
        $scheme = 'https';
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = trim((string)($parts['path'] ?? ''));
    if ($path !== '' && $path !== '/') {
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
    } else {
        $path = '';
    }

    return $scheme . '://' . $host . $port . $path;
}

function guidanceExternalUrlHost(?string $value): string
{
    $normalized = guidanceNormalizeExternalUrl($value);
    if ($normalized === '') {
        return '';
    }

    return \Ikabud\Kernel\TenantResolver::normalizeHost((string)(parse_url($normalized, PHP_URL_HOST) ?? ''));
}

function guidanceLicenseStoreUrl(?int $tenantId = null): string
{
    $settings = [];

    if ($tenantId !== null && $tenantId > 0 && function_exists('readTenantModuleSettingsForTenant')) {
        $settings = readTenantModuleSettingsForTenant('guidance', $tenantId);
    } elseif (function_exists('readTenantModuleSettings')) {
        $settings = readTenantModuleSettings('guidance');
    }

    if (!is_array($settings)) {
        return '';
    }

    return guidanceNormalizeExternalUrl((string)($settings['license_store_url'] ?? ''));
}

function guidanceCurrentRequestHost(): string
{
    return \Ikabud\Kernel\TenantResolver::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
}

function guidanceBundledLicensePublicKey(): string
{
    $path = __DIR__ . '/license-key.pem';
    if (!is_file($path)) {
        return '';
    }

    return (string)file_get_contents($path);
}

/**
 * @return array<string, string>
 */
function guidanceLicensePublicKeyCandidates(?int $tenantId = null, string $issuerHost = ''): array
{
    $candidates = [];
    $seen = [];

    if (($tenantId === null || $tenantId <= 0) && function_exists('moduleTenantSettingsTenantId')) {
        $resolvedTenantId = moduleTenantSettingsTenantId();
        if ($resolvedTenantId !== null && $resolvedTenantId > 0) {
            $tenantId = (int)$resolvedTenantId;
        }
    }

    $register = static function (string $source, string $pem) use (&$candidates, &$seen): void {
        $pem = trim($pem);
        if ($pem === '') {
            return;
        }

        $fingerprint = sha1($pem);
        if (isset($seen[$fingerprint])) {
            return;
        }

        $seen[$fingerprint] = true;
        $candidates[$source] = $pem;
    };

    $guidanceSettings = [];
    if ($tenantId !== null && $tenantId > 0 && function_exists('readTenantModuleSettingsForTenant')) {
        $guidanceSettings = readTenantModuleSettingsForTenant('guidance', $tenantId);
    } elseif (function_exists('readTenantModuleSettings')) {
        $guidanceSettings = readTenantModuleSettings('guidance');
    }
    if (!is_array($guidanceSettings)) {
        $guidanceSettings = [];
    }

    $register('guidance_module_setting', (string)($guidanceSettings['license_public_key_pem'] ?? ''));

    // Always try to load the ecommerce module public key for the current tenant as a fallback.
    // This allows the store tenant to activate modules on itself without manually copy-pasting the public key.
    $ecommerceSettings = [];
    if ($tenantId !== null && $tenantId > 0 && function_exists('readTenantModuleSettingsForTenant')) {
        $ecommerceSettings = readTenantModuleSettingsForTenant('ecommerce', $tenantId);
    } elseif (function_exists('readTenantModuleSettings')) {
        $ecommerceSettings = readTenantModuleSettings('ecommerce');
    }
    
    if (is_array($ecommerceSettings)) {
        $register('ecommerce_current_tenant_setting', (string)($ecommerceSettings['license_public_key_pem'] ?? ''));
    }

    $storeHost = guidanceExternalUrlHost((string)($guidanceSettings['license_store_url'] ?? ''));
    if ($storeHost === '' && $issuerHost !== '') {
        $storeHost = $issuerHost;
    }
    $requestHost = guidanceCurrentRequestHost();

    // If the configured store is hosted on this same multi-tenant database cluster,
    // pull the public key directly from the remote store tenant's ecommerce settings.
    if ($storeHost !== '' && $storeHost !== $requestHost && class_exists('\Ikabud\Kernel\TenantResolver')) {
        $record = \Ikabud\Kernel\TenantResolver::lookupControlHostRecord($storeHost);
        $storeTenantId = (is_array($record) && isset($record['tenant_id'])) ? (int)$record['tenant_id'] : null;

        // Map the main application domain to the default tenant ID if it lacks a domain mapping record.
        if (($storeTenantId === null || $storeTenantId <= 0) && function_exists('app')) {
            $defaultTid = (int)app()->config('app.multi_tenant.default', 1);
            $appHost = \Ikabud\Kernel\TenantResolver::normalizeHost((string)parse_url(app()->config('app.url', ''), PHP_URL_HOST));
            
            if ($appHost !== '' && $storeHost === $appHost) {
                $storeTenantId = $defaultTid;
            } elseif (app()->config('app.multi_tenant.enabled')) {
                // Failsafe: if the issuer domain wasn't found in control_host and didn't perfectly match APP_URL,
                // aggressively trial the default tenant's public key. OpenSSL organically rejects wrong keys, 
                // making this 100% secure while preventing false "invalid signature" blocks for sub-tenants
                // whose root default tenant lacks explicit control_host domain routing.
                $storeTenantId = $defaultTid;
            }
        }

        if ($storeTenantId !== null && $storeTenantId > 0 && function_exists('readTenantModuleSettingsForTenant')) {
            $remoteSettings = readTenantModuleSettingsForTenant('ecommerce', $storeTenantId);
            if (is_array($remoteSettings)) {
                $register('ecommerce_remote_tenant_setting', (string)($remoteSettings['license_public_key_pem'] ?? ''));
            }
        }
    }

    $register('bundled_file', guidanceBundledLicensePublicKey());

    return $candidates;
}

function guidanceLicenseIssuerHost(array $claims): string
{
    foreach (['iss_url', 'issuer_url', 'store_url'] as $key) {
        $raw = trim((string)($claims[$key] ?? ''));
        if ($raw === '') {
            continue;
        }

        return guidanceExternalUrlHost($raw);
    }

    foreach (['iss_host', 'issuer_host', 'store_host'] as $key) {
        $raw = trim((string)($claims[$key] ?? ''));
        if ($raw === '') {
            continue;
        }

        return \Ikabud\Kernel\TenantResolver::normalizeHost($raw);
    }

    return '';
}

/**
 * Restrict access to PRO-tier endpoints.
 * Returns a JSON 403 or redirects if the tenant is on the FREE tier.
 */
function guidanceRequirePro(): void {
    if (guidanceIsPro()) {
        return;
    }

    $storeUrl = guidanceLicenseStoreUrl();

    if (guidanceIsHtmx() || app()->input('api') || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        app()->json([
            'error'       => 'upgrade_required',
            'message'     => 'This feature requires the Guidance PRO tier. Please upgrade your license to access it.',
            'upgrade_url' => $storeUrl !== '' ? $storeUrl : null,
        ], 403);
        exit;
    }

    app()->redirect('/admin/guidance?error=upgrade_required');
    exit;
}

// ─── License Activation (module.license.activate@1) ──────────────────────────

/**
 * Return the bundled RS256 public key PEM used to verify Guidance license JWTs.
 * The matching private key is held exclusively by the module author or store operator.
 */
function guidanceLicensePublicKey(?int $tenantId = null): string
{
    $candidates = guidanceLicensePublicKeyCandidates($tenantId);
    if ($candidates === []) {
        return '';
    }

    return (string)reset($candidates);
}

/**
 * Check whether a JTI has already been bound to a specific tenant.
 * Returns the tenant_id it is bound to, or null if unseen.
 *
 * Looks through all tenants' guidance module settings for a recorded `jti`
 * in the license_activation_state key. This prevents a single license key
 * from being used to activate multiple tenants.
 */
function guidanceLicenseJtiTenantBound(string $jti): ?int
{
    if ($jti === '') {
        return null;
    }
    try {
        $db = app()->controlDb();
        $settingsKey = moduleLicenseActivationSettingsKey();
        $stmt = $db->prepare(
            'SELECT tenant_id, setting_value FROM ' . moduleTenantSettingsTable()
            . ' WHERE module_id = :mid AND setting_key = :skey'
        );
        $stmt->execute([':mid' => 'guidance', ':skey' => $settingsKey]);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $state = json_decode((string)($row['setting_value'] ?? ''), true);
            if (is_array($state) && ($state['jti'] ?? '') === $jti) {
                return (int)$row['tenant_id'];
            }
        }
    } catch (\Throwable $e) {
        // On error, fail open — don't block a legitimate activation due to a DB issue.
    }
    return null;
}

/**
 * Parse and cryptographically verify a Guidance RS256 license JWT.
 *
 * Returns an array with:
 *   ok          bool    — true when signature and all claims are valid
 *   tier        string  — 'pro' (or another tier) from JWT 'tier' claim
 *   expires_at  string  — ISO-8601 expiry, or '' for perpetual
 *   issuer_host string  — issuer/store host when present in the JWT
 *   key_source   string  — which trusted public-key source verified the JWT
 *   error       string  — human-readable failure reason (only when ok=false)
 *
 * The JWT must satisfy:
 *   - alg = RS256
 *   - iss = 'ikabud_ecommerce'
 *   - aud = 'guidance'
 *   - exp > time()  (or omitted → perpetual)
 *   - signature verifiable by the bundled public key
 *   - optional issuer/store host claims must match the tenant's configured
 *     license_store_url when both are present
 */
function guidanceVerifyLicenseJwt(string $jwt, array $options = []): array
{
    // Strip all whitespace (newlines, spaces) that may be introduced when
    // copying a JWT that was word-wrapped in an email or <code> block.
    $jwt = preg_replace('/\s+/', '', trim($jwt));

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return ['ok' => false, 'error' => 'Malformed JWT: expected three dot-separated segments.'];
    }

    [$b64Header, $b64Payload, $b64Sig] = $parts;

    // Decode header
    $headerJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $b64Header), true);
    if ($headerJson === false) {
        return ['ok' => false, 'error' => 'Invalid base64url header.'];
    }
    $header = json_decode($headerJson, true);
    if (!is_array($header) || strtoupper((string)($header['alg'] ?? '')) !== 'RS256') {
        return ['ok' => false, 'error' => 'Unsupported or missing algorithm; RS256 required.'];
    }

    // Decode payload
    $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $b64Payload), true);
    if ($payloadJson === false) {
        return ['ok' => false, 'error' => 'Invalid base64url payload.'];
    }
    $claims = json_decode($payloadJson, true);
    if (!is_array($claims)) {
        return ['ok' => false, 'error' => 'Could not decode JWT payload.'];
    }

    // Decode signature
    $sigBin = base64_decode(str_replace(['-', '_'], ['+', '/'], $b64Sig), true);
    if ($sigBin === false || $sigBin === '') {
        return ['ok' => false, 'error' => 'Invalid base64url signature.'];
    }

    $tenantIdForKey = isset($options['tenant_id']) ? (int)$options['tenant_id'] : null;
    $issuerHost = guidanceLicenseIssuerHost($claims);

    // Verify RS256 signature against the trusted public-key candidates.
    $publicKeyCandidates = guidanceLicensePublicKeyCandidates($tenantIdForKey, $issuerHost);
    if ($publicKeyCandidates === []) {
        return ['ok' => false, 'error' => 'Module license public key not found; contact support.'];
    }

    $signingInput = $b64Header . '.' . $b64Payload;
    $loadedPublicKeys = 0;
    $verifiedSource = '';

    foreach ($publicKeyCandidates as $source => $publicKeyPem) {
        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            continue;
        }

        $loadedPublicKeys++;
        $verified = openssl_verify($signingInput, $sigBin, $pubKey, OPENSSL_ALGO_SHA256);

        if ($verified === 1) {
            $verifiedSource = $source;
            break;
        }
    }

    if ($loadedPublicKeys === 0) {
        return ['ok' => false, 'error' => 'Could not load module license public key.'];
    }

    if ($verifiedSource === '') {
        return ['ok' => false, 'error' => 'License key signature is invalid. Ensure the key was issued for Guidance by the authorized author.'];
    }

    // Validate standard claims
    $iss = trim((string)($claims['iss'] ?? ''));
    if ($iss !== 'ikabud_ecommerce') {
        return ['ok' => false, 'error' => "Invalid issuer '{$iss}'; expected 'ikabud_ecommerce'."];
    }

    $aud = trim((string)($claims['aud'] ?? ''));
    if ($aud !== 'guidance') {
        return ['ok' => false, 'error' => "License is not for this module (aud='{$aud}'); expected 'guidance'."];
    }

    $tier = strtolower(trim((string)($claims['tier'] ?? '')));
    if ($tier === '') {
        return ['ok' => false, 'error' => 'License key is missing the tier claim.'];
    }

    $exp = isset($claims['exp']) ? (int)$claims['exp'] : null;
    if ($exp !== null && $exp < time()) {
        return ['ok' => false, 'error' => 'License key has expired. Please renew at the module store.'];
    }

    $expectedStoreUrl = guidanceNormalizeExternalUrl((string)($options['license_store_url'] ?? ''));
    if ($expectedStoreUrl === '') {
        $tenantId = isset($options['tenant_id']) ? (int)$options['tenant_id'] : null;
        $expectedStoreUrl = guidanceLicenseStoreUrl($tenantId);
    }

    if ($issuerHost === '') {
        foreach (['iss_url', 'issuer_url', 'store_url', 'iss_host', 'issuer_host', 'store_host'] as $key) {
            if (trim((string)($claims[$key] ?? '')) !== '') {
                return ['ok' => false, 'error' => 'License key contains an invalid issuer store URL or host.'];
            }
        }
    }

    $expectedStoreHost = guidanceExternalUrlHost($expectedStoreUrl);
    if ($issuerHost !== '' && $expectedStoreHost !== '' && $issuerHost !== $expectedStoreHost) {
        return [
            'ok' => false,
            'error' => 'License key issuer does not match this tenant\'s configured Guidance store URL.',
        ];
    }

    $expiresAt = ($exp !== null) ? date('Y-m-d H:i:s', $exp) : '';

    return [
        'ok'         => true,
        'tier'       => $tier,
        'expires_at' => $expiresAt,
        'jti'        => (string)($claims['jti'] ?? ''),
        'issuer_host'=> $issuerHost,
        'key_source' => $verifiedSource,
    ];
}

/**
 * Capability handler for module.license.activate@1.
 *
 * Named using the module-manager auto-wiring convention:
 *   <modulePrefix>_cap_<sanitized_capability_id>
 * = guidance_cap_module_license_activate_1
 *
 * Called by the kernel when a license key is submitted (superadmin settings or
 * the Guidance admin settings activate-license endpoint).
 * Validates the JWT and — on success — grants the corresponding entitlement tier
 * for the tenant via grantModuleEntitlementForTenant().
 *
 * Returns an array compatible with the kernel's invokeModuleLicenseActivation contract:
 *   ok          bool
 *   status      'active' | 'error'
 *   tier        string
 *   expires_at  string
 *   provider    'guidance'
 *   error       string  (only on failure)
 */
function guidance_cap_module_license_activate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'status' => 'error', 'provider' => 'guidance', 'error' => 'Invalid payload.'];
    }

    $moduleId = trim((string)($payload['module_id'] ?? ''));
    if ($moduleId !== 'guidance') {
        // Not for us; pass through by returning a skip signal.
        return ['ok' => true, 'status' => 'skipped', 'reason' => 'module_mismatch', 'provider' => 'guidance'];
    }

    $licenseKey = trim((string)($payload['license_key'] ?? ''));
    if ($licenseKey === '') {
        return ['ok' => false, 'status' => 'error', 'provider' => 'guidance', 'error' => 'No license key supplied.'];
    }

    $verification = guidanceVerifyLicenseJwt($licenseKey, [
        'tenant_id' => $tenantId,
    ]);
    if (!($verification['ok'] ?? false)) {
        write_log('guidance.license.activate failed', 'warning', [
            'error'     => $verification['error'] ?? 'unknown',
            'module_id' => $moduleId,
            'tenant_id' => (int)($payload['tenant_id'] ?? 0),
        ]);
        return [
            'ok'       => false,
            'status'   => 'error',
            'provider' => 'guidance',
            'error'    => (string)($verification['error'] ?? 'License validation failed.'),
        ];
    }

    $tier      = (string)$verification['tier'];
    $expiresAt = (string)$verification['expires_at'];
    $tenantId  = (int)($payload['tenant_id'] ?? 0);

    // Persist the entitlement tier so guidanceTenantTier() picks it up.
    if ($tenantId > 0 && function_exists('grantModuleEntitlementForTenant')) {
        grantModuleEntitlementForTenant('guidance', $tenantId, [
            'status'     => 'active',
            'tier'       => $tier,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'source'     => 'license_jwt',
            'metadata'   => [
                'jti'       => $verification['jti'] ?? '',
                'activated_at' => date('Y-m-d H:i:s'),
                'via'       => 'guidance_cap_module_license_activate_1',
            ],
        ]);
    }

    write_log('guidance.license.activate ok', 'info', [
        'tier'      => $tier,
        'expires_at'=> $expiresAt,
        'tenant_id' => $tenantId,
        'issuer_host' => (string)($verification['issuer_host'] ?? ''),
        'key_source' => (string)($verification['key_source'] ?? ''),
        'jti'       => $verification['jti'] ?? '',
    ]);

    return [
        'ok'          => true,
        'status'      => 'active',
        'provider'    => 'guidance',
        'tier'        => $tier,
        'expires_at'  => $expiresAt,
        'activated_at'=> date('Y-m-d H:i:s'),
        'jti'         => $verification['jti'] ?? '',
    ];
}

function guidanceAvailabilityDayLabels(): array {
    return [
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
    ];
}

function guidanceAvailabilityDefaultWorkingHours(): array {
    return [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'],
        'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'],
        'saturday' => null,
        'sunday' => null,
    ];
}

function guidanceNormalizeAvailabilityTime(?string $time): ?string {
    if ($time === null) {
        return null;
    }

    $time = trim($time);
    if ($time === '') {
        return null;
    }

    return substr($time, 0, 5);
}

function guidanceAvailabilityTableExists(\Ikabud\Kernel\Contracts\DatabaseContract $db): bool {
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'gm_counselor_availability'");
        return (bool)($stmt ? $stmt->fetchColumn() : false);
    } catch (Throwable $e) {
        return false;
    }
}

function guidanceAvailabilityColumnExists(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $column): bool {
    try {
        $stmt = $db->query('SHOW COLUMNS FROM gm_counselor_availability');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string)($row['Field'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function guidanceAvailabilityIndexExists(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $indexName): bool {
    try {
        $stmt = $db->query('SHOW INDEX FROM gm_counselor_availability');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string)($row['Key_name'] ?? '') === $indexName) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function guidanceEnsureCounselorAvailabilityTable(\Ikabud\Kernel\Contracts\DatabaseContract $db): void {
    if (!guidanceAvailabilityTableExists($db)) {
        throw new RuntimeException('Guidance counselor availability table is missing. Run the guidance module migrations.');
    }
}

function guidanceGetGlobalWorkingHours(\Ikabud\Kernel\Contracts\DatabaseContract $db): array {
    $hours = guidanceAvailabilityDefaultWorkingHours();

    try {
        $stmt = $db->prepare("SELECT setting_value FROM gm_settings WHERE setting_key = 'working_hours' LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $hours;
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $hours;
    }

    foreach (guidanceAvailabilityDayLabels() as $day => $label) {
        if (!array_key_exists($day, $decoded)) {
            continue;
        }

        if ($decoded[$day] === null) {
            $hours[$day] = null;
            continue;
        }

        $start = guidanceNormalizeAvailabilityTime($decoded[$day]['start'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($decoded[$day]['end'] ?? null);
        if ($start !== null && $end !== null) {
            $hours[$day] = ['start' => $start, 'end' => $end];
        }
    }

    return $hours;
}

function guidanceGetGlobalWorkingHourRanges(\Ikabud\Kernel\Contracts\DatabaseContract $db): array {
    $hours = guidanceGetGlobalWorkingHours($db);
    $ranges = [];

    foreach (guidanceAvailabilityDayLabels() as $day => $label) {
        $ranges[$day] = [];
        if (!is_array($hours[$day] ?? null)) {
            continue;
        }

        $start = guidanceNormalizeAvailabilityTime($hours[$day]['start'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($hours[$day]['end'] ?? null);
        if ($start === null || $end === null) {
            continue;
        }

        $ranges[$day][] = [
            'slot_index' => 1,
            'start' => $start,
            'end' => $end,
        ];
    }

    return $ranges;
}

function guidanceSerializeAvailabilityRanges(array $ranges): string {
    $parts = [];

    foreach ($ranges as $range) {
        $start = guidanceNormalizeAvailabilityTime($range['start'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($range['end'] ?? null);
        if ($start === null || $end === null) {
            continue;
        }
        $parts[] = $start . '|' . $end;
    }

    return implode(';', $parts);
}

function guidanceAvailabilityRangesSummary(array $ranges, string $closedLabel = 'Closed'): string {
    $parts = [];

    foreach ($ranges as $range) {
        $start = guidanceNormalizeAvailabilityTime($range['start'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($range['end'] ?? null);
        if ($start === null || $end === null) {
            continue;
        }
        $parts[] = $start . '-' . $end;
    }

    return empty($parts) ? $closedLabel : implode(', ', $parts);
}

function guidanceGetStoredCounselorAvailability(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $userId): array {
    if (!guidanceAvailabilityTableExists($db)) {
        return [];
    }

    $hasSlotIndex = guidanceAvailabilityColumnExists($db, 'slot_index');

    $stmt = $db->prepare(
        "SELECT day_of_week, " . ($hasSlotIndex ? 'COALESCE(slot_index, 1)' : '1') . " AS slot_index, is_available, start_time, end_time\n"
        . "FROM gm_counselor_availability\n"
        . "WHERE counselor_id = ?\n"
        . "ORDER BY FIELD(day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')"
        . ($hasSlotIndex ? ', COALESCE(slot_index, 1)' : '')
        . ', id'
    );
    $stmt->execute([$userId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availability = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $dayKey = (string)($row['day_of_week'] ?? '');
        if ($dayKey === '') {
            continue;
        }

        if (!isset($availability[$dayKey])) {
            $availability[$dayKey] = [
                'has_custom_record' => true,
                'ranges' => [],
            ];
        }

        $start = guidanceNormalizeAvailabilityTime($row['start_time'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($row['end_time'] ?? null);
        if ((int)($row['is_available'] ?? 0) !== 1 || $start === null || $end === null) {
            continue;
        }

        $availability[$dayKey]['ranges'][] = [
            'slot_index' => (int)($row['slot_index'] ?? (count($availability[$dayKey]['ranges']) + 1)),
            'start' => $start,
            'end' => $end,
        ];
    }

    foreach ($availability as $dayKey => $dayAvailability) {
        usort($dayAvailability['ranges'], static function (array $left, array $right): int {
            return strcmp((string)$left['start'], (string)$right['start']);
        });

        foreach ($dayAvailability['ranges'] as $index => $range) {
            $dayAvailability['ranges'][$index]['slot_index'] = $index + 1;
        }

        $availability[$dayKey] = $dayAvailability;
    }

    return $availability;
}

function guidanceGetMergedCounselorAvailability(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $userId): array {
    $global = guidanceGetGlobalWorkingHourRanges($db);
    $stored = guidanceGetStoredCounselorAvailability($db, $userId);
    $merged = [];

    foreach (guidanceAvailabilityDayLabels() as $day => $label) {
        $defaultRanges = $global[$day] ?? [];
        $defaultIsAvailable = !empty($defaultRanges);
        $customRanges = $stored[$day]['ranges'] ?? [];
        $isCustom = isset($stored[$day]);
        $effectiveRanges = $isCustom ? $customRanges : $defaultRanges;

        $merged[] = [
            'key' => $day,
            'label' => $label,
            'is_available' => !empty($effectiveRanges),
            'ranges' => $effectiveRanges,
            'editor_ranges' => $customRanges,
            'source' => $isCustom ? 'custom' : 'default',
            'summary' => guidanceAvailabilityRangesSummary($effectiveRanges),
            'default_is_available' => $defaultIsAvailable,
            'default_ranges' => $defaultRanges,
            'default_ranges_serialized' => guidanceSerializeAvailabilityRanges($defaultRanges),
            'default_summary' => guidanceAvailabilityRangesSummary($defaultRanges),
        ];
    }

    return $merged;
}

function guidanceGetCounselorAvailabilityForDate(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $userId, string $date): ?array {
    $dayKey = strtolower((string)date('l', strtotime($date)));
    foreach (guidanceGetMergedCounselorAvailability($db, $userId) as $day) {
        if (($day['key'] ?? '') !== $dayKey) {
            continue;
        }

        if (empty($day['is_available'])) {
            return null;
        }

        $ranges = is_array($day['ranges'] ?? null) ? $day['ranges'] : [];
        if ($ranges === []) {
            return null;
        }

        return [
            'start' => (string)($ranges[0]['start'] ?? ''),
            'end' => (string)($ranges[count($ranges) - 1]['end'] ?? ''),
            'ranges' => $ranges,
            'source' => (string)($day['source'] ?? 'default'),
        ];
    }

    return null;
}

function guidanceNormalizeAvailabilityRangesInput(array $rangesInput, string $label): array {
    $normalized = [];

    foreach ($rangesInput as $index => $rangeInput) {
        if (!is_array($rangeInput)) {
            continue;
        }

        $start = guidanceNormalizeAvailabilityTime($rangeInput['start'] ?? null);
        $end = guidanceNormalizeAvailabilityTime($rangeInput['end'] ?? null);
        if ($start === null && $end === null) {
            continue;
        }
        if ($start === null || $end === null) {
            throw new InvalidArgumentException($label . ' range ' . ((int)$index + 1) . ' requires both start and end times');
        }
        if ($start >= $end) {
            throw new InvalidArgumentException($label . ' range ' . ((int)$index + 1) . ' end time must be after the start time');
        }

        $normalized[] = ['start' => $start, 'end' => $end];
    }

    usort($normalized, static function (array $left, array $right): int {
        return strcmp((string)$left['start'], (string)$right['start']);
    });

    $previousEnd = null;
    foreach ($normalized as $index => $range) {
        if ($previousEnd !== null && (string)$range['start'] < $previousEnd) {
            throw new InvalidArgumentException($label . ' ranges cannot overlap');
        }
        $normalized[$index]['slot_index'] = $index + 1;
        $previousEnd = (string)$range['end'];
    }

    return $normalized;
}

function guidanceExtractAvailabilityRangesFromInput(array $dayInput, string $label): array {
    if (isset($dayInput['ranges']) && is_array($dayInput['ranges'])) {
        return guidanceNormalizeAvailabilityRangesInput($dayInput['ranges'], $label);
    }

    $enabled = !empty($dayInput['enabled']);
    if (!$enabled) {
        return [];
    }

    $start = guidanceNormalizeAvailabilityTime($dayInput['start'] ?? null);
    $end = guidanceNormalizeAvailabilityTime($dayInput['end'] ?? null);
    if ($start === null || $end === null) {
        throw new InvalidArgumentException($label . ' start and end times are required');
    }

    return guidanceNormalizeAvailabilityRangesInput([
        ['start' => $start, 'end' => $end],
    ], $label);
}

function guidanceSaveCounselorAvailability(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $userId, array $availability): void {
    guidanceEnsureCounselorAvailabilityTable($db);

    $hasSlotIndex = guidanceAvailabilityColumnExists($db, 'slot_index');

    $insertStmt = $db->prepare(
        $hasSlotIndex
            ? 'INSERT INTO gm_counselor_availability (counselor_id, day_of_week, slot_index, is_available, start_time, end_time, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            : 'INSERT INTO gm_counselor_availability (counselor_id, day_of_week, is_available, start_time, end_time, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $deleteStmt = $db->prepare('DELETE FROM gm_counselor_availability WHERE counselor_id = ? AND day_of_week = ?');

    $db->beginTransaction();
    try {
        foreach (guidanceAvailabilityDayLabels() as $day => $label) {
            $hasDayInput = array_key_exists($day, $availability) && is_array($availability[$day]);
            $dayInput = $hasDayInput ? $availability[$day] : [];
            $useDefault = !$hasDayInput || !empty($dayInput['use_default']);

            $deleteStmt->execute([$userId, $day]);
            if ($useDefault) {
                continue;
            }

            $ranges = guidanceExtractAvailabilityRangesFromInput($dayInput, $label);
            if ($ranges === []) {
                $insertStmt->execute($hasSlotIndex ? [$userId, $day, 1, 0, null, null] : [$userId, $day, 0, null, null]);
                continue;
            }

            if (!$hasSlotIndex && count($ranges) > 1) {
                throw new RuntimeException('Guidance counselor availability schema is outdated. Run the guidance module migrations to support multiple daily ranges.');
            }

            foreach ($ranges as $range) {
                $insertStmt->execute(
                    $hasSlotIndex
                        ? [
                            $userId,
                            $day,
                            (int)$range['slot_index'],
                            1,
                            $range['start'],
                            $range['end'],
                        ]
                        : [
                            $userId,
                            $day,
                            1,
                            $range['start'],
                            $range['end'],
                        ]
                );
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function clientIp(): string
{
    // Delegate to kernel-level trusted-proxy-aware IP resolver.
    return kernel_client_ip();
}

function rateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    $db = guidanceDb();

    static $cleanupDone = false;
    if (!$cleanupDone) {
        if (random_int(1, 100) === 1) {
            try { $db->exec("DELETE FROM gm_rate_limits WHERE expires_at < NOW()"); } catch (\Exception $e) {}
        }
        $cleanupDone = true;
    }

    $stmt = $db->prepare("
        INSERT INTO gm_rate_limits (rate_key, attempts, window_start, expires_at)
        VALUES (?, 1, NOW(), NOW() + INTERVAL ? SECOND)
        ON DUPLICATE KEY UPDATE
            attempts = IF(expires_at < NOW(), 1, attempts + 1),
            window_start = IF(expires_at < NOW(), NOW(), window_start),
            expires_at = IF(expires_at < NOW(), NOW() + INTERVAL ? SECOND, expires_at)
    ");
    $stmt->execute([$key, $windowSeconds, $windowSeconds]);

    $checkStmt = $db->prepare("SELECT attempts FROM gm_rate_limits WHERE rate_key = ? AND expires_at >= NOW()");
    $checkStmt->execute([$key]);
    $attempts = (int) $checkStmt->fetchColumn();

    return $attempts <= $maxAttempts;
}

// ── Entity-View Capabilities ──────────────────────────────────────────

function gm_cap_entity_list_case_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 15), 100);
    $offset = (int)($payload['offset'] ?? 0);
    $sortField = (string)($payload['sort']['field'] ?? 'c.updated_at');
    $sortDir = strtoupper((string)($payload['sort']['direction'] ?? 'DESC'));
    $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';
    $qualifier = (string)($payload['qualifier'] ?? '');
    $statusFilter = '';

    // Cursor-based pagination support
    $cursor = isset($payload['cursor']) ? (string)$payload['cursor'] : null;
    $prevCursor = isset($payload['prev_cursor']) ? (string)$payload['prev_cursor'] : null;

    if ($qualifier === 'open') { $statusFilter = " AND c.status = 'open'"; }
    elseif ($qualifier === 'closed') { $statusFilter = " AND c.status = 'closed'"; }
    elseif ($qualifier === 'active') { $statusFilter = " AND c.status NOT IN ('closed')"; }

    try {
        $db = guidanceDb();

        // Determine the cursor column — use the sort field if it maps to c.id or c.updated_at,
        // otherwise default to c.id for stable keyset pagination.
        $cursorColumn = 'c.id';
        $idField = $sortField;
        if (str_contains($sortField, '.')) {
            $parts = explode('.', $sortField);
            $idField = end($parts);
        }
        // For keyset pagination, we need the sort field to be unique + stable.
        // Default to c.id + c.updated_at composite for stable ordering.
        $cursorClause = '';
        if ($cursor !== null) {
            $cursorVal = (int)$cursor;
            if ($cursorVal > 0) {
                $cursorOp = $sortDir === 'ASC' ? '>' : '<';
                $cursorClause = " AND c.id {$cursorOp} {$cursorVal}";
            }
        } elseif ($prevCursor !== null) {
            // Going backward — reverse the direction for this query
            $cursorVal = (int)$prevCursor;
            if ($cursorVal > 0) {
                $revDir = $sortDir === 'ASC' ? 'DESC' : 'ASC';
                $cursorClause = " AND c.id < {$cursorVal}";
                // We'll reverse the results after fetching
            }
        }

        // Fetch one extra row to determine hasMore
        $fetchLimit = $limit + 1;

        $stmt = $db->query("SELECT c.id, c.student_name, c.case_number, c.status, c.severity, c.category, c.college_code, c.student_status, c.is_urgent, c.created_at, c.updated_at, c.deleted_at, c.counselor_id, CONCAT(u.first_name, ' ', u.last_name) as counselor_name FROM gm_cases c LEFT JOIN gm_users u ON u.id = c.counselor_id WHERE c.deleted_at IS NULL{$statusFilter}{$cursorClause} ORDER BY {$sortField} {$sortDir} LIMIT {$fetchLimit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        // For prev cursor navigation, reverse the results back to original order
        if ($prevCursor !== null && !empty($rows)) {
            $rows = array_reverse($rows);
        }

        // Determine hasMore — if we fetched limit+1 rows, there are more
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            // Remove the extra row
            array_pop($rows);
        }

        // Determine next_cursor from the last row's id
        $nextCursor = null;
        if ($hasMore && !empty($rows)) {
            $lastRow = end($rows);
            $nextCursor = (string)($lastRow['id'] ?? '');
        }

        // Determine prev_cursor from the first row's id (for going back)
        $prevCursorOut = null;
        if ($cursor !== null && !empty($rows)) {
            $firstRow = reset($rows);
            $prevCursorOut = (string)($firstRow['id'] ?? '');
        }

        // Enrich with college codes
        $counselorIds = array_unique(array_filter(array_column($rows, 'counselor_id')));
        $counselorCollegeMap = [];
        if (!empty($counselorIds)) {
            $ph = implode(',', array_fill(0, count($counselorIds), '?'));
            $caStmt = $db->prepare("
                SELECT ca.counselor_id, GROUP_CONCAT(col.code ORDER BY col.sort_order SEPARATOR ', ') as codes
                FROM gm_counselor_assignments ca
                JOIN gm_colleges col ON ca.college_id = col.id AND col.is_active = 1
                WHERE ca.counselor_id IN ({$ph}) AND ca.is_active = 1
                GROUP BY ca.counselor_id
            ");
            $caStmt->execute(array_values($counselorIds));
            foreach ($caStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $counselorCollegeMap[$row['counselor_id']] = $row['codes'];
            }
        }
        foreach ($rows as &$caseRow) {
            if (empty($caseRow['college_code']) && !empty($caseRow['counselor_id']) && isset($counselorCollegeMap[$caseRow['counselor_id']])) {
                $caseRow['college_code'] = $counselorCollegeMap[$caseRow['counselor_id']];
            }
        }
        unset($caseRow);

        // Return cursor-based format when cursor was used, otherwise total-based
        if ($cursor !== null || $prevCursor !== null) {
            return [
                'rows' => $rows,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
                'prev_cursor' => $prevCursorOut,
            ];
        }

        $countStmt = $db->query("SELECT COUNT(*) FROM gm_cases WHERE deleted_at IS NULL{$statusFilter}");
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function gm_cap_entity_get_case_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = guidanceDb();
        $stmt = $db->prepare('SELECT c.*, u.full_name as counselor_name FROM gm_cases c LEFT JOIN gm_users u ON u.id = c.counselor_id WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function gm_cap_entity_list_appointment_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 10), 100);
    try {
        $db = guidanceDb();
        $stmt = $db->query("SELECT a.id, a.title, a.appointment_date as date, a.status, c.student_name FROM gm_appointments a LEFT JOIN gm_cases c ON c.id = a.case_id WHERE a.deleted_at IS NULL ORDER BY a.appointment_date DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query('SELECT COUNT(*) FROM gm_appointments WHERE deleted_at IS NULL');
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function gm_cap_entity_get_appointment_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = guidanceDb();
        $stmt = $db->prepare('SELECT a.*, c.student_name FROM gm_appointments a LEFT JOIN gm_cases c ON c.id = a.case_id WHERE a.id = :id AND a.deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Handle inline status update for guidance cases.
 *
 * Capability: guidance.case.status.update@1
 * Validates the transition, persists, audits, and returns updated data.
 */
function gm_cap_case_status_update_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)($payload['entity_id'] ?? 0);
    $field = (string)($payload['field'] ?? '');
    $value = (string)($payload['value'] ?? '');
    $expectedVersion = isset($payload['expected_version']) ? (int)$payload['expected_version'] : null;

    if ($entityId <= 0 || $field === '') {
        return ['ok' => false, 'error' => 'Missing entity_id or field.'];
    }

    if ($field !== 'status') {
        return ['ok' => false, 'error' => "Field '{$field}' does not support inline editing."];
    }

    $allowed = ['open', 'closed', 'in_progress', 'on_hold', 'pending'];
    if (!in_array($value, $allowed, true)) {
        return ['ok' => false, 'error' => "Invalid status value '{$value}'. Allowed: " . implode(', ', $allowed) . '.'];
    }

    try {
        $db = guidanceDb();

        $stmt = $db->prepare('SELECT id, status, updated_at FROM gm_cases WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $entityId]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($current)) {
            return ['ok' => false, 'error' => 'Case not found.'];
        }

        if ($expectedVersion !== null) {
            $currentVersion = strtotime((string)$current['updated_at']);
            if ($currentVersion !== $expectedVersion) {
                return [
                    'ok' => false,
                    'error' => 'Entity was modified by another user. Reload and try again.',
                    'code' => 'VERSION_CONFLICT',
                    'current_version' => $currentVersion,
                ];
            }
        }

        $oldStatus = $current['status'];
        $forbiddenTransitions = [
            'closed' => ['open', 'in_progress', 'on_hold'],
        ];
        if (isset($forbiddenTransitions[$oldStatus]) && in_array($value, $forbiddenTransitions[$oldStatus], true)) {
            return ['ok' => false, 'error' => "Cannot transition from '{$oldStatus}' to '{$value}'."];
        }

        $updateStmt = $db->prepare('UPDATE gm_cases SET status = :status, updated_at = NOW() WHERE id = :id');
        $updateStmt->execute([':status' => $value, ':id' => $entityId]);

        $checkStmt = $db->prepare('SELECT updated_at FROM gm_cases WHERE id = :id');
        $checkStmt->execute([':id' => $entityId]);
        $updated = $checkStmt->fetch(\PDO::FETCH_ASSOC);
        $newVersion = $updated ? strtotime((string)$updated['updated_at']) : null;

        if (function_exists('app') && ($app = app()) !== null && method_exists($app, 'cap')) {
            try {
                $app->cap()->call('kernel.audit.record@1', [
                    'module' => 'guidance',
                    'action' => 'inline_update',
                    'entity_type' => 'guidance_case',
                    'entity_id' => (string)$entityId,
                    'old_data' => ['status' => $oldStatus],
                    'new_data' => ['status' => $value],
                ], ['caller' => ['module' => 'guidance'], 'mode' => 'first']);
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        return [
            'ok' => true,
            'data' => [
                'raw_value' => $value,
                'display_html' => '',
                'version' => $newVersion,
                'updated_at' => $updated['updated_at'] ?? null,
            ],
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
