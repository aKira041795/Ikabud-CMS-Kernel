<?php
declare(strict_types=1);

class AcademicSimilarityProcessJob
{
    private string $tenantId;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
    }

    /**
     * Dispatch a processing job to the kernel job queue.
     */
    public function dispatch(int $submissionId, string $jobType, int $priority = 0, string $idempotencyKey = ''): array
    {
        if ($idempotencyKey === '') {
            $idempotencyKey = 'ac_sim_' . $jobType . '_' . $submissionId . '_' . time();
        }

        // Create processing job record
        $repo = new AcademicSimilarityProcessingJobRepository($this->tenantId);
        $jobId = $repo->create([
            'tenant_id' => $this->tenantId,
            'submission_id' => $submissionId,
            'job_type' => $jobType,
            'status' => 'pending',
            'priority' => $priority,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Use kernel job queue if available
        if (function_exists('kernelDispatchJob')) {
            kernelDispatchJob('academic-similarity', 'academicSimilarityProcessJobHandler', [
                'job_id' => $jobId,
                'submission_id' => $submissionId,
                'job_type' => $jobType,
                'tenant_id' => $this->tenantId,
                'idempotency_key' => $idempotencyKey,
            ], $priority);
        }

        return ['job_id' => $jobId, 'submission_id' => $submissionId, 'job_type' => $jobType];
    }

    /**
     * Process a job - called by kernel job handler.
     */
    public static function process(int $jobId): array
    {
        $repo = new AcademicSimilarityProcessingJobRepository('');
        $job = $repo->findById($jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'Job not found'];
        }

        $pipeline = new AcademicSimilarityPipelineService($job['tenant_id']);
        return $pipeline->processJob($jobId);
    }
}
