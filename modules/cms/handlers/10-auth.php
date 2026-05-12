<?php

declare(strict_types=1);

function cmsLoginBridge(array $params = []): void
{
    // If already authenticated as CMS (or kernel admin), go straight to the dashboard (or shop if no access).
    $u = cmsCtxUser();
    if (is_array($u)) {
        if (($u['source'] ?? '') === 'kernel' && in_array((string)($u['role'] ?? ''), ['admin', 'superadmin'], true)) {
            $target = kernelResolveAuthenticatedHomeRedirect($u, false) ?? '/cms/admin';
            cmsRedirect($target);
            return;
        }
        if (($u['source'] ?? '') === 'cms') {
            $target = kernelResolveAuthenticatedHomeRedirect($u, false) ?? '/cms/admin';
            cmsRedirect($target);
            return;
        }
    }

    $redirect = function_exists('cmsAuthRequestedRedirectPath') ? cmsAuthRequestedRedirectPath() : '';

    echo cmsRender('modules/cms/pages/login.disyl', [
        'page_title' => 'Sign In',
        'register_url' => function_exists('cmsAuthPublicPageUrl') ? cmsAuthPublicPageUrl('/cms/register', $redirect) : '/cms/register',
    ]);
}
