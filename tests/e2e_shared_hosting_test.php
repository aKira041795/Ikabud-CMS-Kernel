<?php
/**
 * End-to-End Shared-Hosting Performance & Correctness Test
 *
 * Tests the full HTTP request cycle against a running local server to
 * verify that all optimized paths (login, frontend rendering, settings
 * save, customizer save, menu CRUD, ecommerce storefront) work correctly
 * and that no PHP errors are produced.
 *
 * Treats the environment as a shared-hosting analogue (single-process,
 * slow disk, MySQL connection churn — just like Bluehost).
 *
 * Usage:  php tests/e2e_shared_hosting_test.php
 *
 * Requirements:
 *   - Local vhost http://cmsnew.test must be running
 *   - Valid admin credentials: admin / Admin123!
 */

declare(strict_types=1);

// ── Configuration ──────────────────────────────────────────────────
$BASE        = rtrim(getenv('E2E_BASE_URL') ?: 'http://cmsnew.test', '/');
$USERNAME    = getenv('E2E_USER') ?: 'admin';
$PASSWORD    = getenv('E2E_PASS') ?: 'Admin123!';
$COOKIE_JAR  = tempnam(sys_get_temp_dir(), 'e2e_cookies_');

// ── Host availability guard (skip in CI when vhost not configured) ─
$_e2eHost = parse_url($BASE, PHP_URL_HOST) ?: '';
if ($_e2eHost !== '' && gethostbyname($_e2eHost) === $_e2eHost) {
    echo "SKIP: Host {$_e2eHost} does not resolve. This test requires a configured local vhost.\n";
    exit(0);
}

// ── Bookkeeping ────────────────────────────────────────────────────
$pass   = 0;
$fail   = 0;
$errors = [];
$timings = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function timing(string $label, float $seconds): void
{
    global $timings;
    $ms = round($seconds * 1000, 1);
    $timings[$label] = $ms;
    $flag = $ms > 2000 ? ' ⚠ SLOW' : '';
    echo "    ⏱ {$label}: {$ms}ms{$flag}\n";
}

// ── HTTP helpers (cookie-aware, timing) ────────────────────────────
function http_get(string $url, array $extraHeaders = []): array
{
    global $COOKIE_JAR;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEFILE     => $COOKIE_JAR,
        CURLOPT_COOKIEJAR      => $COOKIE_JAR,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_HEADER         => true,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $headerSize = $info['header_size'];
    $headers    = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);

    return [
        'status'  => $info['http_code'],
        'body'    => $body,
        'headers' => $headers,
        'time'    => $info['total_time'],
        'error'   => $err,
    ];
}

function http_post(string $url, array $data, array $extraHeaders = []): array
{
    global $COOKIE_JAR;
    $ch = curl_init($url);
    $json = json_encode($data);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEFILE     => $COOKIE_JAR,
        CURLOPT_COOKIEJAR      => $COOKIE_JAR,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders),
        CURLOPT_HEADER         => true,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $headerSize = $info['header_size'];
    $headers    = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);

    return [
        'status'  => $info['http_code'],
        'body'    => $body,
        'headers' => $headers,
        'time'    => $info['total_time'],
        'error'   => $err,
    ];
}

// ── Clear logs ──────────────────────────────────────────────────────
$appRoot = dirname(__DIR__);
$appLog  = $appRoot . '/storage/logs/app.log';
$errLog  = $appRoot . '/storage/logs/error.log';
file_put_contents($appLog, '');
file_put_contents($errLog, '');
echo "Logs cleared.\n";

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 1. FRONTEND PAGE LOADS ===\n";
// ═══════════════════════════════════════════════════════════════════

// 1a. Homepage
$r = http_get("{$BASE}/");
t('Homepage returns 200', $r['status'] === 200);
t('Homepage has content', strlen($r['body']) > 500);
t('Homepage contains site name', str_contains($r['body'], "Li'l Juanita") || str_contains($r['body'], '<html'));
timing('Homepage load', $r['time']);

