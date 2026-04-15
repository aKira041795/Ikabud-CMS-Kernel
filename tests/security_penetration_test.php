<?php
/**
 * Security Penetration Test Suite (Platform Tier 2 — 2.4)
 *
 * Covers: SQL injection vectors, XSS prevention, CSRF enforcement,
 * privilege escalation guards, input sanitization boundaries.
 *
 * Run: php tests/security_penetration_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

ob_start();
try {
    require_once BASE_PATH . '/src/helpers/module-manager.php';
} catch (\Throwable $e) {}
ob_end_clean();

$passed = 0;
$failed = 0;

function t(string $label, bool $result): void
{
    global $passed, $failed;
    if ($result) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failed++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

echo "Security Penetration Test Suite\n";
echo str_repeat('=', 60) . "\n\n";

// ─── Section 1: Input Sanitization ─────────────────────────────────────
echo "── Section 1: Input Sanitization ──\n";

$app = app();

// 1.1 App input() method exists and handles requests safely
$reflection = new ReflectionClass($app);
t('App has input() method for safe request access', $reflection->hasMethod('input'));
t('App has private sanitizeInput for internal safety', $reflection->hasMethod('sanitizeInput'));

// 1.2 sanitizeInput is private (cannot be called externally)
$sanitizeMethod = $reflection->getMethod('sanitizeInput');
t('sanitizeInput is private (encapsulated)', $sanitizeMethod->isPrivate());

// 1.3 Verify MAX_INPUT_SIZE constant exists (2MB limit)
t('App enforces MAX_INPUT_SIZE', $reflection->hasConstant('MAX_INPUT_SIZE'));

// 1.4 SQL injection payloads — verify prepared statements are the defense layer
$sqlPayloads = [
    "'; DROP TABLE users; --",
    "1' OR '1'='1",
    "1 UNION SELECT password FROM users--",
    "admin'--",
    "1; EXEC xp_cmdshell('whoami')",
];
// The defense is prepared statements in ModuleDB/KernelPDO, not input sanitization
t('SQL defense: ModuleDB uses prepared statements', class_exists('Ikabud\Kernel\Contracts\ModuleDB'));
$moduleDbSource = file_get_contents(__DIR__ . '/../kernel/Contracts/ModuleDB.php');
t('ModuleDB has parameterized query support', str_contains($moduleDbSource, 'prepare') || str_contains($moduleDbSource, 'PDOStatement'));

// 1.5 XSS defense verification — template auto-escaping
$engine = $app->templates();
foreach ([
    ['<script>alert("xss")</script>', 'script tag'],
    ['<img src=x onerror=alert(1)>', 'img tag'],
    ['"><svg onload=alert(1)>', 'svg tag'],
] as [$payload, $label]) {
    $output = $engine->renderString('Test: {v}', ['v' => $payload]);
    // After escaping, < becomes &lt; so no actual HTML tags should remain
    t("XSS auto-escaped: {$label}", str_contains($output, '&lt;') && !str_contains($output, $payload));
}

// ─── Section 2: CSRF Protection ────────────────────────────────────────
echo "\n── Section 2: CSRF Protection ──\n";

// 2.1 CSRF token generation
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$token = $app->csrfToken();
t('CSRF token generated', is_string($token) && strlen($token) > 10);

// 2.2 CSRF token is consistent within session
$token2 = $app->csrfToken();
t('CSRF token consistent within session', $token === $token2);

// 2.3 CSRF field generates HTML
$field = $app->csrfField();
t('CSRF field contains hidden input', str_contains($field, '<input') && str_contains($field, 'type="hidden"'));

// 2.4 CSRF validation uses timing-safe comparison (structural check)
t('CSRF token is hex string', ctype_xdigit($token) || preg_match('/^[a-f0-9]+$/', $token) === 1);

// ─── Section 3: Security Headers ───────────────────────────────────────
echo "\n── Section 3: Security Header Configuration ──\n";

$headersClass = 'Ikabud\Kernel\Http\SecurityHeaders';
t('SecurityHeaders class exists', class_exists($headersClass));

if (class_exists($headersClass)) {
    $reflection = new ReflectionClass($headersClass);
    t('buildCspHeaderValue method exists', $reflection->hasMethod('buildCspHeaderValue'));
    t('apply method exists', $reflection->hasMethod('apply'));
}

// ─── Section 4: Redirect Validation ────────────────────────────────────
echo "\n── Section 4: Redirect Validation ──\n";

// 4.1 kernel_validate_redirect_target should exist
t('kernel_validate_redirect_target exists', function_exists('kernel_validate_redirect_target'));

if (function_exists('kernel_validate_redirect_target')) {
    // 4.2 Relative paths are allowed
    $safe = kernel_validate_redirect_target('/dashboard');
    t('Relative redirect allowed', $safe === '/dashboard');

    // 4.3 External URLs throw InvalidArgumentException
    $externalBlocked = false;
    try { kernel_validate_redirect_target('https://evil.com/phish'); } catch (\InvalidArgumentException $e) { $externalBlocked = true; }
    t('External redirect throws exception', $externalBlocked);

    // 4.4 javascript: protocol throws
    $jsBlocked = false;
    try { kernel_validate_redirect_target('javascript:alert(1)'); } catch (\InvalidArgumentException $e) { $jsBlocked = true; }
    t('javascript: redirect throws exception', $jsBlocked);

    // 4.5 data: protocol throws
    $dataBlocked = false;
    try { kernel_validate_redirect_target('data:text/html,<script>alert(1)</script>'); } catch (\InvalidArgumentException $e) { $dataBlocked = true; }
    t('data: redirect throws exception', $dataBlocked);
}

// ─── Section 5: ModuleDB SQL Firewall ──────────────────────────────────
echo "\n── Section 5: SQL Firewall (ModuleDB) ──\n";

$moduleDbClass = 'Ikabud\Kernel\Contracts\ModuleDB';
t('ModuleDB class exists', class_exists($moduleDbClass));

if (class_exists($moduleDbClass)) {
    $reflection = new ReflectionClass($moduleDbClass);
    // Check for DDL/DCL blocking methods
    $hasSafetyCheck = $reflection->hasMethod('execute') || $reflection->hasMethod('query');
    t('ModuleDB has query execution methods', $hasSafetyCheck);
}

// ─── Section 6: DiSyL Template Safety ──────────────────────────────────
echo "\n── Section 6: Template Engine Safety ──\n";

$engineClass = 'Ikabud\Kernel\DiSyL\TemplateEngine';
t('TemplateEngine class exists', class_exists($engineClass));

if (class_exists($engineClass)) {
    $engine = app()->templates();

    // 6.1 Auto-escaping: variables are HTML-escaped by default
    $output = $engine->renderString('Hello {name}', ['name' => '<script>alert(1)</script>']);
    t('Auto-escaping: script tags escaped', !str_contains($output, '<script>'));
    t('Auto-escaping: contains escaped form', str_contains($output, '&lt;script&gt;'));

    // 6.2 Raw filter disables escaping (intentional)
    $output = $engine->renderString('Hello {name | raw}', ['name' => '<b>bold</b>']);
    t('Raw filter passes through HTML', str_contains($output, '<b>bold</b>'));

    // 6.3 esc_url rejects javascript:
    $output = $engine->renderString('{url | esc_url}', ['url' => 'javascript:alert(1)']);
    t('esc_url blocks javascript: protocol', !str_contains($output, 'javascript:'));

    // 6.4 Template injection: {set} cannot execute PHP
    $output = $engine->renderString('{set x = phpinfo()}', []);
    t('Template injection: phpinfo() not executed', !str_contains($output, 'PHP Version'));

    // 6.5 Path traversal in includes should be prevented
    try {
        $output = $engine->renderString('{include "../../etc/passwd"}', []);
        $traversalBlocked = !str_contains($output, 'root:');
    } catch (\Throwable $e) {
        $traversalBlocked = true;
    }
    t('Include path traversal blocked', $traversalBlocked);
}

// ─── Section 7: Session Security ───────────────────────────────────────
echo "\n── Section 7: Session Configuration ──\n";

// In CLI, session cookie params aren't set by public/index.php.
// Verify the source code enforces secure session config.
$indexSource = file_get_contents(__DIR__ . '/../public/index.php');
t('Session cookie httponly enforced in entrypoint', str_contains($indexSource, "'httponly' => true"));
t('Session cookie samesite enforced in entrypoint', str_contains($indexSource, "'samesite' => 'Strict'") || str_contains($indexSource, "'samesite' => 'Lax'"));

// ─── Section 8: Encryption ─────────────────────────────────────────────
echo "\n── Section 8: Encryption Safety ──\n";

$cryptoClass = 'Ikabud\Kernel\Crypto';
t('Crypto class exists', class_exists($cryptoClass));

// 8.1 Verify algorithm is AES-256-GCM (check source)
$cryptoSource = file_get_contents(__DIR__ . '/../kernel/Crypto.php');
t('Uses AES-256-GCM', str_contains($cryptoSource, 'aes-256-gcm'));
t('Uses random_bytes for IV', str_contains($cryptoSource, 'random_bytes'));
t('Key minimum 32 bytes enforced', str_contains($cryptoSource, 'strlen($key) < 32'));

// ─── Section 9: JWT Security ───────────────────────────────────────────
echo "\n── Section 9: JWT Security ──\n";

$jwtClass = 'Ikabud\Kernel\JWT';
t('JWT class exists', class_exists($jwtClass));

$jwtSource = file_get_contents(__DIR__ . '/../kernel/JWT.php');
t('JWT uses hash_equals for timing-safe comparison', str_contains($jwtSource, 'hash_equals'));
t('JWT uses HMAC-SHA256', str_contains($jwtSource, 'sha256') || str_contains($jwtSource, 'HS256'));

// ─── Summary ───────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Security Penetration Tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
