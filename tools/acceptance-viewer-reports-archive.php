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
 * RED-now: P1 (a viewer export still creates a shared archive row), P8 (the explicit
 * reports.archive.create action does not exist yet). P1b/P2-P6 are pins.
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
$adminId = 997922;
$adminUsername = 'probe-admin-archive-' . $adminId;
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
$cleanup = static function () use ($db, $probeId, $probeUsername, $adminId, $adminUsername, $removeArchive, &$createdIds): void {
    foreach (array_keys($createdIds) as $id) {
        $removeArchive($id);
    }
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$probeId, $adminId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?) OR username IN (?, ?)')->execute([$probeId, $adminId, $probeUsername, $adminUsername]);
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id IN (?, ?)')->execute([$probeId, $adminId]);
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
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "admin", 1)')
        ->execute([$adminId, $adminUsername, password_hash($password, PASSWORD_BCRYPT), 'Probe Archive Admin']);

    // Login both probes over HTTP; the session token is the JWT from the response.
    $loginAs = static function (string $username) use ($http, $httpBase, $password): string {
        $login = $http('POST', $httpBase . '/daily-ledger/auth/login', ['Content-Type: application/json', 'Accept: application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_SLASHES));
        $body = json_decode($login['body'], true) ?: [];

        return (string)($body['token'] ?? '');
    };
    $cookieViewer = 'Cookie: ' . dlCookieName() . '=' . $loginAs($probeUsername);
    $cookieAdmin = 'Cookie: ' . dlCookieName() . '=' . $loginAs($adminUsername);
    $auditCount = static function () use ($db): int {
        return (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export'")->fetchColumn();
    };

    // ── P1 (D3 split): a VIEWER export downloads and audits, but does NOT persist a shared archive ─
    $auditBefore = $auditCount();
    $before = $listArchived();
    $exportUrl = $httpBase . '/daily-ledger/admin/reports/sales/export?format=csv'
        . '&date_from=2026-10-05&date_to=2026-10-11&branch_id=8';
    $exportViewer = $http('GET', $exportUrl, [$cookieViewer]);
    usleep(250000);
    $afterViewer = $listArchived();
    $newFromViewer = array_values(array_diff(array_keys($afterViewer), array_keys($before)));
    foreach ($newFromViewer as $id) {
        $createdIds[$id] = true;
    }
    $auditAfter = $auditCount();
    $viewerCsv = $exportViewer['status'] === 200 && str_contains(strtolower($exportViewer['headers']), 'text/csv') && $exportViewer['body'] !== '';
    $p1 = $viewerCsv && $newFromViewer === [] && $auditAfter > $auditBefore;
    $check('P1', 'viewer export downloads + audits WITHOUT creating a shared archive entry', $p1,
        sprintf('HTTP %d; csv body %d bytes; new archive(s) from the viewer: %d (must be 0%s); report_export audit rows: %d -> %d (must increase)',
            $exportViewer['status'], strlen($exportViewer['body']), count($newFromViewer),
            $newFromViewer !== [] ? ' - viewer still writes shared state' : '', $auditBefore, $auditAfter));

    // ── P1b (pin): an explicitly-authorized role's export still archives (the workflow keeps working) ─
    $beforeAdmin = $listArchived();
    $exportAdmin = $http('GET', $exportUrl, [$cookieAdmin]);
    usleep(250000);
    $afterAdmin = $listArchived();
    $newIds = array_values(array_diff(array_keys($afterAdmin), array_keys($beforeAdmin)));
    foreach ($newIds as $id) {
        $createdIds[$id] = true;
    }
    $newId = $newIds[0] ?? '';
    $adminCsv = $exportAdmin['status'] === 200 && str_contains(strtolower($exportAdmin['headers']), 'text/csv') && $exportAdmin['body'] !== '';
    $p1b = $adminCsv && count($newIds) === 1;
    $check('P1b', 'an authorized role\'s export still creates its archive row', $p1b,
        sprintf('HTTP %d; new archive(s): %d%s', $exportAdmin['status'], count($newIds),
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
    $download = $http('GET', $downloadUrl, [$cookieViewer]);
    $sameBytes = $download['status'] === 200 && $download['body'] === $exportAdmin['body'];
    $check('P3', 'a viewer can download the authorized archive (identical bytes to the export)', $sameBytes,
        sprintf('HTTP %d; %d bytes vs export %d; identical: %s',
            $download['status'], strlen($download['body']), strlen($exportAdmin['body']), $sameBytes ? 'yes' : 'NO'));

    // ── P4 (D3): the endpoint is authorized, not open - an anonymous request is refused ──────────
    $anon = $http('GET', $downloadUrl, []);
    $refused = in_array($anon['status'], [401, 403], true);
    $check('P4', 'anonymous download is REFUSED (401/403, not data)', $refused,
        sprintf('anonymous HTTP %d (must be 401 or 403; 200 would expose the archive, 404 means the endpoint does not exist yet)',
            $anon['status']));

    // ── P5 (pin): an unknown/garbage archive id is a clean refusal, never a fatal ────────────────
    $bogus = $http('GET', $httpBase . '/daily-ledger/admin/reports/archive-download?id=rpt_does_not_exist_0000', [$cookieViewer]);
    $check('P5', 'unknown archive id -> clean refusal (not 200, no fatal)', $bogus['status'] !== 200 && !str_contains($bogus['body'], 'Fatal'),
        sprintf('HTTP %d; body: %s', $bogus['status'], substr(strip_tags($bogus['body']), 0, 80)));

    // ── P6 (pin): the reports page still states the exports are audited ──────────────────────────
    $check('P6', 'the reports page states exports are audited', (bool)preg_match('/audited/i', $page),
        preg_match('/audited/i', $page) ? 'statement present' : 'statement missing');

    // ── P8 (D3 split): reports.archive.create exists as an explicit, separately-grantable action ──
    $actions = dl_allPermissionActions();
    $viewerDefaults = dl_defaultRolePermissions()['viewer'] ?? [];
    $hasAction = in_array('reports.archive.create', $actions, true);
    $viewerGranted = in_array('reports.archive.create', $viewerDefaults, true);
    $check('P8', 'reports.archive.create is grantable but NOT a viewer default', $hasAction && !$viewerGranted,
        sprintf('action in the settings whitelist: %s; viewer default grant: %s (must be no - the grant is the explicit authorization)',
            $hasAction ? 'yes' : 'NO', $viewerGranted ? 'YES (must not be)' : 'no'));
} finally {
    $cleanup();
}

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