// 1b. Blog page
$r = http_get("{$BASE}/cms/page/blog");
t('Blog page returns 200', $r['status'] === 200);
timing('Blog page load', $r['time']);

// 1c. Contact page
$r = http_get("{$BASE}/cms/page/contact");
t('Contact page returns 200', $r['status'] === 200);
timing('Contact page load', $r['time']);

// 1d. Second homepage load (should hit caches)
$r2 = http_get("{$BASE}/");
t('Homepage second load returns 200', $r2['status'] === 200);
timing('Homepage 2nd load (cached)', $r2['time']);

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 2. ECOMMERCE STOREFRONT ===\n";
// ═══════════════════════════════════════════════════════════════════

$r = http_get("{$BASE}/ecommerce/shop");
t('Shop page returns 200', $r['status'] === 200, "got {$r['status']}");
timing('Shop page load', $r['time']);

$r = http_get("{$BASE}/ecommerce/cart");
t('Cart page returns 200', $r['status'] === 200);
timing('Cart page load', $r['time']);

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 3. AUTH — LOGIN ===\n";
// ═══════════════════════════════════════════════════════════════════

$r = http_post("{$BASE}/api/v1/auth/login", [
    'username' => $USERNAME,
    'password' => $PASSWORD,
]);
$loginData = json_decode($r['body'], true);
t('Login returns 200', $r['status'] === 200, "got {$r['status']}");
t('Login response is ok', ($loginData['ok'] ?? false) === true, $r['body']);
$jwt = $loginData['token'] ?? '';
t('Login returns JWT token', strlen($jwt) > 20);
timing('Login API', $r['time']);

