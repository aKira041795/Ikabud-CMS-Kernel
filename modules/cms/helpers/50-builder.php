<?php

declare(strict_types=1);

function cmsBuilderDefaultDocument(array $overrides = []): array
{
    // DB storage envelope: {schema_version, document: {React node tree}}
    // The inner 'document' is a React-compatible DiSyLNode:
    //   {id, type, props, style, children, meta}
    $document = [
        'schema_version' => '1.0',
        'document' => [
            'id' => 'doc_root',
            'type' => 'document',
            'props' => [],
            'style' => [],
            'children' => [],
            'meta' => [],
        ],
    ];

    return array_replace_recursive($document, $overrides);
}

/**
 * Extract a clean React DiSyLNode tree from any input shape.
 *
 * Input can be:
 *  A) React flat node: {id, type:'document', props, style, children, meta}
 *  B) DB wrapper:      {schema_version, document: {id, type, ...}}
 *  C) Hybrid (bug):    {schema_version, id, type, children, document: {id, type, children}}
 *  D) JSON string of any of the above
 *
 * Output is ALWAYS the DB envelope: {schema_version, document: {id, type, props, style, children, meta}}
 * where 'document' is a clean React DiSyLNode with NO extra keys (no kind, version, responsive, visibility).
 */

function cmsBuilderNormalizeDocument(mixed $document): array
{
    // Step 1: Parse JSON string if needed
    if (is_string($document) && trim($document) !== '') {
        $decoded = json_decode($document, true);
        if (is_array($decoded)) {
            $document = $decoded;
        }
    }

    if (!is_array($document)) {
        return cmsBuilderDefaultDocument();
    }

    // Step 2: Extract the React node tree from whatever shape we received
    $reactNode = _cmsBuilderExtractReactNode($document);

    // Step 3: Ensure the node is a valid document root
    if (($reactNode['type'] ?? '') !== 'document') {
        // If it's a section or other node, wrap it in a document
        if (!empty($reactNode['type'])) {
            $reactNode = [
                'id' => 'doc_root',
                'type' => 'document',
                'props' => [],
                'style' => [],
                'children' => [$reactNode],
                'meta' => [],
            ];
        } else {
            return cmsBuilderDefaultDocument();
        }
    }

    // Step 4: Sanitize the root node — ensure all required React fields exist
    $seenNodeIds = [];
    $reactNode = _cmsBuilderSanitizeNode($reactNode, $seenNodeIds, true);

    // Step 5: Return clean DB envelope — ONLY schema_version + document
    return [
        'schema_version' => (string)($document['schema_version'] ?? '1.0'),
        'document' => $reactNode,
    ];
}

/**
 * Extract the React DiSyLNode from any input shape.
 * Returns the node tree {id, type, props, style, children, meta}.
 */

function _cmsBuilderExtractReactNode(array $input): array
{
    // Case A: Has 'document' key with a 'type' field — it's the DB wrapper
    if (isset($input['document']) && is_array($input['document']) && isset($input['document']['type'])) {
        $docNode = $input['document'];
        // But check if the wrapper ALSO has top-level children that the document node lacks
        // (hybrid corruption case C)
        $topChildren = (isset($input['children']) && is_array($input['children'])) ? $input['children'] : [];
        $docChildren = (isset($docNode['children']) && is_array($docNode['children'])) ? $docNode['children'] : [];
        if (count($topChildren) > 0 && count($docChildren) === 0) {
            $docNode['children'] = $topChildren;
        }
        return $docNode;
    }

    // Case B: Has 'type' field directly — it IS the React node
    if (isset($input['type'])) {
        return $input;
    }

    // Case C: Has 'document' key but it's not a proper node — try to use it anyway
    if (isset($input['document']) && is_array($input['document'])) {
        return $input['document'];
    }

    // Case D: Has 'children' but no 'type' — wrap as document
    if (isset($input['children']) && is_array($input['children']) && !empty($input['children'])) {
        return [
            'id' => (string)($input['id'] ?? 'doc_root'),
            'type' => 'document',
            'props' => (isset($input['props']) && is_array($input['props'])) ? $input['props'] : [],
            'style' => (isset($input['style']) && is_array($input['style'])) ? $input['style'] : [],
            'children' => $input['children'],
            'meta' => (isset($input['meta']) && is_array($input['meta'])) ? $input['meta'] : [],
        ];
    }

    // Fallback — empty document
    return cmsBuilderDefaultDocument()['document'];
}

/**
 * Recursively sanitize a React DiSyLNode to ensure all required fields exist.
 * Strips non-React fields (kind, version, responsive, visibility) that were
 * added by the old normalizer but are not part of the React model.
 */

function _cmsBuilderSanitizeNode(array $node, array &$seenNodeIds = [], bool $isRoot = false): array
{
    $type = (string)($node['type'] ?? ($isRoot ? 'document' : 'text'));
    if ($isRoot) {
        $type = 'document';
    }

    $id = trim((string)($node['id'] ?? ''));
    if ($id === '' || isset($seenNodeIds[$id])) {
        $prefix = $isRoot ? 'doc' : 'node';
        do {
            $id = $prefix . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
        } while (isset($seenNodeIds[$id]));
    }
    $seenNodeIds[$id] = true;

    $children = [];
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $children[] = _cmsBuilderSanitizeNode($child, $seenNodeIds, false);
        }
    }

    return [
        'id' => $id,
        'type' => $type,
        'props' => (isset($node['props']) && is_array($node['props'])) ? $node['props'] : [],
        'style' => (isset($node['style']) && is_array($node['style'])) ? $node['style'] : [],
        'children' => array_values($children),
        'meta' => (isset($node['meta']) && is_array($node['meta'])) ? $node['meta'] : [],
    ];
}

