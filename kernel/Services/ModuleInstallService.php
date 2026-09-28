<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Database\KernelPDO;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Installs a module dependency closure into an existing active tenant.
 *
 * This is deliberately separate from TenantProvisioner: it never changes the
 * tenant lifecycle status. Tenant writes are staged; the entry pointer and the
 * generation commit are one control-DB transaction; staged activation is only
 * promoted after that transaction commits. The per-tenant advisory lock also
 * serializes enable/disable and entry-pointer changes made through this service.
 */
final class ModuleInstallService
{
    /** @var callable(int):PDO|null */
    private $tenantPdoResolver;
    /** @var callable():array<string,array<string,mixed>> */
    private $moduleResolver;
    /** @var callable(PDO,string,array<string,mixed>):array<int,string> */
    private $migrationRunner;
    /** @var callable(PDO,string,array<string,mixed>):void */
    private $policySeeder;
    /** @var array<int,true> */
    private static array $localLocks = [];

    public function __construct(
        private readonly PDO $controlDb,
        ?callable $tenantPdoResolver = null,
        ?callable $moduleResolver = null,
        ?callable $migrationRunner = null,
        ?callable $policySeeder = null,
    ) {
        $this->tenantPdoResolver = $tenantPdoResolver ?? function (int $tenantId): ?PDO {
            $db = app()->dbForTenant($tenantId);
            if ($db instanceof PDO) {
                return $db;
            }
            $stmt = $this->controlDb->prepare('SELECT COUNT(*) FROM kernel_tenant_db_connections WHERE tenant_id = :tenant');
            $stmt->execute([':tenant' => $tenantId]);
            if ((int)$stmt->fetchColumn() > 0) {
                // A declared dedicated connection that failed must never fall
                // through to the shared/base PDO.
                return null;
            }
            // Shared-schema mode is represented by the absence of a dedicated
            // connection row. In that mode the primary PDO is the correct
            // tenant PDO; module schemas and queries enforce tenant_id.
            return app()->db();
        };
        $this->moduleResolver = $moduleResolver ?? static fn (): array => function_exists('discoverModules') ? discoverModules() : [];
        $this->migrationRunner = $migrationRunner ?? static function (PDO $db, string $moduleId, array $manifest): array {
            if (!function_exists('tenantSyncModuleMigrations')) {
                throw new RuntimeException('Tenant migration coordinator is unavailable.');
            }
            return tenantSyncModuleMigrations($db, $moduleId, $manifest);
        };
        $this->policySeeder = $policySeeder ?? static function (PDO $db, string $moduleId, array $manifest): void {
            $rows = [];
            foreach ((array)($manifest['capabilities']['exposes'] ?? []) as $expose) {
                if (!is_array($expose) || empty($expose['requires_protocol'])) {
                    continue;
                }
                $id = trim((string)($expose['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                [$base, $version] = array_pad(explode('@', $id, 2), 2, '1');
                $rows[] = [
                    'policy_version' => 1,
                    'capability_id' => $base . '@' . $version,
                    'capability_version' => $version,
                    'provider' => $moduleId,
                    'caller_module' => null,
                    'allowed_roles' => 'admin',
                    'provider_activation_required' => true,
                    'requires_protocol' => (string)$expose['requires_protocol'],
                    'is_active' => true,
                ];
            }
            if ($rows !== []) {
                (new CapabilityAuthorizationRegistry($db))->seedPolicy($rows);
            }
        };
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function install(int $tenantId, string $selectionId, array $options = []): array
    {
        $generation = trim((string)($options['generation'] ?? ''));
        if ($generation === '') {
            $generation = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
        }
        if ($tenantId <= 0 || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $generation) !== 1) {
            return ['ok' => false, 'generation' => $generation, 'status' => 'failed_dependencies_resolving', 'error' => 'Invalid tenant or generation.'];
        }

        $lock = $this->acquireLock($tenantId, (int)($options['lock_timeout'] ?? 10));
        if (!$lock) {
            return ['ok' => false, 'generation' => $generation, 'status' => 'failed_dependencies_resolving', 'error' => 'Another install or activation change is in progress.'];
        }

        $tenantDb = null;
        $members = [];
        $stagedMembers = [];
        $activationBefore = [];
        $previousEntry = null;
        $generationId = 0;
        try {
            $tenant = $this->tenant($tenantId);
            if ($tenant === null || strtolower((string)$tenant['status']) !== 'active') {
                throw new RuntimeException('Module installation requires an existing active tenant.');
            }
            $previousEntry = $this->nullable((string)($tenant['entry_module_id'] ?? ''));
            $existing = $this->generation($tenantId, $generation);
            if (is_array($existing) && (string)$existing['status'] === 'active') {
                return ['ok' => true, 'generation' => $generation, 'status' => 'active', 'members' => $this->memberIds((int)$existing['id'])];
            }

            $modules = ($this->moduleResolver)();
            $members = $this->resolveClosure($selectionId, $modules, $options);
            $entry = in_array('cms-akira-shell', $members, true) && !empty($options['set_entry'])
                ? 'cms-akira-shell'
                : null;
            if (isset($options['entry_module']) && $options['entry_module'] !== null && $options['entry_module'] !== 'cms-akira-shell') {
                throw new RuntimeException('Only cms-akira-shell may be assigned as an entry module.');
            }

            $generationId = $this->createOrResetGeneration($tenantId, $generation, $selectionId, $entry, $previousEntry);
            $this->state($generationId, 'dependencies_resolving');
            $this->failIfRequested($options, 'dependencies_resolving');

            $tenantDb = ($this->tenantPdoResolver)($tenantId);
            if (!$tenantDb instanceof PDO) {
                throw new RuntimeException('Tenant PDO is unavailable.');
            }
            app()->tenant()->setTenantId($tenantId);

            $this->state($generationId, 'migrations_running');
            foreach ($members as $member) {
                $this->step($generationId, $member, 'migration', 'running');
                $this->failIfRequested($options, 'migration');
                $executed = ($this->migrationRunner)($tenantDb, $member, $modules[$member]);
                $this->step($generationId, $member, 'migration', 'complete', ['executed' => $executed]);
            }

            $this->state($generationId, 'policy_seeding');
            foreach ($members as $member) {
                $this->step($generationId, $member, 'policy', 'running');
                $this->failIfRequested($options, 'policy_seed');
                ($this->policySeeder)($tenantDb, $member, $modules[$member]);
                $this->step($generationId, $member, 'policy', 'complete');
            }

            $this->state($generationId, 'activation_writing');
            foreach ($members as $member) {
                $this->failIfRequested($options, 'activation_write');
                $settings = function_exists('_readTenantModuleSettingsSingle')
                    ? _readTenantModuleSettingsSingle($member, $tenantId, $tenantDb)
                    : [];
                $activationBefore[$member] = (bool)($settings['_module_enabled'] ?? false);
                $this->activationWrite($tenantDb, $tenantId, [$member], false, $generation, true);
                $stagedMembers[] = $member;
                $this->step($generationId, $member, 'activation', 'staged');
            }

            $this->kernelControlOperation(function () use ($entry, $tenantId, $generationId, $options): void {
                $this->controlDb->beginTransaction();
                try {
                    $this->failIfRequested($options, 'entry_write');
                    if ($entry !== null) {
                        $stmt = $this->controlDb->prepare('UPDATE kernel_tenants SET entry_module_id = :entry, updated_at = NOW() WHERE id = :tenant AND status = \'active\'');
                        $stmt->execute([':entry' => $entry, ':tenant' => $tenantId]);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Entry module write lost its active-tenant compare-and-set.');
                        }
                    }
                    $stmt = $this->controlDb->prepare("UPDATE kernel_module_install_generations SET status = 'active', committed_at = NOW(), error_message = NULL, updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $generationId]);
                    $this->controlDb->commit();
                } catch (Throwable $e) {
                    if ($this->controlDb->inTransaction()) {
                        $this->controlDb->rollBack();
                    }
                    throw $e;
                }
            });

            foreach ($members as $member) {
                $this->activationWrite($tenantDb, $tenantId, [$member], true, $generation, false);
                $this->step($generationId, $member, 'activation', 'complete');
            }
            $this->prune($tenantId);
            return ['ok' => true, 'generation' => $generation, 'status' => 'active', 'members' => $members, 'entry_module_id' => $entry];
        } catch (Throwable $e) {
            $stage = $this->currentState($generationId);
            $failed = $this->failureState($stage);
            if ($generationId > 0) {
                $this->state($generationId, 'pending_rollback', $e->getMessage());
            }
            if ($tenantDb instanceof PDO) {
                foreach ($stagedMembers as $member) {
                    try {
                        $this->activationWrite($tenantDb, $tenantId, [$member], (bool)($activationBefore[$member] ?? false), null, false);
                    } catch (Throwable) {
                    }
                }
            }
            if ($generationId > 0) {
                try {
                    $this->kernelControlOperation(function () use ($previousEntry, $tenantId): void {
                        $stmt = $this->controlDb->prepare('UPDATE kernel_tenants SET entry_module_id = :entry, updated_at = NOW() WHERE id = :tenant AND status = \'active\'');
                        $stmt->execute([':entry' => $previousEntry, ':tenant' => $tenantId]);
                    });
                } catch (Throwable) {
                }
                $this->state($generationId, $failed, $e->getMessage());
            }
            return ['ok' => false, 'generation' => $generation, 'status' => $failed, 'members' => $members, 'error' => $e->getMessage()];
        } finally {
            $this->releaseLock($tenantId);
        }
    }

    /**
     * Data-preserving uninstall: only activation and this service's entry pointer are removed.
     * @param string[] $moduleIds
     * @return array<string,mixed>
     */
    public function uninstall(int $tenantId, array $moduleIds, bool $confirmedPurge = false): array
    {
        if ($confirmedPurge) {
            throw new RuntimeException('Data purge is not implemented; uninstall is preservation-only.');
        }
        if (!$this->acquireLock($tenantId, 10)) {
            return ['ok' => false, 'error' => 'Another install or activation change is in progress.'];
        }
        try {
            $db = ($this->tenantPdoResolver)($tenantId);
            if (!$db instanceof PDO) {
                throw new RuntimeException('Tenant PDO is unavailable.');
            }
            $this->activationWrite($db, $tenantId, $moduleIds, false);
            if (in_array('cms-akira-shell', $moduleIds, true)) {
                $this->kernelControlOperation(function () use ($tenantId): void {
                    $stmt = $this->controlDb->prepare("UPDATE kernel_tenants SET entry_module_id = NULL, updated_at = NOW() WHERE id = :tenant AND entry_module_id = 'cms-akira-shell'");
                    $stmt->execute([':tenant' => $tenantId]);
                });
            }
            return ['ok' => true, 'data_preserved' => true];
        } finally {
            $this->releaseLock($tenantId);
        }
    }

    /**
     * Return suite package state from the install ledger and tenant activation
     * store. Manifests supply identity only; they never supply enabled state.
     *
     * @return list<array<string,mixed>>
     */
    public function suiteState(int $tenantId, string $suite): array
    {
        if ($tenantId <= 0 || $suite === '') {
            throw new RuntimeException('A tenant and suite are required.');
        }
        $tenant = $this->tenant($tenantId);
        if ($tenant === null) {
            throw new RuntimeException('Tenant not found.');
        }
        $modules = ($this->moduleResolver)();
        $installed = $this->kernelControlOperation(function () use ($tenantId): array {
            $stmt = $this->controlDb->prepare(
                "SELECT g.selection_id, s.module_id FROM kernel_module_install_generations g "
                . "LEFT JOIN kernel_module_install_steps s ON s.install_generation_id = g.id "
                . "WHERE g.tenant_id = :tenant AND g.status = 'active'"
            );
            $stmt->execute([':tenant' => $tenantId]);
            $ids = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                foreach (['selection_id', 'module_id'] as $key) {
                    $id = trim((string)($row[$key] ?? ''));
                    if ($id !== '') {
                        $ids[$id] = true;
                    }
                }
            }
            return $ids;
        });
        $entry = trim((string)($tenant['entry_module_id'] ?? ''));
        $required = $entry !== '' && isset($modules[$entry]) ? array_fill_keys($this->resolveClosure($entry, $modules, []), true) : [];
        $rows = [];
        $tenantDb = ($this->tenantPdoResolver)($tenantId);
        if (!$tenantDb instanceof PDO) {
            throw new RuntimeException('Tenant PDO is unavailable.');
        }
        foreach ($modules as $moduleId => $manifest) {
            if (($manifest['suite'] ?? '') !== $suite) {
                continue;
            }
            $settings = $this->kernelControlOperation(
                static fn (): array => function_exists('_readTenantModuleSettingsSingle')
                    ? _readTenantModuleSettingsSingle((string)$moduleId, $tenantId, $tenantDb) : []
            );
            $hasActivation = array_key_exists('_module_enabled', $settings);
            $rows[] = [
                'id' => (string)$moduleId,
                'name' => (string)($manifest['name'] ?? $moduleId),
                'kind' => (string)($manifest['kind'] ?? 'module'),
                'version' => (string)($manifest['version'] ?? ''),
                'present' => true,
                'installed' => $hasActivation || isset($installed[$moduleId]),
                'enabled' => $hasActivation && $settings['_module_enabled'] === true,
                'activation_state' => (string)($settings['_module_activation_state'] ?? ''),
                'required' => isset($required[$moduleId]),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['kind'] === 'profile' ? 1 : 0, $a['name']] <=> [$b['kind'] === 'profile' ? 1 : 0, $b['name']]);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function setEnabled(int $tenantId, string $moduleId, bool $enabled): array
    {
        $modules = ($this->moduleResolver)();
        if ($tenantId <= 0 || !isset($modules[$moduleId]) || ($modules[$moduleId]['suite'] ?? '') !== 'cms-akira') {
            throw new RuntimeException('Unknown Akira module.');
        }
        if (($modules[$moduleId]['kind'] ?? '') === 'profile') {
            throw new RuntimeException('Profiles are install selections, not runtime modules.');
        }
        if (!$this->acquireLock($tenantId, 10)) {
            return ['ok' => false, 'error' => 'Another install or activation change is in progress.'];
        }
        try {
            $tenant = $this->tenant($tenantId);
            if ($tenant === null || strtolower((string)$tenant['status']) !== 'active') {
                throw new RuntimeException('Activation requires an existing active tenant.');
            }
            if (!$enabled) {
                $entry = trim((string)($tenant['entry_module_id'] ?? ''));
                $required = $entry !== '' && isset($modules[$entry]) ? $this->resolveClosure($entry, $modules, []) : [];
                if (in_array($moduleId, $required, true)) {
                    throw new RuntimeException('An entry-module dependency cannot be disabled.');
                }
            }
            $db = ($this->tenantPdoResolver)($tenantId);
            if (!$db instanceof PDO) {
                throw new RuntimeException('Tenant PDO is unavailable.');
            }
            $this->activationToggleWrite($db, $tenantId, $moduleId, $enabled);
            return ['ok' => true, 'module_id' => $moduleId, 'enabled' => $enabled];
        } finally {
            $this->releaseLock($tenantId);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $modules
     * @param array<string,mixed> $options
     * @return string[]
     */
    private function resolveClosure(string $selectionId, array $modules, array $options): array
    {
        if (!isset($modules[$selectionId])) {
            throw new RuntimeException('Unknown module/profile: ' . $selectionId);
        }
        $roots = [$selectionId];
        $selection = $modules[$selectionId];
        if (($selection['kind'] ?? '') === 'profile') {
            $roots = array_values(array_filter(array_map('strval', (array)($selection['installs'] ?? []))));
            $headless = str_contains($selectionId, 'headless') || !empty($options['headless']);
            if (!$headless) {
                $roots[] = 'cms-akira-shell';
            }
        }
        $seen = [];
        $visit = function (string $id) use (&$visit, &$seen, $modules): void {
            if (isset($seen[$id])) {
                return;
            }
            if (!isset($modules[$id])) {
                throw new RuntimeException('Missing module dependency: ' . $id);
            }
            foreach ((array)($modules[$id]['depends'] ?? []) as $dep) {
                if (is_string($dep) && isset($modules[$dep])) {
                    $visit($dep);
                }
            }
            $seen[$id] = true;
        };
        foreach ($roots as $root) {
            $visit($root);
        }
        return array_keys($seen);
    }

    /** @return array<string,mixed>|null */
    private function tenant(int $tenantId): ?array
    {
        return $this->kernelControlOperation(function () use ($tenantId): ?array {
            $stmt = $this->controlDb->prepare('SELECT id, status, entry_module_id FROM kernel_tenants WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        });
    }

    /** @return array<string,mixed>|null */
    private function generation(int $tenantId, string $generation): ?array
    {
        return $this->kernelControlOperation(function () use ($tenantId, $generation): ?array {
            $stmt = $this->controlDb->prepare('SELECT id, status FROM kernel_module_install_generations WHERE tenant_id = :tenant AND install_generation = :generation LIMIT 1');
            $stmt->execute([':tenant' => $tenantId, ':generation' => $generation]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        });
    }

    private function createOrResetGeneration(int $tenantId, string $generation, string $selection, ?string $entry, ?string $previous): int
    {
        $row = $this->generation($tenantId, $generation);
        return $this->kernelControlOperation(function () use ($row, $tenantId, $generation, $selection, $entry, $previous): int {
            if (is_array($row)) {
                $stmt = $this->controlDb->prepare("UPDATE kernel_module_install_generations SET selection_id = :selection, entry_module_id = :entry, previous_entry_module_id = :previous, status = 'install_requested', error_message = NULL, committed_at = NULL, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':selection' => $selection, ':entry' => $entry, ':previous' => $previous, ':id' => $row['id']]);
                return (int)$row['id'];
            }
            $stmt = $this->controlDb->prepare("INSERT INTO kernel_module_install_generations (tenant_id, install_generation, selection_id, entry_module_id, previous_entry_module_id, status) VALUES (:tenant, :generation, :selection, :entry, :previous, 'install_requested')");
            $stmt->execute([':tenant' => $tenantId, ':generation' => $generation, ':selection' => $selection, ':entry' => $entry, ':previous' => $previous]);
            return (int)$this->controlDb->lastInsertId();
        });
    }

    private function state(int $id, string $state, ?string $error = null): void
    {
        if ($id <= 0) {
            return;
        }
        $this->kernelControlOperation(function () use ($id, $state, $error): void {
            $stmt = $this->controlDb->prepare('UPDATE kernel_module_install_generations SET status = :status, error_message = :error, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':status' => $state, ':error' => $error, ':id' => $id]);
        });
    }

    private function currentState(int $id): string
    {
        if ($id <= 0) {
            return 'dependencies_resolving';
        }
        return $this->kernelControlOperation(function () use ($id): string {
            $stmt = $this->controlDb->prepare('SELECT status FROM kernel_module_install_generations WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return (string)($stmt->fetchColumn() ?: 'dependencies_resolving');
        });
    }

    private function failureState(string $state): string
    {
        return match ($state) {
            'migrations_running' => 'failed_migrations_running',
            'policy_seeding' => 'failed_policy_seeding',
            'activation_writing', 'active' => 'failed_activation_writing',
            default => 'failed_dependencies_resolving',
        };
    }

    /** @param array<string,mixed> $detail */
    private function step(int $generationId, string $module, string $step, string $status, array $detail = []): void
    {
        $sql = 'INSERT INTO kernel_module_install_steps (install_generation_id, module_id, step_name, status, detail_json, started_at, completed_at) '
            . 'VALUES (:generation, :module, :step, :status, :detail, NOW(), IF(:complete = 1, NOW(), NULL)) '
            . 'ON DUPLICATE KEY UPDATE status = VALUES(status), detail_json = VALUES(detail_json), completed_at = VALUES(completed_at)';
        $this->kernelControlOperation(function () use ($sql, $generationId, $module, $step, $status, $detail): void {
            $stmt = $this->controlDb->prepare($sql);
            $stmt->execute([':generation' => $generationId, ':module' => $module, ':step' => $step, ':status' => $status, ':detail' => json_encode($detail), ':complete' => in_array($status, ['complete', 'staged'], true) ? 1 : 0]);
        });
    }

    /** @return string[] */
    private function memberIds(int $generationId): array
    {
        return $this->kernelControlOperation(function () use ($generationId): array {
            $stmt = $this->controlDb->prepare('SELECT DISTINCT module_id FROM kernel_module_install_steps WHERE install_generation_id = :id ORDER BY module_id');
            $stmt->execute([':id' => $generationId]);
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        });
    }

    private function acquireLock(int $tenantId, int $timeout): bool
    {
        if ((string)$this->controlDb->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            if (isset(self::$localLocks[$tenantId])) {
                return false;
            }
            self::$localLocks[$tenantId] = true;
            return true;
        }
        $stmt = $this->controlDb->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(['ikabud_module_install_' . $tenantId, max(0, $timeout)]);
        $ok = (int)$stmt->fetchColumn() === 1;
        $stmt->closeCursor();
        return $ok;
    }

    private function releaseLock(int $tenantId): void
    {
        unset(self::$localLocks[$tenantId]);
        if ((string)$this->controlDb->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->controlDb->query("SELECT RELEASE_LOCK(" . $this->controlDb->quote('ikabud_module_install_' . $tenantId) . ')');
            if ($stmt) {
                $stmt->fetchColumn();
                $stmt->closeCursor();
            }
        }
    }

    /** @param array<string,mixed> $options */
    private function failIfRequested(array $options, string $window): void
    {
        if (($options['fail_at'] ?? null) === $window) {
            throw new RuntimeException('Injected failure at ' . $window . '.');
        }
    }

    private function prune(int $tenantId): void
    {
        // Keep all in-flight rows and the ten newest terminal generations;
        // terminal rows older than 30 days beyond that operational window go.
        $sql = "DELETE FROM kernel_module_install_generations WHERE tenant_id = :tenant AND status IN ('active','failed_dependencies_resolving','failed_migrations_running','failed_policy_seeding','failed_activation_writing') AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND id NOT IN (SELECT id FROM (SELECT id FROM kernel_module_install_generations WHERE tenant_id = :tenant2 ORDER BY id DESC LIMIT 10) retained)";
        $this->kernelControlOperation(function () use ($sql, $tenantId): void {
            $stmt = $this->controlDb->prepare($sql);
            $stmt->execute([':tenant' => $tenantId, ':tenant2' => $tenantId]);
        });
    }

    /**
     * CD-001: this is the only escalation seam in the installer. It is called
     * only around this service's control-table reads/writes and activation-store
     * writes. Dependency resolution, policy seeders, and especially module
     * migration callbacks execute outside this scope.
     */
    private function kernelControlOperation(callable $operation): mixed
    {
        KernelPDO::kernelEscalationEnter();
        try {
            return $operation();
        } finally {
            KernelPDO::kernelEscalationLeave();
        }
    }

    /** @param string[] $moduleIds */
    private function activationWrite(PDO $db, int $tenantId, array $moduleIds, bool $enabled, ?string $generation = null, bool $staged = false): void
    {
        $this->kernelControlOperation(static function () use ($db, $tenantId, $moduleIds, $enabled, $generation, $staged): void {
            tenantSetModuleActivationState($db, $tenantId, $moduleIds, $enabled, $generation, $staged);
        });
    }

    /** Toggle only live activation keys; install-generation provenance is untouched. */
    private function activationToggleWrite(PDO $db, int $tenantId, string $moduleId, bool $enabled): void
    {
        $this->kernelControlOperation(static function () use ($db, $tenantId, $moduleId, $enabled): void {
            $enabledSaved = tenantWriteModuleSetting($db, $tenantId, $moduleId, '_module_enabled', $enabled);
            $stateSaved = tenantWriteModuleSetting($db, $tenantId, $moduleId, '_module_activation_state', $enabled ? 'committed' : 'inactive');
            if (!$enabledSaved || !$stateSaved) {
                throw new RuntimeException('Tenant module activation could not be persisted.');
            }
        });
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
