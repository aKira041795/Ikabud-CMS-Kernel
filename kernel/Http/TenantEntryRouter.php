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
                $this->logRewriteWarning('tenant_suspended', [
                    'host' => $host,
                    'uri' => $uri,
                    'tenant_id' => $tenantId,
                    'status' => $status,
                ]);
                return $uri;
            }

            $canonicalDomain = strtolower(trim((string)($row['canonical_domain'] ?? '')));
            if ($canonicalDomain !== '' && $canonicalDomain !== $host) {
                $_SERVER['IK_CANONICAL_DOMAIN'] = $canonicalDomain;
            }

            $entry = trim((string)($row['entry_module_id'] ?? ''));
            if ($entry === '') {
                return $uri;
            }

            if ($this->shouldFastReject($uri)) {
                $_SERVER['IK_FAST_404'] = '1';
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

        if (str_starts_with($entry, 'cms-akira-profile-')) {
            return '/cms/login';
        }

        static $landingCache = [];
        if (isset($landingCache[$entry])) {
            return $landingCache[$entry];
        }

        // Check APCu for cross-process cache before expensive routes.php load
        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled');
        $apcuKey = 'ikabud:entry_landing:v3:' . sha1($entry);
        if ($apcuEnabled) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success && is_string($cached)) {
                $landingCache[$entry] = $cached;
                return $cached;
            }
        }

        $entryRoot = '/' . $entry;
        $entryLogin = '/' . $entry . '/login';
        $result = '/login';
        // If the entry module declares an explicit root route, prefer it.
        // Otherwise prefer a conventional login route if it exists.
        try {
            if (defined('BASE_PATH')) {
                $routesFile = '';
                if (function_exists('modulePathForId')) {
                    $modulePath = modulePathForId($entry);
                    if (is_string($modulePath) && $modulePath !== '') {
                        $routesFile = rtrim($modulePath, '/') . '/routes.php';
                    }
                }
                if ($routesFile === '') {
                    $routesFile = rtrim((string)BASE_PATH, '/') . '/modules/' . $entry . '/routes.php';
                }
                if (is_file($routesFile)) {
                    $routes = require $routesFile;
                    $get = is_array($routes) ? ($routes['GET'] ?? []) : [];
                    if (is_array($get)) {
                        if (array_key_exists($entryRoot, $get)) {
                            $result = $entryRoot;
                        } elseif (array_key_exists($entryLogin, $get)) {
                            $result = $entryLogin;
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

        // Store in both caches for future lookups
        $landingCache[$entry] = $result;
        if ($apcuEnabled) {
            apcu_store($apcuKey, $result, 3600); // 1 hour TTL
        }

        return $result;
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
        // Root should always resolve through entryLandingPath() and does not need
        // expensive enabled-module route scanning.
        if ($uri === '/') {
            return false;
        }

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

        // Requests already under the entry module prefix should skip rewrite and
        // should not pay the cost of cross-module route pattern scanning.
        if ($uri === '/' . $entry || str_starts_with($uri, '/' . $entry . '/')) {
            return true;
        }

        // Never rewrite CMS module routes. CMS is an in-app module that must remain
        // accessible even when a host maps to an entry module via tenant domains.
        if ($uri === '/cms' || str_starts_with($uri, '/cms/') || str_starts_with($uri, '/api/v1/cms/')) {
            return true;
        }

        // Preserve public module routes even when they do not start with the
        // module ID, such as ecommerce's /store/{slug} storefront pages.
        if ($this->matchesEnabledModuleRoute($uri)) {
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

    private function matchesEnabledModuleRoute(string $uri): bool
    {
        if (!function_exists('getEnabledModules')) {
            return false;
        }

        $method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if ($method === '') {
            $method = 'GET';
        }

        $modules = getEnabledModules();
        $moduleIds = array_keys($modules);
        sort($moduleIds);

        static $patternsByCacheKey = [];
        $cacheKey = $method . ':' . implode(',', $moduleIds);
        if (!isset($patternsByCacheKey[$cacheKey])) {
            $patterns = [];
            foreach ($modules as $module) {
                $routesFile = rtrim((string)($module['_path'] ?? ''), '/') . '/routes.php';
                if ($routesFile === '' || !is_file($routesFile)) {
                    continue;
                }

                $routes = require $routesFile;
                $modulePatterns = is_array($routes) ? array_keys((array)($routes[$method] ?? [])) : [];
                foreach ($modulePatterns as $pattern) {
                    if (is_string($pattern) && $pattern !== '') {
                        $patterns[] = $pattern;
                    }
                }
            }
            $patternsByCacheKey[$cacheKey] = array_values(array_unique($patterns));
        }

        foreach ($patternsByCacheKey[$cacheKey] as $pattern) {
            if ($this->routePatternMatches($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function routePatternMatches(string $pattern, string $uri): bool
    {
        if ($pattern === $uri) {
            return true;
        }

        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        if (!is_string($regex) || $regex === '') {
            return false;
        }

        return preg_match('#^' . $regex . '$#', $uri) === 1;
    }

    private function shouldFastReject(string $uri): bool
    {
        $normalized = strtolower(trim($uri));
        if ($normalized === '' || $normalized === '/') {
            return false;
        }

        if (
            $normalized === '/favicon.ico'
            || $normalized === '/robots.txt'
            || $normalized === '/sitemap.xml'
            || $normalized === '/manifest.json'
            || $normalized === '/site.webmanifest'
            || str_starts_with($normalized, '/.well-known/')
            || str_starts_with($normalized, '/apple-touch-icon')
        ) {
            return false;
        }

        $firstSegment = (string) strtok(ltrim($normalized, '/'), '/');
        $basename = basename($normalized);

        $blockedFirstSegments = [
            '.git',
            '.env',
            'wp-admin',
            'wp-content',
            'wp-includes',
            'wordpress',
            'phpmyadmin',
            'pma',
            'adminer',
            'cgi-bin',
            'server-status',
            'server-info',
        ];
        if (in_array($firstSegment, $blockedFirstSegments, true)) {
            return true;
        }

        $blockedBasenames = [
            '.env',
            'wp-login.php',
            'xmlrpc.php',
            'swagger-ui.html',
            'trace.axd',
            'login.action',
            'server-status',
            'server-info',
        ];
        if (in_array($basename, $blockedBasenames, true)) {
            return true;
        }

        if (str_contains($normalized, '/.git/') || str_contains($normalized, '/.env')) {
            return true;
        }

        return preg_match('/\.(?:php\d*|phtml|phar|asp|aspx|axd|jsp|cgi|pl|env|sql|bak|old|ini|log|swp)$/i', $basename) === 1;
    }
}
