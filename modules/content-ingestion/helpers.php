<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Content Ingestion Module — Helpers
// Idempotency guard, provenance tracking, and bridge utilities.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Get the bridge module's database connection (via CMS module context,
 * since bridge_ingestion_log is owned by this module but lives in the
 * same tenant database).
 */
function wpBridgeDb(): PDO|\Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('content-ingestion');
    if ($ctx) {
        return $ctx->db();
    }
    // Fallback to CMS db if module context not available during bootstrap
    return cmsDb();
}

// ── Idempotency ─────────────────────────────────────────────────────────

/**
 * Check idempotency for an ingestion event.
 *
 * Returns:
 *   'process'  — this event is new or newer than stored; proceed with ingestion
 *   'skip'     — already processed with this exact (source, external_id, external_modified)
 *   'stale'    — event is older than what we already processed (out-of-order delivery)
 */
function wpBridgeIdempotencyCheck(string $source, string $externalId, string $externalModified): string
{
    $db = wpBridgeDb();

        // Only a successfully processed entry counts as the authoritative version.
        // Duplicate/stale/failed log rows must not mask the last successful sync.
    $stmt = $db->prepare(
        "SELECT external_modified, status FROM bridge_ingestion_log
            WHERE source = :source AND external_id = :eid AND status = 'processed'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':source' => $source, ':eid' => $externalId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        return 'process'; // First time seeing this item
    }

    // If the last attempt failed, allow retry regardless of timestamp
    if ((string)($existing['status'] ?? '') !== 'processed') {
        return 'process';
    }

    $storedModified = (string)$existing['external_modified'];

    if ($storedModified === $externalModified) {
        return 'skip'; // Exact duplicate of a successful ingest
    }

    // Compare timestamps — newer means process, older means stale
    $storedTime = strtotime($storedModified);
    $incomingTime = strtotime($externalModified);

    if ($storedTime === false || $incomingTime === false) {
        // Can't parse timestamps — treat as new to be safe
        return 'process';
    }

    if ($incomingTime > $storedTime) {
        return 'process'; // Newer version
    }

    return 'stale'; // Older than what we have
}

/**
 * Record an ingestion event in the log.
 */
function wpBridgeLogIngestion(
    string $source,
    string $externalId,
    string $externalModified,
    string $eventName,
    string $status,
    ?int $cmsContentId = null,
    ?array $payload = null,
    ?string $errorMessage = null
): void {
    $db = wpBridgeDb();
    $requestId = function_exists('request_id') ? request_id() : null;

    // Use INSERT ... ON DUPLICATE KEY UPDATE so re-processing a newer version
    // updates the existing row for this (source, external_id, external_modified)
    $db->prepare(
        "INSERT INTO bridge_ingestion_log
            (source, external_id, external_modified, event_name, status, cms_content_id, payload_json, error_message, request_id, created_at)
         VALUES
            (:source, :eid, :emod, :event, :status, :cid, :payload, :err, :rid, NOW())
         ON DUPLICATE KEY UPDATE
            status = CASE
                WHEN bridge_ingestion_log.status = 'processed' THEN bridge_ingestion_log.status
                ELSE VALUES(status)
            END,
            cms_content_id = COALESCE(bridge_ingestion_log.cms_content_id, VALUES(cms_content_id)),
            error_message = CASE
                WHEN bridge_ingestion_log.status = 'processed' THEN bridge_ingestion_log.error_message
                ELSE VALUES(error_message)
            END,
            request_id = VALUES(request_id)"
    )->execute([
        ':source'  => $source,
        ':eid'     => $externalId,
        ':emod'    => $externalModified,
        ':event'   => $eventName,
        ':status'  => $status,
        ':cid'     => $cmsContentId,
        ':payload' => $payload !== null ? json_encode($payload) : null,
        ':err'     => $errorMessage,
        ':rid'     => $requestId,
    ]);
}

// ── Provenance ──────────────────────────────────────────────────────────

/**
 * Write bridge provenance metadata to cms_content_meta for a content item.
 */
function wpBridgeWriteProvenance(int $contentId, string $source, string $externalId, string $externalModified, string $bridgeStatus = 'external-managed'): void
{
    $db = wpBridgeDb();
    $db->prepare(
        'INSERT INTO bridge_content_map (source, external_id, cms_content_id, bridge_status, synced_at, source_modified)
         VALUES (:src, :eid, :cid, :status, NOW(), :smod)
         ON DUPLICATE KEY UPDATE
             cms_content_id  = VALUES(cms_content_id),
             bridge_status   = VALUES(bridge_status),
             synced_at       = NOW(),
             source_modified = VALUES(source_modified),
             updated_at      = NOW()'
    )->execute([
        ':src'    => $source,
        ':eid'    => $externalId,
        ':cid'    => $contentId,
        ':status' => $bridgeStatus,
        ':smod'   => $externalModified,
    ]);
}

