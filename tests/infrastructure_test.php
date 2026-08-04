<?php
/**
 * Ikabud Kernel — Infrastructure Integration Tests
 * Tests: QueryBuilder, EventBus, MigrationRunner, CLI, TenantResolver
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\QueryBuilder;
use Ikabud\Kernel\Database\MigrationRunner;
use Ikabud\Kernel\Database\ConnectionPool;
use Ikabud\Kernel\EventBus;
use Ikabud\Kernel\TenantResolver;
use Ikabud\Kernel\Crypto;

$pass = 0; $fail = 0;
function ok(string $d, bool $c): void {
    global $pass, $fail;
    if ($c) { echo "  \033[32m✓\033[0m {$d}\n"; $pass++; }
    else    { echo "  \033[31m✗\033[0m {$d}\n"; $fail++; }
}
function heading(string $t): void { echo "\n\033[1m  ── {$t} ──\033[0m\n"; }

$pdo = app()->db();
$qb = new QueryBuilder($pdo);

// ── 1. QUERY BUILDER ─────────────────────────────────────────────
heading('QueryBuilder — SELECT');
$users = $qb->table('users')->get();
ok('get() returns rows', is_array($users) && count($users) > 0);
ok('first() works', is_array($qb->table('users')->where('role','admin')->first()));
ok('first() null on miss', $qb->table('users')->where('username','___x___')->first() === null);
ok('value() scalar', is_string($qb->table('users')->where('role','admin')->value('full_name')));
ok('pluck() flat array', count($qb->table('users')->pluck('username')) > 0);
ok('count() int', $qb->table('users')->count() > 0);
ok('exists() true', $qb->table('users')->where('role','admin')->exists());
ok('exists() false', !$qb->table('users')->where('username','___x___')->exists());

heading('QueryBuilder — WHERE variants');
ok('where = works', count($qb->table('users')->where('is_active','=',1)->get()) > 0);
ok('where IN works', count($qb->table('users')->where('role','IN',['admin','supervisor'])->get()) > 0);
ok('where LIKE works', count($qb->table('users')->where('username','LIKE','admin%')->get()) > 0);
ok('whereRaw works', count($qb->table('users')->whereRaw('LENGTH(username) > ?',[3])->get()) > 0);
ok('whereNotNull works', count($qb->table('users')->whereNotNull('username')->get()) > 0);

heading('QueryBuilder — ORDER/LIMIT/PAGINATE');
$ord = $qb->table('users')->orderBy('id','DESC')->limit(2)->get();
ok('orderBy+limit', count($ord) <= 2);
$pg = $qb->table('users')->paginate(2,1);
ok('paginate keys', isset($pg['data'],$pg['total'],$pg['page'],$pg['pages']));
ok('paginate data count', count($pg['data']) <= 2);

heading('QueryBuilder — INSERT/UPDATE/DELETE');
$pdo->exec("CREATE TEMPORARY TABLE _qbt (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), score INT DEFAULT 0)");
$id1 = $qb->table('_qbt')->insert(['name'=>'Alice','score'=>10]);
ok('insert returns ID', $id1 > 0);
$id2 = $qb->table('_qbt')->insert(['name'=>'Bob','score'=>20]);
ok('insert increments', $id2 > $id1);
ok('insertMany', $qb->table('_qbt')->insertMany([['name'=>'C','score'=>30],['name'=>'D','score'=>40]]) === 2);
ok('4 rows total', $qb->table('_qbt')->count() === 4);
ok('update', $qb->table('_qbt')->where('name','Alice')->update(['score'=>99]) === 1);
ok('update persisted', (int)$qb->table('_qbt')->where('name','Alice')->value('score') === 99);
$qb->table('_qbt')->where('name','Bob')->increment('score',5);
ok('increment', (int)$qb->table('_qbt')->where('name','Bob')->value('score') === 25);
$qb->table('_qbt')->where('name','Bob')->decrement('score',3);
ok('decrement', (int)$qb->table('_qbt')->where('name','Bob')->value('score') === 22);
ok('delete', $qb->table('_qbt')->where('name','D')->delete() === 1);
$threw = false;
try { $qb->table('_qbt')->delete(); } catch (\RuntimeException $e) { $threw = true; }
ok('delete without WHERE throws', $threw);

heading('QueryBuilder — Upsert/Join/Raw/Transaction');
$pdo->exec("CREATE TEMPORARY TABLE _qbu (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) UNIQUE, val INT DEFAULT 0)");
$qb->table('_qbu')->insert(['code'=>'A','val'=>1]);
$qb->table('_qbu')->upsert(['code'=>'A','val'=>1],['val'=>99]);
ok('upsert', (int)$qb->table('_qbu')->where('code','A')->value('val') === 99);
try {
    $pdo->exec("CREATE TEMPORARY TABLE user_branches (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, branch_id INT UNSIGNED)");
} catch (\Throwable $e) {
    // If it already exists in this DB, ignore.
}
ok('leftJoin runs', is_array($qb->table('users u')->leftJoin('user_branches ub','ub.user_id = u.id')->select('u.username')->get()));
ok('raw()', (int)($qb->raw('SELECT 1+1 AS r')[0]['r'] ?? 0) === 2);
$tx = $qb->transaction(function() use ($qb) { $qb->table('_qbt')->where('name','Alice')->update(['score'=>200]); return 'ok'; });
ok('transaction commit', $tx === 'ok' && (int)$qb->table('_qbt')->where('name','Alice')->value('score') === 200);
try { $qb->transaction(function() use ($qb) { $qb->table('_qbt')->where('name','Alice')->update(['score'=>999]); throw new \RuntimeException('rb'); }); } catch (\RuntimeException $e) {}
ok('transaction rollback', (int)$qb->table('_qbt')->where('name','Alice')->value('score') === 200);

// ── 2. EVENT BUS ─────────────────────────────────────────────────
heading('EventBus — Core');
$ev = EventBus::getInstance(); $ev->reset();
$got = [];
$ev->listen('t.a', function($p,$e) use (&$got) { $got[] = $e; });
ok('fire returns 1', $ev->fire('t.a',['k'=>'v']) === 1);
ok('listener called', count($got) === 1 && $got[0] === 't.a');

heading('EventBus — Priority');
$ev->reset(); $ord = [];
$ev->listen('t.p', function() use (&$ord) { $ord[] = 'B'; }, 20);
$ev->listen('t.p', function() use (&$ord) { $ord[] = 'A'; }, 5);
$ev->fire('t.p');
ok('priority order', $ord === ['A','B']);

heading('EventBus — Wildcards');
$ev->reset(); $wh = [];
$ev->listen('order.*', function($p,$e) use (&$wh) { $wh[] = $e; });
$ev->fire('order.placed'); $ev->fire('order.cancelled'); $ev->fire('user.created');
ok('wildcard matches', $wh === ['order.placed','order.cancelled']);

heading('EventBus — Error isolation');
$ev->reset(); $after = false;
$ev->listen('t.err', function() { throw new \RuntimeException('boom'); }, 10);
$ev->listen('t.err', function() use (&$after) { $after = true; }, 20);
$ev->fire('t.err');
ok('listener after error runs', $after);

heading('EventBus — Utility');
$ev->reset();
$ev->listen('a.b', function() {});
ok('hasListeners true', $ev->hasListeners('a.b'));
ok('hasListeners false', !$ev->hasListeners('z.z'));
$ev->off('a.b');
ok('off removes', !$ev->hasListeners('a.b'));
$ev->enableHistory(true); $ev->fire('h.1'); $ev->fire('h.2');
ok('history records', count($ev->history()) === 2);
$ev->reset();

heading('EventBus — Deferred');
$deferredEvents = [];
$ev->listen('t.defer', function($p,$e) use (&$deferredEvents) { $deferredEvents[] = $e . ':' . (int)($p['id'] ?? 0); });
ok('fireDeferred queues without immediate delivery', $ev->fireDeferred('t.defer', ['id' => 9]) === 1 && $ev->deferredCount() === 1 && $deferredEvents === []);
ok('flushDeferred delivers queued event', $ev->flushDeferred() === 1 && $ev->deferredCount() === 0 && $deferredEvents === ['t.defer:9']);
$ev->reset();

// ── 3. MIGRATION RUNNER ─────────────────────────────────────────
heading('MigrationRunner');
$runner = new MigrationRunner($pdo);
ok('_migrations table exists', $pdo->query("SHOW TABLES LIKE '_migrations'")->fetchColumn() !== false);

heading('Control-plane migrations');
$cpdo = app()->controlDb();
$crunner = new MigrationRunner($cpdo);
$executedControl = $crunner->migrate('_control');
ok('control migrate runs or is already up to date', is_array($executedControl));
ok('kernel_tenants table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_tenants'")->fetchColumn() !== false);
ok('kernel_tenant_domains table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_tenant_domains'")->fetchColumn() !== false);
ok('kernel_tenant_db_connections table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_tenant_db_connections'")->fetchColumn() !== false);
ok('kernel_module_catalog table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_module_catalog'")->fetchColumn() !== false);
ok('kernel_tenant_module_entitlements table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_tenant_module_entitlements'")->fetchColumn() !== false);
ok('kernel_tenant_module_access_requests table exists (control)', $cpdo->query("SHOW TABLES LIKE 'kernel_tenant_module_access_requests'")->fetchColumn() !== false);

heading('Crypto — AES-256-GCM');
$_ENV['CONTROL_DB_ENC_KEY'] = $_ENV['CONTROL_DB_ENC_KEY'] ?? base64_encode(random_bytes(32));
$crypto = new Crypto();
$enc = $crypto->encryptString('secret123');
ok('encrypt returns ciphertext', is_array($enc) && !empty($enc['ciphertext']) && !empty($enc['iv']) && !empty($enc['tag']));
ok('decrypt roundtrip', $crypto->decryptString($enc['ciphertext'], $enc['iv'], $enc['tag']) === 'secret123');

$tmpDir = BASE_PATH.'/modules/_test_mig';
$migDir = $tmpDir.'/migrations';
@mkdir($migDir, 0775, true);
file_put_contents($tmpDir.'/module.json', json_encode(['id'=>'_test_mig','name'=>'T','version'=>'0.1']));
file_put_contents($migDir.'/001_t.sql', "CREATE TABLE IF NOT EXISTS _mig_t (id INT PRIMARY KEY, v VARCHAR(10));");
file_put_contents($migDir.'/001_t.down.sql', "DROP TABLE IF EXISTS _mig_t;");

$ex = $runner->migrate('_test_mig');
ok('migrate runs pending', count($ex) === 1 && $ex[0] === '001_t.sql');
ok('table created', $pdo->query("SHOW TABLES LIKE '_mig_t'")->fetchColumn() !== false);
ok('idempotent re-run', count($runner->migrate('_test_mig')) === 0);
$st = $runner->status('_test_mig');
ok('status applied=1', count($st['applied']) === 1);
ok('status pending=0', count($st['pending']) === 0);
$rb = $runner->rollback('_test_mig');
ok('rollback works', count($rb) === 1);
ok('table dropped', $pdo->query("SHOW TABLES LIKE '_mig_t'")->fetchColumn() === false);
ok('pending after rollback', count($runner->status('_test_mig')['pending']) === 1);

$pdo->exec("DELETE FROM _migrations WHERE module='_test_mig'");
@unlink($migDir.'/001_t.sql'); @unlink($migDir.'/001_t.down.sql'); @rmdir($migDir);
@unlink($tmpDir.'/module.json'); @rmdir($tmpDir);

// ── 4. CLI TOOL ─────────────────────────────────────────────────
heading('CLI Tool');
$base = BASE_PATH;
ok('ikabud help', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' help >/dev/null 2>&1; echo $?') === 0);
ok('ikabud module:list', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' module:list >/dev/null 2>&1; echo $?') === 0);
ok('ikabud routes', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' routes >/dev/null 2>&1; echo $?') === 0);
ok('ikabud migrate:status', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' migrate:status >/dev/null 2>&1; echo $?') === 0);
ok('ikabud tinker', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . " tinker \"SELECT 1\" >/dev/null 2>&1; echo $?") === 0);
ok('ikabud capability:test', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' capability:test kernel.auth.authenticate@1 --with-modules >/dev/null 2>&1; echo $?') === 0);
ok('ikabud make:module', (int) shell_exec('php ' . escapeshellarg(__DIR__ . '/../ikabud') . ' make:module cli-test-tmp >/dev/null 2>&1; echo $?') === 0);
ok('make:module creates dir', is_dir(__DIR__ . '/../modules/cli-test-tmp'));
ok('make:module creates module.json', is_file(__DIR__ . '/../modules/cli-test-tmp/module.json'));
ok('make:module creates handlers.php', is_file(__DIR__ . '/../modules/cli-test-tmp/handlers.php'));
ok('make:module creates template', is_file(__DIR__ . '/../templates/modules/cli-test-tmp/pages/home.disyl'));
// cleanup
shell_exec("rm -rf {$base}/modules/cli-test-tmp {$base}/templates/modules/cli-test-tmp");

heading('CLI Tool — Grouped module paths');

$rrmdir = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($path);
};

$tmpSuffix = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
$cli = 'php ' . escapeshellarg(__DIR__ . '/../ikabud');

$rootModuleId = 'tmpmod-' . $tmpSuffix;
$groupSuiteId = 'zzsuite-alpha';
$groupedModuleId = $groupSuiteId . '-' . $tmpSuffix;
$groupedEntity = 'ticket';
$groupedCapability = $groupedModuleId . '.health.check@1';
$groupedCapabilityBase = $groupedModuleId . '.health.check';
$groupedCapHandler = __DIR__ . '/../modules/' . $groupSuiteId . '/' . $groupedModuleId . '/helpers/cap-' . str_replace(['.', '@'], '-', $groupedCapabilityBase) . '.php';
$zipPath = __DIR__ . '/../storage/' . $groupedModuleId . '-' . $tmpSuffix . '.zip';

$explicitSuiteId = 'suiteexplicit';
$explicitModuleId = $explicitSuiteId . '-' . $tmpSuffix;
$explicitSuitePath = __DIR__ . '/../modules/' . $explicitSuiteId;
$explicitModulePath = $explicitSuitePath . '/' . $explicitModuleId;
$explicitFlatPath = __DIR__ . '/../modules/' . $explicitModuleId;
$explicitTemplatePath = __DIR__ . '/../templates/modules/' . $explicitModuleId;
$explicitManifestPath = $explicitModulePath . '/module.json';
$explicitZipPath = __DIR__ . '/../storage/' . $explicitModuleId . '-' . $tmpSuffix . '.zip';

$rootModulePath = __DIR__ . '/../modules/' . $rootModuleId;
$rootTemplatePath = __DIR__ . '/../templates/modules/' . $rootModuleId;
$groupSuitePath = __DIR__ . '/../modules/' . $groupSuiteId;
$groupedModulePath = $groupSuitePath . '/' . $groupedModuleId;
$groupedFlatPath = __DIR__ . '/../modules/' . $groupedModuleId;
$groupedTemplatePath = __DIR__ . '/../templates/modules/' . $groupedModuleId;

$ownedSuiteId = 'suiteown-alpha';
$ownedSuitePath = __DIR__ . '/../modules/' . $ownedSuiteId;
$ownedSuiteModuleManifest = $ownedSuitePath . '/module.json';
$ownedChildId = $ownedSuiteId . '-child-' . $tmpSuffix;
$ownedChildRootPath = __DIR__ . '/../modules/' . $ownedChildId;

$ambiguousSuiteId = 'school-guidance';
$ambiguousSuitePath = __DIR__ . '/../modules/' . $ambiguousSuiteId;
$ambiguousModuleId = $ambiguousSuiteId . '-report-' . $tmpSuffix;

$dupSuiteId = 'dupsuite-beta';
$dupSuitePath = __DIR__ . '/../modules/' . $dupSuiteId;
$dupId = $dupSuiteId . '-' . $tmpSuffix;
$dupGroupedPath = $dupSuitePath . '/' . $dupId;
$dupRootPath = __DIR__ . '/../modules/' . $dupId;

$movedBucketPath = __DIR__ . '/../modules/moved-bucket-' . $tmpSuffix;
$movedRootPath = $movedBucketPath . '/' . $rootModuleId;

// Ensure a clean slate for this random suffix.
foreach ([
    $rootModulePath,
    $rootTemplatePath,
    $groupedModulePath,
    $groupedFlatPath,
    $groupedTemplatePath,
    $explicitModulePath,
    $explicitFlatPath,
    $explicitTemplatePath,
    $ownedChildRootPath,
    $dupGroupedPath,
    $dupRootPath,
    $movedRootPath,
] as $p) {
    $rrmdir($p);
}
@unlink($zipPath);
@unlink($explicitZipPath);
foreach (glob(__DIR__ . '/../tests/' . $groupedModuleId . '_*') ?: [] as $generatedTest) {
    @unlink($generatedTest);
}
foreach (glob(__DIR__ . '/../tests/' . $explicitModuleId . '_*') ?: [] as $generatedTest) {
    @unlink($generatedTest);
}
@unlink(__DIR__ . '/../tests/' . $rootModuleId . '_module_test.php');

if (is_dir($groupSuitePath) && !is_file($groupSuitePath . '/module.json')) {
    $rrmdir($groupSuitePath);
}
if (is_dir($explicitSuitePath) && !is_file($explicitSuitePath . '/module.json')) {
    $rrmdir($explicitSuitePath);
}
if (is_dir($ownedSuitePath) && !is_file($ownedSuiteModuleManifest)) {
    $rrmdir($ownedSuitePath);
}
if (is_dir($ambiguousSuitePath) && !is_file($ambiguousSuitePath . '/module.json')) {
    $rrmdir($ambiguousSuitePath);
}
if (is_dir($dupSuitePath) && !is_file($dupSuitePath . '/module.json')) {
    $rrmdir($dupSuitePath);
}
if (is_dir($movedBucketPath) && !is_file($movedBucketPath . '/module.json')) {
    $rrmdir($movedBucketPath);
}

ok('moduleInstallTargetDirForId 2-part id stays root', moduleInstallTargetDirForId('simple-' . $tmpSuffix) === __DIR__ . '/../modules/simple-' . $tmpSuffix);
ok('moduleInstallTargetDirForId explicit suite groups 2-part id', moduleInstallTargetDirForId($explicitModuleId, $explicitSuiteId) === $explicitModulePath);

@mkdir($groupSuitePath, 0775, true);
ok('ikabud make:module root id', (int) shell_exec($cli . ' make:module ' . escapeshellarg($rootModuleId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('root module path created at modules/<id>', is_dir($rootModulePath));

ok('ikabud make:module grouped id', (int) shell_exec($cli . ' make:module ' . escapeshellarg($groupedModuleId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('grouped module path created under suite container', is_dir($groupedModulePath));
ok('grouped module not created at root modules/<id>', !is_dir($groupedFlatPath));
ok('grouped module templates remain in templates/modules/<id>', is_file($groupedTemplatePath . '/pages/home.disyl'));
ok('modulePathForId resolves grouped module path', modulePathForId($groupedModuleId) === realpath($groupedModulePath));

ok('ikabud make:module explicit suite id', (int) shell_exec($cli . ' make:module ' . escapeshellarg($explicitModuleId) . ' --suite=' . escapeshellarg($explicitSuiteId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('explicit suite module created under suite container', is_dir($explicitModulePath));
ok('explicit suite module not created at root modules/<id>', !is_dir($explicitFlatPath));
ok('explicit suite module templates remain in templates/modules/<id>', is_file($explicitTemplatePath . '/pages/home.disyl'));
$explicitManifest = is_file($explicitManifestPath) ? json_decode((string)file_get_contents($explicitManifestPath), true) : null;
ok('explicit suite persisted in module manifest', is_array($explicitManifest) && (($explicitManifest['suite'] ?? null) === $explicitSuiteId));

$resolvedThreePart = moduleInstallTargetDirForId($groupedModuleId);
$resolvedFourPart = moduleInstallTargetDirForId($groupSuiteId . '-feature-' . $tmpSuffix);
ok('moduleInstallTargetDirForId groups 3-part ids into suite', $resolvedThreePart === $groupedModulePath);
ok('moduleInstallTargetDirForId groups 4-part ids into suite', $resolvedFourPart === $groupSuitePath . '/' . $groupSuiteId . '-feature-' . $tmpSuffix);

ok('ikabud make:capability grouped module', (int) shell_exec($cli . ' make:capability ' . escapeshellarg($groupedCapability) . ' --module=' . escapeshellarg($groupedModuleId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('grouped capability handler created under grouped path', is_file($groupedCapHandler));

ok('ikabud make:entity grouped module', (int) shell_exec($cli . ' make:entity ' . escapeshellarg($groupedModuleId . '.' . $groupedEntity) . ' >/dev/null 2>&1; echo $?') === 0);
ok('grouped entity handler created under grouped path', is_file($groupedModulePath . '/helpers/' . $groupedEntity . '-entity.php'));
ok('grouped entity templates created under templates/modules/<id>', is_file($groupedTemplatePath . '/' . $groupedEntity . '-list.disyl') && is_file($groupedTemplatePath . '/' . $groupedEntity . '-detail.disyl'));

ok('ikabud module:pack grouped module', (int) shell_exec($cli . ' module:pack ' . escapeshellarg($groupedModuleId) . ' ' . escapeshellarg($zipPath) . ' >/dev/null 2>&1; echo $?') === 0);
ok('grouped module zip created', is_file($zipPath));
ok('ikabud module:pack explicit suite module', (int) shell_exec($cli . ' module:pack ' . escapeshellarg($explicitModuleId) . ' ' . escapeshellarg($explicitZipPath) . ' >/dev/null 2>&1; echo $?') === 0);
ok('explicit suite module zip created', is_file($explicitZipPath));

ok('ikabud module:uninstall grouped module', (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($groupedModuleId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('grouped module path removed on uninstall', !is_dir($groupedModulePath));
ok('grouped templates removed on uninstall', !is_dir($groupedTemplatePath));
ok('ikabud module:uninstall explicit suite module', (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($explicitModuleId) . ' >/dev/null 2>&1; echo $?') === 0);
ok('explicit suite module path removed on uninstall', !is_dir($explicitModulePath));
ok('explicit suite templates removed on uninstall', !is_dir($explicitTemplatePath));

$installResult = installModuleFromZip($zipPath);
ok('installModuleFromZip grouped module succeeds', !empty($installResult['ok']));
ok('installModuleFromZip restores grouped path', is_dir($groupedModulePath));

$explicitInstallResult = installModuleFromZip($explicitZipPath);
ok('installModuleFromZip explicit suite module succeeds', !empty($explicitInstallResult['ok']));
ok('installModuleFromZip explicit suite restores grouped path', is_dir($explicitModulePath));

$wasEnabled = isModuleEnabled($groupedModuleId);
disableModule($groupedModuleId);
ok('disableModule updates grouped module enabled state', !isModuleEnabled($groupedModuleId));
enableModule($groupedModuleId);
ok('enableModule updates grouped module enabled state', isModuleEnabled($groupedModuleId));
if (!$wasEnabled) {
    disableModule($groupedModuleId);
}

@mkdir($ownedSuitePath, 0775, true);
file_put_contents($ownedSuiteModuleManifest, json_encode([
    'id' => $ownedSuiteId,
    'name' => 'Owned Suite',
    'version' => '1.0.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
ok('suite dir with module.json keeps child module at root path', moduleInstallTargetDirForId($ownedChildId) === $ownedChildRootPath);
ok('make:module --suite fails when suite path is a real module', (int) shell_exec($cli . ' make:module ' . escapeshellarg($ownedChildId) . ' --suite=' . escapeshellarg($ownedSuiteId) . ' >/dev/null 2>&1; echo $?') !== 0);

@mkdir($ambiguousSuitePath, 0775, true);
ok('ambiguous suite-like id groups when bare container exists', moduleInstallTargetDirForId($ambiguousModuleId) === $ambiguousSuitePath . '/' . $ambiguousModuleId);

@mkdir($dupSuitePath, 0775, true);
@mkdir($dupGroupedPath, 0775, true);
@mkdir($dupRootPath, 0775, true);
file_put_contents($dupGroupedPath . '/module.json', json_encode(['id' => $dupId, 'name' => 'Dup Grouped', 'version' => '1.0.0'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($dupRootPath . '/module.json', json_encode(['id' => $dupId, 'name' => 'Dup Root', 'version' => '1.0.0'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$allModules = discoverModules();
ok('duplicate id across root and grouped paths registers one module id', isset($allModules[$dupId]) && count(array_filter(array_keys($allModules), static fn(string $id): bool => $id === $dupId)) === 1);

@mkdir($movedBucketPath, 0775, true);
if (is_dir($rootModulePath) && !is_dir($movedRootPath)) {
    @rename($rootModulePath, $movedRootPath);
}
$movedModules = discoverModules();
$movedResolved = modulePathForId($rootModuleId);
ok('discoverModules still finds module after moving into nested folder', isset($movedModules[$rootModuleId]));
ok('modulePathForId resolves moved module nested path', is_string($movedResolved) && $movedResolved === realpath($movedRootPath));
ok('moved module templates remain under templates/modules/<id>', is_file($rootTemplatePath . '/pages/home.disyl'));

// cleanup grouped-path temporary artifacts
foreach (glob(__DIR__ . '/../tests/' . $groupedModuleId . '_*') ?: [] as $generatedTest) {
    @unlink($generatedTest);
}
foreach (glob(__DIR__ . '/../tests/' . $explicitModuleId . '_*') ?: [] as $generatedTest) {
    @unlink($generatedTest);
}
@unlink(__DIR__ . '/../tests/' . $rootModuleId . '_module_test.php');
@unlink($zipPath);
@unlink($explicitZipPath);

foreach ([
    $movedRootPath,
    $movedBucketPath,
    $rootModulePath,
    $rootTemplatePath,
    $groupedModulePath,
    $groupedTemplatePath,
    $groupedFlatPath,
    $explicitModulePath,
    $explicitTemplatePath,
    $explicitFlatPath,
    $ownedChildRootPath,
    $dupGroupedPath,
    $dupRootPath,
] as $p) {
    $rrmdir($p);
}

if (is_file($ownedSuiteModuleManifest)) {
    @unlink($ownedSuiteModuleManifest);
}
if (is_dir($ownedSuitePath) && !is_file($ownedSuiteModuleManifest)) {
    $rrmdir($ownedSuitePath);
}
if (is_dir($ambiguousSuitePath) && !is_file($ambiguousSuitePath . '/module.json')) {
    $rrmdir($ambiguousSuitePath);
}
if (is_dir($dupSuitePath) && !is_file($dupSuitePath . '/module.json')) {
    $rrmdir($dupSuitePath);
}
if (is_dir($groupSuitePath) && !is_file($groupSuitePath . '/module.json')) {
    $rrmdir($groupSuitePath);
}
if (is_dir($explicitSuitePath) && !is_file($explicitSuitePath . '/module.json')) {
    $rrmdir($explicitSuitePath);
}

// ── 5. TENANT RESOLVER ──────────────────────────────────────────
heading('TenantResolver — Disabled (default)');
$tr = new TenantResolver(['enabled' => false]);
ok('disabled returns null', $tr->resolve() === null);
ok('isEnabled false', !$tr->isEnabled());

heading('TenantResolver — Enabled + JWT strategy');
$tr2 = new TenantResolver(['enabled' => true, 'strategy' => 'jwt']);
$tid = $tr2->resolve(['tenant_id' => 42, 'role' => 'admin']);
ok('JWT resolve', $tid === 42);
ok('current() cached', $tr2->current() === 42);

$tr2->reset();
$tr2->setTenantId(99);
ok('setTenantId override', $tr2->current() === 99);
ok('column default', $tr2->column() === 'tenant_id');

heading('TenantResolver — Enabled + host strategy');
$_SERVER['HTTP_HOST'] = 'guidance.client-domain.test';
$tr3 = new TenantResolver([
    'enabled' => true,
    'strategy' => 'host',
    'host_map' => [
        'guidance.client-domain.test' => 7,
        'ledger.client-domain.test' => 8,
    ],
]);
ok('host resolve', $tr3->resolve() === 7);

$tr4 = new TenantResolver([
    'enabled' => true,
    'strategy' => 'host',
    'host_map' => [
        'guidance.client-domain.test' => 7,
    ],
]);
$_SERVER['HTTP_HOST'] = 'guidance.client-domain.test:8080';
ok('host resolve strips port', $tr4->resolve() === 7);

heading('TenantResolver — Enabled + control_host strategy');
// Seed a tenant + domain mapping into control plane
$cpdo->exec("INSERT IGNORE INTO kernel_tenants (id, tenant_key, status) VALUES (200, 't200', 'active')");
$cpdo->exec("INSERT IGNORE INTO kernel_tenant_domains (tenant_id, domain) VALUES (200, 'tenant200.test')");
$_SERVER['HTTP_HOST'] = 'tenant200.test';
$tr5 = new TenantResolver(['enabled' => true, 'strategy' => 'control_host']);
ok('control_host resolve', $tr5->resolve() === 200);

heading('TenantResolver — QueryBuilder auto-scope');
$pdo->exec("CREATE TEMPORARY TABLE _tenant_test (id INT PRIMARY KEY, tenant_id INT, name VARCHAR(30))");
$pdo->exec("INSERT INTO _tenant_test VALUES (1,10,'A'),(2,10,'B'),(3,20,'C')");

$scopedQb = new QueryBuilder($pdo, 10);
$rows = $scopedQb->table('_tenant_test')->get();
ok('scoped SELECT returns tenant rows only', count($rows) === 2);
foreach ($rows as $r) { ok("row tenant_id=10", (int)$r['tenant_id'] === 10); }

$allRows = $scopedQb->table('_tenant_test')->unscoped()->get();
ok('unscoped returns all rows', count($allRows) === 3);

// Scoped insert auto-injects tenant_id
try {
    $pdo->exec("CREATE TEMPORARY TABLE _tenant_ins (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, name VARCHAR(30))");
    $scopedQb->table('_tenant_ins')->insert(['name' => 'X']);
    $inserted = $pdo->query("SELECT * FROM _tenant_ins")->fetch(PDO::FETCH_ASSOC);
    ok('insert auto-injects tenant_id', (int)($inserted['tenant_id'] ?? 0) === 10);
} catch (\Throwable $e) {
    ok('insert auto-injects tenant_id (error: '.$e->getMessage().')', false);
}

heading('ConnectionPool — tenant scoped names');
// Enable multi-tenant mode for this test.
$GLOBALS['_test_prev_mt'] = config('app.multi_tenant', []);
$GLOBALS['_test_prev_mt_enabled'] = $_ENV['APP_MULTI_TENANT_ENABLED'] ?? null;
$_ENV['APP_MULTI_TENANT_ENABLED'] = '1';

// Force tenant resolver to a specific tenant id.
app()->tenant()->setTenantId(10);
$pool = new ConnectionPool();
$pool->register('reporting', ['host' => 'localhost', 'database' => 'db10', 'username' => 'u', 'password' => 'p']);
ok('has reporting in tenant 10', $pool->has('reporting'));

app()->tenant()->setTenantId(20);
ok('reporting not registered in tenant 20 yet', !$pool->has('reporting'));
$pool->register('reporting', ['host' => 'localhost', 'database' => 'db20', 'username' => 'u', 'password' => 'p']);
ok('has reporting in tenant 20 after register', $pool->has('reporting'));

// Restore env
if ($GLOBALS['_test_prev_mt_enabled'] === null) {
    unset($_ENV['APP_MULTI_TENANT_ENABLED']);
} else {
    $_ENV['APP_MULTI_TENANT_ENABLED'] = (string)$GLOBALS['_test_prev_mt_enabled'];
}

// ── SUMMARY ──────────────────────────────────────────────────────
echo "\n\033[1m  ════════════════════════════════════════\033[0m\n";
echo "  \033[32m{$pass} passed\033[0m";
if ($fail > 0) echo ", \033[31m{$fail} failed\033[0m";
echo "  (total " . ($pass + $fail) . ")\n\n";
exit($fail > 0 ? 1 : 0);
