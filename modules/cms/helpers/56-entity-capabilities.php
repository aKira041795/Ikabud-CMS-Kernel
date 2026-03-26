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
}

function cmsEntityCapabilityContext(int $entityId): array
{
    $attached = cmsEntityGetCapabilities($entityId);
    $allTypes = cmsEntityCapabilityTypes();

    $map = [];
    foreach ($allTypes as $capId => $_def) {
        $map[$capId] = isset($attached[$capId]);
    }
    return $map;
}

function cmsEntityCapabilityData(int $entityId, array $entity): array
{
    $attached = cmsEntityGetCapabilities($entityId);
    if (empty($attached)) {
        return [];
    }

    $data     = [];
    $registry = app()->capabilities();
    $bus      = app()->cap();

    foreach ($attached as $capId => $config) {
        $providerKey = "entity.capability.{$capId}.data@1";
        if (!$registry->has($providerKey)) {
            $data[$capId] = [];
            continue;
        }
        try {
            $result = $bus->call($providerKey, [
                'entity'    => $entity,
                'config'    => $config,
                'entity_id' => $entityId,
            ]);
            $data[$capId] = is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            write_log('warn', 'cms.entity_capability_data.error', [
                'entity_id' => $entityId,
                'cap_id'    => $capId,
                'error'     => $e->getMessage(),
            ]);
            $data[$capId] = [];
        }
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

            return [
                'sku' => $config['sku'] ?? null,
                'stock' => $stockQty,
                'stock_qty' => $stockQty,
                'track_inventory' => $trackStock,
                'track_stock' => $trackStock,
                'in_stock' => !$trackStock || $stockQty > 0,
                'out_of_stock' => $trackStock && $stockQty <= 0,
                'low_stock' => $trackStock && $stockQty > 0 && $stockQty <= 5,
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

    return [
        'sku' => $rows['_sku'] ?? null,
        'stock' => $stockQty,
        'stock_qty' => $stockQty,
        'track_inventory' => $trackInventory,
        'track_stock' => $trackInventory,
        'in_stock' => !isset($rows['_stock_qty']) || (int)$rows['_stock_qty'] > 0,
        'out_of_stock' => $trackInventory && isset($rows['_stock_qty']) && (int)$rows['_stock_qty'] <= 0,
        'low_stock' => $trackInventory && isset($rows['_stock_qty']) && (int)$rows['_stock_qty'] > 0 && (int)$rows['_stock_qty'] <= 5,
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
}
