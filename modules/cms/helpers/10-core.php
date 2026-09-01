<?php

declare(strict_types=1);

/**
 * CMS admin URL prefix — single source of truth for admin route paths.
 * Use this instead of hardcoding '/cms/admin/' in breadcrumbs, redirects, and links.
 */
define('CMS_ADMIN_PATH', '/cms/admin');

function cmsNormalizeRole(string $role): string
{
    return \Ikabud\Cms\Cms::normalizeRole($role);
}

function cmsNormalizeUserContext(?array $user): ?array
{
    return \Ikabud\Cms\Cms::normalizeUser($user);
}

function cmsRoleAtLeast(string $role, string $minimum): bool
{
    return \Ikabud\Cms\Cms::roleAtLeast($role, $minimum);
}

function cmsIsLearnerRole(string $role): bool
{
    return \Ikabud\Cms\Cms::isLearnerRole($role);
}

function cmsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return \Ikabud\Cms\Cms::db();
}

function cmsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    return \Ikabud\Cms\Cms::ctx();
}

function cmsCtxUser(): ?array
{
    return \Ikabud\Cms\Cms::ctxUser();
}

function cmsInput(?string $key = null, mixed $default = null): mixed
{
    return \Ikabud\Cms\Cms::input($key, $default);
}

function cmsRender(string $template, array $context = []): string
{
    return \Ikabud\Cms\Cms::render($template, $context);
}

function cmsRedirect(string $url, int $status = 302): void
{
    \Ikabud\Cms\Cms::redirect($url, $status);
}

function cmsIsHtmx(): bool
{
    return \Ikabud\Cms\Cms::isHtmx();
}

function cmsHtmxResponse(array $headers = []): void
{
    \Ikabud\Cms\Cms::htmxResponse($headers);
}

// ── CMS SEO Helpers ─────────────────────────────────────────────────

function cmsUser(): ?array
{
    return \Ikabud\Cms\Cms::user();
}

/**
 * Require CMS authentication with a minimum role level.
 * Redirects to /cms/admin (login will catch unauthenticated) or returns 403.
 */

function cmsRequireRole(string $minimum = 'subscriber'): array
{
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isApiRoute = str_starts_with($requestUri, '/api/');
    $user = \Ikabud\Cms\Cms::ctxUser();
    if (!$user) {
        if ($isApiRoute) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Auth required']);
        } else {
            \Ikabud\Cms\Cms::redirect('/cms/login');
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
            echo \Ikabud\Cms\Cms::render('pages/404.disyl', ['page_title' => 'Access Denied']);
        }
        exit;
    }

    if (!\Ikabud\Cms\Cms::roleAtLeast($role, $minimum)) {
        http_response_code(403);
        if ($isApiRoute) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
        } else {
            echo \Ikabud\Cms\Cms::render('pages/404.disyl', ['page_title' => 'Access Denied']);
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
 * Permission gate for capability-level media reads (cms.media.get@1).
 * Kernel admins and CMS editors+ may read media records; any authenticated
 * CMS user may read public media metadata.
 */
function cmsCapCanReadMedia(array $user): bool
{
    $source = (string)($user['source'] ?? '');
    $role   = (string)($user['role'] ?? '');

    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }
    if ($source !== 'cms') {
        return false;
    }
    return cmsRoleAtLeast($role, 'subscriber');
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

    // Service tokens (CMS Assistant) can never publish.
    if (($user['is_service'] ?? false) === true) {
        return false;
    }

    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }

    return cmsRoleAtLeast($role, 'author');
}

/**
 * Normalize a publish datetime string from UI/API input.
 */