function cmsBuilderValidateDocument(mixed $document): array
{
    $normalized = cmsBuilderNormalizeDocument($document);
    $issues = [];
    $root = $normalized['document'] ?? [];

    if (($root['type'] ?? '') !== 'document') {
        $issues[] = ['path' => 'document.type', 'message' => 'Root node type must be document'];
    }
    if (!isset($root['children']) || !is_array($root['children'])) {
        $issues[] = ['path' => 'document.children', 'message' => 'Root children must be an array'];
    }

    // All React component types the builder can produce
    $allowedTypes = [
        'document', 'section', 'columns', 'container', 'row', 'column',
        'heading', 'text', 'button', 'image', 'video', 'icon', 'icon_box',
        'social_icons', 'list', 'testimonial', 'blockquote', 'image_box',
        'logo_grid', 'star_rating', 'call_to_action', 'pricing_table',
        'code_block', 'table', 'slideshow', 'gallery', 'map', 'tabs',
        'accordion', 'counter', 'progress', 'countdown', 'flip_box',
        'toggle', 'search_box', 'form', 'spacer', 'divider', 'alert',
        'anchor', 'breadcrumbs', 'badge', 'stat_card', 'contact_card', 'posts_grid', 'products_grid', 'team_grid',
        'entity_view', 'entity_list',
    ];
    // Also include any widget registry types
    foreach (cmsBuilderWidgetRegistry() as $widget) {
        $wType = (string)($widget['type'] ?? '');
        if ($wType !== '' && !in_array($wType, $allowedTypes, true)) {
            $allowedTypes[] = $wType;
        }
    }

    $walk = function (array $node, string $path) use (&$walk, &$issues, $allowedTypes): void {
        $type = (string)($node['type'] ?? '');
        if ($path !== 'document') {
            if ($type === '') {
                $issues[] = ['path' => $path . '.type', 'message' => 'Node type is required'];
            } elseif (!in_array($type, $allowedTypes, true)) {
                $issues[] = ['path' => $path . '.type', 'message' => 'Unsupported widget type: ' . $type];
            }
        }

        if (isset($node['props']) && !is_array($node['props'])) {
            $issues[] = ['path' => $path . '.props', 'message' => 'Node props must be an object'];
        }
        if (isset($node['style']) && !is_array($node['style'])) {
            $issues[] = ['path' => $path . '.style', 'message' => 'Node style must be an object'];
        }
        if (isset($node['children']) && !is_array($node['children'])) {
            $issues[] = ['path' => $path . '.children', 'message' => 'Node children must be an array'];
            return;
        }

        $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
        if ($type === 'heading') {
            $level = cmsBuilderNormalizeHeadingTag($props['level'] ?? 'h2');
            if (!in_array($level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $issues[] = ['path' => $path . '.props.level', 'message' => 'Heading level must be h1-h6'];
            }
        }

        foreach (($node['children'] ?? []) as $index => $child) {
            if (!is_array($child)) {
                $issues[] = ['path' => $path . '.children[' . $index . ']', 'message' => 'Child node must be an object'];
                continue;
            }
            $walk($child, $path . '.children[' . $index . ']');
        }
    };

    $walk($root, 'document');

    return [
        'ok' => empty($issues),
        'document' => $normalized,
        'issues' => $issues,
    ];
}

function cmsBuilderLoadDocumentRow(int $contentId, string $status = 'draft'): ?array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT * FROM cms_builder_documents WHERE content_id = :content_id AND status = :status ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':content_id' => $contentId,
            ':status' => $status,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function cmsBuilderLoadDraftDocument(int $contentId, ?array $contentRow = null, array $meta = []): array
{
    $row = cmsBuilderLoadDocumentRow($contentId, 'draft');
    if ($row && !empty($row['document_json'])) {
        return cmsBuilderNormalizeDocument((string)$row['document_json']);
    }

    return cmsBuilderDefaultDocument();
}

function cmsBuilderLoadPublishedDocument(int $contentId, ?array $contentRow = null, array $meta = []): array
{
    $row = cmsBuilderLoadDocumentRow($contentId, 'published');
    if ($row && !empty($row['document_json'])) {
        return cmsBuilderNormalizeDocument((string)$row['document_json']);
    }

    return cmsBuilderDefaultDocument();
}

function cmsBuilderNextRevisionNumber(int $builderDocumentId): int
{
    try {
        $stmt = cmsDb()->prepare("SELECT COALESCE(MAX(revision_number), 0) FROM cms_builder_revisions WHERE builder_document_id = :id");
        $stmt->execute([':id' => $builderDocumentId]);
        return ((int)$stmt->fetchColumn()) + 1;
    } catch (Throwable $e) {
        return 1;
    }
}

function cmsBuilderCreateRevision(int $builderDocumentId, array $document, ?int $createdBy = null, ?string $note = null): void
{
    $json = json_encode(cmsBuilderNormalizeDocument($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    try {
        $stmt = cmsDb()->prepare(
            "INSERT INTO cms_builder_revisions (builder_document_id, revision_number, snapshot_json, note, created_by, created_at)
             VALUES (:doc_id, :rev, :snapshot_json, :note, :created_by, NOW())"
        );
        $stmt->execute([
            ':doc_id' => $builderDocumentId,
            ':rev' => cmsBuilderNextRevisionNumber($builderDocumentId),
            ':snapshot_json' => $json,
            ':note' => $note,
            ':created_by' => $createdBy,
        ]);
    } catch (Throwable $e) {
    }
}

/**
 * Recursively apply default prop values to nodes with null props before persisting.
 * The React builder saves null for unedited props; this fills them with defaults
 * from cmsBuilderDefaultProps so the DB always has renderable content matching
 * what the builder displays visually.
 */

function cmsBuilderApplyDefaultProps(array $node): array
{
    $type = (string)($node['type'] ?? '');
    if ($type !== '') {
        // Fill null props with component defaults
        if (isset($node['props']) && is_array($node['props'])) {
            $propDefaults = cmsBuilderDefaultProps($type);
            foreach ($node['props'] as $key => $value) {
                if ($value === null && isset($propDefaults[$key])) {
                    $node['props'][$key] = $propDefaults[$key];
                }
            }
        }
        // Merge default styles into empty/partial style objects so the DB
        // always has renderable style data matching the React builder visuals
        $styleDefaults = cmsBuilderDefaultStyle($type);
        if (!empty($styleDefaults)) {
            $nodeStyle = (isset($node['style']) && is_array($node['style'])) ? $node['style'] : [];
            // Only fill in missing keys; never overwrite user-set values
            foreach ($styleDefaults as $key => $value) {
                if (!isset($nodeStyle[$key]) || $nodeStyle[$key] === null || $nodeStyle[$key] === '') {
                    $nodeStyle[$key] = $value;
                }
            }
            $node['style'] = $nodeStyle;
        }
    }
    if (isset($node['style']) && is_array($node['style'])) {
        $node['style'] = array_filter($node['style'], static fn($v) => $v !== null);
    }
    if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = array_map('cmsBuilderApplyDefaultProps', $node['children']);
    }
    return $node;
}

function cmsBuilderPersistDocument(int $contentId, array $document, string $status, string $title, ?int $actorId = null): int
{
    $normalized = cmsBuilderNormalizeDocument($document);
    // Apply default props to null values so the DB always has renderable content
    if (isset($normalized['document']) && is_array($normalized['document'])) {
        $normalized['document'] = cmsBuilderApplyDefaultProps($normalized['document']);
    }
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode builder document');
    }

    $existing = cmsBuilderLoadDocumentRow($contentId, $status);
    $renderHash = hash('sha256', $json);
    $db = cmsDb();

    if ($existing) {
        $stmt = $db->prepare(
            "UPDATE cms_builder_documents
             SET schema_version = :schema_version,
                 document_version = document_version + 1,
                 title = :title,
                 document_json = :document_json,
                 render_hash = :render_hash,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':schema_version' => (string)($normalized['schema_version'] ?? '1.0'),
            ':title' => $title,
            ':document_json' => $json,
            ':render_hash' => $renderHash,
            ':updated_by' => $actorId,
            ':id' => (int)$existing['id'],
        ]);
        return (int)$existing['id'];
    }

    $stmt = $db->prepare(
        "INSERT INTO cms_builder_documents
         (content_id, schema_version, document_version, status, title, document_json, render_hash, created_by, updated_by, created_at, updated_at)
         VALUES
         (:content_id, :schema_version, 1, :status, :title, :document_json, :render_hash, :created_by, :updated_by, NOW(), NOW())"
    );
    $stmt->execute([
        ':content_id' => $contentId,
        ':schema_version' => (string)($normalized['schema_version'] ?? '1.0'),
        ':status' => $status,
        ':title' => $title,
        ':document_json' => $json,
        ':render_hash' => $renderHash,
        ':created_by' => $actorId,
        ':updated_by' => $actorId,
    ]);

    return (int)$db->lastInsertId();
}

function cmsBuilderNormalizeHeadingTag(mixed $level): string
{
    if (is_int($level) || is_float($level) || (is_string($level) && ctype_digit(trim($level)))) {
        $num = max(1, min(6, (int)$level));
        return 'h' . $num;
    }

    $value = strtolower(trim((string)$level));
    if (preg_match('/^h?([1-6])$/', $value, $matches)) {
        return 'h' . $matches[1];
    }

    return 'h2';
}

