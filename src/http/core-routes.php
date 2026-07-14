<?php

declare(strict_types=1);

if (!function_exists('kernelCoreRoutes')) {
    function kernelCoreRoutes(): array
    {
        return [
            'GET' => [
                '/' => 'pageHome',
                '/login' => 'pageLogin',
                '/forgot-password' => 'authForgotPasswordPage',
                '/reset-password' => 'authResetPasswordPage',
                '/auth/logout' => 'authLogout',
                '/api/v1/auth/logout' => 'authLogout',
                '/api/v1/health' => 'apiHealth',
                '/api/v1/platform' => 'apiPlatform',
                '/api/v1/me' => 'apiMe',
                '/api/v1/audit-log' => 'apiAuditLog',
                '/admin/profile' => 'pageAdminProfile',
                '/api/v1/admin/profile/update' => 'pageAdminProfile',
                '/admin/users' => 'pageAdminUsers',
                '/admin/modules' => 'pageAdminModules',
                '/admin/tenants' => 'pageAdminTenants',
                '/admin/kernel/triggers' => 'pageAdminKernelTriggers',
                '/admin/platform' => 'pageAdminPlatform',
                '/admin/ai' => 'pageAdminAi',
                '/superadmin/settings' => 'pageSuperadminSettings',
                '/kernel/integrations' => 'pageKernelIntegrations',
                '/api/v1/kernel/integrations' => 'apiKernelIntegrations',
                '/superadmin/perf' => 'pageSuperadminPerf',
                '/superadmin/cache' => 'pageSuperadminCache',
                '/superadmin/workbench' => 'pageSuperadminWorkbench',
                '/api/v1/superadmin/workbench/keys' => 'apiSuperadminWorkbenchKeys',
                '/api/v1/superadmin/workbench/test-results' => 'apiSuperadminWorkbenchTestResults',
                '/api/v1/superadmin/workbench/runs' => 'apiSuperadminWorkbenchRuns',
                '/api/v1/superadmin/workbench/issues' => 'apiSuperadminWorkbenchIssues',
                '/api/v1/superadmin/workbench/modules' => 'apiSuperadminWorkbenchModules',
                '/api/v1/superadmin/workbench/coverage' => 'apiSuperadminWorkbenchCoverage',
                '/api/v1/superadmin/workbench/contracts' => 'apiSuperadminWorkbenchContracts',
                '/api/v1/superadmin/modules' => 'apiSuperadminModules',
                '/api/v1/superadmin/perf' => 'apiSuperadminPerf',
                '/api/v1/superadmin/cache' => 'apiSuperadminCache',
                '/api/v1/kernel/modules' => 'apiKernelModulesCatalog',
                '/api/v1/kernel/capabilities' => 'apiKernelCapabilityCatalog',
                '/api/v1/admin/modules' => 'apiListModules',
                '/api/v1/admin/modules/health' => 'apiModulesHealth',
                '/api/v1/admin/capabilities' => 'apiListCapabilities',
                '/api/v1/admin/capabilities/metrics' => 'apiCapabilityMetrics',
                '/api/v1/admin/capabilities/breakers' => 'apiCapabilityBreakers',
                '/api/v1/admin/cache/health' => 'apiCacheHealth',
                '/api/v1/admin/kernel/events' => 'apiKernelEventsList',
                '/api/v1/admin/kernel/triggers' => 'apiKernelTriggersList',
                '/api/v1/admin/kernel/trigger-executions' => 'apiKernelTriggerExecutionsList',
                '/api/v1/admin/ai/settings' => 'apiAiSettingsGet',
                '/api/v1/admin/tenants' => 'apiTenantsList',
            ],
            'POST' => [
                '/auth/login' => 'authLogin',
                '/api/v1/auth/login' => 'authLogin',
                '/api/v1/auth/forgot-password' => 'authForgotPassword',
                '/api/v1/auth/reset-password' => 'authResetPassword',
                '/api/v1/auth/refresh' => 'authRefresh',
                '/api/v1/kernel/integrations' => 'apiKernelIntegrations',
                '/api/v1/admin/modules/install' => 'apiInstallModule',
                '/api/v1/admin/modules/enable' => 'apiEnableModule',
                '/api/v1/admin/modules/disable' => 'apiDisableModule',
                '/api/v1/admin/modules/settings' => 'apiUpdateModuleSettings',
                '/api/v1/superadmin/modules/access-request' => 'apiSuperadminReviewModuleAccessRequest',
                '/api/v1/superadmin/modules/catalog' => 'apiSuperadminUpdateModuleCatalog',
                '/api/v1/superadmin/modules/settings' => 'apiSuperadminUpdateModuleSettings',
                '/api/v1/superadmin/modules/entitlement' => 'apiSuperadminSetModuleEntitlement',
                '/api/v1/superadmin/modules/toggle' => 'apiSuperadminToggleModule',
                '/api/v1/superadmin/cache/flush' => 'apiSuperadminCacheFlush',
                '/api/v1/superadmin/workbench/keys' => 'apiSuperadminWorkbenchKeys',
                '/api/v1/superadmin/workbench/trigger-tests' => 'apiSuperadminWorkbenchTriggerTests',
                '/api/v1/superadmin/workbench/ai-settings' => 'apiSuperadminWorkbenchAiSettings',
                '/api/v1/superadmin/workbench/run' => 'apiSuperadminWorkbenchRunDetail',
                '/api/v1/superadmin/services/health' => 'apiSuperadminServiceHealth',
                '/api/v1/superadmin/services/diagnostics' => 'apiSuperadminServiceProxyDiagnostics',
                '/api/v1/superadmin/capabilities/trace' => 'apiSuperadminCapabilityTrace',
                '/api/v1/superadmin/breakers' => 'apiSuperadminBreakers',
                '/api/v1/superadmin/breakers/reset' => 'apiSuperadminBreakersReset',
                '/api/v1/superadmin/entity-views/debug' => 'apiSuperadminEntityViewDebug',
                '/api/v1/superadmin/reports/templates' => 'apiSuperadminReportTemplates',
                '/api/v1/superadmin/reports/archive' => 'apiSuperadminReportArchive',
                '/api/v1/superadmin/reports/packs' => 'apiSuperadminReportPacks',
                '/api/v1/superadmin/reports/schedule' => 'apiSuperadminReportSchedule',
                '/api/v1/superadmin/reports/consistency' => 'apiSuperadminReportConsistencyCheck',
                '/api/v1/superadmin/reports/signature-presets' => 'apiSuperadminSignaturePresets',
                '/api/v1/superadmin/ai/config' => 'apiSuperadminAiConfig',
                '/api/v1/superadmin/ai/tenant-settings' => 'apiSuperadminAiTenantSettings',
                '/api/v1/superadmin/ai/capability-policy' => 'apiSuperadminAiCapabilityPolicy',
                '/api/v1/superadmin/ai/usage' => 'apiSuperadminAiUsage',
                '/api/v1/superadmin/ai/prompts' => 'apiSuperadminAiPrompts',
                '/api/v1/superadmin/ai/redaction' => 'apiSuperadminAiRedaction',
                '/api/v1/superadmin/ai/review-queue' => 'apiSuperadminAiReviewQueue',
                '/api/v1/superadmin/ai/audit' => 'apiSuperadminAiAudit',
                '/api/v1/superadmin/ai/certify' => 'apiSuperadminAiCertify',
                '/api/v1/admin/ai/settings' => 'apiAiSettingsSave',
                '/api/v1/admin/cache/clear' => 'apiCacheClear',
                '/api/v1/admin/updates/check' => 'apiAdminCheckUpdates',
                '/api/v1/admin/profile/update' => 'apiAdminUpdateProfile',
                '/api/v1/admin/users' => 'apiAdminCreateUser',
                '/api/v1/admin/users/update' => 'apiAdminUpdateUser',
                '/api/v1/admin/capabilities/breakers/reset' => 'apiCapabilityBreakersReset',
                '/api/v1/admin/capabilities/policy' => 'apiUpdateCapabilityPolicy',
                '/api/v1/admin/modules/depends' => 'apiUpdateModuleDepends',
                '/api/v1/admin/kernel/triggers/save' => 'apiKernelTriggerSave',
                '/api/v1/admin/kernel/triggers/delete' => 'apiKernelTriggerDelete',
                '/api/v1/admin/kernel/triggers/suggest' => 'apiKernelTriggersSuggest',
                '/api/v1/admin/tenants/create' => 'apiTenantCreate',
                '/api/v1/entity/update' => 'apiEntityUpdate',
                '/api/v1/admin/tenants/entry-module' => 'apiTenantEntryModuleSet',
                '/api/v1/admin/tenants/domain/add' => 'apiTenantDomainAdd',
                '/api/v1/admin/tenants/domain/remove' => 'apiTenantDomainRemove',
                '/api/v1/admin/tenants/canonical-domain' => 'apiTenantCanonicalDomainSet',
                '/api/v1/admin/tenants/db/upsert' => 'apiTenantDbUpsert',
                '/api/v1/admin/tenants/status' => 'apiTenantStatusSet',
                '/api/v1/admin/tenants/delete' => 'apiTenantDelete',
                '/api/v1/admin/tenants/admin-email' => 'apiTenantAdminEmailPush',
                '/api/v1/admin/tenants/admin-password' => 'apiTenantAdminPasswordPush',
                '/api/v1/admin/tenants/seed-data' => 'apiTenantSeedData',
            ],
            'PUT' => [],
            'DELETE' => [
                '/api/v1/kernel/integrations' => 'apiKernelIntegrations',
            ],
        ];
    }
}

