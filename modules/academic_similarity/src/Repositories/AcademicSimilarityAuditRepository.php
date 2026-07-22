<?php
declare(strict_types=1);

class AcademicSimilarityAuditRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function record(string $eventType, int $actorId, string $actorName, string $targetType, int $targetId, string $description, array $details = []): void {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_audit_events (tenant_id, event_type, actor_id, actor_name, target_type, target_id, description, details_json) VALUES (:tid, :evt, :aid, :aname, :ttype, :tidval, :desc, :djson)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':evt' => $eventType,
            ':aid' => $actorId,
            ':aname' => $actorName,
            ':ttype' => $targetType,
            ':tidval' => $targetId,
            ':desc' => $description,
            ':djson' => !empty($details) ? json_encode($details) : null,
        ]);
    }

    public function search(string $eventType = '', string $targetType = '', int $targetId = 0, int $page = 1, int $perPage = 50): array {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($eventType !== '') { $conditions[] = 'event_type = :evt'; $params[':evt'] = $eventType; }
        if ($targetType !== '') { $conditions[] = 'target_type = :ttype'; $params[':ttype'] = $targetType; }
        if ($targetId > 0) { $conditions[] = 'target_id = :tidval'; $params[':tidval'] = $targetId; }
        $where = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_audit_events WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(string $eventType = '', string $targetType = '', int $targetId = 0): int {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($eventType !== '') { $conditions[] = 'event_type = :evt'; $params[':evt'] = $eventType; }
        if ($targetType !== '') { $conditions[] = 'target_type = :ttype'; $params[':ttype'] = $targetType; }
        if ($targetId > 0) { $conditions[] = 'target_id = :tidval'; $params[':tidval'] = $targetId; }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ac_similarity_audit_events WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
