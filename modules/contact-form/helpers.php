<?php

declare(strict_types=1);

function contactFormTemplateKey(string $relativePath): string
{
    return 'modules/contact-form/' . ltrim($relativePath, '/');
}

function contactFormBaseUrl(): string
{
    return rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
}

function contactFormPath(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return contactFormBaseUrl() . $path;
}

function contactFormCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('contact-form');
    if (!$ctx) {
        throw new \RuntimeException('Contact form module context unavailable');
    }

    return $ctx;
}

function contactFormDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return contactFormCtx()->db();
}

function contactFormInput(): array
{
    $input = contactFormCtx()->input();
    return is_array($input) ? $input : [];
}

function contactFormRenderTemplate(string $relativePath, array $context = []): string
{
    $template = contactFormTemplateKey($relativePath);
    return contactFormCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function contactFormRequireAdmin(): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        echo 'CMS module required for contact form admin.';
        exit;
    }

    return cmsRequireRole('administrator');
}

function contactFormAdminPageContext(
    array $user,
    string $currentPage,
    string $pageTitle,
    array $breadcrumbs = [],
    array $extra = []
): array {
    $base = function_exists('cmsAdminContext')
        ? cmsAdminContext($user, $currentPage, $breadcrumbs)
        : [
            'current_page' => $currentPage,
            'breadcrumbs' => $breadcrumbs,
            'ext_nav_items' => [],
        ];

    return array_merge($base, [
        'page_title' => $pageTitle,
    ], $extra);
}

function contactFormFlashSessionKey(): string
{
    return '_contact_form_flash';
}

function contactFormSetFlash(string $type, string $text): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $_SESSION[contactFormFlashSessionKey()] = [
        'type' => $type,
        'text' => $text,
    ];
}

function contactFormPullFlash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $key = contactFormFlashSessionKey();
    $flash = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);

    return is_array($flash) ? $flash : null;
}

function contactFormFormDefaults(): array
{
    return [
        'id' => 0,
        'name' => '',
        'slug' => '',
        'success_message' => '',
        'submit_label' => 'Send Message',
        'captcha_enabled' => 1,
        'status' => 'active',
        'created_at' => '',
        'updated_at' => '',
    ];
}

function contactFormNormalizeFormRow(array $row): array
{
    $defaults = contactFormFormDefaults();

    return array_merge($defaults, [
        'id' => (int) ($row['id'] ?? 0),
        'name' => trim((string) ($row['name'] ?? '')),
        'slug' => trim((string) ($row['slug'] ?? '')),
        'success_message' => trim((string) ($row['success_message'] ?? '')),
        'submit_label' => trim((string) ($row['submit_label'] ?? '')),
        'captcha_enabled' => (int) ($row['captcha_enabled'] ?? 0),
        'status' => trim((string) ($row['status'] ?? 'inactive')),
        'created_at' => trim((string) ($row['created_at'] ?? '')),
        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
    ]);
}

function contactFormFieldDefaults(): array
{
    return [
        'id' => 0,
        'form_id' => 0,
        'field_type' => 'text',
        'label' => '',
        'name' => '',
        'placeholder' => '',
        'help_text' => '',
        'options_text' => '',
        'required' => 1,
        'sort_order' => 0,
        'created_at' => '',
        'updated_at' => '',
    ];
}

function contactFormNormalizeFieldRow(array $row): array
{
    $defaults = contactFormFieldDefaults();

    return array_merge($defaults, [
        'id' => (int) ($row['id'] ?? 0),
        'form_id' => (int) ($row['form_id'] ?? 0),
        'field_type' => trim((string) ($row['field_type'] ?? 'text')),
        'label' => trim((string) ($row['label'] ?? '')),
        'name' => trim((string) ($row['name'] ?? '')),
        'placeholder' => trim((string) ($row['placeholder'] ?? '')),
        'help_text' => trim((string) ($row['help_text'] ?? '')),
        'options_text' => trim((string) ($row['options_text'] ?? '')),
        'required' => (int) ($row['required'] ?? 0),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'created_at' => trim((string) ($row['created_at'] ?? '')),
        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
    ]);
}

function contactFormNormalizeFormsContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = kernelApplyRenderContextShape($context, [
        'page_title' => 'Contact Forms',
        'forms' => [],
        'message' => null,
        'stats' => [],
    ], ['page_title', 'forms', 'stats'], $missingKeys, $typeMismatches);

    $context['stats'] = kernelApplyRenderContextShape($context['stats'], [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
    ], ['total', 'active', 'inactive'], $missingKeys, $typeMismatches, 'stats.');

    return $context;
}

function contactFormNormalizeEditorContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Contact Form',
        'form' => contactFormFormDefaults(),
        'fields' => [],
        'message' => null,
        'error' => null,
        'is_edit' => false,
        'shortcode' => '',
        'field_type_options' => [],
        'active_tab' => 'overview',
    ], ['page_title', 'form', 'fields', 'is_edit', 'shortcode'], $missingKeys, $typeMismatches);
}

function contactFormNormalizeSubmissionsContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Contact Form Entries',
        'submissions' => [],
        'forms' => [],
        'message' => null,
        'selected_form_id' => 0,
        'selected_status' => '',
        'status_options' => [],
        'export_url' => '',
        'page' => 1,
        'total_pages' => 1,
        'total' => 0,
        'has_prev' => false,
        'has_next' => false,
        'prev_page' => 1,
        'next_page' => 1,
    ], ['page_title', 'submissions', 'forms', 'selected_form_id', 'selected_status', 'status_options', 'page', 'total_pages', 'total', 'has_prev', 'has_next', 'prev_page', 'next_page'], $missingKeys, $typeMismatches);
}

function contactFormNormalizeSubmissionDetailContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Submission Detail',
        'submission' => [],
        'records' => [],
        'message' => null,
        'status_options' => [],
    ], ['page_title', 'submission', 'records', 'status_options'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('contact-form.admin.forms', [
    'template' => contactFormTemplateKey('admin/forms.disyl'),
    'priority' => 20,
    'normalize' => 'contactFormNormalizeFormsContext',
    'log_event' => 'contact-form.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('contact-form.admin.editor', [
    'template' => contactFormTemplateKey('admin/form-edit.disyl'),
    'priority' => 20,
    'normalize' => 'contactFormNormalizeEditorContext',
    'log_event' => 'contact-form.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('contact-form.admin.submissions', [
    'template' => contactFormTemplateKey('admin/submissions.disyl'),
    'priority' => 20,
    'normalize' => 'contactFormNormalizeSubmissionsContext',
    'log_event' => 'contact-form.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('contact-form.admin.submission-detail', [
    'template' => contactFormTemplateKey('admin/submission-detail.disyl'),
    'priority' => 20,
    'normalize' => 'contactFormNormalizeSubmissionDetailContext',
    'log_event' => 'contact-form.render_context.contract_mismatch',
]);

app()->hooks()->on('cms.admin.nav_items', static function (array $items): array {
    $items[] = [
        'label' => 'Contact Forms',
        'section' => true,
        'children' => [
            [
                'label' => 'Forms',
                'url' => contactFormPath('/cms/admin/contact-forms'),
                'icon' => 'F',
                'active_key' => 'contact_forms',
            ],
            [
                'label' => 'Entries',
                'url' => contactFormPath('/cms/admin/contact-forms/submissions'),
                'icon' => 'L',
                'active_key' => 'contact_form_submissions',
            ],
        ],
    ];

    return $items;
}, 20);

app()->hooks()->on('cms.editor.block_types', function (array $blocks): array {
    $blocks[] = [
        'type' => 'contact_form',
        'label' => 'Contact Form',
        'icon' => 'mail',
        'fields' => [
            [
                'key' => 'form_id',
                'type' => 'select',
                'label' => 'Saved Form',
                'options' => contactFormBuilderFormOptions(),
            ],
            [
                'key' => 'title',
                'type' => 'text',
                'label' => 'Form Title',
                'placeholder' => 'Contact Us',
            ],
            [
                'key' => 'submit_label',
                'type' => 'text',
                'label' => 'Button Label',
                'placeholder' => 'Send Message',
            ],
            [
                'key' => 'success_message',
                'type' => 'text',
                'label' => 'Success Message',
                'placeholder' => 'Thank you for your message!',
            ],
        ],
    ];

    return $blocks;
}, 10);

app()->hooks()->on('cms.builder.renderers', function (array $map): array {
    $map['contact_form'] = 'contactFormRenderBuilderBlock';
    return $map;
}, 10);

app()->hooks()->on('cms.public.render_content', function (string $html, array $content): string {
    if ($html === '' || (stripos($html, '[contact-form') === false && stripos($html, '[contact_form') === false)) {
        return $html;
    }

    $pattern = '/<p>\s*\[(contact(?:-|_)form)([^\]]*)\]\s*<\/p>|\[(contact(?:-|_)form)([^\]]*)\]/i';

    return preg_replace_callback($pattern, static function (array $matches): string {
        $attrString = trim((string) ($matches[2] !== '' ? $matches[2] : ($matches[4] ?? '')));
        $attrs = contactFormParseShortcodeAttrs($attrString);
        return contactFormRenderFromAttrs($attrs);
    }, $html) ?? $html;
}, 10);

app()->hooks()->on('cms.public.head', function (string $html, array $content): string {
    if ($html === '' || (stripos($html, '[contact-form') === false && stripos($html, '[contact_form') === false)) {
        return $html;
    }

    $html = preg_replace('/\[(contact(?:-|_)form)([^\]]*)\]/i', '', $html) ?? $html;
    $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;
    return $html;
}, 10);

function contactFormSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['contact-form'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string) ($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string) $field['default'];
    }

    return $defaults;
}

function contactFormGetSettings(): array
{
    $saved = getModuleSettings('contact-form');
    return array_merge(contactFormSettingsDefaults(), is_array($saved) ? $saved : []);
}

function contactFormParseShortcodeAttrs(string $raw): array
{
    $attrs = [];
    if ($raw === '') {
        return $attrs;
    }

    if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*(["\'])(.*?)\2/', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower((string) ($match[1] ?? ''));
            $value = html_entity_decode((string) ($match[3] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($key !== '') {
                $attrs[$key] = $value;
            }
        }
    }

    return $attrs;
}

function contactFormLimit(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

function contactFormSlugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    $value = preg_replace('/-{2,}/', '-', $value) ?? '';
    return contactFormLimit($value, 100);
}

function contactFormFieldNameify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    $value = preg_replace('/_{2,}/', '_', $value) ?? '';

    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[a-z]/', $value)) {
        $value = 'field_' . $value;
    }

    return contactFormLimit($value, 100);
}

function contactFormBoolish(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function contactFormFieldTypeLabels(): array
{
    return [
        'text'     => 'Text',
        'email'    => 'Email',
        'tel'      => 'Telephone',
        'number'   => 'Number',
        'url'      => 'Website URL',
        'date'     => 'Date',
        'time'     => 'Time',
        'datetime' => 'Date & Time',
        'month'    => 'Month',
        'week'     => 'Week',
        'textarea' => 'Textarea',
        'select'   => 'Select / Dropdown',
        'radio'    => 'Radio Buttons',
        'checkbox' => 'Checkbox',
        'password' => 'Password',
        'rating'   => 'Star Rating',
        'range'    => 'Range Slider',
        'color'    => 'Color Picker',
        'hidden'   => 'Hidden',
        'section'  => 'Section Divider',
    ];
}

function contactFormFieldTypeOptions(): array
{
    $options = [];
    foreach (contactFormFieldTypeLabels() as $value => $label) {
        $options[] = [
            'value' => $value,
            'label' => $label,
        ];
    }

    return $options;
}

function contactFormFieldTypesWithOptions(): array
{
    return ['select', 'radio', 'checkbox'];
}

function contactFormFieldInputType(string $fieldType): string
{
    return match ($fieldType) {
        'email'    => 'email',
        'tel'      => 'tel',
        'number'   => 'number',
        'url'      => 'url',
        'date'     => 'date',
        'time'     => 'time',
        'datetime' => 'datetime-local',
        'month'    => 'month',
        'week'     => 'week',
        'checkbox' => 'checkbox',
        'hidden'   => 'hidden',
        'password' => 'password',
        'range'    => 'range',
        'color'    => 'color',
        default    => 'text',
    };
}

function contactFormNormalizeOptionsText(string $optionsText): string
{
    $lines = preg_split('/\r\n|\r|\n/', $optionsText) ?: [];
    $normalized = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $normalized[] = $line;
        }
    }

    return implode("\n", $normalized);
}

function contactFormParseOptionsText(string $optionsText): array
{
    $optionsText = contactFormNormalizeOptionsText($optionsText);
    if ($optionsText === '') {
        return [];
    }

    $options = [];
    $lines = preg_split('/\r\n|\r|\n/', $optionsText) ?: [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (str_contains($line, '|')) {
            [$value, $label] = array_map('trim', explode('|', $line, 2));
        } else {
            $value = $line;
            $label = $line;
        }

        if ($value === '' || $label === '') {
            continue;
        }

        $options[] = [
            'value' => $value,
            'label' => $label,
        ];
    }

    return $options;
}

function contactFormSubmissionStatusMap(): array
{
    return [
        'new' => 'New',
        'read' => 'Reviewed',
        'reviewed' => 'Reviewed',
        'archived' => 'Archived',
        'spam' => 'Spam',
    ];
}

