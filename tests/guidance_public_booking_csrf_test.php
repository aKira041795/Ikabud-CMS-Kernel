<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/guidance/book';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';

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
        echo "  PASS {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function unexpectedAppLogLines(string $content): array
{
    return array_values(array_filter(explode("\n", $content), static function (string $line): bool {
        if (trim($line) === '') {
            return false;
        }

        return str_contains($line, '[error]') || str_contains($line, '[critical]');
    }));
}

function hasGuidanceTenantSchema(PDO $db): bool
{
    try {
        $users = $db->query("SHOW TABLES LIKE 'gm_users'");
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($users && $users->fetchColumn()) && (bool)($settings && $settings->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function resolveGuidanceTenant(): array
{
    $controlDb = app()->controlDb();
    $stmt = $controlDb->query(
        "SELECT t.id, COALESCE(d.domain, '') AS domain\n"
        . "FROM kernel_tenants t\n"
        . "LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id\n"
        . "WHERE t.status = 'active' AND t.entry_module_id = 'guidance'\n"
        . "ORDER BY t.id ASC"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $tenantId = (int)($row['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }

        $tenantDb = app()->dbForTenant($tenantId);
        if (!$tenantDb instanceof PDO || !hasGuidanceTenantSchema($tenantDb)) {
            continue;
        }

        return [
            'tenant_id' => $tenantId,
            'domain' => trim((string)($row['domain'] ?? '')),
        ];
    }

    return ['tenant_id' => 0, 'domain' => ''];
}

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
    exit(1);
}

loadModuleHelpers($guidance);
moduleWithContext('guidance', static function () use ($guidance): void {
    require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
});

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenant = resolveGuidanceTenant();
$tenantId = (int)($tenant['tenant_id'] ?? 0);
$tenantDomain = trim((string)($tenant['domain'] ?? ''));

if ($tenantId <= 0) {
    fwrite(STDERR, "No active Guidance tenant database with the required schema is available.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();
invalidateModuleContextCache('guidance');

if ($tenantDomain !== '') {
    $_SERVER['HTTP_HOST'] = $tenantDomain;
}

try {
    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidancePublicBooking();
    });
    $html = (string)ob_get_clean();

    $csrfMatch = preg_match("/var APP_CSRF_TOKEN = '([a-f0-9]{64})';/", $html, $matches) === 1;
    $csrfToken = $csrfMatch ? (string)($matches[1] ?? '') : '';
    $csrfInputs = preg_match_all('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $inputMatches);
    $inputValues = is_array($inputMatches[1] ?? null) ? $inputMatches[1] : [];

    t('public booking page exposes a CSRF token to JavaScript', $csrfMatch, $html);
    t('public booking page renders CSRF hidden inputs for both forms', $csrfInputs >= 2, 'count=' . (string)$csrfInputs);
    t(
        'public booking page keeps rendered CSRF input values aligned with the page token',
        $csrfToken !== '' && $inputValues !== [] && count(array_unique($inputValues)) === 1 && $inputValues[0] === $csrfToken,
        'js=' . $csrfToken . ' inputs=' . json_encode($inputValues, JSON_UNESCAPED_SLASHES)
    );
    t('public booking page sends CSRF header on booking submit', str_contains($html, "csrfFetch(APP_BASE + '/book/api/booking'"), 'booking submit missing csrfFetch');
    t('public booking page sends CSRF header on OTP verify', str_contains($html, "csrfFetch(APP_BASE + '/book/api/verify-otp'"), 'otp verify missing csrfFetch');
    t('public booking page includes CSRF token when resending OTP', str_contains($html, "formData.append('_token', APP_CSRF_TOKEN);"), 'otp resend missing _token append');

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('public booking page leaves app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('public booking page leaves error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
    invalidateModuleContextCache('guidance');
}

echo "\n==========================================\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "==========================================\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);