<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

use Ikabud\Kernel\Capabilities\AuthorityScope;
use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;

/**
 * Enforces manifest-declared route authority before a module handler runs.
 *
 * Missing declarations, tenant scope, or policy configuration are compatibility
 * states and are allowed. Once a route and role policy are both declared, a
 * mismatch is a policy violation and is refused.
 */
final class AuthorityDispatchGuard
{
    public const ENV_FLAG = 'KERNEL_AUTHORITY_DISPATCH_ENFORCE';

    public static function enabled(): bool
    {
        $raw = $_ENV[self::ENV_FLAG] ?? getenv(self::ENV_FLAG);
        if ($raw === false || $raw === null || $raw === '') {
            return true;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? true;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed>|null $actor
     * @return array{allowed:bool,reason:string,capability_id:?string,configured:bool}
     */
    public static function decide(
        string $moduleId,
        string $method,
        ?string $routePattern,
        string $requestUri,
        array $manifest,
        ?array $actor,
        ?AuthorityScopeResolver $resolver = null,
    ): array {
        if (!self::enabled()) {
            return self::result(true, 'enforcement_disabled', null, false);
        }

        // Resolve at dispatch time even for an undeclared route. This makes the
        // authority scope a property of the request rather than module loading.
        $resolver ??= AuthorityScopeResolver::forApplication();
        $scope = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $actor]);

        $routes = is_array($manifest['capabilities']['routes'] ?? null)
            ? $manifest['capabilities']['routes']
            : [];
        $key = self::routeKey($method, $routePattern, $requestUri, $routes);
        if ($key === null) {
            return self::result(true, 'no_authority_declared', null, false);
        }

        $declaration = $routes[$key];
        $capabilityId = is_array($declaration)
            ? trim((string)($declaration['capability'] ?? $declaration['id'] ?? ''))
            : trim((string)$declaration);
        if ($capabilityId === '') {
            // A malformed/partial installation is configuration absence, not a
            // negative authorization decision.
            return self::result(true, 'missing_authority_configuration', null, false);
        }

        if (!$scope instanceof AuthorityScope) {
            return self::result(true, $resolver->failureReason() ?? 'missing_authority_configuration', $capabilityId, false);
        }

        $policy = is_array($manifest['capabilities']['policy'][$capabilityId] ?? null)
            ? $manifest['capabilities']['policy'][$capabilityId]
            : [];
        $roles = is_array($declaration) ? ($declaration['allowed_roles'] ?? null) : null;
        $roles ??= $policy['allowed_roles'] ?? $policy['allow_roles'] ?? null;
        if (is_string($roles)) {
            $roles = array_map('trim', explode(',', $roles));
        }
        if (!is_array($roles) || $roles === []) {
            return self::result(true, 'missing_authority_configuration', $capabilityId, false);
        }

        $roles = array_values(array_filter(array_map('strval', $roles), static fn(string $role): bool => $role !== ''));
        $actorRole = trim((string)($actor['role'] ?? ''));
        if (!in_array($actorRole, $roles, true)) {
            return self::result(false, 'role_not_allowed', $capabilityId, true);
        }

        return self::result(true, 'allowed', $capabilityId, true);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed>|null $actor
     */
    public static function assertAllowed(
        string $moduleId,
        string $method,
        ?string $routePattern,
        string $requestUri,
        array $manifest,
        ?array $actor,
        ?AuthorityScopeResolver $resolver = null,
    ): void {
        $decision = self::decide($moduleId, $method, $routePattern, $requestUri, $manifest, $actor, $resolver);
        if (!$decision['allowed']) {
            throw new AuthorityDispatchDeniedException(
                'Authority dispatch refused: ' . $decision['reason'],
                $decision
            );
        }
    }

    /** @param array<string,mixed> $routes */
    private static function routeKey(string $method, ?string $pattern, string $uri, array $routes): ?string
    {
        $method = strtoupper(trim($method));
        if ($pattern !== null && isset($routes[$method . ' ' . $pattern])) {
            return $method . ' ' . $pattern;
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        foreach (array_keys($routes) as $key) {
            if (!is_string($key) || !str_starts_with($key, $method . ' ')) {
                continue;
            }
            $route = substr($key, strlen($method) + 1);
            $parts = preg_split('/(\\{[A-Za-z_][A-Za-z0-9_]*\\})/', $route, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $regexBody = '';
            foreach ($parts as $part) {
                $regexBody .= preg_match('/^\\{[A-Za-z_][A-Za-z0-9_]*\\}$/', $part) === 1
                    ? '[^/]+'
                    : preg_quote($part, '#');
            }
            if (preg_match('#^' . $regexBody . '$#', $path) === 1) {
                return $key;
            }
        }
        return null;
    }

    /** @return array{allowed:bool,reason:string,capability_id:?string,configured:bool} */
    private static function result(bool $allowed, string $reason, ?string $capabilityId, bool $configured): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'capability_id' => $capabilityId,
            'configured' => $configured,
        ];
    }
}

final class AuthorityDispatchDeniedException extends \RuntimeException
{
    /** @param array{allowed:bool,reason:string,capability_id:?string,configured:bool} $decision */
    public function __construct(string $message, public readonly array $decision)
    {
        parent::__construct($message);
    }
}
