<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/cms/admin/page-builder/create';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER ASSET VERSIONING ===\n";

$cssPath = PUBLIC_PATH . '/assets/cms/builder/builder.css';
$cssMtime = is_file($cssPath) ? (string)@filemtime($cssPath) : '';
$cssUrl = cmsAdminBuilderAssetUrl('builder.css');

t('builder asset helper returns the builder stylesheet path', str_contains($cssUrl, '/assets/cms/builder/builder.css'), $cssUrl);
t('builder asset helper appends the current filemtime for cache busting', $cssMtime !== '' && str_contains($cssUrl, '?v=' . $cssMtime), $cssUrl);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);