<?php

declare(strict_types=1);

function cmsApiBuilderDocumentGet(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.access');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $meta = cmsLoadContentMeta(cmsDb(), $id);
    $draftRow = cmsBuilderLoadDocumentRow($id, 'draft');
    $documentWrapper = ($draftRow && !empty($draftRow['document_json']))
        ? cmsBuilderNormalizeDocument((string)$draftRow['document_json'])
        : cmsBuilderDefaultDocument();
    $seoSettings = trim((string)($meta['_builder_seo_settings'] ?? ''));

    // React builder expects the inner document node ({id, type, props, style, children, meta})
    // not the wrapper ({schema_version, document: {...}}).
    // Extract the inner document node for React compatibility.
    $documentNode = $documentWrapper['document'] ?? $documentWrapper;
    // If somehow the node itself is still a wrapper (has 'document' key but no 'type'),
    // keep unwrapping until we get a node with 'type'.
    if (isset($documentNode['document']) && !isset($documentNode['type'])) {
        $documentNode = $documentNode['document'];
    }

    // Only pass the inner node to React when it has actual content (children).
    // For empty / brand-new documents return null so React falls back to its own
    // createEmptyDocument(), which seeds the canvas with one default section.
    // This preserves the previous UX while still allowing saved templates to be
    // loaded back into the builder for re-editing.
    $documentHasContent = !empty($documentNode['children']) && is_array($documentNode['children']);

    echo json_encode([
        'ok' => true,
        'data' => [
            'content' => [
                'id' => (int)$content['id'],
                'title' => (string)($content['title'] ?? ''),
                'slug' => (string)($content['slug'] ?? ''),
                'type' => (string)($content['type'] ?? ''),
                'status' => (string)($content['status'] ?? 'draft'),
                'content_mode' => (string)($content['content_mode'] ?? 'standard'),
                'builder_enabled' => cmsPageBuilderEnabled($meta, $content),
            ],
            'document' => $documentHasContent ? $documentNode : null,
            'document_id' => (int)($draftRow['id'] ?? 0),
            'source' => $draftRow ? 'builder_document' : 'react_default',
            'global_styles' => cmsPageBuilderSettings($meta, $content),
            'seo_settings' => $seoSettings !== '' ? $seoSettings : null,
        ],
    ]);
    exit;
}

