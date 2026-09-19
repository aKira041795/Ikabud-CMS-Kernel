<?php

declare(strict_types=1);

/**
 * Which tenant host does each test suite actually address?
 *
 * A host no tenant claims is not an error to the kernel — resolution falls through to the
 * kernel database — so a suite aimed at one reads the wrong database and can still report
 * success. This separates the suite host (which decides the database) from host strings that
 * merely appear inside a test for some other reason.
 */

require __DIR__ . '/../bootstrap.php';

$db = app()->db();
$domains = array_map('strtolower', $db->query('SELECT domain FROM kernel_tenant_domains')->fetchAll(PDO::FETCH_COLUMN));

$suiteHosts = [];
$rawOccurrences = [];
$files = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../tests', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $path) {
    $src = (string) file_get_contents($path);

    // Host-like strings anywhere in the file.
    if (preg_match_all('/["\']([a-z0-9][a-z0-9.\-]*\.(?:test|localhost|local))["\']/i', $src, $m)) {
        foreach ($m[1] as $host) {
            $rawOccurrences[strtolower($host)] = ($rawOccurrences[strtolower($host)] ?? 0) + 1;
        }
    }

    // The host is whichever quoted argument looks like a host. Not "the third argument":
    // the mode is unquoted (TestHarness::MODE_INTEGRATION), so the host is the last quoted
    // string in most calls — and relying on position instead caught file paths and prose.
    $chunks = preg_split('/new\s+TestHarness\s*\(/', $src);
    array_shift($chunks);
    foreach ($chunks as $chunk) {
        $head = substr($chunk, 0, 300);
        if (preg_match_all('/[\'"]([^\'"]*)[\'"]/', $head, $args)) {
            foreach ($args[1] as $arg) {
                if (preg_match('/^[a-z0-9][a-z0-9.\-]*\.(?:test|localhost|local)$/i', $arg)) {
                    $suiteHosts[strtolower($arg)][] = basename($path);
                    break;
                }
            }
        }
    }
}

echo 'test files scanned: ' . count($files) . "\n";

echo "\n=== A. SUITE HOSTS — the host that decides which database the suite reads ===\n";
ksort($suiteHosts);
$badSuites = 0;
foreach ($suiteHosts as $host => $suiteFiles) {
    $ok = in_array($host, $domains, true);
    if (!$ok) {
        $badSuites += count($suiteFiles);
    }
    printf("  %-26s suites=%-3d resolves=%s\n", $host, count($suiteFiles), $ok ? 'YES' : 'NO  <-- wrong database');
}
printf("  --- %d suite(s) address a host that belongs to no tenant\n", $badSuites);
echo "  names of those: " . implode(', ', array_filter(
    array_map(static fn($h, $fs) => in_array($h, $domains, true) ? null : $h, array_keys($suiteHosts), $suiteHosts)
)) . "\n";

echo "\n=== B. host strings that merely appear in a test, by host ===\n";
arsort($rawOccurrences);
foreach ($rawOccurrences as $host => $n) {
    printf("  %-28s %-4d resolves=%s\n", $host, $n, in_array($host, $domains, true) ? 'YES' : 'no');
}
