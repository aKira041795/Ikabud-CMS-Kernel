<?php

declare(strict_types=1);

/**
 * Bakeshop — Recipe Version Service
 *
 * Immutable recipe snapshots. Every recipe edit creates a new version.
 * Production runs bind to a specific recipe version at completion time.
 * Later recipe edits cannot change the expected consumption of completed runs.
 *
 * Current recipe (for new production) is always the latest version.
 */

class BakeshopRecipeVersionService
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(?\Ikabud\Kernel\Contracts\ModuleDB $db = null)
    {
        $this->db = $db ?? bakeshopDb();
    }

    /**
     * Snapshot the current recipe as a new version.
     * Returns the new recipe_header ID.
     */
    public function snapshot(int $productId, string $notes = '', ?int $userId = null): int
    {
        // Determine next version number
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1 FROM bakeshop_recipe_headers WHERE product_id = :pid'
        );
        $stmt->execute([':pid' => $productId]);
        $versionNo = (int)$stmt->fetchColumn();

        // Create header
        $this->db->prepare(
            'INSERT INTO bakeshop_recipe_headers (product_id, version_no, notes, created_by, created_at)
             VALUES (:pid, :vn, :notes, :cb, NOW())'
        )->execute([':pid' => $productId, ':vn' => $versionNo, ':notes' => $notes, ':cb' => $userId]);
        $headerId = (int)$this->db->lastInsertId();

        // Copy lines from current bakeshop_product_recipe
        $lines = $this->db->prepare(
            'SELECT ingredient_id, qty, unit_id FROM bakeshop_product_recipe WHERE product_id = :pid'
        );
        $lines->execute([':pid' => $productId]);
        $ins = $this->db->prepare(
            'INSERT INTO bakeshop_recipe_version_lines (recipe_header_id, ingredient_id, qty, unit_id)
             VALUES (:hid, :iid, :qty, :uid)'
        );
        foreach ($lines as $line) {
            $ins->execute([
                ':hid' => $headerId,
                ':iid' => $line['ingredient_id'],
                ':qty' => $line['qty'],
                ':uid' => $line['unit_id'],
            ]);
        }

        return $headerId;
    }

    /**
     * Get the latest recipe version for a product.
     * Returns null if no recipe versions exist.
     */
    public function getLatestVersion(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rh.* FROM bakeshop_recipe_headers rh
             WHERE rh.product_id = :pid
             ORDER BY rh.version_no DESC LIMIT 1'
        );
        $stmt->execute([':pid' => $productId]);
        $header = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$header) return null;

        $lStmt = $this->db->prepare(
            'SELECT rvl.*, i.name AS ingredient_name, u.code AS unit_code
             FROM bakeshop_recipe_version_lines rvl
             LEFT JOIN bakeshop_ingredients i ON i.id = rvl.ingredient_id
             LEFT JOIN bakeshop_units u ON u.id = rvl.unit_id
             WHERE rvl.recipe_header_id = :hid
             ORDER BY rvl.id'
        );
        $lStmt->execute([':hid' => $header['id']]);
        $header['lines'] = $lStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $header;
    }

    /**
     * Get a specific recipe version by header ID.
     */
    public function getVersion(int $headerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM bakeshop_recipe_headers WHERE id = :id');
        $stmt->execute([':id' => $headerId]);
        $header = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$header) return null;

        $lStmt = $this->db->prepare(
            'SELECT rvl.*, i.name AS ingredient_name, u.code AS unit_code
             FROM bakeshop_recipe_version_lines rvl
             LEFT JOIN bakeshop_ingredients i ON i.id = rvl.ingredient_id
             LEFT JOIN bakeshop_units u ON u.id = rvl.unit_id
             WHERE rvl.recipe_header_id = :hid
             ORDER BY rvl.id'
        );
        $lStmt->execute([':hid' => $header['id']]);
        $header['lines'] = $lStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $header;
    }

    /**
     * Get all version headers for a product (without lines).
     */
    public function getVersionHistory(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT rh.*, COUNT(rvl.id) AS line_count
             FROM bakeshop_recipe_headers rh
             LEFT JOIN bakeshop_recipe_version_lines rvl ON rvl.recipe_header_id = rh.id
             WHERE rh.product_id = :pid
             GROUP BY rh.id
             ORDER BY rh.version_no DESC'
        );
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
