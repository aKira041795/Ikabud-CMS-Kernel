<?php

/**
 * Verifies that the test runner discovers tests in nested directories.
 *
 * This test lives in tests/runner/ (a subdirectory) to confirm that
 * recursive glob discovery works.
 */

declare(strict_types=1);

echo "PASS: Recursive discovery works — test found in nested directory.\n";
echo "Assertions: 1\n";
exit(0);
