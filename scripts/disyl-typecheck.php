<?php

declare(strict_types=1);

/**
 * DiSyL 4.2 — Type-check CLI.
 *
 * Walks one or more .disyl templates and runs {@see TypeChecker} against any
 * `{types}` block they declare. Exits non-zero on diagnostics.
 *
 * Usage:
 *   php scripts/disyl-typecheck.php path/to/template.disyl [more.disyl ...]
 *   php scripts/disyl-typecheck.php --json path/to/dir
 *
 * Flags:
 *   --json   Emit machine-readable JSON (one object per template)
 *   --quiet  Suppress per-template "OK" lines
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\Types\TypeChecker;

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name

$json = false;
$quiet = false;
$paths = [];
foreach ($argv as $a) {
    if ($a === '--json') { $json = true; continue; }
    if ($a === '--quiet') { $quiet = true; continue; }
    if (str_starts_with($a, '--')) {
        fwrite(STDERR, "unknown flag: $a\n");
        exit(2);
    }
    $paths[] = $a;
}

if ($paths === []) {
    fwrite(STDERR, "usage: disyl-typecheck.php [--json] [--quiet] <file-or-dir>...\n");
    exit(2);
}

/** @var list<string> $files */
$files = [];
foreach ($paths as $p) {
    if (is_dir($p)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile() && str_ends_with($f->getFilename(), '.disyl')) {
                $files[] = $f->getPathname();
            }
        }
    } elseif (is_file($p)) {
        $files[] = $p;
    } else {
        fwrite(STDERR, "not found: $p\n");
        exit(2);
    }
}

$totalErrors = 0;
$results = [];

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $diags = (new TypeChecker())->check($src, $file);
    $totalErrors += count($diags);
    $results[] = ['file' => $file, 'diagnostics' => $diags];

    if ($json) continue;
    if ($diags === []) {
        if (!$quiet) echo "OK   $file\n";
        continue;
    }
    foreach ($diags as $d) {
        echo "ERR  {$d['template']}:{$d['line']}  [{$d['code']}] {$d['message']}\n";
    }
}

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

exit($totalErrors === 0 ? 0 : 1);
