<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use PDO;

/**
 * ArtifactRegistry — governed registration for global theme/profile artifacts.
 *
 * Enforces the digest-conflict contract from the CMS Akira registry ownership
 * decision (.ai/ark-registry-ownership-contract.md):
 *
 *   - Registration is IDEMPOTENT by (artifact_type, name, version,
 *     canonical_digest): an identical identity + digest is a no-op.
 *   - Same identity + DIFFERENT digest → explicit CONFLICT (never a silent
 *     overwrite).
 *   - Concurrency: a MySQL advisory lock serializes check-then-insert, and the
 *     UNIQUE INDEX on (artifact_type, name, version) is the deterministic
 *     winner. A loser of a concurrent duplicate sees either an idempotent
 *     no-op (same digest) or an explicit CONFLICT (different digest) — never a
 *     silently chosen winner.
 *
 * Works against both `kernel_application_profile_registry` (base/control DB)
 * and `cms_theme_registry` (tenant DB) — they share an identical shape.
 *
 * @package Ikabud\Kernel\Services
 */
class ArtifactRegistry
{
    /**
     * Register an artifact, returning the outcome.
     *
     * @param PDO    $db           Connection hosting the registry table.
     * @param string $table        Table name (kernel_application_profile_registry | cms_theme_registry).
     * @param string $artifactType 'theme' | 'profile'
     * @param string $name         Artifact name (e.g. 'ark').
     * @param string $version      Semantic version (e.g. '3.0.0').
     * @param string $canonicalDigest sha256 hex digest of canonical manifest JSON.
     * @param string $manifestPath Absolute path to the manifest file.
     * @return array{status: 'registered'|'idempotent'|'conflict'|'error', id?: int, digest?: string, message?: string}
     */
    public static function register(
        PDO $db,
        string $table,
        string $artifactType,
        string $name,
        string $version,
        string $canonicalDigest,
        string $manifestPath
    ): array {
        $artifactType = strtolower(trim($artifactType));
        if (!in_array($artifactType, ['theme', 'profile'], true)) {
            return ['status' => 'error', 'message' => 'artifact_type must be theme|profile'];
        }
        if ($name === '' || $version === '' || strlen($canonicalDigest) !== 64) {
            return ['status' => 'error', 'message' => 'name/version required; digest must be sha256 hex'];
        }
        $table = self::sanitizeTable($table);

        $lockName = 'ikabud_registry_' . $artifactType;
        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = (int) $lockStmt->fetchColumn();
        $lockStmt->closeCursor();

        if ($lockAcquired !== 1) {
            return ['status' => 'error', 'message' => 'Could not acquire registry advisory lock'];
        }

        try {
            return self::registerLocked($db, $table, $artifactType, $name, $version, $canonicalDigest, $manifestPath);
        } finally {
            $rel = $db->query('SELECT RELEASE_LOCK(' . $db->quote($lockName) . ')');
            if ($rel) {
                $rel->fetchColumn();
                $rel->closeCursor();
            }
        }
    }

    /**
     * Registration body — caller must hold the advisory lock.
     */
    private static function registerLocked(
        PDO $db,
        string $table,
        string $artifactType,
        string $name,
        string $version,
        string $canonicalDigest,
        string $manifestPath
    ): array {
        // Existing row?
        $stmt = $db->prepare(
            "SELECT id, canonical_digest FROM {$table}
             WHERE artifact_type = :t AND name = :n AND version = :v LIMIT 1"
        );
        $stmt->execute([':t' => $artifactType, ':n' => $name, ':v' => $version]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            if (hash_equals((string)$existing['canonical_digest'], $canonicalDigest)) {
                return [
                    'status' => 'idempotent',
                    'id' => (int)$existing['id'],
                    'digest' => (string)$existing['canonical_digest'],
                ];
            }
            return [
                'status' => 'conflict',
                'id' => (int)$existing['id'],
                'digest' => (string)$existing['canonical_digest'],
                'message' => "Registry CONFLICT: {$artifactType}/{$name}@{$version} already registered with a different canonical digest",
            ];
        }

        // Insert. The UNIQUE INDEX is the deterministic winner under concurrency;
        // a duplicate-key here means another connection inserted between our
        // SELECT and INSERT (possible despite the advisory lock only if a
        // different lock name was used) — re-read and classify.
        try {
            $ins = $db->prepare(
                "INSERT INTO {$table}
                    (name, version, artifact_type, canonical_digest, manifest_path)
                 VALUES (:n, :v, :t, :d, :p)"
            );
            $ins->execute([
                ':n' => $name,
                ':v' => $version,
                ':t' => $artifactType,
                ':d' => $canonicalDigest,
                ':p' => $manifestPath,
            ]);
        } catch (\PDOException $e) {
            $mysqlCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            if ($mysqlCode !== 1062) {
                return ['status' => 'error', 'message' => 'Registry insert failed: ' . $e->getMessage()];
            }
            // Duplicate key — another writer won. Classify by digest.
            $stmt = $db->prepare(
                "SELECT id, canonical_digest FROM {$table}
                 WHERE artifact_type = :t AND name = :n AND version = :v LIMIT 1"
            );
            $stmt->execute([':t' => $artifactType, ':n' => $name, ':v' => $version]);
            $winner = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($winner) && hash_equals((string)$winner['canonical_digest'], $canonicalDigest)) {
                return ['status' => 'idempotent', 'id' => (int)$winner['id'], 'digest' => (string)$winner['canonical_digest']];
            }
            return [
                'status' => 'conflict',
                'id' => is_array($winner) ? (int)$winner['id'] : 0,
                'digest' => is_array($winner) ? (string)$winner['canonical_digest'] : '',
                'message' => "Registry CONFLICT: concurrent registration of {$artifactType}/{$name}@{$version} with a different digest",
            ];
        }

        return [
            'status' => 'registered',
            'id' => (int)$db->lastInsertId(),
            'digest' => $canonicalDigest,
        ];
    }

    /**
     * Compute the canonical sha256 digest of a manifest JSON document.
     *
     * Uses a canonical serialization (sorted keys, no whitespace, unescaped
     * slashes/unicode) so the same logical manifest always yields the same
     * digest regardless of source formatting.
     */
    public static function canonicalDigest(array $manifest): string
    {
        $json = self::canonicalJson($manifest);
        return hash('sha256', $json);
    }

    /**
     * Canonical JSON: recursively sort object keys, compact, no slashes/unicode escaping.
     */
    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                $parts = [];
                foreach ($value as $v) {
                    $parts[] = self::canonicalJson($v);
                }
                return '[' . implode(',', $parts) . ']';
            }
            ksort($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string)$k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . ':' . self::canonicalJson($v);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Look up a registered artifact by identity.
     *
     * @return array{id:int,digest:string,manifest_path:string}|null
     */
    public static function find(PDO $db, string $table, string $artifactType, string $name, string $version): ?array
    {
        $table = self::sanitizeTable($table);
        $stmt = $db->prepare(
            "SELECT id, canonical_digest, manifest_path FROM {$table}
             WHERE artifact_type = :t AND name = :n AND version = :v LIMIT 1"
        );
        $stmt->execute([':t' => $artifactType, ':n' => $name, ':v' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'digest' => (string)$row['canonical_digest'],
            'manifest_path' => (string)$row['manifest_path'],
        ];
    }

    /**
     * Hard allowlist of registry table names (SQL-injection guard).
     */
    private static function sanitizeTable(string $table): string
    {
        $allowed = ['kernel_application_profile_registry', 'cms_theme_registry'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported registry table '{$table}'");
        }
        return $table;
    }
}
