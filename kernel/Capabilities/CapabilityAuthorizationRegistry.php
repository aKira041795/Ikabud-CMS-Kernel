<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

use PDO;
use Throwable;

final class CapabilityAuthorizationRegistry
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $policyCache = [];
    /** @var array<string, int|null> */
    private static array $activeVersionCache = [];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    public static function invalidate(): void
    {
        self::$policyCache = [];
        self::$activeVersionCache = [];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function authorize(array $ctx): array
    {
        $capabilityId = trim((string)($ctx['capability_id'] ?? ''));
        $capabilityVersion = trim((string)($ctx['capability_version'] ?? ''));
        $provider = trim((string)($ctx['provider'] ?? $ctx['explicit_provider'] ?? ''));
        $callerModule = trim((string)($ctx['caller_module'] ?? ''));
        $actorRole = trim((string)($ctx['actor_role'] ?? ''));
        $tenantId = trim((string)($ctx['tenant_id'] ?? ''));
        $providerActivation = (bool)($ctx['provider_activation'] ?? false);
        $explicitProvider = trim((string)($ctx['explicit_provider'] ?? ''));
        $override = isset($ctx['policy_version']) && $ctx['policy_version'] !== '' ? (int)$ctx['policy_version'] : null;

        $result = [
            'allowed' => false,
            'policy_version' => null,
            'reason' => 'missing_policy_row',
            'capability_id' => $capabilityId,
            'capability_version' => $capabilityVersion,
            'provider' => $provider,
            'caller_module' => $callerModule,
            'actor_role' => $actorRole,
            'tenant_id' => $tenantId,
        ];

        try {
            if ($capabilityId === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_capability_id']), 'warning');
            }
            if ($capabilityVersion === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_capability_version']), 'warning');
            }
            if ($provider === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_provider']), 'warning');
            }
            if ($callerModule === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_caller_module']), 'warning');
            }
            if ($actorRole === '') {
                return $this->audit(array_merge($result, ['reason' => 'unknown_role']), 'warning');
            }
            if ($tenantId === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_tenant']), 'warning');
            }
            if ($explicitProvider !== '' && $explicitProvider !== $provider) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }

            $policyVersion = $this->resolvePolicyVersion($override);
            $result['policy_version'] = $policyVersion;
            if ($policyVersion === null) {
                return $this->audit(array_merge($result, ['reason' => $override !== null ? 'inactive_policy_version_override' : 'missing_active_policy_version']), 'warning');
            }

            $rows = $this->rowsForVersion($policyVersion);
            $capabilityRows = [];
            $providerRows = [];
            $exactRow = null;
            foreach ($rows as $row) {
                if ((string)($row['capability_id'] ?? '') !== $capabilityId) {
                    continue;
                }
                $capabilityRows[] = $row;
                if ((string)($row['provider'] ?? '') === $provider) {
                    $providerRows[] = $row;
                    if ((string)($row['capability_version'] ?? '') === $capabilityVersion) {
                        $exactRow = $row;
                        break;
                    }
                }
            }

            if ($capabilityRows === []) {
                return $this->audit(array_merge($result, ['reason' => 'missing_policy_row']), 'warning');
            }
            if ($providerRows === []) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }
            if (!is_array($exactRow)) {
                return $this->audit(array_merge($result, ['reason' => 'version_mismatch']), 'warning');
            }

            $rowCaller = trim((string)($exactRow['caller_module'] ?? ''));
            if ($rowCaller !== '' && $rowCaller !== $callerModule) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_caller']), 'warning');
            }

            $allowedRoles = $this->parseAllowedRoles($exactRow['allowed_roles'] ?? null);
            if ($allowedRoles !== [] && !in_array($actorRole, $allowedRoles, true)) {
                return $this->audit(array_merge($result, ['reason' => 'unknown_role']), 'warning');
            }

            if ((int)($exactRow['provider_activation_required'] ?? 1) === 1 && !$providerActivation) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }

            return $this->audit(array_merge($result, ['allowed' => true, 'reason' => 'allowed']), 'info');
        } catch (CapabilityAuthorizationRegistryUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry unavailable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function seedPolicy(array $rows): void
    {
        $db = $this->db();
        $sql = 'INSERT INTO capability_authorization_policies '
            . '(policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, updated_at) '
            . 'VALUES (:policy_version, :capability_id, :capability_version, :provider, :caller_module, :allowed_roles, :provider_activation_required, :requires_protocol, :is_active, NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'caller_module = VALUES(caller_module), '
            . 'allowed_roles = VALUES(allowed_roles), '
            . 'provider_activation_required = VALUES(provider_activation_required), '
            . 'requires_protocol = VALUES(requires_protocol), '
            . 'is_active = VALUES(is_active), '
            . 'updated_at = NOW()';

        try {
            $stmt = $db->prepare($sql);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $stmt->execute([
                    ':policy_version' => (int)($row['policy_version'] ?? 0),
                    ':capability_id' => trim((string)($row['capability_id'] ?? '')),
                    ':capability_version' => trim((string)($row['capability_version'] ?? '')),
                    ':provider' => trim((string)($row['provider'] ?? '')),
                    ':caller_module' => $this->nullableString($row['caller_module'] ?? null),
                    ':allowed_roles' => $this->nullableString($row['allowed_roles'] ?? null),
                    ':provider_activation_required' => !array_key_exists('provider_activation_required', $row) || (bool)$row['provider_activation_required'] ? 1 : 0,
                    ':requires_protocol' => trim((string)($row['requires_protocol'] ?? 'v1')),
                    ':is_active' => !array_key_exists('is_active', $row) || (bool)$row['is_active'] ? 1 : 0,
                ]);
            }
            self::invalidate();
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry seed failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function hasPolicyFor(string $capabilityId, ?string $capabilityVersion = null, ?string $provider = null, ?int $policyVersion = null): bool
    {
        $version = $this->resolvePolicyVersion($policyVersion);
        if ($version === null) {
            return false;
        }

        foreach ($this->rowsForVersion($version) as $row) {
            if ((string)($row['capability_id'] ?? '') !== $capabilityId) {
                continue;
            }
            if ($capabilityVersion !== null && (string)($row['capability_version'] ?? '') !== $capabilityVersion) {
                continue;
            }
            if ($provider !== null && (string)($row['provider'] ?? '') !== $provider) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function requiresProtocol(string $capabilityId, string $capabilityVersion, string $provider, ?int $policyVersion = null): ?string
    {
        $version = $this->resolvePolicyVersion($policyVersion);
        if ($version === null) {
            return null;
        }

        foreach ($this->rowsForVersion($version) as $row) {
            if ((string)($row['capability_id'] ?? '') === $capabilityId
                && (string)($row['capability_version'] ?? '') === $capabilityVersion
                && (string)($row['provider'] ?? '') === $provider) {
                $requiresProtocol = trim((string)($row['requires_protocol'] ?? ''));
                return $requiresProtocol !== '' ? $requiresProtocol : null;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsForVersion(int $policyVersion): array
    {
        if (isset(self::$policyCache[$policyVersion])) {
            return self::$policyCache[$policyVersion];
        }

        try {
            $stmt = $this->db()->prepare('SELECT policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active FROM capability_authorization_policies WHERE is_active = 1 AND policy_version = :policy_version ORDER BY capability_id ASC, capability_version ASC, provider ASC');
            $stmt->execute([':policy_version' => $policyVersion]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            self::$policyCache[$policyVersion] = is_array($rows) ? $rows : [];
            return self::$policyCache[$policyVersion];
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function resolvePolicyVersion(?int $override = null): ?int
    {
        $cacheKey = $override === null ? 'active' : 'override:' . $override;
        if (array_key_exists($cacheKey, self::$activeVersionCache)) {
            return self::$activeVersionCache[$cacheKey];
        }

        try {
            $db = $this->db();
            if ($override !== null) {
                $stmt = $db->prepare('SELECT policy_version FROM capability_authorization_policies WHERE policy_version = :policy_version AND is_active = 1 ORDER BY id DESC LIMIT 1');
                $stmt->execute([':policy_version' => $override]);
                $found = $stmt->fetchColumn();
                self::$activeVersionCache[$cacheKey] = $found === false ? null : (int)$found;
                return self::$activeVersionCache[$cacheKey];
            }

            $stmt = $db->query('SELECT MAX(policy_version) FROM capability_authorization_policies WHERE is_active = 1');
            $value = $stmt === false ? false : $stmt->fetchColumn();
            self::$activeVersionCache[$cacheKey] = $value === false || $value === null ? null : (int)$value;
            return self::$activeVersionCache[$cacheKey];
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry version lookup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function db(): PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }

        if (function_exists('app')) {
            $db = app()->db();
            if ($db instanceof PDO) {
                return $db;
            }
        }

        throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry database unavailable');
    }

    /** @return array<int, string> */
    private function parseAllowedRoles(mixed $value): array
    {
        $roles = array_filter(array_map(
            static fn(string $role): string => trim($role),
            explode(',', trim((string)$value))
        ), static fn(string $role): bool => $role !== '');

        return array_values(array_unique($roles));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $result */
    private function audit(array $result, string $level): array
    {
        try {
            if (function_exists('write_log')) {
                write_log('capability.authz.decision', $level, $result);
            }
        } catch (Throwable $e) {
        }

        return $result;
    }
}

final class CapabilityAuthorizationRegistryUnavailableException extends \RuntimeException
{
}
