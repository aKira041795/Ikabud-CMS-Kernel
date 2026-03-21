<?php

declare(strict_types=1);

/**
 * Baron Bakeshop Daily Ledger — Web Installer
 *
 * Creates database, runs schema migration, seeds admin user + branch,
 * generates secure .env, and locks itself.
 *
 * SECURITY: Delete this file after installation.
 */

// ── Guard: already installed ────────────────────────────────────────────
$installLock = __DIR__ . '/../storage/.installed';
if (is_file($installLock) && !isset($_GET['force'])) {
    http_response_code(403);
    die('System already installed. Remove storage/.installed to reinstall.');
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'install') {

    // ── Collect & validate input ────────────────────────────────────────
    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbName = trim((string) ($_POST['db_name'] ?? 'baronbakeshop'));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');

    $adminUsername = trim((string) ($_POST['admin_username'] ?? 'admin'));
    $adminName     = trim((string) ($_POST['admin_name'] ?? 'Administrator'));
    $adminPass     = (string) ($_POST['admin_pass'] ?? '');

    if ($dbName === '')                $errors[] = 'Database name is required.';
    if ($dbUser === '')                $errors[] = 'Database username is required.';
    if ($adminUsername === '')          $errors[] = 'Admin username is required.';
    if ($adminName === '')             $errors[] = 'Admin full name is required.';
    if (strlen($adminPass) < 8)        $errors[] = 'Admin password must be at least 8 characters.';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $adminUsername))
                                       $errors[] = 'Admin username may only contain letters, numbers, underscore.';

    if ($errors === []) {
        try {
            // ── Connect directly to the target database (Bluehost: DB is pre-created) ──
            $dsnDb = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbHost,
                $dbPort,
                $dbName
            );
            $pdo = new PDO($dsnDb, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // ── Run schema migration (statement-by-statement for shared hosts) ──
            $schemaFile = __DIR__ . '/../database/migrations/001_full_schema.sql';
            if (!is_file($schemaFile)) {
                throw new RuntimeException('Schema file not found: database/migrations/001_full_schema.sql');
            }

            $schemaSql = file_get_contents($schemaFile);
            // Strip comments and split by semicolons
            $schemaSql = preg_replace('/--.*$/m', '', $schemaSql);
            $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }

            // ── Seed admin user + default branch ────────────────────────
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT INTO branches (code, name, is_active) VALUES ('MAIN', 'Main Branch', 1)
                            ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1");

                $branchId    = (int) $pdo->query("SELECT id FROM branches WHERE code = 'MAIN' LIMIT 1")->fetchColumn();
                $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT, ['cost' => 12]);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, full_name, role, is_active)
                     VALUES (:username, :password_hash, :full_name, :role, 1)
                     ON DUPLICATE KEY UPDATE
                        password_hash = VALUES(password_hash),
                        full_name     = VALUES(full_name),
                        role          = VALUES(role),
                        is_active     = 1'
                );
                $stmt->execute([
                    ':username'      => $adminUsername,
                    ':password_hash' => $passwordHash,
                    ':full_name'     => $adminName,
                    ':role'          => 'admin',
                ]);

                $userIdStmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                $userIdStmt->execute([':u' => $adminUsername]);
                $userId = (int) $userIdStmt->fetchColumn();

                $pdo->prepare(
                    'INSERT INTO user_branches (user_id, branch_id) VALUES (:uid, :bid)
                     ON DUPLICATE KEY UPDATE branch_id = VALUES(branch_id)'
                )->execute([':uid' => $userId, ':bid' => $branchId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // ── Generate .env with secure JWT secret ────────────────────
            $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // Detect base path (Bluehost subdirectory installs)
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $basePath  = rtrim(dirname($scriptDir), '/');
            if ($basePath === '.' || $basePath === '/' || $basePath === '') {
                $basePath = '';
            }
            $appUrl    = "{$scheme}://{$host}{$basePath}";
            $jwtSecret = bin2hex(random_bytes(32));

            $env = "APP_ENV=production\n"
                 . "APP_DEBUG=0\n"
                 . "APP_URL={$appUrl}\n"
                 . "APP_TIMEZONE=Asia/Manila\n"
                 . "APP_COOKIE_NAME=baroninventory_token\n"
                 . "APP_COOKIE_SAMESITE=Lax\n\n"
                 . "DB_HOST={$dbHost}\n"
                 . "DB_PORT={$dbPort}\n"
                 . "DB_DATABASE={$dbName}\n"
                 . "DB_USERNAME={$dbUser}\n"
                 . "DB_PASSWORD={$dbPass}\n"
                 . "DB_COLLATION=utf8mb4_unicode_ci\n\n"
                 . "JWT_SECRET={$jwtSecret}\n"
                 . "JWT_EXPIRATION=86400\n";

            file_put_contents(__DIR__ . '/../.env', $env, LOCK_EX);
            @chmod(__DIR__ . '/../.env', 0600);

            // ── Create storage dirs ─────────────────────────────────────
            $dirs = [
                __DIR__ . '/../storage',
                __DIR__ . '/../storage/cache',
                __DIR__ . '/../storage/cache/disyl',
                __DIR__ . '/../storage/logs',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
            }

            // ── Write install lock ──────────────────────────────────────
            file_put_contents($installLock, date('Y-m-d H:i:s') . "\n");

            // Flush code caches so the fresh install is picked up immediately
            clearstatcache(true);
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            $success = true;

        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baron Bakeshop — Installer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',system-ui,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:440px;padding:32px}
        h1{font-size:20px;font-weight:700;color:#1a202c}
        h1 span{color:#2563eb}
        .sub{font-size:13px;color:#718096;margin-top:2px;margin-bottom:24px}
        .form-group{margin-bottom:14px}
        .form-label{display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px}
        .form-input{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit}
        .form-input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px #dbeafe}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        hr{border:none;border-top:1px solid #e2e8f0;margin:18px 0}
        .btn{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
        .btn:hover{background:#1d4ed8}
        .alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
        .alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
        .alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
        .alert ul{margin:0;padding-left:18px}
        .warn{font-size:11px;color:#d97706;margin-top:12px;padding:8px 10px;background:#fffbeb;border-radius:6px;border:1px solid #fef3c7}
        a.open{display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;margin-top:12px}
    </style>
</head>
<body>
<div class="card">
    <h1><span>Baron</span> Bakeshop</h1>
    <p class="sub">Daily Ledger System — Installer</p>

    <?php if ($success): ?>
        <div class="alert alert-success">
            Installation complete!
        </div>
        <div class="warn">
            <strong>Security:</strong> Delete <code>public/lock.php</code> immediately after verifying the app works.
        </div>
        <br>
        <a class="open" href="/">Open App &rarr;</a>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="step" value="install">

            <div class="form-group">
                <label class="form-label">Database Host</label>
                <div class="row">
                    <input name="db_host" class="form-input" placeholder="localhost" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8') ?>">
                    <input name="db_port" class="form-input" placeholder="3306" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Database Name</label>
                <input name="db_name" class="form-input" placeholder="baronbakeshop" value="<?= htmlspecialchars($_POST['db_name'] ?? 'baronbakeshop', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Database Username</label>
                <input name="db_user" class="form-input" value="<?= htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Database Password</label>
                <input type="password" name="db_pass" class="form-input">
            </div>

            <hr>

            <div class="form-group">
                <label class="form-label">Admin Username</label>
                <input name="admin_username" class="form-input" value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Admin Full Name</label>
                <input name="admin_name" class="form-input" value="<?= htmlspecialchars($_POST['admin_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Admin Password (min 8 chars)</label>
                <input type="password" name="admin_pass" class="form-input">
            </div>

            <button type="submit" class="btn">Install</button>
            <div class="warn">
                <strong>Bluehost / cPanel:</strong> Use <code>localhost</code> as DB host. Create the database via cPanel first if CREATE DATABASE fails.
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
