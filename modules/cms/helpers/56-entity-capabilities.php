<?php

declare(strict_types=1);

// ──────────────────────────────────────────────────────────────────────────────
// Entity Capability Profile System — helpers/56-entity-capabilities.php
//
// An "entity capability" is a FEATURE PROFILE attached to a CMS content entity
// (e.g. "pricing", "inventory", "booking", "progress_tracking"). It is distinct
// from Kernel service capabilities (CapabilityBus contracts between modules).
//
// Entity capabilities drive universal template block rendering: the same
// entity.view.disyl template renders a product, course, or service by detecting
// which feature capabilities are attached.
//
// Data providers are registered on the Kernel CapabilityBus under the convention
// "entity.capability.{id}.data@1" (declared in module.json so the module-manager
// registers the named functions below). External modules can override at higher
// priority (e.g. a future ecommerce module overriding the default pricing provider).
// ──────────────────────────────────────────────────────────────────────────────

function cmsBuiltinEntityCapabilities(): array
{
    return [
        [
            'id'             => 'pricing',
            'label'          => 'Pricing',
            'description'    => 'Attach price, currency, and optional sale price to the entity.',
            'icon'           => 'tag',
            'config_schema'  => [
                'price'      => ['type' => 'number',  'label' => 'Price',         'required' => false],
                'currency'   => ['type' => 'string',  'label' => 'Currency Code', 'default'  => 'USD'],
                'sale_price' => ['type' => 'number',  'label' => 'Sale Price',    'required' => false],
            ],
            'default_config' => ['currency' => 'USD'],
        ],
        [
            'id'             => 'inventory',
            'label'          => 'Inventory',
            'description'    => 'Track stock quantity and SKU for the entity.',
            'icon'           => 'package',
            'config_schema'  => [
                'track_stock' => ['type' => 'boolean', 'label' => 'Track Stock', 'default' => true],
                'sku'         => ['type' => 'string',  'label' => 'SKU',         'required' => false],
                'stock_qty'   => ['type' => 'integer', 'label' => 'Stock Qty',   'default'  => 0],
            ],
            'default_config' => ['track_stock' => true, 'stock_qty' => 0],
        ],
        [
            'id'             => 'booking',
            'label'          => 'Booking',
            'description'    => 'Enable appointment or slot booking for the entity.',
            'icon'           => 'calendar',
            'config_schema'  => [
                'slot_duration_minutes' => ['type' => 'integer', 'label' => 'Slot Duration (min)',     'default' => 60],
                'advance_days'          => ['type' => 'integer', 'label' => 'Book Up to N Days Ahead', 'default' => 30],
            ],
            'default_config' => ['slot_duration_minutes' => 60, 'advance_days' => 30],
        ],
        [
            'id'             => 'inquiry',
            'label'          => 'Inquiry / Contact',
            'description'    => 'Show a contact/inquiry CTA instead of a buy or book button.',
            'icon'           => 'mail',
            'config_schema'  => [
                'label'       => ['type' => 'string', 'label' => 'CTA Label',                     'default' => 'Inquire'],
                'form_fields' => ['type' => 'string', 'label' => 'Form Fields (comma-separated)', 'default' => 'name,email,message'],
            ],
            'default_config' => ['label' => 'Inquire'],
        ],
        [
            'id'             => 'progress_tracking',
            'label'          => 'Progress Tracking',
            'description'    => 'Track per-user completion progress (e.g. for courses or challenges).',
            'icon'           => 'activity',
            'config_schema'  => [
                'unit' => ['type' => 'string', 'label' => 'Progress Unit', 'default' => 'percent'],
            ],
            'default_config' => ['unit' => 'percent'],
        ],
        [
            'id'             => 'lessons_index',
            'label'          => 'Lessons / Chapters',
            'description'    => 'Render an ordered index of child entities (lessons, chapters, modules).',
            'icon'           => 'list',
            'config_schema'  => [
                'child_type'   => ['type' => 'string',  'label' => 'Child Content Type', 'default' => 'lesson'],
                'show_numbers' => ['type' => 'boolean', 'label' => 'Show Numbers',       'default' => true],
            ],
            'default_config' => ['child_type' => 'lesson', 'show_numbers' => true],
        ],
        [
            'id'             => 'media_gallery',
            'label'          => 'Media Gallery',
            'description'    => 'Display an extended gallery block with lightbox support.',
            'icon'           => 'image',
            'config_schema'  => [
                'columns'  => ['type' => 'integer', 'label' => 'Columns',         'default' => 3],
                'lightbox' => ['type' => 'boolean', 'label' => 'Enable Lightbox', 'default' => true],
            ],
            'default_config' => ['columns' => 3, 'lightbox' => true],
        ],
    ];
}

function cmsEntityCapabilityTypes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $caps = cmsBuiltinEntityCapabilities();

    $extras = app()->hooks()->filter('cms.entity.capabilities.register', []);
    if (is_array($extras)) {
        foreach ($extras as $extra) {
            if (!empty($extra['id']) && is_string($extra['id'])) {
                $caps[] = $extra;
            }
        }
    }

    $indexed = [];
    foreach ($caps as $cap) {
        $indexed[(string)$cap['id']] = $cap;
    }

    $cache = $indexed;
    return $cache;
}

function cmsEntityAttachCapability(int $entityId, string $capId, array $config = []): void
{
    $knownTypes = cmsEntityCapabilityTypes();
    if (!isset($knownTypes[$capId])) {
        throw new \InvalidArgumentException("Unknown entity capability: {$capId}");
    }

    $defaults = $knownTypes[$capId]['default_config'] ?? [];
    $merged   = array_merge($defaults, $config);

    $db      = cmsDb();
    $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE);
    $stmt    = $db->prepare(
        "INSERT INTO cms_entity_capabilities (entity_id, capability_id, config)
         VALUES (:entity_id, :capability_id, :config)
         ON DUPLICATE KEY UPDATE config = :config2, updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':entity_id'     => $entityId,
        ':capability_id' => $capId,
        ':config'        => $encoded,
        ':config2'       => $encoded,
    ]);

    cmsEntityCapabilityClearCache($entityId);
}

