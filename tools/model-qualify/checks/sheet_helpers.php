<?php
/**
 * Executable check for the `repair-sheet-helpers` qualification task.
 *
 * Verdict is decided by behavior across all three repaired functions, not by string matching.
 * Every case below is derived from the SPECIFICATION in the task prompt.
 */

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "check: missing candidate file\n");
    exit(2);
}

require $file;

foreach (['slugify', 'paginate', 'formatMoney'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "check: {$fn}() was not defined\n");
        exit(1);
    }
}

$failures = [];

$expect = static function (string $label, $expected, callable $actual) use (&$failures): void {
    try {
        $got = $actual();
    } catch (\Throwable $e) {
        $failures[] = sprintf('%s threw %s: %s', $label, get_class($e), $e->getMessage());
        return;
    }
    if ($got !== $expected) {
        $failures[] = sprintf(
            '%s expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($got, true)
        );
    }
};

// slugify ---------------------------------------------------------------
$expect('slugify("Hello,  World! ")', 'hello-world', static fn() => slugify('Hello,  World! '));
$expect('slugify("--Edge--")', 'edge', static fn() => slugify('--Edge--'));
$expect('slugify("A_B")', 'a-b', static fn() => slugify('A_B'));
$expect('slugify("already-slugged")', 'already-slugged', static fn() => slugify('already-slugged'));
$expect('slugify("   ")', '', static fn() => slugify('   '));
$expect('slugify("One2Three")', 'one2three', static fn() => slugify('One2Three'));

// paginate --------------------------------------------------------------
$expect('paginate(101,10,1) pages', 11, static fn() => paginate(101, 10, 1)['pages']);
$expect('paginate(101,10,1) offset', 0, static fn() => paginate(101, 10, 1)['offset']);
$expect('paginate(101,10,3) offset', 20, static fn() => paginate(101, 10, 3)['offset']);
$expect('paginate(101,10,3) limit', 10, static fn() => paginate(101, 10, 3)['limit']);
$expect('paginate(101,10,0) offset', 0, static fn() => paginate(101, 10, 0)['offset']);
$expect('paginate(101,10,-4) offset', 0, static fn() => paginate(101, 10, -4)['offset']);
$expect('paginate(20,7,3) pages', 3, static fn() => paginate(20, 7, 3)['pages']);
$expect('paginate(0,10,1) pages', 0, static fn() => paginate(0, 10, 1)['pages']);
$expect('paginate(20,0,1) pages', 0, static fn() => paginate(20, 0, 1)['pages']);

// formatMoney -----------------------------------------------------------
$expect('formatMoney(1234.5)', 'PHP 1,234.50', static fn() => formatMoney(1234.5));
$expect('formatMoney(0,"USD")', 'USD 0.00', static fn() => formatMoney(0, 'USD'));
$expect('formatMoney(1234567.891)', 'PHP 1,234,567.89', static fn() => formatMoney(1234567.891));

if ($failures === []) {
    echo "check: all 18 cases behaved\n";
    exit(0);
}

foreach ($failures as $line) {
    fwrite(STDERR, "check: FAIL {$line}\n");
}
exit(1);
