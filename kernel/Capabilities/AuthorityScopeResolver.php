<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

use PDO;
use Throwable;

/**
 * The single authority-scope resolver for every runtime entry point.
 *
 * Web obtains its tenant from the request tenant resolver. Every non-web
 * transport must carry tenant_id or explicitly establish it on TenantResolver;
 * it never inherits the database selected by app()->db(). Global kernel maintenance legitimately has no
 * tenant scope and must avoid tenant authorization reads (or inject its
 * deliberately selected PDO into the registry for maintenance-only work).
 */
final class AuthorityScopeResolver
{
    public const WEB = 'web';
    public const CLI = 'cli';
    public const CRON = 'cron';
    public const QUEUE = 'queue';
    public const SERVICE = 'service';
    public const EVENT = 'event';
    public const WORKBENCH = 'workbench';
    public const TEST = 'test';

    /** @var list<string> */
    private const ENTRY_POINTS = [self::WEB, self::CLI, self::CRON, self::QUEUE, self::SERVICE, self::EVENT, self::WORKBENCH, self::TEST];

    /** @var list<string> */
    private static array $entryPointStack = [];

    private ?string $failureReason = null;

    /**
     * @param \Closure(int):?PDO $tenantDatabase
     * @param \Closure(?array<string,mixed>):?int $webTenant
     * @param \Closure():?int|null $establishedTenant
     * @param \Closure(string,array<string,mixed>):void|null $logger
     */
    public function __construct(
        private readonly \Closure $tenantDatabase,
        private readonly \Closure $webTenant,
        private readonly ?\Closure $establishedTenant = null,
        private readonly ?\Closure $logger = null,
    ) {
    }

    public static function forApplication(?object $application = null): self
    {
        $application ??= function_exists('app') ? app() : null;

        return new self(
            static function (int $tenantId) use ($application): ?PDO {
                if (!is_object($application) || !method_exists($application, 'dbForTenant')) {
                    return null;
                }
                $db = $application->dbForTenant($tenantId);
                return $db instanceof PDO ? $db : null;
            },
            static function (?array $actor) use ($application): ?int {
                if (!is_object($application) || !method_exists($application, 'tenant')) {
                    return null;
                }
                $tenant = $application->tenant();
                if (!is_object($tenant)) {
                    return null;
                }
                $tenantId = method_exists($tenant, 'current') ? $tenant->current() : null;
                if ($tenantId === null && method_exists($tenant, 'resolve')) {
                    $tenantId = $tenant->resolve($actor);
                }
                return is_numeric($tenantId) && (int)$tenantId > 0 ? (int)$tenantId : null;
            },
            static function () use ($application): ?int {
                if (!is_object($application) || !method_exists($application, 'tenant')) {
                    return null;
                }
                $tenant = $application->tenant();
                $tenantId = is_object($tenant) && method_exists($tenant, 'current') ? $tenant->current() : null;
                return is_numeric($tenantId) && (int)$tenantId > 0 ? (int)$tenantId : null;
            },
            static function (string $reason, array $context): void {
                if (function_exists('write_log')) {
                    $level = $reason === 'unknown_authority_entry_point' ? 'warning' : 'info';
                    write_log('capability.authority_scope.unresolved', $level, ['reason' => $reason] + $context);
                }
            },
        );
    }

    public static function supportsEntryPoint(string $entryPoint): bool
    {
        return in_array($entryPoint, self::ENTRY_POINTS, true);
    }

    public static function currentEntryPoint(): ?string
    {
        $entryPoint = end(self::$entryPointStack);
        return is_string($entryPoint) ? $entryPoint : null;
    }

    /**
     * Run one complete unit of work against a resolvable tenant authority store.
     * Tenant and transport are stack-scoped so nested units restore their parent.
     */
    public static function withScope(int $tenantId, string $entryPoint, callable $work): mixed
    {
        $entryPoint = strtolower(trim($entryPoint));
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('An authority scope requires a positive tenant id.');
        }
        if (!self::supportsEntryPoint($entryPoint)) {
            throw new \InvalidArgumentException('Unknown authority entry point: ' . $entryPoint);
        }

