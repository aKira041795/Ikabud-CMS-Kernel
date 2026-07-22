<?php
declare(strict_types=1);

/**
 * Represents a single highlight span in a submission or source document.
 *
 * Precedence (higher wins when spans overlap):
 *   100 – excluded
 *    90 – quotation/citation
 *    80 – exact match
 *    70 – near-exact match
 *    60 – semantic paraphrase
 *    50 – statistical / Bayesian risk
 */
class AcademicSimilarityHighlightSpan
{
    public readonly int $submissionId;
    public readonly ?int $sourceId;
    public readonly ?int $matchId;
    public readonly ?int $evidenceId;
    public readonly string $side;          // 'submission' | 'source'
    public readonly string $level;         // 'word' | 'phrase' | 'sentence'
    public readonly string $type;          // 'exact' | 'near-exact' | 'semantic' | 'quotation' | 'excluded' | 'statistical'
    public readonly float $confidence;
    public readonly int $wordStart;
    public readonly int $wordEnd;
    public readonly int $charStart;
    public readonly int $charEnd;
    public readonly int $sentenceIndex;
    public readonly int $precedence;
    public readonly string $cssToken;
    public readonly string $label;
    public readonly string $tooltip;
    public readonly string $sourceTitle;
    public readonly string $sourceExcerpt;
    public readonly array $metadata;

    private const PRECEDENCE_MAP = [
        'excluded'     => 100,
        'quotation'    => 90,
        'exact'        => 80,
        'near-exact'   => 70,
        'semantic'     => 60,
        'statistical'  => 50,
    ];

    private const CSS_MAP = [
        'exact'       => 'hl-exact',
        'near-exact'  => 'hl-near',
        'semantic'    => 'hl-semantic',
        'quotation'   => 'hl-quote',
        'excluded'    => 'hl-excluded',
        'statistical' => 'hl-stat',
    ];

    private const LABEL_MAP = [
        'exact'       => 'Exact Copy',
        'near-exact'  => 'Similar Wording',
        'semantic'    => 'Semantic Paraphrase',
        'quotation'   => 'Quoted/Cited',
        'excluded'    => 'Excluded',
        'statistical' => 'Statistical Risk',
    ];

    public function __construct(array $data)
    {
        $this->submissionId = (int)($data['submission_id'] ?? 0);
        $this->sourceId = isset($data['source_id']) && $data['source_id'] > 0 ? (int)$data['source_id'] : null;
        $this->matchId = isset($data['match_id']) && $data['match_id'] > 0 ? (int)$data['match_id'] : null;
        $this->evidenceId = isset($data['evidence_id']) && $data['evidence_id'] > 0 ? (int)$data['evidence_id'] : null;
        $this->side = (string)($data['side'] ?? 'submission');
        $this->level = (string)($data['level'] ?? 'phrase');
        $type = (string)($data['type'] ?? 'exact');
        $this->type = $type;
        $this->confidence = (float)($data['confidence'] ?? 1.0);
        $this->wordStart = (int)($data['word_start'] ?? 0);
        $this->wordEnd = (int)($data['word_end'] ?? 0);
        $this->charStart = (int)($data['char_start'] ?? 0);
        $this->charEnd = (int)($data['char_end'] ?? 0);
        $this->sentenceIndex = (int)($data['sentence_index'] ?? 0);
        $this->precedence = (int)($data['precedence'] ?? (self::PRECEDENCE_MAP[$type] ?? 50));
        $this->cssToken = (string)($data['css_token'] ?? (self::CSS_MAP[$type] ?? 'hl-exact'));
        $this->label = (string)($data['label'] ?? (self::LABEL_MAP[$type] ?? 'Matched'));
        $this->tooltip = (string)($data['tooltip'] ?? $this->buildDefaultTooltip());
        $this->sourceTitle = (string)($data['source_title'] ?? '');
        $this->sourceExcerpt = (string)($data['source_excerpt'] ?? '');
        $this->metadata = (array)($data['metadata'] ?? []);
    }

    private function buildDefaultTooltip(): string
    {
        $label = self::LABEL_MAP[$this->type] ?? 'Matched';
        $pct = number_format($this->confidence * 100, 0);
        $words = $this->wordEnd - $this->wordStart + 1;
        return "{$label} — {$pct}% confidence, {$words} words";
    }

    public function toArray(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'source_id' => $this->sourceId,
            'match_id' => $this->matchId,
            'evidence_id' => $this->evidenceId,
            'side' => $this->side,
            'level' => $this->level,
            'type' => $this->type,
            'confidence' => $this->confidence,
            'word_start' => $this->wordStart,
            'word_end' => $this->wordEnd,
            'char_start' => $this->charStart,
            'char_end' => $this->charEnd,
            'sentence_index' => $this->sentenceIndex,
            'precedence' => $this->precedence,
            'css_token' => $this->cssToken,
            'label' => $this->label,
            'tooltip' => $this->tooltip,
            'source_title' => $this->sourceTitle,
            'source_excerpt' => $this->sourceExcerpt,
            'metadata' => $this->metadata,
        ];
    }
}
