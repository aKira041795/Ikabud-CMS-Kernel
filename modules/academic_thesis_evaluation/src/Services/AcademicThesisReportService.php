<?php
declare(strict_types=1);

/**
 * Generates evaluation reports: reviewer reports, student revision reports, final evaluation reports.
 */
class AcademicThesisReportService
{
    private const REPORT_SCHEMA_ID = 'academic_thesis_evaluation.evaluation_case_report';
    private const REPORT_SCHEMA_VERSION = '1.0';
    private const CANONICALIZATION_VERSION = '1.0';

    private string $tenantId;
    /** @var callable|null */
    private $generatedAtProvider;

    public function __construct(string $tenantId, ?callable $generatedAtProvider = null)
    {
        $this->tenantId = $tenantId;
        $this->generatedAtProvider = $generatedAtProvider;
    }

    public function generateEvaluationReport(int $caseId): array
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        // Gather all data
        $stageRepo = new EvaluationStageRepository($this->tenantId);
        $stages = $stageRepo->findByCaseId($caseId);

        $manuscriptRepo = new ManuscriptVersionRepository($this->tenantId);
        $manuscripts = $manuscriptRepo->findByCaseId($caseId);

        $snapshotRepo = new AissEvidenceSnapshotRepository($this->tenantId);
        $snapshots = $snapshotRepo->findByCaseId($caseId);

        $decisionRepo = new EvidenceReviewDecisionRepository($this->tenantId);
        $decisions = $decisionRepo->findByCaseId($caseId);

        $rubricService = new AcademicThesisRubricService($this->tenantId);
        $rubricSummary = $rubricService->getSummary($caseId);

        $revisionRepo = new RevisionRequestRepository($this->tenantId);
        $revisions = $revisionRepo->findByCaseId($caseId);

        $dispositionRepo = new FinalDispositionRepository($this->tenantId);
        $disposition = $dispositionRepo->findByCaseId($caseId);

        $auditRepo = new AuditEventRepository($this->tenantId);
        $auditTrail = $auditRepo->findByCase($caseId);

        $stages = $this->sortByTimestampThenId($stages, 'created_at', 'stages');
        $manuscripts = $this->sortRows($manuscripts, [
            ['field' => 'version_number', 'type' => 'number', 'nullable' => false],
            ['field' => 'id', 'type' => 'number', 'nullable' => false],
        ], 'manuscripts');
        $snapshots = $this->sortByTimestampThenId($snapshots, 'generated_at', 'snapshots');
        $decisions = $this->sortRows($decisions, [
            ['field' => 'id', 'type' => 'number', 'nullable' => false],
        ], 'decisions');
        $revisions = $this->sortByTimestampThenId($revisions, 'created_at', 'revisions');
        $auditTrail = $this->sortByTimestampThenId($auditTrail, 'created_at', 'audit_trail');
        if ($rubricSummary['ok']) {
            $rubricSummary['data'] = $this->normalizeRubricSummary($rubricSummary['data'] ?? []);
        }

