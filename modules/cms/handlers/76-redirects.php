<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════
// REDIRECT MANAGER — Admin UI + API
// ═══════════════════════════════════════════════════════════════════════

/**
 * Admin page listing all slug redirects with ability to add custom redirects and delete.
 */
function cmsAdminRedirects(array $params = []): void
{
    $user = cmsRequireCap('redirects.view');

    $db = cmsDb();
    $redirects = $db->query(
        "SELECT r.id, r.content_id, r.old_slug, r.target_url, r.created_at,
                c.title AS content_title, c.slug AS current_slug, c.type AS content_type
         FROM cms_slug_redirects r
         LEFT JOIN cms_content c ON c.id = r.content_id AND c.deleted_at IS NULL
         ORDER BY r.created_at DESC
         LIMIT 500"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo cmsRender('modules/cms/admin/redirects.disyl', array_merge(cmsAdminContext($user, 'redirects', [
        ['label' => 'Redirects', 'url' => ''],
    ]), [
        'page_title' => 'Redirects',
        'redirects'  => $redirects,
    ]));
}

// ── API: List redirects (JSON) ───────────────────────────────────────

function cmsApiRedirectList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('redirects.view');

    $db = cmsDb();
    $rows = $db->query(
        "SELECT r.id, r.content_id, r.old_slug, r.target_url, r.created_at,
                c.title AS content_title, c.slug AS current_slug, c.type AS content_type
         FROM cms_slug_redirects r
         LEFT JOIN cms_content c ON c.id = r.content_id AND c.deleted_at IS NULL
         ORDER BY r.created_at DESC
         LIMIT 500"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['ok' => true, 'redirects' => $rows]);
    exit;
}

// ── API: Create custom redirect ──────────────────────────────────────

function cmsApiRedirectCreate(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('redirects.create');

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $oldSlug   = trim((string)($input['old_slug'] ?? ''));
    $targetUrl = trim((string)($input['target_url'] ?? ''));
    $contentId = !empty($input['content_id']) ? (int)$input['content_id'] : null;

    if ($oldSlug === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'old_slug is required']);
        exit;
    }

    // Must have either target_url or content_id
    if ($targetUrl === '' && $contentId === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Provide either target_url or content_id']);
        exit;
    }

    // Clean old_slug (strip leading slashes)
    $oldSlug = ltrim($oldSlug, '/');

    if ($oldSlug === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'old_slug cannot be empty or just slashes']);
        exit;
    }

    $db = cmsDb();

    // Check for duplicate old_slug
    $existing = $db->prepare("SELECT id FROM cms_slug_redirects WHERE old_slug = :slug LIMIT 1");
    $existing->execute([':slug' => $oldSlug]);
    if ($existing->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'A redirect for this slug already exists']);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT INTO cms_slug_redirects (content_id, old_slug, target_url, created_at)
         VALUES (:cid, :slug, :target, NOW())"
    );
    $stmt->execute([
        ':cid'    => $contentId,
        ':slug'   => $oldSlug,
        ':target' => $targetUrl !== '' ? $targetUrl : null,
    ]);

    $newId = (int)$db->lastInsertId();

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

// ── API: Delete redirect ─────────────────────────────────────────────

function cmsApiRedirectDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('redirects.delete');

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid redirect ID']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("DELETE FROM cms_slug_redirects WHERE id = :id");
    $stmt->execute([':id' => $id]);

    echo json_encode(['ok' => true]);
    exit;
}