function cmsBuilderCssProp(string $prop): string
{
    return strtolower((string)preg_replace('/([A-Z])/', '-$1', $prop));
}

function cmsBuilderStyleAttr(array $style): string
{
    if (empty($style)) {
        return '';
    }

    $parts = [];
    foreach ($style as $prop => $value) {
        if ($value === null || $value === '' || is_array($value)) {
            continue;
        }
        $parts[] = cmsBuilderCssProp((string)$prop) . ':' . (string)$value;
    }

    if (empty($parts)) {
        return '';
    }

    return ' style="' . htmlspecialchars(implode(';', $parts), ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Default styles per node type — mirrors the React component registry defaultStyle.
 * Used to ensure the PHP renderer matches the React builder's visual output
 * when nodes have empty/partial style objects.
 */

function cmsBuilderDefaultStyle(string $type): array
{
    static $defaults = [
        'section'    => ['width' => '100%', 'display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'justifyContent' => 'center', 'padding' => '48px 24px', 'boxSizing' => 'border-box'],
        // Container defaults are contextual in the renderer. Keep the static baseline
        // minimal so preset child containers do not accidentally inherit constrained-width
        // wrapper behavior on the public frontend.
        'container'  => ['boxSizing' => 'border-box'],
        'row'        => ['display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => '24px', 'justifyContent' => 'center', 'alignItems' => 'stretch'],
        'column'     => ['display' => 'flex', 'flexDirection' => 'column', 'gap' => '16px', 'alignItems' => 'stretch', 'boxSizing' => 'border-box'],
        'heading'    => ['fontSize' => '32px', 'fontWeight' => '700', 'lineHeight' => '1.2', 'color' => '#111827', 'textAlign' => 'center', 'width' => '100%'],
        'text'       => ['fontSize' => '16px', 'textAlign' => 'left', 'lineHeight' => '1.6', 'color' => '#4B5563'],
        // Button: backgroundColor and color omitted from defaults — theme CSS classes
        // (.cms-builder-button--{variant}) handle variant colors via CSS custom properties.
        // User-set colors still override via node.style inline output.
        'button'     => ['display' => 'inline-flex', 'padding' => '12px 24px', 'borderRadius' => '8px', 'fontWeight' => '500', 'fontSize' => '14px', 'textDecoration' => 'none', 'cursor' => 'pointer', 'border' => 'none'],
        'image'      => ['width' => '100%', 'height' => 'auto', 'borderRadius' => '8px', 'objectFit' => 'cover', 'overflow' => 'hidden'],
        'video'      => ['width' => '100%', 'borderRadius' => '8px'],
        'spacer'     => ['height' => '48px'],
        'divider'    => ['width' => '100%', 'height' => '1px', 'backgroundColor' => '#E5E7EB', 'margin' => '24px 0'],
        'icon'       => ['display' => 'inline-flex', 'alignItems' => 'center', 'justifyContent' => 'center', 'color' => '#3B82F6'],
        'icon_box'   => ['display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'textAlign' => 'center', 'padding' => '24px', 'gap' => '12px'],
        'tabs'       => ['width' => '100%'],
        'accordion'  => ['width' => '100%'],
        'social_icons' => ['display' => 'flex', 'gap' => '12px', 'alignItems' => 'center'],
        'list'       => ['display' => 'flex', 'flexDirection' => 'column', 'gap' => '8px', 'paddingLeft' => '1.5em'],
        'counter'    => ['textAlign' => 'center', 'padding' => '24px'],
        'progress'   => ['width' => '100%'],
        'testimonial' => ['padding' => '24px', 'backgroundColor' => '#f9fafb', 'borderRadius' => '8px'],
        'form'       => ['width' => '100%', 'maxWidth' => '500px'],
        'slideshow'  => ['width' => '100%'],
        'gallery'    => ['width' => '100%'],
        'alert'      => ['width' => '100%', 'padding' => '16px 20px', 'borderRadius' => '6px', 'fontSize' => '14px', 'lineHeight' => '1.5'],
        'posts_grid' => ['display' => 'grid', 'gridTemplateColumns' => 'repeat(3, 1fr)', 'gap' => '24px', 'width' => '100%'],
        'products_grid' => ['display' => 'grid', 'gridTemplateColumns' => 'repeat(3, 1fr)', 'gap' => '24px', 'width' => '100%'],
        'team_grid' => ['display' => 'grid', 'gridTemplateColumns' => 'repeat(4, 1fr)', 'gap' => '24px', 'width' => '100%'],
        'entity_view' => ['width' => '100%', 'display' => 'block'],
        'entity_list' => ['display' => 'grid', 'gridTemplateColumns' => 'repeat(3, 1fr)', 'gap' => '24px', 'width' => '100%'],
        'pricing_table' => ['padding' => '32px', 'backgroundColor' => '#ffffff', 'borderRadius' => '16px', 'boxShadow' => '0 4px 20px rgba(0,0,0,0.08)', 'textAlign' => 'center', 'position' => 'relative'],
        'call_to_action' => ['padding' => '48px 32px', 'textAlign' => 'center', 'borderRadius' => '12px'],
        'blockquote'   => ['padding' => '32px', 'borderLeft' => '4px solid #3b82f6', 'backgroundColor' => '#f8fafc', 'fontStyle' => 'italic', 'fontSize' => '20px', 'margin' => '0'],
        'toggle'       => ['width' => '100%', 'borderRadius' => '8px', 'border' => '1px solid #e5e7eb'],
        'search_box'   => ['maxWidth' => '500px', 'width' => '100%', 'display' => 'flex', 'gap' => '8px'],
        'breadcrumbs'  => ['fontSize' => '14px', 'color' => '#6b7280'],
        'badge'        => ['display' => 'inline-flex', 'alignItems' => 'center', 'justifyContent' => 'center'],
        'stat_card'    => ['padding' => '24px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'contact_card' => ['padding' => '24px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'countdown'    => ['display' => 'flex', 'justifyContent' => 'center', 'gap' => '16px'],
        'star_rating'  => ['display' => 'inline-flex', 'alignItems' => 'center', 'gap' => '4px'],
        'flip_box'     => ['width' => '300px', 'height' => '300px'],
        'logo_grid'    => ['width' => '100%'],
    ];
    return $defaults[$type] ?? [];
}

function cmsBuilderDefaultProps(string $type): array
{
    static $defaults = [
        'heading'        => ['content' => 'Heading', 'level' => 2],
        'text'           => ['content' => 'Enter your text here...'],
        'button'         => ['content' => 'Click me', 'href' => '#', 'variant' => 'primary', 'size' => 'md'],
        'image'          => ['src' => '', 'alt' => 'Image'],
        'video'          => ['src' => '', 'controls' => true, 'autoplay' => false],
        'slideshow'      => [
            'slides' => [],
            'autoplay' => true,
            'interval' => 5000,
            'showArrows' => true,
            'showDots' => true,
            'height' => '500px',
        ],
        'gallery'        => ['images' => [], 'columns' => 3, 'lightbox' => true, 'gap' => 16, 'layout' => 'grid', 'imageSize' => 'medium', 'aspectRatio' => 'auto'],
        'icon'           => ['icon' => 'Star', 'size' => '24'],
        'icon_box'       => ['icon' => 'Star', 'title' => 'Feature Title', 'description' => 'Feature description goes here'],
        'testimonial'    => ['quote' => 'This is an amazing product!', 'author' => 'John Doe', 'role' => 'CEO, Company Inc.', 'rating' => '5'],
        'blockquote'     => ['content' => 'The only way to do great work is to love what you do.', 'author' => 'Steve Jobs', 'authorTitle' => '', 'style' => 'modern'],
        'call_to_action' => ['title' => 'Ready to Get Started?', 'description' => 'Join thousands of satisfied customers.', 'buttonText' => 'Start Free Trial', 'buttonUrl' => '#', 'secondaryButtonText' => '', 'secondaryButtonUrl' => '', 'layout' => 'horizontal'],
        'alert'          => ['content' => 'This is an important message.', 'alertType' => 'info', 'dismissible' => true],
        'counter'        => ['startValue' => '0', 'endValue' => '100', 'duration' => '2000', 'prefix' => '', 'suffix' => '', 'title' => 'Counter Title'],
        'progress'       => ['value' => '75', 'max' => '100', 'label' => 'Progress', 'showValue' => true, 'color' => '#3B82F6'],
        'toggle'         => ['title' => 'Click to expand', 'content' => 'Toggle content.', 'isOpen' => false],
        'search_box'     => ['placeholder' => 'Search...', 'buttonText' => 'Search', 'showButton' => true, 'searchUrl' => '/cms/search'],
        'badge'          => ['text' => 'Featured', 'variant' => 'primary', 'size' => 'md'],
        'stat_card'      => ['value' => '128', 'label' => 'Happy Customers', 'description' => 'A quick metric you want visitors to notice immediately.', 'accentColor' => '#0f172a'],
        'contact_card'   => ['title' => 'Let\'s Talk', 'description' => 'Share your project, request a quote, or visit our studio.', 'phone' => '+63 900 000 0000', 'email' => 'hello@example.com', 'address' => '123 Market Street, Manila', 'buttonText' => 'Contact Us', 'buttonUrl' => '/cms/contact'],
        'code_block'     => ['code' => '// Code here', 'language' => 'javascript', 'showLineNumbers' => true, 'theme' => 'dark'],
        'pricing_table'  => ['planName' => 'Professional', 'price' => '49', 'currency' => '$', 'period' => '/month', 'features' => [], 'buttonText' => 'Get Started', 'buttonUrl' => '#', 'highlighted' => false, 'ribbon' => ''],
        'countdown'      => ['targetDate' => date('c', time() + 7 * 24 * 60 * 60), 'showDays' => true, 'showHours' => true, 'showMinutes' => true, 'showSeconds' => true, 'labels' => ['days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'], 'expiredMessage' => 'Event has ended!', 'style' => 'boxes'],
        'flip_box'       => ['frontTitle' => 'Front Title', 'frontDescription' => 'Hover to see more', 'backTitle' => 'Back Title', 'backDescription' => 'More details here.', 'backButtonText' => 'Learn More', 'backButtonUrl' => '#'],
        'image_box'      => ['title' => 'Image Title', 'description' => 'A brief description.', 'titlePosition' => 'below'],
        'star_rating'    => ['rating' => 4.5, 'maxRating' => 5, 'showNumber' => true, 'size' => 'medium', 'color' => '#fbbf24'],
        'spacer'         => ['height' => '48px'],
        'anchor'         => ['anchorId' => 'section-1'],
        'map'            => ['mapType' => 'embed', 'embedUrl' => '', 'address' => '', 'latitude' => '14.5995', 'longitude' => '120.9842', 'zoom' => 14, 'markerTitle' => 'Our Location', 'height' => '400px'],
        'table'          => ['rows' => 3, 'columns' => 3],
        'form'           => ['submitText' => 'Submit', 'fields' => []],
        'posts_grid'     => ['postCount' => 3, 'categoryIds' => [], 'showDate' => true, 'showExcerpt' => true, 'excerptLength' => 120, 'showFeaturedImage' => true, 'showAuthor' => false, 'showReadMore' => true, 'readMoreText' => 'Read More', 'gridColumns' => 3, 'postType' => 'post', 'orderBy' => 'date', 'order' => 'desc'],
        'products_grid'  => ['itemCount' => 6, 'categoryIds' => [], 'showImage' => true, 'showTitle' => true, 'showExcerpt' => true, 'excerptLength' => 120, 'showMeta' => true, 'showAction' => true, 'actionText' => 'View Product', 'gridColumns' => 3, 'orderBy' => 'date', 'order' => 'desc', 'emptyMessage' => 'No products found.'],
        'team_grid'      => ['itemCount' => 4, 'teamType' => '', 'departmentIds' => [], 'showImage' => true, 'showTitle' => true, 'showExcerpt' => true, 'excerptLength' => 100, 'showMeta' => false, 'showAction' => true, 'gridColumns' => 4, 'orderBy' => 'name', 'order' => 'asc', 'emptyMessage' => 'No team members found.'],
        'entity_view'    => ['showFeaturedImage' => true, 'showTitle' => true, 'showMeta' => true, 'showTypeLabel' => true, 'showAuthor' => true, 'showDate' => true, 'showPricing' => true, 'showInventory' => true, 'showSku' => true, 'showProgress' => true, 'showLessons' => true, 'showActions' => true, 'showBody' => true],
        'entity_list'    => ['entityType' => 'post', 'itemCount' => 6, 'layout' => 'grid', 'gridColumns' => 3, 'showFeaturedImage' => true, 'showTitle' => true, 'showExcerpt' => true, 'excerptLength' => 120, 'showPricing' => true, 'showInventory' => true, 'emptyMessage' => 'No items found.', 'orderBy' => 'date', 'order' => 'desc'],
    ];
    return $defaults[$type] ?? [];
}

/**
 * Merge default props into a node's props, filling only null/missing values.
 */

function cmsBuilderMergeDefaults(array $props, string $type): array
{
    $defaults = cmsBuilderDefaultProps($type);
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $props) || $props[$key] === null) {
            $props[$key] = $value;
        }
    }
    return $props;
}

function cmsBuilderNodeContent(array $props): string
{
    $content = $props['content'] ?? $props['html'] ?? $props['text'] ?? '';
    return is_string($content) ? $content : '';
}

function cmsBuilderEsc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cmsBuilderAttrString(array $attrs): string
{
    $html = '';
    foreach ($attrs as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= ' ' . $name . '="' . cmsBuilderEsc($value) . '"';
    }
    return $html;
}

function cmsBuilderVisibilityClass(string $visibility): string
{
    return match ($visibility) {
        'desktop' => 'desktop-only',
        'tablet' => 'tablet-only',
        'mobile' => 'mobile-only',
        'desktop-tablet' => 'desktop-tablet',
        'tablet-mobile' => 'tablet-mobile',
        default => preg_replace('/[^a-z0-9-]/i', '', $visibility) ?: '',
    };
}

function cmsBuilderCurrentUser(): ?array
{
    if (!function_exists('cmsCtxUser')) {
        return null;
    }

    try {
        $user = cmsCtxUser();
        return is_array($user) ? $user : null;
    } catch (\Throwable) {
        return null;
    }
}

function cmsBuilderResolveDynamicValue(string $path, array $context = []): mixed
{
    $path = trim($path);
    $path = preg_replace('/^\{\{\s*|\s*\}\}$/', '', $path) ?? $path;
    if ($path === '') {
        return null;
    }

    $dynamicContext = [
        'page' => [
            'id' => $context['id'] ?? null,
            'title' => $context['title'] ?? $context['page_title'] ?? null,
            'slug' => $context['slug'] ?? null,
            'excerpt' => $context['excerpt'] ?? null,
            'featured_image' => $context['featured_image'] ?? $context['featured_image_url'] ?? null,
        ],
        'content' => $context,
        'context' => $context,
        'user' => cmsBuilderCurrentUser(),
    ];

    if (array_key_exists($path, $dynamicContext)) {
        return $dynamicContext[$path];
    }
    if (array_key_exists($path, $context)) {
        return $context[$path];
    }

    $segments = array_values(array_filter(explode('.', $path), static fn(string $segment): bool => $segment !== ''));
    if ($segments === []) {
        return null;
    }

    $value = null;
    $first = array_shift($segments);
    if (array_key_exists($first, $dynamicContext)) {
        $value = $dynamicContext[$first];
    } elseif (array_key_exists($first, $context)) {
        $value = $context[$first];
    } else {
        return null;
    }

    foreach ($segments as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
            continue;
        }
        return null;
    }

    return $value;
}

function cmsBuilderIsEmptyValue(mixed $value): bool
{
    if ($value === null) {
        return true;
    }
    if (is_string($value)) {
        return trim($value) === '';
    }
    if (is_array($value)) {
        return $value === [];
    }
    return false;
}

function cmsBuilderEvaluateCondition(array $props, array $context = []): bool
{
    $conditionalField = trim((string)($props['conditionalField'] ?? ''));
    if ($conditionalField === '') {
        return true;
    }

    if ($conditionalField === 'user_logged_in') {
        return cmsBuilderCurrentUser() !== null;
    }
    if ($conditionalField === 'user_logged_out') {
        return cmsBuilderCurrentUser() === null;
    }
    if ($conditionalField !== 'custom') {
        return true;
    }

    $fieldPath = trim((string)($props['customConditionField'] ?? ''));
    if ($fieldPath === '') {
        return true;
    }

    $actualValue = cmsBuilderResolveDynamicValue($fieldPath, $context);
    $operator = (string)($props['conditionOperator'] ?? 'equals');
    $expectedValue = (string)($props['conditionValue'] ?? '');

    return match ($operator) {
        'not_equals' => (string)$actualValue !== $expectedValue,
        'contains' => str_contains(strtolower(is_scalar($actualValue) ? (string)$actualValue : (json_encode($actualValue) ?: '')), strtolower($expectedValue)),
        'not_empty' => !cmsBuilderIsEmptyValue($actualValue),
        'empty' => cmsBuilderIsEmptyValue($actualValue),
        default => (string)$actualValue === $expectedValue,
    };
}

function cmsBuilderParseCustomAttributes(mixed $rawAttributes): array
{
    if (!is_string($rawAttributes) || trim($rawAttributes) === '') {
        return [];
    }

    $reserved = ['class', 'data-node-id', 'draggable', 'id', 'role', 'style', 'tabindex'];
    $attributes = [];
    $lines = preg_split('/\r\n|\r|\n/', $rawAttributes) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_.:-]*)(?:\s*=\s*(.+))?$/', $line, $matches)) {
            continue;
        }

        $name = $matches[1];
        $lowerName = strtolower($name);
        if (in_array($lowerName, $reserved, true) || str_starts_with($lowerName, 'on')) {
            continue;
        }

        $value = trim((string)($matches[2] ?? ''));
        if ($value === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $attributes;
}

