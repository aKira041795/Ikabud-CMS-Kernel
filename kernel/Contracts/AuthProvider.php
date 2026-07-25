<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * AuthProvider — narrow authentication contract for kernel-level injection.
 *
 * Services type-hint this interface instead of calling app()->user(),
 * app()->isAuthenticated(), etc. This decouples auth logic from the
 * App singleton and makes services testable with a mock auth provider.
 *
 * Step 1 of the App decomposition roadmap.
 *
 * @package Ikabud\Kernel\Contracts
 */
interface AuthProvider
{
    /**
     * Get the currently authenticated user, or null if unauthenticated.
     *
     * @return array{id: int, username: string, role: string, name: string, source?: string, email?: string, ...}|null
     */
    public function user(): ?array;

    /**
     * Check if any user is currently authenticated.
     */
    public function isAuthenticated(): bool;

    /**
     * Check if the current user has a specific role.
     */
    public function hasRole(string $role): bool;

    /**
     * Require authentication. Halts with 401/redirect if unauthenticated.
     *
     * @return array{id: int, username: string, role: string, name: string, source?: string, ...}
     */
    public function requireAuth(): array;

    /**
     * Require the user to have the specified role. Halts with 403 if not.
     *
     * @return array{id: int, username: string, role: string, name: string, source?: string, ...}
     */
    public function requireRole(string $role): array;

    /**
     * Require the user to have any of the specified roles. Halts with 403 if none match.
     *
     * @return array{id: int, username: string, role: string, name: string, source?: string, ...}
     */
    public function requireAnyRole(string ...$roles): array;
}
