<?php

/**
 * Verifies that the test runner's per-test timeout terminates a slow test.
 *
 * This test deliberately sleeps longer than the default timeout. The runner
 * (scripts/run-tests.php) must terminate it and report TIMEOUT.
 *
 * Run via the runner, NOT directly:
 *   TEST_TIMEOUT=2 php scripts/run-tests.php --dir=tests
 */

declare(strict_types=1);

// Only sleep when running as a standalone test (not via runner sub-process).
// The runner will kill us, so we use a short sleep that exceeds TEST_TIMEOUT.
// Default TEST_TIMEOUT is 120s — we sleep 3s and expect the env to be set to 2s
// in CI for this test.
$timeout = (int) ($_ENV['TEST_TIMEOUT'] ?? $_SERVER['TEST_TIMEOUT'] ?? 120);

// If timeout is too high, this test is running without the runner's timeout
// context. Skip rather than hang.
if ($timeout > 10) {
    echo "SKIP: TEST_TIMEOUT not set low enough for timeout test (got {$timeout}s, need <=10s)\n";
    echo "Assertions: 0\n";
    exit(0);
}

// Sleep past the timeout — runner should kill us
sleep($timeout + 5);

// If we reach here, the runner did NOT terminate us — test fails
echo "FAIL: Runner did not terminate after timeout ({$timeout}s)\n";
echo "Assertions: 0\n";
exit(1);