function cmsBuilderNormalizeItems(mixed $items, string $kind = 'text'): array
{
    if (is_string($items) && trim($items) !== '') {
        $decoded = json_decode($items, true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }

    if (!is_array($items)) {
        if (is_string($items) && trim($items) !== '' && $kind === 'text') {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $items) ?: []), static fn(string $item): bool => $item !== ''));
        }
        return [];
    }

    $normalized = [];
    foreach (array_values($items) as $index => $item) {
        if ($kind === 'text') {
            if (is_array($item)) {
                $text = trim((string)($item['text'] ?? $item['label'] ?? $item['content'] ?? ''));
                if ($text !== '') {
                    $normalized[] = $text;
                }
                continue;
            }
            $text = trim((string)$item);
            if ($text !== '') {
                $normalized[] = $text;
            }
            continue;
        }

        if (!is_array($item)) {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            $id = $kind . '_' . ($index + 1);
        }

        if ($kind === 'tabs') {
            $normalized[] = [
                'id' => $id,
                'label' => (string)($item['label'] ?? ('Tab ' . ($index + 1))),
                'content' => (string)($item['content'] ?? ''),
            ];
            continue;
        }

        if ($kind === 'accordion') {
            $normalized[] = [
                'id' => $id,
                'title' => (string)($item['title'] ?? ('Item ' . ($index + 1))),
                'content' => (string)($item['content'] ?? ''),
                'isOpen' => !empty($item['isOpen']),
            ];
            continue;
        }

        if ($kind === 'slides') {
            $normalized[] = [
                'id' => $id,
                'image' => (string)($item['image'] ?? $item['src'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'description' => (string)($item['description'] ?? $item['content'] ?? ''),
                'link' => (string)($item['link'] ?? $item['ctaLink'] ?? ''),
                'ctaText' => (string)($item['ctaText'] ?? $item['buttonText'] ?? ''),
            ];
            continue;
        }

        if ($kind === 'images') {
            $normalized[] = [
                'id' => $id,
                'src' => (string)($item['src'] ?? $item['image'] ?? ''),
                'alt' => (string)($item['alt'] ?? ''),
                'caption' => (string)($item['caption'] ?? $item['title'] ?? ''),
            ];
            continue;
        }

        if ($kind === 'features') {
            $normalized[] = [
                'id' => $id,
                'text' => (string)($item['text'] ?? $item['label'] ?? ''),
                'included' => array_key_exists('included', $item) ? !empty($item['included']) : true,
            ];
            continue;
        }

        $normalized[] = $item;
    }

    return $normalized;
}

function cmsBuilderResolveGlobalStyles(array $document, array $context = []): array
{
    $candidates = [
        $context['global_styles'] ?? null,
        $document['global_styles'] ?? null,
        $document['settings']['global_styles'] ?? null,
        $context['meta']['_builder_page_settings'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($candidate)) {
            return $candidate;
        }
    }

    return [];
}

function cmsBuilderRenderGlobalStyleTag(array $globalStyles, string $scopeClass): string
{
    $colors = isset($globalStyles['colors']) && is_array($globalStyles['colors']) ? $globalStyles['colors'] : [];
    $typography = isset($globalStyles['typography']) && is_array($globalStyles['typography']) ? $globalStyles['typography'] : [];
    $spacing = isset($globalStyles['spacing']) && is_array($globalStyles['spacing']) ? $globalStyles['spacing'] : [];
    $buttons = isset($globalStyles['buttons']) && is_array($globalStyles['buttons']) ? $globalStyles['buttons'] : [];

    $rules = [];
    $root = [];
    if (!empty($colors['background'])) $root[] = 'background-color:' . $colors['background'];
    if (!empty($colors['text'])) $root[] = 'color:' . $colors['text'];
    if (!empty($typography['fontFamily'])) $root[] = 'font-family:' . $typography['fontFamily'];
    if (!empty($typography['baseFontSize'])) $root[] = 'font-size:' . $typography['baseFontSize'];
    if (!empty($typography['lineHeight'])) $root[] = 'line-height:' . $typography['lineHeight'];
    if (!empty($root)) $rules[] = '.' . $scopeClass . '{' . implode(';', $root) . '}';
    if (!empty($typography['headingFontFamily'])) $rules[] = '.' . $scopeClass . ' h1,.' . $scopeClass . ' h2,.' . $scopeClass . ' h3,.' . $scopeClass . ' h4,.' . $scopeClass . ' h5,.' . $scopeClass . ' h6{font-family:' . $typography['headingFontFamily'] . '}';
    if (!empty($typography['h1Size'])) $rules[] = '.' . $scopeClass . ' h1{font-size:' . $typography['h1Size'] . '}';
    if (!empty($typography['h2Size'])) $rules[] = '.' . $scopeClass . ' h2{font-size:' . $typography['h2Size'] . '}';
    if (!empty($typography['h3Size'])) $rules[] = '.' . $scopeClass . ' h3{font-size:' . $typography['h3Size'] . '}';
    if (!empty($typography['h4Size'])) $rules[] = '.' . $scopeClass . ' h4{font-size:' . $typography['h4Size'] . '}';
    if (!empty($spacing['sectionPadding'])) $rules[] = '.' . $scopeClass . ' .cms-builder-node--section{padding:' . $spacing['sectionPadding'] . '}';
    if (!empty($spacing['containerMaxWidth'])) $rules[] = '.' . $scopeClass . ' .cms-builder-node--container{max-width:' . $spacing['containerMaxWidth'] . ';margin-left:auto;margin-right:auto;width:100%}';
    if (!empty($spacing['elementGap'])) $rules[] = '.' . $scopeClass . ' .cms-builder-node--row,.' . $scopeClass . ' .cms-builder-node--column,.' . $scopeClass . ' .cms-builder-node--container{gap:' . $spacing['elementGap'] . '}';
    if (!empty($buttons['borderRadius']) || !empty($buttons['paddingY']) || !empty($buttons['paddingX']) || !empty($buttons['fontSize'])) {
        $buttonRules = [];
        if (!empty($buttons['borderRadius'])) $buttonRules[] = 'border-radius:' . $buttons['borderRadius'];
        if (!empty($buttons['paddingY']) || !empty($buttons['paddingX'])) $buttonRules[] = 'padding:' . ($buttons['paddingY'] ?? '12px') . ' ' . ($buttons['paddingX'] ?? '24px');
        if (!empty($buttons['fontSize'])) $buttonRules[] = 'font-size:' . $buttons['fontSize'];
        $rules[] = '.' . $scopeClass . ' .cms-builder-button{' . implode(';', $buttonRules) . '}';
    }

    // Animation CSS is provided by the theme stylesheet (style.css) globally.
    // No need to duplicate it inline — the theme covers all entrance + hover animations.

    $allCss = implode('', $rules);
    return empty($allCss) ? '' : '<style>' . $allCss . '</style>';
}

function cmsBuilderRenderNodeChildren(array $node, array $context = []): string
{
    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];

    // Propagate parent layout info so child containers can detect when they
    // are flex/grid items and skip constrained-width defaults.  This mirrors
    // the React builder where the wrapper <div> absorbs flex-child behaviour
    // while the inner renderer applies its own styles independently.
    $parentType = (string)($node['type'] ?? '');
    $parentStyle = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];
    $parentDisplay = (string)($parentStyle['display'] ?? '');
    // Also account for default styles (e.g. rows default to display:flex)
    if ($parentDisplay === '') {
        $parentDefaults = cmsBuilderDefaultStyle($parentType);
        $parentDisplay = (string)($parentDefaults['display'] ?? '');
    }
    $childContext = array_merge($context, [
        '_parent_type'    => $parentType,
        '_parent_display' => $parentDisplay,
    ]);

    $html = '';
    foreach ($children as $child) {
        if (is_array($child)) {
            $html .= cmsBuilderRenderNode($child, $childContext);
        }
    }
    return $html;
}

