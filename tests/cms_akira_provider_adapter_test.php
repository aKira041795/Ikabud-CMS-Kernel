<?php

/**
 * CMS Akira Provider Adapter Test
 *
 * Verifies the Phase B provider adapters delegate to the canonical owners
 * (modules/cms for theme/navigation/SEO/editor/media, kernel for workflow,
 * search contract for search) instead of returning derived stub data.
 *
 * Run: php tests/cms_akira_provider_adapter_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'akiracms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-theme/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-navigation/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-seo/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-editor/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-media/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-workflow/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-search-adapter/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function registerHandlers(string $provider, array $handlers): void
{
    foreach ($handlers as $capabilityId => $handlerFn) {
        if (!is_string($handlerFn) || !function_exists($handlerFn)) {
            continue;
        }
        try {
            app()->capabilities()->register($capabilityId, $provider, $handlerFn, 100, ['first']);
        } catch (Throwable $e) {
            // already registered in repeated runs
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// Under Activation-Before-Participation, the standard profile installs only
// cover core/editor/theme/navigation/media/seo. The workflow and search
// adapters are suite EXTENSIONS outside that install set, so they must be
// explicitly activated before the gated capability bus will dispatch them.
// Restore prior state on shutdown so no tenant settings are left mutated.
$probeTenantId = (int)(moduleTenantSettingsTenantId() ?: 0);
$activationRestore = [];
if ($probeTenantId > 0) {
    foreach (['cms-akira-workflow', 'cms-akira-search-adapter'] as $actModuleId) {
        if (!moduleIsActive($actModuleId)) {
            enableModuleForTenant($actModuleId, $probeTenantId);
            $activationRestore[] = $actModuleId;
        }
    }
    register_shutdown_function(static function () use ($activationRestore, $probeTenantId): void {
        foreach ($activationRestore as $actModuleId) {
            disableModuleForTenant($actModuleId, $probeTenantId);
        }
    });
}

registerHandlers('cms', cms_capability_handlers());
registerHandlers('cms-akira-core', cms_akira_core_capability_handlers());
registerHandlers('cms-akira-theme', cms_akira_theme_capability_handlers());
registerHandlers('cms-akira-navigation', cms_akira_navigation_capability_handlers());
registerHandlers('cms-akira-seo', cms_akira_seo_capability_handlers());
registerHandlers('cms-akira-editor', cms_akira_editor_capability_handlers());
registerHandlers('cms-akira-media', cms_akira_media_capability_handlers());
registerHandlers('cms-akira-workflow', cms_akira_workflow_capability_handlers());
registerHandlers('cms-akira-search-adapter', cms_akira_search_adapter_capability_handlers());

echo "=== THEME ADAPTER ===\n";
$theme = app()->cap()->call('akira.theme.resolve@1', ['title' => 'T']);
$td = $theme['data'] ?? [];
t('theme adapter delegates to CMS', ($td['resolved_from'] ?? '') === 'cms', json_encode($td));
t('theme adapter exposes active_theme', array_key_exists('active_theme', $td));
t('theme adapter exposes active_theme_name', array_key_exists('active_theme_name', $td));

echo "\n=== NAVIGATION ADAPTER ===\n";
$nav = app()->cap()->call('akira.navigation.resolve@1', ['slug' => 'hello-world']);
$nd = $nav['data'] ?? [];
t('navigation adapter delegates to CMS', ($nd['resolved_from'] ?? '') === 'cms', json_encode($nd));
t('navigation adapter returns real menus', is_array($nd['menus'] ?? null));
t('navigation adapter returns menu locations', is_array($nd['menu_locations'] ?? null));

echo "\n=== SEO ADAPTER ===\n";
$seo = app()->cap()->call('akira.seo.meta.build@1', ['title' => 'Hello World', 'slug' => 'hello-world', 'excerpt' => 'Intro', 'body' => '<p>Body</p>', 'type' => 'post']);
$sd = $seo['data'] ?? [];
t('seo adapter delegates to CMS', ($sd['resolved_from'] ?? '') === 'cms', json_encode($sd));
t('seo adapter returns head_html', is_string($sd['head_html'] ?? null) && $sd['head_html'] !== '');
t('seo adapter returns meta_title', ($sd['meta_title'] ?? '') !== '');

echo "\n=== EDITOR ADAPTER ===\n";
$en = app()->cap()->call('editor.normalize@1', ['content' => "<p>Hello\r\n\r\n\r\nWorld</p>", 'context' => 'cms.content']);
$end = $en['data'] ?? [];
t('editor normalize delegates to CMS', ($end['resolved_from'] ?? '') === 'cms', json_encode($end));

$es = app()->cap()->call('editor.sanitize@1', ['content' => '<p>ok</p><script>alert(1)</script>', 'context' => 'cms.content']);
$esd = $es['data'] ?? [];
t('editor sanitize delegates to CMS', ($esd['resolved_from'] ?? '') === 'cms', json_encode($esd));
t('editor sanitize strips scripts', !str_contains((string)($esd['content'] ?? ''), '<script'));

$ea = app()->cap()->call('editor.assets@1', ['context' => 'cms.content', 'profile' => 'default']);
$ead = $ea['data'] ?? [];
t('editor assets delegate to CMS resolver', ($ead['resolved_from'] ?? '') === 'cms', json_encode($ead));
t('editor assets expose js_urls array', is_array($ead['js_urls'] ?? null));

echo "\n=== MEDIA ADAPTER ===\n";
// New contract: akira.media.resolve@1 delegates ONLY via cms.media.get@1
// (ID-based capability). A payload with a real media_id resolves via the CMS
// capability → resolved_from 'cms'. A raw-path-only payload (no id) cannot be
// resolved by the ID-based capability → fallback (URL passed through).
$existingMediaId = 0;
try {
    $existingMediaId = (int) (app()->db()->query("SELECT id FROM cms_media WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $existingMediaId = 0;
}

if ($existingMediaId > 0) {
    $me = app()->cap()->call('akira.media.resolve@1', ['media_id' => $existingMediaId, 'alt' => 'Probe']);
    $med = $me['data'] ?? [];
    t('media adapter delegates to CMS via cms.media.get@1', ($med['resolved_from'] ?? '') === 'cms', json_encode($med));
    t('media adapter resolves public URL', is_string($med['url'] ?? null) && $med['url'] !== '');
} else {
    // No media rows seeded: verify capability delegation is attempted and the
    // ID-less payload correctly falls back (never calls banned direct helpers).
    $me = app()->cap()->call('akira.media.resolve@1', ['media_id' => 0, 'featured_image' => 'uploads/probe.png', 'alt' => 'Probe']);
    $med = $me['data'] ?? [];
    t('media adapter fallback for id-less payload (no direct helpers)', ($med['resolved_from'] ?? '') === 'fallback', json_encode($med));
    t('media adapter passes through url string', is_string($med['url'] ?? null), json_encode($med));
}

echo "\n=== WORKFLOW ADAPTER ===\n";
// No entity id -> no kernel workflow instance -> adapter falls back.
$wfb = app()->cap()->call('akira.workflow.evaluate@1', ['status' => 'draft']);
$wfbd = $wfb['data'] ?? [];
t('workflow adapter falls back without entity id', ($wfbd['resolved_from'] ?? '') === 'fallback', json_encode($wfbd));
t('workflow adapter fallback status is draft', ($wfbd['status'] ?? '') === 'draft');

$pdo = app()->db();
$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
$contentId = 0;
if ($authorId > 0) {
    $token = bin2hex(random_bytes(4));
    $create = app()->cap()->call('akira.content.create@1', [
        'title' => 'Akira Workflow Probe ' . $token,
        'slug' => 'akira-wf-' . $token,
        'body' => '<p>wf</p>',
        'type' => 'post',
        'status' => 'draft',
        'author_id' => $authorId,
    ]);
    $contentId = (int)($create['id'] ?? 0);
    if ($contentId > 0) {
        $wf = app()->cap()->call('akira.workflow.evaluate@1', ['id' => $contentId, 'status' => 'draft']);
        $wfd = $wf['data'] ?? [];
        t('workflow adapter delegates to kernel for real entity', ($wfd['resolved_from'] ?? '') === 'kernel', json_encode($wfd));
        t('workflow adapter returns kernel state', ($wfd['status'] ?? '') !== '');
    } else {
        t('workflow adapter delegates to kernel for real entity', false, 'could not create content row');
    }
} else {
    t('workflow adapter delegates to kernel for real entity', false, 'no active cms_users row');
}

if ($contentId > 0) {
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id = {$contentId}");
    $pdo->exec("DELETE FROM cms_content WHERE id = {$contentId}");
}

echo "\n=== SEARCH ADAPTER ===\n";
$sa = app()->cap()->call('akira.search.document.build@1', ['title' => 'Hello', 'slug' => 'hello', 'body' => '<p>Hello <b>world</b></p>']);
$sad = $sa['data'] ?? [];
$doc = $sad['document'] ?? [];
t('search adapter builds canonical document', ($doc['search_text'] ?? '') !== '');
t('search adapter derives excerpt', ($doc['excerpt'] ?? '') !== '');
t('search adapter resolves via search or fallback', in_array($sad['resolved_from'] ?? '', ['search', 'fallback'], true));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
