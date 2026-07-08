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

    // Step 4b: Split legacy overloaded containers into constrained wrappers
    // and unconstrained layout containers so old documents render consistently
    // with the new builder semantics.
    $reactNode = _cmsBuilderMigrateContainerSemantics($reactNode, $seenNodeIds);

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

function _cmsBuilderGenerateNodeId(array &$seenNodeIds, string $prefix = 'node'): string
{
    do {
        $id = $prefix . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
    } while (isset($seenNodeIds[$id]));
    $seenNodeIds[$id] = true;
    return $id;
}

function _cmsBuilderNodeHasValue(mixed $value): bool
{
    return $value !== null && $value !== '';
}

function _cmsBuilderContainerHasConstraint(array $style): bool
{
    foreach (['maxWidth', 'margin', 'marginLeft', 'marginRight'] as $key) {
        if (array_key_exists($key, $style) && _cmsBuilderNodeHasValue($style[$key])) {
            return true;
        }
    }

    return false;
}

function _cmsBuilderLooksLikeLayoutContainer(array $node): bool
{
    $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
    $style = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];

    if (_cmsBuilderNodeHasValue($props['layoutMode'] ?? null) || _cmsBuilderNodeHasValue($props['presetId'] ?? null)) {
        return true;
    }

    $display = trim((string)($style['display'] ?? ''));
    if (in_array($display, ['flex', 'grid'], true)) {
        return true;
    }

    foreach (['flexDirection', 'flexWrap', 'gap', 'gridTemplateColumns', 'gridTemplateRows', 'justifyContent', 'alignItems', 'alignContent', 'placeItems', 'placeContent', 'flex', 'flexBasis', 'flexGrow', 'flexShrink', 'order', 'alignSelf'] as $key) {
        if (array_key_exists($key, $style) && _cmsBuilderNodeHasValue($style[$key])) {
            return true;
        }
    }

    return false;
}

function _cmsBuilderMigrateContainerSemantics(array $node, array &$seenNodeIds, ?string $parentType = null): array
{
    $type = (string)($node['type'] ?? '');
    $style = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];

    if ($type === 'container' && _cmsBuilderLooksLikeLayoutContainer($node) && !_cmsBuilderContainerHasConstraint($style)) {
        $node['type'] = 'layout_container';
        $type = 'layout_container';
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    $migratedChildren = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }
        $migratedChildren[] = _cmsBuilderMigrateContainerSemantics($child, $seenNodeIds, $type !== '' ? $type : $parentType);
    }
    $node['children'] = array_values($migratedChildren);

    if ($type === 'layout_container' && $parentType === 'section') {
        return [
            'id' => _cmsBuilderGenerateNodeId($seenNodeIds),
            'type' => 'container',
            'props' => [],
            'style' => [],
            'children' => [$node],
            'meta' => [],
        ];
    }

    return $node;
}

function cmsBuilderValidateDocument(mixed $document): array
{
    $normalized = cmsBuilderNormalizeDocument($document);
    $issues = [];
    $root = $normalized['document'] ?? [];
    $nestingConstraints = cmsBuilderLoadNestingConstraints();

    if (($root['type'] ?? '') !== 'document') {
        $issues[] = ['path' => 'document.type', 'message' => 'Root node type must be document'];
    }
    if (!isset($root['children']) || !is_array($root['children'])) {
        $issues[] = ['path' => 'document.children', 'message' => 'Root children must be an array'];
    }

    // All React component types the builder can produce
    $allowedTypes = [
        'document', 'section', 'columns', 'container', 'layout_container', 'row', 'column',
        'heading', 'text', 'button', 'image', 'video', 'icon', 'icon_box',
        'social_icons', 'list', 'testimonial', 'blockquote', 'image_box',
        'logo_grid', 'star_rating', 'call_to_action', 'pricing_table',
        'code_block', 'table', 'slideshow', 'gallery', 'map', 'tabs',
        'accordion', 'counter', 'progress', 'countdown', 'flip_box',
        'toggle', 'search_box', 'form', 'spacer', 'divider', 'alert',
        'anchor', 'breadcrumbs', 'badge', 'stat_card', 'contact_card', 'posts_grid', 'products_grid', 'team_grid',
        'entity_view', 'entity_list', 'html_embed',
    ];
    // Also include any widget registry types (iterate keys since registry is type => callback)
    foreach (array_keys(cmsBuilderWidgetRenderers()) as $wType) {
        if (!in_array($wType, $allowedTypes, true)) {
            $allowedTypes[] = $wType;
        }
    }

    $walk = function (array $node, string $path, ?string $parentType = null) use (&$walk, &$issues, $allowedTypes, $nestingConstraints): void {
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

        if ($parentType !== null && $parentType !== '') {
            $parentRule = $nestingConstraints[$parentType]['allowed_children'] ?? null;
            if (is_array($parentRule) && $type !== '' && !in_array($type, $parentRule, true)) {
                $issues[] = ['path' => $path . '.type', 'message' => "Node type '{$type}' is not allowed inside '{$parentType}'"];
            } elseif (!is_array($parentRule)) {
                $childRule = $nestingConstraints[$type]['allowed_parents'] ?? null;
                if (is_array($childRule) && $type !== '' && !in_array($parentType, $childRule, true)) {
                    $issues[] = ['path' => $path . '.type', 'message' => "Node type '{$type}' requires one of these parents: " . implode(', ', $childRule)];
                }
            }
        }

        foreach (($node['children'] ?? []) as $index => $child) {
            if (!is_array($child)) {
                $issues[] = ['path' => $path . '.children[' . $index . ']', 'message' => 'Child node must be an object'];
                continue;
            }
            $walk($child, $path . '.children[' . $index . ']', $type);
        }
    };

    $walk($root, 'document');

    return [
        'ok' => empty($issues),
        'document' => $normalized,
        'issues' => $issues,
    ];
}

