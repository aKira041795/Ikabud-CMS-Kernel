<?php
/** Run: php tests/theme_asset_publish_test.php */
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/storage/cms-themes/ark/style.css';
$published = $root . '/public/assets/cms/themes/ark/style.css';
$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " {$label}" . (!$ok && $detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "\nTheme asset publication command\n\n";
t('ARK source stylesheet exists', is_file($source));
$original = (string) file_get_contents($source);
$marker = "\n/* theme:publish verification " . getmypid() . " */\n";
$exit = 1;
$output = [];

try {
    // Change the source, never the generated destination; the command owns publishing.
    file_put_contents($source, $original . $marker);
    clearstatcache(true, $source);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ikabud') . ' theme:publish ark 2>&1', $output, $exit);
    t('theme:publish exits successfully', $exit === 0, implode("\n", $output));
    t('published stylesheet exactly matches changed source',
        is_file($published) && hash_file('sha256', $source) === hash_file('sha256', $published));
    t('changed source content reached the published asset', str_contains((string) @file_get_contents($published), trim($marker)));
} finally {
    file_put_contents($source, $original);
    $restoreOutput = [];
    $restoreExit = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ikabud') . ' theme:publish ark 2>&1', $restoreOutput, $restoreExit);
    t('test restores and republishes the original source',
        $restoreExit === 0 && hash_file('sha256', $source) === hash_file('sha256', $published),
        implode("\n", $restoreOutput));
}

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
