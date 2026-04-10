<?php

declare(strict_types=1);

if (!function_exists('kernelHandlePageLogin')) {
    function kernelHandlePageLogin(): void
    {
        $loginUser = app()->user();
        if ($loginUser) {
            $loginHome = kernelResolveAuthenticatedHomeRedirect($loginUser, true) ?? '/';
            app()->redirect($loginHome);
            return;
        }

        $loginContext = [
            'page_title' => 'Sign In',
        ];
        $loginTenantId = app()->tenant()->current();
        if ($loginTenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
            $entryModuleId = tenantEntryModuleIdForTenant((int)$loginTenantId);
            if ($entryModuleId === 'wms' && function_exists('wmsLoginPageContext')) {
                $loginContext = wmsLoginPageContext();
            }
        }

        echo app()->render('pages/login.disyl', $loginContext);
    }
}