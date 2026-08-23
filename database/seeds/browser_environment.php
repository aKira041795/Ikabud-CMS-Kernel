<?php

declare(strict_types=1);

chdir(dirname(dirname(__DIR__)));
require_once 'bootstrap.php';
require_once 'src/helpers/module-manager.php';
require_once 'modules/cms/helpers.php';

function browserResolveTenantForHost(string $host): ?int
{
    $record = \Ikabud\Kernel\TenantResolver::lookupControlHostRecord($host);
    if (is_array($record) && isset($record['tenant_id'])) {
        return (int) $record['tenant_id'];
    }

    $default = $_ENV['APP_TENANT_DEFAULT'] ?? null;
    if ($default !== null && trim((string) $default) !== '') {
        return (int) $default;
    }

    return null;
}

function browserModuleManifest(string $moduleId): array
{
    $basePath = BASE_PATH . '/modules/' . $moduleId . '/module.json';
    if (is_file($basePath)) {
        $json = json_decode((string) file_get_contents($basePath), true);
        return is_array($json) ? $json : [];
    }

    $matches = glob(BASE_PATH . '/modules/*/' . $moduleId . '/module.json') ?: [];
    if ($matches !== []) {
        $json = json_decode((string) file_get_contents($matches[0]), true);
        return is_array($json) ? $json : [];
    }

    return [];
}

function browserModulePlan(array $rootModuleIds): array
{
    $ordered = [];
    $seen = [];

    $visit = function (string $moduleId) use (&$visit, &$ordered, &$seen): void {
        if (isset($seen[$moduleId])) {
            return;
        }
        $seen[$moduleId] = true;
        $manifest = browserModuleManifest($moduleId);
        foreach (($manifest['depends'] ?? []) as $dependency) {
            if (is_string($dependency) && trim($dependency) !== '') {
                $visit(trim($dependency));
            }
        }
        $ordered[] = $moduleId;
    };

    foreach ($rootModuleIds as $moduleId) {
        $visit($moduleId);
    }

    return array_values(array_unique($ordered));
}

function browserEnsureTenantModules(int $tenantId, array $moduleIds): void
{
    $plan = browserModulePlan($moduleIds);
    echo 'browser seed: enabling modules for tenant #' . $tenantId . ': ' . implode(', ', $plan) . PHP_EOL;
    foreach ($plan as $moduleId) {
        enableModuleForTenant($moduleId, $tenantId);
        echo '  - ' . $moduleId . ': ' . (isModuleEnabledForTenant($moduleId, $tenantId) ? 'enabled' : 'disabled') . PHP_EOL;
    }
}

function browserClearLoginRateLimit(PDO $db, int $tenantId): void
{
    $db->prepare('DELETE FROM rate_limits WHERE action = :action AND identifier LIKE :identifier')->execute([
        ':action' => 'login',
        ':identifier' => 't' . $tenantId . ':%',
    ]);
}

function browserEnsureCmsAdmin(PDO $db): int
{
    $username = 'admin';
    $email = 'admin@akiracms.test';
    $displayName = 'Browser CMS Admin';
    $hash = password_hash('Admin123!', PASSWORD_DEFAULT);

    $stmt = $db->prepare('SELECT id FROM cms_users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        $db->prepare(
            'UPDATE cms_users
             SET email = :email,
                 password_hash = :password_hash,
                 display_name = :display_name,
                 role = :role,
                 is_active = 1
             WHERE id = :id'
        )->execute([
            ':email' => $email,
            ':password_hash' => $hash,
            ':display_name' => $displayName,
            ':role' => 'administrator',
            ':id' => $id,
        ]);
        echo 'browser seed: refreshed cms admin user #' . $id . PHP_EOL;
        return $id;
    }

    $db->prepare(
        'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
         VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
    )->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':display_name' => $displayName,
        ':role' => 'administrator',
    ]);

    $newId = (int) $db->lastInsertId();
    echo 'browser seed: created cms admin user #' . $newId . PHP_EOL;
    return $newId;
}

function browserResetBuilderDocuments(PDO $db, int $contentId): void
{
    $stmt = $db->prepare('SELECT id FROM cms_builder_documents WHERE content_id = :content_id');
    $stmt->execute([':content_id' => $contentId]);
    $documentIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($documentIds as $documentId) {
        $db->prepare('DELETE FROM cms_builder_revisions WHERE builder_document_id = :id')->execute([':id' => (int) $documentId]);
    }

    $db->prepare('DELETE FROM cms_builder_documents WHERE content_id = :content_id')->execute([':content_id' => $contentId]);
    $db->prepare('UPDATE cms_content SET builder_document_id = NULL WHERE id = :id')->execute([':id' => $contentId]);
}