function cmsBuilderLoadNestingConstraints(): array
{
    static $cache = [];

    $themeDir = cmsBuilderConstraintThemeDir();
    if ($themeDir === null) {
        return [];
    }

    if (array_key_exists($themeDir, $cache)) {
        return $cache[$themeDir];
    }

    $constraints = [];
    $pageSchemaPath = $themeDir . '/page-composition.schema.json';
    if (is_file($pageSchemaPath)) {
        $schema = json_decode((string)@file_get_contents($pageSchemaPath), true);
        if (is_array($schema)) {
            $topLevelChildren = array_values(array_filter(array_map('strval', is_array($schema['allowed_top_level_children'] ?? null) ? $schema['allowed_top_level_children'] : [])));
            if ($topLevelChildren !== []) {
                $constraints['document'] = ['allowed_children' => $topLevelChildren];
            }
        }
    }

    // Read ALL block definition categories (not just layout)
    $blockDefinitionsDir = $themeDir . '/block-definitions';
    if (is_dir($blockDefinitionsDir)) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($blockDefinitionsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'json' || $file->getFilename() === 'block-definition.schema.json') {
                continue;
            }
            $definition = json_decode((string)@file_get_contents($file->getPathname()), true);
            if (!is_array($definition) || empty($definition['type'])) {
                continue;
            }

            $blockType = (string)$definition['type'];
            $allowedChildren = is_array($definition['allowed_children'] ?? null)
                ? array_values(array_filter(array_map('strval', $definition['allowed_children'])))
                : null;
            $allowedParents = is_array($definition['allowed_parents'] ?? null)
                ? array_values(array_filter(array_map('strval', $definition['allowed_parents'])))
                : null;

            if ($allowedChildren !== null || $allowedParents !== null) {
                $constraints[$blockType] = [];
                if ($allowedChildren !== null) {
                    $constraints[$blockType]['allowed_children'] = $allowedChildren;
                }
                if ($allowedParents !== null) {
                    $constraints[$blockType]['allowed_parents'] = $allowedParents;
                }
            }
        }
    }

    $cache[$themeDir] = $constraints;
    return $constraints;
}

function cmsBuilderConstraintThemeDir(): ?string
{
    $candidates = [];

    if (function_exists('cmsActiveTheme')) {
        $activeTheme = trim((string)cmsActiveTheme());
        if ($activeTheme !== '') {
            $themesPath = function_exists('cmsThemesPath')
                ? cmsThemesPath()
                : rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage'), '/') . '/cms-themes';
            $candidates[] = $themesPath . '/' . $activeTheme;
        }
    }

    if (defined('CMS_THEME_SYMLINK')) {
        $symlinkTarget = realpath((string)CMS_THEME_SYMLINK);
        if (is_string($symlinkTarget) && $symlinkTarget !== '') {
            $candidates[] = $symlinkTarget;
        }
    }

    $fallbackArk = rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage'), '/') . '/cms-themes/ark';
    $candidates[] = $fallbackArk;

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_dir($candidate) && (is_file($candidate . '/page-composition.schema.json') || is_dir($candidate . '/block-definitions'))) {
            return $candidate;
        }
    }

    return null;
}

function cmsBuilderClientConstraints(): array
{
    return [
        'nesting' => cmsBuilderLoadNestingConstraints(),
        'arkBlocks' => cmsBuilderArkBlockDefinitions(),
    ];
}

/**
 * Load ARK block definitions for the builder client.
 * Reads block-registry.json + all block-definitions/*.json files
 * and returns them as a structured payload the React builder can consume.
 *
 * This is the bridge that makes the builder ARK-aware at runtime:
 * the builder's component palette should derive from this data,
 * not from hardcoded TypeScript definitions.
 */
function cmsBuilderArkBlockDefinitions(): array
{
    $themeDir = cmsBuilderConstraintThemeDir();
    if ($themeDir === null) {
        return [];
    }

    $registryPath = $themeDir . '/block-registry.json';
    if (!is_file($registryPath)) {
        return [];
    }

    $registry = json_decode((string)@file_get_contents($registryPath), true);
    if (!is_array($registry)) {
        return [];
    }

    $categories = $registry['categories'] ?? [];
    $definitionsByType = [];

    $blockDefDir = $themeDir . '/block-definitions';
    if (is_dir($blockDefDir)) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($blockDefDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'json' || $file->getFilename() === 'block-definition.schema.json') {
                continue;
            }
            $def = json_decode((string)@file_get_contents($file->getPathname()), true);
            if (is_array($def) && !empty($def['type'])) {
                $type = (string)$def['type'];
                $definitionsByType[$type] = [
                    'type' => $type,
                    'label' => (string)($def['label'] ?? $type),
                    'category' => (string)($def['category'] ?? ''),
                    'icon' => (string)($def['icon'] ?? ''),
                    'allowedParents' => is_array($def['allowed_parents'] ?? null) ? $def['allowed_parents'] : null,
                    'allowedChildren' => is_array($def['allowed_children'] ?? null) ? $def['allowed_children'] : null,
                    'maxChildren' => isset($def['max_children']) ? (int)$def['max_children'] : null,
                    'controls' => is_array($def['controls'] ?? null) ? $def['controls'] : [],
                    'rendersWith' => (string)($def['renders_with'] ?? ''),
                ];
            }
        }
    }

    // Also include block-registry type lists per category for palette organization
    $categoryTypes = [];
    foreach ($categories as $category => $types) {
        if (is_array($types)) {
            $categoryTypes[$category] = $types;
        }
    }

    return [
        'version' => (string)($registry['version'] ?? '1.0.0'),
        'categories' => $categoryTypes,
        'blocks' => $definitionsByType,
    ];
}

// ─── Builder Schema Versioning (Tier 3.5) ──────────────────────────────

const CMS_BUILDER_CURRENT_SCHEMA_VERSION = '1.1';

function cmsBuilderSchemaVersionCompare(string $a, string $b): int
{
    return version_compare($a, $b);
}

function cmsBuilderSchemaMigrations(): array
{
    return [
        '1.0' => '1.1',
    ];
}

function cmsBuilderSchemaMigrators(): array
{
    return [
        '1.0->1.1' => function (array $document): array {
            // v1.0 → v1.1: Add meta.schema_migrated_at, ensure all nodes have 'meta' key
            $root = $document['document'] ?? [];
            $document['document'] = _cmsBuilderSchemaEnsureNodeMeta($root);
            $document['schema_version'] = '1.1';
            $document['document']['meta'] = array_merge(
                $document['document']['meta'] ?? [],
                ['schema_migrated_at' => date('c'), 'schema_migrated_from' => '1.0']
            );
            return $document;
        },
    ];
}