function cmsEntityDetachCapability(int $entityId, string $capId): void
{
    $db   = cmsDb();
    $stmt = $db->prepare(
        "DELETE FROM cms_entity_capabilities WHERE entity_id = :entity_id AND capability_id = :capability_id"
    );
    $stmt->execute([':entity_id' => $entityId, ':capability_id' => $capId]);
    cmsEntityCapabilityClearCache($entityId);
}

function cmsEntityGetCapabilities(int $entityId): array
{
    $cacheKey = 'cms_entity_caps_' . $entityId;
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT capability_id, config FROM cms_entity_capabilities
             WHERE entity_id = :entity_id ORDER BY capability_id"
        );
        $stmt->execute([':entity_id' => $entityId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        write_log('warn', 'cms.entity_capabilities.read_error', [
            'entity_id' => $entityId,
            'error'     => $e->getMessage(),
        ]);
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        $config  = [];
        if (is_string($row['config'])) {
            $decoded = json_decode($row['config'], true);
            $config  = is_array($decoded) ? $decoded : [];
        }
        $result[(string)$row['capability_id']] = $config;
    }

    $GLOBALS[$cacheKey] = $result;
    return $result;
}

function cmsEntityCapabilityClearCache(int $entityId): void
{
    unset($GLOBALS['cms_entity_caps_' . $entityId]);
    unset($GLOBALS['cms_entity_type_' . $entityId]);
    unset($GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId]);
    unset($GLOBALS['cms_entity_render_projection_request_cache'][$entityId]);
}

function cmsEntityCapabilityRequestUserFingerprint(): string
{
    $user = null;

    if (function_exists('cmsCtxUser')) {
        try {
            $resolvedUser = cmsCtxUser();
            $user = is_array($resolvedUser) ? $resolvedUser : null;
        } catch (\Throwable $e) {
            $user = null;
        }
    }

    if ($user === null) {
        try {
            $resolvedUser = app()->cap()->call('kernel.auth.user@1');
            $user = is_array($resolvedUser) ? $resolvedUser : null;
        } catch (\Throwable $e) {
            $user = null;
        }
    }

    if ($user === null) {
        return 'guest';
    }

    $source = trim((string)($user['source'] ?? ''));
    if ($source === '') {
        $source = 'user';
    }

    return $source . ':' . (int)($user['id'] ?? 0) . ':' . trim((string)($user['role'] ?? ''));
}

function cmsEntityCapabilityRequestCacheableEntityPayload(array $entity): array
{
    foreach ([
        'capabilities',
        'capability_data',
        'entity_context',
        'cart_enabled',
        'cart_action_url',
        'action_sections',
        'primary_image_url',
        'list_card_excerpt',
        'list_card_pricing_html',
        'list_card_inventory_html',
        'list_card_progress_html',
        'list_card_action_html',
        'content_type_label',
        'entity_type_label',
        'published_at_display',
        'entity_render_family',
        'entity_presentation',
    ] as $key) {
        unset($entity[$key]);
    }

    return $entity;
}

function cmsEntityCapabilityRequestEntityFingerprint(array $entity): string
{
    $payload = cmsEntityCapabilityRequestCacheableEntityPayload($entity);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $encoded = serialize($payload);
    }

    return md5($encoded);
}

function cmsEntityCapabilityRuntimeRequestCacheKey(int $entityId, array $entity = []): ?string
{
    if ($entityId <= 0) {
        return null;
    }

    return implode('|', [
        cmsEntityCapabilityEntityType($entityId, $entity),
        cmsEntityCapabilityRequestUserFingerprint(),
        cmsEntityCapabilityRequestEntityFingerprint($entity),
    ]);
}

function cmsEntityRenderProjectionRequestCacheKey(array $entity, array $options = []): ?string
{
    if (is_array($options['runtime'] ?? null)) {
        return null;
    }

    $entityId = (int)($entity['id'] ?? 0);
    if ($entityId <= 0) {
        return null;
    }

    $runtimeCacheKey = cmsEntityCapabilityRuntimeRequestCacheKey($entityId, $entity);
    if ($runtimeCacheKey === null) {
        return null;
    }

    $fallbackCapabilityIds = array_values(array_filter(
        is_array($options['fallback_capability_data'] ?? null) ? $options['fallback_capability_data'] : [],
        static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
    ));
    sort($fallbackCapabilityIds);

    $cartCapabilityAvailable = false;
    if (!empty($options['include_cart'])) {
        try {
            $cartCapabilityAvailable = app()->capabilities()->has('cms.cart.add@1');
        } catch (\Throwable $e) {
            $cartCapabilityAvailable = false;
        }
    }

    $payload = [
        'runtime' => $runtimeCacheKey,
        'include_cart' => !empty($options['include_cart']),
        'cart_capability_available' => $cartCapabilityAvailable,
        'include_action_sections' => !empty($options['include_action_sections']),
        'base_url' => rtrim((string)($options['base_url'] ?? (defined('BASE_URL') ? BASE_URL : '')), '/'),
        'fallback_capability_data' => $fallbackCapabilityIds,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $encoded = serialize($payload);
    }

    return md5($encoded);
}

function cmsEntityCapabilityRememberRuntimeState(int $entityId, ?string $cacheKey, array $runtime): array
{
    if ($entityId <= 0 || $cacheKey === null) {
        return $runtime;
    }

    if (!isset($GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId]) || !is_array($GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId])) {
        $GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId] = [];
    }

    $GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId][$cacheKey] = $runtime;
    return $runtime;
}