function cmsBuilderRenderNode(array $node, array $context = []): string
{
    $type = (string)($node['type'] ?? 'text');
    $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
    $props = cmsBuilderMergeDefaults($props, $type);
    if (!cmsBuilderEvaluateCondition($props, $context)) {
        return '';
    }

    $visibility = (string)($props['visibility'] ?? '');
    if ($visibility === 'hidden') {
        return '';
    }

    $rawStyle = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];
    // Strip null/empty values so they don't accidentally override component defaults
    // (React stores null for unset style properties; we must treat them as "not set").
    $nodeStyle = array_filter($rawStyle, static fn($v) => $v !== null && $v !== '');
    // Merge component default styles with node-specific overrides.
    // Default styles fill in gaps; node styles always win.
    $style = array_merge(cmsBuilderDefaultStyle($type), $nodeStyle);
    if (($style['display'] ?? null) === 'none') {
        return '';
    }

    $children = cmsBuilderRenderNodeChildren($node, $context);
    $cssClass = 'cms-builder-node cms-builder-node--' . preg_replace('/[^a-z0-9_-]/i', '-', $type);

    // ── Custom classes (Advanced tab) ────────────────────────────────────
    $customClasses = trim((string)($props['customClasses'] ?? ''));
    if ($customClasses !== '') {
        // Sanitize: only allow word chars, hyphens, spaces
        $customClasses = preg_replace('/[^a-zA-Z0-9_ -]/', '', $customClasses);
        $customClasses = trim((string)preg_replace('/\s+/', ' ', (string)$customClasses));
        if ($customClasses !== '') {
            $cssClass .= ' ' . $customClasses;
        }
    }

    // ── Responsive visibility (Advanced tab) ─────────────────────────────
    if ($visibility !== '' && $visibility !== 'all') {
        $visibilityClass = cmsBuilderVisibilityClass($visibility);
        if ($visibilityClass !== '') {
            $cssClass .= ' cms-builder-visible--' . $visibilityClass;
        }
    }

    // ── Entrance / hover animations (Advanced tab) ──────────────────────
    $entranceAnim = (string)($props['entranceAnimation'] ?? '');
    $hoverAnim = (string)($props['hoverAnimation'] ?? '');

    $attrs = [
        'class' => $cssClass,
        'data-node-id' => (string)($node['id'] ?? ''),
    ];

    // Custom ID (Advanced tab)
    $customId = trim((string)($props['customId'] ?? ''));
    if ($customId !== '') {
        $attrs['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $customId);
    }

    $attrs = array_merge($attrs, cmsBuilderParseCustomAttributes($props['customAttributes'] ?? null));

    // Animation data attributes (via centralized definitions) for builder-public.js
    $animDuration = (string)($props['animationDuration'] ?? '');
    $animDelay = (string)($props['animationDelay'] ?? '');
    $animAttrs = cmsBuilderGetAnimationAttrs($entranceAnim, $animDuration, $animDelay, $hoverAnim);
    $attrs = array_merge($attrs, $animAttrs);

    // Prevent duplicate style attributes: merge animation inline vars into node style.
    // Browsers may ignore one duplicated style attribute, which can strip layout/alignment
    // styles when animation settings are enabled.
    if (isset($attrs['style']) && is_string($attrs['style']) && $attrs['style'] !== '') {
        $inlineDeclarations = explode(';', $attrs['style']);
        foreach ($inlineDeclarations as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $declaration, 2));
            if ($prop !== '' && $value !== '') {
                $style[$prop] = $value;
            }
        }
        unset($attrs['style']);
    }

    // Dispatch to per-widget render function
    $renderers = cmsBuilderWidgetRenderers();
    $fn = $renderers[$type] ?? 'cmsRenderWidget_default';
    if (is_callable($fn)) {
        return $fn($props, $style, $attrs, $children, $node, $context);
    }
    return cmsRenderWidget_default($props, $style, $attrs, $children, $node, $context);
}

