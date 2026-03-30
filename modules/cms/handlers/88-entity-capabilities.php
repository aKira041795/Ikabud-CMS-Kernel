<?php

declare(strict_types=1);

// ──────────────────────────────────────────────────────────────────────────────
// Entity Capability API Handlers — handlers/88-entity-capabilities.php
// ──────────────────────────────────────────────────────────────────────────────

function cmsEntityCapabilityJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

/**
 * GET /api/v1/cms/entity-capabilities
 * Returns all registered entity capability type definitions.
 */
function cmsApiEntityCapabilityTypes(): void
{
    $user = cmsRequireRole('contributor');

    $types = array_values(cmsEntityCapabilityTypes());

    cmsEntityCapabilityJsonResponse(['success' => true, 'capabilities' => $types]);
}

/**
 * GET /api/v1/cms/entity-presets
 * Returns all available entity preset definitions.
 */
function cmsApiEntityPresets(): void
{
    $user = cmsRequireRole('contributor');

    $presets = array_values(cmsEntityPresets());

    cmsEntityCapabilityJsonResponse(['success' => true, 'presets' => $presets]);
}

/**
 * GET /api/v1/cms/entity-contexts
 * Returns the registered entity context catalog and example schemas.
 */
function cmsApiEntityContextCatalog(): void
{
    $user = cmsRequireRole('contributor');

    cmsEntityCapabilityJsonResponse([
        'success' => true,
        'catalog' => cmsEntityContextRegistrySnapshot(),
        'examples' => cmsEntityContextExampleSchemas(),
    ]);
}

/**
 * GET /api/v1/cms/entity-contexts/schema/{entityType}
 * Returns the resolved entity context and derived customizer schema for an entity type.
 */
function cmsApiEntityContextSchema(array $params = []): void
{
    $user = cmsRequireRole('contributor');

    $entityType = trim((string)($params['entityType'] ?? ''));
    if ($entityType === '') {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'entityType is required'], 400);
        return;
    }

    $profile = [];
    $base = trim((string)($_GET['base'] ?? ''));
    if ($base !== '') {
        $profile['base'] = $base;
    }

    $rawExtensions = $_GET['extensions'] ?? [];
    $extensions = [];
    if (is_string($rawExtensions) && trim($rawExtensions) !== '') {
        $extensions = array_values(array_filter(array_map('trim', explode(',', $rawExtensions))));
    } elseif (is_array($rawExtensions)) {
        $extensions = array_values(array_filter(array_map(static fn($value): string => is_string($value) ? trim($value) : '', $rawExtensions)));
    }
    if ($extensions !== []) {
        $profile['extensions'] = $extensions;
    }

    $options = [];
    if ($profile !== []) {
        $options['profile'] = $profile;
    }

    $resolved = cmsResolveEntityContextForType($entityType, $options);
    cmsEntityCapabilityJsonResponse([
        'success' => true,
        'resolved_context' => $resolved,
        'schema' => $resolved['customizer_schema'] ?? [],
    ]);
}

/**
 * GET /api/v1/cms/content/{id}/capabilities
 * Returns the capabilities attached to a specific entity.
 */
function cmsApiEntityCapabilitiesGet(array $params = []): void
{
    $user     = cmsRequireRole('contributor');
    $entityId = (int)($params['id'] ?? 0);
    if ($entityId <= 0) {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $runtime  = cmsEntityCapabilityRuntimeState($entityId);
    $attached = cmsEntityGetCapabilities($entityId);
    $context  = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];

    cmsEntityCapabilityJsonResponse([
        'success' => true,
        'attached' => $attached,
        'context' => $context,
        'resolved_context' => is_array($runtime['resolved_context'] ?? null) ? $runtime['resolved_context'] : [],
    ]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities
 * Attach (or update) a capability on an entity.
 *
 * Body JSON: { "capability_id": "pricing", "config": { ... } }
 */
function cmsApiEntityCapabilityAttach(array $params = []): void
{
    $user     = cmsRequireRole('editor');
    $entityId = (int)($params['id'] ?? 0);
    if ($entityId <= 0) {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $body  = cmsInput();
    $capId = trim((string)($body['capability_id'] ?? ''));
    if ($capId === '') {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'capability_id is required'], 422);
        return;
    }

    $config = is_array($body['config'] ?? null) ? $body['config'] : [];

    try {
        cmsEntityAttachCapability($entityId, $capId, $config);
    } catch (\InvalidArgumentException $e) {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    cmsEntityCapabilityJsonResponse(['success' => true, 'attached' => cmsEntityGetCapabilities($entityId)]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach
 * Detach a capability from an entity.
 */
function cmsApiEntityCapabilityDetach(array $params = []): void
{
    $user     = cmsRequireRole('editor');
    $entityId = (int)($params['id'] ?? 0);
    $capId    = trim((string)(routeParam('cap_id') ?? ''));

    if ($entityId <= 0 || $capId === '') {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
        return;
    }

    cmsEntityDetachCapability($entityId, $capId);

    cmsEntityCapabilityJsonResponse(['success' => true, 'attached' => cmsEntityGetCapabilities($entityId)]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities/preset
 * Apply a preset to an entity (attaches all default capabilities for that preset).
 *
 * Body JSON: { "preset_id": "ecommerce" }
 */
function cmsApiEntityCapabilityPreset(array $params = []): void
{
    $user     = cmsRequireRole('editor');
    $entityId = (int)($params['id'] ?? 0);
    if ($entityId <= 0) {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $body     = cmsInput();
    $presetId = trim((string)($body['preset_id'] ?? ''));
    if ($presetId === '') {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => 'preset_id is required'], 422);
        return;
    }

    try {
        cmsApplyEntityPreset($entityId, $presetId);
    } catch (\InvalidArgumentException $e) {
        cmsEntityCapabilityJsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    cmsEntityCapabilityJsonResponse(['success' => true, 'preset_id' => $presetId, 'attached' => cmsEntityGetCapabilities($entityId)]);
}