function cmsEntityRememberRenderProjection(int $entityId, ?string $cacheKey, array $projection): array
{
    if ($entityId <= 0 || $cacheKey === null) {
        return $projection;
    }

    if (!isset($GLOBALS['cms_entity_render_projection_request_cache'][$entityId]) || !is_array($GLOBALS['cms_entity_render_projection_request_cache'][$entityId])) {
        $GLOBALS['cms_entity_render_projection_request_cache'][$entityId] = [];
    }

    $GLOBALS['cms_entity_render_projection_request_cache'][$entityId][$cacheKey] = $projection;
    return $projection;
}

function cmsEntityCapabilityEntityType(int $entityId, array $entity = []): string
{
    $type = trim((string)($entity['type'] ?? $entity['entity_type'] ?? ''));
    if ($type !== '') {
        return $type;
    }

    if ($entityId <= 0) {
        return '';
    }

    $cacheKey = 'cms_entity_type_' . $entityId;
    if (isset($GLOBALS[$cacheKey]) && is_string($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    try {
        $db = cmsDb();
        $stmt = $db->prepare('SELECT type FROM cms_content WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $entityId]);
        $type = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (\Throwable $e) {
        $type = '';
    }

    $GLOBALS[$cacheKey] = $type;

    return $type;
}

function cmsEntityCapabilityKnownIds(array $attached, array $resolvedContext): array
{
    $knownIds = array_keys(cmsEntityCapabilityTypes());

    foreach (array_keys($attached) as $capId) {
        if (!is_string($capId) || $capId === '' || in_array($capId, $knownIds, true)) {
            continue;
        }
        $knownIds[] = $capId;
    }

    foreach (($resolvedContext['capability_ids'] ?? []) as $capId) {
        if (!is_string($capId) || $capId === '' || in_array($capId, $knownIds, true)) {
            continue;
        }
        $knownIds[] = $capId;
    }

    return $knownIds;
}

function cmsEntityCapabilityActivationPolicy(string $capId, array $definition = []): string
{
    $meta = is_array($definition['meta'] ?? null) ? $definition['meta'] : [];
    $policy = trim((string)($meta['runtime_activation'] ?? ''));
    if ($policy !== '') {
        return $policy;
    }

    return match ($capId) {
        'booking', 'inquiry', 'progress_tracking' => 'profile_default',
        'lessons_index' => 'items_required',
        'media_gallery' => 'gallery_required',
        'pricing' => 'price_required',
        'inventory' => 'inventory_required',
        default => 'attached_only',
    };
}

function cmsEntityCapabilityShouldActivateProfileCapability(string $capId, array $definition, array $capabilityData): bool
{
    return match (cmsEntityCapabilityActivationPolicy($capId, $definition)) {
        'profile_default' => true,
        'items_required' => !empty($capabilityData['items']) && is_array($capabilityData['items']),
        'gallery_required' => !empty($capabilityData['items']) && is_array($capabilityData['items']),
        'price_required' => ($capabilityData['active_price'] ?? $capabilityData['price'] ?? null) !== null,
        'inventory_required' => ($capabilityData['stock_qty'] ?? null) !== null
            || !empty($capabilityData['sku'])
            || !empty($capabilityData['track_inventory'])
            || !empty($capabilityData['track_stock']),
        'data_required' => $capabilityData !== [],
        default => false,
    };
}

function cmsEntityCapabilityProviderData(string $capId, int $entityId, array $entity, array $config = [], bool $isAttached = false): array
{
    if ($entityId <= 0) {
        return [];
    }

    if (!$isAttached && in_array($capId, ['pricing', 'inventory'], true)) {
        $fallbackFunction = 'cms_cap_entity_capability_' . $capId . '_data_1';
        if (function_exists($fallbackFunction)) {
            try {
                $result = $fallbackFunction([
                    'entity' => $entity,
                    'config' => $config,
                    'entity_id' => $entityId,
                ]);

                return is_array($result) ? $result : [];
            } catch (\Throwable $e) {
                return [];
            }
        }
    }

    $providerKey = "entity.capability.{$capId}.data@1";
    $registry = app()->capabilities();
    if (!$registry->has($providerKey)) {
        return [];
    }

    try {
        $result = app()->cap()->call($providerKey, [
            'entity' => $entity,
            'config' => $config,
            'entity_id' => $entityId,
        ]);

        return is_array($result) ? $result : [];
    } catch (\Throwable $e) {
        write_log('warn', 'cms.entity_capability_data.error', [
            'entity_id' => $entityId,
            'cap_id' => $capId,
            'error' => $e->getMessage(),
        ]);

        return [];
    }
}

function cmsEntityCapabilityRuntimeState(int $entityId, array $entity = []): array
{
    $requestCacheKey = cmsEntityCapabilityRuntimeRequestCacheKey($entityId, $entity);
    if ($requestCacheKey !== null) {
        $cachedRuntime = $GLOBALS['cms_entity_capability_runtime_request_cache'][$entityId][$requestCacheKey] ?? null;
        if (is_array($cachedRuntime)) {
            return $cachedRuntime;
        }
    }

    $attached = $entityId > 0 ? cmsEntityGetCapabilities($entityId) : [];
    $entityType = cmsEntityCapabilityEntityType($entityId, $entity);
    $resolvedContext = [];

    if ($entityType !== '' && function_exists('cmsResolveEntityContextForType')) {
        try {
            $resolvedContext = cmsResolveEntityContextForType($entityType, [
                'attached_capabilities' => $attached,
            ]);
        } catch (\Throwable $e) {
            $resolvedContext = [];
        }
    }

    $capabilities = [];
    foreach (cmsEntityCapabilityKnownIds($attached, $resolvedContext) as $capId) {
        $capabilities[$capId] = false;
    }

    $capabilityData = [];
    $resolvedCapabilityDefinitions = is_array($resolvedContext['capabilities'] ?? null)
        ? $resolvedContext['capabilities']
        : [];
    $resolvedCapabilityIds = array_values(array_filter(
        is_array($resolvedContext['capability_ids'] ?? null) ? $resolvedContext['capability_ids'] : [],
        static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
    ));

    foreach ($capabilities as $capId => $_enabled) {
        $isAttached = array_key_exists($capId, $attached);
        $isProfileCapability = in_array($capId, $resolvedCapabilityIds, true);
        if (!$isAttached && !$isProfileCapability) {
            continue;
        }

        $definition = is_array($resolvedCapabilityDefinitions[$capId] ?? null)
            ? $resolvedCapabilityDefinitions[$capId]
            : [];
        $config = is_array($attached[$capId] ?? null)
            ? $attached[$capId]
            : (is_array($definition['config'] ?? null) ? $definition['config'] : []);
        $data = cmsEntityCapabilityProviderData($capId, $entityId, $entity, $config, $isAttached);
        $isActive = $isAttached || cmsEntityCapabilityShouldActivateProfileCapability($capId, $definition, $data);

        $capabilities[$capId] = $isActive;
        if ($isActive || $isAttached) {
            $capabilityData[$capId] = $data;
        }
    }

    return cmsEntityCapabilityRememberRuntimeState($entityId, $requestCacheKey, [
        'entity_type' => $entityType,
        'attached_capabilities' => $attached,
        'resolved_context' => $resolvedContext,
        'capabilities' => $capabilities,
        'capability_data' => $capabilityData,
    ]);
}

function cmsEntityRenderProjectionFallbackData(
    int $entityId,
    array $entity,
    array $attachedCapabilities,
    array $projection,
    array $fallbackCapabilityIds
): array {
    foreach ($fallbackCapabilityIds as $capabilityId) {
        if (!is_string($capabilityId)) {
            continue;
        }

        $capabilityId = trim($capabilityId);
        if ($capabilityId === '') {
            continue;
        }

        if (empty($projection['capabilities'][$capabilityId]) || !empty($projection['capability_data'][$capabilityId])) {
            continue;
        }

        $fallbackFunction = 'cms_cap_entity_capability_' . $capabilityId . '_data_1';
        if (!function_exists($fallbackFunction)) {
            continue;
        }

        try {
            $data = $fallbackFunction([
                'entity' => $entity,
                'config' => is_array($attachedCapabilities[$capabilityId] ?? null) ? $attachedCapabilities[$capabilityId] : [],
                'entity_id' => $entityId,
            ]);
            if (is_array($data) && $data !== []) {
                $projection['capability_data'][$capabilityId] = $data;
            }
        } catch (\Throwable $e) {
        }
    }

    return $projection;
}

function cmsEntityRenderProjection(array $entity, array $options = []): array
{
    $entityId = (int)($entity['id'] ?? 0);
    $includeCart = !empty($options['include_cart']);
    $includeActionSections = !empty($options['include_action_sections']);
    $baseUrl = rtrim((string)($options['base_url'] ?? (defined('BASE_URL') ? BASE_URL : '')), '/');
    $projectionCacheKey = cmsEntityRenderProjectionRequestCacheKey($entity, $options);

    if ($entityId > 0 && $projectionCacheKey !== null) {
        $cachedProjection = $GLOBALS['cms_entity_render_projection_request_cache'][$entityId][$projectionCacheKey] ?? null;
        if (is_array($cachedProjection)) {
            return $cachedProjection;
        }
    }

    $projection = [
        'capabilities' => [],
        'capability_data' => [],
        'entity_context' => [],
    ];

    if ($includeCart) {
        $projection['cart_enabled'] = false;
        $projection['cart_action_url'] = '';
    }

    if ($includeActionSections) {
        $projection['action_sections'] = '';
    }

    if ($entityId <= 0) {
        return $projection;
    }

    $runtime = is_array($options['runtime'] ?? null) ? $options['runtime'] : [];
    $attachedCapabilities = [];
    $logErrorEvent = trim((string)($options['log_error_event'] ?? ''));
    $logErrorContext = is_array($options['log_error_context'] ?? null) ? $options['log_error_context'] : [];

    try {
        if ($runtime === []) {
            $runtime = cmsEntityCapabilityRuntimeState($entityId, $entity);
        }

        $projection['capabilities'] = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
        $projection['capability_data'] = is_array($runtime['capability_data'] ?? null) ? $runtime['capability_data'] : [];
        $projection['entity_context'] = is_array($runtime['resolved_context'] ?? null) ? $runtime['resolved_context'] : [];
        $attachedCapabilities = is_array($runtime['attached_capabilities'] ?? null) ? $runtime['attached_capabilities'] : [];
    } catch (\Throwable $e) {
        if ($logErrorEvent !== '') {
            write_log('warn', $logErrorEvent, array_merge($logErrorContext, [
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]));
        }

        return cmsEntityRememberRenderProjection($entityId, $projectionCacheKey, $projection);
    }

    $fallbackCapabilityIds = array_values(array_filter(
        is_array($options['fallback_capability_data'] ?? null) ? $options['fallback_capability_data'] : [],
        static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
    ));

    if ($fallbackCapabilityIds !== []) {
        $projection = cmsEntityRenderProjectionFallbackData(
            $entityId,
            $entity,
            $attachedCapabilities,
            $projection,
            $fallbackCapabilityIds
        );
    }

    if ($includeCart) {
        try {
            if (!empty($projection['capabilities']['pricing']) && app()->capabilities()->has('cms.cart.add@1')) {
                $projection['cart_enabled'] = true;
                $projection['cart_action_url'] = $baseUrl . '/ecommerce/cart/add';
            }
        } catch (\Throwable $e) {
        }
    }

    if ($includeActionSections) {
        try {
            $sections = app()->hooks()->filter('cms.entity.action_block.sections', [], [
                'entity' => $entity,
                'capabilities' => $projection['capabilities'],
                'capability_data' => $projection['capability_data'],
                'base_url' => $baseUrl,
            ]);
            if (is_string($sections) && $sections !== '') {
                $projection['action_sections'] = $sections;
            }
        } catch (\Throwable $e) {
        }
    }

    return cmsEntityRememberRenderProjection($entityId, $projectionCacheKey, $projection);
}

function cmsEntityCapabilityResolvedContext(int $entityId, array $entity = []): array
{
    $runtime = cmsEntityCapabilityRuntimeState($entityId, $entity);

    return is_array($runtime['resolved_context'] ?? null) ? $runtime['resolved_context'] : [];
}

function cmsEntityCapabilityContext(int $entityId, array $entity = []): array
{
    $runtime = cmsEntityCapabilityRuntimeState($entityId, $entity);

    return is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
}

function cmsEntityCapabilityData(int $entityId, array $entity = []): array
{
    $runtime = cmsEntityCapabilityRuntimeState($entityId, $entity);
    $data = is_array($runtime['capability_data'] ?? null) ? $runtime['capability_data'] : [];

    // Phase 5 capability contract enforcement
    foreach (['pricing', 'inventory', 'progress_tracking', 'lessons_index', 'media_gallery'] as $cap) {
        if (array_key_exists($cap, $data) && !is_array($data[$cap])) {
            $data[$cap] = [];
        }
    }

    if (array_key_exists('pricing', $data)) {
        if (!array_key_exists('price', $data['pricing']) || ($data['pricing']['price'] !== null && !is_float($data['pricing']['price']))) {
            $data['pricing']['price'] = $data['pricing']['price'] !== null ? (float)$data['pricing']['price'] : null;
        }
        if (empty($data['pricing']['currency']) || !is_string($data['pricing']['currency'])) {
            $data['pricing']['currency'] = "USD";
        }
    }
    
    if (array_key_exists('inventory', $data)) {
        $data['inventory']['in_stock'] = (bool)($data['inventory']['in_stock'] ?? false);
        $data['inventory']['track_inventory'] = (bool)($data['inventory']['track_inventory'] ?? false);
    }
    
    if (array_key_exists('progress_tracking', $data)) {
        $percent = (int)($data['progress_tracking']['percent'] ?? 0);
        $data['progress_tracking']['percent'] = max(0, min(100, $percent));
        $data['progress_tracking']['authenticated'] = (bool)($data['progress_tracking']['authenticated'] ?? false);
    }
    
    if (array_key_exists('lessons_index', $data)) {
        if (!is_array($data['lessons_index']['items'] ?? null)) {
            $data['lessons_index']['items'] = [];
        }
    }
    
    if (array_key_exists('media_gallery', $data)) {
        if (!is_array($data['media_gallery']['items'] ?? null)) {
            $data['media_gallery']['items'] = [];
        }
        $columns = (int)($data['media_gallery']['columns'] ?? 3);
        $data['media_gallery']['columns'] = max(1, $columns);
    }

    return $data;
}

// ── Preset System ────────────────────────────────────────────────────────────

function cmsEntityPresets(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $presets = [];

    $presetDir = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/config/entity-presets';
    if (is_dir($presetDir)) {
        foreach (glob($presetDir . '/*.json') ?: [] as $file) {
            $decoded = kernelReadJsonFile($file);
            if (is_array($decoded) && !empty($decoded['id'])) {
                $presets[(string)$decoded['id']] = $decoded;
            }
        }
    }

    $extras = app()->hooks()->filter('cms.entity.presets', []);
    if (is_array($extras)) {
        foreach ($extras as $preset) {
            if (!empty($preset['id']) && is_string($preset['id'])) {
                $presets[$preset['id']] = $preset;
            }
        }
    }

    $cache = $presets;
    return $cache;
}

function cmsEntityPresetTargetList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $item = strtolower(trim($item));
        if ($item !== '') {
            $normalized[$item] = $item;
        }
    }

    return array_values($normalized);
}

function cmsEntityPresetRecommendationScore(array $preset, string $entityType, array $resolvedContext = []): int
{
    $entityType = strtolower(trim($entityType));
    $targetTypes = cmsEntityPresetTargetList($preset['entity_types'] ?? []);
    $targetBases = cmsEntityPresetTargetList($preset['context_bases'] ?? []);
    $targetExtensions = cmsEntityPresetTargetList($preset['context_extensions'] ?? []);
    $hasTargeting = $targetTypes !== [] || $targetBases !== [] || $targetExtensions !== [];

    if (!$hasTargeting) {
        return 0;
    }

    $binding = is_array($resolvedContext['binding'] ?? null) ? $resolvedContext['binding'] : [];
    $base = strtolower(trim((string)($binding['base'] ?? '')));
    $extensions = cmsEntityPresetTargetList($binding['extensions'] ?? []);

    if ($targetTypes !== [] && ($entityType === '' || !in_array($entityType, $targetTypes, true))) {
        return -1;
    }

    if ($targetBases !== [] && ($base === '' || !in_array($base, $targetBases, true))) {
        return -1;
    }

    foreach ($targetExtensions as $extensionId) {
        if (!in_array($extensionId, $extensions, true)) {
            return -1;
        }
    }

    $score = 100 + (int)($preset['recommendation_priority'] ?? 0);
    if ($entityType !== '' && in_array($entityType, $targetTypes, true)) {
        $score += 30;
    }
    if ($base !== '' && in_array($base, $targetBases, true)) {
        $score += 20;
    }

    $score += count(array_intersect($targetExtensions, $extensions)) * 10;
    return $score;
}

function cmsEntityPresetRecommendationsForType(string $entityType, array $options = []): array
{
    $entityType = strtolower(trim($entityType));
    if ($entityType === '') {
        return [];
    }

    $resolvedContext = is_array($options['resolved_context'] ?? null) ? $options['resolved_context'] : null;
    if (!is_array($resolvedContext)) {
        $resolveOptions = $options;
        unset($resolveOptions['resolved_context']);
        $resolvedContext = cmsResolveEntityContextForType($entityType, $resolveOptions);
    }

    $matches = [];
    foreach (cmsEntityPresets() as $preset) {
        if (!is_array($preset)) {
            continue;
        }

        $score = cmsEntityPresetRecommendationScore($preset, $entityType, $resolvedContext);
        if ($score <= 0) {
            continue;
        }

        $preset['_recommendation_score'] = $score;
        $matches[] = $preset;
    }

    usort($matches, static function (array $left, array $right): int {
        $scoreCompare = (int)($right['_recommendation_score'] ?? 0) <=> (int)($left['_recommendation_score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return strcasecmp((string)($left['label'] ?? $left['id'] ?? ''), (string)($right['label'] ?? $right['id'] ?? ''));
    });

    foreach ($matches as &$preset) {
        unset($preset['_recommendation_score']);
    }
    unset($preset);

    return $matches;
}

function cmsApplyEntityPreset(int $entityId, string $presetId): void
{
    $presets = cmsEntityPresets();
    if (!isset($presets[$presetId])) {
        throw new \InvalidArgumentException("Unknown entity preset: {$presetId}");
    }

    foreach ($presets[$presetId]['default_capabilities'] ?? [] as $capDef) {
        if (empty($capDef['id'])) {
            continue;
        }
        try {
            cmsEntityAttachCapability($entityId, (string)$capDef['id'], $capDef['config'] ?? []);
        } catch (\InvalidArgumentException $e) {
            write_log('warn', 'cms.entity_preset.unknown_cap', [
                'entity_id' => $entityId,
                'preset_id' => $presetId,
                'cap_id'    => $capDef['id'],
            ]);
        }
    }

    write_log('info', 'cms.entity_preset.applied', ['entity_id' => $entityId, 'preset_id' => $presetId]);
}

// ── CapabilityBus Data Provider Implementations ───────────────────────────────
// Named functions registered via cms_capability_handlers() in 55-capabilities.php.
// Each function IS the actual handler — no bus re-dispatch, no infinite loops.
// External modules override by registering the same capability ID at higher priority.

function cms_cap_entity_capability_pricing_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)(is_array($payload) ? ($payload['entity']['id'] ?? 0) : 0);
    if ($entityId <= 0) return [];
    $config = is_array($payload) ? ($payload['config'] ?? []) : [];

    if (is_array($config) && array_key_exists('price', $config)) {
        $price = $config['price'] !== '' && $config['price'] !== null ? (float)$config['price'] : null;
        $salePrice = array_key_exists('sale_price', $config) && $config['sale_price'] !== '' && $config['sale_price'] !== null
            ? (float)$config['sale_price']
            : null;
        $currency = (string)($config['currency'] ?? 'USD');
        $onSale = $price !== null && $salePrice !== null && $salePrice < $price;

        return [
            'price' => $price,
            'currency' => $currency,
            'sale_price' => $onSale ? $salePrice : null,
            'active_price' => $onSale ? $salePrice : $price,
            'on_sale' => $onSale,
        ];
    }

    try {
        $db = cmsDb();
        $stmt = $db->prepare(
            "SELECT config FROM cms_entity_capabilities
             WHERE entity_id = :id AND capability_id = 'pricing' LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $capConfig = (array)json_decode((string)($row['config'] ?? '{}'), true);
            $price = isset($capConfig['price']) ? (float)$capConfig['price'] : null;
            $salePrice = isset($capConfig['sale_price']) ? (float)$capConfig['sale_price'] : null;
            $currency = (string)($capConfig['currency'] ?? $config['currency'] ?? 'USD');
            $onSale = $price !== null && $salePrice !== null && $salePrice < $price;

            return [
                'price' => $price,
                'currency' => $currency,
                'sale_price' => $onSale ? $salePrice : null,
                'active_price' => $onSale ? $salePrice : $price,
                'on_sale' => $onSale,
            ];
        }
    } catch (\Throwable $e) {
    }

    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT meta_key, meta_value FROM cms_content_meta
             WHERE content_id = :id AND meta_key IN ('_price','_currency','_sale_price') LIMIT 3"
        );
        $stmt->execute([':id' => $entityId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        return [];
    }

    $price = isset($rows['_price']) ? (float)$rows['_price'] : null;
    $salePrice = isset($rows['_sale_price']) ? (float)$rows['_sale_price'] : null;
    $onSale = $price !== null && $salePrice !== null && $salePrice < $price;

    return [
        'price' => $price,
        'currency' => (string)($rows['_currency'] ?? $config['currency'] ?? 'USD'),
        'sale_price' => $onSale ? $salePrice : null,
        'active_price' => $onSale ? $salePrice : $price,
        'on_sale' => $onSale,
    ];
}

function cms_cap_entity_capability_inventory_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)(is_array($payload) ? ($payload['entity']['id'] ?? 0) : 0);
    if ($entityId <= 0) return [];
    $config = is_array($payload) ? ($payload['config'] ?? []) : [];

    $inventoryState = static function (bool $trackStock, int $stockQty, ?int $threshold = null): array {
        if ($threshold === null) {
            $threshold = 5;
            if (function_exists('ecSettings')) {
                try {
                    $threshold = max(0, (int)ecSettings('low_stock_threshold'));
                } catch (Throwable $e) {
                    $threshold = 5;
                }
            }
        }

        return [
            'in_stock' => !$trackStock || $stockQty > 0,
            'out_of_stock' => $trackStock && $stockQty <= 0,
            'low_stock' => $trackStock && $stockQty > 0 && $stockQty <= $threshold,
        ];
    };

    if (is_array($config) && (array_key_exists('track_stock', $config) || array_key_exists('stock_qty', $config) || array_key_exists('sku', $config))) {
        $trackStock = (bool)($config['track_stock'] ?? true);
        $stockQty = isset($config['stock_qty']) && $config['stock_qty'] !== '' ? (int)$config['stock_qty'] : 0;
        $state = $inventoryState($trackStock, $stockQty);

        return [
            'sku' => $config['sku'] ?? null,
            'stock_qty' => $stockQty,
            'track_stock' => $trackStock,
            'in_stock' => $state['in_stock'],
            'out_of_stock' => $state['out_of_stock'],
            'low_stock' => $state['low_stock'],
        ];
    }

    try {
        $db = cmsDb();
        $stmt = $db->prepare(
            "SELECT config FROM cms_entity_capabilities
             WHERE entity_id = :id AND capability_id = 'inventory' LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $config = (array)json_decode((string)($row['config'] ?? '{}'), true);
            $trackStock = (bool)($config['track_stock'] ?? true);
            $stockQty = isset($config['stock_qty']) ? (int)$config['stock_qty'] : 0;
            $state = $inventoryState($trackStock, $stockQty);

            return [
                'sku' => $config['sku'] ?? null,
                'stock_qty' => $stockQty,
                'track_stock' => $trackStock,
                'in_stock' => $state['in_stock'],
                'out_of_stock' => $state['out_of_stock'],
                'low_stock' => $state['low_stock'],
            ];
        }
    } catch (\Throwable $e) {
    }

    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT meta_key, meta_value FROM cms_content_meta
             WHERE content_id = :id AND meta_key IN ('_sku','_stock_qty','_track_inventory') LIMIT 3"
        );
        $stmt->execute([':id' => $entityId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        return [];
    }

    $trackInventory = !empty($rows['_track_inventory']);
    $stockQty = isset($rows['_stock_qty']) ? (int)$rows['_stock_qty'] : null;
    $state = $inventoryState($trackInventory, (int)($stockQty ?? 0));

    return [
        'sku' => $rows['_sku'] ?? null,
        'stock_qty' => $stockQty,
        'track_stock' => $trackInventory,
        'in_stock' => !isset($rows['_stock_qty']) ? true : $state['in_stock'],
        'out_of_stock' => isset($rows['_stock_qty']) ? $state['out_of_stock'] : false,
        'low_stock' => isset($rows['_stock_qty']) ? $state['low_stock'] : false,
    ];
}