/**
 * Recursively collect responsive style overrides (tablet/mobile) from all nodes.
 * Returns array of ['nodeId' => string, 'tablet' => array, 'mobile' => array].
 */

function cmsBuilderCollectResponsiveStyles(array $node, array &$collected): void
{
    $id = (string)($node['id'] ?? '');
    $style = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];
    $tablet = isset($style['tablet']) && is_array($style['tablet']) ? $style['tablet'] : [];
    $mobile = isset($style['mobile']) && is_array($style['mobile']) ? $style['mobile'] : [];

    if ($id !== '' && (!empty($tablet) || !empty($mobile))) {
        $collected[] = ['nodeId' => $id, 'tablet' => $tablet, 'mobile' => $mobile];
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    foreach ($children as $child) {
        if (is_array($child)) {
            cmsBuilderCollectResponsiveStyles($child, $collected);
        }
    }
}

/**
 * Generate a <style> tag with media queries for tablet/mobile responsive overrides.
 * Breakpoints match the theme: tablet ≤1024px, mobile ≤640px.
 */

function cmsBuilderRenderResponsiveCss(array $documentNode, string $scopeClass): string
{
    $collected = [];
    cmsBuilderCollectResponsiveStyles($documentNode, $collected);
    if (empty($collected)) {
        return '';
    }

    $tabletRules = [];
    $mobileRules = [];

    foreach ($collected as $entry) {
        $selector = '.' . $scopeClass . ' [data-node-id="' . htmlspecialchars($entry['nodeId'], ENT_QUOTES, 'UTF-8') . '"]';

        if (!empty($entry['tablet'])) {
            $props = [];
            foreach ($entry['tablet'] as $prop => $value) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $props[] = cmsBuilderCssProp((string)$prop) . ':' . (string)$value . ' !important';
            }
            if (!empty($props)) {
                $tabletRules[] = $selector . '{' . implode(';', $props) . '}';
            }
        }

        if (!empty($entry['mobile'])) {
            $props = [];
            foreach ($entry['mobile'] as $prop => $value) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $props[] = cmsBuilderCssProp((string)$prop) . ':' . (string)$value . ' !important';
            }
            if (!empty($props)) {
                $mobileRules[] = $selector . '{' . implode(';', $props) . '}';
            }
        }
    }

    if (empty($tabletRules) && empty($mobileRules)) {
        return '';
    }

    $css = '<style>';
    if (!empty($tabletRules)) {
        $css .= '@media(max-width:1024px){' . implode('', $tabletRules) . '}';
    }
    if (!empty($mobileRules)) {
        $css .= '@media(max-width:640px){' . implode('', $mobileRules) . '}';
    }
    $css .= '</style>';

    return $css;
}