function cmsApiBuilderDocumentSave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.save');
    // CSRF only guards cookie/session-authenticated browser requests; a Bearer
    // service token is non-ambient (cannot be attached cross-origin). Skip CSRF
    // for service-token writes; auth/caps are enforced by cmsRequireCap above.
    if (!cmsIsServiceTokenRequest()) {
        app()->csrfEnforce();
    }
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $input = cmsInput();

    // Guard: if the kernel could not parse the JSON body (e.g. depth exceeded)
    // reject the request immediately so we never overwrite real content with an
    // empty default document.
    if (isset($input['_json_error'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request body: ' . $input['_json_error']]);
        exit;
    }

    $document = $input['document'] ?? $input;
    $validation = cmsBuilderValidateDocument($document);
    if (empty($validation['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'validation_failed', 'issues' => $validation['issues']]);
        exit;
    }

    // Guard: prevent accidental overwrite of real content with an empty document.
    // This catches edge cases where the request body was partially parsed or the
    // React builder sent a reset/default document due to a client-side error.
    $incomingSections = count($validation['document']['document']['children'] ?? []);
    if ($incomingSections === 0) {
        $existingDraft = cmsBuilderLoadDocumentRow($id, 'draft');
        if ($existingDraft && !empty($existingDraft['document_json'])) {
            $existingDoc = cmsBuilderNormalizeDocument((string)$existingDraft['document_json']);
            $existingSections = count($existingDoc['document']['children'] ?? []);
            if ($existingSections > 0) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Refusing to overwrite ' . $existingSections . ' section(s) with an empty document. If intentional, delete sections in the builder first.']);
                exit;
            }
        }
    }

    $title = trim((string)($input['title'] ?? $content['title'] ?? 'Untitled Page'));
    $slug = trim((string)($input['slug'] ?? $content['slug'] ?? ''));
    if ($slug === '') {
        $slug = cmsSlugify($title);
    }
    $status = trim((string)($input['status'] ?? $content['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'published', 'scheduled', 'private'], true)) {
        $status = (string)($content['status'] ?? 'draft');
    }
    if (!cmsCanPublish($user)) {
        $status = (string)($content['status'] ?? 'draft');
    }
    $actorId = (int)($user['id'] ?? 0);

    $builderSettings = $input['global_styles'] ?? ($input['builder_page_settings'] ?? $input['builder_settings'] ?? []);
    if (is_string($builderSettings) && trim($builderSettings) !== '') {
        $decodedBuilderSettings = json_decode($builderSettings, true);
        $builderSettings = is_array($decodedBuilderSettings) ? $decodedBuilderSettings : [];
    }
    if (!is_array($builderSettings)) {
        $builderSettings = [];
    }
    $builderSettingsJson = json_encode($builderSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $seoSettings = $input['seo_settings'] ?? null;
    if (is_array($seoSettings)) {
        $seoSettings = json_encode($seoSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (!is_string($seoSettings)) {
        $seoSettings = null;
    }
    $seoSettings = is_string($seoSettings) && trim($seoSettings) !== '' ? $seoSettings : null;

    try {
        $db = cmsDb();
        $slug = cmsEnsureUniqueSlug($slug, (string)($content['type'] ?? 'page'), $id);
        if ($slug !== (string)($content['slug'] ?? '')) {
            cmsSaveSlugRedirect($id, (string)($content['slug'] ?? ''));
        }
        $documentId = cmsBuilderPersistDocument($id, $validation['document'], 'draft', $title, $actorId);
        $publishedDocumentId = null;
        if ($status === 'published' && cmsCanPublish($user)) {
            $publishedDocumentId = cmsBuilderPersistDocument($id, $validation['document'], 'published', $title, $actorId);
            cmsBuilderCreateRevision($publishedDocumentId, $validation['document'], $actorId, 'Saved via builder');
        }
        $db->prepare("UPDATE cms_content SET title = :title, slug = :slug, status = :status, content_mode = 'builder', updated_at = NOW() WHERE id = :id")
            ->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':status' => $status,
                ':id' => $id,
            ]);
        $previousSlug = (string)($content['slug'] ?? '');
        $content['title'] = $title;
        $content['slug'] = $slug;
        $content['status'] = $status;
        $content['content_mode'] = 'builder';
        if ($publishedDocumentId !== null) {
            $content['builder_document_id'] = $publishedDocumentId;
            $db->prepare("UPDATE cms_content SET builder_document_id = :doc_id WHERE id = :id")
                ->execute([
                    ':doc_id' => $publishedDocumentId,
                    ':id' => $id,
                ]);
        }
        $metaToSave = [
            '_builder_enabled' => '1',
            '_builder_page_settings' => $builderSettingsJson,
        ];
        if ($seoSettings !== null) {
            $metaToSave['_builder_seo_settings'] = $seoSettings;
        }
        cmsSaveMeta($db, $id, $metaToSave);

        // Invalidate public page cache so the live page reflects changes
        $cacheTags = cmsCacheTagsForContent($content);
        if ($previousSlug !== '' && $previousSlug !== $slug) {
            $cacheTags[] = 'cms:' . (string)($content['type'] ?? 'page') . ':' . $previousSlug;
        }
        cmsCacheInvalidateByTags(array_values(array_unique(array_filter($cacheTags))));

        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.builder.document.saved', [
                'content_id' => $id,
                'document_id' => $documentId,
                'schema_version' => (string)($validation['document']['schema_version'] ?? '1.0'),
                'actor_id' => $actorId,
            ]);
        }

        echo json_encode(['ok' => true, 'data' => ['document_id' => $documentId, 'content_id' => $id, 'status' => 'draft']]);
        exit;
    } catch (Throwable $e) {
        app()->log('error', 'Builder save failed: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'content_id' => $id,
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save builder document']);
        exit;
    }
}

function cmsApiBuilderDocumentPublish(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.publish');

    // Publish requires at least 'author' role (or kernel admin)
    if (!cmsCanPublish($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to publish']);
        exit;
    }

    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    // Guard: builder is only for pages
    if (($content['type'] ?? '') !== 'page') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Builder publish is only supported for pages']);
        exit;
    }

    $draft = cmsBuilderLoadDocumentRow($id, 'draft');
    if (!$draft || empty($draft['document_json'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No draft builder document to publish']);
        exit;
    }

    $document = cmsBuilderNormalizeDocument((string)$draft['document_json']);

    // Publish-time validation — re-validate before promoting draft to published
    $validationErrors = cmsBuilderValidateDocument($document);
    if ($validationErrors !== []) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Document validation failed', 'errors' => $validationErrors]);
        exit;
    }

    $actorId = (int)($user['id'] ?? 0);
    $title = trim((string)($content['title'] ?? $draft['title'] ?? 'Untitled Page'));
    $meta = cmsLoadContentMeta(cmsDb(), $id);
    $builderSettings = cmsPageBuilderSettings($meta);
    $builderSettingsJson = json_encode($builderSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $db = cmsDb();

        // Wrap document + meta writes in a transaction to prevent dual-write desync
        $db->beginTransaction();

        $publishedId = cmsBuilderPersistDocument($id, $document, 'published', $title, $actorId);
        cmsBuilderCreateRevision($publishedId, $document, $actorId, 'Published');

        // Set content_mode, builder_document_id, AND content status to 'published'
        $publishedAt = $content['published_at'] ?? date('Y-m-d H:i:s');
        $db->prepare(
            "UPDATE cms_content SET status = 'published', content_mode = 'builder', builder_document_id = :doc_id, published_at = COALESCE(published_at, :pub_at), updated_at = NOW() WHERE id = :id"
        )->execute([
            ':doc_id' => $publishedId,
            ':pub_at' => $publishedAt,
            ':id' => $id,
        ]);
        cmsSaveMeta($db, $id, [
            '_builder_enabled' => '1',
            '_builder_page_settings' => $builderSettingsJson,
        ]);

        $db->commit();

        $content['builder_document_id'] = $publishedId;
        $content['content_mode'] = 'builder';
        $content['status'] = 'published';
        cmsCacheInvalidateByTags(array_values(array_unique(array_filter(cmsCacheTagsForContent($content)))));

        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.builder.document.published', [
                'content_id' => $id,
                'document_id' => $publishedId,
                'actor_id' => $actorId,
            ]);
        }

        echo json_encode(['ok' => true, 'data' => ['document_id' => $publishedId, 'content_id' => $id, 'status' => 'published']]);
        exit;
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to publish builder document']);
        exit;
    }
}

function cmsApiBuilderDocumentPreview(array $params = []): void
{
    $user = cmsRequireCap('builder.preview');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $input = cmsInput();
    $document = $input['document'] ?? null;

    // Determine if this is a browser preview (GET with no posted document)
    // vs a programmatic API call expecting JSON.
    $isBrowserPreview = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $document === null;

    if ($document === null) {
        $meta = cmsLoadContentMeta(cmsDb(), $id);
        $document = cmsBuilderLoadDraftDocument($id, $content, $meta);
    }
    $validation = cmsBuilderValidateDocument($document);
    if (empty($validation['ok'])) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'validation_failed', 'issues' => $validation['issues']]);
        exit;
    }

    $renderedHtml = cmsBuilderRenderDocument($validation['document'], $content);

    if ($isBrowserPreview) {
        // Return a full themed HTML page for browser preview
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $meta = cmsLoadContentMeta(cmsDb(), $id);
        $publicHead = cmsGetPublicHeadHtml($content);
        $builderSettings = cmsPageBuilderSettings($meta);

        $templatePath = cmsResolveContentTemplate('public/page.disyl', $meta, 'page');
        $sidebarTemplateKey = cmsSidebarTemplateKeyFromPath($templatePath, 'page');
        $override = isset($meta['builder_show_sidebar_override']) ? (string)$meta['builder_show_sidebar_override'] : '';
        $builderSidebarForceShow = false;
        $cmsGlobalSidebarForce = false;
        $forceHideCustomizedSidebar = false;
        
        if ($override === '1') {
            $builderSidebarForceShow = true;
            // Keep the resolved sidebar key
        } elseif ($override === '0') {
            $forceHideCustomizedSidebar = true;
            $sidebarTemplateKey = '';
        } else {
            // Fallback to global setting
            $cmsGlobalSettings = function_exists('readCmsSettings') ? readCmsSettings() : [];
            if (!empty($cmsGlobalSettings['page_builder_show_sidebar'])) {
                $cmsGlobalSidebarForce = true;
            }
        }

        echo cmsRenderThemeAwareTemplate($templatePath, cmsPublicContext([
            'page_title'   => $content['title'] . ' — Preview',
            'content'      => $content,
            'content_meta' => $meta,
            'content_html' => $renderedHtml,
            'cms_head'     => $publicHead,
            'structured_data' => '',
            'builder_enabled' => true,
            'builder_page_settings' => $builderSettings,
            'force_customized_sidebar' => $builderSidebarForceShow,
            'force_hide_customized_sidebar' => $forceHideCustomizedSidebar,
            'cms_global_sidebar_force' => $cmsGlobalSidebarForce,
            'sidebar_template' => $sidebarTemplateKey,
        ]));
        exit;
    }

    // Programmatic / API response
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => ['html' => $renderedHtml]]);
    exit;
}

