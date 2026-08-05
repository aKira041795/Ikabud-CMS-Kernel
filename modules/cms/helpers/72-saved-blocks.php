<?php

declare(strict_types=1);

function cmsGetSavedBlocks(?string $category = null): array
{
    try {
        $sql = "SELECT * FROM cms_saved_blocks";
        $params = [];
        if ($category) {
            $sql .= " WHERE category = ?";
            $params[] = $category;
        }
        $sql .= " ORDER BY name ASC";
        $stmt = cmsDb()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['blocks_json'] = json_decode($r['blocks_json'] ?? '[]', true);
            $r['styles_json'] = json_decode($r['styles_json'] ?? 'null', true);
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a single saved block by ID.
 */

function cmsGetSavedBlock(int $id): ?array
{
    try {
        $stmt = cmsDb()->prepare("SELECT * FROM cms_saved_blocks WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['blocks_json'] = json_decode($row['blocks_json'] ?? '[]', true);
        $row['styles_json'] = json_decode($row['styles_json'] ?? 'null', true);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Save a reusable block.
 */

function cmsSavedBlockCreate(array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || empty($data['blocks'])) {
        return ['ok' => false, 'error' => 'Name and blocks are required'];
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    try {
        $db = cmsDb();
        // Ensure unique slug
        $check = $db->prepare("SELECT id FROM cms_saved_blocks WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);
        if ($check->fetch()) $slug .= '-' . substr(uniqid('', true), -6);

        $db->prepare(
            "INSERT INTO cms_saved_blocks (name, slug, category, description, blocks_json, styles_json, is_global, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $name, $slug,
            trim((string)($data['category'] ?? 'custom')),
            trim((string)($data['description'] ?? '')),
            json_encode($data['blocks']),
            isset($data['styles']) ? json_encode($data['styles']) : null,
            (int)($data['is_global'] ?? 0),
            $data['created_by'] ?? null,
        ]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to save block'];
    }
}

/**
 * Update a saved block.
 */

function cmsSavedBlockUpdate(int $id, array $data): array
{
    try {
        $sets = [];
        $params = [];
        if (isset($data['name'])) { $sets[] = 'name = ?'; $params[] = trim($data['name']); }
        if (isset($data['category'])) { $sets[] = 'category = ?'; $params[] = trim($data['category']); }
        if (isset($data['description'])) { $sets[] = 'description = ?'; $params[] = trim($data['description']); }
        if (isset($data['blocks'])) { $sets[] = 'blocks_json = ?'; $params[] = json_encode($data['blocks']); }
        if (isset($data['styles'])) { $sets[] = 'styles_json = ?'; $params[] = json_encode($data['styles']); }
        if (isset($data['is_global'])) { $sets[] = 'is_global = ?'; $params[] = (int)$data['is_global']; }
        if (empty($sets)) return ['ok' => false, 'error' => 'Nothing to update'];
        $params[] = $id;
        cmsDb()->prepare("UPDATE cms_saved_blocks SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Update failed'];
    }
}

/**
 * Delete a saved block.
 */

function cmsSavedBlockDelete(int $id): array
{
    try {
        cmsDb()->prepare("DELETE FROM cms_saved_blocks WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

/**
 * Increment usage count for a saved block.
 */

function cmsSavedBlockIncrementUsage(int $id): void
{
    try {
        cmsDb()->prepare("UPDATE cms_saved_blocks SET usage_count = usage_count + 1 WHERE id = ?")->execute([$id]);
    } catch (Throwable $e) {}
}

// ── Revisions ──────────────────────────────────────────────────────

/**
 * Save a revision snapshot of content.
 */
