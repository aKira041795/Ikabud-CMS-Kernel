CREATE TABLE IF NOT EXISTS `kernel_jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `queue` varchar(64) NOT NULL DEFAULT 'default',
    `handler` varchar(255) NOT NULL,
    `payload_json` longtext NOT NULL,
    `attempts` int unsigned NOT NULL DEFAULT 0,
    `max_attempts` int unsigned NOT NULL DEFAULT 3,
    `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reserved_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `failed_at` datetime DEFAULT NULL,
    `error` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_kernel_jobs_queue_available` (`queue`, `available_at`, `reserved_at`),
    KEY `idx_kernel_jobs_reserved` (`reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_failed_jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `queue` varchar(64) NOT NULL DEFAULT 'default',
    `handler` varchar(255) NOT NULL,
    `payload_json` longtext NOT NULL,
    `attempts` int unsigned NOT NULL DEFAULT 0,
    `failed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `error` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_kernel_failed_jobs_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
