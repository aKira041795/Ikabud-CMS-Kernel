<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

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

echo "\n=== CMS FONT LOADING ===\n";

$customizerHelper = file_get_contents(__DIR__ . '/../modules/cms/helpers/80-customizer.php') ?: '';
$nativeLayout = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/layouts/public.disyl') ?: '';

t('customizer font helper no longer emits preconnect tags', !str_contains($customizerHelper, 'rel="preconnect"'));
t('customizer font helper builds a consolidated css2 Google Fonts URL', str_contains($customizerHelper, 'https://fonts.googleapis.com/css2?'));
t('native default public layout does not hardcode Google Fonts preconnect', !str_contains($nativeLayout, 'fonts.gstatic.com'));
t('native default public layout does not hardcode Inter stylesheet', !str_contains($nativeLayout, 'family=Inter'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);