function contactFormSubmissionStatusOptions(): array
{
    $options = [];
    foreach (contactFormSubmissionStatusMap() as $value => $label) {
        if ($value === 'read') {
            continue;
        }
        $options[] = [
            'value' => $value,
            'label' => $label,
        ];
    }

    return $options;
}

function contactFormCanonicalSubmissionStatus(string $status): string
{
    $status = trim($status);
    return $status === 'read' ? 'reviewed' : $status;
}

function contactFormSubmissionStatusLabel(string $status): string
{
    $status = contactFormCanonicalSubmissionStatus($status);
    $statusMap = contactFormSubmissionStatusMap();
    return $statusMap[$status] ?? ucfirst($status !== '' ? $status : 'new');
}

function contactFormSubmissionFormLabel(array $row): string
{
    $formId = (int) ($row['form_id'] ?? 0);
    $formName = trim((string) ($row['form_name'] ?? ''));

    if ($formId <= 0) {
        return 'Legacy Form';
    }

    return $formName !== '' ? $formName : 'Deleted Form #' . $formId;
}

function contactFormSubmissionRecordsFromRow(array $row): array
{
    $records = [];
    $decoded = json_decode((string) ($row['form_data'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $records[] = [
                'label' => trim((string) ($entry['label'] ?? $entry['name'] ?? 'Field')),
                'name' => trim((string) ($entry['name'] ?? '')),
                'type' => trim((string) ($entry['type'] ?? 'text')),
                'value' => (string) ($entry['value'] ?? ''),
                'required' => !empty($entry['required']),
            ];
        }
    }

    if ($records !== []) {
        return $records;
    }

    $fallback = [];
    foreach ([
        ['label' => 'Name', 'name' => 'name', 'value' => (string) ($row['name'] ?? '')],
        ['label' => 'Email', 'name' => 'email', 'value' => (string) ($row['email'] ?? '')],
        ['label' => 'Message', 'name' => 'message', 'value' => (string) ($row['message'] ?? '')],
    ] as $entry) {
        if (trim($entry['value']) === '') {
            continue;
        }
        $fallback[] = $entry;
    }

    return $fallback;
}

function contactFormResetSchemaState(): void
{
    unset(
        $GLOBALS['_contact_form_table_exists_cache'],
        $GLOBALS['_contact_form_column_exists_cache'],
        $GLOBALS['_contact_form_schema_status']
    );
}

function contactFormSqlName(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        return null;
    }

    return $value;
}

function contactFormSqlLikeLiteral(string $value): string
{
    return str_replace(['\\', '_', '%', "'"], ['\\\\', '\\_', '\\%', "''"], $value);
}