function cms_cap_entity_capability_booking_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    // Stub: a future booking module registers the same capability ID at higher priority.
    return ['available_slots' => [], 'stub' => true];
}

function cms_cap_entity_capability_inquiry_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $config = is_array($payload) ? ($payload['config'] ?? []) : [];
    return [
        'label'       => (string)($config['label'] ?? 'Inquire'),
        'form_fields' => array_values(array_filter(
            array_map('trim', explode(',', (string)($config['form_fields'] ?? 'name,email,message')))
        )),
    ];
}

function cms_cap_entity_capability_progress_tracking_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)(is_array($payload) ? ($payload['entity']['id'] ?? 0) : 0);
    if ($entityId <= 0) return ['percent' => 0];

    $user = null;
    try {
        $userResult = app()->cap()->call('kernel.auth.user@1');
        $user = is_array($userResult) ? $userResult : null;
    } catch (\Throwable $e) {}

    if (!$user) {
        return ['percent' => 0, 'authenticated' => false];
    }

    $userId = (int)$user['id'];

    // Primary: dedicated progress table (scales to large user counts)
    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT percent FROM cms_entity_progress WHERE entity_id = :eid AND user_id = :uid LIMIT 1"
        );
        $stmt->execute([':eid' => $entityId, ':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return ['percent' => min(100, max(0, (int)$row['percent'])), 'authenticated' => true];
        }
    } catch (\Throwable $e) {
        // Table may not exist yet (migration pending) — fall through to legacy
    }

    // Legacy fallback: cms_content_meta pattern (pre-022 migration)
    try {
        $db          = cmsDb();
        $progressKey = '_progress_user_' . $userId;
        $stmt        = $db->prepare(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = :cid AND meta_key = :key LIMIT 1"
        );
        $stmt->execute([':cid' => $entityId, ':key' => $progressKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['percent' => 0, 'authenticated' => true];
    }
    $percent = 0;
    if ($row) {
        $data    = json_decode((string)$row['meta_value'], true);
        $percent = is_array($data) ? (int)($data['percent'] ?? 0) : (int)$row['meta_value'];
    }
    return ['percent' => min(100, max(0, $percent)), 'authenticated' => true];
}

function cms_cap_entity_capability_lessons_index_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId  = (int)(is_array($payload) ? ($payload['entity']['id'] ?? 0) : 0);
    $config    = is_array($payload) ? ($payload['config'] ?? []) : [];
    $childType = preg_replace('/[^a-z0-9_\-]/i', '', (string)($config['child_type'] ?? 'lesson'));
    if ($childType === '') {
        $childType = 'lesson';
    }
    if ($entityId <= 0) return ['items' => []];
    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.status
             FROM cms_content c
             JOIN cms_content_meta m ON m.content_id = c.id AND m.meta_key = '_parent_id'
             WHERE m.meta_value = :parent_id AND c.type = :type AND c.deleted_at IS NULL
             ORDER BY c.id ASC LIMIT 200"
        );
        $stmt->execute([':parent_id' => $entityId, ':type' => $childType]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['items' => []];
    }
    return ['items' => $rows, 'child_type' => $childType];
}