function _cmsBuilderSchemaEnsureNodeMeta(array $node): array
{
    if (!isset($node['meta']) || !is_array($node['meta'])) {
        $node['meta'] = [];
    }
    if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = array_map('_cmsBuilderSchemaEnsureNodeMeta', $node['children']);
    }
    return $node;
}

function cmsBuilderSchemaMigrateDocument(array $document): array
{
    $version = (string)($document['schema_version'] ?? '1.0');
    $target = CMS_BUILDER_CURRENT_SCHEMA_VERSION;

    if (cmsBuilderSchemaVersionCompare($version, $target) >= 0) {
        return $document;
    }

    $migrations = cmsBuilderSchemaMigrations();
    $migrators = cmsBuilderSchemaMigrators();
    $current = $version;
    $steps = 0;
    $maxSteps = 20;

    while (cmsBuilderSchemaVersionCompare($current, $target) < 0 && $steps < $maxSteps) {
        if (!isset($migrations[$current])) {
            write_log("Builder schema migration path not found from {$current}", 'warning');
            break;
        }
        $next = $migrations[$current];
        $migratorKey = "{$current}->{$next}";
        if (!isset($migrators[$migratorKey])) {
            write_log("Builder schema migrator not found: {$migratorKey}", 'warning');
            break;
        }
        $document = ($migrators[$migratorKey])($document);
        $current = $next;
        $steps++;
    }

    $document['schema_version'] = $current;
    return $document;
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
        $doc = cmsBuilderNormalizeDocument((string)$row['document_json']);
        return cmsBuilderSchemaMigrateDocument($doc);
    }

    return cmsBuilderDefaultDocument(['schema_version' => CMS_BUILDER_CURRENT_SCHEMA_VERSION]);
}

