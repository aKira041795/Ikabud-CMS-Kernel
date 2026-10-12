<?php
declare(strict_types=1);

/**
 * Acceptance criterion: archived reports have a governed download, and the export endpoint is
 * authorized for the viewer itself (viewer surface W11/W17; findings F15/F16; chair ruling D3).
 *
 * Verified truth (2026-10-12): ReportManager::archiveReport() COPIES the finished file into
 * storage/report-archive/<id>.<format> and keeps it (the module's unlink is only the audit-rollback
 * path), and dl_handleAdminReportExport() streams the file after archiving. So the archive table's
 * missing action (F15) is a missing GOVERNED CONTROL, not missing data: a control + a tenant-scoped,
 * permission-checked download endpoint can serve the stored copy.
 *
 * D3: "An audit row documenting a download is compatible with read-only access"; a viewer's export is
 * already authorized at the endpoint (dlRequireAuth includes viewer; ReportManager::canExport gates
 * the export). This criterion proves that end to end AND proves the new download is NOT open.
 *
 * Method: probe viewer account (seeded, removed in finally; the live accounts are never used),
 * real HTTP to http://baronledger.test. It exports CSV as the probe viewer, asserts the archive
 * appeared WITH a download control on the reports page, downloads the archived bytes, and asserts
 * an anonymous request is refused.
 *
 * RED-now: P2 (no per-row control), P3 (no download endpoint), P4 (anonymous fetch expectation).
 * P1/P5/P6 are pins.
 *
 * Usage: php tools/acceptance-viewer-reports-archive.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/reports';

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

$db = module()->db();
$probeId = 997921;
$probeUsername = 'probe-viewer-archive-' . $probeId;
$password = 'Probe!Archive2031';
$archiveDir = STORAGE_PATH . '/report-archive';
$createdIds = [];

$listArchived = static function (): array {
    $out = [];
    foreach ((array)\Ikabud\Kernel\Services\ReportManager::listArchived() as $item) {
        $out[(string)($item['id'] ?? '')] = $item;
    }

    return $out;
};
$removeArchive = static function (string $id) use ($archiveDir, $db, &$createdIds): void {
    foreach (glob($archiveDir . '/' . $id . '.*') ?: [] as $f) {
        @unlink($f);
    }
    $db->prepare('DELETE FROM audit_logs WHERE entity_id = ?')->execute([$id]);
    unset($createdIds[$id]);
};
$cleanup = static function () use ($db, $probeId, $probeUsername, $removeArchive, &$createdIds): void {
    foreach (array_keys($createdIds) as $id) {
        $removeArchive($id);
    }
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = ?')->execute([$probeId]);
    $db->prepare('DELETE FROM dl_users WHERE id = ? OR username = ?')->execute([$probeId, $probeUsername]);
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id = ?')->execute([$probeId]);
};
$cleanup();

$http = static function (string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    $respBody = is_string($raw) ? substr($raw, $headerSize) : '';
    $respHeaders = is_string($raw) ? substr($raw, 0, $headerSize) : '';

    return ['status' => $status, 'body' => $respBody, 'headers' => $respHeaders, 'error' => $error];
};

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$reacheable = $http('GET', $httpBase . '/daily-ledger/login');
if ($reacheable['status'] === 0) {
    $check('P1..P6', 'live HTTP probes', false, 'no web server reachable at ' . $httpBase);
    printf("\n  => %d RED. %s\n", $reds, 'The criterion discriminates: this is the work.');
    exit(1);
}

try {
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "viewer", 1)')
        ->execute([$probeId, $probeUsername, password_hash($password, PASSWORD_BCRYPT), 'Probe Viewer']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$probeId, 8]);

    // Login the probe viewer over HTTP; the session token is the JWT from the response.
    $login = $http('POST', $httpBase . '/daily-ledger/auth/login', ['Content-Type: application/json', 'Accept: application/json'],
        json_encode(['username' => $probeUsername, 'password' => $password], JSON_UNESCAPED_SLASHES));
    $loginBody = json_decode($login['body'], true) ?: [];
    $token = (string)($loginBody['token'] ?? '');
    $cookieHeader = 'Cookie: ' . dlCookieName() . '=' . $token;

    // ── P1 (pin, and W17's end-to-end authorization proof): the VIEWER's export works and archives ─
    $before = $listArchived();
    $exportUrl = $httpBase . '/daily-ledger/admin/reports/sales/export?format=csv'
        . '&date_from=2026-10-05&date_to=2026-10-11&branch_id=8';
    $export = $http('GET', $exportUrl, [$cookieHeader]);
    usleep(200000);
    $after = $listArchived();
    $newIds = array_values(array_diff(array_keys($after), array_keys($before)));
    foreach ($newIds as $id) {
        $createdIds[$id] = true;
    }
    $newId = $newIds[0] ?? '';
    $isCsv = $export['status'] === 200 && str_contains(strtolower($export['headers']), 'text/csv') && $export['body'] !== '';
    $p1 = $isCsv && count($newIds) === 1;
    $check('P1', 'viewer export (HTTP) succeeds AND its archive row appears', $p1,
        sprintf('HTTP %d; csv body %d bytes; new archive(s) after export: %d%s',
            $export['status'], strlen($export['body']), count($newIds),
            $newId !== '' ? ' (id ' . $newId . ')' : ''));

    // ── P2 (F15): the reports page offers a download control for that archive ────────────────────
    $tokens = dl_generateAuthTokens(['sub' => 'viewer:' . $probeId, 'id' => $probeId, 'username' => $probeUsername, 'name' => 'Probe Viewer', 'role' => 'viewer', 'source' => 'daily-ledger']);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $_GET = [];
    $_REQUEST = [];
    ob_start();
    try {
        handleAdminReports();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    $page = (string)ob_get_clean();
    $hasControl = $newId !== '' && (bool)preg_match('/href="[^"]*archive-download[^"]*' . preg_quote($newId, '/') . '"/i', $page);
    $check('P2', 'the archive row for a real archive carries a download control', $hasControl,
        sprintf('renders: %s; control linking archive %s: %s',
            !str_contains($page, 'THREW:') ? 'yes' : 'NO', $newId !== '' ? $newId : '(none created)',
            $hasControl ? 'present' : 'ABSENT - the table is metadata-only (F15)'));

    // ── P3 (W11): downloading serves the ARCHIVED bytes - identical to the original export ───────
    $downloadUrl = $httpBase . '/daily-ledger/admin/reports/archive-download?id=' . rawurlencode($newId);
    $download = $http('GET', $downloadUrl, [$cookieHeader]);
    $sameBytes = $download['status'] === 200 && $download['body'] === $export['body'];
    $check('P3', 'the download endpoint serves the stored copy (same bytes as the export)', $sameBytes,
        sprintf('HTTP %d; %d bytes vs export %d; identical: %s',
            $download['status'], strlen($download['body']), strlen($export['body']), $sameBytes ? 'yes' : 'NO'));

    // ── P4 (D3): the endpoint is authorized, not open - an anonymous request is refused ──────────
    $anon = $http('GET', $downloadUrl, []);
    $refused = in_array($anon['status'], [401, 403], true);
    $check('P4', 'anonymous download is REFUSED (401/403, not data)', $refused,
        sprintf('anonymous HTTP %d (must be 401 or 403; 200 would expose the archive, 404 means the endpoint does not exist yet)',
            $anon['status']));

    // ── P5 (pin): an unknown/garbage archive id is a clean refusal, never a fatal ────────────────
    $bogus = $http('GET', $httpBase . '/daily-ledger/admin/reports/archive-download?id=rpt_does_not_exist_0000', [$cookieHeader]);
    $check('P5', 'unknown archive id -> clean refusal (not 200, no fatal)', $bogus['status'] !== 200 && !str_contains($bogus['body'], 'Fatal'),
        sprintf('HTTP %d; body: %s', $bogus['status'], substr(strip_tags($bogus['body']), 0, 80)));

    // ── P6 (pin): the landing page still states that exports are archived and audited ────────────
    $check('P6', 'the reports page states exports are archived and audited', str_contains($page, 'archived and audited'),
        str_contains($page, 'archived and audited') ? 'statement present' : 'statement missing');
} finally {
    $cleanup();
}

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
