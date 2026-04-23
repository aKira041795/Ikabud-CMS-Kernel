<?php

declare(strict_types=1);

function cmsUserServicesTable(): string
{
    return 'cms_user_services';
}

function cmsUserServicesTableExists(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $stmt = cmsDb()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => cmsUserServicesTable()]);
        $cache = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function cmsUserServiceBindings(int $userId): array
{
    if ($userId <= 0 || !cmsUserServicesTableExists()) {
        return [];
    }

    try {
        $stmt = cmsDb()->prepare(
            'SELECT service_key, is_primary, metadata_json, created_at, updated_at
             FROM ' . cmsUserServicesTable() . '
             WHERE user_id = :user_id
             ORDER BY is_primary DESC, service_key ASC'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function cmsPrimaryUserService(int $userId): ?array
{
    foreach (cmsUserServiceBindings($userId) as $binding) {
        if (!empty($binding['is_primary'])) {
            return $binding;
        }
    }

    $bindings = cmsUserServiceBindings($userId);
    return $bindings[0] ?? null;
}

function cmsUserHasService(int $userId, string $serviceKey): bool
{
    $serviceKey = trim($serviceKey);
    if ($userId <= 0 || $serviceKey === '' || !cmsUserServicesTableExists()) {
        return false;
    }

    try {
        $stmt = cmsDb()->prepare(
            'SELECT COUNT(*) FROM ' . cmsUserServicesTable() . ' WHERE user_id = :user_id AND service_key = :service_key'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':service_key' => $serviceKey,
        ]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cmsAssignUserService(int $userId, string $serviceKey, bool $isPrimary = false, array $metadata = []): bool
{
    $serviceKey = trim($serviceKey);
    if ($userId <= 0 || $serviceKey === '' || !cmsUserServicesTableExists()) {
        return false;
    }

    $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES);
    if ($metadataJson === false) {
        $metadataJson = null;
    }

    try {
        $db = cmsDb();
        $db->beginTransaction();

        if ($isPrimary) {
            $clear = $db->prepare('UPDATE ' . cmsUserServicesTable() . ' SET is_primary = 0 WHERE user_id = :user_id');
            $clear->execute([':user_id' => $userId]);
        }

        $stmt = $db->prepare(
            'INSERT INTO ' . cmsUserServicesTable() . ' (user_id, service_key, is_primary, metadata_json, created_at, updated_at)
             VALUES (:user_id, :service_key, :is_primary, :metadata_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_primary = VALUES(is_primary),
                metadata_json = COALESCE(VALUES(metadata_json), metadata_json),
                updated_at = NOW()'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':service_key' => $serviceKey,
            ':is_primary' => $isPrimary ? 1 : 0,
            ':metadata_json' => $metadataJson,
        ]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        try {
            if (cmsDb()->inTransaction()) {
                cmsDb()->rollBack();
            }
        } catch (Throwable $rollbackError) {
        }
        return false;
    }
}

function cmsDetectPrimaryUserService(int $userId): ?string
{
    $binding = cmsPrimaryUserService($userId);
    if (!is_array($binding)) {
        return null;
    }

    $serviceKey = trim((string)($binding['service_key'] ?? ''));
    return $serviceKey !== '' ? $serviceKey : null;
}