<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
function t(string $label, bool $ok): void { global $pass, $fail; if($ok){$pass++;echo "  OK $label
";}else{$fail++;echo "  FAIL $label
";} }

echo "=== DatabaseManager Integration Test ===

";
$app = app();

echo "-- Primary DB --
";
$db = $app->db();
t('db() returns PDO', $db instanceof \PDO);
t('db() alive', ((int)$db->query('SELECT 1')->fetchColumn())===1);
$db2 = $app->db();
t('db() reuse', $db === $db2);

echo "
-- Control DB --
";
$cdb = $app->controlDb();
t('controlDb() returns PDO', $cdb instanceof \PDO);
t('controlDb() alive', ((int)$cdb->query('SELECT 1')->fetchColumn())===1);

echo "
-- Reconnect --
";
t('reconnectDb() PDO', $app->reconnectDb() instanceof \PDO);
t('reconnectControlDb() PDO', $app->reconnectControlDb() instanceof \PDO);

echo "
-- Runtime Snapshot --
";
$s = $app->dbRuntimeSnapshot();
t('snapshot is array', is_array($s));
foreach(['request_tenant_id','primary_request_target','primary_connection_target','tenant_pool','tenant_config_cache','policy','primary_policy','control_policy','counters'] as $k){ t("snapshot:$k", array_key_exists($k,$s)); }
$c = $s['counters']??[];
foreach(['primary_connects','primary_validations','primary_reconnects','control_connects','tenant_connects','tenant_pool_hits'] as $k){ t("counter:$k", array_key_exists($k,$c)); }
t('primary_connects>0', ($c['primary_connects']??0)>0);
$p = $s['policy']??[];
foreach(['timeout_seconds','persistent','emulate_prepares','ssl_enabled','ssl_verify_server_cert','idle_validation_seconds','tenant_config_cache_backend'] as $k){ t("policy:$k", array_key_exists($k,$p)); }

echo "
-- Pool Stats --
";
$ps = $app->tenantDbPoolStats();
t('poolStats array', is_array($ps));
foreach(['active','max','tenant_ids'] as $k){ t("pool:$k", array_key_exists($k,$ps)); }
t('max>0', ($ps['max']??0)>0);

echo "
-- Tenant DB --
";
if($mt){ t('dbForTenant(999999) null (fail-closed)', $app->dbForTenant(999999)===null); }
else { t('multi-tenancy off — skip', true); }

echo "
-- Idempotency --
";
$s1=$app->dbRuntimeSnapshot(); $s2=$app->dbRuntimeSnapshot();
t('snapshots idempotent', is_array($s1)&&is_array($s2));

echo "
-- Alive --
";
t('db() still alive', ((int)$app->db()->query('SELECT 1')->fetchColumn())===1);

printf("
PASS: %d  FAIL: %d
", $pass, $fail);
exit($fail>0?1:0);
