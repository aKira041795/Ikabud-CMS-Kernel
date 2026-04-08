<?php

declare(strict_types=1);

function wmsApiConfigsList(): never
{
    wmsRequireRole(['admin', 'supervisor']);

    $db = wmsDb();
    $configs = $db->fetchAll('SELECT config_key, config_value, description, updated_at FROM wms_configs ORDER BY config_key ASC');
    
    $parsed = [];
    foreach ($configs as $cfg) {
        $val = json_decode($cfg['config_value'], true);
        $cfg['config_value'] = (json_last_error() === JSON_ERROR_NONE) ? $val : $cfg['config_value'];
        $parsed[] = $cfg;
    }

    wmsJson(['configs' => $parsed]);
}

function wmsApiConfigsUpdate(): never
{
    wmsRequireRole(['admin', 'supervisor']);

    $input = wmsInput('configs');
    if (!is_array($input)) {
        wmsJsonError('configs field must be an object/array mapping keys to values.', 400);
    }

    $db = wmsDb();
    $db->beginTransaction();
    try {
        foreach ($input as $key => $value) {
            $keyStr = wmsSanitizeString((string)$key, 100);
            wmsConfigSet($keyStr, $value);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        wmsJsonError('Failed to update configs: ' . $e->getMessage(), 500);
    }

    // Log the configuration change
    $user = wmsUser();
    wmsLog('Configurations updated by ' . ($user['email'] ?? 'unknown'));

    wmsJson(['success' => true, 'message' => 'Configurations updated.']);
}
