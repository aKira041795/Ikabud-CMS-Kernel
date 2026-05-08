<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Federation;

/**
 * Service registry for {remote} resolvers.
 *
 * 4.6.0: in-memory registry populated by callers. Each service maps to a
 * resolver callable invoked with the parsed query string and bound context.
 *
 * 4.6.1: this will load from config/federation.php with per-tenant override
 * and enforce per-service auth tokens via the kernel secrets manager.
 */
final class ServiceRegistry
{
    /** @var array<string, callable(string $query, array $ctx): mixed> */
    private array $resolvers = [];

    /**
     * @param callable(string $query, array $ctx): mixed $resolver
     */
    public function register(string $service, callable $resolver): void
    {
        $this->resolvers[$service] = $resolver;
    }

    public function has(string $service): bool
    {
        return isset($this->resolvers[$service]);
    }

    /** @return list<string> */
    public function list(): array
    {
        return array_keys($this->resolvers);
    }

    public function resolve(string $service, string $query, array $ctx): mixed
    {
        if (!isset($this->resolvers[$service])) {
            throw new \RuntimeException("DISYL_FEDERATION_UNKNOWN_SERVICE: $service");
        }
        return ($this->resolvers[$service])($query, $ctx);
    }
}