/**
 * Read bridge provenance metadata for a content item.
 * Returns associative array of bridge_* keys, or empty array if none.
 */
function wpBridgeReadProvenance(int $contentId): array
{
    $db = wpBridgeDb();
    $stmt = $db->prepare(
        'SELECT source, external_id, source_modified, bridge_status, synced_at
         FROM bridge_content_map
         WHERE cms_content_id = :cid
         ORDER BY updated_at DESC LIMIT 1'
    );
    $stmt->execute([':cid' => $contentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [];
    }
    // Normalize to the bridge_* key names used in the rest of the codebase
    return [
        'bridge_source'          => $row['source'],
        'bridge_source_id'       => $row['external_id'],
        'bridge_source_modified' => $row['source_modified'],
        'bridge_status'          => $row['bridge_status'],
        'bridge_synced_at'       => $row['synced_at'],
    ];
}

/**
 * Find a CMS content item that was previously ingested from a given source + external ID.
 * Returns the cms_content row or null.
 */
function wpBridgeFindExistingByProvenance(string $source, string $externalId): ?array
{
    // Look up in bridge_content_map (owned by content-ingestion)
    $bridgeDb = wpBridgeDb();
    $stmt = $bridgeDb->prepare(
        'SELECT cms_content_id FROM bridge_content_map
         WHERE source = :src AND external_id = :eid
         LIMIT 1'
    );
    $stmt->execute([':src' => $source, ':eid' => $externalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $cmsContentId = (int)$row['cms_content_id'];

    // Fetch the CMS content row (read-only access declared in reads_tables)
    $cmsDb = wpBridgeDb();
    $stmt2 = $cmsDb->prepare(
        'SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1'
    );
    $stmt2->execute([':id' => $cmsContentId]);
    $content = $stmt2->fetch(PDO::FETCH_ASSOC);
    return is_array($content) ? $content : null;
}

// ── Conflict Detection ──────────────────────────────────────────────────

/**
 * Check if a content item has been modified in the CMS since the last bridge sync.
 * Returns true if the CMS side was edited after the last sync (conflict detected).
 */
function wpBridgeHasConflict(array $existing, array $provenance): bool
{
    $syncedAt = $provenance['bridge_synced_at'] ?? null;
    if ($syncedAt === null) {
        return false; // No previous sync — can't conflict
    }

    $cmsUpdatedAt = $existing['updated_at'] ?? null;
    if ($cmsUpdatedAt === null) {
        return false;
    }

    $syncTime = strtotime($syncedAt);
    $cmsTime = strtotime($cmsUpdatedAt);

    if ($syncTime === false || $cmsTime === false) {
        return false;
    }

    // CMS was modified after our last sync → conflict
    return $cmsTime > $syncTime;
}

// ─────────────────────────────────────────────────────────────────────────
// WordPress Utility Shims
//
// These are local copies of wordpress-importer utility functions needed
// by the ingestion pipeline. Guarded against redeclaration in case both
// modules are loaded in the same request.
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('wordpressImporterNormalizeStatus')) {
    function wordpressImporterNormalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'publish', 'published' => 'published',
            'future', 'scheduled'  => 'scheduled',
            'private'              => 'private',
            default                => 'draft',
        };
    }
}

