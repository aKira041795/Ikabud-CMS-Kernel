<?php
/**
 * CI Tenant Seed Script
 *
 * Seeds the control-plane tenant registry for the CI test environment.
 * Requires: control-plane migrations already applied (php ikabud migrate:control).
 *
 * Usage: php database/seeds/ci_tenants.php
 */

declare(strict_types=1);

chdir(dirname(dirname(__DIR__)));
require_once 'bootstrap.php';

$pdo = app()->controlDb();
$crypto = new \Ikabud\Kernel\Crypto();

// ── Tenants ──────────────────────────────────────────────────────────────────
$pdo->exec(
    "INSERT IGNORE INTO kernel_tenants (id, tenant_key, status, entry_module_id) VALUES
        (1, 'baronbakeshop', 'active', 'daily-ledger'),
    (2, 'clientsite',    'active', 'cms'),
    (3, 'healthcare',    'active', 'ehr'),
    -- Aliases expected by tenancy_e2e_test.php (dev-style tenant keys/domains),
    -- pointing at the same CI databases.
    (4, 'baron-001',     'active', 'daily-ledger'),
    (5, 'applicationos', 'active', 'cms'),
    -- Guidance tenant for guidance_*_test.php suites.
    (6, 'guidance',      'active', 'guidance')"
);

// ── Domain mapping ────────────────────────────────────────────────────────────
$pdo->exec(
    "INSERT IGNORE INTO kernel_tenant_domains (tenant_id, domain) VALUES
        (2, 'clientsite.test'),
        (3, 'healthcare.test'),
        (4, 'baronledger.test'),
        (5, 'cmsnew.test'),
        (6, 'guidance.test')"
);

// ── DB connections (encrypted passwords) ─────────────────────────────────────
$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbUser = $_ENV['DB_USERNAME'] ?? 'root';
$dbPass = $_ENV['DB_PASSWORD'] ?? 'ci_root_pass';
$baronDb = $_ENV['DB_DATABASE'] ?? 'baronbakeshop_ci';
$clientDb = 'clientsite_ci';
$healthcareDb = 'healthcare_ci';

$enc1 = $crypto->encryptString($dbPass);
$enc2 = $crypto->encryptString($dbPass);
$enc3 = $crypto->encryptString($dbPass);

$stmt = $pdo->prepare(
    'INSERT IGNORE INTO kernel_tenant_db_connections
        (tenant_id, db_host, db_port, db_name, db_user, db_pass, db_charset,
         db_pass_ciphertext, db_pass_iv, db_pass_tag)
     VALUES (:tid, :host, :port, :name, :user, \'\', \'utf8mb4\', :ct, :iv, :tag)'
);

$stmt->execute([
    ':tid'  => 1,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $baronDb,
    ':user' => $dbUser,
    ':ct'   => $enc1['ciphertext'],
    ':iv'   => $enc1['iv'],
    ':tag'  => $enc1['tag'],
]);

$stmt->execute([
    ':tid'  => 2,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $clientDb,
    ':user' => $dbUser,
    ':ct'   => $enc2['ciphertext'],
    ':iv'   => $enc2['iv'],
    ':tag'  => $enc2['tag'],
]);

$stmt->execute([
    ':tid'  => 3,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $healthcareDb,
    ':user' => $dbUser,
    ':ct'   => $enc3['ciphertext'],
    ':iv'   => $enc3['iv'],
    ':tag'  => $enc3['tag'],
]);

// baron-001 → baronbakeshop_ci (daily-ledger alias for tenancy_e2e_test)
$stmt->execute([
    ':tid'  => 4,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $baronDb,
    ':user' => $dbUser,
    ':ct'   => $enc1['ciphertext'],
    ':iv'   => $enc1['iv'],
    ':tag'  => $enc1['tag'],
]);

// applicationos → clientsite_ci (cms alias for tenancy_e2e_test)
$stmt->execute([
    ':tid'  => 5,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $clientDb,
    ':user' => $dbUser,
    ':ct'   => $enc2['ciphertext'],
    ':iv'   => $enc2['iv'],
    ':tag'  => $enc2['tag'],
]);

// guidance → guidance_ci (guidance test suite tenant)
$guidanceDb = 'guidance_ci';
$enc6 = $crypto->encryptString($dbPass);
$stmt->execute([
    ':tid'  => 6,
    ':host' => $dbHost,
    ':port' => $dbPort,
    ':name' => $guidanceDb,
    ':user' => $dbUser,
    ':ct'   => $enc6['ciphertext'],
    ':iv'   => $enc6['iv'],
    ':tag'  => $enc6['tag'],
]);

echo "CI tenant seed complete.\n";
echo "  baronbakeshop  (id=1, entry=daily-ledger) → {$baronDb}\n";
echo "  clientsite     (id=2, entry=cms)          → {$clientDb}\n";
echo "  healthcare     (id=3, entry=ehr)          → {$healthcareDb}\n";
echo "  baron-001      (id=4, entry=daily-ledger) → {$baronDb}  [tenancy_e2e_test]\n";
echo "  applicationos  (id=5, entry=cms)          → {$clientDb}  [tenancy_e2e_test]\n";
echo "  guidance       (id=6, entry=guidance)     → {$guidanceDb}\n";
