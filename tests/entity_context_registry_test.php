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
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
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

function sectionIds(array $schema): array
{
    $sections = is_array($schema['sections'] ?? null) ? $schema['sections'] : [];
    return array_values(array_filter(array_map(static fn(array $section): string => (string)($section['id'] ?? ''), $sections)));
}

function schemaField(array $schema, string $sectionId, string $fieldName): array
{
    $sections = is_array($schema['sections'] ?? null) ? $schema['sections'] : [];
    foreach ($sections as $section) {
        if (($section['id'] ?? '') !== $sectionId) {
            continue;
        }

        $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
        foreach ($fields as $field) {
            if (($field['name'] ?? '') === $fieldName) {
                return is_array($field) ? $field : [];
            }
        }
    }

    return [];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

$modules = discoverModules();
registerEntityContextsFromManifest($modules['ecommerce'] ?? []);
registerEntityContextsFromManifest($modules['guidance'] ?? []);

echo "\n=== ENTITY CONTEXT MANIFESTS ===\n";

$ecommerceManifestCheck = validateModuleEntityContexts($modules['ecommerce'] ?? []);
$guidanceManifestCheck = validateModuleEntityContexts($modules['guidance'] ?? []);

t('ecommerce entity context manifest is valid', !empty($ecommerceManifestCheck['ok']), (string)($ecommerceManifestCheck['error'] ?? ''));
t('guidance entity context manifest is valid', !empty($guidanceManifestCheck['ok']), (string)($guidanceManifestCheck['error'] ?? ''));

echo "\n=== REGISTRY SNAPSHOT ===\n";

$snapshot = cmsEntityContextRegistrySnapshot();
t('business context is registered', isset($snapshot['contexts']['business']));
t('commerce context is registered', isset($snapshot['contexts']['commerce']));
t('guidance context is registered', isset($snapshot['contexts']['guidance']));
t('product binding resolves commerce base', ($snapshot['bindings']['product']['base'] ?? '') === 'commerce');
t('course binding resolves guidance base', ($snapshot['bindings']['course']['base'] ?? '') === 'guidance');
t('course binding includes commerce extension', in_array('commerce', $snapshot['bindings']['course']['extensions'] ?? [], true));
t('pricing capability block metadata is registered', ($snapshot['capabilities']['pricing']['block'] ?? '') === 'pricing.block');

echo "\n=== RESOLUTION ===\n";

$service = cmsResolveEntityContextForType('service');
t('service resolves business base context', ($service['binding']['base'] ?? '') === 'business');
t('service includes booking capability', in_array('booking', $service['capability_ids'] ?? [], true));
t('service includes inquiry capability', in_array('inquiry', $service['capability_ids'] ?? [], true));
t('service includes media gallery capability', in_array('media_gallery', $service['capability_ids'] ?? [], true));
t('service includes pricing via ecommerce extension', in_array('pricing', $service['capability_ids'] ?? [], true));

$serviceBlocks = array_values(array_map(static fn(array $entry): string => (string)($entry['capability'] ?? ''), $service['blocks'] ?? []));
t('service block order is deterministic', $serviceBlocks === ['media_gallery', 'pricing', 'booking', 'inquiry'], json_encode($serviceBlocks));

$serviceSchemaSections = sectionIds($service['customizer_schema'] ?? []);
t('service schema includes general section', in_array('general', $serviceSchemaSections, true));
t('service schema includes catalog section', in_array('catalog', $serviceSchemaSections, true));
t('service schema includes media section', in_array('media', $serviceSchemaSections, true));
t('service schema includes pricing section', in_array('pricing', $serviceSchemaSections, true));
t('service schema includes actions section', in_array('actions', $serviceSchemaSections, true));
t('service schema does not include progress section before hook', !in_array('progress', $serviceSchemaSections, true));

$serviceCatalogTitleFont = schemaField($service['customizer_schema'] ?? [], 'catalog', 'entity_list_title_font');
t('service schema preserves font field metadata for schema renderer', ($serviceCatalogTitleFont['type'] ?? '') === 'font_select' && ($serviceCatalogTitleFont['empty_option_label'] ?? '') === 'Inherit Heading Font', json_encode($serviceCatalogTitleFont));

$serviceBlogColumns = schemaField($service['customizer_schema'] ?? [], 'archive', 'blog_columns');
t('service schema preserves conditional archive field metadata', (($serviceBlogColumns['depends_on']['field'] ?? '') === 'blog_layout') && (($serviceBlogColumns['depends_on']['operator'] ?? '') === '!='), json_encode($serviceBlogColumns));

app()->hooks()->on('context.extend.business', static function ($context) {
    if ($context instanceof \Ikabud\Kernel\EntityContext\ContextProfile) {
        $context->addCapability('progress_tracking');
    }

    return $context;
}, 10);

$serviceWithHook = cmsResolveEntityContextForType('service');
t('hook-based business extension adds progress_tracking', in_array('progress_tracking', $serviceWithHook['capability_ids'] ?? [], true));
t('hook-based business extension adds progress section to schema', in_array('progress', sectionIds($serviceWithHook['customizer_schema'] ?? []), true));
app()->hooks()->off('context.extend.business');

$course = cmsResolveEntityContextForType('course');
$courseCapabilities = $course['capability_ids'] ?? [];
foreach (['lessons_index', 'progress_tracking', 'media_gallery', 'pricing', 'inventory'] as $capabilityId) {
    t("course resolves {$capabilityId}", in_array($capabilityId, $courseCapabilities, true));
}

$courseBlocks = array_values(array_map(static fn(array $entry): string => (string)($entry['capability'] ?? ''), $course['blocks'] ?? []));
t('course block order is deterministic', $courseBlocks === ['media_gallery', 'progress_tracking', 'pricing', 'inventory', 'lessons_index'], json_encode($courseBlocks));

$courseSchemaSections = sectionIds($course['customizer_schema'] ?? []);
t('course schema includes lessons section', in_array('lessons', $courseSchemaSections, true));
t('course schema includes progress section', in_array('progress', $courseSchemaSections, true));
t('course schema includes inventory section', in_array('inventory', $courseSchemaSections, true));

echo "\n=== EXAMPLES ===\n";

$examples = cmsEntityContextExampleSchemas();
t('content example schema is present', !empty($examples['content']['schema']['sections'] ?? []));
t('business example schema is present', !empty($examples['business']['schema']['sections'] ?? []));
t('guidance example schema is present', !empty($examples['guidance']['schema']['sections'] ?? []));
t('hybrid example schema is present', !empty($examples['hybrid']['schema']['sections'] ?? []));

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
