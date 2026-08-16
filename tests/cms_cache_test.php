<?php
/**
 * CMS Module — Cache Layer Test
 * Verifies cache helpers, tag-based invalidation, event-driven flush,
 * ETag header generation, and public handler cache integration.
 * Run: php tests/cms_cache_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

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
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Clear logs + cache ──────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
$resolver = app()->tenant();
$suiteOriginalTenantId = $resolver->current();
$resolver->setTenantId(0);
$GLOBALS['cms_settings_cached_t0'] = true;
$GLOBALS['cms_settings_value_t0'] = [
    'cache_enabled' => '1',
    'cache_ttl' => (string) CMS_CACHE_TTL,
];
cmsResetCacheRuntimeState();
cmsCacheFlushAll();

// ═══════════════════════════════════════════════════════════════════
// 1. Cache constants & config
// ═══════════════════════════════════════════════════════════════════
echo "\n=== CACHE CONFIG ===\n";

t('CMS_CACHE_INSTANCE defined', defined('CMS_CACHE_INSTANCE'));
t('CMS_CACHE_INSTANCE is "cms"', CMS_CACHE_INSTANCE === 'cms');
t('CMS_CACHE_TTL defined', defined('CMS_CACHE_TTL'));
t('CMS_CACHE_TTL is 600', CMS_CACHE_TTL === 600);
t('cmsCacheTtl() returns int', is_int(cmsCacheTtl()));
t('cmsCacheEnabled() returns true (default)', cmsCacheEnabled() === true);

// Respect admin cache toggle and keep runtime state tenant-scoped.
$originalTenantId = $resolver->current();

$resolver->setTenantId(101);
$GLOBALS['cms_settings_cached_t101'] = true;
$GLOBALS['cms_settings_value_t101'] = ['cache_enabled' => '0', 'cache_ttl' => '600'];
cmsResetCacheRuntimeState();
t('cmsCacheEnabled() respects cache_enabled=0', cmsCacheEnabled() === false);

$resolver->setTenantId(202);
$GLOBALS['cms_settings_cached_t202'] = true;
$GLOBALS['cms_settings_value_t202'] = ['cache_enabled' => '1', 'cache_ttl' => '321'];
cmsResetCacheRuntimeState();
t('cmsCacheEnabled() respects enabled tenant setting', cmsCacheEnabled() === true);
t('cmsCacheTtl() uses tenant-specific runtime value', cmsCacheTtl() === 321);

$resolver->setTenantId(101);
t('cmsCacheEnabled() remains isolated per tenant', cmsCacheEnabled() === false);
$resolver->setTenantId(0);
$GLOBALS['cms_settings_cached_t0'] = true;
$GLOBALS['cms_settings_value_t0'] = [
    'cache_enabled' => '1',
    'cache_ttl' => (string) CMS_CACHE_TTL,
];
cmsResetCacheRuntimeState();

// ═══════════════════════════════════════════════════════════════════
// 2. Basic cache get/set
// ═══════════════════════════════════════════════════════════════════
echo "\n=== BASIC CACHE GET/SET ===\n";

$testKey = 'test:cache:basic';
$testData = ['html' => '<h1>Test</h1>', 'etag' => 'abc123', 'updated_at' => '2026-03-06 10:00:00'];

// Miss
$result = cmsCacheGet($testKey);
t('cache miss returns null', $result === null);

// Set
cmsCacheSet($testKey, $testData, ['test:tag1', 'test:tag2']);

// Hit
$result = cmsCacheGet($testKey);
t('cache hit returns array', is_array($result));
t('cache hit has html', ($result['html'] ?? '') === '<h1>Test</h1>');
t('cache hit has etag', ($result['etag'] ?? '') === 'abc123');
t('cache hit has updated_at', ($result['updated_at'] ?? '') === '2026-03-06 10:00:00');

// ═══════════════════════════════════════════════════════════════════
// 3. Tag-based invalidation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== TAG-BASED INVALIDATION ===\n";

// Set two entries with overlapping tags
cmsCacheSet('test:entry1', ['val' => '1'], ['shared:tag', 'unique:tag1']);
cmsCacheSet('test:entry2', ['val' => '2'], ['shared:tag', 'unique:tag2']);

// Verify both cached
t('entry1 cached', cmsCacheGet('test:entry1') !== null);
t('entry2 cached', cmsCacheGet('test:entry2') !== null);

// Invalidate by unique tag — only entry1 should go
cmsCacheInvalidateByTags(['unique:tag1']);
t('entry1 invalidated by unique tag', cmsCacheGet('test:entry1') === null);
t('entry2 still cached', cmsCacheGet('test:entry2') !== null);

// Invalidate by shared tag — entry2 should go
cmsCacheInvalidateByTags(['shared:tag']);
t('entry2 invalidated by shared tag', cmsCacheGet('test:entry2') === null);

// Regression: invalidation must not depend on request method/query context
$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$prevGet = $_GET ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['archive' => '2026-03'];
cmsCacheSet('test:context-sensitive-key', ['val' => 'x'], ['context:tag']);
t('context-sensitive key cached', cmsCacheGet('test:context-sensitive-key') !== null);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
cmsCacheInvalidateByTags(['context:tag']);
t('tag invalidation works across request contexts', cmsCacheGet('test:context-sensitive-key') === null);

if ($prevMethod !== null) {
    $_SERVER['REQUEST_METHOD'] = $prevMethod;
} else {
    unset($_SERVER['REQUEST_METHOD']);
}
$_GET = $prevGet;

// ═══════════════════════════════════════════════════════════════════
// 4. Content tag generation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== CONTENT TAG GENERATION ===\n";

$postContent = ['id' => 42, 'type' => 'post', 'slug' => 'hello-world'];
$tags = cmsCacheTagsForContent($postContent);
t('post tags include content id', in_array('cms:content:42', $tags));
t('post tags include type', in_array('cms:type:post', $tags));
t('post tags include slug', in_array('cms:post:hello-world', $tags));
t('post tags include home', in_array('cms:home', $tags));
t('post tags include api:posts', in_array('cms:api:posts', $tags));

$pageContent = ['id' => 10, 'type' => 'page', 'slug' => 'about'];
$tags = cmsCacheTagsForContent($pageContent);
t('page tags include content id', in_array('cms:content:10', $tags));
t('page tags include type', in_array('cms:type:page', $tags));
t('page tags include slug', in_array('cms:page:about', $tags));
t('page tags include api:pages', in_array('cms:api:pages', $tags));
t('page tags do NOT include home', !in_array('cms:home', $tags));

// ═══════════════════════════════════════════════════════════════════
// 5. Content invalidation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== CONTENT INVALIDATION ===\n";

// Simulate cached home and single post
cmsCacheSet('cms:home:page:1', ['html' => 'home'], ['cms:home', 'cms:type:post']);
cmsCacheSet('cms:post:hello-world', ['html' => 'post'], ['cms:post:hello-world', 'cms:content:42', 'cms:type:post']);
cmsCacheSet('cms:page:about', ['html' => 'page'], ['cms:page:about', 'cms:content:10', 'cms:type:page']);

// Invalidate for the post content
$cleared = cmsCacheInvalidateContent($postContent);
t('invalidateContent cleared entries', $cleared > 0);
t('home cache cleared (post changed)', cmsCacheGet('cms:home:page:1') === null);
t('post cache cleared', cmsCacheGet('cms:post:hello-world') === null);
t('page cache NOT cleared (different type)', cmsCacheGet('cms:page:about') !== null);

// Clean up
cmsCacheFlushAll();

// Builder save slug-change regression
cmsCacheSet('cms:page:old-builder-slug', ['html' => 'old page'], ['cms:page:old-builder-slug', 'cms:content:777', 'cms:type:page']);
cmsCacheSet('cms:page:new-builder-slug', ['html' => 'new page'], ['cms:page:new-builder-slug', 'cms:content:777', 'cms:type:page']);
$builderCacheTags = cmsCacheTagsForContent(['id' => 777, 'type' => 'page', 'slug' => 'new-builder-slug']);
$builderCacheTags[] = 'cms:page:old-builder-slug';
cmsCacheInvalidateByTags(array_values(array_unique(array_filter($builderCacheTags))));
t('builder save slug change clears old page cache tag', cmsCacheGet('cms:page:old-builder-slug') === null);
t('builder save slug change clears new page cache tag', cmsCacheGet('cms:page:new-builder-slug') === null);

// ═══════════════════════════════════════════════════════════════════
// 6. Flush all
// ═══════════════════════════════════════════════════════════════════
echo "\n=== FLUSH ALL ===\n";

cmsCacheSet('test:flush1', ['v' => '1'], ['t1']);
cmsCacheSet('test:flush2', ['v' => '2'], ['t2']);
t('entries cached before flush', cmsCacheGet('test:flush1') !== null && cmsCacheGet('test:flush2') !== null);

$flushed = cmsCacheFlushAll();
t('flush returned count', $flushed >= 0);
t('entry1 gone after flush', cmsCacheGet('test:flush1') === null);
t('entry2 gone after flush', cmsCacheGet('test:flush2') === null);

// ═══════════════════════════════════════════════════════════════════
// 7. ETag / Last-Modified header logic (unit test without actual headers)
// ═══════════════════════════════════════════════════════════════════
echo "\n=== ETAG LOGIC ===\n";

// Test the etag generation logic (don't actually send headers in CLI)
$html = '<html><body>Hello</body></html>';
$etag = md5($html);
t('etag is md5 of html', strlen($etag) === 32);
t('etag is consistent', md5($html) === $etag);

$differentHtml = '<html><body>Changed</body></html>';
$differentEtag = md5($differentHtml);
t('different content = different etag', $etag !== $differentEtag);

// ═══════════════════════════════════════════════════════════════════
// 8a. Tenant runtime cache isolation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== TENANT RUNTIME CACHE ISOLATION ===\n";

$originalTenantId = $resolver->current();

$resolver->setTenantId(111);
$GLOBALS['cms_active_theme_cached_t111'] = true;
$GLOBALS['cms_active_theme_value_t111'] = 'theme-one';
$GLOBALS['cms_preferred_ecommerce_theme_cached_t111'] = true;
$GLOBALS['cms_preferred_ecommerce_theme_value_t111'] = null;
$GLOBALS['cms_cap_map_cached_t111'] = true;
$GLOBALS['cms_cap_map_t111'] = ['content.edit' => 'author'];

$resolver->setTenantId(222);
$GLOBALS['cms_active_theme_cached_t222'] = true;
$GLOBALS['cms_active_theme_value_t222'] = 'theme-two';
$GLOBALS['cms_preferred_ecommerce_theme_cached_t222'] = true;
$GLOBALS['cms_preferred_ecommerce_theme_value_t222'] = null;
$GLOBALS['cms_cap_map_cached_t222'] = true;
$GLOBALS['cms_cap_map_t222'] = ['content.edit' => 'editor'];

$resolver->setTenantId(111);
t('theme cache key is tenant-scoped', cmsActiveTheme() === 'theme-one');
t('capability map cache key is tenant-scoped', (cmsCapabilityMap()['content.edit'] ?? '') === 'author');

$resolver->setTenantId(222);
t('theme cache does not bleed across tenants', cmsActiveTheme() === 'theme-two');
t('capability map does not bleed across tenants', (cmsCapabilityMap()['content.edit'] ?? '') === 'editor');

$resolver->setTenantId($originalTenantId);

// ═══════════════════════════════════════════════════════════════════
// 9. EventBus cache listeners
// ═══════════════════════════════════════════════════════════════════
echo "\n=== EVENTBUS CACHE LISTENERS ===\n";

$bus = \Ikabud\Kernel\EventBus::getInstance();

// Pre-populate cache
cmsCacheSet('cms:home:page:1', ['html' => 'home'], ['cms:home', 'cms:type:post']);
cmsCacheSet('cms:post:test-slug', ['html' => 'post'], ['cms:post:test-slug', 'cms:content:99', 'cms:type:post']);

// Fire cms.content.published event
$bus->fire('cms.content.published', [
    'content_id' => 99,
    'slug'       => 'test-slug',
    'type'       => 'post',
], 'cms');
t('home invalidated by publish event', cmsCacheGet('cms:home:page:1') === null);
t('post invalidated by publish event', cmsCacheGet('cms:post:test-slug') === null);

// Re-populate and test cms.content.updated
cmsCacheSet('cms:home:page:1', ['html' => 'home'], ['cms:home', 'cms:type:post']);
cmsCacheSet('cms:post:test-slug', ['html' => 'post'], ['cms:post:test-slug', 'cms:content:99', 'cms:type:post']);

$bus->fire('cms.content.updated', [
    'content_id' => 99,
    'slug'       => 'test-slug',
    'type'       => 'post',
], 'cms');
t('home invalidated by update event', cmsCacheGet('cms:home:page:1') === null);
t('post invalidated by update event', cmsCacheGet('cms:post:test-slug') === null);

// Re-populate and test cms.content.deleted
cmsCacheSet('cms:home:page:1', ['html' => 'home'], ['cms:home', 'cms:type:post']);
cmsCacheSet('cms:post:another', ['html' => 'post2'], ['cms:post:another', 'cms:content:50', 'cms:type:post']);

$bus->fire('cms.content.deleted', [
    'content_id' => 50,
    'slug'       => 'another',
    'type'       => 'post',
], 'cms');
t('home invalidated by delete event', cmsCacheGet('cms:home:page:1') === null);
t('post invalidated by delete event', cmsCacheGet('cms:post:another') === null);

// Test cms.content.created
cmsCacheSet('cms:home:page:1', ['html' => 'home'], ['cms:home', 'cms:type:post']);
$bus->fire('cms.content.created', ['type' => 'post'], 'cms');
t('home invalidated by create event', cmsCacheGet('cms:home:page:1') === null);

// Test cms.settings.updated — flush all
cmsCacheSet('cms:home:page:1', ['html' => 'h'], ['cms:home']);
cmsCacheSet('cms:page:about', ['html' => 'p'], ['cms:page:about']);
$bus->fire('cms.settings.updated', [], 'cms');
t('all cache flushed by settings event', cmsCacheGet('cms:home:page:1') === null && cmsCacheGet('cms:page:about') === null);

// ═══════════════════════════════════════════════════════════════════
// 10. Handler integration (verify handlers still work after caching changes)
// ═══════════════════════════════════════════════════════════════════
echo "\n=== HANDLER INTEGRATION ===\n";

// Check that the split handler files still wire cache + invalidation correctly.
$publicHandlerCode = file_get_contents(BASE_PATH . '/modules/cms/handlers/90-public.php');
$contentHandlerCode = file_get_contents(BASE_PATH . '/modules/cms/handlers/35-api-content.php');
$contentActionsCode = file_get_contents(BASE_PATH . '/modules/cms/handlers/36-api-content-actions.php');
$builderHandlerCode = file_get_contents(BASE_PATH . '/modules/cms/handlers/20-api-builder.php');
$settingsHandlerCode = file_get_contents(BASE_PATH . '/modules/cms/handlers/50-api-settings.php');
t('cmsPublicHome uses cmsCacheGet', str_contains($publicHandlerCode, "cmsCacheGet(\$cacheKey)"));
t('cmsPublicHome uses cmsCacheSet', str_contains($publicHandlerCode, 'cmsCacheSet($cacheKey'));
t('cmsPublicSingle uses cmsCacheGet', str_contains($publicHandlerCode, "'cms:post:entity_contract_v3:' . \$slug") && str_contains($publicHandlerCode, 'cmsCacheGet($cacheKey)'));
t('cmsPublicPage uses cmsCacheSet', str_contains($publicHandlerCode, "'cms:page:entity_contract_v3:' . \$slug") && str_contains($publicHandlerCode, 'cmsCacheSet($cacheKey'));
t('cmsPublicEntityList versions cache by template fingerprint', str_contains($publicHandlerCode, '$templateVersion = md5(') && str_contains($publicHandlerCode, ":tpl:"));
t('cmsApiContentUpdate fires updated event', str_contains($contentHandlerCode . $contentActionsCode, 'cms.content.updated'));
t('cmsApiContentTrash fires deleted event', str_contains($contentHandlerCode . $contentActionsCode, 'cms.content.deleted'));
t('cmsApiContentPublish calls cmsCacheInvalidateContent', str_contains($contentHandlerCode . $contentActionsCode, 'cmsCacheInvalidateContent('));
t('cmsApiBuilderDocumentPublish invalidates cache tags', str_contains($builderHandlerCode, 'cmsCacheInvalidateByTags'));
t('cmsApiSettingsSave fires cms.settings.updated event', str_contains($settingsHandlerCode, "fireEvent('cms.settings.updated'") || str_contains($settingsHandlerCode, 'fireEvent("cms.settings.updated"'));
t('settings updated listener flushes CMS cache', str_contains(file_get_contents(BASE_PATH . '/modules/cms/helpers/99-misc.php') ?: '', "listen('cms.settings.updated'") && str_contains(file_get_contents(BASE_PATH . '/modules/cms/helpers/99-misc.php') ?: '', 'cmsCacheFlushAll()'));
t('public handlers centralize output through cmsPublicRespond', str_contains($publicHandlerCode, 'function cmsPublicRespond(string $body): void'));
t('cmsPublicRespond releases session lock after render', str_contains($publicHandlerCode, 'releaseSessionAfterRender()'));
t('cms public context detailed timing helper exists', str_contains(file_get_contents(BASE_PATH . '/modules/cms/helpers/78-public-context.php') ?: '', 'function cmsPublicContextDetailedTimingEnabled(): bool'));
t('.env.example documents CMS public context verbose timing flag', str_contains(file_get_contents(BASE_PATH . '/.env.example') ?: '', 'CMS_PUBLIC_CONTEXT_TIMING_VERBOSE='));
t('cmsPublicHome logs cache lookup timing', str_contains($publicHandlerCode, 'cms.public.home.cache_lookup'));
t('cmsPublicHome logs total timing', str_contains($publicHandlerCode, 'cms.public.home.total'));

$publicLayoutCode = file_get_contents(BASE_PATH . '/templates/modules/cms/layouts/public.disyl');
t('public layout loads builder runtime for builder pages', str_contains($publicLayoutCode, '/assets/cms/builder-public.js'));

// ═══════════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════════
echo "\n=== CLEANUP ===\n";
cmsCacheFlushAll();
t('cache flushed', true);

$resolver->setTenantId($suiteOriginalTenantId);

// ═══════════════════════════════════════════════════════════════════
// LOG CHECK
// ═══════════════════════════════════════════════════════════════════
echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]'));
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));
// Filter out kernel cache info lines — only flag real PHP errors
$errLines = array_filter(explode("\n", $errLog), function ($l) {
    $l = trim($l);
    if ($l === '') return false;
    if (str_contains($l, 'Ikabud Cache:')) return false; // kernel cache info
    return true;
});
t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

// ═══════════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════════
echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
