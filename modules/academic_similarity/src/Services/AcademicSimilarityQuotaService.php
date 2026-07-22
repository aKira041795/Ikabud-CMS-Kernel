<?php
declare(strict_types=1);

class AcademicSimilarityQuotaService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    private AcademicSimilarityUsageCounterRepository $usageRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->usageRepo = new AcademicSimilarityUsageCounterRepository($tenantId);
    }

    /**
     * Check whether the institution can perform an action (e.g. submit).
     *
     * @param int    $institutionId
     * @param string $metric        The usage metric to check (e.g. 'submissions')
     * @return array{ok: bool, current: int, limit: int, warning: bool, error?: string}
     */
    public function checkQuota(int $institutionId, string $metric = 'submissions'): array
    {
        $subscription = $this->getSubscription($institutionId);
        if ($subscription === null) {
            return [
                'ok' => false,
                'current' => 0,
                'limit' => 0,
                'warning' => false,
                'error' => 'No active subscription found for this institution',
            ];
        }

        $planLimits = $subscription['plan_limits'] ?? [];
        $metricLimit = (int)($planLimits[$metric] ?? 0);

        if ($metricLimit <= 0) {
            // Unlimited or no limit defined — allow
            $usage = $this->getUsage($institutionId, $metric);
            return [
                'ok' => true,
                'current' => $usage['monthly'],
                'limit' => -1,
                'warning' => false,
            ];
        }

        $monthlyUsage = $this->usageRepo->getMonthlyCount($metric, $institutionId);
        $dailyUsage = $this->usageRepo->getDailyCount($metric, $institutionId);

        $exceeded = $monthlyUsage >= $metricLimit;
        $warning = (!$exceeded) && ($monthlyUsage / $metricLimit) >= 0.8;

        return [
            'ok' => !$exceeded,
            'current' => $monthlyUsage,
            'limit' => $metricLimit,
            'warning' => $warning,
            'error' => $exceeded ? "Monthly {$metric} limit of {$metricLimit} has been reached" : null,
        ];
    }

    /**
     * Get the active subscription with plan limits for an institution.
     *
     * @param int $institutionId
     * @return array|null Returns subscription data with 'plan_limits' key, or null if no active subscription
     */
    public function getSubscription(int $institutionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.name as plan_name, p.price_monthly,
                    p.monthly_submissions_limit, p.daily_submissions_limit,
                    p.max_file_size_mb, p.max_word_count,
                    p.source_repository_limit, p.retention_days, p.semantic_enabled
             FROM ac_similarity_subscriptions s
             JOIN ac_similarity_plans p ON s.plan_id = p.id
             WHERE s.institution_id = :iid AND s.tenant_id = :tid AND s.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([':iid' => $institutionId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Assemble plan_limits from individual columns
        $row['plan_limits'] = [
            'submissions' => (int)($row['monthly_submissions_limit'] ?? 0),
            'submissions_daily' => (int)($row['daily_submissions_limit'] ?? 0),
            'max_file_size_mb' => (int)($row['max_file_size_mb'] ?? 20),
            'max_word_count' => (int)($row['max_word_count'] ?? 50000),
            'source_repository_limit' => (int)($row['source_repository_limit'] ?? 0),
            'retention_days' => (int)($row['retention_days'] ?? 365),
            'semantic_enabled' => (bool)($row['semantic_enabled'] ?? false),
        ];

        return $row;
    }

    /**
     * Get usage statistics for an institution.
     *
     * @param int    $institutionId
     * @param string $metric
     * @return array{monthly: int, daily: int}
     */
    public function getUsage(int $institutionId, string $metric = 'submissions'): array
    {
        return [
            'monthly' => $this->usageRepo->getMonthlyCount($metric, $institutionId),
            'daily' => $this->usageRepo->getDailyCount($metric, $institutionId),
        ];
    }

    /**
     * Check if adding N additional units would exceed plan limits.
     *
     * @param int    $institutionId
     * @param string $metric
     * @param int    $additional    Number of additional units to check
     * @return array{ok: bool, would_exceed_monthly: bool, would_exceed_daily: bool, monthly_after: int, daily_after: int, limit: int, error?: string}
     */
    public function checkLimits(int $institutionId, string $metric = 'submissions', int $additional = 1): array
    {
        $subscription = $this->getSubscription($institutionId);
        if ($subscription === null) {
            return [
                'ok' => false,
                'would_exceed_monthly' => true,
                'would_exceed_daily' => false,
                'monthly_after' => 0,
                'daily_after' => 0,
                'limit' => 0,
                'error' => 'No active subscription found',
            ];
        }

        $planLimits = $subscription['plan_limits'] ?? [];
        $monthlyLimit = (int)($planLimits[$metric] ?? 0);
        $dailyLimit = (int)($planLimits[$metric . '_daily'] ?? 0);

        $monthlyUsage = $this->usageRepo->getMonthlyCount($metric, $institutionId);
        $dailyUsage = $this->usageRepo->getDailyCount($metric, $institutionId);

        $monthlyAfter = $monthlyUsage + $additional;
        $dailyAfter = $dailyUsage + $additional;

        $wouldExceedMonthly = $monthlyLimit > 0 && $monthlyAfter > $monthlyLimit;
        $wouldExceedDaily = $dailyLimit > 0 && $dailyAfter > $dailyLimit;
        $wouldExceed = $wouldExceedMonthly || $wouldExceedDaily;

        return [
            'ok' => !$wouldExceed,
            'would_exceed_monthly' => $wouldExceedMonthly,
            'would_exceed_daily' => $wouldExceedDaily,
            'monthly_after' => $monthlyAfter,
            'daily_after' => $dailyAfter,
            'monthly_usage' => $monthlyUsage,
            'daily_usage' => $dailyUsage,
            'monthly_limit' => $monthlyLimit,
            'daily_limit' => $dailyLimit,
            'error' => $wouldExceed
                ? ($wouldExceedMonthly ? "Would exceed monthly {$metric} limit of {$monthlyLimit}" : "Would exceed daily {$metric} limit of {$dailyLimit}")
                : null,
        ];
    }
}