/**
 * Route metadata for enhanced API behavior.
 *
 * This map serves as the source of truth for:
 * - API format detection (JSON vs HTML)
 * - Authentication requirements
 * - Stateless (no session) designation
 * - API version
 * - OpenAPI schema generation
 *
 * Routes without metadata fall back to URL-prefix detection.
 * Add entries incrementally as routes are migrated.
 *
 * @return array<string, array<string, array>> Map of 'METHOD:/path' => metadata[]
 */
if (!function_exists('kernelRouteMeta')) {
    function kernelRouteMeta(): array
    {
        return [
            // ── Auth ──────────────────────────────────────
            'GET:/api/v1/health' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            'POST:/api/v1/auth/login' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            'POST:/api/v1/auth/refresh' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            'POST:/api/v1/auth/forgot-password' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            'POST:/api/v1/auth/reset-password' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            'GET:/api/v1/auth/logout' => [
                'format' => 'json',
                'auth' => true,
                'stateless' => true,
                'version' => 'v1',
            ],
            // ── User ──────────────────────────────────────
            'GET:/api/v1/me' => [
                'format' => 'json',
                'auth' => true,
                'stateless' => true,
                'version' => 'v1',
            ],
            // ── Health ────────────────────────────────────
            'GET:/api/v1/platform' => [
                'format' => 'json',
                'auth' => false,
                'stateless' => true,
                'version' => 'v1',
            ],
            // ── Superadmin ────────────────────────────────
            'GET:/api/v1/superadmin/modules' => [
                'format' => 'json',
                'auth' => true,
                'stateless' => true,
                'version' => 'v1',
            ],
            // ── Admin ─────────────────────────────────────
            'GET:/api/v1/admin/modules' => [
                'format' => 'json',
                'auth' => true,
                'stateless' => true,
                'version' => 'v1',
            ],
        ];
    }
}