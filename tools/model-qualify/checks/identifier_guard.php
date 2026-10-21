<?php
/**
 * Executable check for the `edit-php-identifier-guard` qualification task.
 *
 * The model's answer is written to a temp file and required here, so the verdict is
 * decided by behavior, not by string matching. Exit 0 = every case behaved.
 */

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "check: missing candidate file\n");
    exit(2);
}

require $file;

if (!function_exists('validateIdentifier')) {
    fwrite(STDERR, "check: validateIdentifier() was not defined\n");
    exit(1);
}

$cases = [
    ['user_name',   true,  'ordinary identifier'],
    ['a',           true,  'single character'],
    ['A1',          true,  'letter then digit'],
    ['_x',          true,  'leading underscore'],
    [str_repeat('a', 64), true,  'exactly 64 characters'],
    ['',            false, 'empty string'],
    ['1abc',        false, 'leading digit'],
    ['a`b',         false, 'backtick'],
    ['a b',         false, 'space'],
    ['a;b',         false, 'semicolon'],
    ['a-b',         false, 'hyphen'],
    ['a.b',         false, 'dot (qualified name is not a single identifier)'],
    [str_repeat('a', 65), false, '65 characters (over the limit)'],
];

$failures = [];
foreach ($cases as [$input, $expected, $label]) {
    try {
        $actual = validateIdentifier($input);
    } catch (\Throwable $e) {
        $failures[] = sprintf('%-42s threw %s: %s', $label, get_class($e), $e->getMessage());
        continue;
    }
    if ($actual !== $expected) {
        $failures[] = sprintf(
            '%-42s expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

if ($failures === []) {
    echo "check: all " . count($cases) . " cases behaved\n";
    exit(0);
}

foreach ($failures as $line) {
    fwrite(STDERR, "check: FAIL {$line}\n");
}
exit(1);