function cmsBuilderRenderDocument(array $document, array $context = []): string
{
    $normalized = cmsBuilderNormalizeDocument($document);
    $scopeId = (int)($context['id'] ?? 0);
    $scopeClass = 'cms-builder-scope-' . ($scopeId > 0 ? (string)$scopeId : substr(md5(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'builder'), 0, 8));
    $globalStyles = cmsBuilderResolveGlobalStyles($normalized, $context);
    $html = cmsBuilderRenderNode($normalized['document'] ?? [], $context);
    if (trim($html) === '') {
        return '';
    }
    // Generate responsive CSS for tablet/mobile overrides stored per-node
    $responsiveCss = cmsBuilderRenderResponsiveCss($normalized['document'] ?? [], $scopeClass);
    return cmsBuilderRenderGlobalStyleTag($globalStyles, $scopeClass)
        . $responsiveCss
        . '<div class="cms-builder-document ' . cmsBuilderEsc($scopeClass) . '">'
        . $html
        . '</div>';
}

function cmsBuilderDocumentToEditorBlocks(array $document): array
{
    $normalized = cmsBuilderNormalizeDocument($document);
    $children = $normalized['document']['children'] ?? [];
    $blocks = [];

    foreach ($children as $node) {
        if (!is_array($node)) {
            continue;
        }

        $type = (string)($node['type'] ?? 'text');
        $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];

        if ($type === 'text') {
            $blocks[] = [
                'type' => 'paragraph',
                'content' => trim(strip_tags((string)($props['html'] ?? ''))),
            ];
        } elseif ($type === 'heading') {
            $blocks[] = [
                'type' => 'heading',
                'content' => (string)($props['text'] ?? ''),
                'level' => (string)($props['level'] ?? 'h2'),
            ];
        } elseif ($type === 'image') {
            $blocks[] = [
                'type' => 'image',
                'url' => (string)($props['url'] ?? ''),
                'alt' => (string)($props['alt'] ?? ''),
                'caption' => (string)($props['caption'] ?? ''),
            ];
        } elseif ($type === 'button') {
            $blocks[] = [
                'type' => 'button',
                'label' => (string)($props['text'] ?? ''),
                'url' => (string)($props['url'] ?? ''),
                'variant' => (string)($props['style'] ?? 'primary'),
            ];
        } elseif ($type === 'divider') {
            $blocks[] = [
                'type' => 'separator',
            ];
        } elseif ($type === 'spacer') {
            $height = (string)($props['height'] ?? '48px');
            if (!str_ends_with($height, 'px')) {
                $height .= 'px';
            }
            $blocks[] = [
                'type' => 'spacer',
                'height' => $height,
            ];
        } else {
            $block = $props;
            $block['type'] = $type;
            $blocks[] = $block;
        }
    }

    return $blocks;
}

function cmsBuilderEditorBlocksToDocument(array $blocks, string $title = ''): array
{
    $children = [];

    foreach (array_values($blocks) as $index => $block) {
        if (!is_array($block)) {
            continue;
        }

        $type = (string)($block['type'] ?? 'paragraph');
        $nodeType = match ($type) {
            'paragraph' => 'text',
            'separator' => 'divider',
            default => $type,
        };

        $props = [];
        if ($nodeType === 'text') {
            $props = [
                'html' => nl2br(htmlspecialchars((string)($block['content'] ?? $block['text'] ?? ''), ENT_QUOTES, 'UTF-8')),
            ];
        } elseif ($nodeType === 'heading') {
            $props = [
                'text' => (string)($block['content'] ?? $block['text'] ?? ''),
                'level' => (string)($block['level'] ?? 'h2'),
            ];
        } elseif ($nodeType === 'image') {
            $props = [
                'url' => (string)($block['url'] ?? ''),
                'alt' => (string)($block['alt'] ?? ''),
                'caption' => (string)($block['caption'] ?? ''),
            ];
        } elseif ($nodeType === 'button') {
            $props = [
                'text' => (string)($block['label'] ?? $block['text'] ?? ''),
                'url' => (string)($block['url'] ?? ''),
                'style' => (string)($block['variant'] ?? 'primary'),
            ];
        } elseif ($nodeType === 'divider') {
            $props = [
                'style' => 'solid',
            ];
        } elseif ($nodeType === 'spacer') {
            $props = [
                'height' => (string)($block['height'] ?? '48px'),
            ];
        } else {
            $props = $block;
        }

        $children[] = [
            'id' => 'editor_' . $index,
            'type' => $nodeType,
            'kind' => in_array($nodeType, ['section', 'container', 'columns'], true) ? 'section' : 'widget',
            'version' => 1,
            'props' => $props,
            'style' => [],
            'responsive' => [],
            'visibility' => [],
            'meta' => ['editor_block_type' => $type],
            'children' => [],
        ];
    }

    return cmsBuilderDefaultDocument([
        'document' => [
            'props' => ['title' => $title],
            'children' => $children,
        ],
    ]);
}

