<?php
declare(strict_types=1);

/**
 * Academic Similarity — Semantic Service Client.
 *
 * Bridges the PHP academic-similarity module with the external Python
 * semantic comparison service via the CapabilityBus.
 *
 * This service is strictly optional — exact and near-exact matching
 * work without it. Semantic matching is gated by:
 *   1. Module setting: semantic_match_enabled = 1
 *   2. Plan feature:   semantic_enabled = 1
 *   3. Quota:          available semantic comparison quota
 *   4. Privacy:        only segment text is sent (never full documents)
 *   5. Capability:     academic_similarity.semantic.compare@1 must be registered
 *
 * Provider-neutral: the backend is configurable in the Python service
 * via SEMANTIC_EMBEDDING_BACKEND env var. The PHP side never knows or
 * cares which backend is used.
 */

class AcademicSimilaritySemanticService
{
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Compare submission segments against source segments using the
     * semantic service capability.
     *
     * @param array<int, string> $submissionSegments Text segments from the submission
     * @param array<int, string> $sourceSegments     Text segments from source documents
     * @param array              $options            {
     *     Optional overrides:
     *     @type int    $institutionId  Institution ID for quota/plan gating
     *     @type string $provider       Backend provider name
     *     @type string $modelName      Model name override
     *     @type float  $threshold      Similarity threshold override
     * }
     * @return array{ok: bool, comparisons?: array, model?: array, summary?: array, error?: string}
     */
    public function compare(array $submissionSegments, array $sourceSegments, array $options = []): array
    {
        // 1. Check if semantic matching is enabled in tenant settings
        try {
            $settings = academic_similarity_get_settings($this->tenantId);
            $semanticEnabled = !empty($settings['semantic_match_enabled']) && $settings['semantic_match_enabled'] === '1';
        } catch (\Throwable $e) {
            $semanticEnabled = false;
        }
        if (!$semanticEnabled) {
            return ['ok' => false, 'error' => 'Semantic matching is disabled in tenant settings'];
        }

        // 2. Check quota if institution_id is provided
        $institutionId = (int)($options['institution_id'] ?? 0);
        if ($institutionId > 0) {
            $quotaService = new AcademicSimilarityQuotaService($this->tenantId);
            $quota = $quotaService->checkQuota($institutionId, 'semantic_comparisons');
            if (!($quota['ok'] ?? false)) {
                return ['ok' => false, 'error' => $quota['error'] ?? 'Semantic comparison quota exhausted'];
            }
        }

        // 3. Validate input
        if (empty($submissionSegments)) {
            return ['ok' => false, 'error' => 'submission_segments must be non-empty'];
        }
        if (empty($sourceSegments)) {
            return ['ok' => false, 'error' => 'source_segments must be non-empty'];
        }

        // Max segment limit from settings (default 500)
        try {
            $settings = academic_similarity_get_settings($this->tenantId);
            $maxSegments = (int)($settings['semantic_max_segments'] ?? 500);
        } catch (\Throwable $e) {
            $maxSegments = 500;
        }
        $maxSegments = max(50, min(5000, $maxSegments)); // Clamp 50-5000
        if (count($submissionSegments) > $maxSegments) {
            return ['ok' => false, 'error' => "submission_segments exceeds limit of {$maxSegments}"];
        }
        if (count($sourceSegments) > $maxSegments) {
            return ['ok' => false, 'error' => "source_segments exceeds limit of {$maxSegments}"];
        }

        // 4. Build capability payload
        $payload = [
            'submission_segments' => $submissionSegments,
            'source_segments' => $sourceSegments,
            'tenant_id' => $this->tenantId,
            'institution_id' => $institutionId,
        ];

        if (isset($options['provider']) || isset($options['modelName'])) {
            $payload['model_profile'] = [];
            if (isset($options['provider'])) {
                $payload['model_profile']['provider'] = $options['provider'];
            }
            if (isset($options['modelName'])) {
                $payload['model_profile']['model_name'] = $options['modelName'];
            }
            if (isset($options['threshold'])) {
                $payload['model_profile']['threshold'] = (float)$options['threshold'];
            }
        }
        if (($settings['semantic_provider'] ?? '') === 'groq') {
            $apiKey = trim((string)($settings['semantic_external_api_key'] ?? ''));
            if ($apiKey !== '') {
                $payload['model_profile'] = $payload['model_profile'] ?? [];
                $payload['model_profile']['api_key'] = $apiKey;
            }
        }

        // 5. Call the capability bus
        try {
            $result = app()->cap()->call(
                'academic_similarity.semantic.compare@1',
                $payload,
                ['caller' => ['module' => 'academic-similarity']]
            );

            if (!is_array($result)) {
                return ['ok' => false, 'error' => 'Semantic service returned unexpected response type'];
            }

            // 6. Increment usage counter
            if ($institutionId > 0) {
                try {
                    $usageRepo = new AcademicSimilarityUsageCounterRepository($this->tenantId);
                    $usageRepo->increment('semantic_comparisons', $institutionId, 1);
                } catch (\Throwable $e) {
                    write_log('Failed to increment semantic usage counter: ' . $e->getMessage());
                }
            }

            return [
                'ok' => true,
                'comparisons' => $result['comparisons'] ?? [],
                'model' => $result['model'] ?? [],
                'summary' => $result['summary'] ?? [],
            ];
        } catch (\Throwable $e) {
            write_log('Semantic comparison failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Semantic comparison failed: ' . $e->getMessage()];
        }
    }

