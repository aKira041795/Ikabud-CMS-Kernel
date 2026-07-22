<?php
declare(strict_types=1);

class AcademicSimilarityQuotaPolicy
{
    /**
     * Check and enforce quota before a submission is created.
     * Returns ['ok' => true/false, 'error' => '...', 'warning' => false]
     */
    public static function enforceSubmissionQuota(int $institutionId, string $tenantId): array
    {
        $quotaService = new AcademicSimilarityQuotaService($tenantId);
        $quota = $quotaService->checkQuota($institutionId, 'submissions');
        if (!$quota['ok']) {
            return ['ok' => false, 'error' => 'Submission quota exhausted. Please upgrade your plan or wait for the next billing cycle.', 'warning' => false];
        }
        return $quota;
    }

    /**
     * Check if a file size is within plan limits.
     */
    public static function validateFileSize(int $fileSizeBytes, int $institutionId, string $tenantId): array
    {
        $quotaService = new AcademicSimilarityQuotaService($tenantId);
        $subscription = $quotaService->getSubscription($institutionId);
        if (!$subscription) {
            return ['ok' => true, 'max_bytes' => 20 * 1024 * 1024]; // Default 20MB
        }
        $maxMb = (int)($subscription['plan_limits']['max_file_size_mb'] ?? 20);
        $maxBytes = $maxMb * 1024 * 1024;
        if ($fileSizeBytes > $maxBytes) {
            return ['ok' => false, 'error' => "File exceeds plan limit of {$maxMb}MB", 'max_bytes' => $maxBytes];
        }
        return ['ok' => true, 'max_bytes' => $maxBytes];
    }
}
