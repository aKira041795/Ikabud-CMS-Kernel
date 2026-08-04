<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/dev/validate_json_files.php <file1> [file2 ...]\n");
    exit(2);
}

$failed = 0;

for ($i = 1; $i < $argc; $i++) {
    $path = $argv[$i];

    if (!is_file($path)) {
        fwrite(STDERR, $path . ": missing\n");
        $failed++;
        continue;
    }

    $raw = (string)file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, $path . ": INVALID JSON: " . json_last_error_msg() . "\n");
        $failed++;
        continue;
    }

    echo $path . ": valid\n";
}

exit($failed === 0 ? 0 : 1);
