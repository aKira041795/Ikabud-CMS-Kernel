<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/**
 * Ensure the kernel `users` table exists in a tenant database (idempotent), and
 * optionally seed it with the base kernel users.
 *
 * Auth-owned module tenants (e.g. daily-ledger) keep their application users in
 * their own table (dl_users) and do not provision the shared kernel `users`
 * table — yet the module's admin views resolve kernel actor names by reading
 * `users`. This script brings such a tenant in line with the other tenants
 * (which all carry `users`) so kernel actor names resolve and kernel auth works.
 *
 * Safe to re-run: CREATE TABLE IF NOT EXISTS; seeding only happens when the
 * table is empty. The app code is additionally guarded (dl_tableExists), so
 * this script is an enhancement, not a prerequisite for the app to run.
 *
 * Usage:
 *   php scripts/ensure-tenant-kernel-users.php --tenant=ID [--seed] [--db=NAME]
 *
 * Options:
 *   --tenant=ID   Target tenant id (resolved via app()->dbForTenant()).
 *   --db=NAME     Alternatively, target a database by name directly.
 *   --seed        Copy the base kernel users (admin/superadmin) when the target
 *                 `users` table is empty, matching sibling tenants.
 *   --help        Show this message.
 */

function ensureKernelUsersUsage(): void
{
    echo "Ensure Tenant Kernel Users Table\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/ensure-tenant-kernel-users.php --tenant=ID [--seed] [--db=NAME]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --tenant=ID   Target tenant id.\n";
    echo "  --db=NAME     Target a database by name directly (alternative to --tenant).\n";
    echo "  --seed        Seed base kernel users into an empty `users` table.\n";
    echo "  --help        Show this message.\n";
}

function ensureKernelUsersArg(?string $prefix): ?string
{
    if ($prefix === null) {
        return null;
    }
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

$argvList = $_SERVER['argv'] ?? [];
if (in_array('--help', $argvList, true)) {
    ensureKernelUsersUsage();
    exit(0);
}

$tenantId = (int)(ensureKernelUsersArg('--tenant=') ?? 0);
$dbName = (string)(ensureKernelUsersArg('--db=') ?? '');
$seed = in_array('--seed', $argvList, true);

if ($tenantId <= 0 && $dbName === '') {
    ensureKernelUsersUsage();
    fwrite(STDERR, "\nMissing --tenant=ID or --db=NAME\n");
    exit(1);
}

// ── Canonical kernel `users` schema (mirrors the base/install users table) ──
$usersDdl = <<<'SQL'
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','superadmin','manager','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `token_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

try {
    // ── Resolve the target connection ──────────────────────────────────
    if ($dbName !== '') {
        $cfg = require CONFIG_PATH . '/database.php';
        $target = new PDO(
            "mysql:host={$cfg['host']};dbname={$dbName};charset=utf8mb4",
            $cfg['username'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        echo "Target: db={$dbName}\n";
    } else {
        $target = app()->dbForTenant($tenantId);
        if (!$target instanceof PDO) {
            fwrite(STDERR, "Unable to resolve tenant DB for tenant {$tenantId}\n");
            exit(1);
        }
        echo "Target: tenant={$tenantId} db=" . $target->query('SELECT DATABASE()')->fetchColumn() . "\n";
    }

    // ── Ensure the table exists ────────────────────────────────────────
    $target->exec($usersDdl);
    echo "  ✓ users table ensured\n";

    $userCount = (int)$target->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "  users rows: {$userCount}\n";

    // ── Seed base kernel users when requested and the table is empty ───
    if ($seed && $userCount === 0) {
        $baseCfg = require CONFIG_PATH . '/database.php';
        $base = new PDO(
            "mysql:host={$baseCfg['host']};dbname={$baseCfg['database']};charset=utf8mb4",
            $baseCfg['username'],
            $baseCfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $baseHasUsers = $base->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if (!$baseHasUsers) {
            echo "  ! base has no users table; skipping seed (empty users table is fine)\n";
        } else {
            $baseUsers = $base->query('SELECT id, username, email, password_hash, full_name, role, is_active, created_at, updated_at, token_version FROM users')->fetchAll();
            $ins = $target->prepare(
                'INSERT IGNORE INTO users
                    (id, username, email, password_hash, full_name, role, is_active, created_at, updated_at, token_version)
                 VALUES (:id, :u, :e, :p, :f, :r, :a, :c, :u2, :tv)'
            );
            $seeded = 0;
            foreach ($baseUsers as $row) {
                $ins->execute([
                    ':id' => (int)$row['id'],
                    ':u' => $row['username'],
                    ':e' => $row['email'] ?? null,
                    ':p' => $row['password_hash'],
                    ':f' => $row['full_name'],
                    ':r' => $row['role'],
                    ':a' => (int)$row['is_active'],
                    ':c' => $row['created_at'],
                    ':u2' => $row['updated_at'],
                    ':tv' => (int)($row['token_version'] ?? 0),
                ]);
                $seeded++;
            }
            echo "  ✓ seeded {$seeded} kernel user(s) from the base DB\n";
            echo "  users rows now: " . (int)$target->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
        }
    } elseif ($seed && $userCount > 0) {
        echo "  ! users table already has {$userCount} row(s); seed skipped (idempotent)\n";
    }

    echo "\nDone. The admin views now resolve kernel actor names; the app is guarded either way.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n");
    exit(1);
}