        $report = [
            'report_schema' => [
                'id' => self::REPORT_SCHEMA_ID,
                'version' => self::REPORT_SCHEMA_VERSION,
            ],
            'case' => $case,
            'analysis_profile' => $this->buildAnalysisProfile($case, $snapshots),
            'stages_completed' => array_map(function ($s) {
                return [
                    'stage' => $s['stage_code'],
                    'status' => $s['status'],
                    'outcome' => $s['outcome'],
                    'completed_at' => $s['completed_at'],
                ];
            }, $stages),
            'manuscript_versions' => array_map(function ($m) {
                return [
                    'version' => $m['version_number'],
                    'file_hash' => $m['file_hash'],
                    'submitted_at' => $m['created_at'],
                    'is_revision' => (bool)$m['is_revision'],
                ];
            }, $manuscripts),
            'evidence_snapshots' => array_map(function ($s) {
                return [
                    'snapshot_id' => $s['id'],
                    'version' => $s['evidence_version'],
                    'maturity' => $this->decodeJsonField($s['maturity_metadata'] ?? null, [], 'snapshot.maturity_metadata'),
                    'warnings' => $this->decodeJsonField($s['capability_warnings'] ?? null, [], 'snapshot.capability_warnings'),
                    'generated_at' => $s['generated_at'],
                ];
            }, $snapshots),
            'evidence_decisions' => array_map(function ($d) {
                return [
                    'machine_relationship' => $d['machine_relationship'],
                    'reviewer_relationship' => $d['reviewer_relationship'],
                    'action' => $d['reviewer_action'],
                    'reason' => $d['reviewer_reason'],
                ];
            }, $decisions),
            'rubric_summary' => $rubricSummary['ok'] ? ($rubricSummary['data'] ?? []) : [],
            'revisions' => array_map(function ($r) {
                return [
                    'category' => $r['category'],
                    'severity' => $r['severity'],
                    'status' => $r['status'],
                    'instruction' => $r['instruction'],
                ];
            }, $revisions),
            'disposition' => $disposition ? [
                'status' => $disposition['status'],
                'summary' => $disposition['decision_summary'],
                'conditions' => $disposition['conditions'],
                'effective_date' => $disposition['effective_date'],
                'decided_by' => $disposition['decided_by'],
                'authority_role' => $disposition['authority_role'],
            ] : null,
            'audit_trail' => array_map(function ($a) {
                return [
                    'action' => $a['action'],
                    'actor_role' => $a['actor_role'],
                    'created_at' => $a['created_at'],
                ];
            }, $auditTrail),
        ];

        $report['content_digest'] = $this->contentDigest($report);

