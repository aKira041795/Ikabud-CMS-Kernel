<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

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
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function createEntity(string $type, string $title): int
{
    $db = cmsDb();
    $slug = cmsEnsureUniqueSlug(strtolower($type) . '-' . substr(bin2hex(random_bytes(4)), 0, 8), $type);
    $stmt = $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at)
         VALUES (:uuid, :title, :slug, '', :type, 'published', 1, NOW())"
    );
    $stmt->execute([
        ':uuid' => cmsUuid(),
        ':title' => $title,
        ':slug' => $slug,
        ':type' => $type,
    ]);

    return (int)$db->lastInsertId();
}

function registerEntityContextsFromManifest(array $manifest): void
{
    $moduleId = trim((string)($manifest['id'] ?? ''));
    if ($moduleId === '') {
        return;
    }

    $check = validateModuleEntityContexts($manifest);
    if (empty($check['ok'])) {
        return;
    }

    foreach (($check['definitions'] ?? []) as $definition) {
        if (!is_array($definition) || empty($definition['id'])) {
            continue;
        }
        app()->entityContexts()->registerContext((string)$definition['id'], $definition, $moduleId, (int)($definition['priority'] ?? 10));
    }

    foreach (($check['extensions'] ?? []) as $extension) {
        if (!is_array($extension) || empty($extension['context'])) {
            continue;
        }
        app()->entityContexts()->extendContext((string)$extension['context'], $extension, $moduleId, (int)($extension['priority'] ?? 10));
    }

    foreach (($check['bindings'] ?? []) as $binding) {
        if (!is_array($binding) || empty($binding['entity_type'])) {
            continue;
        }
        app()->entityContexts()->bindEntityType((string)$binding['entity_type'], $binding, $moduleId, (int)($binding['priority'] ?? 10));
    }

    foreach (($check['capability_metadata'] ?? []) as $metadata) {
        if (!is_array($metadata) || empty($metadata['id'])) {
            continue;
        }
        app()->entityContexts()->registerCapability((string)$metadata['id'], $metadata, $moduleId, (int)($metadata['priority'] ?? 10));
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

$modules = discoverModules();
registerEntityContextsFromManifest($modules['ecommerce'] ?? []);
registerEntityContextsFromManifest($modules['guidance'] ?? []);

$serviceId = createEntity('service', 'Runtime Bridge Service');
$courseId = createEntity('course', 'Runtime Bridge Course');
$lessonId = createEntity('lesson', 'Runtime Bridge Lesson');

$db = cmsDb();
$db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
     VALUES (:content_id, '_parent_id', :meta_value)
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([
    ':content_id' => $lessonId,
    ':meta_value' => (string)$courseId,
]);

echo "\n=== SERVICE RUNTIME CONTEXT ===\n";

$serviceEntity = ['id' => $serviceId, 'type' => 'service', 'slug' => 'runtime-bridge-service'];
$serviceContext = cmsEntityCapabilityContext($serviceId, $serviceEntity);
$serviceData = cmsEntityCapabilityData($serviceId, $serviceEntity);

t('service enables booking from context profile', !empty($serviceContext['booking']));
t('service enables inquiry from context profile', !empty($serviceContext['inquiry']));
t('service keeps pricing inactive without concrete data', empty($serviceContext['pricing']));
t('service keeps media gallery inactive without gallery data', empty($serviceContext['media_gallery']));
t('service inquiry data is synthesized from context defaults', ($serviceData['inquiry']['label'] ?? '') === 'Inquire');
t('service booking stub data is available', ($serviceData['booking']['stub'] ?? false) === true);
t('service pricing data is not exposed while inactive', !isset($serviceData['pricing']));

$db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
     VALUES (:content_id, '_price', '149.00')
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([':content_id' => $serviceId]);
$db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
     VALUES (:content_id, '_currency', 'USD')
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([':content_id' => $serviceId]);
cmsEntityCapabilityClearCache($serviceId);

$serviceContextWithPrice = cmsEntityCapabilityContext($serviceId, $serviceEntity);
$serviceDataWithPrice = cmsEntityCapabilityData($serviceId, $serviceEntity);

t('service activates pricing when concrete price metadata exists', !empty($serviceContextWithPrice['pricing']));
t('service pricing data reflects content metadata', ($serviceDataWithPrice['pricing']['active_price'] ?? null) === 149.0);

echo "\n=== COURSE RUNTIME CONTEXT ===\n";

$courseEntity = ['id' => $courseId, 'type' => 'course', 'slug' => 'runtime-bridge-course'];
$courseContext = cmsEntityCapabilityContext($courseId, $courseEntity);
$courseData = cmsEntityCapabilityData($courseId, $courseEntity);

t('course enables progress tracking from context profile', !empty($courseContext['progress_tracking']));
t('course enables lessons index when lessons exist', !empty($courseContext['lessons_index']));
t('course keeps pricing inactive without price data', empty($courseContext['pricing']));
t('course keeps inventory inactive without inventory data', empty($courseContext['inventory']));
t('course progress data uses guest fallback', ($courseData['progress_tracking']['authenticated'] ?? null) === false);
t('course lessons index includes child lessons', count($courseData['lessons_index']['items'] ?? []) === 1);

$runtimeState = cmsEntityCapabilityRuntimeState($courseId, $courseEntity);
t('runtime state exposes resolved context payload', ($runtimeState['resolved_context']['entity_type'] ?? '') === 'course');
t('runtime state retains inactive commerce extension flags as false', isset($runtimeState['capabilities']['inventory']) && $runtimeState['capabilities']['inventory'] === false);

$db->prepare('DELETE FROM cms_content_meta WHERE content_id IN (?, ?, ?)')->execute([$serviceId, $courseId, $lessonId]);
$db->prepare('DELETE FROM cms_content WHERE id IN (?, ?, ?)')->execute([$serviceId, $courseId, $lessonId]);

t('runtime bridge test content cleaned up',
    (int)$db->query('SELECT COUNT(*) FROM cms_content WHERE id IN (?, ?, ?)', [$serviceId, $courseId, $lessonId])->fetchColumn() === 0
);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('no critical app log entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('no PHP error log entries', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
