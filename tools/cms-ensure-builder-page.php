<?php
/**
 * Ensure a host's tenant has a PUBLISHED page-builder fixture page.
 *
 * WHY THIS EXISTS: the browser render-parity gate needs the same document
 * available two ways — in the editor canvas and on the published page — for a
 * real tenant. database/seeds/browser_environment.php cannot serve that:
 *
 *   1. it hardcodes `akiracms.test`, and that vhost's DocumentRoot is
 *      /var/www/html/ikabudsix/public — a DIFFERENT codebase — while the seed
 *      itself runs against THIS repository's databases; and
 *   2. it writes the builder document with status 'draft' only, but the public
 *      render path (cmsPageBuilderRenderedHtml) loads status 'published'. A
 *      seeded page therefore CANNOT render publicly, which is exactly what was
 *      observed: HTTP 200, the page shell, and not one node marker.
 *
 * This writes both rows — published so the page renders, draft so the editor
 * opens on the same document — for whichever host/tenant you point it at.
 *
 * Idempotent.
 *
 * Usage:
 *   php tools/cms-ensure-builder-page.php --host=cmsnew.test
 *   php tools/cms-ensure-builder-page.php --host=cmsnew.test --slug=my-fixture --title="My Fixture"
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once 'bootstrap.php';
require_once 'src/helpers/module-manager.php';
require_once 'modules/cms/helpers.php';
require_once __DIR__ . '/lib/builder-parity-fixture.php';

function cmsFixtureOption(array $argv, string $key, ?string $default = null): ?string
{
    $needle = '--' . $key . '=';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $needle)) {
            return substr($arg, strlen($needle));
        }
    }
    return $default;
}

$host = trim((string) cmsFixtureOption($argv, 'host'));
if ($host === '') {
    fwrite(STDERR, "Usage: php tools/cms-ensure-builder-page.php --host=<domain> [--slug=…] [--title=…]\n");
    exit(1);
}
$slug = trim((string) cmsFixtureOption($argv, 'slug', 'render-parity-fixture'));
$title = trim((string) cmsFixtureOption($argv, 'title', 'Render Parity Fixture'));

$record = \Ikabud\Kernel\TenantResolver::lookupControlHostRecord($host);
$tenantId = is_array($record) && isset($record['tenant_id']) ? (int) $record['tenant_id'] : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "No tenant resolves for host '{$host}'. See `php ikabud tenant:list`.\n");
    exit(1);
}
$db = app()->dbForTenant($tenantId);
if (!$db instanceof \PDO) {
    fwrite(STDERR, "Tenant #{$tenantId} has no database connection.\n");
    exit(1);
}

// Author: any CMS administrator. Created by tools/cms-ensure-admin.php.
$authorStmt = $db->query("SELECT id FROM cms_users WHERE role = 'administrator' AND is_active = 1 ORDER BY id LIMIT 1");
$authorId = (int) ($authorStmt->fetchColumn() ?: 0);
if ($authorId <= 0) {
    fwrite(STDERR, "No active CMS administrator on tenant #{$tenantId}.\n");
    fwrite(STDERR, "Run: php tools/cms-ensure-admin.php --host={$host}\n");
    exit(1);
}

$document = builderParityFixtureDocument();
$declaredIds = builderParityFixtureIds($document);
if (function_exists('cmsBuilderApplyDefaultProps')) {
    $document['document'] = cmsBuilderApplyDefaultProps($document['document']);
}
// Deliberately no cmsBuilderEmitDiSyLContract() call: see the note in
// tools/lib/builder-parity-fixture.php — a `disyl` key would route the public
// page through cmsRenderDiSyLDocument() instead of the widget renderers.
$documentJson = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($documentJson === false) {
    fwrite(STDERR, "Could not encode the parity document\n");
    exit(1);
}

$currentUser = function_exists('cmsCurrentUserId') ? cmsCurrentUserId() : null;
$byUserId = $authorId;

// ── Content row ──────────────────────────────────────────────────────────
$find = $db->prepare('SELECT id FROM cms_content WHERE slug = :slug AND type = :type AND deleted_at IS NULL LIMIT 1');
$find->execute([':slug' => $slug, ':type' => 'page']);
$contentId = (int) ($find->fetchColumn() ?: 0);

if ($contentId > 0) {
    $db->prepare(
        'UPDATE cms_content
            SET title = :title,
                content_mode = :content_mode,
                status = :status,
                author_id = :author_id,
                updated_at = NOW(),
                published_at = COALESCE(published_at, NOW())
          WHERE id = :id'
    )->execute([
        ':title' => $title,
        ':content_mode' => 'builder',
        ':status' => 'published',
        ':author_id' => $authorId,
        ':id' => $contentId,
    ]);
    $action = 'updated';
} else {
    $db->prepare(
        "INSERT INTO cms_content
            (uuid, title, slug, body, excerpt, type, content_mode, status, author_id,
             sort_order, comment_status, published_at, created_at, updated_at, deleted_at,
             is_sticky, is_featured, post_format, word_count, reading_time, comment_count)
         VALUES
            (UUID(), :title, :slug, '', '', 'page', 'builder', 'published', :author_id,
             0, 'open', NOW(), NOW(), NOW(), NULL, 0, 0, 'standard', 0, 0, 0)"
    )->execute([':title' => $title, ':slug' => $slug, ':author_id' => $authorId]);
    $contentId = (int) $db->lastInsertId();
    $action = 'created';
}

// ── Builder document rows ────────────────────────────────────────────────
// Replaced wholesale so re-running always yields the same fixture rather than
// accumulating revisions.
$old = $db->prepare('SELECT id FROM cms_builder_documents WHERE content_id = :content_id');
$old->execute([':content_id' => $contentId]);
foreach ($old->fetchAll(PDO::FETCH_COLUMN) ?: [] as $oldId) {
    $db->prepare('DELETE FROM cms_builder_revisions WHERE builder_document_id = :id')->execute([':id' => (int) $oldId]);
}
$db->prepare('DELETE FROM cms_builder_documents WHERE content_id = :content_id')->execute([':content_id' => $contentId]);
$db->prepare('UPDATE cms_content SET builder_document_id = NULL WHERE id = :id')->execute([':id' => $contentId]);

$insert = $db->prepare(
    'INSERT INTO cms_builder_documents
        (content_id, schema_version, document_version, status, title, document_json, render_hash,
         created_by, updated_by, created_at, updated_at)
     VALUES
        (:content_id, :schema_version, 1, :status, :title, :document_json, :render_hash,
         :created_by, :updated_by, NOW(), NOW())'
);

$firstId = 0;
foreach (['published', 'draft'] as $docStatus) {
    $insert->execute([
        ':content_id' => $contentId,
        ':schema_version' => (string)($document['schema_version'] ?? '1.1'),
        ':status' => $docStatus,
        ':title' => $title,
        ':document_json' => $documentJson,
        ':render_hash' => hash('sha256', $documentJson),
        ':created_by' => $byUserId,
        ':updated_by' => $byUserId,
    ]);
    if ($firstId === 0) {
        $firstId = (int) $db->lastInsertId();
    }
}
if ($firstId > 0) {
    $db->prepare('UPDATE cms_content SET builder_document_id = :document_id WHERE id = :id')
        ->execute([':document_id' => $firstId, ':id' => $contentId]);
}

printf("builder fixture %s: host=%s tenant=%d content=#%d slug=%s (%d nodes)\n", $action, $host, $tenantId, $contentId, $slug, count($declaredIds));
printf("  published page : http://%s/cms/page/%s\n", $host, $slug);
printf("  editor         : http://%s/cms/admin/react-builder/%d\n", $host, $contentId);