function browserSeedBuilderDocument(PDO $db, int $contentId, int $authorId): void
{
    $document = cmsBuilderNormalizeDocument([
        'schema_version' => '1.1',
        'document' => [
            'id' => 'doc_root',
            'type' => 'document',
            'props' => ['title' => 'Browser Builder Page'],
            'style' => [],
            'meta' => [],
            'children' => [[
                'id' => 'browser_heading',
                'type' => 'heading',
                'props' => ['text' => 'Browser Builder Fixture', 'level' => 'h1'],
                'style' => [],
                'meta' => ['name' => 'Browser Builder Heading'],
                'children' => [],
            ]],
        ],
    ]);
    if (isset($document['document']) && is_array($document['document']) && function_exists('cmsBuilderApplyDefaultProps')) {
        $document['document'] = cmsBuilderApplyDefaultProps($document['document']);
    }
    if (isset($document['document']) && is_array($document['document']) && function_exists('cmsBuilderEmitDiSyLContract')) {
        $disyl = cmsBuilderEmitDiSyLContract($document['document']);
        if ($disyl !== null) {
            $document['disyl'] = $disyl;
        }
    }

    $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode browser builder document');
    }

    $stmt = $db->prepare(
        'INSERT INTO cms_builder_documents
            (content_id, schema_version, document_version, status, title, document_json, render_hash, created_by, updated_by, created_at, updated_at)
         VALUES
            (:content_id, :schema_version, 1, :status, :title, :document_json, :render_hash, :created_by, :updated_by, NOW(), NOW())'
    );
    $stmt->execute([
        ':content_id' => $contentId,
        ':schema_version' => (string)($document['schema_version'] ?? '1.1'),
        ':status' => 'draft',
        ':title' => 'Browser Builder Page',
        ':document_json' => $json,
        ':render_hash' => hash('sha256', $json),
        ':created_by' => $authorId,
        ':updated_by' => $authorId,
    ]);

    $documentId = (int)$db->lastInsertId();
    $db->prepare('UPDATE cms_content SET builder_document_id = :document_id WHERE id = :id')->execute([
        ':document_id' => $documentId,
        ':id' => $contentId,
    ]);
}

function browserEnsureBuilderPage(PDO $db, int $authorId): int
{
    $slug = 'browser-builder-page';
    $title = 'Browser Builder Page';
    $body = '<p>Browser builder seed page.</p>';

    $stmt = $db->prepare('SELECT id FROM cms_content WHERE slug = :slug AND type = :type AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':slug' => $slug, ':type' => 'page']);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        $db->prepare(
            'UPDATE cms_content
             SET title = :title,
                 body = :body,
                 content_mode = :content_mode,
                 status = :status,
                 author_id = :author_id,
                 updated_at = NOW(),
                 published_at = COALESCE(published_at, NOW())
             WHERE id = :id'
        )->execute([
            ':title' => $title,
            ':body' => $body,
            ':content_mode' => 'builder',
            ':status' => 'published',
            ':author_id' => $authorId,
            ':id' => $id,
        ]);
        browserResetBuilderDocuments($db, $id);
        browserSeedBuilderDocument($db, $id, $authorId);
        echo 'browser seed: builder page #' . $id . ' ready' . PHP_EOL;
        return $id;
    }

    $db->prepare(
        'INSERT INTO cms_content
            (uuid, title, slug, body, excerpt, type, content_mode, status, author_id,
             sort_order, comment_status, published_at, created_at, updated_at, deleted_at,
             is_sticky, is_featured, post_format, word_count, reading_time, comment_count)
         VALUES
            (UUID(), :title, :slug, :body, \'\', :type, :content_mode, :status, :author_id,
             0, \'open\', NOW(), NOW(), NOW(), NULL, 0, 0, \'standard\', 0, 0, 0)'
    )->execute([
        ':title' => $title,
        ':slug' => $slug,
        ':body' => $body,
        ':type' => 'page',
        ':content_mode' => 'builder',
        ':status' => 'published',
        ':author_id' => $authorId,
    ]);

    $newId = (int) $db->lastInsertId();
    browserResetBuilderDocuments($db, $newId);
    browserSeedBuilderDocument($db, $newId, $authorId);
    echo 'browser seed: created builder page #' . $newId . PHP_EOL;
    return $newId;
}

function browserRunSqlFileStatements(PDO $db, string $path): void
{
    $sql = (string) file_get_contents($path);
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }
}