        $application = function_exists('app') ? app() : null;
        $resolver = self::forApplication(is_object($application) ? $application : null);
        $scope = $resolver->resolve($entryPoint, ['tenant_id' => $tenantId]);
        if (!$scope instanceof AuthorityScope || !$resolver->database($scope) instanceof PDO) {
            $reason = $resolver->failureReason() ?? 'tenant_authority_store_unavailable';
            throw new \RuntimeException('Authority scope unavailable: ' . $reason);
        }
        if (!is_object($application) || !method_exists($application, 'tenant')) {
            throw new \RuntimeException('Authority scope unavailable: tenant_resolver_unavailable');
        }
        $tenant = $application->tenant();
        if (!is_object($tenant) || !method_exists($tenant, 'current') || !method_exists($tenant, 'setTenantId')) {
            throw new \RuntimeException('Authority scope unavailable: tenant_resolver_unavailable');
        }

        $previousTenant = $tenant->current();
        self::$entryPointStack[] = $entryPoint;
        $tenant->setTenantId($tenantId);
        try {
            return $work($scope);
        } finally {
            $tenant->setTenantId(is_numeric($previousTenant) ? (int)$previousTenant : null);
            array_pop(self::$entryPointStack);
        }
    }

    /**
     * @param array{tenant_id?:mixed,actor?:array<string,mixed>|null,declaration_revision?:mixed} $context
     */
    public function resolve(string $entryPoint, array $context = []): ?AuthorityScope
    {
        $entryPoint = strtolower(trim($entryPoint));
        $this->failureReason = null;
        if (!self::supportsEntryPoint($entryPoint)) {
            return $this->fail('unknown_authority_entry_point', ['entry_point' => $entryPoint]);
        }

        $actor = is_array($context['actor'] ?? null) ? $context['actor'] : null;
        $tenantId = $entryPoint === self::WEB
            ? ($this->webTenant)($actor)
            : (int)($context['tenant_id'] ?? 0);
        if ($entryPoint !== self::WEB && $tenantId <= 0 && $this->establishedTenant instanceof \Closure) {
            $tenantId = (int)(($this->establishedTenant)() ?? 0);
        }

        if (!is_int($tenantId) || $tenantId <= 0) {
            return $this->fail('missing_tenant_authority_scope', ['entry_point' => $entryPoint]);
        }

        $revision = isset($context['declaration_revision']) && $context['declaration_revision'] !== ''
            ? (int)$context['declaration_revision']
            : null;

        return new AuthorityScope($tenantId, $actor, $revision, $entryPoint);
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $caller
     */
    public function resolveForCapability(array $options, array $caller): ?AuthorityScope
    {
        $entryPoint = strtolower(trim((string)($options['authority_entry_point'] ?? '')));
        if ($entryPoint === '') {
            $entryPoint = self::currentEntryPoint() ?? '';
        }
        if ($entryPoint === '') {
            $entryPoint = PHP_SAPI === 'cli' ? self::CLI : self::WEB;
        }

        return $this->resolve($entryPoint, [
            'tenant_id' => $options['tenant_id'] ?? null,
            'actor' => is_array($caller['user'] ?? null) ? $caller['user'] : null,
            'declaration_revision' => $options['policy_version'] ?? null,
        ]);
    }

    public function database(AuthorityScope $scope): ?PDO
    {
        try {
            $db = ($this->tenantDatabase)($scope->tenantId);
            if ($db instanceof PDO) {
                return $db;
            }
        } catch (Throwable $e) {
            $this->fail('tenant_authority_store_unavailable', [
                'tenant_id' => $scope->tenantId,
                'entry_point' => $scope->entryPoint,
                'exception' => get_class($e),
            ]);
            return null;
        }

        $this->fail('tenant_authority_store_unavailable', [
            'tenant_id' => $scope->tenantId,
            'entry_point' => $scope->entryPoint,
        ]);
        return null;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    /** @param array<string,mixed> $context */
    private function fail(string $reason, array $context): null
    {
        $this->failureReason = $reason;
        if ($this->logger instanceof \Closure) {
            try {
                ($this->logger)($reason, $context);
            } catch (Throwable) {
            }
        }
        return null;
    }
}
