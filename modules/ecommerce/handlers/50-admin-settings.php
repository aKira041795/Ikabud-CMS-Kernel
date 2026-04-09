<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Settings (handlers/50-admin-settings.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET  /admin/ecommerce/settings  — settings page
 * POST /admin/ecommerce/settings  — save settings
 */
function ecAdminSettingsFields(): array
{
    $modules = discoverModules();
    $manifest = $modules['ecommerce'] ?? [];
    $fields = moduleEditableSettingsFields($manifest);
    $rendered = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $currentValue = ecSettings($key);
        $isCheckbox = in_array($type, ['checkbox', 'bool', 'boolean'], true);
        $isSelect = ($type === 'select');
        $isTextarea = ($type === 'textarea');
        $inputType = in_array($type, ['number', 'int', 'integer'], true) ? 'number' : ($type === 'email' ? 'email' : 'text');
        $controlClass = trim((string)($field['control_class'] ?? $field['input_class'] ?? ''));

        $options = [];
        if ($isSelect && is_array($field['options'] ?? null)) {
            foreach ($field['options'] as $opt) {
                if (is_string($opt)) {
                    $options[] = [
                        'value' => $opt,
                        'label' => $opt,
                        'selected' => ((string)$currentValue === $opt),
                    ];
                } elseif (is_array($opt)) {
                    $value = (string)($opt['value'] ?? '');
                    $options[] = [
                        'value' => $value,
                        'label' => (string)($opt['label'] ?? $value),
                        'selected' => ((string)$currentValue === $value),
                    ];
                }
            }
        }

        $rendered[] = [
            'key' => $key,
            'label' => (string)($field['label'] ?? $key),
            'description' => (string)($field['description'] ?? ''),
            'type' => $type,
            'is_checkbox' => $isCheckbox,
            'is_select' => $isSelect,
            'is_textarea' => $isTextarea,
            'is_text' => (!$isCheckbox && !$isSelect && !$isTextarea),
            'input_type' => $inputType,
            'control_class' => $controlClass,
            'current_value' => $isCheckbox ? '' : (string)$currentValue,
            'is_checked' => $isCheckbox ? !empty($currentValue) : false,
            'options' => $options,
        ];
    }

    return $rendered;
}

function ecAdminSettings(): void
{
    $user = ecRequireAdmin();
    $modules = discoverModules();
    $manifest = $modules['ecommerce'] ?? [];
    $fields = moduleEditableSettingsFields($manifest);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();

        $settings = getModuleSettings('ecommerce');
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $type = strtolower(trim((string)($field['type'] ?? 'text')));
            $raw = $input[$key] ?? null;

            if (in_array($type, ['checkbox', 'bool', 'boolean'], true)) {
                $settings[$key] = !empty($input[$key]);
                continue;
            }

            if ($raw === null) {
                continue;
            }

            if (in_array($type, ['number', 'int', 'integer'], true)) {
                $settings[$key] = (string)(0 + (float)$raw);
                continue;
            }

            if ($type === 'select' && is_array($field['options'] ?? null)) {
                $allowedValues = [];
                foreach ($field['options'] as $opt) {
                    if (is_string($opt)) {
                        $allowedValues[$opt] = true;
                    } elseif (is_array($opt) && array_key_exists('value', $opt)) {
                        $allowedValues[(string)$opt['value']] = true;
                    }
                }
                $val = (string)$raw;
                if (!empty($allowedValues) && !isset($allowedValues[$val])) {
                    continue;
                }
                $settings[$key] = $val;
                continue;
            }

            $settings[$key] = trim((string)$raw);
        }

        try {
            ecSyncWmsFulfillmentBridges(!empty($settings['wms_fulfillment_bridge_enabled']));
            saveModuleSettings('ecommerce', $settings);
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Settings saved.'];
        } catch (\Throwable $e) {
            $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Settings were not saved: ' . $e->getMessage()];
        }
        header('Location: /ecommerce/admin/settings');
        exit;
    }

    $ctx = ecAdminContext($user, 'settings', [
        'message' => $_SESSION['ec_message'] ?? null,
        'settings_fields' => ecAdminSettingsFields(),
        'settings_map'    => (function () {
            $map = [];
            foreach (ecAdminSettingsFields() as $f) {
                $map[$f['key']] = $f;
            }
            return $map;
        })(),
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/settings.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}
