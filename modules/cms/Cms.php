<?php

declare(strict_types=1);

namespace Ikabud\Cms;

use Ikabud\Kernel\Contracts\ModuleContext;
use Ikabud\Kernel\Contracts\ModuleDB;

/**
 * CMS Module Service — namespaced alternative to global cms*() functions.
 *
 * Provides the same functionality as the global CMS helper functions
 * but in a namespaced, autoloadable class. New code should prefer this
 * over calling global functions.
 *
 * Usage:
 *   Cms::db()       → same as cmsDb()
 *   Cms::ctx()      → same as cmsCtx()
 *   Cms::input()    → same as cmsInput()
 *   Cms::render()   → same as cmsRender()
 *   Cms::user()     → same as cmsUser()
 *
 * @package Ikabud\Cms
 */
final class Cms
{
    private static ?ModuleContext $context = null;

    /**
     * Get the CMS module context (lazy-loaded, cached per request).
     */
    public static function ctx(): ModuleContext
    {
        if (self::$context === null) {
            $ctx = \module('cms');
            if (!$ctx) {
                throw new \RuntimeException('CMS module context unavailable');
            }
            self::$context = $ctx;
        }
        return self::$context;
    }

    /**
     * Reset the cached context (useful for testing).
     */
    public static function reset(): void
    {
        self::$context = null;
    }

    /**
     * Get the CMS module database.
     */
    public static function db(): ModuleDB
    {
        return self::ctx()->db();
    }

    /**
     * Get a normalized CMS user from the current context.
     */
    public static function ctxUser(): ?array
    {
        return self::normalizeUser(self::ctx()->user());
    }

    /**
     * Get an input value from the CMS module context.
     */
    public static function input(?string $key = null, mixed $default = null): mixed
    {
        return self::ctx()->input($key, $default);
    }

    /**
     * Render a CMS template with the given context.
     */
    public static function render(string $template, array $context = []): string
    {
        return self::ctx()->render($template, $context);
    }

    /**
     * Redirect via the CMS module context.
     */
    public static function redirect(string $url, int $status = 302): void
    {
        self::ctx()->redirect($url, $status);
    }

    /**
     * Check if current request is HTMX via CMS context.
     */
    public static function isHtmx(): bool
    {
        return self::ctx()->isHtmx();
    }

    /**
     * Set HTMX response headers via CMS context.
     */
    public static function htmxResponse(array $headers = []): void
    {
        self::ctx()->htmxResponse($headers);
    }

    /**
     * Get the CMS-authenticated user (source='cms' only), or null.
     */
    public static function user(): ?array
    {
        $user = self::ctxUser();
        if (!$user || ($user['source'] ?? '') !== 'cms') {
            return null;
        }
        return $user;
    }

    /**
     * Normalize a user array: map 'superadmin' role to 'administrator'.
     */
    public static function normalizeUser(?array $user): ?array
    {
        if (!\is_array($user)) {
            return $user;
        }

        if ((string)($user['source'] ?? '') !== 'cms') {
            return $user;
        }

        $role = \trim((string)($user['role'] ?? ''));
        if ($role !== 'superadmin') {
            return $user;
        }

        $user['legacy_role'] = $role;
        $user['role'] = 'administrator';
        return $user;
    }

    /**
     * Normalize a role string: map 'superadmin' → 'administrator'.
     */
    public static function normalizeRole(string $role): string
    {
        $role = \trim($role);
        return $role === 'superadmin' ? 'administrator' : $role;
    }

    /**
     * Check if a role meets a minimum level.
     */
    public static function roleAtLeast(string $role, string $minimum): bool
    {
        $roleLevel = \CMS_ROLES[self::normalizeRole($role)] ?? 0;
        $minLevel  = \CMS_ROLES[self::normalizeRole($minimum)] ?? 999;
        return $roleLevel >= $minLevel;
    }

    /**
     * Check if a role is a learner-type (subscriber or customer).
     */
    public static function isLearnerRole(string $role): bool
    {
        $role = \trim($role);
        return $role === 'subscriber' || $role === 'customer';
    }
}
