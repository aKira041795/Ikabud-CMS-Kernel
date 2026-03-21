<?php

declare(strict_types=1);

function cmsApiRevisionList(array $params = []): void
{
    cmsRequireCap('revisions.list');
    $contentId = (int)($params['id'] ?? 0);
    if ($contentId <= 0) {
        app()->json(['ok' => false, 'error' => 'Invalid content ID']);
        return;
    }
    $revisions = cmsGetRevisions($contentId);
    $count = cmsRevisionCount($contentId);
    app()->json(['ok' => true, 'revisions' => $revisions, 'total' => $count]);
}

function cmsApiRevisionGet(array $params = []): void
{
    cmsRequireCap('revisions.view');
    $revId = (int)($params['id'] ?? 0);
    $revision = cmsGetRevision($revId);
    if (!$revision) {
        app()->json(['ok' => false, 'error' => 'Revision not found']);
        return;
    }
    app()->json(['ok' => true, 'revision' => $revision]);
}

function cmsApiRevisionRestore(array $params = []): void
{
    $user = cmsRequireCap('revisions.restore');
    $contentId = (int)($params['id'] ?? 0);
    $revId = (int)($params['rid'] ?? 0);

    $revision = cmsGetRevision($revId);
    if (!$revision || (int)$revision['content_id'] !== $contentId) {
        app()->json(['ok' => false, 'error' => 'Revision not found']);
        return;
    }

    $db = cmsDb();

    // Snapshot current state as a new revision before restoring
    $current = $db->prepare("SELECT title, body, blocks_json FROM cms_content WHERE id = ? LIMIT 1");
    $current->execute([$contentId]);
    $cur = $current->fetch(PDO::FETCH_ASSOC);
    if ($cur) {
        cmsSaveRevision($contentId, (int)($user['id'] ?? 0), $cur['title'], $cur['body'], $cur['blocks_json'], 'Before restore');
    }

    // Restore from revision
    $db->prepare(
        "UPDATE cms_content SET title = ?, body = ?, blocks_json = ?, updated_at = NOW() WHERE id = ?"
    )->execute([
        $revision['title'],
        $revision['body'],
        $revision['blocks_json'],
        $contentId,
    ]);

    cmsCacheInvalidateContent($contentId);

    app()->json(['ok' => true, 'message' => 'Revision restored']);
}

// ═══════════════════════════════════════════════════════════════════════
// AUTOSAVE
// ═══════════════════════════════════════════════════════════════════════
