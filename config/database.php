<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_DATABASE'] ?? 'baronbakeshop',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
    'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
    'timeout_seconds' => max(1, (int)($_ENV['DB_TIMEOUT_SECONDS'] ?? 5)),
    'persistent' => filter_var($_ENV['DB_PERSISTENT'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    'ssl' => [
        'enabled' => filter_var($_ENV['DB_SSL_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'ca' => trim((string)($_ENV['DB_SSL_CA'] ?? '')),
        'cert' => trim((string)($_ENV['DB_SSL_CERT'] ?? '')),
        'key' => trim((string)($_ENV['DB_SSL_KEY'] ?? '')),
        'verify_server_cert' => filter_var($_ENV['DB_SSL_VERIFY_SERVER_CERT'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
    ],

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];