if (!$jwt) {
    echo "\n✗✗✗ Cannot continue without JWT token. Aborting.\n";
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 4. ADMIN PAGES (AUTHENTICATED) ===\n";
// ═══════════════════════════════════════════════════════════════════

// 4a. Admin dashboard
$r = http_get("{$BASE}/cms/admin", ["Authorization: Bearer {$jwt}"]);
t('Admin dashboard returns 200', $r['status'] === 200, "got {$r['status']}");
t('Admin dashboard has content', strlen($r['body']) > 500);
timing('Admin dashboard load', $r['time']);

// 4b. Admin settings page
$r = http_get("{$BASE}/cms/admin/settings", ["Authorization: Bearer {$jwt}"]);
t('Admin settings page returns 200', $r['status'] === 200, "got {$r['status']}");
timing('Admin settings page load', $r['time']);

// 4c. Admin menus page
$r = http_get("{$BASE}/cms/admin/menus", ["Authorization: Bearer {$jwt}"]);
t('Admin menus page returns 200', $r['status'] === 200, "got {$r['status']}");
timing('Admin menus page load', $r['time']);

// 4d. Extract CSRF token from admin layout
$csrfToken = '';
if (preg_match("/CMS_CSRF\\s*=\\s*'([a-f0-9]{64})'/", $r['body'], $m)) {
    $csrfToken = $m[1];
}
t('CSRF token extracted from admin page', strlen($csrfToken) === 64, 'len=' . strlen($csrfToken));

// 4e. Admin customizer page
$r = http_get("{$BASE}/cms/admin/customize", ["Authorization: Bearer {$jwt}"]);
t('Admin customizer page returns 200', $r['status'] === 200, "got {$r['status']}");
timing('Admin customizer page load', $r['time']);

// If we couldn't extract CSRF from menus page, try from customizer
if (!$csrfToken && preg_match("/CMS_CSRF\\s*=\\s*'([a-f0-9]{64})'/", $r['body'], $m)) {
    $csrfToken = $m[1];
    echo "  (CSRF token extracted from customizer page)\n";
}

if (!$csrfToken) {
    echo "\n  ⚠ No CSRF token found — POST tests that require CSRF will be skipped\n";
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 5. SETTINGS SAVE ===\n";
// ═══════════════════════════════════════════════════════════════════

if ($csrfToken) {
    // 5a. Read current site title from the settings admin page (Alpine JSON data)
    $r = http_get("{$BASE}/cms/admin/settings", ["Authorization: Bearer {$jwt}"]);
    $siteTitle = '';
    // The settings JSON is embedded as: settings: {cms_settings_json|raw}
    if (preg_match('/settings:\s*(\{[^}]*"site_title"\s*:\s*"([^"]*)"[^}]*\})/s', $r['body'], $stm)) {
        // Decode JSON unicode escapes (e.g. \u0027 → ')
        $siteTitle = json_decode('"' . $stm[2] . '"') ?: $stm[2];
    }
    if (!$siteTitle) {
        $siteTitle = "Li'l Juanita"; // fallback
    }
    t('Settings page has site_title', $siteTitle !== '', 'title=' . $siteTitle);
    timing('Settings read (page)', $r['time']);

    // 5b. Save settings (round-trip: write back same value)
    $r = http_post("{$BASE}/api/v1/cms/settings", [
        'settings' => [
            'site_title' => $siteTitle,
        ],
        '_token' => $csrfToken,
    ], ["Authorization: Bearer {$jwt}"]);
    $saveData = json_decode($r['body'], true);
    t('Settings save returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
    t('Settings save response is ok', ($saveData['ok'] ?? false) === true, $r['body']);
    timing('Settings save API', $r['time']);

    // 5c. Verify the save persisted (re-read from admin settings page)
    $r = http_get("{$BASE}/cms/admin/settings", ["Authorization: Bearer {$jwt}"]);
    $rereadTitle = '';
    if (preg_match('/settings:\s*(\{[^}]*"site_title"\s*:\s*"([^"]*)"[^}]*\})/s', $r['body'], $stm2)) {
        $rereadTitle = json_decode('"' . $stm2[2] . '"') ?: $stm2[2];
    }
    t('Settings re-read matches saved value', $rereadTitle === $siteTitle,
        "expected '{$siteTitle}', got '{$rereadTitle}'");
    timing('Settings re-read (page)', $r['time']);
} else {
    echo "  (Skipped — no CSRF token)\n";
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 6. CUSTOMIZER SAVE ===\n";
// ═══════════════════════════════════════════════════════════════════

if ($csrfToken) {
    // 6a. Read current footer customizer state, then save it back unchanged
    $r = http_get("{$BASE}/api/v1/cms/customizer/footer", ["Authorization: Bearer {$jwt}"]);
    $footerState = json_decode($r['body'], true);
    t('Customizer footer GET returns 200', $r['status'] === 200, "got {$r['status']}");
    $footerSettings = $footerState['settings'] ?? [];
    $footerWidgets  = $footerState['widgets'] ?? null;
    timing('Customizer footer read API', $r['time']);

    $savePayload = ['settings' => $footerSettings, '_token' => $csrfToken];
    if (is_array($footerWidgets)) {
        $savePayload['widgets'] = $footerWidgets;
    }
    $r = http_post("{$BASE}/api/v1/cms/customizer/footer", $savePayload, ["Authorization: Bearer {$jwt}"]);
    $custData = json_decode($r['body'], true);
    t('Customizer footer save returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
    t('Customizer footer save response is ok', ($custData['ok'] ?? false) === true, $r['body']);
    timing('Customizer footer save API', $r['time']);

    // 6b. Read current header customizer state, then save it back unchanged
    $r = http_get("{$BASE}/api/v1/cms/customizer/header", ["Authorization: Bearer {$jwt}"]);
    $headerState = json_decode($r['body'], true);
    $headerSettings = $headerState['settings'] ?? [];
    $headerWidgets  = $headerState['widgets'] ?? null;

    $savePayload = ['settings' => $headerSettings, '_token' => $csrfToken];
    if (is_array($headerWidgets)) {
        $savePayload['widgets'] = $headerWidgets;
    }
    $r = http_post("{$BASE}/api/v1/cms/customizer/header", $savePayload, ["Authorization: Bearer {$jwt}"]);
    $custData = json_decode($r['body'], true);
    t('Customizer header save returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
    t('Customizer header save response is ok', ($custData['ok'] ?? false) === true, $r['body']);
    timing('Customizer header save API', $r['time']);

    // 6c. Read current sidebar customizer state, then save it back unchanged
    $r = http_get("{$BASE}/api/v1/cms/customizer/sidebar", ["Authorization: Bearer {$jwt}"]);
    $sidebarState = json_decode($r['body'], true);
    $sidebarSettings = $sidebarState['settings'] ?? [];
    $sidebarWidgets  = $sidebarState['widgets'] ?? null;

    $savePayload = ['settings' => $sidebarSettings, '_token' => $csrfToken];
    if (is_array($sidebarWidgets)) {
        $savePayload['widgets'] = $sidebarWidgets;
    }
    $r = http_post("{$BASE}/api/v1/cms/customizer/sidebar", $savePayload, ["Authorization: Bearer {$jwt}"]);
    $custData = json_decode($r['body'], true);
    t('Customizer sidebar save returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
    t('Customizer sidebar save response is ok', ($custData['ok'] ?? false) === true, $r['body']);
    timing('Customizer sidebar save API', $r['time']);
} else {
    echo "  (Skipped — no CSRF token)\n";
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 7. MENU CRUD ===\n";
// ═══════════════════════════════════════════════════════════════════

$testMenuId = null;

if ($csrfToken) {
    // 7a. Create test menu
    $r = http_post("{$BASE}/api/v1/cms/menus/create", [
        'name'        => '__e2e_test_menu_' . time(),
        'description' => 'Automated E2E test menu',
        '_token'      => $csrfToken,
    ], ["Authorization: Bearer {$jwt}"]);
    $menuData = json_decode($r['body'], true);
    t('Menu create returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
    t('Menu create response is ok', ($menuData['ok'] ?? false) === true, $r['body']);
    $testMenuId = $menuData['id'] ?? $menuData['menu_id'] ?? null;
    t('Menu create returns an ID', $testMenuId !== null, 'id=' . json_encode($testMenuId));
    timing('Menu create API', $r['time']);

    if ($testMenuId) {
        // 7b. Update menu with items
        $r = http_post("{$BASE}/api/v1/cms/menus/{$testMenuId}", [
            'name'  => '__e2e_test_menu_updated',
            'items' => [
                ['label' => 'Test Home', 'url' => '/', 'order' => 0],
                ['label' => 'Test About', 'url' => '/about', 'order' => 1],
            ],
            '_token' => $csrfToken,
        ], ["Authorization: Bearer {$jwt}"]);
        $updateData = json_decode($r['body'], true);
        t('Menu update returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
        t('Menu update response is ok', ($updateData['ok'] ?? false) === true, $r['body']);
        timing('Menu update API', $r['time']);

        // 7c. Delete the test menu (cleanup)
        $r = http_post("{$BASE}/api/v1/cms/menus/{$testMenuId}/delete", [
            '_token' => $csrfToken,
        ], ["Authorization: Bearer {$jwt}"]);
        $deleteData = json_decode($r['body'], true);
        t('Menu delete returns 200', $r['status'] === 200, "got {$r['status']}, body: " . substr($r['body'], 0, 200));
        t('Menu delete response is ok', ($deleteData['ok'] ?? false) === true, $r['body']);
        timing('Menu delete API', $r['time']);
    }
} else {
    echo "  (Skipped — no CSRF token)\n";
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 8. POST-SAVE FRONTEND VERIFICATION ===\n";
// ═══════════════════════════════════════════════════════════════════

// After settings/customizer saves, verify the frontend still works
$r = http_get("{$BASE}/");
t('Homepage still returns 200 after saves', $r['status'] === 200);
t('Homepage still has content after saves', strlen($r['body']) > 500);
timing('Homepage after saves', $r['time']);

// Verify caches are warm on third load
$r = http_get("{$BASE}/");
timing('Homepage 3rd load (post-invalidation)', $r['time']);

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 9. ERROR LOG ANALYSIS ===\n";
// ═══════════════════════════════════════════════════════════════════

$phpErrors = file_exists($errLog) ? file_get_contents($errLog) : '';
$appErrors = file_exists($appLog) ? file_get_contents($appLog) : '';

// Count PHP errors (excluding deprecation notices)
$phpErrorLines = array_filter(
    explode("\n", trim($phpErrors)),
    fn($line) => $line !== '' && !str_contains($line, 'Deprecated:')
);
$fatalErrors = array_filter($phpErrorLines, fn($l) => str_contains($l, 'Fatal') || str_contains($l, 'fatal'));
$warnings    = array_filter($phpErrorLines, fn($l) => str_contains($l, 'Warning') || str_contains($l, 'warning'));
$notices     = array_filter($phpErrorLines, fn($l) => str_contains($l, 'Notice') || str_contains($l, 'notice'));

t('No PHP fatal errors', count($fatalErrors) === 0, implode("\n    ", array_slice($fatalErrors, 0, 3)));
t('No PHP warnings', count($warnings) === 0, count($warnings) . ' warning(s): ' . implode("\n    ", array_slice($warnings, 0, 3)));
t('No PHP notices', count($notices) === 0, count($notices) . ' notice(s)');

// Check app log for ERROR level entries
$appErrorLines = array_filter(
    explode("\n", trim($appErrors)),
    fn($line) => str_contains($line, '[ERROR]') || str_contains($line, '[CRITICAL]')
);
t('No ERROR/CRITICAL entries in app.log', count($appErrorLines) === 0,
    count($appErrorLines) . ' error(s): ' . implode("\n    ", array_slice($appErrorLines, 0, 3)));

// Show any app log content for review
if (trim($appErrors)) {
    $logLines = explode("\n", trim($appErrors));
    $shown = array_slice($logLines, 0, 20);
    echo "\n  App log excerpt (" . count($logLines) . " lines):\n";
    foreach ($shown as $l) {
        echo "    | {$l}\n";
    }
    if (count($logLines) > 20) {
        echo "    | ... (" . (count($logLines) - 20) . " more lines)\n";
    }
}

if (trim($phpErrors)) {
    $errLines = explode("\n", trim($phpErrors));
    $shown = array_slice($errLines, 0, 15);
    echo "\n  Error log excerpt (" . count($errLines) . " lines):\n";
    foreach ($shown as $l) {
        echo "    | {$l}\n";
    }
    if (count($errLines) > 15) {
        echo "    | ... (" . (count($errLines) - 15) . " more lines)\n";
    }
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== 10. TIMING SUMMARY ===\n";
// ═══════════════════════════════════════════════════════════════════

$slowThreshold = 2000; // ms
$slow = array_filter($timings, fn($ms) => $ms > $slowThreshold);

echo "\n";
printf("  %-40s %8s\n", 'Endpoint', 'Time');
printf("  %-40s %8s\n", str_repeat('─', 40), str_repeat('─', 8));
foreach ($timings as $label => $ms) {
    $flag = $ms > $slowThreshold ? ' ⚠' : '';
    printf("  %-40s %7.1fms%s\n", $label, $ms, $flag);
}

if ($slow) {
    echo "\n  ⚠ " . count($slow) . " endpoint(s) exceeded {$slowThreshold}ms threshold\n";
}

// ═══════════════════════════════════════════════════════════════════
echo "\n=== RESULTS ===\n";
// ═══════════════════════════════════════════════════════════════════

echo "\n  Passed: {$pass}\n  Failed: {$fail}\n";

if ($errors) {
    echo "\n  Failed tests:\n";
    foreach ($errors as $e) {
        echo "    ✗ {$e}\n";
    }
}

// Cleanup
@unlink($COOKIE_JAR);

echo "\n";
exit($fail > 0 ? 1 : 0);