function cms_cap_entity_capability_media_gallery_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)(is_array($payload) ? ($payload['entity']['id'] ?? 0) : 0);
    $config   = is_array($payload) ? ($payload['config'] ?? []) : [];

    if (is_array($config) && array_key_exists('items', $config) && is_array($config['items'])) {
        return [
            'items'    => $config['items'],
            'columns'  => (int)($config['columns'] ?? 3),
            'lightbox' => !empty($config['lightbox']),
        ];
    }

    if ($entityId <= 0) return ['items' => []];
    try {
        $db   = cmsDb();
        $stmt = $db->prepare(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = :id AND meta_key = '_gallery' LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['items' => []];
    }
    $items = [];
    if ($row && $row['meta_value']) {
        $decoded = json_decode((string)$row['meta_value'], true);
        $items   = is_array($decoded) ? $decoded : [];
    }
    return [
        'items'    => $items,
        'columns'  => (int)($config['columns'] ?? 3),
        'lightbox' => !empty($config['lightbox']),
    ];
}

// ── Progress Write Helper ────────────────────────────────────────────────────

/**
 * Update progress for a user on an entity (writes to dedicated table).
 */
function cmsEntityProgressUpdate(int $entityId, int $userId, int $percent): void
{
    $percent = min(100, max(0, $percent));
    $db      = cmsDb();
    $stmt    = $db->prepare(
        "INSERT INTO cms_entity_progress (entity_id, user_id, percent)
         VALUES (:eid, :uid, :pct)
         ON DUPLICATE KEY UPDATE percent = :pct2, updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':eid'  => $entityId,
        ':uid'  => $userId,
        ':pct'  => $percent,
        ':pct2' => $percent,
    ]);

    cmsEntityCapabilityClearCache($entityId);
}

