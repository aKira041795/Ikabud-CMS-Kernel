<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// WordPress Bridge Module — Ingestion Handler
//
// This is the spine of the migration bridge:
//   1. API endpoint validates + dispatches ingestion events
//   2. Event handler enforces idempotency → normalize → capability write → provenance
// ─────────────────────────────────────────────────────────────────────────

/**
 * POST /api/v1/bridge/ingest
 *
 * Accepts a normalized content ingestion event and dispatches it through
 * the kernel event bus. This endpoint does NO business logic — it validates
 * the schema and hands off to the event handler.
 */
function wpBridgeApiIngest(array $params = []): void
{
    header('Content-Type: application/json');

    // ── bridge_enabled gate ──────────────────────────────────────────
    // bridge_enabled is the master on/off switch. If it is not set,
    // the ingest endpoint is closed regardless of bridge_state.
    $bridgeSettings = function_exists('getModuleSettings') ? getModuleSettings('wordpress-bridge') : [];
    if (empty($bridgeSettings['bridge_enabled'])) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'WordPress Bridge is not enabled for this tenant.']);
        exit;
    }

    // ── Auth: session cap OR bearer token ───────────────────────────
    // The WordPress companion plugin authenticates via bearer token (no
    // browser session). Fall back to session cap check when no bearer
    // token is present (WXR import triggered from the admin UI).
    $user         = null;
    $authHeader   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $usingToken   = false;

    if (str_starts_with($authHeader, 'Bearer ')) {
        $providedToken = substr($authHeader, 7);
        $storedToken   = (string)($bridgeSettings['bridge_api_token'] ?? '');

        if ($storedToken === '' || !hash_equals($storedToken, $providedToken)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing bridge API token.']);
            exit;
        }
        $usingToken = true;
        // Token-authenticated requests act as an anonymous system actor (id=0)
        $user = ['id' => 0, 'role' => 'bridge-token'];
    } else {
        // Session-based: require the CMS import cap and enforce CSRF
        $user = cmsRequireCap('import_export.manage');
        app()->csrfEnforce();
    }

    // Read input
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody ?: '', true);
    if (!is_array($input)) {
        // Fall back to POST form data
        $input = $_POST;
    }

    // ── Schema validation ────────────────────────────────────────────
    $event = trim((string)($input['event'] ?? ''));
    $source = trim((string)($input['source'] ?? ''));
    $externalId = trim((string)($input['external_id'] ?? ''));
    $externalModified = trim((string)($input['external_modified'] ?? ''));
    $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

    $errors = [];
    if ($event === '') {
        $errors[] = 'event is required';
    }
    if ($source === '') {
        $errors[] = 'source is required';
    }
    if ($externalId === '') {
        $errors[] = 'external_id is required';
    }
    if ($externalModified === '') {
        $errors[] = 'external_modified is required';
    }
    if (empty($payload)) {
        $errors[] = 'payload is required and must be an object';
    }
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $errors]);
        exit;
    }

    // Only accept known bridge events
    $allowedEvents = ['cms.migration.content.upserted'];
    if (!in_array($event, $allowedEvents, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unknown bridge event: ' . $event]);
        exit;
    }

    // ── Dispatch via kernel event bus ────────────────────────────────
    // Pack the envelope so the handler has everything it needs.
    $envelope = [
        'source'            => $source,
        'external_id'       => $externalId,
        'external_modified' => $externalModified,
        'payload'           => $payload,
        'author_id'         => (int)($user['id'] ?? 0),
    ];

    $result = wpBridgeHandleContentUpserted($envelope);

    $httpStatus = ($result['outcome'] === 'processed') ? 200 : (($result['outcome'] === 'skipped' || $result['outcome'] === 'stale') ? 200 : 500);
    http_response_code($httpStatus);
    echo json_encode($result);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Event Handler: cms.migration.content.upserted
//
// Flow: idempotency → normalize → capability write (or update) → provenance → log → result event
// ─────────────────────────────────────────────────────────────────────────

/**
 * Handle a single content ingestion event.
 *
 * This is the core of the bridge pipeline. It can be called from:
 *   - The API endpoint (direct call)
 *   - The modified wordpress-importer (via event emission)
 *   - Future: cron-based batch ingestion
 *
 * @param array $envelope Keys: source, external_id, external_modified, payload, author_id
 * @return array Result with keys: ok, outcome, external_id, cms_content_id?, error?
 */
function wpBridgeHandleContentUpserted(array $envelope): array
{
    $source           = (string)($envelope['source'] ?? '');
    $externalId       = (string)($envelope['external_id'] ?? '');
    $externalModified = (string)($envelope['external_modified'] ?? '');
    $payload          = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
    $authorId         = (int)($envelope['author_id'] ?? 0);
    $eventName        = 'cms.migration.content.upserted';

    // ── 0. Bridge-state gate (reject writes when bridge is not active) ─
    if (function_exists('wpBridgeGetState')) {
        $bridgeState = wpBridgeGetState();
        if (!in_array($bridgeState, ['active'], true)) {
            return [
                'ok'          => false,
                'outcome'     => 'blocked',
                'external_id' => $externalId,
                'reason'      => 'bridge-' . $bridgeState,
            ];
        }
    }

    // ── 1. Idempotency guard (FIRST, before anything else) ───────────
    $idempotencyResult = wpBridgeIdempotencyCheck($source, $externalId, $externalModified);

    if ($idempotencyResult === 'skip') {
        wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'skipped', null, $payload, 'Duplicate: already processed');
        write_log("Bridge skip: {$source}/{$externalId} already processed at {$externalModified}", 'info', ['source' => 'wordpress-bridge']);
        return ['ok' => true, 'outcome' => 'skipped', 'external_id' => $externalId, 'reason' => 'duplicate'];
    }

    if ($idempotencyResult === 'stale') {
        wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'skipped', null, $payload, 'Stale: older than stored version');
        write_log("Bridge skip: {$source}/{$externalId} is stale (older than stored)", 'info', ['source' => 'wordpress-bridge']);
        return ['ok' => true, 'outcome' => 'stale', 'external_id' => $externalId, 'reason' => 'out-of-order'];
    }

    // ── 2. Normalize payload ─────────────────────────────────────────
    $title   = trim((string)($payload['title'] ?? ''));
    $slug    = trim((string)($payload['slug'] ?? ''));
    $body    = (string)($payload['body'] ?? '');
    $excerpt = trim((string)($payload['excerpt'] ?? ''));
    $type    = trim((string)($payload['type'] ?? 'post')) ?: 'post';
    $status  = wordpressImporterNormalizeStatus((string)($payload['status'] ?? 'draft'));

    if ($title === '') {
        wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'failed', null, $payload, 'title is required');
        return ['ok' => false, 'outcome' => 'failed', 'external_id' => $externalId, 'error' => 'title is required'];
    }

    if ($slug === '') {
        $slug = cmsSlugify($title);
    }

    // Apply media URL rewrites from bridge pipeline (BEFORE HTML sanitize)
    $urlMap = is_array($envelope['url_map'] ?? null) ? $envelope['url_map'] : [];
    if (!empty($urlMap)) {
        $body    = strtr($body, $urlMap);
        $excerpt = strtr($excerpt, $urlMap);
    }

    // Sanitize HTML
    $body = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($body, 'cms.content'), 'cms.content');

    // Resolve author
    $resolvedAuthorId = wordpressImporterResolveAuthorId($authorId);

    // ── 3. Check for existing content (by provenance, not by slug) ───
    $existing = wpBridgeFindExistingByProvenance($source, $externalId);
    $cmsContentId = null;
    $action = 'create';

    try {
        $db = cmsDb();

        if ($existing) {
            // ── Update path ──────────────────────────────────────────
            $action = 'update';
            $cmsContentId = (int)$existing['id'];

            // Check for conflict
            $provenance = wpBridgeReadProvenance($cmsContentId);
            $bridgeStatus = (string)($provenance['bridge_status'] ?? 'external-managed');

            if ($bridgeStatus === 'cms-managed' || $bridgeStatus === 'retired') {
                // Content has been claimed by CMS or retired — do not overwrite
                wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'skipped', $cmsContentId, $payload, "Content is {$bridgeStatus}, bridge sync disabled");
                return ['ok' => true, 'outcome' => 'skipped', 'external_id' => $externalId, 'cms_content_id' => $cmsContentId, 'reason' => $bridgeStatus];
            }

            if (wpBridgeHasConflict($existing, $provenance)) {
                // CMS was edited since last sync — mark as review-required, don't overwrite
                wpBridgeWriteProvenance($cmsContentId, $source, $externalId, $externalModified, 'review-required');
                wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'skipped', $cmsContentId, $payload, 'Conflict detected: CMS modified since last sync');
                write_log("Bridge conflict: {$source}/{$externalId} → cms_content.id={$cmsContentId}, marking review-required", 'warning', ['source' => 'wordpress-bridge']);
                return ['ok' => true, 'outcome' => 'conflict', 'external_id' => $externalId, 'cms_content_id' => $cmsContentId, 'reason' => 'review-required'];
            }

            // Safe to update
            $publishedAt = wordpressImporterNormalizeDate($payload['published_at'] ?? null);
            $db->prepare(
                "UPDATE cms_content SET title = :title, slug = :slug, body = :body, excerpt = :excerpt,
                        status = :status, published_at = :pub, updated_at = NOW()
                 WHERE id = :id"
            )->execute([
                ':title'   => $title,
                ':slug'    => $slug,
                ':body'    => $body,
                ':excerpt' => $excerpt,
                ':status'  => $status,
                ':pub'     => $publishedAt,
                ':id'      => $cmsContentId,
            ]);
        } else {
            // ── Create path (via capability for boundary safety) ─────
            $capPayload = [
                'title'     => $title,
                'slug'      => $slug,
                'body'      => $body,
                'excerpt'   => $excerpt,
                'type'      => $type,
                'status'    => $status,
                'author_id' => $resolvedAuthorId,
            ];

            if (isset($payload['published_at'])) {
                $capPayload['published_at'] = $payload['published_at'];
            }

            $capResult = app()->cap()->call('cms.content.create@1', $capPayload);

            if (empty($capResult['ok'])) {
                $error = (string)($capResult['error'] ?? 'Capability call failed');
                wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'failed', null, $payload, $error);
                write_log("Bridge failed: {$source}/{$externalId} — cms.content.create@1: {$error}", 'error', ['source' => 'wordpress-bridge']);
                return ['ok' => false, 'outcome' => 'failed', 'external_id' => $externalId, 'error' => $error];
            }

            $cmsContentId = (int)($capResult['id'] ?? 0);
        }

        // ── 4. Sync categories and tags ──────────────────────────────
        if ($cmsContentId > 0) {
            $categories = is_array($payload['categories'] ?? null) ? $payload['categories'] : [];
            $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];

            if (!empty($categories)) {
                $catIds = wpBridgeResolveCategoryIds($categories);
                if (!empty($catIds)) {
                    cmsSyncContentCategories($cmsContentId, $catIds);
                }
            }

            if (!empty($tags)) {
                cmsSyncContentTags($cmsContentId, $tags);
            }
        }

        // ── 5. Write provenance metadata ─────────────────────────────
        if ($cmsContentId > 0) {
            wpBridgeWriteProvenance($cmsContentId, $source, $externalId, $externalModified, 'external-managed');
        }

        // ── 6. Log success ───────────────────────────────────────────
        wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'processed', $cmsContentId, $payload);

        // ── 7. Emit result event ─────────────────────────────────────
        kernelEmitEvent('cms.migration.content.completed', [
            'source'         => $source,
            'external_id'    => $externalId,
            'cms_content_id' => $cmsContentId,
            'status'         => $status,
            'outcome'        => $action === 'create' ? 'created' : 'updated',
        ], 'wordpress-bridge');

        write_log("Bridge {$action}: {$source}/{$externalId} → cms_content.id={$cmsContentId}", 'info', ['source' => 'wordpress-bridge']);

        return [
            'ok'             => true,
            'outcome'        => 'processed',
            'action'         => $action,
            'external_id'    => $externalId,
            'cms_content_id' => $cmsContentId,
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        wpBridgeLogIngestion($source, $externalId, $externalModified, $eventName, 'failed', $cmsContentId, $payload, $error);
        write_log("Bridge exception: {$source}/{$externalId} — {$error}", 'error', ['source' => 'wordpress-bridge']);

        return ['ok' => false, 'outcome' => 'failed', 'external_id' => $externalId, 'error' => 'Ingestion failed'];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Category Resolution
// ─────────────────────────────────────────────────────────────────────────

/**
 * Resolve category names/slugs to CMS category IDs.
 * Creates categories that don't exist yet.
 *
 * @param array $categories Array of category names or slugs
 * @return int[] CMS category IDs
 */
function wpBridgeResolveCategoryIds(array $categories): array
{
    $db = cmsDb();
    $ids = [];

    foreach ($categories as $cat) {
        $cat = trim((string)$cat);
        if ($cat === '') {
            continue;
        }

        $slug = cmsSlugify($cat);

        // Try to find existing category by slug
        $stmt = $db->prepare("SELECT id FROM cms_categories WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $ids[] = (int)$row['id'];
            continue;
        }

        // Create new category
        $db->prepare(
            "INSERT INTO cms_categories (name, slug, description, parent_id) VALUES (:name, :slug, '', NULL)"
        )->execute([':name' => $cat, ':slug' => $slug]);
        $ids[] = (int)$db->lastInsertId();
    }

    return array_values(array_unique($ids));
}