function contactFormTableExists(string $table, bool $refresh = false): bool
{
    $table = contactFormSqlName($table);
    if ($table === null) {
        return false;
    }

    if (!isset($GLOBALS['_contact_form_table_exists_cache']) || !is_array($GLOBALS['_contact_form_table_exists_cache'])) {
        $GLOBALS['_contact_form_table_exists_cache'] = [];
    }

    if ($refresh) {
        unset($GLOBALS['_contact_form_table_exists_cache'][$table]);
    }

    if (array_key_exists($table, $GLOBALS['_contact_form_table_exists_cache'])) {
        return (bool) $GLOBALS['_contact_form_table_exists_cache'][$table];
    }

    try {
        $stmt = contactFormDb()->query(
            "SHOW TABLES LIKE '" . contactFormSqlLikeLiteral($table) . "'"
        );
        $GLOBALS['_contact_form_table_exists_cache'][$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $GLOBALS['_contact_form_table_exists_cache'][$table] = false;
    }

    return (bool) $GLOBALS['_contact_form_table_exists_cache'][$table];
}

function contactFormColumnExists(string $table, string $column, bool $refresh = false): bool
{
    $table = contactFormSqlName($table);
    $column = contactFormSqlName($column);
    if ($table === null || $column === null) {
        return false;
    }

    if (!contactFormTableExists($table, $refresh)) {
        return false;
    }

    if (!isset($GLOBALS['_contact_form_column_exists_cache']) || !is_array($GLOBALS['_contact_form_column_exists_cache'])) {
        $GLOBALS['_contact_form_column_exists_cache'] = [];
    }

    $cacheKey = $table . '.' . $column;
    if ($refresh) {
        unset($GLOBALS['_contact_form_column_exists_cache'][$cacheKey]);
    }

    if (array_key_exists($cacheKey, $GLOBALS['_contact_form_column_exists_cache'])) {
        return (bool) $GLOBALS['_contact_form_column_exists_cache'][$cacheKey];
    }

    try {
        $stmt = contactFormDb()->query(
            "SHOW COLUMNS FROM `" . $table . "` LIKE '" . contactFormSqlLikeLiteral($column) . "'"
        );
        $GLOBALS['_contact_form_column_exists_cache'][$cacheKey] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $GLOBALS['_contact_form_column_exists_cache'][$cacheKey] = false;
    }

    return (bool) $GLOBALS['_contact_form_column_exists_cache'][$cacheKey];
}

function contactFormCollectSchemaGaps(): array
{
    $missingTables = [];
    foreach (['contact_forms', 'contact_form_fields', 'contact_form_submissions'] as $table) {
        if (!contactFormTableExists($table)) {
            $missingTables[] = $table;
        }
    }

    $missingColumns = [];
    if (!in_array('contact_form_submissions', $missingTables, true)) {
        foreach (['form_id', 'form_data'] as $column) {
            if (!contactFormColumnExists('contact_form_submissions', $column)) {
                $missingColumns[] = 'contact_form_submissions.' . $column;
            }
        }
    }

    return [
        'missing_tables' => $missingTables,
        'missing_columns' => $missingColumns,
    ];
}

function contactFormSchemaStatus(bool $refresh = false): array
{
    if ($refresh) {
        contactFormResetSchemaState();
    }

    $cached = $GLOBALS['_contact_form_schema_status'] ?? null;
    if (!$refresh && is_array($cached)) {
        return $cached;
    }

    $gaps = contactFormCollectSchemaGaps();
    $sync = null;

    if (($gaps['missing_tables'] !== [] || $gaps['missing_columns'] !== []) && function_exists('syncTenantMigrationsForTenant')) {
        $tenantId = function_exists('moduleTenantSettingsTenantId') ? (int) (moduleTenantSettingsTenantId() ?? 0) : 0;
        if ($tenantId > 0) {
            $previousUnguarded = (bool) kernel_request_context_get('_kernel_db_unguarded', false);
            kernel_request_context_set('_kernel_db_unguarded', true);
            try {
                $sync = syncTenantMigrationsForTenant($tenantId);
                contactFormResetSchemaState();
                $gaps = contactFormCollectSchemaGaps();
            } catch (Throwable $e) {
                if (function_exists('write_log')) {
                    write_log('contact-form: schema sync failed: ' . $e->getMessage(), 'warning');
                }
            } finally {
                kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
            }
        }
    }

    $details = [];
    if ($gaps['missing_tables'] !== []) {
        $details[] = 'tables: ' . implode(', ', $gaps['missing_tables']);
    }
    if ($gaps['missing_columns'] !== []) {
        $details[] = 'columns: ' . implode(', ', $gaps['missing_columns']);
    }

    $ready = $gaps['missing_tables'] === [] && $gaps['missing_columns'] === [];
    $status = [
        'ready' => $ready,
        'missing_tables' => $gaps['missing_tables'],
        'missing_columns' => $gaps['missing_columns'],
        'sync' => $sync,
        'message' => $ready
            ? null
            : 'Contact form schema is not ready yet. Missing ' . implode('; ', $details) . '. Reload the page after migrations finish.',
    ];

    $GLOBALS['_contact_form_schema_status'] = $status;
    return $status;
}

function contactFormListForms(bool $activeOnly = false): array
{
    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return [];
    }

    try {
        $sql = 'SELECT id, name, slug, success_message, captcha_enabled, status, created_at, updated_at'
            . ' FROM contact_forms';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= ' ORDER BY name ASC';

        $rows = contactFormDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map('contactFormNormalizeFormRow', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function contactFormGetFieldsForForm(int $formId): array
{
    if ($formId <= 0) {
        return [];
    }

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return [];
    }

    try {
        $stmt = contactFormDb()->prepare(
            'SELECT id, form_id, field_type, label, name, placeholder, help_text, options_text, required, sort_order, created_at, updated_at'
            . ' FROM contact_form_fields WHERE form_id = :form_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':form_id' => $formId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map('contactFormNormalizeFieldRow', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function contactFormGetFormById(int $formId, bool $withFields = false, bool $activeOnly = false): ?array
{
    if ($formId <= 0) {
        return null;
    }

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return null;
    }

    try {
        $sql = 'SELECT id, name, slug, success_message, submit_label, captcha_enabled, status, created_at, updated_at FROM contact_forms WHERE id = :id';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' LIMIT 1';

        $stmt = contactFormDb()->prepare($sql);
        $stmt->execute([':id' => $formId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $form = contactFormNormalizeFormRow($row);
        if ($withFields) {
            $form['fields'] = contactFormGetFieldsForForm($formId);
        }

        return $form;
    } catch (Throwable $e) {
        return null;
    }
}

function contactFormGetFormBySlug(string $slug, bool $withFields = false, bool $activeOnly = true): ?array
{
    $slug = contactFormSlugify($slug);
    if ($slug === '') {
        return null;
    }

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return null;
    }

    try {
        $sql = 'SELECT id, name, slug, success_message, submit_label, captcha_enabled, status, created_at, updated_at FROM contact_forms WHERE slug = :slug';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' LIMIT 1';

        $stmt = contactFormDb()->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $form = contactFormNormalizeFormRow($row);
        if ($withFields) {
            $form['fields'] = contactFormGetFieldsForForm((int) $form['id']);
        }

        return $form;
    } catch (Throwable $e) {
        return null;
    }
}

function contactFormGetFieldById(int $fieldId): ?array
{
    if ($fieldId <= 0) {
        return null;
    }

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return null;
    }

    try {
        $stmt = contactFormDb()->prepare(
            'SELECT id, form_id, field_type, label, name, placeholder, help_text, options_text, required, sort_order, created_at, updated_at'
            . ' FROM contact_form_fields WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $fieldId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? contactFormNormalizeFieldRow($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function contactFormGetSubmissionById(int $submissionId): ?array
{
    if ($submissionId <= 0) {
        return null;
    }

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return null;
    }

    try {
        $stmt = contactFormDb()->prepare(
            'SELECT s.id, s.form_id, s.name, s.email, s.message, s.form_data, s.ip_address, s.status, s.created_at, s.reviewed_at, s.reviewed_by, s.updated_at,'
            . ' f.name AS form_name, f.slug AS form_slug'
            . ' FROM contact_form_submissions s'
            . ' LEFT JOIN contact_forms f ON f.id = s.form_id'
            . ' WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $submissionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['form_label'] = contactFormSubmissionFormLabel($row);
        $row['status'] = contactFormCanonicalSubmissionStatus((string) ($row['status'] ?? 'new'));
        $row['status_label'] = contactFormSubmissionStatusLabel((string) $row['status']);
        $row['records'] = contactFormSubmissionRecordsFromRow($row);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function contactFormBuilderFormOptions(): array
{
    $options = [
        ['value' => '', 'label' => 'Legacy inline form'],
    ];

    foreach (contactFormListForms(true) as $form) {
        $label = $form['name'];
        if ($form['slug'] !== '') {
            $label .= ' (' . $form['slug'] . ')';
        }

        $options[] = [
            'value' => (string) $form['id'],
            'label' => $label,
        ];
    }

    return $options;
}

function contactFormGenerateCaptcha(): array
{
    $a = random_int(2, 12);
    $b = random_int(2, 12);
    $multiply = (bool) random_int(0, 1);
    $operator = $multiply ? '*' : '+';
    $answer = $multiply ? ($a * $b) : ($a + $b);

    $payload = base64_encode((string) json_encode([
        'a' => (string) $answer,
        'e' => time() + 900,
    ]));
    $secret = $_ENV['JWT_SECRET'] ?? 'contact-form-captcha-fallback';
    $token = $payload . '.' . hash_hmac('sha256', $payload, $secret);

    return [
        'question' => "What is {$a} {$operator} {$b}?",
        'token' => $token,
    ];
}

function contactFormVerifyCaptcha(string $token, string $submitted): bool
{
    if ($token === '' || $submitted === '') {
        return false;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$payload, $signature] = $parts;
    $secret = $_ENV['JWT_SECRET'] ?? 'contact-form-captcha-fallback';
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        return false;
    }

    $data = json_decode((string) base64_decode($payload), true);
    if (!is_array($data) || (int) ($data['e'] ?? 0) < time()) {
        return false;
    }

    return strtolower(trim($submitted)) === strtolower(trim((string) ($data['a'] ?? '')));
}

function contactFormRenderBuilderBlock(
    array $props,
    array $style,
    array $attrs,
    string $children,
    array $node,
    array $context
): string {
    $savedFormId = (int) ($props['form_id'] ?? 0);
    if ($savedFormId > 0) {
        return contactFormRenderDynamic($savedFormId, [
            'title' => $props['title'] ?? '',
            'submit_label' => $props['submit_label'] ?? 'Submit',
            'success_message' => $props['success_message'] ?? '',
        ]);
    }

    $settings = contactFormGetSettings();

    return contactFormRender([
        'title' => $props['title'] ?? '',
        'submit_label' => $props['submit_label'] ?? 'Send Message',
        'success_message' => $props['success_message'] ?? $settings['success_message'],
    ]);
}

function contactFormRenderFromAttrs(array $attrs = []): string
{
    $formId = (int) ($attrs['form_id'] ?? 0);
    if ($formId > 0) {
        return contactFormRenderDynamic($formId, $attrs);
    }

    $formSlug = trim((string) ($attrs['form'] ?? $attrs['form_slug'] ?? ''));
    if ($formSlug !== '') {
        $form = contactFormGetFormBySlug($formSlug, true, true);
        if (!$form) {
            return contactFormRenderUnavailable('The selected form is unavailable.');
        }

        return contactFormRenderDynamic((int) $form['id'], $attrs);
    }

    return contactFormRender($attrs);
}

function contactFormRenderSharedStyles(): string
{
    static $injected = [];

    $tenantId = (function_exists('moduleTenantSettingsTenantId') ? moduleTenantSettingsTenantId() : null) ?? 0;
    if (!empty($injected[$tenantId])) {
        return '';
    }

    $injected[$tenantId] = true;

    return <<<'HTML'
<style>
.contact-form-wrap {
    max-width: 720px;
    margin: 1.5rem 0;
    padding: 1.5rem;
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.10);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}
.contact-form-title {
    margin: 0 0 1rem;
    font-size: 1.5rem;
    line-height: 1.2;
    font-weight: 700;
    color: #1e293b;
}
.contact-form-note {
    margin: 0 0 1rem;
    color: #475569;
    font-size: .95rem;
}
.contact-form {
    display: grid;
    gap: 1rem;
}
.contact-form .form-group {
    display: grid;
    gap: .45rem;
}
.contact-form label {
    display: block;
    margin: 0;
    font-size: .95rem;
    font-weight: 600;
    color: #334155;
}
.contact-form input,
.contact-form textarea,
.contact-form select {
    width: 100%;
    appearance: none;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    border-radius: 12px;
    padding: .85rem .95rem;
    font: inherit;
    line-height: 1.45;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.contact-form input::placeholder,
.contact-form textarea::placeholder,
.contact-form select::placeholder {
    color: #94a3b8;
}
.contact-form input:focus,
.contact-form textarea:focus,
.contact-form select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}
.contact-form textarea {
    min-height: 160px;
    resize: vertical;
}
.contact-form-help {
    color: #64748b;
    font-size: .82rem;
    line-height: 1.5;
}
.contact-form-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: .8rem 1.25rem;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
    font-size: .95rem;
    font-weight: 700;
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22);
}
.contact-form-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.28);
}
.contact-form-submit:disabled {
    opacity: .65;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
.contact-form-status {
    display: none;
    padding: 1.1rem 1.25rem;
    border-radius: 12px;
    font-size: .94rem;
    font-weight: 500;
    line-height: 1.5;
}
.contact-form-success {
    display: block;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
    font-size: 1rem;
    font-weight: 600;
}
.contact-form-error {
    display: block;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}
.contact-form-empty {
    padding: 1rem;
    border-radius: 12px;
    background: #f8fafc;
    color: #475569;
}
@media (max-width: 640px) {
    .contact-form-wrap {
        padding: 1rem;
        border-radius: 14px;
    }
}
.contact-form input:hover,
.contact-form textarea:hover,
.contact-form select:hover {
    border-color: #94a3b8;
}
.contact-form .form-group.has-error input,
.contact-form .form-group.has-error textarea,
.contact-form .form-group.has-error select {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12);
}
.contact-form .form-group.has-error label {
    color: #dc2626;
}
.contact-form-field-error {
    color: #dc2626;
    font-size: .82rem;
    font-weight: 500;
}
.contact-form-field-error:empty {
    display: none;
}
.contact-form-required-legend {
    margin: -.15rem 0 .5rem;
    font-size: .8rem;
    color: #94a3b8;
}
.contact-form select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    background-size: 16px 16px;
    padding-right: 2.5rem;
}
@keyframes cf-spin {
    to { transform: rotate(360deg); }
}
.cf-spinner {
    display: none;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: cf-spin .6s linear infinite;
    vertical-align: middle;
    margin-right: .45rem;
    flex-shrink: 0;
}
.contact-form-submit.is-loading .cf-spinner {
    display: inline-block;
}
.contact-form-checkbox-group {
    gap: .3rem;
}
.contact-form-checkbox-label {
    display: inline-flex;
    align-items: flex-start;
    gap: .5rem;
    font-size: .95rem;
    color: #334155;
    cursor: pointer;
    line-height: 1.5;
}
.contact-form-checkbox-label input[type="checkbox"] {
    width: auto;
    margin-top: .15rem;
    flex-shrink: 0;
}
.contact-form-checkbox-multi-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.contact-form-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.contact-form-radio-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: .95rem;
    font-weight: 400;
    color: #334155;
    cursor: pointer;
    line-height: 1.5;
}
.contact-form-radio-label input[type="radio"] {
    width: auto;
    flex-shrink: 0;
}
.contact-form-rating {
    display: flex;
    gap: 4px;
    align-items: center;
}
.cf-star {
    font-size: 1.75rem;
    background: none;
    border: none;
    cursor: pointer;
    color: #d1d5db;
    padding: 0;
    line-height: 1;
    transition: color .12s;
}
.cf-star.cf-star-active {
    color: #f59e0b;
}
.cf-star:hover,
.cf-star.cf-star-hover {
    color: #fbbf24;
}
.contact-form-range-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}
.contact-form-range-wrap input[type="range"] {
    flex: 1;
    width: auto;
    border: none;
    padding: 0;
    box-shadow: none;
    background: none;
    border-radius: 0;
}
.contact-form-range-wrap input[type="range"]:focus {
    box-shadow: none;
}
.contact-form-range-wrap output {
    min-width: 2.5rem;
    text-align: center;
    font-size: .9rem;
    color: #64748b;
    background: #f1f5f9;
    border-radius: 6px;
    padding: 2px 6px;
}
.contact-form-section {
    border-top: 2px solid #e2e8f0;
    padding-top: 12px;
    margin-top: 4px;
}
.contact-form-section-heading {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 4px;
}
.contact-form-section-desc {
    font-size: .875rem;
    color: #64748b;
    margin: 0;
}
</style>
HTML;
}

function contactFormEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function contactFormRenderUnavailable(string $message): string
{
    $styles = contactFormRenderSharedStyles();
    $messageHtml = contactFormEscape($message);

    return <<<HTML
{$styles}
<div class="contact-form-wrap">
    <div class="contact-form-empty">{$messageHtml}</div>
</div>
HTML;
}

function contactFormRenderCaptchaMarkup(string $formId): string
{
    $captcha = contactFormGenerateCaptcha();
    $question = contactFormEscape($captcha['question']);
    $token = contactFormEscape($captcha['token']);

    return <<<HTML
<div class="form-group contact-form-captcha-group">
    <label for="{$formId}-captcha_answer" data-captcha-question>{$question}</label>
    <input type="text" id="{$formId}-captcha_answer" name="captcha_answer" required inputmode="numeric" placeholder="Your answer" autocomplete="off" aria-describedby="{$formId}-captcha-help">
    <input type="hidden" name="captcha_token" value="{$token}">
    <div class="contact-form-help" id="{$formId}-captcha-help">Answer to confirm you are not a robot.</div>
    <div class="contact-form-field-error" role="alert" aria-live="assertive"></div>
</div>
HTML;
}

function contactFormRenderFieldMarkup(array $field, string $formId): string
{
    $field = contactFormNormalizeFieldRow($field);
    $fieldInputId = $formId . '-' . $field['name'];
    $fieldLabel = contactFormEscape($field['label']);
    $placeholder = contactFormEscape($field['placeholder']);
    $fieldName = contactFormEscape($field['name']);
    $requiredAttr = $field['required'] ? ' required' : '';
    $requiredMark = $field['required'] ? ' <span aria-hidden="true">*</span>' : '';
    $fieldTypeLower = strtolower((string) $field['field_type']);
    $fieldNameLower = strtolower((string) $field['name']);
    $autocompleteAttr = '';
    if ($fieldTypeLower === 'email' || $fieldNameLower === 'email') {
        $autocompleteAttr = ' autocomplete="email"';
    } elseif ($fieldTypeLower === 'tel' || $fieldNameLower === 'phone' || $fieldNameLower === 'tel') {
        $autocompleteAttr = ' autocomplete="tel"';
    } elseif ($fieldNameLower === 'name' || $fieldNameLower === 'full_name' || $fieldNameLower === 'fullname') {
        $autocompleteAttr = ' autocomplete="name"';
    }
    $ariaRequiredAttr = $field['required'] ? ' aria-required="true"' : '';
    $helpId = $field['help_text'] !== '' ? ($fieldInputId . '-help') : '';
    $ariaDescAttr = $helpId !== '' ? (' aria-describedby="' . $helpId . '"') : '';
    $helpHtml = $field['help_text'] !== ''
        ? '<div class="contact-form-help" id="' . $helpId . '">' . contactFormEscape($field['help_text']) . '</div>'
        : '';

    $inputHtml = '';
    if ($field['field_type'] === 'textarea') {
        $inputHtml = '<textarea id="' . $fieldInputId . '" name="' . $fieldName . '"' . $requiredAttr
            . $ariaRequiredAttr . $ariaDescAttr . ' rows="5" placeholder="' . $placeholder . '"></textarea>';
    } elseif ($field['field_type'] === 'select') {
        $optionsHtml = '<option value="">' . contactFormEscape($field['required'] ? 'Select an option' : 'Optional') . '</option>';
        foreach (contactFormParseOptionsText($field['options_text']) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionValue = contactFormEscape((string) ($option['value'] ?? ''));
            $optionLabel = contactFormEscape((string) ($option['label'] ?? ''));
            if ($optionValue === '' || $optionLabel === '') {
                continue;
            }

            $optionsHtml .= '<option value="' . $optionValue . '">' . $optionLabel . '</option>';
        }

        $inputHtml = '<select id="' . $fieldInputId . '" name="' . $fieldName . '"' . $requiredAttr
            . $ariaRequiredAttr . $ariaDescAttr . '>'
            . $optionsHtml
            . '</select>';
    } elseif ($field['field_type'] === 'radio') {
        $radiosHtml = '';
        foreach (contactFormParseOptionsText($field['options_text']) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optVal = contactFormEscape((string) ($option['value'] ?? ''));
            $optLbl = contactFormEscape((string) ($option['label'] ?? ''));
            if ($optVal === '' || $optLbl === '') {
                continue;
            }

            $radiosHtml .= '<label class="contact-form-radio-label">'
                . '<input type="radio" name="' . $fieldName . '" value="' . $optVal . '"'
                . $requiredAttr . $ariaRequiredAttr . '>'
                . ' ' . $optLbl
                . '</label>';
        }

        return '<div class="form-group">'
            . '<label>' . $fieldLabel . $requiredMark . '</label>'
            . '<div class="contact-form-radio-group" role="group" aria-label="' . $fieldLabel . '"' . $ariaDescAttr . '>'
            . ($radiosHtml !== '' ? $radiosHtml : '<span class="contact-form-help">No options configured.</span>')
            . '</div>'
            . $helpHtml
            . '<div class="contact-form-field-error" role="alert" aria-live="assertive"></div>'
            . '</div>';
    } elseif ($field['field_type'] === 'rating') {
        $starsHtml = '';
        for ($i = 1; $i <= 5; $i++) {
            $label = $i === 1 ? '1 star' : ($i . ' stars');
            $starsHtml .= '<button type="button" class="cf-star" data-value="' . $i . '" aria-label="' . $label . '">&#9733;</button>';
        }

        $inputHtml = '<div class="contact-form-rating" data-rating-group="' . $fieldInputId . '">'
            . '<input type="hidden" name="' . $fieldName . '" id="' . $fieldInputId . '" value=""' . $requiredAttr . '>'
            . $starsHtml
            . '</div>';

        return '<div class="form-group">'
            . '<label>' . $fieldLabel . $requiredMark . '</label>'
            . $inputHtml
            . $helpHtml
            . '<div class="contact-form-field-error" role="alert" aria-live="assertive"></div>'
            . '</div>';
    } elseif ($field['field_type'] === 'range') {
        $inputHtml = '<div class="contact-form-range-wrap">'
            . '<input type="range" id="' . $fieldInputId . '" name="' . $fieldName . '"'
            . ' min="0" max="100" value="50"' . $ariaDescAttr
            . ' oninput="this.nextElementSibling.value=this.value">'
            . '<output>50</output>'
            . '</div>';
    } elseif ($field['field_type'] === 'section') {
        $descHtml = $field['help_text'] !== ''
            ? '<p class="contact-form-section-desc">' . contactFormEscape($field['help_text']) . '</p>'
            : '';

        return '<div class="contact-form-section">'
            . '<h4 class="contact-form-section-heading">' . $fieldLabel . '</h4>'
            . $descHtml
            . '</div>';
    } elseif ($field['field_type'] === 'checkbox') {
        $parsedOptions = contactFormParseOptionsText($field['options_text']);
        $hasOptions = $parsedOptions !== [];

        if ($hasOptions) {
            // Multi-select checkbox group
            $groupName = $fieldName . '[]';
            $checkboxesHtml = '';
            foreach ($parsedOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optVal = contactFormEscape((string) ($option['value'] ?? ''));
                $optLbl = contactFormEscape((string) ($option['label'] ?? ''));
                if ($optVal === '' || $optLbl === '') {
                    continue;
                }

                $checkboxesHtml .= '<label class="contact-form-checkbox-label">'
                    . '<input type="checkbox" name="' . $groupName . '" value="' . $optVal . '"' . $ariaDescAttr . '>'
                    . ' ' . $optLbl
                    . '</label>';
            }

            return '<div class="form-group">'
                . '<label>' . $fieldLabel . $requiredMark . '</label>'
                . '<div class="contact-form-checkbox-multi-group" role="group" aria-label="' . $fieldLabel . '"' . $ariaDescAttr . '>'
                . ($checkboxesHtml !== '' ? $checkboxesHtml : '<span class="contact-form-help">No options configured.</span>')
                . '</div>'
                . $helpHtml
                . '<div class="contact-form-field-error" role="alert" aria-live="assertive"></div>'
                . '</div>';
        }

        // Single agree/disagree checkbox (no options configured)
        $checkboxLabel = $placeholder !== '' ? $placeholder : $fieldLabel;
        $inputHtml = '<label class="contact-form-checkbox-label">'
            . '<input type="checkbox" id="' . $fieldInputId . '" name="' . $fieldName . '" value="1"'
            . $requiredAttr . $ariaRequiredAttr . $ariaDescAttr . '>'
            . ' ' . contactFormEscape($checkboxLabel)
            . '</label>';

        return '<div class="form-group contact-form-checkbox-group">'
            . $inputHtml
            . $helpHtml
            . '<div class="contact-form-field-error" role="alert" aria-live="assertive"></div>'
            . '</div>';
    } elseif ($field['field_type'] === 'hidden') {
        return '<input type="hidden" name="' . $fieldName . '" value="' . $placeholder . '">';
    } else {
        $fieldType = contactFormEscape(contactFormFieldInputType((string) $field['field_type']));
        $extraAttr = $field['field_type'] === 'number' ? ' inputmode="decimal" step="any"' : '';
        $inputHtml = '<input type="' . $fieldType . '" id="' . $fieldInputId . '" name="' . $fieldName . '"'
            . $requiredAttr . $ariaRequiredAttr . $extraAttr . $autocompleteAttr . $ariaDescAttr . ' placeholder="' . $placeholder . '">';
    }

    return '<div class="form-group">'
        . '<label for="' . $fieldInputId . '">' . $fieldLabel . $requiredMark . '</label>'
        . $inputHtml
        . $helpHtml
        . '<div class="contact-form-field-error" role="alert" aria-live="assertive"></div>'
        . '</div>';
}

