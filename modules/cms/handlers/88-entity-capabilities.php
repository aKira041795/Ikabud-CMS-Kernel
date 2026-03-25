<?php

declare(strict_types=1);

// ──────────────────────────────────────────────────────────────────────────────
// Entity Capability API Handlers — handlers/88-entity-capabilities.php
// ──────────────────────────────────────────────────────────────────────────────

/**
 * GET /api/v1/cms/entity-capabilities
 * Returns all registered entity capability type definitions.
 */
function cmsApiEntityCapabilityTypes(): void
{
    $user = cmsRequireRole(['administrator', 'editor', 'author', 'contributor', 'superadmin']);

    $types = array_values(cmsEntityCapabilityTypes());

    jsonResponse(['success' => true, 'capabilities' => $types]);
}

/**
 * GET /api/v1/cms/entity-presets
 * Returns all available entity preset definitions.
 */
function cmsApiEntityPresets(): void
{
    $user = cmsRequireRole(['administrator', 'editor', 'author', 'contributor', 'superadmin']);

    $presets = array_values(cmsEntityPresets());

    jsonResponse(['success' => true, 'presets' => $presets]);
}

/**
 * GET /api/v1/cms/content/{id}/capabilities
 * Returns the capabilities attached to a specific entity.
 */
function cmsApiEntityCapabilitiesGet(): void
{
    $user     = cmsRequireRole(['administrator', 'editor', 'author', 'contributor', 'superadmin']);
    $entityId = (int)(routeParam('id') ?? 0);
    if ($entityId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $attached = cmsEntityGetCapabilities($entityId);
    $context  = cmsEntityCapabilityContext($entityId);

    jsonResponse(['success' => true, 'attached' => $attached, 'context' => $context]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities
 * Attach (or update) a capability on an entity.
 *
 * Body JSON: { "capability_id": "pricing", "config": { ... } }
 */
function cmsApiEntityCapabilityAttach(): void
{
    $user     = cmsRequireRole(['administrator', 'editor', 'superadmin']);
    $entityId = (int)(routeParam('id') ?? 0);
    if ($entityId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $body  = jsonBody();
    $capId = trim((string)($body['capability_id'] ?? ''));
    if ($capId === '') {
        jsonResponse(['success' => false, 'error' => 'capability_id is required'], 422);
        return;
    }

    $config = is_array($body['config'] ?? null) ? $body['config'] : [];

    try {
        cmsEntityAttachCapability($entityId, $capId, $config);
    } catch (\InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    jsonResponse(['success' => true, 'attached' => cmsEntityGetCapabilities($entityId)]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach
 * Detach a capability from an entity.
 */
function cmsApiEntityCapabilityDetach(): void
{
    $user     = cmsRequireRole(['administrator', 'editor', 'superadmin']);
    $entityId = (int)(routeParam('id') ?? 0);
    $capId    = trim((string)(routeParam('cap_id') ?? ''));

    if ($entityId <= 0 || $capId === '') {
        jsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
        return;
    }

    cmsEntityDetachCapability($entityId, $capId);

    jsonResponse(['success' => true, 'attached' => cmsEntityGetCapabilities($entityId)]);
}

/**
 * POST /api/v1/cms/content/{id}/capabilities/preset
 * Apply a preset to an entity (attaches all default capabilities for that preset).
 *
 * Body JSON: { "preset_id": "ecommerce" }
 */
function cmsApiEntityCapabilityPreset(): void
{
    $user     = cmsRequireRole(['administrator', 'editor', 'superadmin']);
    $entityId = (int)(routeParam('id') ?? 0);
    if ($entityId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid entity id'], 400);
        return;
    }

    $body     = jsonBody();
    $presetId = trim((string)($body['preset_id'] ?? ''));
    if ($presetId === '') {
        jsonResponse(['success' => false, 'error' => 'preset_id is required'], 422);
        return;
    }

    try {
        cmsApplyEntityPreset($entityId, $presetId);
    } catch (\InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        return;
    }

    jsonResponse(['success' => true, 'preset_id' => $presetId, 'attached' => cmsEntityGetCapabilities($entityId)]);
}
