<?php
declare(strict_types=1);

/**
 * AISS — Knowledge Lineage Service (Phase 6)
 *
 * Represents the scholarly lineage of a submission as a directed graph.
 * Tracks how concepts, claims, methods, and theories flow from source
 * to source and ultimately into the submission.
 *
 * Core entities: concept, claim, method, source, citation, document,
 * relationship, contribution.
 *
 * Relationship edges: cites, supports, extends, refines, replicates,
 * contradicts, translates, applies, synthesizes.
 *
 * This is a research-grade feature and should not delay the diagnostic MVP.
 * The current implementation provides source-level lineage; concept-level
 * lineage requires the Phase 2+ context analysis pipeline to be fully
 * operational.
 */

class AcademicSimilarityKnowledgeLineageService
{
    private string $tenantId;
    private AcademicSimilaritySourceRepository $sourceRepo;
    private AcademicSimilarityMatchRepository $matchRepo;

    /** Edge types between documents in the lineage graph */
    public const EDGE_CITES        = 'cites';
    public const EDGE_SUPPORTS     = 'supports';
    public const EDGE_EXTENDS      = 'extends';
    public const EDGE_REFINES      = 'refines';
    public const EDGE_REPLICATES   = 'replicates';
    public const EDGE_CONTRADICTS  = 'contradicts';
    public const EDGE_TRANSLATES   = 'translates';
    public const EDGE_APPLIES      = 'applies';
    public const EDGE_SYNTHESIZES  = 'synthesizes';