function contactFormRenderClientScript(string $formId, string $successMessage, bool $hasCaptcha): string
{
    $successJson = json_encode($successMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    $hasCaptchaJson = $hasCaptcha ? 'true' : 'false';

    return <<<HTML
<script>
(function () {
    var form = document.getElementById('{$formId}');
    if (!form) return;

    var statusEl = form.parentElement.querySelector('.contact-form-status');
    var submitBtn = form.querySelector('.contact-form-submit');
    var btnLabel = submitBtn ? submitBtn.querySelector('.cf-btn-label') : null;
    var successMsg = {$successJson};
    var hasCaptcha = {$hasCaptchaJson};
    var originalLabel = btnLabel ? btnLabel.textContent : '';

    function setLoading(isLoading) {
        submitBtn.disabled = isLoading;
        submitBtn.classList.toggle('is-loading', isLoading);
        if (btnLabel) {
            btnLabel.textContent = isLoading ? 'Sending\u2026' : originalLabel;
        }
    }

    function clearFieldErrors() {
        form.querySelectorAll('.form-group.has-error').forEach(function (g) {
            g.classList.remove('has-error');
        });
        form.querySelectorAll('.contact-form-field-error').forEach(function (el) {
            el.textContent = '';
        });
    }

    function showFieldErrors(fieldErrors) {
        if (!fieldErrors || typeof fieldErrors !== 'object') return;
        Object.keys(fieldErrors).forEach(function (fieldName) {
            var input = form.querySelector('[name="' + fieldName + '"]');
            if (!input) return;
            var group = input.closest('.form-group');
            if (group) {
                group.classList.add('has-error');
                var errorEl = group.querySelector('.contact-form-field-error');
                if (errorEl) errorEl.textContent = fieldErrors[fieldName];
            }
        });
        var firstError = form.querySelector('.form-group.has-error input, .form-group.has-error textarea, .form-group.has-error select');
        if (firstError) firstError.focus();
    }

    function refreshCaptcha() {
        if (!hasCaptcha) return Promise.resolve();

        return fetch(form.dataset.captchaUrl)
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var questionEl = form.querySelector('[data-captcha-question]');
                var tokenInput = form.querySelector('[name="captcha_token"]');
                var answerInput = form.querySelector('[name="captcha_answer"]');
                if (questionEl && payload.question) questionEl.textContent = payload.question;
                if (tokenInput && payload.token) tokenInput.value = payload.token;
                if (answerInput) answerInput.value = '';
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        clearFieldErrors();

        var data = {};
        new FormData(form).forEach(function (value, key) {
            if (key.slice(-2) === '[]') {
                var arrKey = key.slice(0, -2);
                if (!Array.isArray(data[arrKey])) data[arrKey] = [];
                data[arrKey].push(value);
            } else {
                data[key] = value;
            }
        });

        setLoading(true);
        statusEl.style.display = 'none';
        statusEl.className = 'contact-form-status';

        fetch(form.dataset.submitUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (payload.ok) {
                    statusEl.className = 'contact-form-status contact-form-success';
                    statusEl.innerHTML = '\u2714 ' + (payload.message || successMsg);
                    statusEl.style.display = '';
                    statusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    form.reset();
                    clearFieldErrors();
                    if (hasCaptcha) refreshCaptcha();
                } else {
                    statusEl.className = 'contact-form-status contact-form-error';
                    statusEl.textContent = payload.error || 'Please check the form below and try again.';
                    statusEl.style.display = '';
                    if (payload.field_errors) showFieldErrors(payload.field_errors);
                    if (payload.refresh_captcha) refreshCaptcha();
                }
            })
            .catch(function () {
                statusEl.style.display = '';
                statusEl.className = 'contact-form-status contact-form-error';
                statusEl.textContent = 'A network error occurred. Please try again.';
            })
            .finally(function () {
                setLoading(false);
            });
    });

    // Star rating interaction
    form.querySelectorAll('.contact-form-rating').forEach(function (ratingEl) {
        var hiddenInput = ratingEl.querySelector('input[type="hidden"]');
        var stars = Array.prototype.slice.call(ratingEl.querySelectorAll('.cf-star'));
        function updateStars(upTo) {
            stars.forEach(function (s, idx) {
                s.classList.toggle('cf-star-active', idx < upTo);
                s.classList.toggle('cf-star-hover', false);
            });
        }
        stars.forEach(function (star) {
            star.addEventListener('mouseenter', function () {
                var val = parseInt(star.getAttribute('data-value'), 10);
                stars.forEach(function (s, idx) {
                    s.classList.toggle('cf-star-hover', idx < val);
                });
            });
            star.addEventListener('mouseleave', function () {
                var current = hiddenInput ? parseInt(hiddenInput.value, 10) || 0 : 0;
                updateStars(current);
            });
            star.addEventListener('click', function () {
                var val = parseInt(star.getAttribute('data-value'), 10);
                if (hiddenInput) hiddenInput.value = val;
                updateStars(val);
            });
        });
        ratingEl.addEventListener('mouseleave', function () {
            var current = hiddenInput ? parseInt(hiddenInput.value, 10) || 0 : 0;
            updateStars(current);
        });
    });
})();
</script>
HTML;
}

