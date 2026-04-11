<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// WordPress Bridge — Lifecycle Handlers
//
// Per-item claim/resolve transitions  +  bridge-state helpers
//
//   PATCH /api/v1/bridge/state                      → wpBridgeApiSetState
//   PATCH /api/v1/bridge/content/{id}/claim         → wpBridgeApiContentClaim
//   PATCH /api/v1/bridge/content/{id}/resolve       → wpBridgeApiContentResolve
//
// Shared helpers:
//   wpBridgeGetState()  → string            current bridge state
//   wpBridgeIsActive()  → bool              true only when state === 'active'
//   wpBridgeGetStats()  → array             counts by bridge_status + totals
// ─────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────
// State helpers (also called by other handlers and the ingestion gate)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns the current per-tenant bridge state.
 * Defaults to 'active' when unset (safe for first-time installs).
 */
function wpBridgeGetState(): string
{
    $settings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
    $state    = $settings['bridge_state'] ?? 'active';

    $allowed = ['active', 'read-only', 'archived', 'disabled'];
    return in_array($state, $allowed, true) ? $state : 'active';
}

/**
 * Returns true only when the bridge is in the 'active' state.
 * Use this to gate any write operation that requires an active bridge.
 */
function wpBridgeIsActive(): bool
{
    return wpBridgeGetState() === 'active';
}

/**
 * Returns ingestion + bridge-status statistics for the current tenant.
 *
 * @return array{
 *   total: int,
 *   external-managed: int,
 *   review-required: int,
 *   cms-managed: int,
 *   retired: int,
 *   media_fetched: int,
 *   ingestion_failed: int,
 * }
 */
