<?php
declare(strict_types=1);

/**
 * Academic Similarity — Highlight Service.
 *
 * Transforms match rows, evidence rows, and submission/source text into
 * highlight span objects and pre-rendered safe HTML for report views.
 *
 * Design principles:
 * - All text is escaped before rendering; only controlled <mark>/<span> wrappers
 *   are injected by the server-side formatter.
 * - Overlapping spans are resolved by precedence (excluded > quotation > exact
 *   > near-exact > semantic > statistical).
 * - Word ranges from match rows are the canonical span anchors.
 * - Evidence character offsets provide fallback precision when word ranges
 *   are unavailable.
 */
class AcademicSimilarityHighlightService
{
    private string $tenantId;

    /** @var array<string, int> */
    private const PRECEDENCE = [
        'excluded'     => 100,
        'quotation'    => 90,
        'exact'        => 80,
        'near-exact'   => 70,
        'semantic'     => 60,
        'statistical'  => 50,
    ];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'exact'       => 'Exact Copy',
        'near-exact'  => 'Similar Wording',
        'semantic'    => 'Semantic Paraphrase',
        'quotation'   => 'Quoted/Cited',
        'excluded'    => 'Excluded',
        'statistical' => 'Statistical Risk',
    ];

    /** @var array<string, string> */
    private const TYPE_CSS = [
        'exact'       => 'hl-exact',
        'near-exact'  => 'hl-near',
        'semantic'    => 'hl-semantic',
        'quotation'   => 'hl-quote',
        'excluded'    => 'hl-excluded',
        'statistical' => 'hl-stat',
    ];

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Build highlight spans from match rows for a submission.
     *
     * @param int          $submissionId
     * @param array        $matches       Rows from ac_similarity_matches
     * @param array        $evidenceMap   Evidence rows keyed by match_id
     * @param array|null   $submission    Submission row (for word_count, title etc.)
     * @param array[]|null $sourceCache   Source rows keyed by source_id for titles
     * @return array{spans: AcademicSimilarityHighlightSpan[], stats: array, legend: array}
     */
    public function buildSpans(
        int $submissionId,
        array $matches,
        array $evidenceMap = [],
        ?array $submission = null,
        ?array $sourceCache = null
    ): array {
        $spans = [];
        $typeCounts = [];

        foreach ($matches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            $matchType = (string)($match['match_type'] ?? 'exact');
            $isExcluded = !empty($match['is_excluded']);
            $sourceId = (int)($match['source_id'] ?? 0);

            // Determine the effective type for rendering
            $renderType = $isExcluded ? 'excluded' : $matchType;
            if (!isset(self::PRECEDENCE[$renderType])) {
                $renderType = 'exact';
            }

            // Word ranges from match row
            $swStart = (int)($match['submission_word_range_start'] ?? 0);
            $swEnd   = (int)($match['submission_word_range_end'] ?? 0);
            $srcwStart = (int)($match['source_word_range_start'] ?? 0);
            $srcwEnd   = (int)($match['source_word_range_end'] ?? 0);
            $wordCount = $swEnd - $swStart + 1;

            // Determine highlight level based on word count
            $level = 'phrase';
            if ($wordCount <= 2) {
                $level = 'word';
            } elseif ($wordCount >= 15) {
                $level = 'sentence';
            }

            $confidence = (float)($match['match_confidence'] ?? 1.0);
            $sourceTitle = '';
            if ($sourceCache && isset($sourceCache[$sourceId])) {
                $sourceTitle = (string)($sourceCache[$sourceId]['title'] ?? '');
            }

            // Evidence rows for this match
            $evidenceRows = $evidenceMap[$matchId] ?? [];

            // Build submission-side span
            $tooltipBase = self::TYPE_LABELS[$renderType] ?? 'Matched';
            $pct = number_format($confidence * 100, 0);
            $subTooltip = "{$tooltipBase} — {$pct}% confidence, {$wordCount} words";
            $srcExcerpt = '';
            if ($evidenceRows !== []) {
                $srcExcerpt = mb_substr($evidenceRows[0]['source_segment_text'] ?? '', 0, 200);
            }
            if ($sourceTitle !== '') {
                $subTooltip .= " — Source: {$sourceTitle}";
            }

            $spans[] = new AcademicSimilarityHighlightSpan([
                'submission_id' => $submissionId,
                'source_id'     => $sourceId,
                'match_id'      => $matchId,
                'evidence_id'   => $evidenceRows[0]['id'] ?? null,
                'side'          => 'submission',
                'level'         => $level,
                'type'          => $renderType,
                'confidence'    => $confidence,
                'word_start'    => $swStart,
                'word_end'      => $swEnd,
                'char_start'    => (int)($evidenceRows[0]['submission_start_offset'] ?? ($swStart * 5)),
                'char_end'      => (int)($evidenceRows[0]['submission_end_offset'] ?? ($swEnd * 5 + 4)),
                'sentence_index' => (int)($match['segment_match_count'] ?? 0),
                'precedence'    => self::PRECEDENCE[$renderType],
                'css_token'     => self::TYPE_CSS[$renderType],
                'label'         => self::TYPE_LABELS[$renderType],
                'tooltip'       => $subTooltip,
                'source_title'  => $sourceTitle,
                'source_excerpt' => htmlspecialchars($srcExcerpt, ENT_QUOTES, 'UTF-8'),
                'metadata'      => [
                    'match_confidence' => $confidence,
                    'word_count'       => $wordCount,
                ],
            ]);

            // Build source-side span if word ranges exist
            if ($srcwStart > 0 || $srcwEnd > 0) {
                $srcWordCount = $srcwEnd - $srcwStart + 1;
                $srcLevel = 'phrase';
                if ($srcWordCount <= 2) $srcLevel = 'word';
                elseif ($srcWordCount >= 15) $srcLevel = 'sentence';

                $subExcerpt = '';
                if ($evidenceRows !== []) {
                    $subExcerpt = mb_substr($evidenceRows[0]['submission_segment_text'] ?? '', 0, 200);
                }

                $spans[] = new AcademicSimilarityHighlightSpan([
                    'submission_id' => $submissionId,
                    'source_id'     => $sourceId,
                    'match_id'      => $matchId,
                    'evidence_id'   => $evidenceRows[0]['id'] ?? null,
                    'side'          => 'source',
                    'level'         => $srcLevel,
                    'type'          => $renderType,
                    'confidence'    => $confidence,
                    'word_start'    => $srcwStart,
                    'word_end'      => $srcwEnd,
                    'char_start'    => (int)($evidenceRows[0]['source_start_offset'] ?? ($srcwStart * 5)),
                    'char_end'      => (int)($evidenceRows[0]['source_end_offset'] ?? ($srcwEnd * 5 + 4)),
                    'sentence_index' => 0,
                    'precedence'    => self::PRECEDENCE[$renderType],
                    'css_token'     => self::TYPE_CSS[$renderType],
                    'label'         => self::TYPE_LABELS[$renderType],
                    'tooltip'       => "Source {$tooltipBase} — {$pct}% confidence, {$srcWordCount} words" . ($sourceTitle !== '' ? " — {$sourceTitle}" : ''),
                    'source_title'  => $sourceTitle,
                    'source_excerpt' => htmlspecialchars($subExcerpt, ENT_QUOTES, 'UTF-8'),
                    'metadata'      => [
                        'match_confidence' => $confidence,
                        'word_count'       => $srcWordCount,
                    ],
                ]);
            }

            // Track type counts
            $typeCounts[$renderType] = ($typeCounts[$renderType] ?? 0) + 1;
        }

        // Resolve overlaps by precedence
        $resolved = $this->resolveOverlaps($spans);

        // Build stats
        $stats = [
            'total_spans'      => count($resolved),
            'type_breakdown'   => $typeCounts,
            'submission_spans' => count(array_filter($resolved, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'submission')),
            'source_spans'     => count(array_filter($resolved, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'source')),
        ];

        // Build legend
        $legend = [];
        foreach (['exact', 'near-exact', 'semantic', 'quotation', 'excluded', 'statistical'] as $type) {
            if (isset($typeCounts[$type]) || $type === 'exact') {
                $legend[] = [
                    'type'  => $type,
                    'label' => self::TYPE_LABELS[$type],
                    'css'   => self::TYPE_CSS[$type],
                    'count' => $typeCounts[$type] ?? 0,
                ];
            }
        }

        return [
            'spans'  => $resolved,
            'stats'  => $stats,
            'legend' => $legend,
        ];
    }

    /**
     * Render submission text with highlight spans as safe HTML.
     *
     * Tokenizes the text by word boundaries, applies highlight spans to
     * the matching word ranges, and escapes all content.
     *
     * @param string                      $text  The full extracted text
     * @param AcademicSimilarityHighlightSpan[] $spans Only submission-side spans
     * @param int                         $maxExcerptLen Max chars per excerpt
     * @return string Safe HTML
     */
    public function renderHighlightedText(string $text, array $spans, int $maxExcerptLen = 5000): string
    {
        // Filter to submission-side spans only
        $subSpans = array_values(array_filter($spans, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'submission'));

        if (empty($subSpans)) {
            return '<div class="hl-text hl-text--no-matches"><p>' . htmlspecialchars(mb_substr($text, 0, $maxExcerptLen), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        // Sort spans by word_start
        usort($subSpans, fn(AcademicSimilarityHighlightSpan $a, AcademicSimilarityHighlightSpan $b): int => $a->wordStart <=> $b->wordStart ?: $b->precedence <=> $a->precedence);

        // Tokenize text into words (preserve whitespace as tokens)
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false || $tokens === []) {
            return '<div class="hl-text hl-text--empty"><p>' . htmlspecialchars(mb_substr($text, 0, $maxExcerptLen), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        // Build word-to-token-index map: which token index does word N start at?
        $wordTokenIndices = [];
        $wordIdx = 0;
        foreach ($tokens as $ti => $tok) {
            if (trim($tok) !== '') {
                $wordTokenIndices[$wordIdx] = $ti;
                $wordIdx++;
            }
        }
        $totalWords = $wordIdx;

        // Limit excerpt
        $maxTokens = $maxExcerptLen > 0 ? min(count($tokens), $maxExcerptLen) : count($tokens);
        $tokens = array_slice($tokens, 0, $maxTokens);
        $lastTokenIndex = count($tokens) - 1;

        // Build an ordered span map: word position -> highest-precedence highlight
        $wordHighlight = []; // word pos -> AcademicSimilarityHighlightSpan
        foreach ($subSpans as $span) {
            for ($w = $span->wordStart; $w <= min($span->wordEnd, $totalWords - 1); $w++) {
                $existing = $wordHighlight[$w] ?? null;
                if ($existing === null || $span->precedence > $existing->precedence) {
                    $wordHighlight[$w] = $span;
                }
            }
        }

        if (empty($wordHighlight)) {
            return '<div class="hl-text hl-text--no-highlights"><p>' . htmlspecialchars(mb_substr($text, 0, $maxExcerptLen), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        // Render: iterate over ALL tokens, outputting whitespace between words,
        // and wrapping word tokens with highlight marks when they match.
        $html = '<div class="hl-text">';
        $inSpan = false;
        $currentSpan = null;

        // Pre-compute which word position each token belongs to
        $tokenWordPos = []; // token index -> word position (or null for whitespace)
        $wPos = 0;
        foreach ($tokens as $ti => $tok) {
            if (trim($tok) !== '') {
                $tokenWordPos[$ti] = $wPos;
                $wPos++;
            } else {
                $tokenWordPos[$ti] = null;
            }
        }

        for ($ti = 0; $ti <= $lastTokenIndex; $ti++) {
            $tok = $tokens[$ti];
            $wPos = $tokenWordPos[$ti] ?? null;

            if ($wPos !== null) {
                // This is a word token
                $span = $wordHighlight[$wPos] ?? null;

                if ($span !== null) {
                    if (!$inSpan || spl_object_id($span) !== spl_object_id($currentSpan)) {
                        if ($inSpan) {
                            $html .= '</mark>';
                        }
                        $dataAttrs = sprintf(
                            'data-type="%s" data-confidence="%.2f" data-match-id="%d" data-source-id="%d" title="%s"',
                            htmlspecialchars($span->type, ENT_QUOTES, 'UTF-8'),
                            $span->confidence,
                            $span->matchId ?? 0,
                            $span->sourceId ?? 0,
                            htmlspecialchars($span->tooltip, ENT_QUOTES, 'UTF-8')
                        );
                        $html .= sprintf(
                            '<mark class="hl-span %s" %s role="mark" aria-label="%s">',
                            htmlspecialchars($span->cssToken, ENT_QUOTES, 'UTF-8'),
                            $dataAttrs,
                            htmlspecialchars($span->label, ENT_QUOTES, 'UTF-8')
                        );
                        $inSpan = true;
                        $currentSpan = $span;
                    }
                } else {
                    if ($inSpan) {
                        $html .= '</mark>';
                        $inSpan = false;
                        $currentSpan = null;
                    }
                }

                $html .= htmlspecialchars($tok, ENT_QUOTES, 'UTF-8');
            } else {
                // Whitespace token — output inside current span if it continues
                $html .= htmlspecialchars($tok, ENT_QUOTES, 'UTF-8');
            }
        }

        if ($inSpan) {
            $html .= '</mark>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render source-side highlighted panels.
     *
     * @param AcademicSimilarityHighlightSpan[] $spans
     * @param array             $sourceTexts  Keyed by source_id
     * @return array<int, array{source_id: int, title: string, html: string}>
     */
    public function renderSourcePanels(array $spans, array $sourceTexts = []): array
    {
        $srcSpans = array_values(array_filter($spans, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'source'));

        // Group by source_id
        $grouped = [];
        foreach ($srcSpans as $span) {
            $sid = $span->sourceId ?? 0;
            if ($sid <= 0) continue;
            $grouped[$sid][] = $span;
        }

        $panels = [];
        foreach ($grouped as $sid => $groupSpans) {
            $title = $groupSpans[0]->sourceTitle ?: "Source #{$sid}";
            $text = $sourceTexts[$sid] ?? '';

            if ($text !== '') {
                $html = $this->renderHighlightedText($text, $groupSpans, 2000);
            } else {
                $html = $this->buildSourceExcerptPanel($groupSpans);
            }

            $panels[] = [
                'source_id' => $sid,
                'title'     => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'html'      => $html,
            ];
        }

        return $panels;
    }

    /**
     * Assemble matched_passages for the template.
     *
     * @param AcademicSimilarityHighlightSpan[] $spans
     * @param array             $matches Raw match rows
     * @param array             $evidenceMap
     * @return array
     */
    public function assembleMatchedPassages(array $spans, array $matches, array $evidenceMap = []): array
    {
        $passages = [];

        foreach ($matches as $match) {
            $matchId = (int)($match['id'] ?? 0);
            $evidenceRows = $evidenceMap[$matchId] ?? [];

            $passage = [
                'id'                    => $matchId,
                'match_type'            => $match['match_type'] ?? 'exact',
                'match_confidence'      => (float)($match['match_confidence'] ?? 0),
                'matched_word_count'    => (int)($match['matched_word_count'] ?? 0),
                'is_excluded'           => !empty($match['is_excluded']),
                'source_id'             => (int)($match['source_id'] ?? 0),
                'source_title'          => '',
                'source_author'         => '',
                'submission_text'       => '',
                'source_text'           => '',
                'submission_line_start' => (int)($match['submission_word_range_start'] ?? 0),
                'submission_line_end'   => (int)($match['submission_word_range_end'] ?? 0),
                'source_line_start'     => (int)($match['source_word_range_start'] ?? 0),
                'source_line_end'       => (int)($match['source_word_range_end'] ?? 0),
                'highlight_labels'      => [],
            ];

            if ($evidenceRows !== []) {
                $passage['submission_text'] = htmlspecialchars(mb_substr($evidenceRows[0]['submission_segment_text'] ?? '', 0, 500), ENT_QUOTES, 'UTF-8');
                $passage['source_text'] = htmlspecialchars(mb_substr($evidenceRows[0]['source_segment_text'] ?? '', 0, 500), ENT_QUOTES, 'UTF-8');
            }

            // Find matching spans for highlight labels
            $spanLabels = [];
            foreach ($spans as $span) {
                if ($span->matchId === $matchId && $span->side === 'submission') {
                    $spanLabels[] = $span->label;
                }
            }
            $passage['highlight_labels'] = array_unique($spanLabels);

            $passages[] = $passage;
        }

        return $passages;
    }

    /**
     * Build the highlight legend array (type, label, css, count).
     */
    public function buildLegend(array $stats, array $spans): array
    {
        $legend = [];
        $types = ['exact', 'near-exact', 'semantic', 'quotation', 'excluded', 'statistical'];

        foreach ($types as $type) {
            $count = 0;
            foreach ($spans as $span) {
                if ($span->type === $type) {
                    $count++;
                }
            }
            $legend[] = [
                'type'  => $type,
                'label' => self::TYPE_LABELS[$type],
                'css'   => self::TYPE_CSS[$type],
                'count' => $count,
            ];
        }

        return $legend;
    }

    /**
     * Resolve overlapping highlight spans by precedence.
     * When two spans overlap on the same side, the higher-precedence span wins.
     * Equal-precedence spans are merged.
     *
     * @param AcademicSimilarityHighlightSpan[] $spans
     * @return AcademicSimilarityHighlightSpan[]
     */
    public function resolveOverlaps(array $spans): array
    {
        if (empty($spans)) {
            return [];
        }

        // Separate by side
        $submissionSpans = array_values(array_filter($spans, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'submission'));
        $sourceSpans = array_values(array_filter($spans, fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'source'));

        $resolved = array_merge(
            $this->resolveSideOverlaps($submissionSpans),
            $this->resolveSideOverlaps($sourceSpans)
        );

        return $resolved;
    }

    /**
     * Resolve overlaps for a single side.
     *
     * When a higher-precedence span is contained within a lower-precedence span,
     * the higher-precedence span replaces the contained portion. Lower-precedence
     * spans are split around higher-precedence spans.
     */
    private function resolveSideOverlaps(array $spans): array
    {
        if (empty($spans)) {
            return [];
        }

        // Sort by word_start ASC, then precedence DESC, then word_end DESC
        usort($spans, function (AcademicSimilarityHighlightSpan $a, AcademicSimilarityHighlightSpan $b): int {
            if ($a->wordStart !== $b->wordStart) {
                return $a->wordStart <=> $b->wordStart;
            }
            if ($b->precedence !== $a->precedence) {
                return $b->precedence <=> $a->precedence;
            }
            return ($b->wordEnd - $b->wordStart) <=> ($a->wordEnd - $a->wordStart);
        });

        // Build a flat word-by-word precedence map
        $wordPrecedence = []; // position => highest precedence
        $wordSpan = [];       // position => span

        foreach ($spans as $span) {
            for ($w = $span->wordStart; $w <= $span->wordEnd; $w++) {
                $existing = $wordPrecedence[$w] ?? 0;
                if ($span->precedence > $existing) {
                    $wordPrecedence[$w] = $span->precedence;
                    $wordSpan[$w] = $span;
                }
            }
        }

        if (empty($wordSpan)) {
            return [];
        }

        // Group consecutive word positions with the same span into resolved spans
        $positions = array_keys($wordSpan);
        sort($positions);

        $resolved = [];
        $runStart = $positions[0];
        $runSpan = $wordSpan[$positions[0]];
        $prevPos = $positions[0];

        for ($i = 1, $n = count($positions); $i < $n; $i++) {
            $pos = $positions[$i];
            $spanAtPos = $wordSpan[$pos];
            if ($spanAtPos === $runSpan && $pos === $prevPos + 1) {
                // Continue the run
                $prevPos = $pos;
            } else {
                // End the current run, start a new one
                $resolved[] = new AcademicSimilarityHighlightSpan(array_merge($runSpan->toArray(), [
                    'word_start' => $runStart,
                    'word_end' => $prevPos,
                ]));
                $runStart = $pos;
                $runSpan = $spanAtPos;
                $prevPos = $pos;
            }
        }

        // Last run
        if ($runSpan !== null) {
            $resolved[] = new AcademicSimilarityHighlightSpan(array_merge($runSpan->toArray(), [
                'word_start' => $runStart,
                'word_end' => $prevPos,
            ]));
        }

        return $resolved;
    }

    /**
     * Build a simple source excerpt panel from evidence texts.
     */
    private function buildSourceExcerptPanel(array $spans): string
    {
        $seen = [];
        $html = '<div class="hl-source-excerpts">';
        foreach ($spans as $span) {
            $key = $span->matchId ?? $span->wordStart;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            if ($span->sourceExcerpt !== '') {
                $css = htmlspecialchars($span->cssToken, ENT_QUOTES, 'UTF-8');
                $html .= '<div class="hl-source-excerpt">';
                $html .= '<span class="hl-label ' . $css . '">' . htmlspecialchars($span->label, ENT_QUOTES, 'UTF-8') . '</span> ';
                $html .= '<span class="hl-excerpt-text">' . htmlspecialchars(mb_substr($span->sourceExcerpt, 0, 300), ENT_QUOTES, 'UTF-8') . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';
        return $html;
    }
}
