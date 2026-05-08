<?php

declare(strict_types=1);

function cmsNormalizeRole(string $role): string
{
    $role = trim($role);
    return $role === 'superadmin' ? 'administrator' : $role;
}

function cmsNormalizeUserContext(?array $user): ?array
{
    if (!is_array($user)) {
        return $user;
    }

    if ((string)($user['source'] ?? '') !== 'cms') {
        return $user;
    }

    $role = trim((string)($user['role'] ?? ''));
    $normalizedRole = cmsNormalizeRole($role);
    if ($normalizedRole === $role) {
        return $user;
    }

    $user['legacy_role'] = $role;
    $user['role'] = $normalizedRole;
    return $user;
}

function cmsRoleAtLeast(string $role, string $minimum): bool
{
    $roleLevel = CMS_ROLES[cmsNormalizeRole($role)] ?? 0;
    $minLevel  = CMS_ROLES[cmsNormalizeRole($minimum)] ?? 999;
    return $roleLevel >= $minLevel;
}

function cmsIsLearnerRole(string $role): bool
{
    $role = trim($role);
    return $role === 'subscriber' || $role === 'customer';
}

function cmsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('cms');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx->db();
}

function cmsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function cmsCtxUser(): ?array
{
    return cmsNormalizeUserContext(cmsCtx()->user());
}

function cmsInput(?string $key = null, mixed $default = null): mixed
{
    return cmsCtx()->input($key, $default);
}

function cmsRender(string $template, array $context = []): string
{
    return cmsCtx()->render($template, $context);
}

function cmsRedirect(string $url, int $status = 302): void
{
    cmsCtx()->redirect($url, $status);
}

function cmsIsHtmx(): bool
{
    return cmsCtx()->isHtmx();
}

function cmsHtmxResponse(array $headers = []): void
{
    cmsCtx()->htmxResponse($headers);
}

// ── CMS SEO Helpers ─────────────────────────────────────────────────

function cmsUser(): ?array
{
    $user = cmsCtxUser();
    if (!$user) {
        return null;
    }
    // CMS users have source=cms in their JWT payload
    if (($user['source'] ?? '') !== 'cms') {
        return null;
    }
    return $user;
}

/**
 * Require CMS authentication with a minimum role level.
 * Redirects to /cms/admin (login will catch unauthenticated) or returns 403.
 */

function cmsRequireRole(string $minimum = 'subscriber'): array
{
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isApiRoute = str_starts_with($requestUri, '/api/');
    $user = cmsCtxUser();
    if (!$user) {
        if ($isApiRoute) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Auth required']);
        } else {
            cmsRedirect('/cms/login');
        }
        exit;
    }

    $source = (string)($user['source'] ?? '');
    $role   = (string)($user['role'] ?? '');

    // Kernel admins get superadmin-equivalent access to CMS
    if ($source === 'kernel' && $role === 'admin') {
        return $user;
    }

    // Must be a CMS user
    if ($source !== 'cms') {
        http_response_code(403);
        if ($isApiRoute) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
        } else {
            echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        }
        exit;
    }

    if (!cmsRoleAtLeast($role, $minimum)) {
        http_response_code(403);
        if ($isApiRoute) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
        } else {
            echo cmsRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        }
        exit;
    }

    return $user;
}

/**
 * Check if the current user can access any content item without author scoping.
 */

function cmsCanAccessAnyContent(array $user): bool
{
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');

    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }

    if ($source !== 'cms') {
        return false;
    }

    return cmsRoleAtLeast($role, 'editor');
}

function cmsCapabilityCallerUser(): ?array
{
    $ctx = function_exists('capability_call_context') ? capability_call_context() : null;
    if (!is_array($ctx) && is_array($GLOBALS['_capability_call_context'] ?? null)) {
        $ctx = $GLOBALS['_capability_call_context'];
    }
    if (is_array($ctx) && is_array($ctx['user'] ?? null)) {
        return $ctx['user'];
    }

    $user = function_exists('app') ? app()->user() : null;
    return is_array($user) ? $user : null;
}

/**
 * Returns the author scope for content queries.
 * Non-editor CMS users are restricted to their own authored content.
 */

function cmsScopedContentAuthorId(array $user): ?int
{
    $source = (string)($user['source'] ?? '');

    if (cmsCanAccessAnyContent($user)) {
        return null;
    }

    return $source === 'cms' ? (int)($user['id'] ?? 0) : 0;
}

/**
 * Check if the current user can read a specific content item.
 * Contributors/authors can only read their own content in admin/API flows.
 */

function cmsCanReadContent(array $user, array $content): bool
{
    $source = (string)($user['source'] ?? '');

    if (cmsCanAccessAnyContent($user)) {
        return true;
    }

    if ($source !== 'cms') {
        return false;
    }

    $userId   = (int)($user['id'] ?? 0);
    $authorId = (int)($content['author_id'] ?? 0);

    return $userId > 0 && $userId === $authorId;
}

/**
 * Check if the current user can edit a specific content item.
 * Contributors/authors can only edit their own content.
 */

function cmsCanEditContent(array $user, array $content): bool
{
    return cmsCanReadContent($user, $content);
}

/**
 * Check if the current user can publish content.
 */

function cmsCanPublish(array $user): bool
{
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');

    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }

    return cmsRoleAtLeast($role, 'author');
}

/**
 * Normalize a publish datetime string from UI/API input.
 */
