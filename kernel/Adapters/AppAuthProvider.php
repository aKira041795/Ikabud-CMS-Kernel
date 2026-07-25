<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Adapters;

use Ikabud\Kernel\Contracts\AuthProvider;

/**
 * AppAuthProvider — adapts App::user(), App::requireAuth() etc. to AuthProvider contract.
 *
 * Step 2 of the App decomposition roadmap. Wraps existing auth methods
 * behind the narrow AuthProvider interface for service injection.
 *
 * @package Ikabud\Kernel\Adapters
 */
final class AppAuthProvider implements AuthProvider
{
    private \Ikabud\Kernel\App $app;

    public function __construct(?\Ikabud\Kernel\App $app = null)
    {
        $this->app = $app ?? \Ikabud\Kernel\App::getInstance();
    }

    public function user(): ?array
    {
        return $this->app->user();
    }

    public function isAuthenticated(): bool
    {
        return $this->app->isAuthenticated();
    }

    public function hasRole(string $role): bool
    {
        return $this->app->hasRole($role);
    }

    public function requireAuth(): array
    {
        return $this->app->requireAuth();
    }

    public function requireRole(string $role): array
    {
        return $this->app->requireRole($role);
    }

    public function requireAnyRole(string ...$roles): array
    {
        return $this->app->requireAnyRole(...$roles);
    }
}