function contactFormRenderFrame(
    string $formId,
    string $title,
    string $fieldsHtml,
    string $submitLabel,
    string $successMessage,
    string $hiddenHtml = '',
    string $noteHtml = '',
    string $captchaHtml = '',
    bool $honeypot = true,
    bool $hasCaptcha = false
): string {
    $stylesHtml = contactFormRenderSharedStyles();
    $titleHtml = $title !== ''
        ? '<h3 class="contact-form-title">' . contactFormEscape($title) . '</h3>'
        : '';
    $honeypotHtml = $honeypot
        ? '<input type="text" name="_hp_name" class="contact-form-hp" tabindex="-1" autocomplete="off" style="display:none!important;position:absolute;left:-9999px" aria-hidden="true">'
        : '';
    $submitLabelHtml = contactFormEscape($submitLabel);
    $requiredLegend = '<p class="contact-form-required-legend">Fields marked <span aria-hidden="true">*</span> are required</p>';
    $scriptHtml = contactFormRenderClientScript($formId, $successMessage, $hasCaptcha);
    $submitUrl = contactFormEscape(contactFormPath('/api/v1/contact-form/submit'));
    $captchaUrl = contactFormEscape(contactFormPath('/api/v1/contact-form/captcha'));

    return <<<HTML
{$stylesHtml}
<div class="contact-form-wrap" id="wrap-{$formId}">
    {$titleHtml}
    {$requiredLegend}
    {$noteHtml}
    <form id="{$formId}" class="contact-form" data-submit-url="{$submitUrl}" data-captcha-url="{$captchaUrl}" novalidate>
        {$hiddenHtml}
        {$honeypotHtml}
        {$fieldsHtml}
        {$captchaHtml}
        <div class="contact-form-status" role="status" aria-live="polite" style="display:none"></div>
        <button type="submit" class="btn btn-primary contact-form-submit">
            <span class="cf-spinner" aria-hidden="true"></span>
            <span class="cf-btn-label">{$submitLabelHtml}</span>
        </button>
    </form>
    {$scriptHtml}
</div>
HTML;
}

