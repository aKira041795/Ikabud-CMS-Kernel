<?php

declare(strict_types=1);

if (!function_exists('durableEventOutboxDdl')) {
    function durableEventOutboxDdl(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS `kernel_durable_event_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(64) NOT NULL,
    `event_id` CHAR(36) NOT NULL,
    `event_name` VARCHAR(190) NOT NULL,
    `idempotency_key` VARCHAR(190) DEFAULT NULL,
    `payload` LONGTEXT DEFAULT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `source` VARCHAR(64) NOT NULL,
    `actor_id` VARCHAR(64) DEFAULT NULL,
    `actor_role` VARCHAR(64) DEFAULT NULL,
    `request_id` VARCHAR(64) DEFAULT NULL,
    `status` ENUM('pending','claimed','processed','failed','dead_letter') NOT NULL DEFAULT 'pending',
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME DEFAULT NULL,
    `lease_owner` VARCHAR(64) DEFAULT NULL,
    `lease_token` CHAR(64) DEFAULT NULL,
    `lease_expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_kernel_durable_event_outbox_tenant_event` (`tenant_id`, `event_id`),
    UNIQUE KEY `uq_kernel_durable_event_outbox_tenant_idempotency` (`tenant_id`, `idempotency_key`),
    KEY `idx_kernel_durable_event_outbox_pending` (`tenant_id`, `status`, `available_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }
}

if (!function_exists('durableEventOutboxUuidV4')) {
    function durableEventOutboxUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('writeDurableEventOutbox')) {
    function writeDurableEventOutbox(PDO $pdo, array $event): int
    {
        $tenantId = trim((string)($event['tenant_id'] ?? ''));
        $eventName = trim((string)($event['event_name'] ?? ''));
        $source = trim((string)($event['source'] ?? ''));

        if ($tenantId === '') {
            throw new InvalidArgumentException('tenant_id is required.');
        }
        if ($eventName === '') {
            throw new InvalidArgumentException('event_name is required.');
        }
        if ($source === '') {
            throw new InvalidArgumentException('source is required.');
        }

        $payloadValue = $event['payload'] ?? [];
        $payloadJson = json_encode($payloadValue);
        $payloadHash = hash('sha256', (string) $payloadJson);
        $payload = array_key_exists('payload', $event) ? (string) $payloadJson : null;

        $stmt = $pdo->prepare(
            'INSERT INTO `kernel_durable_event_outbox` '
            . '(`tenant_id`, `event_id`, `event_name`, `idempotency_key`, `payload`, `payload_hash`, `source`, `actor_id`, `actor_role`, `request_id`, `status`, `attempt_count`, `available_at`, `lease_owner`, `lease_token`, `lease_expires_at`) '
            . 'VALUES (:tenant_id, :event_id, :event_name, :idempotency_key, :payload, :payload_hash, :source, :actor_id, :actor_role, :request_id, :status, :attempt_count, :available_at, :lease_owner, :lease_token, :lease_expires_at)'
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':event_id' => trim((string)($event['event_id'] ?? '')) !== '' ? (string)$event['event_id'] : durableEventOutboxUuidV4(),
            ':event_name' => $eventName,
            ':idempotency_key' => isset($event['idempotency_key']) && trim((string)$event['idempotency_key']) !== '' ? (string)$event['idempotency_key'] : null,
            ':payload' => $payload,
            ':payload_hash' => $payloadHash,
            ':source' => $source,
            ':actor_id' => isset($event['actor_id']) && trim((string)$event['actor_id']) !== '' ? (string)$event['actor_id'] : null,
            ':actor_role' => isset($event['actor_role']) && trim((string)$event['actor_role']) !== '' ? (string)$event['actor_role'] : null,
            ':request_id' => isset($event['request_id']) && trim((string)$event['request_id']) !== '' ? (string)$event['request_id'] : null,
            ':status' => isset($event['status']) && trim((string)$event['status']) !== '' ? (string)$event['status'] : 'pending',
            ':attempt_count' => isset($event['attempt_count']) ? (int)$event['attempt_count'] : 0,
            ':available_at' => isset($event['available_at']) && trim((string)$event['available_at']) !== '' ? (string)$event['available_at'] : null,
            ':lease_owner' => isset($event['lease_owner']) && trim((string)$event['lease_owner']) !== '' ? (string)$event['lease_owner'] : null,
            ':lease_token' => isset($event['lease_token']) && trim((string)$event['lease_token']) !== '' ? (string)$event['lease_token'] : null,
            ':lease_expires_at' => isset($event['lease_expires_at']) && trim((string)$event['lease_expires_at']) !== '' ? (string)$event['lease_expires_at'] : null,
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('durableEventOutboxFind')) {
    function durableEventOutboxFind(PDO $pdo, string $tenantId, string $eventId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM `kernel_durable_event_outbox` WHERE `tenant_id` = :tenant_id AND `event_id` = :event_id LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':event_id' => $eventId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
