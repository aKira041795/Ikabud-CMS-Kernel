<?php

declare(strict_types=1);

function cmsRoleAtLeast(string $role, string $minimum): bool
{
    $roleLevel = CMS_ROLES[$role] ?? 0;
    $minLevel  = CMS_ROLES[$minimum] ?? 999;
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
    return cmsCtx()->user();
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
 * Check if the current user can edit a specific content item.
 * Contributors/authors can only edit their own content.
 */

function cmsCanEditContent(array $user, array $content): bool
{
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');

    // Kernel admin = full access
    if ($source === 'kernel' && $role === 'admin') {
        return true;
    }

    // Editor+ can edit anything
    if (cmsRoleAtLeast($role, 'editor')) {
        return true;
    }

    // Author/contributor can only edit own content
    $userId    = (int)($user['id'] ?? 0);
    $authorId  = (int)($content['author_id'] ?? 0);
    return $userId > 0 && $userId === $authorId;
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
