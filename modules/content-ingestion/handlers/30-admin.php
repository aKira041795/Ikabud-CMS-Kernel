<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Content Ingestion — Admin UI Handlers
//
// All admin-facing routes for the bridge:
//   GET  /cms/admin/bridge              → wpBridgeAdminDashboard  (UI page)
//   GET  /api/v1/bridge/status          → wpBridgeApiStatus        (stats JSON)
//   GET  /api/v1/bridge/content         → wpBridgeApiContentList   (bridge-managed items)
//   POST /api/v1/bridge/import/wxr      → wpBridgeApiImportWxr     (WXR upload trigger)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /cms/admin/bridge
 *
 * Renders the bridge management dashboard in the CMS admin.
 * Requires import_export.manage capability (administrator+).
 */
function wpBridgeAdminDashboard(array $params = []): void
{
    $user = cmsRequireCap('import_export.manage');

    $bridgeState = wpBridgeGetState();
    $stats       = wpBridgeGetStats();

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    echo cmsRender('modules/content-ingestion/admin/bridge-dashboard.disyl', array_merge(
        cmsAdminContext($user, 'wordpress_bridge', [
            ['label' => 'Content Ingestion', 'url' => ''],
        ]),
        [
            'page_title'           => 'Content Ingestion',
            'bridge_state'         => $bridgeState,
            'bridge_enabled'       => $bridgeState === 'active',
            'bridge_readonly'      => $bridgeState === 'read-only',
            'bridge_archived'      => in_array($bridgeState, ['archived', 'disabled'], true),
            'stats_total'          => (int)($stats['total'] ?? 0),
            'stats_external'       => (int)($stats['external-managed'] ?? 0),
            'stats_review'         => (int)($stats['review-required'] ?? 0),
            'stats_cms_managed'    => (int)($stats['cms-managed'] ?? 0),
            'stats_retired'        => (int)($stats['retired'] ?? 0),
            'stats_media_fetched'  => (int)($stats['media_fetched'] ?? 0),
            'stats_failed'         => (int)($stats['ingestion_failed'] ?? 0),
            'api_base'             => $baseUrl . '/api/v1',
        ]
    ));
}

// ─────────────────────────────────────────────────────────────────────────
// GET /api/v1/bridge/status
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns bridge status: current state, per-status item counts, recent activity.
 */
