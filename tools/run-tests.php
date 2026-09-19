<?php

declare(strict_types=1);

/**
 * Run every test file as its own process, and classify what happened.
 *
 * Deliberately not built on TestHarness: 486 of the 513 test files predate it, and rewriting
 * them is not the fix — the fix is to measure them. A test that dies mid-run can exit 0 while
 * printing the kernel's HTML error page, and from the outside that is indistinguishable from a
 * pass. That is how DcCafeHttpTest reported green while reading orphaned tables, and how
 * guidance_notification_runtime_test still does.
 *
 * So each file is run as a subprocess and judged on four things: the exit code, whether it
 * printed a fatal or the error page, whether it printed anything at all, and whether it
 * finished in time.
 *
 * Usage:
 *   php tools/run-tests.php                       # everything (slow: ~500 processes)
 *   php tools/run-tests.php --filter=dc-cafe      # only matching paths
 *   php tools/run-tests.php --limit=20 --json     # first 20, machine readable
 *   php tools/run-tests.php --timeout=180
 */

$options = ['filter' => '', 'limit' => 0, 'json' => false, 'timeout' => 120, 'quiet' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $key = $m[1];
        $value = $m[2] ?? '1';
        if ($key === 'json' || $key === 'quiet') {
            $options[$key] = true;
        } elseif ($key === 'limit' || $key === 'timeout') {
            $options[$key] = (int) $value;
        } elseif ($key === 'filter') {
            $options[$key] = (string) $value;
        }
    }
}

$root = dirname(__DIR__);
$testsDir = $root . '/tests';

/** A test file is a runnable suite: *_test.php or *Test.php. The rest are fixtures and helpers. */
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $name = $file->getFilename();
    if (!str_ends_with($name, '_test.php') && !str_ends_with($name, 'Test.php')) {
        continue;   // fixtures, bootstraps, helpers — not suites
    }
    $path = $file->getPathname();
    if ($options['filter'] !== '' && !str_contains($path, (string) $options['filter'])) {
        continue;
    }
    $files[] = $path;
}

sort($files);
if ($options['limit'] > 0) {
    $files = array_slice($files, 0, $options['limit']);
}

/**
 * Signals that a test died rather than finished. The kernel renders an HTML error page and
 * exits cleanly, so the exit code alone cannot tell the difference.
 */
const FATAL_MARKERS = [
    'Fatal error',
    'Parse error',
    'Uncaught',
    'Something went wrong',
    'Allowed memory size',
    'Maximum execution time',
];

$results = [];
$startedAll = microtime(true);

foreach ($files as $path) {
    $relative = ltrim(str_replace($root, '', $path), '/');
    $timeout = max(5, (int) $options['timeout']);
    $command = 'timeout ' . $timeout . ' php ' . escapeshellarg($path) . ' 2>&1';

    $output = [];
    $exit = 0;
    $t0 = microtime(true);
    exec($command, $output, $exit);
    $seconds = round(microtime(true) - $t0, 1);

    $text = implode("\n", $output);
    $marker = '';
    foreach (FATAL_MARKERS as $candidate) {
        if (stripos($text, $candidate) !== false) {
            $marker = $candidate;
            break;
        }
    }

    // 124 is what `timeout` returns when it kills the process.
    if ($exit === 124) {
        $status = 'TIMEOUT';
    } elseif ($exit !== 0) {
        $status = 'FAIL';
    } elseif ($marker !== '') {
        // Exited 0 having printed a fatal or the error page: it died and said it succeeded.
        $status = 'ABORTED';
    } elseif (trim($text) === '') {
        // Finished cleanly having proved nothing. Worth seeing rather than counting as green.
        $status = 'NO-OUTPUT';
    } else {
        $status = 'PASS';
    }

    $results[] = [
        'file' => $relative,
        'status' => $status,
        'exit' => $exit,
        'seconds' => $seconds,
        'marker' => $marker,
        'tail' => $marker !== '' ? trim(mb_substr($text, -300)) : '',
    ];

    if (!$options['quiet']) {
        $bad = in_array($status, ['FAIL', 'ABORTED', 'TIMEOUT'], true);
        printf("  %-11s %-58s %5.1fs\n", $status, mb_substr($relative, 0, 58), $seconds);
        if ($bad && $marker !== '') {
            printf("              → %s\n", $marker);
        }
    }
}

$counts = array_count_values(array_column($results, 'status'));
ksort($counts);

if ($options['json']) {
    echo json_encode(['counts' => $counts, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $elapsed = round(microtime(true) - $startedAll, 1);
    echo "\n══════════════════════════════════════\n";
    echo '  ' . count($results) . " test file(s) in {$elapsed}s\n\n";
    foreach (['PASS', 'NO-OUTPUT', 'ABORTED', 'FAIL', 'TIMEOUT'] as $status) {
        if (isset($counts[$status])) {
            printf("  %-11s %d\n", $status, $counts[$status]);
        }
    }

    $bad = array_filter($results, static fn(array $r): bool => in_array($r['status'], ['FAIL', 'ABORTED', 'TIMEOUT'], true));
    if ($bad !== []) {
        echo "\n  Not passing:\n";
        foreach ($bad as $r) {
            printf("    %-10s %s\n", $r['status'], $r['file']);
        }
    }

    $silent = array_filter($results, static fn(array $r): bool => $r['status'] === 'NO-OUTPUT');
    if ($silent !== []) {
        echo "\n  Finished without printing anything — no evidence either way:\n";
        foreach (array_slice($silent, 0, 10) as $r) {
            printf("    %s\n", $r['file']);
        }
        if (count($silent) > 10) {
            printf("    … and %d more\n", count($silent) - 10);
        }
    }
    echo "══════════════════════════════════════\n";
}

exit($bad === [] && $silent === [] ? 0 : 1);
