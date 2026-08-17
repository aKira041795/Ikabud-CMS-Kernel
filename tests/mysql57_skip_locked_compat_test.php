<?php
/**
 * Regression test — MySQL 5.7 `FOR UPDATE SKIP LOCKED` compatibility gate.
 *
 * CI caught platform_tier1_operational_test failing ONLY on mysql-5.7 because
 * `SELECT ... FOR UPDATE SKIP LOCKED` is a syntax error there (SKIP LOCKED was
 * added in MySQL 8.0.1 / MariaDB 10.6). kernelProcessNextJob and PushWorker
 * fall back to plain `FOR UPDATE` when the server does not support the clause.
 *
 * This guards the version-parsing gate so the fallback cannot silently regress:
 * - MySQL 5.7 / MariaDB <10.6  → false (must fall back to plain FOR UPDATE)
 * - MySQL 8.0.1+ / MariaDB 10.6+ → true (may use SKIP LOCKED)
 *
 * Run: php tests/mysql57_skip_locked_compat_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function m57_t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $msg = "  ✗ {$label}";
        if ($detail !== '') {
            $msg .= " — {$detail}";
        }
        echo $msg . "\n";
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
}

echo "=== MySQL / MariaDB SKIP LOCKED version gate ===\n";

m57_t('helper function exists', function_exists('kernelDbVersionSupportsSkipLocked'));
m57_t('DB helper exists', function_exists('kernelDbSupportsSkipLocked'));

echo "\n--- MySQL versions ---\n";
m57_t('MySQL 5.5 is NOT supported', kernelDbVersionSupportsSkipLocked('5.5.62') === false);
m57_t('MySQL 5.6 is NOT supported', kernelDbVersionSupportsSkipLocked('5.6.51') === false);
m57_t('MySQL 5.7 is NOT supported (production target)', kernelDbVersionSupportsSkipLocked('5.7.44') === false);
m57_t('MySQL 8.0.0 is NOT supported (SKIP LOCKED unusable)', kernelDbVersionSupportsSkipLocked('8.0.0') === false);
m57_t('MySQL 8.0.1 IS supported', kernelDbVersionSupportsSkipLocked('8.0.1') === true);
m57_t('MySQL 8.0.36 IS supported', kernelDbVersionSupportsSkipLocked('8.0.36') === true);
m57_t('MySQL 8.4 IS supported', kernelDbVersionSupportsSkipLocked('8.4.0') === true);

echo "\n--- MariaDB versions ---\n";
m57_t('MariaDB 10.3 is NOT supported', kernelDbVersionSupportsSkipLocked('10.3.39-MariaDB') === false);
m57_t('MariaDB 10.5 is NOT supported', kernelDbVersionSupportsSkipLocked('10.5.24-MariaDB') === false);
m57_t('MariaDB 10.6 IS supported', kernelDbVersionSupportsSkipLocked('10.6.18-MariaDB') === true);
m57_t('MariaDB 10.11 IS supported', kernelDbVersionSupportsSkipLocked('10.11.8-MariaDB') === true);
m57_t('MariaDB 11.x IS supported', kernelDbVersionSupportsSkipLocked('11.4.3-MariaDB') === true);

echo "\n--- Edge cases ---\n";
m57_t('empty version is NOT supported', kernelDbVersionSupportsSkipLocked('') === false);
m57_t('whitespace version is NOT supported', kernelDbVersionSupportsSkipLocked('   ') === false);

echo "\n════════════════════════════════════════════\n";
echo '  MySQL 5.7 SKIP LOCKED compat tests: ' . $pass . ' passed, ' . $fail . ' failed' . "\n";
echo "════════════════════════════════════════════\n";

exit($fail > 0 ? 1 : 0);
