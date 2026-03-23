<?php

declare(strict_types=1);

return [
    'GET' => [
        // ── Admin routes ─────────────────────────────────────────
        '/cms/login'                      => 'cms:cmsLoginBridge',
        '/cms/forgot-password'            => 'cms:cmsForgotPasswordPage',
        '/cms/reset-password'             => 'cms:cmsResetPasswordPage',
        '/cms/admin'                      => 'cms:cmsAdminDashboard',
        '/cms/admin/content'              => 'cms:cmsAdminContentList',
        '/cms/admin/content/create'       => 'cms:cmsAdminContentCreate',
        '/cms/admin/content/edit/{id}'    => 'cms:cmsAdminContentEdit',
        '/cms/admin/page-builder/create'  => 'cms:cmsAdminReactBuilderCreate',
        '/cms/admin/page-builder/{id}'    => 'cms:cmsAdminReactBuilderEdit',
        '/cms/admin/react-builder/create' => 'cms:cmsAdminReactBuilderCreate',
        '/cms/admin/react-builder/{id}'   => 'cms:cmsAdminReactBuilderEdit',
        '/cms/admin/media'                => 'cms:cmsAdminMedia',
        '/cms/admin/users'                => 'cms:cmsAdminUsers',
        '/cms/admin/settings'             => 'cms:cmsAdminSettings',
        '/cms/admin/ai-automation'        => 'cms:cmsAdminAiAutomation',
        '/cms/admin/content-types'         => 'cms:cmsAdminContentTypes',
        '/cms/admin/categories'            => 'cms:cmsAdminCategories',
        '/cms/admin/menus'                 => 'cms:cmsAdminMenus',
        '/cms/admin/customize'             => 'cms:cmsAdminCustomizer',
        '/cms/admin/redirects'             => 'cms:cmsAdminRedirects',
        '/cms/admin/import-export'         => 'cms:cmsAdminImportExport',
        '/cms/admin/permissions'           => 'cms:cmsAdminPermissions',
        '/cms/admin/themes'                => 'cms:cmsAdminThemes',
        '/cms/admin/modules'               => 'cms:cmsAdminModules',

        // ── Admin API (GET) ──────────────────────────────────────
        '/api/v1/cms/redirects'            => 'cms:cmsApiRedirectList',
        '/api/v1/cms/export'               => 'cms:cmsApiExport',

        // ── Themes API (GET) ──────────────────────────────────────
        '/api/v1/cms/themes'               => 'cms:cmsApiThemeList',
        '/api/v1/cms/content'             => 'cms:cmsApiContentList',
        '/api/v1/cms/content/{id}'        => 'cms:cmsApiContentGet',
        '/api/v1/cms/content/{id}/permalink' => 'cms:cmsApiContentPermalink',
        '/api/v1/cms/content/{id}/builder' => 'cms:cmsApiBuilderDocumentGet',
        '/api/v1/cms/content/{id}/builder/preview' => 'cms:cmsApiBuilderDocumentPreview',
        '/api/v1/cms/content/{id}/builder/revisions' => 'cms:cmsApiBuilderRevisionList',
        '/api/v1/cms/content/{id}/workflow' => 'cms:cmsApiContentWorkflowState',
        '/api/v1/cms/content/{id}/ai/summary' => 'cms:cmsApiContentAiSummary',
        '/api/v1/cms/content/{id}/ai/seo'     => 'cms:cmsApiContentAiSeo',
        '/api/v1/cms/ai/plans'               => 'cms:cmsApiAiPlanList',
        '/api/v1/cms/ai/plans/{id}'          => 'cms:cmsApiAiPlanGet',
        '/api/v1/cms/ai/runs'                => 'cms:cmsApiAiRunList',
        '/api/v1/cms/content/{id}/revisions'  => 'cms:cmsApiRevisionList',
        '/api/v1/cms/revisions/{id}'          => 'cms:cmsApiRevisionGet',
        '/api/v1/cms/media'               => 'cms:cmsApiMediaList',
        '/api/v1/cms/builder/reusable-sections' => 'cms:cmsApiBuilderReusableList',
        '/api/v1/cms/builder/templates' => 'cms:cmsApiBuilderTemplateList',
        '/api/v1/cms/builder/templates/{id}' => 'cms:cmsApiBuilderTemplateGet',
        '/api/v1/cms/builder/widgets' => 'cms:cmsApiBuilderWidgetList',
        '/api/v1/cms/builder/dynamic-sources' => 'cms:cmsApiBuilderDynamicSources',
        '/api/v1/cms/content-types'        => 'cms:cmsApiContentTypesList',
        '/api/v1/cms/categories'              => 'cms:cmsApiCategoryList',
        '/api/v1/cms/content-types/{id}/fields' => 'cms:cmsApiContentTypeFieldsList',

        // ── Public Headless API (GET) ────────────────────────────
        '/api/v1/cms/public/posts'        => 'cms:cmsApiPublicPosts',
        '/api/v1/cms/public/posts/{slug}' => 'cms:cmsApiPublicPostBySlug',
        '/api/v1/cms/public/pages/{slug}' => 'cms:cmsApiPublicPageBySlug',
        '/api/v1/cms/public/content/{type}' => 'cms:cmsApiPublicContentByType',

        // ── Public routes ────────────────────────────────────────
        '/cms'                            => 'cms:cmsPublicHome',
        '/cms/blog'                       => 'cms:cmsPublicArchive',
        '/cms/sitemap.xml'                => 'cms:cmsPublicSitemapXml',
        '/cms/feed'                        => 'cms:cmsPublicRssFeed',
        '/cms/search'                      => 'cms:cmsPublicSearch',
        '/cms/category/{slug}'             => 'cms:cmsPublicCategoryArchive',
        '/cms/tag/{slug}'                  => 'cms:cmsPublicTagArchive',
        '/cms/blog/{slug}'                => 'cms:cmsPublicSingle',
        '/cms/page/{slug}'                => 'cms:cmsPublicPage',

        // ── Tags API (GET) ─────────────────────────────────────
        '/api/v1/cms/tags'                => 'cms:cmsApiTagList',

        // ── Menu API (GET) ─────────────────────────────────────
        '/api/v1/cms/menus'               => 'cms:cmsApiMenuList',
        '/api/v1/cms/menus/locations'     => 'cms:cmsApiMenuLocations',
        '/api/v1/cms/menus/{location}'    => 'cms:cmsApiMenuGet',

        // ── Saved Blocks API (GET) ────────────────────────────────
        '/api/v1/cms/saved-blocks'        => 'cms:cmsApiSavedBlockList',

        // ── Theme Customizer API (GET) ──────────────────────────
        '/api/v1/cms/customizer/{section}' => 'cms:cmsApiCustomizerGet',
        '/api/v1/cms/customizer/footer/preview' => 'cms:cmsApiCustomizerFooterPreview',
        '/api/v1/cms/permissions'           => 'cms:cmsApiPermissionsGet',
    ],
    'POST' => [
        '/api/v1/cms/auth/forgot-password' => 'cms:cmsApiForgotPassword',
        '/api/v1/cms/auth/reset-password'  => 'cms:cmsApiResetPassword',
        '/api/v1/cms/auth/test-reset-email' => 'cms:cmsApiTestResetEmail',
        // ── Content API ──────────────────────────────────────────
        '/api/v1/cms/content'             => 'cms:cmsApiContentCreate',
        '/api/v1/cms/content/bulk'            => 'cms:cmsApiContentBulk',
        '/api/v1/cms/content/publish-scheduled' => 'cms:cmsApiContentPublishScheduled',
        '/api/v1/cms/content/{id}'        => 'cms:cmsApiContentUpdate',
        '/api/v1/cms/content/{id}/builder' => 'cms:cmsApiBuilderDocumentSave',
        '/api/v1/cms/content/{id}/builder/autosave' => 'cms:cmsApiBuilderAutosave',
        '/api/v1/cms/content/{id}/builder/publish' => 'cms:cmsApiBuilderDocumentPublish',
        '/api/v1/cms/content/{id}/builder/revisions/{revisionId}/restore' => 'cms:cmsApiBuilderRevisionRestore',
        '/api/v1/cms/builder/reusable-sections' => 'cms:cmsApiBuilderReusableSave',
        '/api/v1/cms/builder/reusable-sections/{id}/delete' => 'cms:cmsApiBuilderReusableDelete',
        '/api/v1/cms/builder/templates' => 'cms:cmsApiBuilderTemplateSave',
        '/api/v1/cms/builder/templates/{id}/delete' => 'cms:cmsApiBuilderTemplateDelete',
        '/api/v1/cms/content/{id}/trash'  => 'cms:cmsApiContentTrash',
        '/api/v1/cms/content/{id}/publish'=> 'cms:cmsApiContentPublish',
        '/api/v1/cms/content/{id}/restore'=> 'cms:cmsApiContentRestore',
        '/api/v1/cms/content/{id}/workflow/transition' => 'cms:cmsApiContentWorkflowTransition',
        '/api/v1/cms/content/{id}/ai/summary' => 'cms:cmsApiContentAiSummary',
        '/api/v1/cms/content/{id}/ai/seo'     => 'cms:cmsApiContentAiSeo',
        '/api/v1/cms/content/{id}/ai/refine'  => 'cms:cmsApiAiContentRefine',
        '/api/v1/cms/ai/plans'               => 'cms:cmsApiAiPlanCreate',
        '/api/v1/cms/ai/plans/{id}'          => 'cms:cmsApiAiPlanUpdate',
        '/api/v1/cms/ai/plans/{id}/toggle'   => 'cms:cmsApiAiPlanToggle',
        '/api/v1/cms/ai/plans/{id}/run'      => 'cms:cmsApiAiPlanRunNow',
        '/api/v1/cms/ai/plans/{id}/delete'   => 'cms:cmsApiAiPlanDelete',
        '/api/v1/cms/content/{id}/revisions/{rid}/restore' => 'cms:cmsApiRevisionRestore',
        '/api/v1/cms/content/{id}/autosave'   => 'cms:cmsApiContentAutosave',
        '/api/v1/cms/content/{id}/duplicate'  => 'cms:cmsApiContentDuplicate',

        // ── Media API ────────────────────────────────────────────
        '/api/v1/cms/media/upload'        => 'cms:cmsApiMediaUpload',
        '/api/v1/cms/media/{id}/edit'     => 'cms:cmsApiMediaEdit',
        '/api/v1/cms/media/{id}/delete'   => 'cms:cmsApiMediaDelete',

        // ── User API ─────────────────────────────────────────────
        '/api/v1/cms/users'               => 'cms:cmsApiUserCreate',
        '/api/v1/cms/users/{id}'          => 'cms:cmsApiUserUpdate',

        // ── Settings API ─────────────────────────────────────────
        '/api/v1/cms/settings'            => 'cms:cmsApiSettingsSave',
        '/api/v1/cms/settings/reset'      => 'cms:cmsApiSettingsReset',

        // ── Content Type Registry API ────────────────────────────
        '/api/v1/cms/content-types'        => 'cms:cmsApiContentTypeUpsert',
        '/api/v1/cms/content-types/{id}/delete' => 'cms:cmsApiContentTypeDelete',
        '/api/v1/cms/content-types/{id}/fields' => 'cms:cmsApiFieldDefinitionUpsert',
        '/api/v1/cms/fields/{id}/delete'   => 'cms:cmsApiFieldDefinitionDelete',

        // ── Category API ────────────────────────────────────────
        '/api/v1/cms/categories'              => 'cms:cmsApiCategoryCreate',
        '/api/v1/cms/categories/{id}'         => 'cms:cmsApiCategoryUpdate',
        '/api/v1/cms/categories/{id}/delete'  => 'cms:cmsApiCategoryDelete',

        // ── Tag API ────────────────────────────────────────────
        '/api/v1/cms/tags'                    => 'cms:cmsApiTagCreate',
        '/api/v1/cms/tags/{id}/delete'        => 'cms:cmsApiTagDelete',

        // ── Menu API ──────────────────────────────────────────
        '/api/v1/cms/menus'                   => 'cms:cmsApiMenuSave',
        '/api/v1/cms/menus/create'            => 'cms:cmsApiMenuCreate',
        '/api/v1/cms/menus/locations/assign'  => 'cms:cmsApiMenuLocationAssign',
        '/api/v1/cms/menus/{id}'              => 'cms:cmsApiMenuSave',
        '/api/v1/cms/menus/{id}/delete'       => 'cms:cmsApiMenuDelete',

        // ── Saved Blocks API ─────────────────────────────────────
        '/api/v1/cms/saved-blocks'            => 'cms:cmsApiSavedBlockCreate',
        '/api/v1/cms/saved-blocks/{id}'       => 'cms:cmsApiSavedBlockUpdate',
        '/api/v1/cms/saved-blocks/{id}/delete' => 'cms:cmsApiSavedBlockDelete',

        // ── Theme Customizer API ─────────────────────────────────
        '/api/v1/cms/customizer/{section}'     => 'cms:cmsApiCustomizerSave',

        // ── Redirect API ─────────────────────────────────────────
        '/api/v1/cms/redirects'                => 'cms:cmsApiRedirectCreate',
        '/api/v1/cms/redirects/{id}/delete'    => 'cms:cmsApiRedirectDelete',

        // ── Import / Export API ───────────────────────────────────
        '/api/v1/cms/import'                   => 'cms:cmsApiImport',

        // ── Permissions API ────────────────────────────────────────
        '/api/v1/cms/permissions'               => 'cms:cmsApiPermissionsSave',
        '/api/v1/cms/permissions/reset'         => 'cms:cmsApiPermissionsReset',

        // ── Theme Installer API ────────────────────────────────────
        '/api/v1/cms/themes/upload'             => 'cms:cmsApiThemeUpload',
        '/api/v1/cms/themes/activate'           => 'cms:cmsApiThemeActivate',
        '/api/v1/cms/themes/{slug}/delete'      => 'cms:cmsApiThemeDelete',

        // ── Module Installer API ───────────────────────────────────
        '/api/v1/cms/modules/upload'                    => 'cms:cmsApiModuleUpload',
        '/api/v1/cms/modules/toggle'                    => 'cms:cmsApiModuleToggle',
        '/api/v1/cms/modules/{module_id}/settings'      => 'cms:cmsApiModuleSettingsSave',
        '/api/v1/cms/modules/{module_id}/delete'        => 'cms:cmsApiModuleDelete',
    ],
];