function cmsApiBuilderRevisionList(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.revisions');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $published = cmsBuilderLoadDocumentRow($id, 'published');
    $draft = cmsBuilderLoadDocumentRow($id, 'draft');
    $targetId = (int)($published['id'] ?? $draft['id'] ?? 0);
    if ($targetId <= 0) {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }

    $stmt = cmsDb()->prepare(
        "SELECT id, builder_document_id, revision_number, snapshot_json, note, created_by, created_at
         FROM cms_builder_revisions WHERE builder_document_id = :id ORDER BY revision_number DESC"
    );
    $stmt->execute([':id' => $targetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiBuilderRevisionRestore(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.revision_restore');
    app()->csrfEnforce();
    $id = (int)($params['id'] ?? 0);
    $revisionId = (int)($params['revisionId'] ?? 0);
    if ($id <= 0 || $revisionId <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_builder_revisions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $revisionId]);
    $revision = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$revision) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Revision not found']);
        exit;
    }

    // Verify the revision belongs to a document owned by this content
    $docStmt = cmsDb()->prepare(
        "SELECT id FROM cms_builder_documents WHERE content_id = :cid AND id = :did LIMIT 1"
    );
    $docStmt->execute([':cid' => $id, ':did' => (int)($revision['builder_document_id'] ?? 0)]);
    if (!$docStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Revision does not belong to this content']);
        exit;
    }

    $document = cmsBuilderNormalizeDocument((string)($revision['snapshot_json'] ?? ''));
    $actorId = (int)($user['id'] ?? 0);
    $title = trim((string)($content['title'] ?? 'Untitled Page'));

    try {
        $draftId = cmsBuilderPersistDocument($id, $document, 'draft', $title, $actorId);
        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.builder.document.restored', [
                'content_id' => $id,
                'document_id' => $draftId,
                'revision_id' => $revisionId,
                'actor_id' => $actorId,
            ]);
        }
        echo json_encode(['ok' => true, 'data' => ['document_id' => $draftId, 'revision_id' => $revisionId]]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to restore builder revision']);
        exit;
    }
}

