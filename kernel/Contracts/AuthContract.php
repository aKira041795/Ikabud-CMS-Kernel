<?php
/**
 * Ikabud Kernel — Auth Contract
 * 
 * Modules consume this interface for authentication and authorization.
 * They never touch the JWT, session, or cookie layer directly.
 * 
 * @package Ikabud\Kernel\Contracts
 */

namespace Ikabud\Kernel\Contracts;

interface AuthContract
{
    /**
     * Get the currently authenticated user, or null if unauthenticated.
     * Returns an associative array with at minimum: id, username, role, name.
     */
    public function user(): ?array;

    /**
     * Require authentication. Redirects or halts if not authenticated.
     * Returns the user array on success.
     */
    public function requireAuth(): array;

    /**
     * Require the user to have the specified role.
     * Halts with 403 if role does not match.
     */
    public function requireRole(string $role): array;

    /**
     * Require the user to have any of the specified roles.
     * Halts with 403 if no role matches.
     */
    public function requireAnyRole(string ...$roles): array;

    /**
     * Check if the current user has a specific role.
     */
    public function hasRole(string $role): bool;

    /**
     * Check if any user is currently authenticated.
     */
    public function isAuthenticated(): bool;
}