        return ['ok' => true, 'data' => $report];
    }

    public static function canonicalJson(array $data): string
    {
        $previousPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode(
                self::canonicalizeValue($data),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } finally {
            if ($previousPrecision !== false) {
                ini_set('serialize_precision', $previousPrecision);
            }
        }
    }

    public static function contentDigestForData(array $data): array
    {
        unset($data['content_digest']);
        return [
            'algorithm' => 'sha256',
            'canonicalization_version' => self::CANONICALIZATION_VERSION,
            'value' => hash('sha256', self::canonicalJson($data)),
        ];
    }

    /**
     * Build a self-describing analysis profile — the authoritative provenance
     * record for how this evaluation was produced.
     *
     * Contract shape (schema_version 1.0):
     * {
     *   schema_version, engine: {id, version}, mode,
     *   extensions: { module_id: {enabled, version} },
     *   capabilities: { short_name: 'disabled' | {id, status, version} },
     *   label, reason?, generated_at
     * }
     */
    private function buildAnalysisProfile(array $case, array $snapshots): array
    {
        $base = [
            'schema_version' => '1.0',
            'engine' => [
                'id' => 'academic_thesis_evaluation',
                'version' => $this->getModuleVersion(),
            ],
            'mode' => 'standalone',
            'extensions' => [
                'academic_similarity' => ['enabled' => false, 'version' => null],
            ],
            'capabilities' => [
                'textual_matching' => 'disabled',
                'semantic_resemblance' => 'disabled',
                'citation_detection' => 'disabled',
                'context_analysis' => 'disabled',
            ],
            'label' => 'Standalone — No AISS analysis was performed',
            'generated_at' => $this->resolveGeneratedAt($case, $snapshots),
        ];

        if (empty($snapshots)) {
            return $base;
        }

        $aissDataSnapshots = [];

        foreach ($snapshots as $s) {
            if (!empty($s['textual_result']) || !empty($s['aiss_submission_id'])) {
                $aissDataSnapshots[] = $s;
            }
        }

        if ($aissDataSnapshots !== []) {
            $latestAissSnapshot = $aissDataSnapshots[count($aissDataSnapshots) - 1];
            $maturity = $this->decodeJsonField($latestAissSnapshot['maturity_metadata'] ?? null, [], 'snapshot.maturity_metadata');
            $capMap = [
                'textual_matching' => 'academic_similarity.textual.match@1',
                'semantic_resemblance' => 'academic_similarity.semantic.resemblance@1',
                'citation_detection' => 'academic_similarity.citation.analysis@1',
                'context_analysis' => 'academic_similarity.context.analysis@1',
            ];

            foreach ($capMap as $shortName => $capId) {
                $status = $maturity[$shortName] ?? 'unavailable';
                if (in_array($status, ['stable', 'beta', 'experimental'], true)) {
                    $base['capabilities'][$shortName] = [
                        'id' => $capId,
                        'status' => $status,
                        'version' => $latestAissSnapshot['capability_version'] ?? '1',
                    ];
                }
            }
            $base['mode'] = 'aiss_assisted';
            $base['extensions']['academic_similarity'] = [
                'enabled' => true,
                'version' => $this->getAissVersion(),
            ];
            $base['label'] = 'AISS-Assisted — Similarity and scholarship evidence was analyzed';
            return $base;
        }

        $latestSnapshot = $snapshots[count($snapshots) - 1];
        $maturity = $this->decodeJsonField($latestSnapshot['maturity_metadata'] ?? null, [], 'snapshot.maturity_metadata');
        $aissIntegration = $maturity['aiss_integration'] ?? null;
        if ($aissIntegration === 'disabled_by_tenant') {
            $base['label'] = 'Standalone — AISS integration is disabled for this tenant';
            $base['reason'] = 'disabled_by_tenant';
        } elseif ($aissIntegration === 'standalone_mode') {
            $base['label'] = 'Standalone — AISS module is not available';
            $base['reason'] = 'standalone_mode';
        }

        return $base;
    }

    private function contentDigest(array $report): array
    {
        return self::contentDigestForData($report);
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_float($item) && !is_finite($item)) {
                    throw new \RuntimeException('Report contains non-finite float');
                }
            }

            if (array_is_list($value)) {
                return array_map([self::class, 'canonicalizeValue'], $value);
            }

            ksort($value, SORT_STRING);
            $out = [];
            foreach ($value as $key => $item) {
                $out[(string)$key] = self::canonicalizeValue($item);
            }
            return $out;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new \RuntimeException('Report contains non-finite float');
        }

        return $value;
    }

    private function resolveGeneratedAt(array $case, array $sortedSnapshots): string
    {
        if ($this->generatedAtProvider !== null) {
            $value = ($this->generatedAtProvider)($case, $sortedSnapshots);
            if (!is_string($value)) {
                throw new \RuntimeException('analysis_profile.generated_at provider must return a timestamp string');
            }
            return $this->normalizeProviderTimestamp($value);
        }

        if ($sortedSnapshots !== []) {
            $latest = $sortedSnapshots[count($sortedSnapshots) - 1];
            return $this->storedDatetimeToUtc((string)$latest['generated_at'], 'snapshots.generated_at');
        }

        if (!empty($case['created_at'])) {
            return $this->storedDatetimeToUtc((string)$case['created_at'], 'case.created_at');
        }

        return '1970-01-01T00:00:00+00:00';
    }

    private function normalizeProviderTimestamp(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new \RuntimeException('analysis_profile.generated_at provider returned invalid timestamp format');
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
            throw new \RuntimeException('analysis_profile.generated_at provider returned invalid calendar timestamp');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s+00:00');
    }

    private function storedDatetimeToUtc(string $value, string $field): string
    {
        $this->assertStoredDatetime($value, $field);
        return str_replace(' ', 'T', $value) . '+00:00';
    }

    private function assertStoredDatetime(mixed $value, string $field): void
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            throw new \RuntimeException("Invalid {$field}: expected Y-m-d H:i:s");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
            throw new \RuntimeException("Invalid {$field}: non-calendar timestamp");
        }
    }

    private function sortByTimestampThenId(array $rows, string $timestampField, string $section): array
    {
        return $this->sortRows($rows, [
            ['field' => $timestampField, 'type' => 'timestamp', 'nullable' => false],
            ['field' => 'id', 'type' => 'number', 'nullable' => false],
        ], $section);
    }

    private function sortRows(array $rows, array $specs, string $section): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("Invalid {$section} row {$index}");
            }
            foreach ($specs as $spec) {
                $field = (string)$spec['field'];
                if (!array_key_exists($field, $row)) {
                    throw new \RuntimeException("Missing {$section}.{$field}");
                }
                if (($row[$field] ?? null) === null && empty($spec['nullable'])) {
                    throw new \RuntimeException("Missing {$section}.{$field}");
                }
                if (($spec['type'] ?? '') === 'timestamp') {
                    $this->assertStoredDatetime($row[$field] ?? null, "{$section}.{$field}");
                } elseif (($spec['type'] ?? '') === 'number' && !is_numeric($row[$field])) {
                    throw new \RuntimeException("Invalid {$section}.{$field}: expected numeric value");
                }
            }
        }

        usort($rows, function (array $a, array $b) use ($specs): int {
            foreach ($specs as $spec) {
                $field = (string)$spec['field'];
                $av = $a[$field] ?? null;
                $bv = $b[$field] ?? null;
                if ($av === null && $bv === null) {
                    continue;
                }
                if ($av === null) {
                    return 1;
                }
                if ($bv === null) {
                    return -1;
                }
                $cmp = match ($spec['type'] ?? 'string') {
                    'number' => ((float)$av <=> (float)$bv),
                    default => ((string)$av <=> (string)$bv),
                };
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return 0;
        });

        return $rows;
    }

    private function normalizeRubricSummary(array $summary): array
    {
        $summaries = $summary['reviewer_summaries'] ?? [];
        if (is_array($summaries)) {
            foreach ($summaries as &$reviewerSummary) {
                if (!is_array($reviewerSummary)) {
                    continue;
                }
                $scores = $reviewerSummary['scores'] ?? [];
                if (!is_array($scores)) {
                    continue;
                }
                usort($scores, function (array $a, array $b): int {
                    return $this->compareNullable($a['criterion'] ?? null, $b['criterion'] ?? null, 'string')
                        ?: $this->compareNullable($a['score'] ?? null, $b['score'] ?? null, 'number')
                        ?: $this->compareNullable($a['weight'] ?? null, $b['weight'] ?? null, 'number')
                        ?: $this->compareNullable($a['comment'] ?? null, $b['comment'] ?? null, 'string');
                });
                $reviewerSummary['scores'] = $scores;
            }
            unset($reviewerSummary);

            usort($summaries, function (array $a, array $b): int {
                return $this->compareNullable($a['reviewer_id'] ?? null, $b['reviewer_id'] ?? null, 'number')
                    ?: $this->compareNullable($a['role'] ?? null, $b['role'] ?? null, 'string');
            });
            $summary['reviewer_summaries'] = $summaries;
        }
        return $summary;
    }

    private function compareNullable(mixed $a, mixed $b, string $type): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        return $type === 'number' ? ((float)$a <=> (float)$b) : ((string)$a <=> (string)$b);
    }

    private function decodeJsonField(?string $json, array $default, string $field): array
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON object/array in {$field}");
        }
        return $decoded;
    }

    private function getModuleVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $json = @file_get_contents(__DIR__ . '/../../module.json');
            $data = $json ? json_decode($json, true) : [];
            $version = $data['version'] ?? '0.1.0';
        }
        return $version;
    }

    private function getAissVersion(): ?string
    {
        static $aissVersion = null;
        if ($aissVersion === null) {
            $json = @file_get_contents(__DIR__ . '/../../../academic_similarity/module.json');
            $data = $json ? json_decode($json, true) : [];
            $aissVersion = $data['version'] ?? null;
        }
        return $aissVersion;
    }
}
