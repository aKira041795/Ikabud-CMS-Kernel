<?php
/**
 * CI Kernel Admin Seed
 *
 * Seeds the canonical kernel `admin` user into the primary app database so
 * tests that assert a kernel admin row exists (e.g.
 * tests/kernel_admin_profile_update_test.php) pass in CI, where the app DB is
 * fresh and has no admin user.
 *
 * Idempotent: INSERT IGNORE by unique username. Run AFTER application
 * migrations (the `users` table must exist).
 *
 * Usage: php database/seeds/ci_kernel_admin.php
 */

declare(strict_types=1);

chdir(dirname(dirname(__DIR__)));
require_once 'bootstrap.php';

$db = app()->db();

// Password must be a valid bcrypt hash so profile/password flows work if a
// test exercises them. Same default as ci_clientsite.sql uses.
$hash = password_hash('admin1234', PASSWORD_DEFAULT);

$db->prepare(
    "INSERT IGNORE INTO users (username, email, password_hash, full_name, role, is_active)
     VALUES (:username, :email, :hash, :name, 'admin', 1)"
)->execute([
    ':username' => 'admin',
    ':email'    => 'admin@applicationos.test',
    ':hash'     => $hash,
    ':name'     => 'Admin',
]);

$count = (int)$db->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
echo "Kernel admin seed complete. admin rows: {$count}\n";