function browserEnsureCapabilityAuthorizationRegistry(PDO $db): void
{
    try {
        $db->query('SELECT 1 FROM capability_authorization_policies LIMIT 1');
        echo 'browser seed: capability_authorization_policies table present' . PHP_EOL;
        return;
    } catch (Throwable $e) {
    }

    browserRunSqlFileStatements($db, BASE_PATH . '/database/migrations/025_kernel_capability_authorization_policies.sql');
    echo 'browser seed: created capability_authorization_policies table' . PHP_EOL;
}

function browserEnsureWorkflowRunsTable(PDO $db): void
{
    try {
        $db->query('SELECT 1 FROM workflow_runs LIMIT 1');
        echo 'browser seed: workflow_runs table present' . PHP_EOL;
        return;
    } catch (Throwable $e) {
    }

    browserRunSqlFileStatements($db, BASE_PATH . '/database/migrations/021_kernel_workflow_runs.sql');
    echo 'browser seed: created workflow_runs table' . PHP_EOL;
}

function browserEnsureReportApprovalsTable(PDO $db): void
{
    try {
        $db->query('SELECT 1 FROM report_approvals LIMIT 1');
        echo 'browser seed: report_approvals table present' . PHP_EOL;
        return;
    } catch (Throwable $e) {
    }

    browserRunSqlFileStatements($db, BASE_PATH . '/migrations/010_report_approvals.sql');
    echo 'browser seed: created report_approvals table' . PHP_EOL;
}

function browserEnsureReportApprovalFixture(PDO $db, int $requestedBy): int
{
    $title = 'Browser Report Approval Fixture';
    $db->prepare('DELETE FROM report_approvals WHERE title = :title')->execute([':title' => $title]);
    $db->prepare(
        'INSERT INTO report_approvals
            (export_source, export_format, title, status, requested_by, created_at, updated_at)
         VALUES
            (:export_source, :export_format, :title, :status, :requested_by, NOW(), NOW())'
    )->execute([
        ':export_source' => 'cms_page',
        ':export_format' => 'csv',
        ':title' => $title,
        ':status' => 'pending',
        ':requested_by' => $requestedBy,
    ]);

    $id = (int) $db->lastInsertId();
    echo 'browser seed: inserted report approval fixture #' . $id . PHP_EOL;
    return $id;
}

$cmsHost = 'akiracms.test';
$tenantId = browserResolveTenantForHost($cmsHost);
if ($tenantId === null || $tenantId <= 0) {
    fwrite(STDERR, 'browser seed: failed to resolve tenant for ' . $cmsHost . PHP_EOL);
    exit(1);
}

browserEnsureTenantModules($tenantId, [
    'cms-akira-profile-standard',
    'ecommerce',
]);
invalidateTenantModuleSettingsCache();

$db = app()->dbForTenant($tenantId);
if (!$db instanceof PDO) {
    fwrite(STDERR, 'browser seed: no tenant DB for #' . $tenantId . PHP_EOL);
    exit(1);
}

browserEnsureCapabilityAuthorizationRegistry($db);
browserEnsureWorkflowRunsTable($db);
browserClearLoginRateLimit($db, $tenantId);
$adminId = browserEnsureCmsAdmin($db);
$builderPageId = browserEnsureBuilderPage($db, $adminId);
browserEnsureReportApprovalsTable($db);
$approvalId = browserEnsureReportApprovalFixture($db, $adminId);

$palHost = 'palsystem.test';
$palTenantId = browserResolveTenantForHost($palHost);
if ($palTenantId !== null && $palTenantId > 0) {
    $palDb = app()->dbForTenant($palTenantId);
    if ($palDb instanceof PDO) {
        browserClearLoginRateLimit($palDb, $palTenantId);
        $palHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $palStmt = $palDb->prepare('SELECT id FROM pal_users WHERE tenant_id = :tenant_id AND username = :username LIMIT 1');
        $palStmt->execute([':tenant_id' => $palTenantId, ':username' => 'admin']);
        $palUserId = (int)($palStmt->fetchColumn() ?: 0);
        if ($palUserId > 0) {
            $palDb->prepare(
                'UPDATE pal_users
                 SET email = COALESCE(NULLIF(email, \'\'), :email),
                     password_hash = :password_hash,
                     full_name = :full_name,
                     role = :role,
                     is_active = 1
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                ':email' => 'admin@palsystem.test',
                ':password_hash' => $palHash,
                ':full_name' => 'Browser PAL Admin',
                ':role' => 'admin',
                ':id' => $palUserId,
                ':tenant_id' => $palTenantId,
            ]);
            echo 'browser seed: refreshed PAL admin user #' . $palUserId . PHP_EOL;
        }
    }
}

echo 'browser seed: done tenant=' . $tenantId . ' builder_page_id=' . $builderPageId . ' approval_id=' . $approvalId . PHP_EOL;
