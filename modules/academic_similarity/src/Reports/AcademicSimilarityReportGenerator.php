<?php
declare(strict_types=1);

class AcademicSimilarityReportGenerator
{
    private string $tenantId;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
    }

    /**
     * Generate report data for a submission.
     * Returns structured array with all match/source/exclusion data.
     */
    public function generate(int $submissionId): array
    {
        $submissionRepo = new AcademicSimilaritySubmissionRepository($this->tenantId);
        $matchRepo = new AcademicSimilarityMatchRepository($this->tenantId);
        $sourceRepo = new AcademicSimilaritySourceRepository($this->tenantId);

        $submission = $submissionRepo->findById($submissionId);
        if (!$submission) {
            return ['ok' => false, 'error' => 'Submission not found'];
        }

        $matches = $matchRepo->findBySubmissionId($submissionId);
        $excludedMatches = $matchRepo->findExcluded($submissionId);

        // Group matches by source
        $sourceMatches = [];
        foreach ($matches as $match) {
            $sid = $match['source_id'];
            if (!isset($sourceMatches[$sid])) {
                $sourceMatches[$sid] = [
                    'source' => null,
                    'matches' => [],
                    'total_matched_words' => 0,
                ];
            }
            $sourceMatches[$sid]['matches'][] = $match;
            $sourceMatches[$sid]['total_matched_words'] += (int)($match['matched_word_count'] ?? 0);
        }

        // Load source info
        foreach ($sourceMatches as $sid => &$sm) {
            $sm['source'] = $sourceRepo->findById($sid);
        }
        unset($sm);

        // Calculate scores
        $scoringService = new AcademicSimilarityScoringService($this->tenantId);
        $scores = $scoringService->calculateScore($submissionId);

        // Build match data with highlights
        $matchData = [];
        foreach ($matches as $match) {
            $evidence = $matchRepo->getEvidence((int)$match['id']);
            $matchData[] = [
                'match' => $match,
                'evidence' => $evidence,
            ];
        }

        return [
            'ok' => true,
            'report' => [
                'submission' => $submission,
                'scores' => $scores,
                'total_matches' => count($matches),
                'total_excluded' => count($excludedMatches),
                'source_breakdown' => $sourceMatches,
                'matches' => $matchData,
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Build HTML report content, optionally with highlight spans.
     *
     * @param array $reportData
     * @param array|null $highlightData Optional {spans, stats, legend, highlighted_html, source_panels}
     * @return string
     */
    public function buildHtml(array $reportData, ?array $highlightData = null): string
    {
        $submission = $reportData['submission'] ?? [];
        $scores = $reportData['scores'] ?? [];
        $sourceBreakdown = $reportData['source_breakdown'] ?? [];
        $matches = $reportData['matches'] ?? [];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Similarity Report - ' . htmlspecialchars($submission['submission_title'] ?? '') . '</title>';
        $html .= '<style>';
        $html .= 'body{font-family:system-ui,sans-serif;max-width:960px;margin:0 auto;padding:2rem;color:#1a1a1a}';
        $html .= 'h1{font-size:1.5rem;margin-bottom:.25rem}';
        $html .= '.meta{color:#666;font-size:.875rem;margin-bottom:2rem}';
        $html .= '.score-card{display:inline-block;padding:1rem 2rem;border-radius:8px;text-align:center;margin-right:1rem;margin-bottom:1rem}';
        $html .= '.score-card.raw{background:#f0f0f0}';
        $html .= '.score-card.adjusted{background:#e8f5e9}';
        $html .= '.score-value{font-size:2rem;font-weight:700}';
        $html .= '.score-label{font-size:.75rem;text-transform:uppercase;color:#666}';
        $html .= 'table{width:100%;border-collapse:collapse;margin-top:1rem}';
        $html .= 'th,td{padding:.5rem;text-align:left;border-bottom:1px solid #e0e0e0}';
        $html .= 'th{background:#f5f5f5;font-weight:600}';
        $html .= '.excluded{opacity:.5;text-decoration:line-through}';
        $html .= '.match-source{margin-top:1.5rem;border:1px solid #e0e0e0;border-radius:8px;padding:1rem}';
        $html .= '.match-source h3{margin:0 0 .5rem}';
        $html .= '.highlight{padding:.25rem .5rem;background:#fff3cd;border-radius:3px}';
        $html .= '.footer{margin-top:3rem;font-size:.75rem;color:#999;border-top:1px solid #eee;padding-top:1rem}';

        // Highlight styles
        $html .= '.hl-exact{background:#fecaca;border-bottom:2px solid #dc2626}';
        $html .= '.hl-near{background:#fed7aa;border-bottom:2px solid #ea580c}';
        $html .= '.hl-semantic{background:#fef08a;border-bottom:2px solid #ca8a04}';
        $html .= '.hl-quote{background:#bfdbfe;border-bottom:2px solid #2563eb}';
        $html .= '.hl-excluded{background:#e5e7eb;border-bottom:2px solid #9ca3af;text-decoration:line-through;opacity:.6}';
        $html .= '.hl-stat{background:#ddd6fe;border-bottom:2px solid #7c3aed}';
        $html .= '.hl-span{cursor:pointer;border-radius:2px;padding:0 1px}';
        $html .= '.hl-text{line-height:1.8}';
        $html .= '.hl-legend{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1rem;padding:8px;background:#f9fafb;border-radius:6px}';
        $html .= '.hl-legend-item{display:flex;align-items:center;gap:6px;padding:4px 10px;border-radius:4px;font-size:12px}';
        $html .= '.hl-legend-swatch{display:inline-block;width:14px;height:14px;border-radius:3px}';
        $html .= '.hl-source-panel{border:1px solid #e0e0e0;border-radius:8px;margin-top:1rem;overflow:hidden}';
        $html .= '.hl-source-panel h3{margin:0;padding:.75rem 1rem;background:#f5f5f5;font-size:.875rem}';
        $html .= '.hl-source-panel .hl-text{padding:1rem}';
        $html .= '</style></head><body>';

        $html .= '<h1>Similarity Report</h1>';
        $html .= '<div class="meta">';
        $html .= '<strong>' . htmlspecialchars($submission['submission_title'] ?? 'Untitled') . '</strong><br>';
        $html .= 'Author: ' . htmlspecialchars($submission['author_name'] ?? 'Unknown') . '<br>';
        $html .= 'Submitted: ' . htmlspecialchars($submission['submitted_at'] ?? '') . '<br>';
        $html .= 'Word count: ' . ((int)($submission['word_count'] ?? 0)) . '<br>';
        $html .= 'Report generated: ' . date('Y-m-d H:i:s');
        $html .= '</div>';

        // Score cards
        $rawScore = $scores['raw_score'] ?? 0;
        $adjScore = $scores['adjusted_score'] ?? $rawScore;
        $html .= '<div style="margin-bottom:1.5rem">';
        $html .= '<div class="score-card raw"><div class="score-value">' . number_format($rawScore, 1) . '%</div><div class="score-label">Raw Similarity Score</div></div>';
        $html .= '<div class="score-card adjusted"><div class="score-value">' . number_format($adjScore, 1) . '%</div><div class="score-label">Adjusted Score</div></div>';
        $html .= '</div>';

        // Match count
        $html .= '<p>Found <strong>' . count($matches) . '</strong> matched passage(s) across <strong>' . count($sourceBreakdown) . '</strong> source(s).</p>';

        // Legend
        if ($highlightData !== null && !empty($highlightData['legend'])) {
            $html .= '<div class="hl-legend">';
            foreach ($highlightData['legend'] as $entry) {
                $html .= '<div class="hl-legend-item">';
                $html .= '<span class="hl-legend-swatch ' . htmlspecialchars($entry['css'], ENT_QUOTES, 'UTF-8') . '"></span>';
                $html .= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8');
                $html .= ' <span style="color:#999">(' . (int)$entry['count'] . ')</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Highlighted submission text
        if ($highlightData !== null && !empty($highlightData['highlighted_html'])) {
            $html .= '<h2>Highlighted Submission</h2>';
            $html .= '<div class="hl-text">' . $highlightData['highlighted_html'] . '</div>';
        }

        // Source panels
        if ($highlightData !== null && !empty($highlightData['source_panels'])) {
            $html .= '<h2>Source Comparisons</h2>';
            foreach ($highlightData['source_panels'] as $panel) {
                $html .= '<div class="hl-source-panel">';
                $html .= '<h3>' . htmlspecialchars($panel['title'], ENT_QUOTES, 'UTF-8') . '</h3>';
                $html .= '<div class="hl-text">' . $panel['html'] . '</div>';
                $html .= '</div>';
            }
        }

        // Source breakdown
        foreach ($sourceBreakdown as $sid => $sb) {
            $source = $sb['source'] ?? [];
            $html .= '<div class="match-source">';
            $html .= '<h3>' . htmlspecialchars($source['title'] ?? ('Source #' . $sid)) . '</h3>';
            $html .= '<p class="meta">' . htmlspecialchars($source['author'] ?? '') . ' | ' . ((int)($source['word_count'] ?? 0)) . ' words</p>';
            $html .= '<table><thead><tr><th>Type</th><th>Words</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
            foreach ($sb['matches'] as $match) {
                $excluded = !empty($match['is_excluded']);
                $cls = $excluded ? ' class="excluded"' : '';
                $html .= '<tr' . $cls . '>';
                $html .= '<td>' . htmlspecialchars($match['match_type'] ?? '') . '</td>';
                $html .= '<td>' . ((int)($match['matched_word_count'] ?? 0)) . '</td>';
                $html .= '<td>' . number_format((float)($match['match_confidence'] ?? 0) * 100, 1) . '%</td>';
                $html .= '<td>' . ($excluded ? 'Excluded' : 'Active') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // Detail matches
        if (!empty($matches)) {
            $html .= '<h2>Matched Passages</h2>';
            foreach ($matches as $mi => $md) {
                $match = $md['match'] ?? [];
                $evidence = $md['evidence'] ?? [];
                $html .= '<div class="match-source">';
                $html .= '<h3>Match #' . ($mi + 1) . ' — ' . htmlspecialchars($match['match_type'] ?? 'exact') . '</h3>';
                $html .= '<p>Confidence: ' . number_format((float)($match['match_confidence'] ?? 0) * 100, 1) . '% | Words: ' . ((int)($match['matched_word_count'] ?? 0)) . '</p>';
                foreach ($evidence as $ev) {
                    $html .= '<div class="highlight">';
                    $html .= '<p><strong>Submission:</strong> ' . htmlspecialchars(mb_substr($ev['submission_segment_text'] ?? '', 0, 500)) . '</p>';
                    $html .= '<p><strong>Source:</strong> ' . htmlspecialchars(mb_substr($ev['source_segment_text'] ?? '', 0, 500)) . '</p>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        }

        $html .= '<div class="footer">';
        $html .= 'Report ID: ' . ($submission['submission_id'] ?? 'N/A') . ' | ';
        $html .= 'Engine: v1.0.0 | ';
        $html .= 'This report shows similarity scores. A similarity score is not a determination of academic misconduct.';
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }
}
