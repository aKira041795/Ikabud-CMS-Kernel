<?php
/**
 * Refresh and optionally apply extension updates from creator-published feeds.
 *
 * The kernel half of the update system (kernelUpdatesSyncCatalog) tracks ONE
 * repository's releases for the kernel and the modules shipped in it. Extensions
 * installed from a ZIP have no such repository in this install, so each declares
 * its own feed in module.json:
 *
 *     "update_url": "https://example.com/my-extension/update.json"
 *
 * Usage (cron, every 6h is enough):
 *
 *     php scripts/check-extension-updates.php            # refresh state only
 *     php scripts/check-extension-updates.php --apply     # also apply opted-in updates
 *
 * Without --apply this script NEVER changes installed code: it only records what
 * the feeds advertise, so the CMS shows "1.1.0 -> 1.2.0" and an operator decides.
 * That is why the safe form is the default one.
 *
 * Exit codes: 0 = all reachable feeds checked; 1 = at least one feed failed or an
 * opted-in update failed.
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';
require SRC_PATH . '/helpers/updates.php';

$apply = in_array('--apply', $argv ?? [], true);

$checked = moduleExtensionCheckAll(['force' => true]);
$feedErrors = [];

echo 'Extension update check: ' . count($checked) . " feed(s) declared\n";
foreach ($checked as $moduleId => $result) {
    $state = moduleExtensionUpdateState((string) $moduleId);
    if (empty($result['ok'])) {
        $feedErrors[] = (string) $moduleId;
        echo '  FAIL  ' . $moduleId . ' — ' . (string) ($result['error'] ?? 'unknown error') . "\n";
        continue;
    }
    if (!empty($state['update_available'])) {
        echo '  UPDATE ' . $moduleId . ' ' . ($state['installed_version'] ?: '?') . ' -> ' . $state['latest_version']
            . ($state['auto_update'] ? ' (auto-update on)' : '') . "\n";
        continue;
    }
    echo '  OK    ' . $moduleId . ' at ' . ($state['installed_version'] ?: '?') . "\n";
}

if ($checked === []) {
    echo "  (no installed extension declares an update feed)\n";
}

if (!$apply) {
    exit($feedErrors === [] ? 0 : 1);
}

$result = moduleExtensionAutoUpdateAll();
echo "\nAuto-apply: " . count($result['applied']) . ' applied, '
    . count($result['skipped']) . ' not opted in, '
    . count($result['failed']) . " failed\n";

foreach ($result['applied'] as $moduleId) {
    echo '  APPLIED ' . $moduleId . "\n";
}
foreach ($result['failed'] as $moduleId => $error) {
    echo '  FAILED  ' . $moduleId . ' — ' . $error . "\n";
}

exit(($feedErrors === [] && $result['failed'] === []) ? 0 : 1);
