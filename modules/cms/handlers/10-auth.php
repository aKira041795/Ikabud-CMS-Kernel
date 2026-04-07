<?php

declare(strict_types=1);

function cmsLoginBridge(array $params = []): void
{
    // If already authenticated as CMS (or kernel admin), go straight to the dashboard (or shop if no access).
    $u = cmsCtxUser();
    if (is_array($u)) {
        if (($u['source'] ?? '') === 'kernel' && ($u['role'] ?? '') === 'admin') {
            cmsRedirect('/cms/admin');
            return;
        }
        if (($u['source'] ?? '') === 'cms') {
            if (cmsUserCan($u, 'dashboard.view')) {
                cmsRedirect('/cms/admin');
            } else {
                cmsRedirect('/ecommerce/shop');
            }
            return;
        }
    }

    echo cmsRender('modules/cms/pages/login.disyl', [
        'page_title' => 'Sign In',
    ]);
}
