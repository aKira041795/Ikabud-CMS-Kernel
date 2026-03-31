<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$db = app()->db();
$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function captureOutput(callable $callback): string
{
    ob_start();
    try {
        $callback();
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$suffix = strtolower(substr(bin2hex(random_bytes(5)), 0, 10));
$postSlug = 'test-entity-contract-post-' . $suffix;
$pageSlug = 'test-entity-contract-page-' . $suffix;
$categorySlug = 'test-entity-contract-category-' . $suffix;
$tagName = 'Test Entity Contract ' . $suffix;
$actionType = 'service';
$actionSlug = 'test-entity-contract-service-' . $suffix;
$plainActionType = 'course';
$plainActionSlug = 'test-entity-contract-plain-course-' . $suffix;

$contentIds = [];
$categoryId = 0;
$tagId = 0;
$nativeEntityPresentationExisted = cmsCustomizerSectionExists($db, 'entity_presentation', 'native');
$nativeEntityPresentationSettings = cmsCustomizerGet($db, 'entity_presentation', 'native')['settings'] ?? cmsEntityPresentationSectionDefaults('native');

$singleHtml = '';
$pageHtml = '';
$builderHtml = '';
$suppressedChromeHtml = '';
$taxonomyHtml = '';
$searchListHtml = '';
$bookHtml = '';
$inquiryHtml = '';
$canonicalEntityViewContext = [];
$canonicalEntityListContext = [];
$tracedCanonicalHtml = '';
$latestCanonicalTrace = [];
$bookMissingCapabilityStatus = 200;
$inquiryMissingCapabilityStatus = 200;

try {
    cmsUpsertCustomizerSection($db, 'entity_presentation', array_merge(
        cmsEntityPresentationSectionDefaults('native'),
        is_array($nativeEntityPresentationSettings) ? $nativeEntityPresentationSettings : [],
        [
            'single_show_categories' => 1,
            'single_show_tags' => 1,
        ]
    ), [], null, 'native');
    cmsCustomizerClearPersistentCache('entity_presentation', 'native');
    cmsCacheInvalidateByTags(['cms:customizer']);
    $GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'native')] = [];

    $category = cmsCategoryCreate('Entity Contract Category ' . $suffix, $categorySlug, 'Category used by the canonical entity contract test.');
    $categoryId = (int)($category['id'] ?? 0);

    $tag = cmsTagCreate($tagName);
    $tagId = (int)($tag['id'] ?? 0);

    $postUuid = cmsUuid();
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'published', 1, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => $postUuid,
        ':title' => 'Entity Contract Post',
        ':slug' => $postSlug,
        ':body' => '<p>Canonical post body.</p>',
        ':excerpt' => 'Canonical post excerpt.',
    ]);
    $postId = (int)$db->lastInsertId();
    $contentIds[] = $postId;
    cmsSyncContentCategories($postId, [$categoryId]);
    cmsSyncContentTags($postId, [$tagName]);

    $pageUuid = cmsUuid();
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, 'page', 'published', 1, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => $pageUuid,
        ':title' => 'Entity Contract Page',
        ':slug' => $pageSlug,
        ':body' => '<p>Canonical page body.</p>',
        ':excerpt' => 'Canonical page excerpt.',
    ]);
    $pageId = (int)$db->lastInsertId();
    $contentIds[] = $pageId;

    $actionUuid = cmsUuid();
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, :type, 'published', 1, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => $actionUuid,
        ':title' => 'Entity Contract Service',
        ':slug' => $actionSlug,
        ':body' => '<p>Canonical service body.</p>',
        ':excerpt' => 'Canonical service excerpt.',
        ':type' => $actionType,
    ]);
    $actionEntityId = (int)$db->lastInsertId();
    $contentIds[] = $actionEntityId;
    cmsEntityAttachCapability($actionEntityId, 'pricing', ['price' => 149.00, 'currency' => 'USD']);
    cmsEntityAttachCapability($actionEntityId, 'booking', []);
    cmsEntityAttachCapability($actionEntityId, 'inquiry', ['label' => 'Ask About This Service']);

    $plainActionUuid = cmsUuid();
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, :type, 'published', 1, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => $plainActionUuid,
        ':title' => 'Entity Contract Plain Course',
        ':slug' => $plainActionSlug,
        ':body' => '<p>Plain course body.</p>',
        ':excerpt' => 'Plain course excerpt.',
        ':type' => $plainActionType,
    ]);
    $plainActionEntityId = (int)$db->lastInsertId();
    $contentIds[] = $plainActionEntityId;

    $singleHtml = captureOutput(static function () use ($postSlug): void {
        cmsPublicSingle(['slug' => $postSlug]);
    });

    $pageHtml = captureOutput(static function () use ($pageSlug): void {
        cmsPublicPage(['slug' => $pageSlug]);
    });

    $bookHtml = captureOutput(static function () use ($actionType, $actionSlug): void {
        cmsPublicEntityBook(['type' => $actionType, 'slug' => $actionSlug]);
    });

    $inquiryHtml = captureOutput(static function () use ($actionType, $actionSlug): void {
        cmsPublicEntityInquiry(['type' => $actionType, 'slug' => $actionSlug]);
    });

    http_response_code(200);
    captureOutput(static function () use ($plainActionType, $plainActionSlug, &$bookMissingCapabilityStatus): void {
        cmsPublicEntityBook(['type' => $plainActionType, 'slug' => $plainActionSlug]);
        $bookMissingCapabilityStatus = (int)(http_response_code() ?: 200);
    });

    http_response_code(200);
    captureOutput(static function () use ($plainActionType, $plainActionSlug, &$inquiryMissingCapabilityStatus): void {
        cmsPublicEntityInquiry(['type' => $plainActionType, 'slug' => $plainActionSlug]);
        $inquiryMissingCapabilityStatus = (int)(http_response_code() ?: 200);
    });
    http_response_code(200);

    $builderHtml = cmsPublicCanonicalRenderEntityView([
        'id' => 0,
        'title' => 'Builder Landing',
        'slug' => 'builder-landing-' . $suffix,
        'body' => '',
        'type' => 'page',
    ], [
        'content_type' => 'page',
        'meta' => [
            '_builder_enabled' => '1',
            '_builder_page_settings' => json_encode(['container_class' => 'builder-shell']),
        ],
        'rendered_html' => '<div class="builder-fragment">Builder Body</div>',
        'builder_enabled' => true,
        'builder_page_settings' => ['container_class' => 'builder-shell'],
        'entity_view_context' => [
            'show_header' => true,
            'show_meta' => false,
            'show_media' => false,
            'bypass_shell' => true,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'canonical',
    ]);

    $suppressedChromeHtml = cmsPublicCanonicalRenderEntityView([
        'id' => 0,
        'title' => 'Flagged Page',
        'slug' => 'flagged-page-' . $suffix,
        'body' => '<p>Flagged page body.</p>',
        'type' => 'page',
        'author_name' => 'Flag Author',
        'published_at' => '2026-03-30 09:00:00',
        'featured_image_url' => '/uploads/flagged-page-hero.jpg',
    ], [
        'content_type' => 'page',
        'rendered_html' => '<p>Flagged page body.</p>',
        'entity_view_context' => [
            'show_header' => true,
            'show_meta' => false,
            'show_media' => false,
            'bypass_shell' => false,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'canonical',
    ]);

    $taxonomyHtml = cmsPublicCanonicalRenderEntityView([
        'id' => $postId,
        'title' => 'Entity Contract Post',
        'slug' => $postSlug,
        'body' => '<p>Canonical post body.</p>',
        'excerpt' => 'Canonical post excerpt.',
        'type' => 'post',
        'author_name' => 'Test Author',
        'published_at' => '2026-03-29 12:00:00',
    ], [
        'content_type' => 'post',
        'rendered_html' => '<p>Canonical post body.</p>',
        'entity_back_link_url' => '/cms/blog',
        'entity_back_link_label' => 'Back to blog',
        'entity_view_context' => [
            'show_header' => true,
            'show_meta' => true,
            'show_media' => false,
            'bypass_shell' => false,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'post',
        'public_presentation_mode' => 'canonical',
        'template_context' => [
            'theme_settings' => array_merge(cmsThemeLayoutSettingsDefaults(), [
                'single_show_author' => 1,
                'single_show_date' => 1,
                'single_show_categories' => 1,
                'single_show_tags' => 1,
                'single_show_nav' => 1,
            ]),
        ],
    ]);

    $searchListHtml = cmsPublicCanonicalRenderEntityList([
        [
            'id' => 0,
            'title' => 'Search Post Result',
            'slug' => 'search-post-' . $suffix,
            'type' => 'post',
            'excerpt' => 'Post result excerpt.',
            'author_name' => 'Alice',
            'published_at' => '2026-03-28 10:30:00',
        ],
        [
            'id' => 0,
            'title' => 'Search Page Result',
            'slug' => 'search-page-' . $suffix,
            'type' => 'page',
            'excerpt' => 'Page result excerpt.',
            'author_name' => 'Bob',
            'published_at' => '2026-03-27 09:15:00',
        ],
    ], [
        'default_type' => 'post',
        'page_title' => 'Search: contract',
        'list_title' => 'Search: contract',
        'list_description' => '2 results for "contract"',
        'entity_list_context' => [
            'base_list_url' => '/cms/search',
            'search_action_url' => '/cms/search',
            'search' => 'contract',
            'result_count' => 2,
            'result_label' => '2 results',
            'active_filter_count' => 1,
            'show_item_meta' => true,
            'show_item_author' => true,
            'show_item_date' => true,
            'show_item_type_badge' => true,
        ],
        'public_render_origin' => 'cms',
        'public_route_kind' => 'search',
        'public_presentation_mode' => 'canonical',
    ]);

    $canonicalEntityViewContext = cmsCanonicalRenderContextNormalize([], 'templates/modules/cms/public/entity.view.disyl');
    $canonicalEntityListContext = cmsCanonicalRenderContextNormalize([], 'templates/modules/cms/public/entity.list.disyl');

    $traceOutputEnv = array_key_exists('APP_RENDER_TRACE_OUTPUT', $_ENV) ? (string)$_ENV['APP_RENDER_TRACE_OUTPUT'] : null;
    $traceLogEnv = array_key_exists('APP_RENDER_TRACE_LOGS', $_ENV) ? (string)$_ENV['APP_RENDER_TRACE_LOGS'] : null;
    kernelClearRenderTraces();
    file_put_contents(STORAGE_PATH . '/logs/app.log', '');
    try {
        $_ENV['APP_RENDER_TRACE_OUTPUT'] = 'comment';
        $_ENV['APP_RENDER_TRACE_LOGS'] = '1';
        $tracedCanonicalHtml = cmsPublicCanonicalRenderEntityView([
            'id' => $actionEntityId,
            'title' => 'Entity Contract Service',
            'slug' => $actionSlug,
            'body' => '<p>Canonical service body.</p>',
            'excerpt' => 'Canonical service excerpt.',
            'type' => $actionType,
        ], [
            'content_type' => $actionType,
            'rendered_html' => '<p>Canonical service body.</p>',
            'public_render_origin' => 'cms',
            'public_route_kind' => 'entity',
            'public_presentation_mode' => 'canonical',
        ]);
        $latestCanonicalTrace = kernelLatestRenderTrace() ?? [];
    } finally {
        if ($traceOutputEnv === null) {
            unset($_ENV['APP_RENDER_TRACE_OUTPUT']);
        } else {
            $_ENV['APP_RENDER_TRACE_OUTPUT'] = $traceOutputEnv;
        }

        if ($traceLogEnv === null) {
            unset($_ENV['APP_RENDER_TRACE_LOGS']);
        } else {
            $_ENV['APP_RENDER_TRACE_LOGS'] = $traceLogEnv;
        }
    }
} finally {
    if ($nativeEntityPresentationExisted) {
        cmsUpsertCustomizerSection(
            $db,
            'entity_presentation',
            is_array($nativeEntityPresentationSettings) ? $nativeEntityPresentationSettings : cmsEntityPresentationSectionDefaults('native'),
            [],
            null,
            'native'
        );
    } else {
        cmsDeleteCustomizerSection($db, 'entity_presentation', 'native');
    }
    cmsCustomizerClearPersistentCache('entity_presentation', 'native');
    cmsCacheInvalidateByTags(['cms:customizer']);
    $GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'native')] = [];

    foreach ($contentIds as $contentId) {
        try {
            $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
        try {
            $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
        try {
            $db->prepare('DELETE FROM cms_content_categories WHERE content_id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
        try {
            $db->prepare('DELETE FROM cms_content_tags WHERE content_id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
        try {
            $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$contentId]);
        } catch (Throwable $e) {
        }
    }
    if ($tagId > 0) {
        cmsTagDelete($tagId);
    }
    if ($categoryId > 0) {
        cmsCategoryDelete($categoryId);
    }
}

$publicHandlerCode = file_get_contents(__DIR__ . '/../modules/cms/handlers/90-public.php') ?: '';

echo "\n=== HANDLER CUTOVER ===\n";

t('legacy public single template reference removed', !str_contains($publicHandlerCode, "public/single.disyl"));
t('legacy public page template reference removed', !str_contains($publicHandlerCode, "public/page.disyl"));
t('legacy public home template reference removed', !str_contains($publicHandlerCode, "public/home.disyl"));
t('legacy public archive template reference removed', !str_contains($publicHandlerCode, "public/archive.disyl"));
t('legacy public search template reference removed', !str_contains($publicHandlerCode, "public/search.disyl"));
t('legacy public book template reference removed', !str_contains($publicHandlerCode, 'entity.book.disyl'));
t('legacy public inquiry template reference removed', !str_contains($publicHandlerCode, 'entity.inquire.disyl'));

echo "\n=== SINGLE AND PAGE ROUTES ===\n";

t('post route renders canonical presentation mode', str_contains($singleHtml, 'data-public-presentation-mode="canonical"'));
t('post route renders canonical route kind', str_contains($singleHtml, 'data-public-route-kind="post"'));
t('post route renders back-to-blog link', str_contains($singleHtml, '/cms/blog'));

t('page route renders canonical presentation mode', str_contains($pageHtml, 'data-public-presentation-mode="canonical"'));
t('page route renders canonical route kind', str_contains($pageHtml, 'data-public-route-kind="page"'));
t('page route omits canonical meta block', !str_contains($pageHtml, '<div class="cms-entity-meta'));
t('page route omits hero media wrapper', !str_contains($pageHtml, '<div class="cms-entity-hero'));

echo "\n=== ACTION ROUTES ===\n";

t('book route renders canonical presentation mode', str_contains($bookHtml, 'data-public-presentation-mode="canonical"'));
t('book route renders canonical book route kind and booking form', str_contains($bookHtml, 'data-public-route-kind="book"') && str_contains($bookHtml, 'id="entity-booking-form"') && str_contains($bookHtml, 'Book Entity Contract Service'), $bookHtml);
t('book route keeps a canonical back link to the source entity', str_contains($bookHtml, 'Back to Entity Contract Service') && str_contains($bookHtml, '/cms/' . $actionType . '/' . $actionSlug), $bookHtml);
t('inquiry route renders canonical presentation mode', str_contains($inquiryHtml, 'data-public-presentation-mode="canonical"'));
t('inquiry route renders canonical inquiry route kind and inquiry form', str_contains($inquiryHtml, 'data-public-route-kind="inquiry"') && str_contains($inquiryHtml, 'id="entity-inquiry-form"') && str_contains($inquiryHtml, 'Inquire About Entity Contract Service'), $inquiryHtml);
t('book route rejects entities without booking capability', $bookMissingCapabilityStatus === 404, (string)$bookMissingCapabilityStatus);
t('inquiry route rejects entities without inquiry capability', $inquiryMissingCapabilityStatus === 404, (string)$inquiryMissingCapabilityStatus);

echo "\n=== VIEW HELPER ===\n";

t('builder bypass keeps builder fragment', str_contains($builderHtml, 'builder-fragment'));
t('builder bypass keeps builder container class', str_contains($builderHtml, 'builder-shell'));
t('builder bypass omits canonical header wrapper', !str_contains($builderHtml, '<header class="cms-entity-header'));
t('canonical view honors explicit false meta flag', !str_contains($suppressedChromeHtml, '<div class="cms-entity-meta'));
t('canonical view honors explicit false media flag', !str_contains($suppressedChromeHtml, '<div class="cms-entity-hero'));
t('canonical post helper renders category link', str_contains($taxonomyHtml, '/cms/category/' . $categorySlug));
t('canonical post helper renders tag link', str_contains($taxonomyHtml, '/cms/tag/' . cmsSlugify($tagName)));

echo "\n=== METADATA ===\n";

t('canonical entity view context reports cms_public profile', ($canonicalEntityViewContext['render_profile_id'] ?? '') === 'cms_public', json_encode($canonicalEntityViewContext['render_profile_id'] ?? null));
t('canonical entity view context reports schema stack', ($canonicalEntityViewContext['render_schema_stack'] ?? null) === ['kernel.shell@1', 'cms.public.entity.view@1'], json_encode($canonicalEntityViewContext['render_schema_stack'] ?? null));
t('canonical entity list context reports cms_public profile', ($canonicalEntityListContext['render_profile_id'] ?? '') === 'cms_public', json_encode($canonicalEntityListContext['render_profile_id'] ?? null));
t('canonical entity list context reports schema stack', ($canonicalEntityListContext['render_schema_stack'] ?? null) === ['kernel.shell@1', 'cms.public.entity.list@1'], json_encode($canonicalEntityListContext['render_schema_stack'] ?? null));

echo "\n=== RENDER TRACE ===\n";

t('canonical render emits render-trace HTML comment when enabled', str_contains($tracedCanonicalHtml, '<!-- render-trace ') && str_contains($tracedCanonicalHtml, '"render_profile_id":"cms_public"'), $tracedCanonicalHtml);
t('canonical render records latest trace metadata', ($latestCanonicalTrace['render_profile_id'] ?? '') === 'cms_public' && (($latestCanonicalTrace['render_schema_stack'] ?? null) === ['kernel.shell@1', 'cms.public.entity.view@1']) && (($latestCanonicalTrace['public_route_kind'] ?? '') === 'entity'), json_encode($latestCanonicalTrace));
$canonicalTraceLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
t('canonical render logs render trace when enabled', str_contains($canonicalTraceLog, 'kernel.render_trace') && str_contains($canonicalTraceLog, '"render_profile_id":"cms_public"') && str_contains($canonicalTraceLog, '"public_route_kind":"entity"'), $canonicalTraceLog);
file_put_contents(STORAGE_PATH . '/logs/app.log', '');

echo "\n=== RENDER LOCK SESSION ===\n";

$lockOptimizedHtml = '';
$themeTimingLogs = array_key_exists('CMS_THEME_TIMING_LOGS', $_ENV) ? (string)$_ENV['CMS_THEME_TIMING_LOGS'] : null;
$themeTimingThreshold = array_key_exists('CMS_THEME_TIMING_THRESHOLD_MS', $_ENV) ? (string)$_ENV['CMS_THEME_TIMING_THRESHOLD_MS'] : null;
$lockCountBefore = count(array_values(array_filter(
    explode("\n", @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: ''),
    static fn(string $line): bool => str_contains($line, 'cms.theme_symlink_lock')
)));
try {
    $_ENV['CMS_THEME_TIMING_LOGS'] = '1';
    $_ENV['CMS_THEME_TIMING_THRESHOLD_MS'] = '0';

    $lockOptimizedHtml = cmsPublicCanonicalRenderEntityView([
        'id' => $actionEntityId,
        'title' => 'Entity Contract Service',
        'slug' => $actionSlug,
        'body' => '<p>Canonical service body.</p>',
        'excerpt' => 'Canonical service excerpt.',
        'type' => $actionType,
    ], [
        'content_type' => $actionType,
        'rendered_html' => '<p>Canonical service body.</p>',
        'public_render_origin' => 'cms',
        'public_route_kind' => 'entity',
        'public_presentation_mode' => 'canonical',
    ]);
} finally {
    if ($themeTimingLogs === null) {
        unset($_ENV['CMS_THEME_TIMING_LOGS']);
    } else {
        $_ENV['CMS_THEME_TIMING_LOGS'] = $themeTimingLogs;
    }

    if ($themeTimingThreshold === null) {
        unset($_ENV['CMS_THEME_TIMING_THRESHOLD_MS']);
    } else {
        $_ENV['CMS_THEME_TIMING_THRESHOLD_MS'] = $themeTimingThreshold;
    }
}
$lockCountAfter = count(array_values(array_filter(
    explode("\n", @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: ''),
    static fn(string $line): bool => str_contains($line, 'cms.theme_symlink_lock')
)));
$newLockLines = $lockCountAfter - $lockCountBefore;
t('canonical render avoids request-time theme symlink lock across nested block renders', $newLockLines === 0, (string)$newLockLines);
t('lock-optimized canonical render still outputs pricing and action blocks', str_contains($lockOptimizedHtml, 'cms-pricing-block') && str_contains($lockOptimizedHtml, 'cms-action-block'), $lockOptimizedHtml);

echo "\n=== LIST HELPER ===\n";

t('search list renders canonical presentation mode', str_contains($searchListHtml, 'data-public-presentation-mode="canonical"'));
t('search list renders canonical search route kind', str_contains($searchListHtml, 'data-public-route-kind="search"'));
t('search list renders post URL contract', str_contains($searchListHtml, '/cms/blog/search-post-' . $suffix));
t('search list renders page URL contract', str_contains($searchListHtml, '/cms/page/search-page-' . $suffix));
t('search list renders type badges', str_contains($searchListHtml, '>Post<') && str_contains($searchListHtml, '>Page<'));
t('search list renders formatted dates', str_contains($searchListHtml, 'Mar 28, 2026') && str_contains($searchListHtml, 'Mar 27, 2026'));

echo "\n=== CONTRACT LOG METADATA ===\n";

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
cmsCanonicalRenderContextNormalize([
    '__render_contract_validate' => true,
    'entity' => 'bad-entity',
], 'templates/modules/cms/public/entity.view.disyl');
$metadataAppLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
t('canonical contract mismatch logs include cms_public metadata', str_contains($metadataAppLog, '"render_profile_id":"cms_public"') && str_contains($metadataAppLog, '"cms.public.entity.view@1"'), $metadataAppLog);
file_put_contents(STORAGE_PATH . '/logs/app.log', '');

$renderedOutputs = implode("\n", [$singleHtml, $pageHtml, $builderHtml, $taxonomyHtml, $searchListHtml, $bookHtml, $inquiryHtml]);
$leakedDisylControlTag = '';
if (preg_match('/\{\/(?:if|foreach|for|block)\}/', $renderedOutputs, $matches) === 1) {
    $leakedDisylControlTag = (string)($matches[0] ?? '');
}
t('canonical public renders do not leak raw Disyl control tags', $leakedDisylControlTag === '', $leakedDisylControlTag);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticalLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[critical]')));
$contractMismatchLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, 'cms.render_context.contract_mismatch')));
$unexpectedErrorLines = array_values(array_filter(
    explode("\n", $errLog),
    static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
));

echo "\n=== LOGS ===\n";

t('no app.log critical errors', empty($criticalLines), implode('; ', $criticalLines));
t('canonical public renders do not log contract mismatch warnings', empty($contractMismatchLines), implode('; ', $contractMismatchLines));
t('no PHP errors in error.log', empty($unexpectedErrorLines), implode('; ', $unexpectedErrorLines));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);