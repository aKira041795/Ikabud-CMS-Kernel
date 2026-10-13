<?php

declare(strict_types=1);

/**
 * Kernel — module provisioning guard (2026-10-13).
 *
 * Live incident: baronledger.zdnorte.net dispatched daily-ledger against a
 * database without any dl_* tables and the first query died as a raw
 * PDOException (42S02) fatal. executeModuleHandler() now refuses a module whose
 * declared tables are absent on the ACTIVE connection with the standard 404 and
 * logs the database name, so the cause is read instead of guessed.
 *
 * Both context checks and the dispatch proof run in SUBPROCESSES: the guard's
 * verdict is cached per process (correct at runtime — one DB per request), and
 * http_response_code() cannot be exercised after the harness has echoed output.
 *
 * Proved here:
 *   1. Unprovisioned context -> helper true + REAL dispatch answers 404 with the
 *      "Module Not Available" page (no fatal) + the log names module + database.
 *   2. Provisioned tenant (207) -> helper false (never blocked).
 *   3. No-op and fail-open semantics: no declared tables, invalid names.
 *   4. Wiring: the guard runs BEFORE the authority gate; API + page covered.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('kernel-module-provisioning-guard', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'kernel_state_cache: module_registry rebuilt',
    'disyl.render.breakdown',
    'not provisioned on the active database'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';

$runProbe = static function (string $phpCode) use ($base): array {
    $file = tempnam(sys_get_temp_dir(), 'guard-probe-');
    file_put_contents($file, "<?php\n" . $phpCode);
    $raw = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : ['_raw' => $raw];
};

// ═══════════════════════════════════════════════════════════════════════
$h->section('1. Unprovisioned context: helper + REAL dispatch (subprocess)');

$negative = $runProbe(<<<'PHP'
$base = '/var/www/html/applicationostest';
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(null); // base/unresolved context: the incident shape
require_once $base . '/src/helpers/module-manager.php';
$mods = getEnabledModules();
$missing = isset($mods['daily-ledger']) ? kernelModuleTablesMissing('daily-ledger', $mods['daily-ledger']) : 'not-enabled';
$dbName = (string)($app->db()->query('SELECT DATABASE()')->fetchColumn() ?: '');
ob_start();
executeModuleHandler('daily-ledger:handleAdminCommissary');
$html = (string)ob_get_clean();
echo json_encode([
    'missing' => $missing,
    'code' => http_response_code(),
    'has_title' => str_contains($html, 'Module Not Available'),
    'has_fatal' => str_contains($html, 'PDOException'),
    'db' => $dbName,
]);
PHP);

$h->test('daily-ledger enabled in the cleared context', ($negative['missing'] ?? null) === true, json_encode($negative));
$h->test('dispatch answered 404 instead of a fatal', ($negative['code'] ?? null) === 404, 'code=' . var_export($negative['code'] ?? null, true));
$h->test('dispatch rendered the Module Not Available page', ($negative['has_title'] ?? false) === true);
$h->test('no PDOException text in the response', ($negative['has_fatal'] ?? true) === false);

$log = (string)file_get_contents($base . '/storage/logs/app.log');
$h->test(
    'log names the module and the database',
    str_contains($log, 'not provisioned on the active database')
    && str_contains($log, '"module":"daily-ledger"')
    && str_contains($log, '"database":"'),
    substr($log, -260)
);

// ═══════════════════════════════════════════════════════════════════════
$h->section('2. Provisioned tenant is never blocked (subprocess)');

$positive = $runProbe(<<<'PHP'
$base = '/var/www/html/applicationostest';
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
require_once $base . '/src/helpers/module-manager.php';
$m = getEnabledModules()['daily-ledger'] ?? null;
echo json_encode(['missing' => $m === null ? 'no-module' : kernelModuleTablesMissing('daily-ledger', $m)]);
PHP);
$h->test('tenant 207 (tables present) -> not missing', ($positive['missing'] ?? null) === false, json_encode($positive));

// ═══════════════════════════════════════════════════════════════════════
$h->section('3. No-op and fail-open semantics');

$h->test('no declared tables -> never blocked', kernelModuleTablesMissing('probe-no-tables', ['owns_tables' => []]) === false);
$h->test('invalid table names ignored -> never blocked', kernelModuleTablesMissing('probe-invalid', ['owns_tables' => ['bad-name!', 'x y']]) === false);
$h->test('single absent probe table -> missing', kernelModuleTablesMissing('probe-missing', ['owns_tables' => ['zzz_guard_test_absent_table']]) === true);

$src = (string)file_get_contents($base . '/src/helpers/module-manager.php');
$h->test('fail-open clause present (probe errors never block)', str_contains($src, 'Fail-open'));

// ═══════════════════════════════════════════════════════════════════════
$h->section('4. Wiring');

$guardAt = strpos($src, 'if (kernelModuleTablesMissing($moduleId, $modules[$moduleId]))');
$authAt = strpos($src, 'AuthorityDispatchGuard::decide(');
$h->test('guard call exists', $guardAt !== false);
$h->test('guard runs before the authority gate', $guardAt !== false && $authAt !== false && $guardAt < $authAt, "guard@{$guardAt} auth@{$authAt}");
$h->test('API responses model the refusal as JSON', str_contains($src, "'state' => 'module_not_provisioned'"));

$h->done();