function cmsPageBuilderEnabled(array $meta): bool
{
    $raw = $meta['_builder_enabled'] ?? null;
    if (is_bool($raw)) {
        return $raw;
    }
    $value = strtolower(trim((string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function cmsPageBuilderSettings(array $meta): array
{
    $raw = $meta['_builder_page_settings'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cmsPageBuilderContentJson(array $meta, mixed $fallback = null): ?string
{
    $builderContent = cmsNormalizeBlocksJson($meta['_builder_content'] ?? null);
    if ($builderContent !== null) {
        return $builderContent;
    }
    return cmsNormalizeBlocksJson($fallback);
}

function cmsPageBuilderBlocks(array $contentRow): array
{
    $meta = isset($contentRow['meta']) && is_array($contentRow['meta']) ? $contentRow['meta'] : [];
    $json = cmsPageBuilderContentJson($meta, $contentRow['blocks_json'] ?? null);
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function cmsPageBuilderRenderedHtml(array $contentRow): string
{
    $contentId = (int)($contentRow['id'] ?? 0);
    if ($contentId > 0) {
        // Only published builder documents should be rendered publicly.
        // Never fall back to draft — that would leak unpublished content.
        $published = cmsBuilderLoadDocumentRow($contentId, 'published');
        if ($published && !empty($published['document_json'])) {
            return cmsBuilderRenderDocument(cmsBuilderNormalizeDocument((string)$published['document_json']), $contentRow);
        }
    }

    return '';
}

function cmsContentRenderedHtml(array $contentRow): string
{
    $builderRendered = cmsPageBuilderRenderedHtml($contentRow);
    if (trim($builderRendered) !== '') {
        return $builderRendered;
    }
    $blocksJson = $contentRow['blocks_json'] ?? null;
    $blocksJson = is_string($blocksJson) ? $blocksJson : null;
    $rendered = cmsRenderBlocks($blocksJson);
    if (trim($rendered) !== '') {
        return $rendered;
    }
    return (string)($contentRow['body'] ?? '');
}

// ── Capability handlers (module-manager bridge) ───────────────────────
// The kernel's module-manager registers module-declared capabilities by looking
// for either:
//  - a $capability_handlers map
//  - or a function named: <moduleId>_cap_<sanitizedCapabilityId>
//
// For example:
//   cms.content.get@1 -> cms_cap_cms_content_get_1

function cmsBuilderWidgetRegistry(): array
{
    $defaults = [
        // Content
        ['type' => 'heading',       'label' => 'Heading',       'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Title text (h1–h6)',       'icon' => 'heading'],
        ['type' => 'text',          'label' => 'Text',          'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Rich text paragraph',      'icon' => 'text'],
        ['type' => 'image',         'label' => 'Image',         'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Image with caption',       'icon' => 'image'],
        ['type' => 'button',        'label' => 'Button',        'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Call-to-action button',    'icon' => 'button'],
        ['type' => 'divider',       'label' => 'Divider',       'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Horizontal rule',          'icon' => 'divider'],
        ['type' => 'quote',         'label' => 'Quote',         'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Blockquote element',       'icon' => 'quote'],
        ['type' => 'list',          'label' => 'List',          'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Ordered or unordered list','icon' => 'list'],
        // Layout
        ['type' => 'section',       'label' => 'Section',       'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Flex/Grid section (nestable)',  'icon' => 'section'],
        ['type' => 'columns',       'label' => 'Columns',       'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Flex/Grid column layout',       'icon' => 'columns'],
        ['type' => 'container',     'label' => 'Container',     'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Nestable flex/grid container',  'icon' => 'container'],
        ['type' => 'spacer',        'label' => 'Spacer',        'kind' => 'widget',  'category' => 'layout',   'supports_children' => false, 'description' => 'Vertical spacing',          'icon' => 'spacer'],
        // Media
        ['type' => 'gallery',       'label' => 'Gallery',       'kind' => 'widget',  'category' => 'media',    'supports_children' => false, 'description' => 'Image gallery grid',        'icon' => 'gallery'],
        ['type' => 'embed',         'label' => 'Embed',         'kind' => 'widget',  'category' => 'media',    'supports_children' => false, 'description' => 'YouTube, Vimeo, etc.',      'icon' => 'embed'],
        // Dynamic
        ['type' => 'dynamic_field', 'label' => 'Dynamic Field', 'kind' => 'widget',  'category' => 'dynamic',  'supports_children' => false, 'description' => 'Data-bound content',        'icon' => 'dynamic'],
        ['type' => 'posts_list',    'label' => 'Posts List',     'kind' => 'widget',  'category' => 'dynamic',  'supports_children' => false, 'description' => 'Recent posts feed',         'icon' => 'posts'],
        // Advanced
        ['type' => 'html',          'label' => 'HTML',          'kind' => 'widget',  'category' => 'advanced', 'supports_children' => false, 'description' => 'Custom HTML code',          'icon' => 'html'],
    ];

    $items = app()->hooks()->filter('cms.builder.widgets', $defaults);
    return is_array($items) ? array_values($items) : $defaults;
}

function cmsBuilderDynamicSources(): array
{
    $defaults = [
        ['id' => 'page.title', 'label' => 'Page Title', 'type' => 'string'],
        ['id' => 'page.slug', 'label' => 'Page Slug', 'type' => 'string'],
        ['id' => 'page.excerpt', 'label' => 'Page Excerpt', 'type' => 'string'],
        ['id' => 'page.featured_image', 'label' => 'Featured Image', 'type' => 'string'],
    ];

    $items = app()->hooks()->filter('cms.builder.dynamic_sources', $defaults);
    return is_array($items) ? array_values($items) : $defaults;
}

function cmsBuilderTemplateRegistry(): array
{
    $dbTemplates = [];
    try {
        $stmt = cmsDb()->query(
            "SELECT id, slug, name, category, preview_image, template_json, is_system, created_by, created_at, updated_at
             FROM cms_builder_templates ORDER BY is_system DESC, name ASC"
        );
        $dbTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $dbTemplates = [];
    }

    $items = app()->hooks()->filter('cms.builder.templates', $dbTemplates);
    return is_array($items) ? array_values($items) : $dbTemplates;
}

/**
 * Get extra <head> HTML for public pages from all listeners.
 */

function cmsBuilderPruneRevisions(int $builderDocumentId, int $maxRevisions = 50): void
{
    if ($maxRevisions < 1) return;

    try {
        $db = cmsDb();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM cms_builder_revisions WHERE builder_document_id = :id");
        $countStmt->execute([':id' => $builderDocumentId]);
        $total = (int)$countStmt->fetchColumn();

        if ($total <= $maxRevisions) return;

        // Find the minimum revision_number to keep
        $stmt = $db->prepare(
            "SELECT revision_number FROM cms_builder_revisions
             WHERE builder_document_id = :id
             ORDER BY revision_number DESC
             LIMIT 1 OFFSET :offset"
        );
        $stmt->bindValue(':id', $builderDocumentId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $maxRevisions - 1, PDO::PARAM_INT);
        $stmt->execute();
        $cutoff = (int)$stmt->fetchColumn();

        if ($cutoff > 0) {
            $db->prepare(
                "DELETE FROM cms_builder_revisions WHERE builder_document_id = :id AND revision_number < :cutoff"
            )->execute([':id' => $builderDocumentId, ':cutoff' => $cutoff]);
        }
    } catch (Throwable $e) {
        // Non-critical — log and continue
        app()->log('warning', 'Revision prune failed: ' . $e->getMessage());
    }
}

/**
 * Prune old content revisions (classic revisions table).
 */