function wpBridgeGetStats(): array
{
    $db = wpBridgeDb();

    // Counts per bridge_status from cms_content_meta
    $statusStmt = $db->prepare(
        "SELECT meta_value AS bridge_status, COUNT(*) AS cnt
         FROM cms_content_meta
         WHERE meta_key = 'bridge_status'
         GROUP BY meta_value"
    );
    $statusStmt->execute();
    $byStatus = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byStatus[(string)$row['bridge_status']] = (int)$row['cnt'];
    }

    // Total bridged items (have a bridge_source meta)
    $totalStmt = $db->prepare(
        "SELECT COUNT(*) FROM cms_content_meta WHERE meta_key = 'bridge_source'"
    );
    $totalStmt->execute();
    $total = (int)$totalStmt->fetchColumn();

    // Media log counts
    $mediaStmt = $db->prepare(
        "SELECT COUNT(*) FROM bridge_media_log WHERE status = 'saved'"
    );
    $mediaStmt->execute();
    $mediaFetched = (int)$mediaStmt->fetchColumn();

    // Ingestion failures
    $failStmt = $db->prepare(
        "SELECT COUNT(*) FROM bridge_ingestion_log WHERE status = 'error'"
    );
    $failStmt->execute();
    $failed = (int)$failStmt->fetchColumn();

    return [
        'total'            => $total,
        'external-managed' => (int)($byStatus['external-managed'] ?? 0),
        'review-required'  => (int)($byStatus['review-required'] ?? 0),
        'cms-managed'      => (int)($byStatus['cms-managed'] ?? 0),
        'retired'          => (int)($byStatus['retired'] ?? 0),
        'media_fetched'    => $mediaFetched,
        'ingestion_failed' => $failed,
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// PATCH /api/v1/bridge/state
// ─────────────────────────────────────────────────────────────────────────

/**
 * Sets the bridge state for the current tenant.
 *
 * Body (JSON or form): { "state": "active"|"read-only"|"archived"|"disabled" }
 *
 * Transitions are one-directional safety checks:
 *   active  → read-only  → archived → disabled
 * We allow any transition for now but emit an event so listeners can react.
 */
function wpBridgeApiSetState(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');
    app()->csrfEnforce();

    $body     = (string)file_get_contents('php://input');
    $payload  = json_decode($body, true);
    $newState = trim((string)($payload['state'] ?? $_POST['state'] ?? ''));

    $allowed = ['active', 'read-only', 'archived', 'disabled'];
    if (!in_array($newState, $allowed, true)) {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => "Invalid state. Allowed values: " . implode(', ', $allowed),
        ]);
        exit;
    }

    $prevState = wpBridgeGetState();
    if ($prevState === $newState) {
        echo json_encode(['ok' => true, 'state' => $newState, 'changed' => false]);
        exit;
    }

    saveModuleSettings('wordpress-bridge', ['bridge_state' => $newState]);

    app()->events()->emit('cms.migration.bridge.state_changed', [
        'prev_state' => $prevState,
        'new_state'  => $newState,
        'changed_by' => (int)(cmsCtxUser()['id'] ?? 0),
    ]);

    write_log(
        "Bridge state changed from '{$prevState}' to '{$newState}'",
        'info',
        ['source' => 'wordpress-bridge']
    );

    echo json_encode(['ok' => true, 'state' => $newState, 'changed' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// PATCH /api/v1/bridge/content/{id}/claim
// ─────────────────────────────────────────────────────────────────────────

/**
 * Claims a bridge-managed item as CMS-owned.
 *
 * Valid from: external-managed | review-required
 * Transitions to: cms-managed
 *
 * Once claimed, the bridge will no longer overwrite this item on ingestion
 * (because wpBridgeHandleContentUpserted skips cms-managed items by default).
 */
function wpBridgeApiContentClaim(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');
    app()->csrfEnforce();

    $contentId = (int)($params['id'] ?? 0);
    if ($contentId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid content id']);
        exit;
    }

    $db = cmsDb();

    // Verify this content exists and is bridge-managed
    $srcStmt = $db->prepare(
        "SELECT meta_value FROM cms_content_meta
         WHERE content_id = :id AND meta_key = 'bridge_source' LIMIT 1"
    );
    $srcStmt->execute([':id' => $contentId]);
    if (!$srcStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Content item not found or not bridge-managed']);
        exit;
    }

    // Read current bridge_status
    $bstStmt = $db->prepare(
        "SELECT meta_value FROM cms_content_meta
         WHERE content_id = :id AND meta_key = 'bridge_status' LIMIT 1"
    );
    $bstStmt->execute([':id' => $contentId]);
    $currentStatus = (string)($bstStmt->fetchColumn() ?: 'external-managed');

    $claimable = ['external-managed', 'review-required'];
    if (!in_array($currentStatus, $claimable, true)) {
        http_response_code(409);
        echo json_encode([
            'ok'             => false,
            'error'          => "Cannot claim item with bridge_status '{$currentStatus}'",
            'bridge_status'  => $currentStatus,
        ]);
        exit;
    }

    // Re-read the full provenance so we can preserve external_id + external_modified
    $provenance     = wpBridgeReadProvenance($contentId);
    $bridgeSource   = (string)($provenance['bridge_source']          ?? 'wordpress');
    $bridgeSrcId    = (string)($provenance['bridge_source_id']       ?? (string)$contentId);
    $bridgeSrcMod   = (string)($provenance['bridge_source_modified'] ?? date('Y-m-d\TH:i:s\Z'));

    // Write new provenance: cms-managed
    wpBridgeWriteProvenance($contentId, $bridgeSource, $bridgeSrcId, $bridgeSrcMod, 'cms-managed');

    write_log(
        "Bridge content #{$contentId} claimed ('{$currentStatus}' → 'cms-managed')",
        'info',
        ['source' => 'wordpress-bridge']
    );

    echo json_encode([
        'ok'            => true,
        'content_id'    => $contentId,
        'prev_status'   => $currentStatus,
        'bridge_status' => 'cms-managed',
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// PATCH /api/v1/bridge/content/{id}/resolve
// ─────────────────────────────────────────────────────────────────────────

/**
 * Resolves a review-required conflict for a bridge-managed item.
 *
 * Body (JSON): { "resolution": "wp" | "cms" }
 *
 *   resolution=wp   → Keep WP version as current body, reset to external-managed
 *                     so the bridge can continue syncing this item.
 *   resolution=cms  → Keep current CMS body, transition to cms-managed
 *                     so the bridge will no longer overwrite this item.
 *
 * Only items with bridge_status = review-required may be resolved.
 */
function wpBridgeApiContentResolve(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');
    app()->csrfEnforce();

    $contentId = (int)($params['id'] ?? 0);
    if ($contentId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid content id']);
        exit;
    }

    $body       = (string)file_get_contents('php://input');
    $payload    = json_decode($body, true);
    $resolution = trim((string)($payload['resolution'] ?? $_POST['resolution'] ?? ''));

    if (!in_array($resolution, ['wp', 'cms'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => "Invalid resolution. Must be 'wp' or 'cms'"]);
        exit;
    }

    $db = cmsDb();

    // Verify bridge provenance
    $srcStmt = $db->prepare(
        "SELECT meta_value FROM cms_content_meta
         WHERE content_id = :id AND meta_key = 'bridge_source' LIMIT 1"
    );
    $srcStmt->execute([':id' => $contentId]);
    if (!$srcStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Content item not found or not bridge-managed']);
        exit;
    }

    // Verify current status is review-required
    $bstStmt = $db->prepare(
        "SELECT meta_value FROM cms_content_meta
         WHERE content_id = :id AND meta_key = 'bridge_status' LIMIT 1"
    );
    $bstStmt->execute([':id' => $contentId]);
    $currentStatus = (string)($bstStmt->fetchColumn() ?: '');

    if ($currentStatus !== 'review-required') {
        http_response_code(409);
        echo json_encode([
            'ok'            => false,
            'error'         => "Only 'review-required' items can be resolved. Current status: '{$currentStatus}'",
            'bridge_status' => $currentStatus,
        ]);
        exit;
    }

    // Re-read full provenance so we can preserve external_id + external_modified
    $provenance     = wpBridgeReadProvenance($contentId);
    $bridgeSource   = (string)($provenance['bridge_source']          ?? 'wordpress');
    $bridgeSrcId    = (string)($provenance['bridge_source_id']       ?? (string)$contentId);
    $bridgeSrcMod   = (string)($provenance['bridge_source_modified'] ?? date('Y-m-d\\TH:i:s\\Z'));

    if ($resolution === 'wp') {
        // Keep WP version: restore body from bridge_conflict_wp_body if available
        $wpBodyStmt = $db->prepare(
            "SELECT meta_value FROM cms_content_meta
             WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body' LIMIT 1"
        );
        $wpBodyStmt->execute([':id' => $contentId]);
        $wpBody = $wpBodyStmt->fetchColumn();

        if ($wpBody !== false && $wpBody !== '') {
            $db->prepare("UPDATE cms_content SET body = :body WHERE id = :id")
               ->execute([':body' => $wpBody, ':id' => $contentId]);
        }

        wpBridgeWriteProvenance($contentId, $bridgeSource, $bridgeSrcId, $bridgeSrcMod, 'external-managed');

        // Remove the conflict snapshot
        $db->prepare(
            "DELETE FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'"
        )->execute([':id' => $contentId]);

        $newStatus = 'external-managed';

    } else {
        // Keep CMS version: transition to cms-managed so bridge stops overwriting
        wpBridgeWriteProvenance($contentId, $bridgeSource, $bridgeSrcId, $bridgeSrcMod, 'cms-managed');

        // Remove the conflict snapshot if present
        $db->prepare(
            "DELETE FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'"
        )->execute([':id' => $contentId]);

        $newStatus = 'cms-managed';
    }

    write_log(
        "Bridge content #{$contentId} conflict resolved via '{$resolution}': '{$currentStatus}' → '{$newStatus}'",
        'info',
        ['source' => 'wordpress-bridge']
    );

    echo json_encode([
        'ok'            => true,
        'content_id'    => $contentId,
        'resolution'    => $resolution,
        'prev_status'   => $currentStatus,
        'bridge_status' => $newStatus,
    ]);
    exit;
}
