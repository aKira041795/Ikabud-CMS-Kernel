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
        echo "PASS: {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$ikabudPath = BASE_PATH . '/ikabud';
$code = (string) file_get_contents($ikabudPath);

t('ikabud file exists', is_file($ikabudPath));
t('ikabud scaffold template defines modulePath with BASE_PATH', str_contains($code, '\\$modulePath = BASE_PATH . \'/modules/{$id}\';'));
t('ikabud scaffold template uses escaped modulePath for routesFile', str_contains($code, '\\$routesFile = \\$modulePath . \'/routes.php\';'));
t('ikabud scaffold template uses escaped modulePath for helpersFile', str_contains($code, '\\$helpersFile = \\$modulePath . \'/helpers.php\';'));
t('ikabud scaffold template no longer embeds unescaped routesFile expression', !str_contains($code, '\\$routesFile = $modulePath . \'/routes.php\';'));
t('ikabud scaffold template no longer embeds unescaped helpersFile expression', !str_contains($code, '\\$helpersFile = $modulePath . \'/helpers.php\';'));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
