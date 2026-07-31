<?php
declare(strict_types=1);

class AcademicAssessmentBundleService
{
    private const ASSESSMENT_VERSION = 'assessment-bundle-v1.1';
    private const SUGGESTION_RULE_VERSION = 'suggestion-rules-v1.1';

    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private AcademicSimilarityAssessmentRepository $repo;
    private AcademicSimilaritySubmissionRepository $submissionRepo;
    private AcademicSimilarityMatchRepository $matchRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = academic_similarity_resolve_tenant_id($tenantId);
        $this->db = academic_similarity_db($tenantId);
        $this->repo = new AcademicSimilarityAssessmentRepository($tenantId);
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
    }

    public function generate(int $submissionId, array $options = []): array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'error' => 'Submission not found'];
        }

        $textVersion = $this->latestTextVersion($submissionId);
        $text = (string)($textVersion['extracted_text'] ?? '');
        $textHash = (string)($textVersion['text_hash_sha256'] ?? ($submission['text_hash_sha256'] ?? ''));
        $manuscriptHash = (string)($submission['checksum_sha256'] ?? $textHash);
        $internetCoverage = $this->latestInternetCoverage($submissionId);
        $settings = academic_similarity_get_settings($this->tenantId);

        $idempotencyInput = [
            'submission_id' => $submissionId,
            'manuscript_hash' => $manuscriptHash,
            'text_hash' => $textHash,
            'policy' => $options['payload_policy'] ?? 'deterministic_internal_only',
            'assessment_version' => self::ASSESSMENT_VERSION,
            'suggestion_rule_version' => self::SUGGESTION_RULE_VERSION,
        ];
        $idempotencyKey = hash('sha256', json_encode($idempotencyInput));
        $existing = $this->repo->findRunByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->buildResponse($existing);
        }

        $sections = $this->extractSections($text);
        $claims = $this->extractClaims($text, $sections);
        $matches = $this->matchRepo->findBySubmissionId($submissionId);
        $evidence = $this->buildEvidence($submission, $textVersion, $matches, $internetCoverage, $claims);
        $suggestions = $this->buildSuggestions($evidence, $claims, $internetCoverage);
        $coverage = $this->coverage($internetCoverage, count($matches), count($claims));
        $maturity = [
            'integrity_and_provenance' => 'beta',
            'attribution_and_reuse' => count($matches) > 0 ? 'beta' : 'partial',
            'research_alignment' => count($claims) > 0 ? 'experimental' : 'partial',
            'source_relevance_and_coverage' => (($internetCoverage['imported_count'] ?? 0) > 0) ? 'partial' : 'unavailable',
            'contribution_relationship' => count($claims) > 0 ? 'experimental' : 'unavailable',
            'reviewer_attention' => 'beta',
            'calibration' => 'experimental',
        ];
        $limitations = $this->limitations($coverage, $claims);

        $this->db->beginTransaction();
        try {
            $runId = $this->repo->createRun([
                'submission_id' => $submissionId,
                'manuscript_hash' => $manuscriptHash,
                'text_version_id' => isset($textVersion['id']) ? (int)$textVersion['id'] : null,
                'text_hash_sha256' => $textHash,
                'assessment_version' => self::ASSESSMENT_VERSION,
                'search_provider' => $internetCoverage['provider'] ?? ($settings['internet_search_engine'] ?? null),
                'sanitized_queries' => $this->decodeJson($internetCoverage['queries_json'] ?? null),
                'coverage' => $coverage,
                'settings' => [
                    'external_document_text_processing_allowed' => false,
                    'payload_policy' => $options['payload_policy'] ?? 'deterministic_internal_only',
                    'internet_search_engine' => $settings['internet_search_engine'] ?? 'google_scholar',
                ],
                'thresholds' => [
                    'minimum_prior_art_imports' => 3,
                    'near_match_threshold' => (float)($settings['near_match_threshold'] ?? 0.8),
                ],
                'provider_versions' => [
                    'structure' => 'deterministic-heading-v1',
                    'relevance' => 'deterministic-keyword-v1',
                    'suggestions' => self::SUGGESTION_RULE_VERSION,
                ],
                'calibration_profile' => [
                    'id' => 'uncalibrated-default-v1',
                    'maturity' => 'experimental',
                    'decision_labels_enabled' => false,
                ],
                'payload_disclosures' => [
                    'pdf_bytes' => false,
                    'abstract_text' => false,
                    'claims' => false,
                    'passages' => false,
                    'embeddings' => false,
                    'search_queries' => !empty($internetCoverage),
                ],
                'maturity' => $maturity,
                'limitations' => $limitations,
                'status' => $coverage['status'] === 'sufficient' ? 'completed' : 'completed_partial',
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->repo->replaceSections($runId, $submissionId, $sections);
            $this->repo->replaceClaims($runId, $submissionId, $claims);
            $this->repo->replaceEvidence($runId, $submissionId, $evidence);
            $this->repo->replaceSuggestions($runId, $submissionId, $suggestions);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Assessment bundle persistence failed: ' . $e->getMessage()];
        }

        return $this->buildResponse($this->repo->findRunById($runId) ?? []);
    }

    private function buildResponse(array $run): array
    {
        if ($run === []) {
            return ['ok' => false, 'error' => 'Assessment run not found'];
        }
        $rows = $this->repo->bundleRows((int)$run['id']);
        return [
            'ok' => true,
            'assessment_run_id' => (int)$run['id'],
            'submission_id' => (int)$run['submission_id'],
            'manuscript_hash' => (string)$run['manuscript_hash'],
            'capability_version' => '1.0',
            'maturity' => $this->decodeJson($run['maturity_json'] ?? null),
            'coverage' => $this->decodeJson($run['coverage_json'] ?? null),
            'limitations' => $this->decodeJson($run['limitations_json'] ?? null),
            'provenance' => [
                'text_hash_sha256' => (string)$run['text_hash_sha256'],
                'assessment_version' => (string)$run['assessment_version'],
                'extraction_version' => (string)$run['extraction_version'],
                'corpus_cutoff_at' => (string)$run['corpus_cutoff_at'],
                'payload_disclosures' => $this->decodeJson($run['payload_disclosures_json'] ?? null),
                'provider_versions' => $this->decodeJson($run['provider_versions_json'] ?? null),
                'calibration_profile' => $this->decodeJson($run['calibration_profile_json'] ?? null),
            ],
            'structure' => ['sections' => $rows['sections'], 'claims' => $rows['claims']],
            'evidence' => $rows['evidence'],
            'suggestions' => $rows['suggestions'],
        ];
    }

    private function latestTextVersion(int $submissionId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_text_versions WHERE tenant_id = :tid AND submission_id = :sid AND text_type = 'submission' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function latestInternetCoverage(int $submissionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ac_similarity_internet_search_runs WHERE tenant_id = :tid AND submission_id = :sid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function extractSections(string $text): array
    {
        $known = ['abstract', 'introduction', 'background', 'literature review', 'methodology', 'methods', 'results', 'findings', 'discussion', 'conclusion', 'recommendations', 'references'];
        $sections = [];
        if (preg_match_all('/^(abstract|introduction|background|literature review|methodology|methods|results|findings|discussion|conclusion|recommendations|references)\\b.*$/im', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                $heading = trim((string)$match[0]);
                $start = (int)$match[1];
                $next = $matches[0][$index + 1][1] ?? strlen($text);
                $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $matches[1][$index][0]), '_'));
                $sections[] = [
                    'section_key' => $key,
                    'heading' => $heading,
                    'section_order' => $index + 1,
                    'start_offset' => $start,
                    'end_offset' => (int)$next,
                    'extraction_confidence' => in_array(strtolower($matches[1][$index][0]), $known, true) ? 0.85 : 0.55,
                    'maturity' => 'beta',
                ];
            }
        }
        if ($sections === [] && trim($text) !== '') {
            $sections[] = [
                'section_key' => 'document_body',
                'heading' => 'Document body',
                'section_order' => 1,
                'start_offset' => 0,
                'end_offset' => strlen($text),
                'extraction_confidence' => 0.35,
                'maturity' => 'partial',
            ];
        }
        return $sections;
    }

    private function extractClaims(string $text, array $sections): array
    {
        $patterns = [
            'research_objective' => '/\\b(objective|aim|purpose)\\b[^.?!]{20,240}[.?!]/i',
            'research_question' => '/\\b(research question|this study asks|question)\\b[^.?!?]{10,220}[?\\.]/i',
            'method' => '/\\b(method|methodology|participants|respondents|data were|data was)\\b[^.?!]{20,240}[.?!]/i',
            'finding' => '/\\b(result|finding|found|revealed|showed|indicated)\\b[^.?!]{20,240}[.?!]/i',
            'conclusion' => '/\\b(conclude|conclusion|therefore|suggests)\\b[^.?!]{20,240}[.?!]/i',
            'recommendation' => '/\\b(recommend|recommendation|should)\\b[^.?!]{20,240}[.?!]/i',
            'contribution' => '/\\b(contribution|novel|new approach|this study contributes|original)\\b[^.?!]{20,240}[.?!]/i',
        ];
        $claims = [];
        foreach ($patterns as $type => $pattern) {
            if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach (array_slice($matches[0], 0, 8) as $match) {
                $claims[] = [
                    'claim_type' => $type,
                    'claim_text' => trim((string)$match[0]),
                    'start_offset' => (int)$match[1],
                    'end_offset' => (int)$match[1] + strlen((string)$match[0]),
                    'extraction_confidence' => 0.62,
                    'machine_payload' => ['rule' => $type . '-keyword-v1'],
                ];
            }
        }
        return array_slice($claims, 0, 40);
    }

    private function buildEvidence(array $submission, array $textVersion, array $matches, array $coverage, array $claims): array
    {
        $items = [[
            'evidence_key' => 'integrity-file-text-hash',
            'dimension' => 'integrity_and_provenance',
            'evidence_type' => 'file_and_text_hash_recorded',
            'status' => (($submission['checksum_sha256'] ?? '') !== '' || ($textVersion['text_hash_sha256'] ?? '') !== '') ? 'observed' : 'uncertain',
            'rationale' => 'Stored file/text hashes support version identity checks; they do not verify authorship or research authenticity.',
            'uncertainty' => 'medium',
            'limitations' => ['Hash evidence cannot establish human authorship, fabrication, or AI generation.'],
        ]];

        foreach (array_slice($matches, 0, 25) as $match) {
            $items[] = [
                'evidence_key' => 'match-' . (int)($match['id'] ?? 0),
                'dimension' => 'attribution_and_reuse',
                'evidence_type' => (string)($match['match_type'] ?? 'textual_match'),
                'status' => ((int)($match['is_excluded'] ?? 0) === 1) ? 'reviewer_excluded' : 'needs_review',
                'match_id' => (int)($match['id'] ?? 0),
                'source_id' => (int)($match['source_id'] ?? 0),
                'rationale' => 'Textual overlap was detected and requires reviewer interpretation with citation and context.',
                'uncertainty' => 'medium',
                'limitations' => ['A textual match is not a plagiarism verdict.'],
                'payload' => [
                    'matched_word_count' => (int)($match['matched_word_count'] ?? 0),
                    'confidence' => (float)($match['match_confidence'] ?? 0),
                ],
            ];
        }

        if ($claims === []) {
            $items[] = [
                'evidence_key' => 'research-structure-missing',
                'dimension' => 'research_alignment',
                'evidence_type' => 'required_claims_missing',
                'status' => 'not_evidenced',
                'rationale' => 'Deterministic extraction did not find enough objective, method, finding, or contribution claims for a document-level alignment profile.',
                'uncertainty' => 'high',
                'limitations' => ['Headings or claims may be phrased outside the current deterministic rules.'],
            ];
        } else {
            $items[] = [
                'evidence_key' => 'research-claims-detected',
                'dimension' => 'research_alignment',
                'evidence_type' => 'claim_structure_detected',
                'status' => 'partially_supported',
                'rationale' => 'Candidate research claims were extracted with offsets for reviewer inspection.',
                'uncertainty' => 'medium',
                'payload' => ['claim_count' => count($claims)],
                'limitations' => ['Keyword extraction can miss implicit claims and cannot establish quality by itself.'],
            ];
        }

        $imported = (int)($coverage['imported_count'] ?? 0);
        $items[] = [
            'evidence_key' => 'prior-art-coverage',
            'dimension' => 'contribution_relationship',
            'evidence_type' => 'prior_art_coverage',
            'status' => $imported >= 3 ? 'ready_for_review' : 'insufficient_coverage',
            'rationale' => $imported >= 3
                ? 'Imported academic sources are available for relative contribution review.'
                : 'Coverage is below the default minimum for no-close-prior-art language.',
            'uncertainty' => $imported >= 3 ? 'medium' : 'high',
            'payload' => ['imported_count' => $imported, 'minimum_required' => 3],
            'limitations' => ['Corpus incompleteness and inaccessible sources can hide close prior art.'],
        ];

        return $items;
    }

    private function buildSuggestions(array $evidence, array $claims, array $coverage): array
    {
        $suggestions = [];
        foreach ($evidence as $index => $item) {
            if (($item['dimension'] ?? '') === 'attribution_and_reuse' && ($item['status'] ?? '') === 'needs_review') {
                $suggestion = [
                    'suggestion_key' => 'verify-match-' . (int)($item['match_id'] ?? $index),
                    'category' => 'attribution_and_reuse',
                    'priority' => 'high',
                    'reviewer_action' => 'verify',
                    'title' => 'Verify attribution for a matching passage',
                    'rationale' => 'A matching passage needs citation and context review before any academic interpretation is made.',
                    'evidence_ids' => [(string)($item['evidence_key'] ?? ('match-' . (int)($item['match_id'] ?? $index)))],
                    'source_context' => ['match_id' => $item['match_id'] ?? null, 'source_id' => $item['source_id'] ?? null],
                    'uncertainty' => 'medium',
                    'maturity' => 'beta',
                    'limitations' => ['This suggestion is not a plagiarism finding.'],
                ];
                if ($this->isPermittedSuggestionText($suggestion)) {
                    $suggestions[] = $suggestion;
                }
            }
            if (($item['evidence_type'] ?? '') === 'required_claims_missing') {
                $suggestion = [
                    'suggestion_key' => 'clarify-research-structure',
                    'category' => 'research_alignment',
                    'priority' => 'medium',
                    'reviewer_action' => 'clarify',
                    'title' => 'Clarify research objectives, methods, findings, or contribution claims',
                    'rationale' => 'The deterministic parser could not find enough structure to support relevance or contribution review.',
                    'evidence_ids' => [(string)($item['evidence_key'] ?? 'research-structure-missing')],
                    'uncertainty' => 'high',
                    'maturity' => 'beta',
                    'limitations' => ['Reviewer inspection is required because structure extraction is incomplete.'],
                ];
                if ($this->isPermittedSuggestionText($suggestion)) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        if ((int)($coverage['imported_count'] ?? 0) < 3) {
            $suggestion = [
                'suggestion_key' => 'expand-prior-art-coverage',
                'category' => 'contribution_relationship',
                'priority' => 'high',
                'reviewer_action' => 'compare',
                'title' => 'Compare contribution claims against more prior work',
                'rationale' => 'The searched/imported corpus is not sufficient for no-close-prior-art language.',
                'evidence_ids' => ['prior-art-coverage'],
                'source_context' => ['imported_count' => (int)($coverage['imported_count'] ?? 0), 'minimum_required' => 3],
                'uncertainty' => 'high',
                'maturity' => 'experimental',
                'limitations' => ['No machine output may equate weak coverage with novelty.'],
            ];
            if ($this->isPermittedSuggestionText($suggestion)) {
                $suggestions[] = $suggestion;
            }
        }

        return array_slice($suggestions, 0, 20);
    }

    /**
     * Reviewer-assist language gate: reject machine suggestion text that
     * asserts a forbidden verdict (authentic, plagiarized, novel, irrelevant,
     * ai-generated). Limitations are excluded — they may warn against verdict
     * language and still mention the term.
     */
    private function isPermittedSuggestionText(array $suggestion): bool
    {
        $check = implode(' ', [
            (string)($suggestion['title'] ?? ''),
            (string)($suggestion['rationale'] ?? ''),
            (string)($suggestion['reviewer_action'] ?? ''),
        ]);
        return !AcademicSimilarityEvidenceTaxonomy::containsForbiddenMachineConclusion($check);
    }

    private function coverage(array $internetCoverage, int $matchCount, int $claimCount): array
    {
        $imported = (int)($internetCoverage['imported_count'] ?? 0);
        return [
            'status' => $imported >= 3 ? 'sufficient' : 'partial',
            'match_count' => $matchCount,
            'claim_count' => $claimCount,
            'internet_status' => $internetCoverage['status'] ?? 'unavailable',
            'candidate_count' => (int)($internetCoverage['candidate_count'] ?? 0),
            'imported_count' => $imported,
            'minimum_prior_art_imports' => 3,
        ];
    }

    private function limitations(array $coverage, array $claims): array
    {
        $limitations = [
            'AISS output is reviewer-assist evidence, not an authenticity, plagiarism, novelty, relevance, or pass/fail decision.',
            'Zero similarity is not evidence of authenticity or novelty.',
            'Uncalibrated dimensions remain experimental until reviewer-labeled benchmark data is approved.',
        ];
        if (($coverage['status'] ?? '') !== 'sufficient') {
            $limitations[] = 'Prior-art coverage is insufficient for no-close-prior-art language.';
        }
        if ($claims === []) {
            $limitations[] = 'Document structure extraction is incomplete; relevance and contribution profiles are limited.';
        }
        return $limitations;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