function cmsBuilderLoadPublishedDocument(int $contentId, ?array $contentRow = null, array $meta = []): array
{
    $row = cmsBuilderLoadDocumentRow($contentId, 'published');
    if ($row && !empty($row['document_json'])) {
        $doc = cmsBuilderNormalizeDocument((string)$row['document_json']);
        return cmsBuilderSchemaMigrateDocument($doc);
    }

    return cmsBuilderDefaultDocument(['schema_version' => CMS_BUILDER_CURRENT_SCHEMA_VERSION]);
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
    // Emit DiSyL contract tree alongside React node tree for governed components
    if (isset($normalized['document']) && is_array($normalized['document'])) {
        $disylContract = cmsBuilderEmitDiSyLContract($normalized['document']);
        if ($disylContract !== null) {
            $normalized['disyl'] = $disylContract;
        }
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
        $cssProp = cmsBuilderCssProp((string)$prop);
        $val = (string)$value;
        // Emit CSS custom property + referenced property for responsive cascade.
        // E.g., "height:500px" becomes "--b-height:500px;height:var(--b-height)"
        // Responsive CSS can then override --b-height without !important.
        $parts[] = '--b-' . $cssProp . ':' . $val;
        $parts[] = $cssProp . ':var(--b-' . $cssProp . ')';
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
        'section'    => ['width' => '100%', 'display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'justifyContent' => 'center', 'padding' => '48px 24px', 'boxSizing' => 'border-box', 'minHeight' => '80px'],
        // Container defaults are contextual in the renderer. Keep the static baseline
        // minimal so preset child containers do not accidentally inherit constrained-width
        // wrapper behavior on the public frontend.
        'container'  => ['boxSizing' => 'border-box', 'minHeight' => '60px'],
        'layout_container' => ['boxSizing' => 'border-box', 'minWidth' => '0', 'minHeight' => '60px'],
        'row'        => ['display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'nowrap', 'gap' => '24px', 'justifyContent' => 'center', 'alignItems' => 'stretch', 'minHeight' => '50px'],
        'column'     => ['display' => 'flex', 'flexDirection' => 'column', 'gap' => '16px', 'alignItems' => 'stretch', 'boxSizing' => 'border-box', 'minWidth' => '0', 'minHeight' => '50px'],
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
        'nav_menu'     => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'recent_posts' => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'social_links' => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'contact_info' => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'categories'   => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'tag_cloud'    => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'archives'     => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
        'opening_hours' => ['padding' => '20px', 'border' => '1px solid #e5e7eb', 'borderRadius' => '16px', 'backgroundColor' => '#ffffff', 'width' => '100%'],
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
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [
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
        'nav_menu'       => ['title' => 'Browse', 'menuId' => 0],
        'recent_posts'   => ['title' => 'Latest Posts', 'count' => 5, 'showDate' => true],
        'social_links'   => ['title' => 'Follow Us', 'displayStyle' => 'icons'],
        'contact_info'   => ['title' => 'Contact Info', 'address' => '123 Market Street, Manila', 'phone' => '+63 900 000 0000', 'email' => 'hello@example.com'],
        'categories'     => ['title' => 'Categories', 'count' => 8, 'showCount' => true],
        'tag_cloud'      => ['title' => 'Popular Tags', 'count' => 16],
        'archives'       => ['title' => 'Archives', 'count' => 6, 'showCount' => true],
        'opening_hours'  => ['title' => 'Opening Hours', 'text' => 'Mon-Fri, 9:00 AM - 6:00 PM', 'showIcon' => true],
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
    }
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
    if (!empty($spacing['containerMaxWidth'])) $rules[] = '.' . $scopeClass . ' .cms-builder-node--section > .cms-builder-node--container{max-width:' . $spacing['containerMaxWidth'] . ';margin-left:auto;margin-right:auto;width:100%}';
    if (!empty($spacing['elementGap'])) $rules[] = '.' . $scopeClass . ' .cms-builder-node--row,.' . $scopeClass . ' .cms-builder-node--column,.' . $scopeClass . ' .cms-builder-node--layout_container{gap:' . $spacing['elementGap'] . '}';
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

function cmsBuilderCleanFlatStyle(array $style): array
{
    $clean = [];
    foreach ($style as $prop => $value) {
        if (is_array($value) || $value === null || $value === '') {
            continue;
        }
        $clean[$prop] = $value;
    }
    return $clean;
}

function cmsBuilderDiffStyle(array $next, array $previous): array
{
    $diff = [];
    foreach ($next as $prop => $value) {
        if (!array_key_exists($prop, $previous) || (string)$previous[$prop] !== (string)$value) {
            $diff[$prop] = $value;
        }
    }
    return $diff;
}

function cmsBuilderResolveViewportStyle(array $style, string $viewport, string $type): array
{
    $tablet = isset($style['tablet']) && is_array($style['tablet']) ? cmsBuilderCleanFlatStyle($style['tablet']) : [];
    $mobile = isset($style['mobile']) && is_array($style['mobile']) ? cmsBuilderCleanFlatStyle($style['mobile']) : [];
    $base = array_merge(cmsBuilderDefaultStyle($type), cmsBuilderCleanFlatStyle($style));

    if ($viewport === 'tablet') {
        $base = array_merge($base, $tablet);
    } elseif ($viewport === 'mobile') {
        $base = array_merge($base, $tablet, $mobile);
    }

    if ($type !== '' && $viewport !== 'desktop') {
        $effectiveDirection = $base['flexDirection'] ?? ($type === 'row' ? 'row' : null);
        $isHorizontalFlex = (($base['display'] ?? null) === 'flex') || $type === 'row' || $type === 'column';
        $isContainerLike = $type === 'container' || $type === 'layout_container';
        $hasExplicitMobileDir = array_key_exists('flexDirection', $mobile);
        $hasExplicitTabletDir = array_key_exists('flexDirection', $tablet);

        if ($viewport === 'mobile') {
            if ($type === 'row' && !$hasExplicitMobileDir) {
                $base['flexDirection'] = 'column';
            }
            if ($isContainerLike && $isHorizontalFlex && $effectiveDirection === 'row' && !$hasExplicitMobileDir) {
                $base['flexDirection'] = 'column';
            }
            if ($type === 'column' && !array_key_exists('width', $mobile)) {
                $base['width'] = '100%';
                $base['flex'] = 'none';
            }
            if ($isContainerLike && (!empty($base['flex']) || !empty($base['flexBasis']))) {
                if (!array_key_exists('width', $mobile) && !array_key_exists('flex', $mobile)) {
                    $base['width'] = '100%';
                    $base['flex'] = 'none';
                }
            }
        }

        if ($viewport === 'tablet') {
            if ($type === 'row' && !$hasExplicitTabletDir && !array_key_exists('flexWrap', $tablet)) {
                $base['flexWrap'] = 'wrap';
            }
            if ($type === 'column' && !array_key_exists('minWidth', $tablet)) {
                $base['minWidth'] = '0';
            }
        }
    }

    return $base;
}

/**
 * Recursively collect final viewport styles from all nodes. The resolver mirrors
 * React nodeStyleToCSS(): desktop base, tablet override, mobile inherits tablet,
 * then automatic responsive defaults.
 */
function cmsBuilderCollectResponsiveStyles(array $node, array &$collected): void
{
    $id = (string)($node['id'] ?? '');
    $type = (string)($node['type'] ?? '');
    $style = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];
    $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];

    $desktopStyle = cmsBuilderResolveViewportStyle($style, 'desktop', $type);
    $tabletStyle  = cmsBuilderResolveViewportStyle($style, 'tablet', $type);
    $mobileStyle  = cmsBuilderResolveViewportStyle($style, 'mobile', $type);

    // ─── Widget auto-viewport behaviors (mirrors React) ───
    // React automatically reduces grid columns on tablet/mobile for gallery,
    // posts_grid, products_grid, team_grid, entity_list, and logo_grid.
    // React also stacks horizontal CTA to column on mobile.
    // PHP must do the same via responsive CSS, without requiring explicit user style overrides.
    if ($type !== '') {
        $rawTablet = isset($style['tablet']) && is_array($style['tablet']) ? $style['tablet'] : [];
        $rawMobile = isset($style['mobile']) && is_array($style['mobile']) ? $style['mobile'] : [];

        // Call-to-action: horizontal/split layout stacks to column on mobile
        if ($type === 'call_to_action' && !array_key_exists('flexDirection', $rawMobile)) {
            $ctaLayout = (string)($props['layout'] ?? 'horizontal');
            if ($ctaLayout !== 'vertical') {
                $mobileStyle['flexDirection'] = 'column';
            }
        }

        $widgetGridTypes = ['gallery', 'posts_grid', 'products_grid', 'team_grid', 'entity_list', 'logo_grid'];
        if (in_array($type, $widgetGridTypes, true)) {
            $desktopCols = null;
            if (in_array($type, ['posts_grid', 'products_grid', 'team_grid', 'entity_list'], true)) {
                $desktopCols = max(1, min(12, (int)($props['gridColumns'] ?? 3)));
            } elseif ($type === 'gallery') {
                $desktopCols = max(1, min(12, (int)($props['columns'] ?? 3)));
            } elseif ($type === 'logo_grid') {
                $desktopCols = max(1, min(12, (int)($props['columns'] ?? 6)));
            }

            // Only add auto overrides when desktop uses more than 1 column and the user has NOT set an explicit gridTemplateColumns override for that viewport.
            if ($desktopCols !== null && $desktopCols > 1) {
                // Tablet: reduce columns (logo_grid→min(3,desktop), others→min(2,desktop))
                if (!array_key_exists('gridTemplateColumns', $rawTablet)) {
                    $tabletCols = $type === 'logo_grid' ? min(3, $desktopCols) : min(2, $desktopCols);
                    if ($tabletCols < $desktopCols) {
                        $tabletStyle['gridTemplateColumns'] = 'repeat(' . $tabletCols . ', 1fr)';
                    }
                }

                // Mobile: reduce columns (logo_grid→min(2,desktop), others→1)
                if (!array_key_exists('gridTemplateColumns', $rawMobile)) {
                    $mobileCols = $type === 'logo_grid' ? min(2, $desktopCols) : 1;
                    if ($mobileCols < $desktopCols) {
                        $mobileStyle['gridTemplateColumns'] = 'repeat(' . $mobileCols . ', 1fr)';
                    }
                }
            }
        }
    }

    $tabletProps = cmsBuilderDiffStyle($tabletStyle, $desktopStyle);
    $mobileProps = cmsBuilderDiffStyle($mobileStyle, $tabletStyle);

    if ($id !== '' && (!empty($tabletProps) || !empty($mobileProps))) {
        $collected[] = [
            'nodeId' => $id,
            'type' => $type,
            'tablet' => $tabletProps,
            'mobile' => $mobileProps,
        ];
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    foreach ($children as $child) {
        if (is_array($child)) {
            cmsBuilderCollectResponsiveStyles($child, $collected);
        }
    }
}

/**
 * Generate a <style> tag with media queries for tablet/mobile responsive behavior.
 * Breakpoints match the theme: tablet ≤1024px, mobile ≤640px.
 *
 * Each rule is keyed by a per-node selector that includes the node's type class
 * AND its data-node-id, giving specificity (0,0,3,0) — matching the theme's own
 * builder responsive rules. Since this <style> is injected inline with the document
 * (after external theme stylesheets), it wins specificity ties via source order.
 * This eliminates class-selector cascade wars entirely.
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
        $nodeId = htmlspecialchars($entry['nodeId'], ENT_QUOTES, 'UTF-8');
        $typeClass = 'cms-builder-node--' . preg_replace('/[^a-z0-9_-]/i', '-', (string)$entry['type']);
        // Specificity (0,0,3,0): scope class + type class + data-node-id attribute.
        $selector = '.' . $scopeClass . ' .' . $typeClass . '[data-node-id="' . $nodeId . '"]';

        if (!empty($entry['tablet'])) {
            $propsBySelector = [];
            foreach ($entry['tablet'] as $prop => $value) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $targetSelector = $selector;
                if ((string)$entry['type'] === 'image' && in_array((string)$prop, ['height', 'minHeight', 'maxHeight', 'borderRadius', 'objectFit', 'objectPosition'], true)) {
                    $targetSelector .= ' img';
                }
                $propsBySelector[$targetSelector][] = cmsBuilderCssProp((string)$prop) . ':' . (string)$value . ' !important';
            }
            foreach ($propsBySelector as $targetSelector => $props) {
                if (!empty($props)) {
                    $tabletRules[] = $targetSelector . '{' . implode(';', $props) . '}';
                }
            }
        }

        if (!empty($entry['mobile'])) {
            $propsBySelector = [];
            foreach ($entry['mobile'] as $prop => $value) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $targetSelector = $selector;
                if ((string)$entry['type'] === 'image' && in_array((string)$prop, ['height', 'minHeight', 'maxHeight', 'borderRadius', 'objectFit', 'objectPosition'], true)) {
                    $targetSelector .= ' img';
                }
                $propsBySelector[$targetSelector][] = cmsBuilderCssProp((string)$prop) . ':' . (string)$value . ' !important';
            }
            foreach ($propsBySelector as $targetSelector => $props) {
                if (!empty($props)) {
                    $mobileRules[] = $targetSelector . '{' . implode(';', $props) . '}';
                }
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
            'kind' => in_array($nodeType, ['section', 'container', 'layout_container', 'columns'], true) ? 'section' : 'widget',
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

/**
 * Check if builder is enabled for a content item.
 *
 * Prefers content_mode from the content row (source of truth).
 * Falls back to _builder_enabled meta for legacy data.
 */
function cmsPageBuilderEnabled(array $meta, ?array $contentRow = null): bool
{
    // content_mode is the canonical source of truth
    if ($contentRow !== null && isset($contentRow['content_mode'])) {
        return $contentRow['content_mode'] === 'builder';
    }

    // Fallback: legacy _builder_enabled meta key
    $raw = $meta['_builder_enabled'] ?? null;
    if (is_bool($raw)) {
        return $raw;
    }
    $value = strtolower(trim((string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Get builder page settings (global styles, layout config).
 *
 * Prefers settings from the published builder document (source of truth).
 * Falls back to _builder_page_settings meta for legacy data.
 */
function cmsPageBuilderSettings(array $meta, ?array $contentRow = null): array
{
    // Prefer settings from the published builder document
    if ($contentRow !== null && !empty($contentRow['id'])) {
        $doc = cmsBuilderLoadDocumentRow((int)$contentRow['id'], 'published');
        if ($doc && !empty($doc['document_json'])) {
            $parsed = json_decode((string)$doc['document_json'], true);
            if (is_array($parsed) && isset($parsed['page_settings']) && is_array($parsed['page_settings'])) {
                return $parsed['page_settings'];
            }
        }
    }

    // Fallback: legacy _builder_page_settings meta key
    $raw = $meta['_builder_page_settings'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cmsBuilderLivePreviewThemePayload(array $context = []): array
{
    $policy = function_exists('cmsResolveEcommerceThemePolicy')
        ? cmsResolveEcommerceThemePolicy(array_merge([
            'public_render_origin' => 'cms',
            'public_route_kind' => 'page',
            'public_presentation_mode' => 'traditional',
        ], $context))
        : ['active_theme_scope' => function_exists('cmsActiveCustomizerScope') ? cmsActiveCustomizerScope() : 'native'];

    $scope = function_exists('cmsNormalizeCustomizerScope')
        ? cmsNormalizeCustomizerScope((string)($policy['active_theme_scope'] ?? 'native'), function_exists('cmsActiveCustomizerScope') ? cmsActiveCustomizerScope() : 'native')
        : ((string)($policy['active_theme_scope'] ?? 'native') === 'ecommerce' ? 'ecommerce' : 'native');

    try {
        $db = cmsDb();
        $colorsData = cmsCustomizerGet($db, 'colors', $scope);
        $themeData = cmsCustomizerGet($db, 'theme', $scope);
        $colors = cmsValidateColorsSettings(is_array($colorsData['settings'] ?? null) ? $colorsData['settings'] : []);
        $theme = cmsValidateThemeLayoutSettings(is_array($themeData['settings'] ?? null) ? $themeData['settings'] : []);
    } catch (Throwable $e) {
        $colors = cmsColorsSettingsDefaults();
        $theme = cmsThemeLayoutSettingsDefaults();
    }

    return [
        'active_customizer_scope' => $scope,
        'global_styles' => [
            'colors' => [
                'primary' => (string)($colors['color_primary'] ?? '#3b82f6'),
                'secondary' => (string)($colors['color_secondary'] ?? '#64748b'),
                'accent' => (string)($colors['color_accent'] ?? '#f59e0b'),
                'text' => (string)($colors['body_text_color'] ?? '#1e293b'),
                'textLight' => (string)($colors['body_text_light'] ?? '#64748b'),
                'background' => (string)($colors['body_bg_color'] ?? '#ffffff'),
                'backgroundAlt' => (string)($colors['light_bg_color'] ?? '#f8fafc'),
            ],
            'typography' => [
                'fontFamily' => cmsCustomizerFontCssValue((string)($colors['font_body'] ?? 'Inter'), 'system-ui,-apple-system,BlinkMacSystemFont,sans-serif'),
                'headingFontFamily' => cmsCustomizerFontCssValue((string)($colors['font_heading'] ?? 'Inter'), 'system-ui,-apple-system,BlinkMacSystemFont,sans-serif'),
                'baseFontSize' => (string)($colors['font_size_base'] ?? '16') . 'px',
                'lineHeight' => (string)($colors['line_height'] ?? '1.6'),
                'h1Size' => (string)($colors['h1_size'] ?? '2.5') . 'rem',
                'h2Size' => (string)($colors['h2_size'] ?? '2') . 'rem',
                'h3Size' => (string)($colors['h3_size'] ?? '1.5') . 'rem',
                'h4Size' => (string)($colors['h4_size'] ?? '1.25') . 'rem',
            ],
            'spacing' => [
                'containerMaxWidth' => (string)($colors['container_width'] ?? '1200') . 'px',
            ],
            'buttons' => [
                'borderRadius' => (string)($colors['border_radius'] ?? '0.5') . 'rem',
            ],
        ],
        'shell' => [
            'maxWidth' => (string)($theme['site_max_width'] ?? '1280') . 'px',
            'paddingTop' => (string)($theme['content_padding_top'] ?? '32') . 'px',
            'paddingRight' => (string)($theme['content_padding_x'] ?? '16') . 'px',
            'paddingBottom' => (string)($theme['content_padding_bottom'] ?? '32') . 'px',
            'paddingLeft' => (string)($theme['content_padding_x'] ?? '16') . 'px',
        ],
    ];
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

/**
 * Emit a DiSyL contract tree from a normalized React builder node tree.
 * Walks the node tree, extracts governed components (_governed: true),
 * and produces {component, attrs, children} format for cmsRenderDiSyLDocument.
 *
 * Only emits for nodes with _governedName starting with 'ikb_'.
 * Non-governed nodes (layout containers, sections, etc.) are not emitted.
 */
function cmsBuilderEmitDiSyLContract(array $node): ?array
{
    $type = $node['type'] ?? '';
    $props = $node['props'] ?? [];

    // Check if this is a governed DiSyL component
    if (!empty($props['_governed']) && !empty($props['_governedName'])) {
        $governedName = (string)$props['_governedName'];
        if (str_starts_with($governedName, 'ikb_')) {
            // Extract relevant attrs (exclude internal _governed props)
            $attrs = [];
            foreach ($props as $key => $value) {
                if (!str_starts_with($key, '_')) {
                    $attrs[$key] = $value;
                }
            }
            // Recursively process children for non-leaf governed components
            $children = [];
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $emitted = cmsBuilderEmitDiSyLContract($child);
                    if ($emitted !== null) {
                        $children[] = $emitted;
                    }
                }
            }
            return [
                'component' => $governedName,
                'attrs' => $attrs,
                'children' => $children,
            ];
        }
    }

    // Non-governed: recurse into children to find governed descendants
    if (!empty($node['children']) && is_array($node['children'])) {
        $found = null;
        foreach ($node['children'] as $child) {
            $emitted = cmsBuilderEmitDiSyLContract($child);
            if ($emitted !== null) {
                if ($found === null) {
                    $found = $emitted;
                } else {
                    // Multiple governed siblings — wrap in a section
                    if (!isset($found[0])) {
                        $found = [$found];
                    }
                    $found[] = $emitted;
                }
            }
        }
        return $found;
    }

    return null;
}

function cmsPageBuilderRenderedHtml(array $contentRow): string
{
    $contentId = (int)($contentRow['id'] ?? 0);
    if ($contentId > 0) {
        $published = cmsBuilderLoadDocumentRow($contentId, 'published');
        if ($published && !empty($published['document_json'])) {
            $normalized = cmsBuilderNormalizeDocument((string)$published['document_json']);

            // Phase 6: Prefer DiSyL contract rendering when available.
            // If the builder document includes a 'disyl' key alongside the React
            // document tree, render through the governed component system instead.
            $disylDoc = $normalized['disyl'] ?? null;
            if (is_array($disylDoc) && !empty($disylDoc['component'])) {
                $disylHtml = cmsRenderDiSyLDocument($disylDoc, $contentRow);
                if ($disylHtml !== '') {
                    return $disylHtml;
                }
            }

            return cmsBuilderRenderDocument($normalized, $contentRow);
        }
    }

    return '';
}

/**
 * Render a DiSyL component tree JSON structure as HTML.
 *
 * Converts a governed component tree into rendered output using the
 * existing ComponentRegistry and TemplateEngine. This is Phase 6 of
 * the theme infrastructure plan — builder can emit DiSyL contracts
 * instead of raw HTML node trees.
 *
 * Input format:
 *   {
 *     "component": "ikb_section",
 *     "attrs": { "tone": "muted", "padding_y": "lg" },
 *     "children": [
 *       {
 *         "component": "ikb_container",
 *         "attrs": { "max_width": "1200px" },
 *         "children": [
 *           { "component": "ikb_entity_list", "attrs": { "source": "cms_post.recent", "view": "card_grid" } }
 *         ]
 *       }
 *     ]
 *   }
 *
 * @param array $node      DiSyL component node
 * @param array $context   Rendering context (content row, entity data, etc.)
 * @return string Rendered HTML
 */
function cmsRenderDiSyLDocument(array $node, array $context = []): string
{
    if (empty($node['component'])) {
        return '';
    }

    // If the node has children, render them recursively first.
    // Then wrap the rendered children in the parent component.
    return cmsRenderDiSyLNode($node, $context);
}

/**
 * Recursively render a single DiSyL node through the governed component system.
 *
 * @param array $node     {component: string, attrs: array, children: array|string}
 * @param array $context  Rendering context
 * @return string Rendered HTML fragment
 */
function cmsRenderDiSyLNode(array $node, array $context = []): string
{
    $component = (string)($node['component'] ?? '');
    if ($component === '') {
        return '';
    }

    // Render children recursively
    $childrenHtml = '';
    $rawChildren = $node['children'] ?? [];

    if (is_string($rawChildren)) {
        // String children — pass through as-is (raw HTML or text)
        $childrenHtml = $rawChildren;
    } elseif (is_array($rawChildren)) {
        // Array children — recurse
        foreach ($rawChildren as $child) {
            if (is_string($child)) {
                $childrenHtml .= $child;
            } elseif (is_array($child)) {
                $childrenHtml .= cmsRenderDiSyLNode($child, $context);
            }
        }
    }

    $attrs = $node['attrs'] ?? [];
    if (!is_array($attrs)) {
        $attrs = [];
    }

    // Check if the component is a governed ikb_* component
    if (str_starts_with($component, 'ikb_')) {
        // Try kernel-level rendering via app()->templates()
        try {
            $engine = null;
            if (function_exists('app') && ($app = app()) !== null && method_exists($app, 'templates')) {
                $engine = $app->templates();
            }

            if ($engine !== null && method_exists($engine, 'renderString')) {
                // Build a DiSyL template string from the component tree
                $disylTemplate = cmsBuildDiSyLTemplateString($component, $attrs, $childrenHtml);
                return $engine->renderString($disylTemplate, $context);
            }
        } catch (Throwable $e) {
            write_log('cmsRenderDiSyLNode: render failed for ' . $component . ': ' . $e->getMessage(), 'warning');
        }

        // Fallback: use the CMS builder renderers
        return cmsRenderWidgetFromDiSyL($component, $attrs, $childrenHtml);
    }

    // Non-governed component — wrap as a generic div
    $attrString = cmsBuildHtmlAttrs($attrs);
    $tag = $component;
    if ($childrenHtml !== '') {
        return "<{$tag}{$attrString}>{$childrenHtml}</{$tag}>";
    }
    return "<{$tag}{$attrString} />";
}

/**
 * Build a DiSyL template tag string from a component name, attrs, and children.
 *
 * Converts:
 *   component=ikb_section, attrs={tone:muted}, children=...
 * Into:
 *   {ikb_section tone="muted"}...children...{/ikb_section}
 */
function cmsBuildDiSyLTemplateString(string $component, array $attrs, string $children): string
{
    $attrParts = [];
    foreach ($attrs as $key => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        if ($value === true) {
            $attrParts[] = $key;
            continue;
        }
        $escaped = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $attrParts[] = "{$key}=\"{$escaped}\"";
    }

    $attrString = !empty($attrParts) ? ' ' . implode(' ', $attrParts) : '';

    if ($children !== '') {
        return "{{$component}{$attrString}}{$children}{/{$component}}";
    }
    return "{{$component}{$attrString} /}";
}

/**
 * Build an HTML attribute string from an associative array.
 */
function cmsBuildHtmlAttrs(array $attrs): string
{
    $parts = [];
    foreach ($attrs as $key => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        if ($value === true) {
            $parts[] = $key;
            continue;
        }
        $escaped = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $parts[] = "{$key}=\"{$escaped}\"";
    }
    return !empty($parts) ? ' ' . implode(' ', $parts) : '';
}

/**
 * Fallback renderer for governed components when TemplateEngine is unavailable.
 * Maps ikb_* component names to simple HTML wrappers with Tailwind classes.
 */
function cmsRenderWidgetFromDiSyL(string $component, array $attrs, string $children): string
{
    // Map governed components to semantic CSS classes
    $tagMap = [
        'ikb_section' => 'section',
        'ikb_container' => 'div',
        'ikb_grid' => 'div',
        'ikb_panel' => 'div',
        'ikb_card' => 'div',
        'ikb_alert' => 'div',
        'ikb_modal' => 'div',
        'ikb_drawer' => 'div',
        'ikb_text' => 'div',
        'ikb_link' => 'a',
        'ikb_button' => 'a',
        'ikb_badge' => 'span',
        'ikb_spinner' => 'div',
    ];

    $tag = $tagMap[$component] ?? 'div';

    // Build CSS classes from semantic attrs
    $classes = ['ikb-widget', 'ikb-widget--' . str_replace('ikb_', '', $component)];

    if (isset($attrs['tone'])) {
        $classes[] = 'ikb-tone--' . $attrs['tone'];
    }
    if (isset($attrs['spacing'])) {
        $classes[] = 'ikb-spacing--' . $attrs['spacing'];
    }
    if (isset($attrs['radius'])) {
        $classes[] = 'ikb-radius--' . $attrs['radius'];
    }
    if (isset($attrs['variant'])) {
        $classes[] = 'ikb-variant--' . $attrs['variant'];
    }

    $attrs['class'] = isset($attrs['class'])
        ? $attrs['class'] . ' ' . implode(' ', $classes)
        : implode(' ', $classes);

    $attrString = cmsBuildHtmlAttrs($attrs);

    if ($children !== '') {
        return "<{$tag}{$attrString}>{$children}</{$tag}>";
    }
    return "<{$tag}{$attrString} />";
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
        ['type' => 'search_box',    'label' => 'Search Box',    'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Site search widget',        'icon' => 'search'],
        ['type' => 'nav_menu',      'label' => 'Navigation Menu', 'kind' => 'widget', 'category' => 'content', 'supports_children' => false, 'description' => 'Menu by CMS menu ID',      'icon' => 'menu'],
        ['type' => 'recent_posts',  'label' => 'Recent Posts',  'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Compact latest posts list', 'icon' => 'posts'],
        ['type' => 'social_links',  'label' => 'Site Social Links', 'kind' => 'widget', 'category' => 'content', 'supports_children' => false, 'description' => 'Social links from site settings', 'icon' => 'social'],
        ['type' => 'contact_info',  'label' => 'Contact Info',  'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Address, phone, and email', 'icon' => 'contact'],
        ['type' => 'categories',    'label' => 'Categories',    'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Post category listing',     'icon' => 'categories'],
        ['type' => 'tag_cloud',     'label' => 'Tag Cloud',     'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Popular tags listing',      'icon' => 'tags'],
        ['type' => 'archives',      'label' => 'Archives',      'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Posts by month and year',   'icon' => 'archives'],
        ['type' => 'opening_hours', 'label' => 'Opening Hours', 'kind' => 'widget',  'category' => 'content',  'supports_children' => false, 'description' => 'Business hours display',    'icon' => 'clock'],
        // Layout
        ['type' => 'section',       'label' => 'Section',       'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Flex/Grid section (nestable)',  'icon' => 'section'],
        ['type' => 'columns',       'label' => 'Columns',       'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Flex/Grid column layout',       'icon' => 'columns'],
        ['type' => 'container',     'label' => 'Container',     'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Constrained content wrapper',   'icon' => 'container'],
        ['type' => 'layout_container', 'label' => 'Layout',     'kind' => 'section', 'category' => 'layout',   'supports_children' => true,  'description' => 'Flex/grid layout container',    'icon' => 'container'],
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

// ── 4.8: Content Revision Diffing & Restore ──

/**
 * List all revisions for a builder document.
 */
function cmsBuilderRevisionList(int $builderDocumentId, int $limit = 50): array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT id, builder_document_id, revision_number, note, created_by, created_at
             FROM cms_builder_revisions
             WHERE builder_document_id = :id
             ORDER BY revision_number DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':id', $builderDocumentId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a specific revision snapshot.
 */
function cmsBuilderRevisionGet(int $builderDocumentId, int $revisionNumber): ?array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT * FROM cms_builder_revisions
             WHERE builder_document_id = :id AND revision_number = :rev"
        );
        $stmt->execute([':id' => $builderDocumentId, ':rev' => $revisionNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['snapshot'] = json_decode((string)($row['snapshot_json'] ?? '{}'), true);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Compare two revision snapshots and return a structured diff.
 *
 * Returns array of changes: added/removed/modified nodes, prop changes, style changes.
 */
function cmsBuilderRevisionDiff(array $docA, array $docB): array
{
    $nodesA = _cmsBuilderFlattenNodes($docA);
    $nodesB = _cmsBuilderFlattenNodes($docB);

    $idsA = array_keys($nodesA);
    $idsB = array_keys($nodesB);

    $added = array_diff($idsB, $idsA);
    $removed = array_diff($idsA, $idsB);
    $common = array_intersect($idsA, $idsB);

    $changes = [];
    foreach ($added as $id) {
        $changes[] = ['type' => 'added', 'node_id' => $id, 'node_type' => (string)($nodesB[$id]['type'] ?? '')];
    }
    foreach ($removed as $id) {
        $changes[] = ['type' => 'removed', 'node_id' => $id, 'node_type' => (string)($nodesA[$id]['type'] ?? '')];
    }
    foreach ($common as $id) {
        $nodeA = $nodesA[$id];
        $nodeB = $nodesB[$id];

        $propDiffs = _cmsBuilderDiffAssoc(
            is_array($nodeA['props'] ?? null) ? $nodeA['props'] : [],
            is_array($nodeB['props'] ?? null) ? $nodeB['props'] : []
        );
        $styleDiffs = _cmsBuilderDiffAssoc(
            is_array($nodeA['styles'] ?? null) ? $nodeA['styles'] : [],
            is_array($nodeB['styles'] ?? null) ? $nodeB['styles'] : []
        );

        if (!empty($propDiffs) || !empty($styleDiffs)) {
            $changes[] = [
                'type' => 'modified',
                'node_id' => $id,
                'node_type' => (string)($nodeB['type'] ?? ''),
                'prop_changes' => $propDiffs,
                'style_changes' => $styleDiffs,
            ];
        }
    }

    return [
        'total_changes' => count($changes),
        'added' => count($added),
        'removed' => count($removed),
        'modified' => count($changes) - count($added) - count($removed),
        'changes' => $changes,
    ];
}

/**
 * Flatten a node tree into id => node map for diffing.
 */
function _cmsBuilderFlattenNodes(array $node, array &$result = []): array
{
    $id = (string)($node['id'] ?? '');
    if ($id !== '') {
        $flat = $node;
        unset($flat['children']);
        $result[$id] = $flat;
    }
    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            _cmsBuilderFlattenNodes($child, $result);
        }
    }
    return $result;
}

/**
 * Diff two associative arrays — returns list of changed keys.
 */
function _cmsBuilderDiffAssoc(array $a, array $b): array
{
    $diffs = [];
    $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
    foreach ($allKeys as $key) {
        $va = $a[$key] ?? null;
        $vb = $b[$key] ?? null;
        if ($va !== $vb) {
            $diffs[] = [
                'key' => $key,
                'old' => $va,
                'new' => $vb,
            ];
        }
    }
    return $diffs;
}

/**
 * Restore a revision as the current document.
 * Creates a new revision of the current state before restoring.
 */
function cmsBuilderRevisionRestore(int $builderDocumentId, int $revisionNumber, ?int $userId = null): array
{
    $revision = cmsBuilderRevisionGet($builderDocumentId, $revisionNumber);
    if (!$revision || !is_array($revision['snapshot'] ?? null)) {
        return ['ok' => false, 'error' => 'Revision not found'];
    }

    $snapshot = $revision['snapshot'];
    $normalized = cmsBuilderNormalizeDocument($snapshot);
    if (!$normalized) {
        return ['ok' => false, 'error' => 'Invalid revision snapshot'];
    }

    try {
        $db = cmsDb();

        // Save current state as a revision first (backup before restore)
        $current = $db->prepare("SELECT draft_document FROM cms_builder_documents WHERE id = :id");
        $current->execute([':id' => $builderDocumentId]);
        $currentJson = $current->fetchColumn();
        if ($currentJson) {
            $currentDoc = json_decode((string)$currentJson, true);
            if (is_array($currentDoc)) {
                cmsBuilderCreateRevision($builderDocumentId, $currentDoc, $userId, 'Auto-backup before restore to rev #' . $revisionNumber);
            }
        }

        // Restore the revision as the new draft
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->prepare(
            "UPDATE cms_builder_documents SET draft_document = :doc, document_version = document_version + 1, updated_at = NOW() WHERE id = :id"
        )->execute([':doc' => $json, ':id' => $builderDocumentId]);

        // Create a revision for the restored state
        cmsBuilderCreateRevision($builderDocumentId, $normalized, $userId, 'Restored from revision #' . $revisionNumber);

        return ['ok' => true, 'restored_revision' => $revisionNumber];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Restore failed: ' . $e->getMessage()];
    }
}

/**
 * Prune old content revisions (classic revisions table).
 */