if (!function_exists('wordpressImporterNormalizeDate')) {
    function wordpressImporterNormalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('wordpressImporterResolveAuthorId')) {
    function wordpressImporterResolveAuthorId(int $preferredAuthorId): int
    {
        $db = cmsDb();
        if ($preferredAuthorId > 0) {
            $stmt = $db->prepare('SELECT id FROM cms_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $preferredAuthorId]);
            $matched = $stmt->fetchColumn();
            if ($matched) {
                return (int)$matched;
            }
        }
        $fallback = $db->query(
            "SELECT id FROM cms_users ORDER BY CASE WHEN role IN ('superadmin','administrator') THEN 0 ELSE 1 END, id ASC LIMIT 1"
        )->fetchColumn();
        if ($fallback) {
            return (int)$fallback;
        }
        throw new \InvalidArgumentException('Ingestion failed: no CMS author account is available');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// CMS Admin Nav Registration
//
// Register a "Bridge" nav item under CMS Admin when the bridge is enabled.
// The hook fires on every admin page load via cmsGetExtensionNavItems().
// ─────────────────────────────────────────────────────────────────────────

app()->hooks()->on('cms.admin.nav_items', static function (array $items): array {
    if (function_exists('moduleIsActive') && !moduleIsActive('content-ingestion')) {
        return $items;
    }

    $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $enabled  = !empty($settings['bridge_enabled']);
    $baseUrl  = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    // Settings always appears so the user can reach it to enable the bridge.
    // Dashboard only appears once bridge_enabled is true.
    $children = [];

    if ($enabled) {
        $children[] = [
            'label'      => 'Dashboard',
            'url'        => $baseUrl . '/cms/admin/bridge',
            'icon'       => '📊',
            'active_key' => 'wordpress_bridge',
        ];
    }

    $children[] = [
        'label'      => 'Settings',
        'url'        => $baseUrl . '/cms/admin/bridge/settings',
        'icon'       => '⚙️',
        'active_key' => 'wordpress_bridge_settings',
    ];

    $items[] = [
        'label'    => 'Content Ingestion',
        'section'  => true,
        'children' => $children,
    ];

    return $items;
});

// ── Capability Handlers ─────────────────────────────────────────────────

function content_ingestion_capability_handlers(): array
{
    return [
        'content_ingestion.import_content@1' => 'content_ingestion_cap_import_content_1',
    ];
}

/**
 * Capability handler: content_ingestion.import_content@1
 *
 * Entry point for the content ingestion pipeline. Accepts a normalized
 * content payload and dispatches it through the kernel event bus for
 * idempotent processing. The bridge must be enabled for this tenant.
 *
 * Expected payload keys:
 *   - event: string (e.g. 'cms.migration.content.upserted')
 *   - source: string (e.g. 'wordpress')
 *   - external_id: string
 *   - external_modified: string (ISO 8601 or MySQL datetime)
 *   - payload: array (normalized content fields)
 *
 * @return array{ok: bool, status?: string, cms_content_id?: int, error?: string}
 */
function content_ingestion_cap_import_content_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $input = is_array($payload) ? $payload : [];

    // Gate: bridge must be enabled
    $bridgeSettings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    if (empty($bridgeSettings['bridge_enabled'])) {
        return ['ok' => false, 'error' => 'Content Ingestion is not enabled for this tenant.'];
    }

    // Validate required fields
    $event = trim((string)($input['event'] ?? ''));
    $source = trim((string)($input['source'] ?? ''));
    $externalId = trim((string)($input['external_id'] ?? ''));
    $externalModified = trim((string)($input['external_modified'] ?? ''));
    $contentPayload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

    $errors = [];
    if ($event === '') $errors[] = 'event is required';
    if ($source === '') $errors[] = 'source is required';
    if ($externalId === '') $errors[] = 'external_id is required';
    if ($externalModified === '') $errors[] = 'external_modified is required';
    if (empty($contentPayload)) $errors[] = 'payload is required and must be an object';
    if (!empty($errors)) {
        return ['ok' => false, 'error' => implode('; ', $errors)];
    }

    // Only accept known bridge events
    $allowedEvents = ['cms.migration.content.upserted'];
    if (!in_array($event, $allowedEvents, true)) {
        return ['ok' => false, 'error' => "Unknown event: {$event}"];
    }

    // Idempotency check
    if (function_exists('wpBridgeIdempotencyCheck')) {
        $idempotency = wpBridgeIdempotencyCheck($source, $externalId, $externalModified);
        if ($idempotency === 'skip') {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'Already processed with same external_modified'];
        }
        if ($idempotency === 'stale') {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'Event is older than last successfully processed version'];
        }
    }

    // Dispatch the ingestion event through the kernel event bus
    try {
        if (function_exists('app') && method_exists(app(), 'events')) {
            app()->events()->emit($event, array_merge($contentPayload, [
                'source' => $source,
                'external_id' => $externalId,
                'external_modified' => $externalModified,
            ]));
        }

        // Log the ingestion attempt
        if (function_exists('wpBridgeLogIngestion')) {
            wpBridgeLogIngestion($source, $externalId, $externalModified, 'queued', $contentPayload);
        }

        return ['ok' => true, 'status' => 'queued'];
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('content-ingestion cap: import failed: ' . $e->getMessage(), 'error');
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