function cmsApiBuilderReusableList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.reusable_list');

    try {
        $stmt = cmsDb()->query(
            "SELECT id, name, slug, scope, fragment_json, created_by, updated_by, created_at, updated_at
             FROM cms_builder_reusable_sections
             ORDER BY name ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

function cmsApiBuilderReusableSave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.reusable_save');
    app()->csrfEnforce();
    $input = cmsInput();

    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $slug = trim((string)($input['slug'] ?? ''));
    $scope = trim((string)($input['scope'] ?? 'shared'));
    $fragment = $input['section'] ?? $input['fragment'] ?? null;

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Name is required']);
        exit;
    }
    if ($slug === '') {
        $slug = cmsSlugify($name);
    }
    if (!in_array($scope, ['personal', 'shared', 'global'], true)) {
        $scope = 'shared';
    }

    $fragmentJson = json_encode(cmsBuilderNormalizeDocument($fragment), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($fragmentJson === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid reusable section payload']);
        exit;
    }

    $actorId = (int)($user['id'] ?? 0);
    try {
        if ($id > 0) {
            $stmt = cmsDb()->prepare(
                "UPDATE cms_builder_reusable_sections
                 SET name = :name, slug = :slug, scope = :scope, fragment_json = :fragment_json, updated_by = :updated_by, updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':scope' => $scope,
                ':fragment_json' => $fragmentJson,
                ':updated_by' => $actorId,
                ':id' => $id,
            ]);
        } else {
            $stmt = cmsDb()->prepare(
                "INSERT INTO cms_builder_reusable_sections
                 (name, slug, scope, fragment_json, created_by, updated_by, created_at, updated_at)
                 VALUES (:name, :slug, :scope, :fragment_json, :created_by, :updated_by, NOW(), NOW())"
            );
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':scope' => $scope,
                ':fragment_json' => $fragmentJson,
                ':created_by' => $actorId,
                ':updated_by' => $actorId,
            ]);
            $id = (int)cmsDb()->lastInsertId();
        }

        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.builder.reusable.saved', [
                'reusable_id' => $id,
                'slug' => $slug,
                'actor_id' => $actorId,
            ]);
        }

        echo json_encode(['ok' => true, 'data' => ['id' => $id, 'slug' => $slug]]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save reusable section']);
        exit;
    }
}

function cmsApiBuilderReusableDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.reusable_delete');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    try {
        $stmt = cmsDb()->prepare("DELETE FROM cms_builder_reusable_sections WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to delete reusable section']);
        exit;
    }
}

function cmsApiBuilderTemplateList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.template_list');

    try {
        $stmt = cmsDb()->query(
            "SELECT id, slug, name, category, preview_image, template_json, is_system, created_by, created_at, updated_at
             FROM cms_builder_templates
             ORDER BY is_system DESC, name ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['template_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
            $row['description'] = (string)($payload['description'] ?? '');
            $row['content'] = $payload['content'] ?? null;
            $row['global_styles'] = is_array($payload['global_styles'] ?? null) ? $payload['global_styles'] : null;
        }
        unset($row);
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

function cmsApiBuilderTemplateGet(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.template_list');

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    try {
        $stmt = cmsDb()->prepare(
            "SELECT id, slug, name, category, preview_image, template_json, is_system, created_by, created_at, updated_at
             FROM cms_builder_templates
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Template not found']);
            exit;
        }

        $decoded = json_decode((string)($row['template_json'] ?? ''), true);
        $payload = is_array($decoded) ? $decoded : [];
        $content = $payload['content'] ?? null;
        if (is_array($content) && isset($content['document']) && !isset($content['type'])) {
            $content = $content['document'];
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'id' => (int)$row['id'],
                'slug' => (string)($row['slug'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'category' => (string)($row['category'] ?? ''),
                'preview_image' => $row['preview_image'] ?? null,
                'description' => (string)($payload['description'] ?? ''),
                'content' => $content,
                'global_styles' => is_array($payload['global_styles'] ?? null) ? $payload['global_styles'] : null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

function cmsApiBuilderAutosave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.save');
    app()->csrfEnforce();
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $stmt = cmsDb()->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $content = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$content) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $input = cmsInput();

    // Guard: reject requests where the JSON body could not be parsed
    if (isset($input['_json_error'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request body: ' . $input['_json_error']]);
        exit;
    }

    $document = $input['document'] ?? null;
    if ($document === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'document is required']);
        exit;
    }

    $validation = cmsBuilderValidateDocument($document);
    if (empty($validation['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'validation_failed', 'issues' => $validation['issues']]);
        exit;
    }

    $title = trim((string)($input['title'] ?? $content['title'] ?? 'Untitled Page'));
    $actorId = (int)($user['id'] ?? 0);
    $builderSettings = $input['global_styles'] ?? ($input['builder_page_settings'] ?? $input['builder_settings'] ?? []);
    if (is_string($builderSettings) && trim($builderSettings) !== '') {
        $decodedBuilderSettings = json_decode($builderSettings, true);
        $builderSettings = is_array($decodedBuilderSettings) ? $decodedBuilderSettings : [];
    }
    if (!is_array($builderSettings)) {
        $builderSettings = [];
    }
    $builderSettingsJson = json_encode($builderSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $seoSettings = $input['seo_settings'] ?? null;
    if (is_array($seoSettings)) {
        $seoSettings = json_encode($seoSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (!is_string($seoSettings)) {
        $seoSettings = null;
    }
    $seoSettings = is_string($seoSettings) && trim($seoSettings) !== '' ? $seoSettings : null;

    try {
        $db = cmsDb();
        $documentId = cmsBuilderPersistDocument($id, $validation['document'], 'draft', $title, $actorId);
        $metaToSave = [
            '_builder_enabled' => '1',
            '_builder_page_settings' => $builderSettingsJson,
        ];
        if ($seoSettings !== null) {
            $metaToSave['_builder_seo_settings'] = $seoSettings;
        }
        cmsSaveMeta($db, $id, $metaToSave);
        cmsCacheInvalidateByTags(array_values(array_unique(array_filter(cmsCacheTagsForContent($content)))));
        cmsBuilderCreateRevision($documentId, $validation['document'], $actorId, 'Autosave');
        cmsBuilderPruneRevisions($documentId);
        echo json_encode(['ok' => true, 'data' => ['document_id' => $documentId, 'saved_at' => date('Y-m-d H:i:s')]]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to autosave builder document']);
        exit;
    }
}

function cmsApiBuilderWidgetList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.widget_list');
    echo json_encode(['ok' => true, 'data' => cmsBuilderWidgetRegistry()]);
    exit;
}

function cmsApiBuilderDynamicSources(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.dynamic_sources');
    echo json_encode(['ok' => true, 'data' => cmsBuilderDynamicSources()]);
    exit;
}

function cmsApiBuilderWidgetPosts(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    $input = cmsInput();
    $rows = cmsBuilderFetchPosts([
        'type' => (string)($input['type'] ?? 'post'),
        'limit' => (int)($input['limit'] ?? 10),
        'source_mode' => (string)($input['source_mode'] ?? ''),
        'category_ids' => $input['category_ids'] ?? [],
        'post_ids' => $input['post_ids'] ?? [],
        'order_by' => (string)($input['order_by'] ?? 'date'),
        'order' => (string)($input['order'] ?? 'desc'),
        'include_author' => (($input['include_author'] ?? '') === '1'),
        'include_featured_image' => (($input['include_featured_image'] ?? '') === '1'),
    ]);

    foreach ($rows as &$row) {
        if (!empty($row['featured_image']) && function_exists('cmsResolveUploadUrl')) {
            $row['featured_image_url'] = cmsResolveUploadUrl((string)$row['featured_image']);
        }
    }
    unset($row);

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiBuilderWidgetCategories(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    $input = cmsInput();
    $rows = cmsBuilderFetchCategorySummary([
        'module' => (string)($input['module'] ?? 'post'),
        'count' => (int)($input['count'] ?? 8),
        'order_by' => (string)($input['order_by'] ?? 'name'),
        'show_empty' => (($input['show_empty'] ?? '') === '1'),
    ]);

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiBuilderWidgetTags(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    $input = cmsInput();
    $rows = cmsBuilderFetchTagSummary([
        'count' => (int)($input['count'] ?? 16),
        'order_by' => (string)($input['order_by'] ?? 'count'),
    ]);

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiBuilderWidgetArchives(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    $input = cmsInput();
    $rows = cmsBuilderFetchArchiveSummary([
        'count' => (int)($input['count'] ?? 6),
        'order_by' => (string)($input['order_by'] ?? 'date_desc'),
    ]);

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

function cmsApiBuilderWidgetContext(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    echo json_encode([
        'ok' => true,
        'data' => [
            'social_links' => cmsBuilderSocialLinksData(),
        ],
    ]);
    exit;
}

function cmsApiBuilderWidgetMenus(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.access');

    $menus = cmsGetMenus();
    foreach ($menus as &$menu) {
        $menu['items'] = cmsGetMenuItemsTree((int)($menu['id'] ?? 0));
    }
    unset($menu);

    echo json_encode(['ok' => true, 'data' => $menus]);
    exit;
}

function cmsApiBuilderTemplateSave(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('builder.template_save');
    app()->csrfEnforce();
    $input = cmsInput();

    $name = trim((string)($input['name'] ?? ''));
    $slug = trim((string)($input['slug'] ?? ''));
    $category = trim((string)($input['category'] ?? 'page'));
    $description = trim((string)($input['description'] ?? ''));
    $templateJson = $input['template_json'] ?? null;

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Name is required']);
        exit;
    }
    if ($slug === '') {
        $slug = cmsSlugify($name);
    }

    if ($templateJson === null) {
        $content = $input['content'] ?? null;
        if ($content !== null) {
            $content = cmsBuilderNormalizeDocument($content)['document'] ?? null;
        }
        $globalStyles = $input['global_styles'] ?? null;
        if (is_string($globalStyles) && trim($globalStyles) !== '') {
            $decodedStyles = json_decode($globalStyles, true);
            $globalStyles = is_array($decodedStyles) ? $decodedStyles : null;
        }
        if ($content === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Template content is required']);
            exit;
        }
        $templateJson = [
            'description' => $description,
            'content' => $content,
            'global_styles' => is_array($globalStyles) ? $globalStyles : null,
        ];
    }

    if (!is_string($templateJson)) {
        $templateJson = json_encode($templateJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!is_string($templateJson) || trim($templateJson) === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid template payload']);
        exit;
    }

    $actorId = (int)($user['id'] ?? 0);
    try {
        $stmt = cmsDb()->prepare(
            "INSERT INTO cms_builder_templates (slug, name, category, template_json, is_system, created_by, created_at, updated_at)
             VALUES (:slug, :name, :category, :template_json, 0, :created_by, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), template_json = VALUES(template_json), updated_at = NOW()"
        );
        $stmt->execute([
            ':slug' => $slug,
            ':name' => $name,
            ':category' => $category,
            ':template_json' => $templateJson,
            ':created_by' => $actorId,
        ]);
        $id = (int)cmsDb()->lastInsertId();
        $payload = json_decode($templateJson, true);
        $payload = is_array($payload) ? $payload : [];
        echo json_encode(['ok' => true, 'data' => [
            'id' => $id ?: null,
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'description' => (string)($payload['description'] ?? ''),
            'content' => $payload['content'] ?? null,
            'global_styles' => is_array($payload['global_styles'] ?? null) ? $payload['global_styles'] : null,
        ]]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save template']);
        exit;
    }
}

function cmsApiBuilderTemplateDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('builder.template_delete');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    try {
        $stmt = cmsDb()->prepare("DELETE FROM cms_builder_templates WHERE id = :id AND is_system = 0");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to delete template']);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// CONTENT TYPE REGISTRY API
// ═══════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════
// AI BLOCK GENERATION API (page builder ai_block widget)
// ═══════════════════════════════════════════════════════════════════════

/**
 * POST /api/v1/cms/builder/ai/generate
 *
 * Body: {
 *   prompt: string,           // author-composed prompt template
 *   max_tokens?: int (32..2000, default 320),
 *   temperature?: float (0..1, default 0.5),
 *   preferred_tier?: 'free'|'paid' (default 'free')
 * }
 *
 * Response: {
 *   ok: bool,
 *   content: string,          // generated text (paragraphs separated by \n\n)
 *   prompt_hash: string,      // sha1 of the prompt sent (for the editor's
 *                             //   "prompt changed since last generation" hint)
 *   generated_at: string      // ISO 8601 UTC timestamp
 * }
 *
 * Capability: ai.generate (matches existing cmsApiContentAiSummary pattern).
 * Provider: kernel ai.text.generate@1 capability (declared in module.json).
 */
function cmsApiBuilderAiGenerate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.summary');

    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($body)) {
        $body = [];
    }

    $prompt = trim((string)($body['prompt'] ?? ''));
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Prompt is required']);
        exit;
    }
    if (strlen($prompt) > 4000) {
        $prompt = substr($prompt, 0, 4000);
    }

    $maxTokens = (int)($body['max_tokens'] ?? 320);
    $maxTokens = max(32, min(2000, $maxTokens));

    $temperature = (float)($body['temperature'] ?? 0.5);
    if ($temperature < 0.0) { $temperature = 0.0; }
    if ($temperature > 1.0) { $temperature = 1.0; }

    $tier = (string)($body['preferred_tier'] ?? 'free');
    if (!in_array($tier, ['free', 'paid'], true)) {
        $tier = 'free';
    }

    try {
        $res = app()->cap()->call('ai.text.generate@1', [
            'messages' => [
                ['role' => 'system', 'content' => 'You write concise, clean prose for a CMS page-builder block. Output plain text only — no Markdown, no HTML tags. Separate paragraphs with a blank line.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'json' => false,
            'timeout_ms' => 25000,
            'max_tokens' => $maxTokens,
            'preferred_tier' => $tier,
        ], ['caller_module' => 'cms', 'caller_user' => $user, 'timeout_ms' => 25000]);

        if (empty($res['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI provider error')]);
            exit;
        }

        $content = trim((string)($res['content'] ?? ''));
        // Defensive: strip any HTML the model might have leaked through despite the system prompt.
        $content = strip_tags($content);

        echo json_encode([
            'ok'           => true,
            'content'      => $content,
            'prompt_hash'  => sha1($prompt),
            'generated_at' => gmdate('c'),
        ]);
        exit;
    } catch (Throwable $e) {
        write_log('cms ai_block generate failed: ' . $e->getMessage(), 'error', [
            'user_id' => (int)($user['id'] ?? 0),
        ]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
        exit;
    }
}
