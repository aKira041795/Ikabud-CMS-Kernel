<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => $_ENV['CONTROL_DB_HOST'] ?? ($_ENV['DB_HOST'] ?? '127.0.0.1'),
    'port' => $_ENV['CONTROL_DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '3306'),
    'database' => $_ENV['CONTROL_DB_DATABASE'] ?? ($_ENV['DB_DATABASE'] ?? 'baronbakeshop'),
    'username' => $_ENV['CONTROL_DB_USERNAME'] ?? ($_ENV['DB_USERNAME'] ?? 'root'),
    'password' => $_ENV['CONTROL_DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? ''),
    'charset' => 'utf8mb4',
    'collation' => $_ENV['CONTROL_DB_COLLATION'] ?? ($_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];