    /**
     * Check if the semantic service is available and healthy.
     *
     * @return array{ok: bool, health?: array, error?: string}
     */
    public function health(): array
    {
        try {
            $result = app()->cap()->call(
                'academic_similarity.semantic.health@1',
                [],
                ['caller' => ['module' => 'academic-similarity']]
            );

            if (!is_array($result)) {
                return ['ok' => false, 'error' => 'Unexpected health response type'];
            }

            return ['ok' => true, 'health' => $result];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Semantic service unavailable: ' . $e->getMessage()];
        }
    }

    /**
     * Check if semantic matching is available for the current tenant.
     * Returns ok=true only when ALL gates pass.
     *
     * @param int $institutionId
     * @return array{ok: bool, gates: array<string, bool>, error?: string}
     */
    public function isAvailable(int $institutionId = 0): array
    {
        $gates = [];

        // Gate 1: Tenant setting (may not be available in CLI)
        try {
            $settings = academic_similarity_get_settings($this->tenantId);
            $gates['setting_enabled'] = !empty($settings['semantic_match_enabled']) && $settings['semantic_match_enabled'] === '1';
        } catch (\Throwable $e) {
            $gates['setting_enabled'] = false;
        }

        // Gate 2: Capability registered in the kernel registry
        try {
            $gates['capability_registered'] = app()->capabilities()->has('academic_similarity.semantic.health@1')
                && app()->capabilities()->has('academic_similarity.semantic.compare@1');
        } catch (\Throwable $e) {
            $gates['capability_registered'] = false;
        }

        // Gate 3: Python service endpoint reachable through ServiceProxy
        $gates['service_reachable'] = true;
        if ($gates['capability_registered']) {
            try {
                $health = $this->health();
                $gates['service_reachable'] = !empty($health['ok']);
            } catch (\Throwable $e) {
                $gates['service_reachable'] = false;
            }
        }

        // Gate 4: Plan feature (if institution is known)
        $gates['plan_enabled'] = true;
        if ($institutionId > 0) {
            try {
                $quotaService = new AcademicSimilarityQuotaService($this->tenantId);
                $subscription = $quotaService->getSubscription($institutionId);
                $gates['plan_enabled'] = !empty($subscription['plan_limits']['semantic_enabled']);
            } catch (\Throwable $e) {
                $gates['plan_enabled'] = false;
            }
        }

        $allPass = !in_array(false, $gates, true);

        $errors = [];
        if (!$gates['setting_enabled']) {
            $errors[] = 'Semantic matching is disabled in tenant settings';
        }
        if (!$gates['capability_registered']) {
            $errors[] = 'Semantic service capability is not registered';
        }
        if (!$gates['service_reachable']) {
            $errors[] = 'Semantic Python service endpoint is not reachable';
        }
        if (!$gates['plan_enabled']) {
            $errors[] = 'Plan does not include semantic matching';
        }

        return [
            'ok' => $allPass,
            'gates' => $gates,
            'error' => $allPass ? null : implode('; ', $errors),
        ];
    }
}
