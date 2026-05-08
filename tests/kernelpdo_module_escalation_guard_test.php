<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== KERNEL PDO MODULE ESCALATION GUARD ===\n\n";

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

$fixturePath = BASE_PATH . '/modules/.kernelpdo_escalation_guard_fixture.php';
$fixtureCode = <<<'PHP'
<?php
function kernelPdoEscalationGuardFixtureAttempt(): void
{
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        app()->db()->query('SELECT id FROM kernel_integrations LIMIT 1');
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}
PHP;

file_put_contents($fixturePath, $fixtureCode);

try {
    require $fixturePath;

    $threw = false;
    $message = '';
    moduleWithContext('cms', static function () use (&$threw, &$message): void {
        try {
            kernelPdoEscalationGuardFixtureAttempt();
        } catch (Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }
    });

    t('direct module escalation attempt does not bypass ModuleDB', $threw && str_contains($message, 'undeclared table'), $message);

    $rateLimitState = moduleWithContext('cms', static function (): array {
        return kernelConsumeLoginRateLimit('kernelpdo-guard-fixture', 1, 60);
    });
    t('kernel-owned login rate-limit helper still works from module context', is_array($rateLimitState) && array_key_exists('limited', $rateLimitState), json_encode($rateLimitState, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents($appLogPath) ?: '';
    t('blocked direct module escalation is logged', str_contains($appLog, 'Blocked direct module DB escalation request'), $appLog);

    $errorLog = @file_get_contents($errorLogPath) ?: '';
    t('guard test does not emit PHP errors', trim($errorLog) === '', trim($errorLog));
} finally {
    @unlink($fixturePath);
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);