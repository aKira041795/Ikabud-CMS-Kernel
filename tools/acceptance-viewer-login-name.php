<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the login Full Name is OPTIONAL and never rewrites a personal account's
 * session identity (viewer surface W9 / finding F12; chair ruling D6).
 *
 * F12 (verified 2026-10-12): the login marks Full Name `required` for EVERY account, and for a
 * personal account whose profile overwrite is refused, the typed value still becomes the session
 * (and therefore audit) actor name.
 * D6 minimum: not required for personal accounts; the typed value must NEVER replace the
 * authenticated session identity; the shared-cashier name-capture workflow must keep working.
 *
 * Method: probe accounts only - seeded here with high ids and REMOVED in finally (never the live
 * accounts shiela_baina / admin_view), then real HTTP logins against http://baronledger.test.
 * Pattern copied from tests/daily-ledger/daily_ledger_shared_account_latest_holder_test.php.
 *
 * RED-now: N1 (the field is required in the form) and N2 (a refused personal overwrite still
 * becomes the session name). N3-N5 are regression pins that must STAY green.
 *
 * Usage: php tools/acceptance-viewer-login-name.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/login';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-64s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// ── N1: the login form does not REQUIRE a full name (any account may omit it) ────────────────────
$loginHtml = '';
ob_start();
try {
    pageDailyLedgerLogin();
    $loginHtml = (string)ob_get_clean();
} catch (Throwable $e) {
    $loginHtml = 'THREW: ' . $e->getMessage();
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}
$inputTag = '';
if (preg_match('/<input\b[^>]*name="full_name"[^>]*>/i', $loginHtml, $m)) {
    $inputTag = $m[0];
}
$requiredAttr = $inputTag !== '' && (bool)preg_match('/\brequired\b/i', $inputTag);
$check('N1', 'the Full Name field is present but NOT required', $inputTag !== '' && !$requiredAttr,
    sprintf('input[name=full_name] rendered: %s; still carries `required`: %s (a personal account cannot satisfy a required field that its profile refuses to keep)',
        $inputTag !== '' ? 'yes' : 'NO', $requiredAttr ? 'YES' : 'no'));

// ── HTTP probes (probe accounts only) ────────────────────────────────────────────────────────────
$db = module()->db();
$personalId = 997911;
$sharedId = 997912;
$personalUsername = 'probe-login-personal-' . $personalId;
$sharedUsername = 'Cashier-ProbeLoginAM-' . $sharedId;
$password = 'Probe!Login2031';
$personalStored = 'Personal Probe Stored';

$cleanup = static function () use ($db, $personalId, $sharedId, $personalUsername, $sharedUsername): void {
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$personalId, $sharedId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?) OR username IN (?, ?)')
        ->execute([$personalId, $sharedId, $personalUsername, $sharedUsername]);
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id IN (?, ?)')->execute([$personalId, $sharedId]);
};
$cleanup();

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$reachable = false;
try {
    $probe = curl_init($httpBase . '/daily-ledger/login');
    curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4]);
    curl_exec($probe);
    $reachable = (int)curl_getinfo($probe, CURLINFO_HTTP_CODE) > 0;
} catch (Throwable $e) {
    $reachable = false;
}

if (!$reachable) {
    $check('N2..N5', 'live HTTP login probes', false, 'no web server reachable at ' . $httpBase . ' - the session-name checks cannot run');
    printf("\n  => %d RED. %s\n", $reds, 'The criterion discriminates: this is the work.');
    exit(1);
}

try {
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "admin", 1)')
        ->execute([$personalId, $personalUsername, password_hash($password, PASSWORD_BCRYPT), $personalStored]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "cashier", 1)')
        ->execute([$sharedId, $sharedUsername, password_hash($password, PASSWORD_BCRYPT), '']);

    $login = static function (string $url, string $username, string $fullName, string $pass): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'full_name' => $fullName, 'password' => $pass], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return ['status' => $status, 'body' => $body];
    };
    // The session identity the login hands out is the JWT payload (base64url, no secret needed to read).
    $sessionNameOf = static function (array $body): string {
        $token = (string)($body['token'] ?? '');
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return '';
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = is_string($json) ? (json_decode($json, true) ?: []) : [];

        return (string)($payload['name'] ?? '');
    };
    $storedNameOf = static function (int $id) use ($db): string {
        $stmt = $db->prepare('SELECT full_name FROM dl_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return trim((string)$stmt->fetchColumn());
    };
    $loginUrl = $httpBase . '/daily-ledger/auth/login';

    // ── N2: a personal account's refused typed name must NOT become its session identity ─────────
    $intruder = 'Intruder Rewrite';
    $resp = $login($loginUrl, $personalUsername, $intruder, $password);
    $sessionName = $sessionNameOf($resp['body']);
    $storedAfter = $storedNameOf($personalId);
    $check('N2', 'personal login: session identity is the STORED name, not the typed name',
        $resp['status'] === 200 && $storedAfter === $personalStored && $sessionName === $personalStored,
        sprintf('HTTP %d; stored after=%s (unchanged=%s); session name="%s" (must be the stored "%s", never "%s")',
            $resp['status'], $storedAfter, $storedAfter === $personalStored ? 'yes' : 'NO',
            $sessionName, $personalStored, $intruder));

    // ── N3: regression pin - the shared cashier workflow still works end to end ─────────────────
    $holder = 'Maria Santos';
    $resp = $login($loginUrl, $sharedUsername, $holder, $password);
    $sessionName = $sessionNameOf($resp['body']);
    $storedAfter = $storedNameOf($sharedId);
    $check('N3', 'shared cashier login: typed holder still becomes profile AND session name',
        $resp['status'] === 200 && $storedAfter === $holder && $sessionName === $holder,
        sprintf('HTTP %d; stored=%s; session name="%s" (both must be "%s")',
            $resp['status'], $storedAfter, $sessionName, $holder));

    // ── N4: regression pin - omitting the name entirely still logs in (field genuinely optional) ─
    $resp = $login($loginUrl, $personalUsername, '', $password);
    $sessionName = $sessionNameOf($resp['body']);
    $check('N4', 'personal login with NO name typed succeeds and keeps the stored identity',
        $resp['status'] === 200 && $sessionName === $personalStored,
        sprintf('HTTP %d; session name="%s" (expected "%s")', $resp['status'], $sessionName, $personalStored));

    // ── N5: regression pin - the refused overwrite still logs the warning with both values ──────
    $logPath = $base . '/storage/logs/app.log';
    $refusal = '';
    if (is_file($logPath)) {
        $lines = array_filter((array)file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            static fn(string $l): bool => str_contains($l, 'auth full_name overwrite refused') && str_contains($l, (string)$personalId));
        $refusal = $lines !== [] ? (string)end($lines) : '';
    }
    $check('N5', 'the refused personal overwrite is still logged with stored and entered values',
        $refusal !== '' && str_contains($refusal, $personalStored) && str_contains($refusal, $intruder),
        $refusal !== '' ? 'refusal line found for the probe account' : 'no refusal line found for the probe account');
} finally {
    $cleanup();
}

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
