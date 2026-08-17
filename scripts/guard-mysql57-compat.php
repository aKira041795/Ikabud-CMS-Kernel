<?php

/**
 * Guard: MySQL 5.7 / MariaDB <10.2 compatibility scan.
 *
 * Bluehost shared hosting runs MySQL 5.7 (the Compatibility database profile).
 * Several SQL constructs are syntax errors there and must never be introduced
 * without a profile gate:
 *
 *   - Window functions:  COUNT(*) OVER(), ROW_NUMBER() OVER(), RANK(), LAG(), ...
 *   - Common Table Expressions:  WITH ... AS (...)
 *   - JSON_TABLE()
 *   - EXCEPT / INTERSECT set operators
 *   - CHECK constraints (enforced only in 8.0.16+)
 *   - FOR UPDATE SKIP LOCKED (added MySQL 8.0.1 / MariaDB 10.6) — must go
 *     through kernelDbVersionSupportsSkipLocked() / kernelDbSupportsSkipLocked()
 *     with a plain `FOR UPDATE` fallback, never used unconditionally.
 *
 * Usage:  php scripts/guard-mysql57-compat.php
 * Exit:   0 = clean, 1 = forbidden constructs found (paths printed).
 *
 * The SKIP LOCKED check allows the single guarded helper definition site in
 * bootstrap.php (the gate) and any use gated by kernelDbSupportsSkipLocked(),
 * but flags unconditional `FOR UPDATE SKIP LOCKED` usage in SQL strings.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = [$root . '/kernel', $root . '/src', $root . '/modules', $root . '/bootstrap.php'];

$failures = [];

// Patterns that are always forbidden on MySQL 5.7, in order of precedence so a
// single line matching several rules is reported once per rule.
$rules = [
    'window-function'      => '/\b(?:ROW_NUMBER|RANK|DENSE_RANK|LAG|LEAD|NTILE|CUME_DIST|PERCENT_RANK|FIRST_VALUE|LAST_VALUE|NTH_VALUE)\s*\(\s*\)?\s*OVER\s*\(/i',
    'window-over-count'    => '/\bCOUNT\s*\(\s*\*\s*\)\s*OVER\s*\(/i',
    'cte'                  => '/\bWITH\s+[A-Za-z_][A-Za-z0-9_]*\s+AS\s*\(/i',
    'json-table'           => '/\bJSON_TABLE\s*\(/i',
    'except-set-op'        => '/\bEXCEPT\s+(?:ALL\s+)?SELECT\b/i',
    'intersect-set-op'     => '/\bINTERSECT\s+(?:ALL\s+)?SELECT\b/i',
    'check-constraint'     => '/(?:CREATE\s+TABLE|ALTER\s+TABLE).*(?:CONSTRAINT[^,]*)?\bCHECK\s*\(/is',
    'skip-locked-bare'     => '/FOR\s+UPDATE\s+SKIP\s+LOCKED/i',
];

// Allowed SKIP LOCKED sites: the version-gate definitions in bootstrap.php and
// any file that routes through kernelDbSupportsSkipLocked(). We still flag
// other unconditional occurrences.
$skipLockedAllowedFiles = [
    'bootstrap.php',
    'kernel/Services/PushWorker.php',
];

function guardM57ScanFile(string $path, array $rules, array $skipLockedAllowedFiles, array &$failures): void
{
    if (!is_file($path)) {
        return;
    }
    $rel = ltrim(str_replace(dirname(__DIR__), '', $path), '/');
    $lines = @file($path);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $i => $line) {
        $ln = $i + 1;
        foreach ($rules as $ruleName => $pattern) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }
            if ($ruleName === 'skip-locked-bare') {
                // Allow the guarded definition site(s); flag everywhere else.
                foreach ($skipLockedAllowedFiles as $allowed) {
                    if (str_contains($rel, $allowed)) {
                        continue 2;
                    }
                }
            }
            $failures[] = sprintf(
                "%s:%d  [%s]  %s",
                $rel,
                $ln,
                $ruleName,
                trim($line)
            );
        }
    }
}

function guardM57ScanDir(string $dir, array $rules, array $skipLockedAllowedFiles, array &$failures): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            guardM57ScanFile($file->getPathname(), $rules, $skipLockedAllowedFiles, $failures);
        }
    }
}

foreach ($dirs as $dir) {
    if (is_file($dir)) {
        guardM57ScanFile($dir, $rules, $skipLockedAllowedFiles, $failures);
    } else {
        guardM57ScanDir($dir, $rules, $skipLockedAllowedFiles, $failures);
    }
}

if ($failures === []) {
    echo "✓ MySQL 5.7 compatibility scan clean\n";
    exit(0);
}

echo "✗ MySQL 5.7 forbidden SQL constructs found:\n";
foreach ($failures as $f) {
    echo "  {$f}\n";
}
exit(1);
