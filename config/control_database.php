<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => $_ENV['CONTROL_DB_HOST'] ?? ($_ENV['DB_HOST'] ?? '127.0.0.1'),
    'port' => $_ENV['CONTROL_DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '3306'),
    'database' => $_ENV['CONTROL_DB_DATABASE'] ?? ($_ENV['DB_DATABASE'] ?? 'ikabud'),
    'username' => $_ENV['CONTROL_DB_USERNAME'] ?? ($_ENV['DB_USERNAME'] ?? 'root'),
    'password' => $_ENV['CONTROL_DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? ''),
    'charset' => 'utf8mb4',
    'collation' => $_ENV['CONTROL_DB_COLLATION'] ?? ($_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),
    'timeout_seconds' => max(1, (int)($_ENV['CONTROL_DB_TIMEOUT_SECONDS'] ?? ($_ENV['DB_TIMEOUT_SECONDS'] ?? 5))),
    'persistent' => filter_var($_ENV['CONTROL_DB_PERSISTENT'] ?? ($_ENV['DB_PERSISTENT'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
    'ssl' => [
        'enabled' => filter_var($_ENV['CONTROL_DB_SSL_ENABLED'] ?? ($_ENV['DB_SSL_ENABLED'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
        'ca' => trim((string)($_ENV['CONTROL_DB_SSL_CA'] ?? ($_ENV['DB_SSL_CA'] ?? ''))),
        'cert' => trim((string)($_ENV['CONTROL_DB_SSL_CERT'] ?? ($_ENV['DB_SSL_CERT'] ?? ''))),
        'key' => trim((string)($_ENV['CONTROL_DB_SSL_KEY'] ?? ($_ENV['DB_SSL_KEY'] ?? ''))),
        'verify_server_cert' => filter_var($_ENV['CONTROL_DB_SSL_VERIFY_SERVER_CERT'] ?? ($_ENV['DB_SSL_VERIFY_SERVER_CERT'] ?? 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];
