<?php

declare(strict_types=1);

function search_capability_handlers(): array
{
    return [
        'search.index.upsert@1' => 'search_cap_search_index_upsert_1',
        'search.index.delete@1' => 'search_cap_search_index_delete_1',
        'search.query@1' => 'search_cap_search_query_1',
    ];
}

function searchStrip(string $s): string
{
    $s = strip_tags($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

function searchIndexUpsert(array $doc): array
{
    $module = trim((string)($doc['module'] ?? ''));
    $entityType = trim((string)($doc['entity_type'] ?? ''));
    $entityId = trim((string)($doc['entity_id'] ?? ''));
    if ($module === '' || $entityType === '' || $entityId === '') {
        return ['ok' => false, 'error' => 'module, entity_type, entity_id are required'];
    }

    $title = isset($doc['title']) ? (string)$doc['title'] : null;
    $excerpt = isset($doc['excerpt']) ? (string)$doc['excerpt'] : null;
    $searchText = isset($doc['search_text']) ? (string)$doc['search_text'] : null;
    $meta = $doc['json_metadata'] ?? null;
    $metaJson = is_array($meta) ? json_encode($meta) : (is_string($meta) ? $meta : null);
    $ctx = module('search');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $db = $ctx->db();
        $stmt = $db->prepare(
            "INSERT INTO kernel_search_index (module, entity_type, entity_id, title, excerpt, search_text, json_metadata, created_at)\n             VALUES (:m, :t, :id, :title, :ex, :st, :meta, NOW())\n             ON DUPLICATE KEY UPDATE\n                title = VALUES(title),\n                excerpt = VALUES(excerpt),\n                search_text = VALUES(search_text),\n                json_metadata = VALUES(json_metadata),\n                updated_at = NOW()"
        );
        $stmt->execute([
            ':m' => $module,
            ':t' => $entityType,
            ':id' => $entityId,
            ':title' => $title,
            ':ex' => $excerpt,
            ':st' => $searchText,
            ':meta' => $metaJson,
        ]);

        $ctx->fireEvent('search.indexed', [
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function searchIndexDelete(string $module, string $entityType, string $entityId): array
{
    $module = trim($module);
    $entityType = trim($entityType);
    $entityId = trim($entityId);
    if ($module === '' || $entityType === '' || $entityId === '') {
        return ['ok' => false, 'error' => 'module, entity_type, entity_id are required'];
    }

    $ctx = module('search');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $db = $ctx->db();
        $db->prepare(
            "DELETE FROM kernel_search_index WHERE module = :m AND entity_type = :t AND entity_id = :id"
        )->execute([':m' => $module, ':t' => $entityType, ':id' => $entityId]);

        $ctx->fireEvent('search.deleted', [
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function searchQuery(string $q, int $limit = 20, int $offset = 0, ?string $module = null): array
{
    $q = trim($q);
    if ($q === '') {
        return ['ok' => true, 'data' => []];
    }
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $ctx = module('search');
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Module context unavailable'];
    }

    try {
        $db = $ctx->db();
        $where = "WHERE MATCH(title, excerpt, search_text) AGAINST (:q IN NATURAL LANGUAGE MODE)";
        $bind = [':q' => $q];
        if ($module !== null && trim($module) !== '') {
            $where .= " AND module = :m";
            $bind[':m'] = trim($module);
        }

        $stmt = $db->prepare(
            "SELECT module, entity_type, entity_id, title, excerpt, json_metadata, updated_at\n             FROM kernel_search_index\n             {$where}\n             ORDER BY updated_at DESC\n             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Decode metadata
        foreach ($rows as &$r) {
            if (isset($r['json_metadata']) && is_string($r['json_metadata']) && trim($r['json_metadata']) !== '') {
                $decoded = json_decode($r['json_metadata'], true);
                $r['json_metadata'] = is_array($decoded) ? $decoded : null;
            } else {
                $r['json_metadata'] = null;
            }
        }

        return ['ok' => true, 'data' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function search_cap_search_index_upsert_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    return searchIndexUpsert($payload);
}

function search_cap_search_index_delete_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    return searchIndexDelete(
        (string)($payload['module'] ?? ''),
        (string)($payload['entity_type'] ?? ''),
        (string)($payload['entity_id'] ?? '')
    );
}

function search_cap_search_query_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    return searchQuery(
        (string)($payload['q'] ?? ''),
        (int)($payload['limit'] ?? 20),
        (int)($payload['offset'] ?? 0),
        array_key_exists('module', $payload) ? (string)$payload['module'] : null
    );
}

// Index CMS content on CMS events
try {
    $bus = \Ikabud\Kernel\EventBus::getInstance();

    $bus->listen('cms.content.published', function (array $p) {
        $id = (int)($p['content_id'] ?? 0);
        if ($id <= 0) return;
        try {
            $ctx = module('search');
            if (!$ctx) return;
            $db = $ctx->db();
            $stmt = $db->prepare("SELECT id, type, slug, title, excerpt, body, blocks_json, updated_at, published_at FROM cms_content WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $c = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($c)) return;
            $text = (string)($c['body'] ?? '');
            $excerpt = (string)($c['excerpt'] ?? '');
            if (is_string($c['blocks_json'] ?? null) && trim((string)$c['blocks_json']) !== '') {
                $text .= "\n" . (string)$c['blocks_json'];
            }
            $text = searchStrip($text);
            $excerpt = searchStrip($excerpt);
            if ($excerpt === '' && $text !== '') {
                $excerpt = substr($text, 0, 200);
            }

            searchIndexUpsert([
                'module' => 'cms',
                'entity_type' => (string)($c['type'] ?? 'post'),
                'entity_id' => (string)($c['id'] ?? ''),
                'title' => (string)($c['title'] ?? ''),
                'excerpt' => $excerpt,
                'search_text' => $text,
                'json_metadata' => [
                    'slug' => (string)($c['slug'] ?? ''),
                    'type' => (string)($c['type'] ?? ''),
                    'published_at' => (string)($c['published_at'] ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
        }
    }, 10, 'search');

    $bus->listen('cms.content.updated', function (array $p) {
        // Reindex on update (best-effort). If not published, remove from index.
        $id = (int)($p['content_id'] ?? 0);
        if ($id <= 0) return;
        try {
            $ctx = module('search');
            if (!$ctx) return;
            $db = $ctx->db();
            $stmt = $db->prepare("SELECT id, type, slug, title, excerpt, body, blocks_json, status, updated_at, published_at FROM cms_content WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $c = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($c)) return;
            if ((string)($c['status'] ?? '') !== 'published') {
                searchIndexDelete('cms', (string)($c['type'] ?? 'post'), (string)($c['id'] ?? ''));
                return;
            }

            $text = searchStrip((string)($c['body'] ?? ''));
            $excerpt = searchStrip((string)($c['excerpt'] ?? ''));
            if ($excerpt === '' && $text !== '') {
                $excerpt = substr($text, 0, 200);
            }

            searchIndexUpsert([
                'module' => 'cms',
                'entity_type' => (string)($c['type'] ?? 'post'),
                'entity_id' => (string)($c['id'] ?? ''),
                'title' => (string)($c['title'] ?? ''),
                'excerpt' => $excerpt,
                'search_text' => $text,
                'json_metadata' => [
                    'slug' => (string)($c['slug'] ?? ''),
                    'type' => (string)($c['type'] ?? ''),
                    'published_at' => (string)($c['published_at'] ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
        }
    }, 10, 'search');

    $bus->listen('cms.content.deleted', function (array $p) {
        $id = (string)($p['content_id'] ?? '');
        $type = (string)($p['type'] ?? 'post');
        if ($id === '') return;
        searchIndexDelete('cms', $type, $id);
    }, 10, 'search');
} catch (Throwable $e) {
}
