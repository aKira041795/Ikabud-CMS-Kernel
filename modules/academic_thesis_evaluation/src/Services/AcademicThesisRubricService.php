<?php
declare(strict_types=1);

/**
 * Manages rubric templates, criteria, and scoring.
 */
class AcademicThesisRubricService
{
    private string $tenantId;
    private RubricTemplateRepository $templateRepo;
    private RubricResponseRepository $responseRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->templateRepo = new RubricTemplateRepository($tenantId);
        $this->responseRepo = new RubricResponseRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function getByCode(string $code): array
    {
        $template = $this->templateRepo->findByCode($code);
        if (!$template) {
            return ['ok' => false, 'error' => "Rubric template not found: {$code}"];
        }
        $template['criteria'] = $this->templateRepo->getCriteria((int)$template['id']);
        return ['ok' => true, 'data' => $template];
    }

    public function submitScores(int $caseId, int $assignmentId, array $data): array
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        if (empty($data['responses']) || !is_array($data['responses'])) {
            return ['ok' => false, 'error' => 'responses array required'];
        }

        $manuscriptVersionId = $case['active_manuscript_version_id'] ?? null;

        foreach ($data['responses'] as $response) {
            if (empty($response['criterion_id'])) {
                continue;
            }
            $this->responseRepo->upsert(
                $caseId,
                $assignmentId,
                (int)$response['criterion_id'],
                [
                    'score' => $response['score'] ?? null,
                    'comment' => $response['comment'] ?? null,
                    'evidence_reference' => $response['evidence_reference'] ?? null,
                    'manuscript_version_id' => $manuscriptVersionId,
                ]
            );
        }

        // Auto-complete the assignment
        $assignmentRepo = new ReviewerAssignmentRepository($this->tenantId);
        $assignmentRepo->complete($assignmentId);

        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => (int)($data['actor_id'] ?? 0),
            'action' => 'rubric_submitted',
            'after_state' => ['assignment_id' => $assignmentId, 'criteria_count' => count($data['responses'])],
        ]);

        return ['ok' => true, 'message' => 'Scores submitted'];
    }

    public function getSummary(int $caseId): array
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        $responses = $this->responseRepo->findByCaseId($caseId);

        // Group by reviewer ID (not role — multiple reviewers can share the same role)
        $byReviewer = [];
        foreach ($responses as $r) {
            $key = 'reviewer_' . ($r['reviewer_id'] ?? '0');
            $byReviewer[$key][] = $r;
        }

        // Calculate weighted totals per reviewer
        $summaries = [];
        foreach ($byReviewer as $role => $items) {
            $weightedSum = 0.0;
            $totalWeight = 0.0;
            $scores = [];
            foreach ($items as $item) {
                $score = (float)($item['score'] ?? 0);
                $weight = (float)($item['criterion_weight'] ?? 0);
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
                $scores[] = [
                    'criterion' => $item['criterion_label'],
                    'score' => $score,
                    'weight' => $weight,
                    'comment' => $item['comment'],
                ];
            }
            $summaries[] = [
                'role' => $role,
                'reviewer_id' => $items[0]['reviewer_id'] ?? null,
                'weighted_total' => $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0,
                'scores' => $scores,
            ];
        }

        return ['ok' => true, 'data' => [
            'case_id' => $caseId,
            'reviewer_summaries' => $summaries,
            'total_reviewers' => count($summaries),
        ]];
    }
}