// ──────────────────────────────────────────────────────────────────────────────
// Entity List capability handlers — resolve {ikb_entity_list source="..." /}
// Called by EntityViewResolver via the capability bus.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generic entity list resolver for CMS content types.
 *
 * Payload keys from EntityViewResolver:
 *   entity_type, qualifier, view, limit, sort, filters, fields
 */
function cmsResolveEntityList(string $contentType, mixed $payload): array
{
    if (!\function_exists('module')) {
        return ['rows' => [], 'total' => 0, 'error' => 'Module context unavailable'];
    }
    $ctx = module('cms');
    if (!$ctx) {
        return ['rows' => [], 'total' => 0];
    }

    $limit = min((int)($payload['limit'] ?? 25), 100);
    $sortField = (string)($payload['sort']['field'] ?? 'created_at');
    $sortDir = strtoupper((string)($payload['sort']['direction'] ?? 'DESC'));
    if (!in_array($sortDir, ['ASC', 'DESC'], true)) { $sortDir = 'DESC'; }
    // Whitelist sort fields to prevent SQL injection
    $allowedSort = ['id', 'title', 'name', 'status', 'created_at', 'updated_at', 'published_at', 'price'];
    if (!in_array($sortField, $allowedSort, true)) { $sortField = 'created_at'; }

    $qualifier = (string)($payload['qualifier'] ?? '');
    $statusFilter = '';

    // Qualifier hints
    if ($qualifier === 'recent' || $qualifier === 'latest') {
        // No extra filter — sort by date descending handles this
    } elseif ($qualifier === 'published') {
        $statusFilter = " AND c.status = 'published'";
    } elseif ($qualifier === 'featured') {
        $statusFilter = " AND c.status = 'published'";
        // If featured meta exists, could add join on cms_content_meta
    }

    try {
        $db = $ctx->db();
        $query = "SELECT c.id, c.title, c.slug, c.status, c.excerpt, c.body, c.created_at, c.updated_at, c.published_at,
                         u.display_name as author_name
                  FROM cms_content c
                  LEFT JOIN cms_users u ON u.id = c.author_id
                  WHERE c.type = :type AND c.deleted_at IS NULL{$statusFilter}
                  ORDER BY c.{$sortField} {$sortDir}
                  LIMIT {$limit}";
        $stmt = $db->query($query, [':type' => $contentType]);
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Auto-generate excerpts from body when excerpt is empty
        if (\function_exists('cmsProcessPostExcerpts')) {
            $rows = cmsProcessPostExcerpts($rows);
        }

        // Count total
        $countStmt = $db->query("SELECT COUNT(*) FROM cms_content WHERE type = :type AND deleted_at IS NULL{$statusFilter}", [':type' => $contentType]);
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);

        // Hydrate image for card_grid views
        $view = (string)($payload['view'] ?? 'compact');
        if (in_array($view, ['card_grid', 'detailed'], true)) {
            foreach ($rows as &$row) {
                $imgStmt = $db->prepare("SELECT file_path FROM cms_media WHERE id = (SELECT featured_image_id FROM cms_content WHERE id = :id LIMIT 1) LIMIT 1");
                $imgStmt->execute([':id' => $row['id']]);
                $img = $imgStmt->fetchColumn();
                if ($img && is_string($img)) {
                    $row['image'] = $img;
                }
            }
            unset($row);
        }

        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) {
            \write_log("entity.list.{$contentType}: query failed", 'warning', ['error' => $e->getMessage()]);
        }
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function cms_cap_entity_list_page_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return cmsResolveEntityList('page', is_array($payload) ? $payload : []);
}

