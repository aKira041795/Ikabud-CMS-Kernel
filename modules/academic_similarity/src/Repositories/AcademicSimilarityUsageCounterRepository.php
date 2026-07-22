<?php
declare(strict_types=1);

class AcademicSimilarityUsageCounterRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function increment(string $metric, int $institutionId, int $amount = 1): void {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            INSERT INTO ac_similarity_usage_counters (tenant_id, institution_id, metric, period_date, count_value)
            VALUES (:tid, :iid, :metric, :pdate, :amount)
            ON DUPLICATE KEY UPDATE count_value = count_value + :amount2, updated_at = NOW()
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => $institutionId,
            ':metric' => $metric,
            ':pdate' => $today,
            ':amount' => $amount,
            ':amount2' => $amount,
        ]);
    }

    public function getCount(string $metric, int $institutionId, ?string $date = null): int {
        $conditions = ['tenant_id = :tid', 'institution_id = :iid', 'metric = :metric'];
        $params = [':tid' => $this->tenantId, ':iid' => $institutionId, ':metric' => $metric];
        if ($date !== null) {
            $conditions[] = 'period_date = :pdate';
            $params[':pdate'] = $date;
        }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(count_value), 0) FROM ac_similarity_usage_counters WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getMonthlyCount(string $metric, int $institutionId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(count_value), 0) FROM ac_similarity_usage_counters WHERE tenant_id = :tid AND institution_id = :iid AND metric = :metric AND period_date >= :start_date");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => $institutionId,
            ':metric' => $metric,
            ':start_date' => date('Y-m-01'),
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function getDailyCount(string $metric, int $institutionId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(count_value), 0) FROM ac_similarity_usage_counters WHERE tenant_id = :tid AND institution_id = :iid AND metric = :metric AND period_date = :today");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => $institutionId,
            ':metric' => $metric,
            ':today' => date('Y-m-d'),
        ]);
        return (int)$stmt->fetchColumn();
    }
}
