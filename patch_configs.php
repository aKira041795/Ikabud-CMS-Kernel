<?php
$f = 'modules/wms/helpers/10-core.php';
$c = file_get_contents($f);
$newFuncs = <<<EOT

function wmsConfigGet(string \$key, mixed \$default = null): mixed
{
    \$row = wmsFetchOne('SELECT config_value FROM wms_configs WHERE config_key = ? LIMIT 1', [\$key]);
    if (\$row !== null && isset(\$row['config_value'])) {
        \$val = json_decode(\$row['config_value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return \$val;
        }
    }
    return \$default;
}

function wmsConfigSet(string \$key, mixed \$value, ?string \$description = null): void
{
    \$json = json_encode(\$value, JSON_UNESCAPED_UNICODE);
    \$db = wmsDb();
    if (\$description !== null) {
        \$db->execute(
            'INSERT INTO wms_configs (config_key, config_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), description = VALUES(description)',
            [\$key, \$json, \$description]
        );
    } else {
        \$db->execute(
            'INSERT INTO wms_configs (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
            [\$key, \$json]
        );
    }
}
EOT;
$c = str_replace("function wmsNormalizeDecimal", $newFuncs . "\n\nfunction wmsNormalizeDecimal", $c);
file_put_contents($f, $c);