function cms_cap_entity_list_post_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return cmsResolveEntityList('post', is_array($payload) ? $payload : []);
}

/**
 * Entity get handler — resolves a single CMS content entity by ID.
 */
function cmsResolveEntityGet(string $contentType, mixed $payload): array
{
    if (!\function_exists('module')) {
        return [];
    }
    $ctx = module('cms');
    if (!$ctx) {
        return [];
    }

    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) {
        return [];
    }

    try {
        $db = $ctx->db();
        $stmt = $db->prepare(
            "SELECT c.*, u.display_name as author_name
             FROM cms_content c
             LEFT JOIN cms_users u ON u.id = c.author_id
             WHERE c.id = :id AND c.type = :type AND c.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':type' => $contentType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [];
        }

        // Hydrate featured image
        if (!empty($row['featured_image_id'])) {
            $imgStmt = $db->prepare("SELECT file_path FROM cms_media WHERE id = :id LIMIT 1");
            $imgStmt->execute([':id' => $row['featured_image_id']]);
            $img = $imgStmt->fetchColumn();
            if ($img && is_string($img)) {
                $row['image'] = $img;
            }
        }

        return $row;
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) {
            \write_log("entity.get.{$contentType}: query failed", 'warning', ['error' => $e->getMessage()]);
        }
        return [];
    }
}

function cms_cap_entity_get_post_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return cmsResolveEntityGet('post', is_array($payload) ? $payload : []);
}

function cms_cap_entity_get_page_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return cmsResolveEntityGet('page', is_array($payload) ? $payload : []);
}