function wpBridgeApiStatus(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');

    $bridgeState = wpBridgeGetState();
    $stats       = wpBridgeGetStats();

    $db = wpBridgeDb();

    // Recent ingestion activity (last 20 events)
    $recentStmt = $db->prepare(
        "SELECT source, external_id, external_modified, status, cms_content_id, error_message, created_at
         FROM bridge_ingestion_log
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $recentStmt->execute();
    $recentActivity = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Recent media log (last 10)
    $mediaStmt = $db->prepare(
        "SELECT source, external_url, status, local_url, error_message, created_at
         FROM bridge_media_log
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $mediaStmt->execute();
    $recentMedia = $mediaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'              => true,
        'bridge_state'    => $bridgeState,
        'stats'           => $stats,
        'recent_activity' => $recentActivity,
        'recent_media'    => $recentMedia,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET /api/v1/bridge/content
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns paginated list of CMS content items that have bridge provenance.
 *
 * Query params:
 *   bridge_status  = external-managed|review-required|cms-managed|retired (default: all)
 *   page           = 1-based page number (default: 1)
 *   per_page       = items per page (default: 20, max: 100)
 */
function wpBridgeApiContentList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');

    $bridgeStatus = trim((string)($_GET['bridge_status'] ?? ''));
    $page         = max(1, (int)($_GET['page'] ?? 1));
    $perPage      = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset       = ($page - 1) * $perPage;

    $allowedStatuses = ['external-managed', 'review-required', 'cms-managed', 'retired'];

    $db = cmsDb();

    $sql = "SELECT c.id, c.title, c.slug, c.type, c.status, c.updated_at,
                   m_src.meta_value   AS bridge_source,
                   m_sid.meta_value   AS bridge_source_id,
                   m_bst.meta_value   AS bridge_status,
                   m_syn.meta_value   AS bridge_synced_at,
                   m_mod.meta_value   AS bridge_source_modified
            FROM cms_content c
            INNER JOIN cms_content_meta m_src ON m_src.content_id = c.id AND m_src.meta_key = 'bridge_source'
            INNER JOIN cms_content_meta m_sid ON m_sid.content_id = c.id AND m_sid.meta_key = 'bridge_source_id'
            LEFT  JOIN cms_content_meta m_bst ON m_bst.content_id = c.id AND m_bst.meta_key = 'bridge_status'
            LEFT  JOIN cms_content_meta m_syn ON m_syn.content_id = c.id AND m_syn.meta_key = 'bridge_synced_at'
            LEFT  JOIN cms_content_meta m_mod ON m_mod.content_id = c.id AND m_mod.meta_key = 'bridge_source_modified'
            WHERE c.deleted_at IS NULL";

    $countSql = "SELECT COUNT(DISTINCT c.id)
                 FROM cms_content c
                 INNER JOIN cms_content_meta m_src ON m_src.content_id = c.id AND m_src.meta_key = 'bridge_source'
                 WHERE c.deleted_at IS NULL";

    $sqlParams = [];

    if ($bridgeStatus !== '' && in_array($bridgeStatus, $allowedStatuses, true)) {
        $sql      .= " AND m_bst.meta_value = :bs";
        $countSql .= " AND EXISTS (SELECT 1 FROM cms_content_meta bst2 WHERE bst2.content_id = c.id AND bst2.meta_key = 'bridge_status' AND bst2.meta_value = :bs)";
        $sqlParams[':bs'] = $bridgeStatus;
    }

    $sql .= " ORDER BY c.updated_at DESC LIMIT :limit OFFSET :offset";

    $countStmt = $db->prepare($countSql);
    $countStmt->execute($sqlParams);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare($sql);
    foreach ($sqlParams as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'       => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// POST /api/v1/bridge/import/wxr
// ─────────────────────────────────────────────────────────────────────────

/**
 * Accepts a WXR file upload and runs the full import pipeline (with bridge).
 *
 * Expects multipart/form-data:
 *   file   — the .xml WXR file
 *   mode   — 'merge' (default) or 'replace'
 *
 * This is the bridge-aware import endpoint. It is equivalent to the
 * wordpress-importer WXR upload but routes all content through the
 * content-ingestion ingestion pipeline instead of writing directly.
 */
function wpBridgeApiImportWxr(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('import_export.manage');
    app()->csrfEnforce();

    // Reject if bridge is not in an active / operable state
    $bridgeState = wpBridgeGetState();
    if (!in_array($bridgeState, ['active'], true)) {
        http_response_code(423);
        echo json_encode(['ok' => false, 'error' => "Bridge is in '{$bridgeState}' state — import is not allowed"]);
        exit;
    }

    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No valid file uploaded (upload error code: ' . $uploadError . ')']);
        exit;
    }

    $tmpPath = (string)$_FILES['file']['tmp_name'];
    if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Uploaded file is empty or unreadable']);
        exit;
    }

    // Validate content before parsing
    $rawXml = (string)file_get_contents($tmpPath);
    if ($rawXml === '' || stripos($rawXml, '<rss') === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'File does not appear to be a valid WordPress WXR export']);
        exit;
    }

    $mode = trim((string)($_POST['mode'] ?? 'merge'));
    if (!in_array($mode, ['merge', 'replace'], true)) {
        $mode = 'merge';
    }

    // Parse the WXR file
    try {
        $data = wordpressImporterParseWxr($rawXml);
    } catch (Throwable $e) {
        write_log('Bridge WXR import parse error: ' . $e->getMessage(), 'error', ['source' => 'content-ingestion']);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'WXR parse error: ' . $e->getMessage()]);
        exit;
    }

    // Run the import pipeline (bridge-aware)
    try {
        $stats = wordpressImporterImportStructuredPayload($data, $mode, (int)($user['id'] ?? 0));
    } catch (Throwable $e) {
        write_log('Bridge WXR import failed: ' . $e->getMessage(), 'error', ['source' => 'content-ingestion']);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
        exit;
    }

    write_log('Bridge WXR import completed: ' . json_encode($stats), 'info', ['source' => 'content-ingestion']);

    echo json_encode([
        'ok'    => true,
        'stats' => $stats,
    ]);
    exit;
}