function contactFormRender(array $attrs = []): string
{
    $settings = contactFormGetSettings();
    $formId = trim((string) ($attrs['id'] ?? 'cf-' . substr(md5(uniqid('', true)), 0, 7)));
    $title = trim((string) ($attrs['title'] ?? ''));
    $submitLabel = trim((string) ($attrs['submit_label'] ?? 'Send Message'));
    $successMessage = trim((string) ($attrs['success_message'] ?? $settings['success_message']));
    $honeypot = ($settings['spam_protection'] ?? 'honeypot') === 'honeypot';

    $fieldsHtml = <<<HTML
<div class="form-group">
    <label for="{$formId}-name">Name <span aria-hidden="true">*</span></label>
    <input type="text" id="{$formId}-name" name="name" required aria-required="true" autocomplete="name" placeholder="Your name">
    <div class="contact-form-field-error" role="alert" aria-live="assertive"></div>
</div>
<div class="form-group">
    <label for="{$formId}-email">Email <span aria-hidden="true">*</span></label>
    <input type="email" id="{$formId}-email" name="email" required aria-required="true" autocomplete="email" placeholder="your@email.com">
    <div class="contact-form-field-error" role="alert" aria-live="assertive"></div>
</div>
<div class="form-group">
    <label for="{$formId}-message">Message <span aria-hidden="true">*</span></label>
    <textarea id="{$formId}-message" name="message" required aria-required="true" rows="5" placeholder="How can we help you?"></textarea>
    <div class="contact-form-field-error" role="alert" aria-live="assertive"></div>
</div>
HTML;

    return contactFormRenderFrame(
        $formId,
        $title,
        $fieldsHtml,
        $submitLabel,
        $successMessage,
        '',
        '',
        '',
        $honeypot,
        false
    );
}

function contactFormRenderDynamic(int $savedFormId, array $attrs = []): string
{
    $form = contactFormGetFormById($savedFormId, true, true);
    if (!$form) {
        return contactFormRenderUnavailable('The selected form is unavailable.');
    }

    $settings = contactFormGetSettings();
    $formId = trim((string) ($attrs['id'] ?? 'cf-' . substr(md5(uniqid('', true)), 0, 7)));
    $title = trim((string) ($attrs['title'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($form['name'] ?? ''));
    }

    $submitLabel = trim((string) ($attrs['submit_label'] ?? ''));
    if ($submitLabel === '') {
        $submitLabel = trim((string) ($form['submit_label'] ?? ''));
    }
    if ($submitLabel === '') {
        $submitLabel = 'Send Message';
    }

    $successMessage = trim((string) ($attrs['success_message'] ?? ''));
    if ($successMessage === '') {
        $successMessage = trim((string) ($form['success_message'] ?? ''));
    }
    if ($successMessage === '') {
        $successMessage = trim((string) ($settings['success_message'] ?? 'Thank you for your submission.'));
    }

    $fields = $form['fields'] ?? [];
    if (!is_array($fields) || $fields === []) {
        return contactFormRenderUnavailable('This form does not have any fields yet.');
    }

    $fieldMarkup = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldMarkup[] = contactFormRenderFieldMarkup($field, $formId);
    }

    $hiddenHtml = '<input type="hidden" name="form_id" value="' . (int) $form['id'] . '">';
    $captchaHtml = $form['captcha_enabled'] ? contactFormRenderCaptchaMarkup($formId) : '';
    $honeypot = ($settings['spam_protection'] ?? 'honeypot') === 'honeypot';

    return contactFormRenderFrame(
        $formId,
        $title,
        implode("\n", $fieldMarkup),
        $submitLabel,
        $successMessage,
        $hiddenHtml,
        '',
        $captchaHtml,
        $honeypot,
        $form['captcha_enabled'] === 1
    );
}
