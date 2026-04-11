<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// WordPress Bridge Module — Helpers
// Idempotency guard, provenance tracking, and bridge utilities.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Get the bridge module's database connection (via CMS module context,
 * since bridge_ingestion_log is owned by this module but lives in the
 * same tenant database).
 */
function wpBridgeDb(): PDO|\Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('wordpress-bridge');
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

    // Look for any existing ingestion record for this source + external_id
    $stmt = $db->prepare(
        "SELECT external_modified, status FROM bridge_ingestion_log
         WHERE source = :source AND external_id = :eid
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':source' => $source, ':eid' => $externalId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        return 'process'; // First time seeing this item
    }

    $storedModified = (string)$existing['external_modified'];

    if ($storedModified === $externalModified) {
        return 'skip'; // Exact duplicate
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
            status = VALUES(status),
            cms_content_id = VALUES(cms_content_id),
            error_message = VALUES(error_message),
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
    $db = cmsDb();
    $meta = [
        'bridge_source'          => $source,
        'bridge_source_id'       => $externalId,
        'bridge_synced_at'       => date('Y-m-d\TH:i:s\Z'),
        'bridge_source_modified' => $externalModified,
        'bridge_status'          => $bridgeStatus,
    ];

    foreach ($meta as $key => $value) {
        $db->prepare(
            "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
             VALUES (:cid, :k, :v)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        )->execute([':cid' => $contentId, ':k' => $key, ':v' => $value]);
    }
}

/**
 * Read bridge provenance metadata for a content item.
 * Returns associative array of bridge_* meta keys, or empty array if none.
 */
function wpBridgeReadProvenance(int $contentId): array
{
    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT meta_key, meta_value FROM cms_content_meta
         WHERE content_id = :cid AND meta_key LIKE 'bridge_%'"
    );
    $stmt->execute([':cid' => $contentId]);

    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[$row['meta_key']] = $row['meta_value'];
    }
    return $meta;
}

/**
 * Find a CMS content item that was previously ingested from a given source + external ID.
 * Returns the cms_content row or null.
 */
function wpBridgeFindExistingByProvenance(string $source, string $externalId): ?array
{
    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT c.* FROM cms_content c
         INNER JOIN cms_content_meta m1 ON m1.content_id = c.id AND m1.meta_key = 'bridge_source' AND m1.meta_value = :source
         INNER JOIN cms_content_meta m2 ON m2.content_id = c.id AND m2.meta_key = 'bridge_source_id' AND m2.meta_value = :eid
         WHERE c.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':source' => $source, ':eid' => $externalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
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
// CMS Admin Nav Registration
//
// Register a "Bridge" nav item under CMS Admin when the bridge is enabled.
// The hook fires on every admin page load via cmsGetExtensionNavItems().
// ─────────────────────────────────────────────────────────────────────────

app()->hooks()->on('cms.admin.nav_items', static function (array $items): array {
    $settings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
    if (empty($settings['bridge_enabled'])) {
        return $items;
    }
    $baseUrl  = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $items[]  = [
        'label'      => 'Bridge',
        'url'        => $baseUrl . '/cms/admin/bridge',
        'icon'       => 'WP',
        'active_key' => 'wordpress_bridge',
    ];
    return $items;
});
