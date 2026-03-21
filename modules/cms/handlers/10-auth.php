<?php

declare(strict_types=1);

function cmsLoginBridge(array $params = []): void
{
    // If already authenticated as CMS (or kernel admin), go straight to the dashboard.
    $u = cmsCtxUser();
    if (is_array($u) && (($u['source'] ?? '') === 'cms' || (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin'))) {
        cmsRedirect('/cms/admin');
        return;
    }

    echo cmsRender('modules/cms/pages/login.disyl', [
        'page_title' => 'Sign In',
    ]);
}
