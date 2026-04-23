<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
$modulePath = __DIR__;
$manifestPath = $modulePath . '/module.json';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "module.json not found for moodle-integration\n");
    exit(1);
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || ($manifest['id'] ?? '') !== 'moodle-integration') {
    fwrite(STDERR, "Invalid moodle-integration manifest\n");
    exit(1);
}

echo "Moodle Integration preflight complete.\n";
echo "Next steps:\n";
echo "1. Ensure the module is placed at modules/moodle-integration\n";
echo "2. Run: php ikabud migrate moodle-integration\n";
echo "3. Enable the module for the target tenant or environment\n";
echo "4. Configure settings via the superadmin module settings UI\n";

exit(0);