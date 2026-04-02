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
        (2, 'clientsite',    'active', 'cms')"
);

// ── Domain mapping ────────────────────────────────────────────────────────────
$pdo->exec(
    "INSERT IGNORE INTO kernel_tenant_domains (tenant_id, domain) VALUES (2, 'clientsite.test')"
);

// ── DB connections (encrypted passwords) ─────────────────────────────────────
$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbUser = $_ENV['DB_USERNAME'] ?? 'root';
$dbPass = $_ENV['DB_PASSWORD'] ?? 'ci_root_pass';
$baronDb = $_ENV['DB_DATABASE'] ?? 'baronbakeshop_ci';
$clientDb = 'clientsite_ci';

$enc1 = $crypto->encryptString($dbPass);
$enc2 = $crypto->encryptString($dbPass);

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

echo "CI tenant seed complete.\n";
echo "  baronbakeshop  (id=1, entry=daily-ledger) → {$baronDb}\n";
echo "  clientsite     (id=2, entry=cms)          → {$clientDb}\n";