    public const EDGE_TYPES = [
        self::EDGE_CITES,
        self::EDGE_SUPPORTS,
        self::EDGE_EXTENDS,
        self::EDGE_REFINES,
        self::EDGE_REPLICATES,
        self::EDGE_CONTRADICTS,
        self::EDGE_TRANSLATES,
        self::EDGE_APPLIES,
        self::EDGE_SYNTHESIZES,
    ];

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
    }

    /**
     * Build a knowledge lineage graph for a submission.
     *
     * Returns nodes (documents/sources) and edges (relationships) that
     * describe how the submission relates to its source material and
     * how the sources relate to each other.
     *
     * @param int $submissionId
     * @return array{ok: bool, graph?: array, error?: string}
     */
    public function buildGraph(int $submissionId): array
    {
        $matches = $this->matchRepo->findBySubmissionId($submissionId);
        if (empty($matches)) {
            return [
                'ok' => true,
                'graph' => [
                    'nodes' => [],
                    'edges' => [],
                    'metadata' => [
                        'submission_id' => $submissionId,
                        'total_nodes' => 0,
                        'total_edges' => 0,
                        'generated_at' => date('Y-m-d H:i:s'),
                    ],
                ],
            ];
        }

        $nodes = [];
        $edges = [];
        $sourceIds = [];

        // Collect unique source IDs
        foreach ($matches as $match) {
            $sid = (int)($match['source_id'] ?? 0);
            if ($sid > 0) {
                $sourceIds[$sid] = $sid;
            }
        }

        // Build submission node
        $submissionTitle = $matches[0]['submission_title'] ?? ('Submission #' . $submissionId);
        $nodes['submission'] = [
            'id' => 'submission_' . $submissionId,
            'type' => 'submission',
            'label' => $submissionTitle,
            'original_id' => $submissionId,
            'match_count' => count($matches),
        ];

        // Build source nodes and edges from the submission to each source
        foreach ($sourceIds as $sid) {
            $source = $this->sourceRepo->findById($sid);
            $nodeId = 'source_' . $sid;
            $nodes[$nodeId] = [
                'id' => $nodeId,
                'type' => 'source',
                'label' => $source['title'] ?? ('Source #' . $sid),
                'original_id' => $sid,
                'author' => $source['author'] ?? '',
                'source_type' => $source['source_type'] ?? '',
            ];

            // Determine edge type from match scholarly relationships
            $sourceMatches = array_filter($matches, fn($m) => (int)($m['source_id'] ?? 0) === $sid);
            $edgeType = $this->determineEdgeType($sourceMatches);

            $edges[] = [
                'from' => 'submission_' . $submissionId,
                'to' => $nodeId,
                'type' => $edgeType,
                'match_count' => count($sourceMatches),
                'evidence' => $this->summarizeMatches($sourceMatches),
            ];
        }

        // Cross-source edges — infer relationships between sources
        // based on shared scholarly relationships and context types
        $crossEdges = $this->inferCrossSourceEdges($matches, $nodes);
        $edges = array_merge($edges, $crossEdges);

        return [
            'ok' => true,
            'graph' => [
                'nodes' => array_values($nodes),
                'edges' => $edges,
                'metadata' => [
                    'submission_id' => $submissionId,
                    'total_nodes' => count($nodes),
                    'total_edges' => count($edges),
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
            ],
        ];
    }

    /**
     * Determine the dominant edge type from a set of matches.
     */
    private function determineEdgeType(array $matches): string
    {
        $scholarlyRels = [];
        foreach ($matches as $match) {
            $rel = $match['scholarly_relationship'] ?? $match['machine_scholarly_relationship'] ?? null;
            if ($rel !== null) {
                $scholarlyRels[] = $rel;
            }
        }

        $counts = array_count_values($scholarlyRels);

        return match (true) {
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_EXTENSION]) => self::EDGE_EXTENDS,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_REFINEMENT]) => self::EDGE_REFINES,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_REPLICATION]) => self::EDGE_REPLICATES,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_TRANSLATION]) => self::EDGE_TRANSLATES,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_CRITIQUE]) => self::EDGE_CONTRADICTS,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SYNTHESIS]) => self::EDGE_SYNTHESIZES,
            isset($counts[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD]) => self::EDGE_APPLIES,
            default => self::EDGE_CITES,
        };
    }

    /**
     * Infer edges between sources based on shared context relationships.
     */
    private function inferCrossSourceEdges(array $matches, array $nodes): array
    {
        $edges = [];
        $sourceIds = array_keys(array_filter($nodes, fn($n) => $n['type'] === 'source'));

        // Group matches by context relationship type
        $byContext = [];
        foreach ($matches as $match) {
            $rel = $match['context_relationship'] ?? $match['machine_context_relationship'] ?? null;
            if ($rel !== null) {
                $sid = (int)($match['source_id'] ?? 0);
                $byContext[$rel][] = $sid;
            }
        }

        // If multiple sources share the same context relationship, create edges
        foreach ($byContext as $rel => $sids) {
            $uniqueSids = array_unique($sids);
            if (count($uniqueSids) < 2) continue;

            $sidsList = array_values($uniqueSids);
            for ($i = 0; $i < count($sidsList) - 1; $i++) {
                for ($j = $i + 1; $j < count($sidsList); $j++) {
                    $fromId = 'source_' . $sidsList[$i];
                    $toId = 'source_' . $sidsList[$j];

                    if (isset($nodes[$fromId]) && isset($nodes[$toId])) {
                        $edges[] = [
                            'from' => $fromId,
                            'to' => $toId,
                            'type' => self::EDGE_SUPPORTS,
                            'context' => $rel,
                            'match_count' => 1,
                        ];
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * Summarize match types for an edge.
     */
    private function summarizeMatches(array $matches): array
    {
        $types = [];
        foreach ($matches as $match) {
            $t = $match['match_type'] ?? 'unknown';
            $types[$t] = ($types[$t] ?? 0) + 1;
        }
        return $types;
    }

    /**
     * Render the graph as a Mermaid flowchart diagram.
     */
    public function renderMermaid(int $submissionId): string
    {
        $result = $this->buildGraph($submissionId);
        if (!($result['ok'] ?? false) || empty($result['graph']['nodes'])) {
            return 'flowchart LR
                sub_0["Submission #' . $submissionId . '"]
                style sub_0 fill:#e2e8f0,stroke:#64748b';
        }

        $graph = $result['graph'];
        $lines = ['flowchart LR'];

        // Add nodes
        foreach ($graph['nodes'] as $node) {
            $id = preg_replace('/[^a-zA-Z0-9_]/', '_', $node['id']);
            $label = htmlspecialchars($node['label'], ENT_QUOTES, 'UTF-8');
            if ($node['type'] === 'submission') {
                $lines[] = "    {$id}[\"{$label}\"]";
                $lines[] = "    style {$id} fill:#2563eb,color:#fff,stroke:#1d4ed8";
            } else {
                $lines[] = "    {$id}(\"{$label}\")";
                $lines[] = "    style {$id} fill:#f0fdf4,stroke:#22c55e";
            }
        }

        // Add edges
        foreach ($graph['edges'] as $edge) {
            $from = preg_replace('/[^a-zA-Z0-9_]/', '_', $edge['from']);
            $to = preg_replace('/[^a-zA-Z0-9_]/', '_', $edge['to']);
            $label = $edge['type'];
            $lines[] = "    {$from} -->|{$label}| {$to}";
        }

        return implode("\n", $lines);
    }
}
