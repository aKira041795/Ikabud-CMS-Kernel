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
