<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

use Ikabud\Kernel\TenantResolver;
use Throwable;

class TenantEntryRouter
{
    public function rewriteUri(string $uri): string
    {
        $uri = $uri === '' ? '/' : $uri;
        if ($uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        $host = TenantResolver::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return $uri;
        }

        try {
            $row = TenantResolver::lookupControlHostRecord($host);

            if (!is_array($row)) {
                return $uri;
            }

            $tenantId = isset($row['tenant_id']) ? (int)$row['tenant_id'] : 0;
            if ($tenantId > 0) {
                app()->tenant()->setTenantId($tenantId);
            }

            $status = strtolower(trim((string)($row['status'] ?? 'active')));
            if ($status !== 'active') {
                $_SERVER['IK_TENANT_SUSPENDED'] = '1';
                return $uri;
            }

            $entry = trim((string)($row['entry_module_id'] ?? ''));
            if ($entry === '') {
                return $uri;
            }

            if ($this->shouldSkipRewrite($uri, $entry)) {
                return $uri;
            }

            if (!$this->entryModuleAvailable($entry)) {
                $_SERVER['IK_ENTRY_MODULE_UNAVAILABLE'] = '1';
                $_SERVER['IK_ENTRY_MODULE_ID'] = $entry;
                $this->logRewriteWarning('tenant_entry_module_unavailable', [
                    'host' => $host,
                    'uri' => $uri,
                    'tenant_id' => $tenantId,
                    'entry_module_id' => $entry,
                    'skipped_modules' => function_exists('getSkippedModules') ? array_values(getSkippedModules()) : [],
                ]);
                return $uri;
            }

            if ($uri === '/') {
                return $this->entryLandingPath($entry);
            }

            return '/' . $entry . $uri;
        } catch (Throwable $e) {
            $this->logRewriteWarning('tenant_rewrite_fallback', [
                'host' => $host,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);
            return $uri;
        }
    }

    private function entryLandingPath(string $entry): string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return '/';
        }

        $entryRoot = '/' . $entry;
        $entryLogin = '/' . $entry . '/login';

        // If the entry module declares an explicit root route, prefer it.
        // Otherwise prefer a conventional login route if it exists.
        try {
            if (defined('BASE_PATH')) {
                $routesFile = rtrim((string)BASE_PATH, '/') . '/modules/' . $entry . '/routes.php';
                if (is_file($routesFile)) {
                    $routes = require $routesFile;
                    $get = is_array($routes) ? ($routes['GET'] ?? []) : [];
                    if (is_array($get)) {
                        if (array_key_exists($entryRoot, $get)) {
                            return $entryRoot;
                        }
                        if (array_key_exists($entryLogin, $get)) {
                            return $entryLogin;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logRewriteWarning('tenant_entry_landing_resolution_failed', [
                'entry_module_id' => $entry,
                'error' => $e->getMessage(),
            ]);
        }

        return $entryRoot;
    }

    private function entryModuleAvailable(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!function_exists('moduleIsLoadable')) {
            return true;
        }

        return moduleIsLoadable($entry);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRewriteWarning(string $message, array $context): void
    {
        if (!function_exists('write_log')) {
            return;
        }

        write_log($message, 'warning', $context);
    }

    private function shouldSkipRewrite(string $uri, string $entry): bool
    {
        if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/admin/') || str_starts_with($uri, '/assets/') || str_starts_with($uri, '/superadmin/')) {
            return true;
        }

        // Never rewrite kernel auth endpoints.
        // These must remain stable across all hosts/tenants.
        if (
            $uri === '/login'
            || $uri === '/auth/login'
            || $uri === '/auth/logout'
            || str_starts_with($uri, '/auth/')
            || str_starts_with($uri, '/api/v1/auth/')
        ) {
            return true;
        }

        // Never rewrite CMS module routes. CMS is an in-app module that must remain
        // accessible even when a host maps to an entry module via tenant domains.
        if ($uri === '/cms' || str_starts_with($uri, '/cms/') || str_starts_with($uri, '/api/v1/cms/')) {
            return true;
        }

        if ($uri === '/' . $entry || str_starts_with($uri, '/' . $entry . '/')) {
            return true;
        }

        // Never rewrite URIs whose first path segment is another enabled module.
        // Each module owns its own route prefix (e.g. /ecommerce/*, /contact-form/*).
        $firstSegment = strtok(ltrim($uri, '/'), '/');
        if ($firstSegment !== false && $firstSegment !== '' && function_exists('moduleIsLoadable') && moduleIsLoadable($firstSegment)) {
            return true;
        }

        return false;
    }
}
