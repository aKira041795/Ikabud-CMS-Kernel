<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

/**
 * Layer 5a: Pattern Classifier.
 *
 * Classifies failure types from error evidence text using a lightweight
 * scoring system against known error pattern profiles.
 *
 * Each pattern profile defines weighted keywords and regex signatures.
 * The classifier finds the BEST match (highest cumulative score).
 *
 * Error categories detected:
 *   - csrf: CSRF token mismatch, expired token, invalid token
 *   - permission: Access denied, forbidden, unauthorized
 *   - validation: Form validation, field errors, constraint violations
 *   - missing_record: Record not found, 404, null reference
 *   - network: Timeout, connection refused, DNS failure
 *   - db: Query error, constraint violation, deadlock, drift
 *   - session: Session expired, not authenticated, login required
 *   - capability: Capability not registered, no provider, disabled module
 *   - template: Template not found, render error, compile error
 *   - unknown: No pattern matched
 */
class PatternClassifier
{
    /** Pattern profiles with weighted keywords/signatures */
    private const PATTERNS = [
        'csrf' => [
            'weight' => 1.0,
            'keywords' => ['csrf', 'token mismatch', 'invalid token', '419', 'expired token'],
            'patterns' => ['/csrf/i', '/token.*mismatch/i', '/419.*expired/i', '/_token/i'],
        ],
        'permission' => [
            'weight' => 1.0,
            'keywords' => ['forbidden', 'access denied', 'unauthorized', '403', 'not allowed', 'no permission'],
            'patterns' => ['/403/i', '/access.*denied/i', '/forbidden/i', '/unauthorized/i', '/not.*allowed/i'],
        ],
        'validation' => [
            'weight' => 0.9,
            'keywords' => ['validation', 'required', 'invalid', 'must be', '422'],
            'patterns' => ['/422/i', '/validation.*failed/i', '/required.*field/i', '/must.*be/i'],
        ],
        'missing_record' => [
            'weight' => 0.9,
            'keywords' => ['not found', 'no record', 'missing', '404', 'null', 'does not exist'],
            'patterns' => ['/404/i', '/not.*found/i', '/no.*record/i', '/does.*not.*exist/i', '/null.*reference/i'],
        ],
        'network' => [
            'weight' => 0.8,
            'keywords' => ['timeout', 'connection refused', 'network error', 'dns', 'unreachable'],
            'patterns' => ['/timeout/i', '/connection.*refused/i', '/network.*error/i', '/unreachable/i'],
        ],
        'db' => [
            'weight' => 0.9,
            'keywords' => ['sql', 'database', 'constraint', 'deadlock', 'duplicate', 'syntax error', 'drift', 'table.*not'],
            'patterns' => ['/sql.*error/i', '/constraint.*violation/i', '/deadlock/i', '/duplicate.*entry/i', '/drift/i', '/table.*not.*exist/i'],
        ],
        'session' => [
            'weight' => 0.8,
            'keywords' => ['session', 'expired', 'login required', 'authenticate', 'not logged'],
            'patterns' => ['/session.*expired/i', '/login.*required/i', '/not.*authenticated/i', '/not.*logged/i'],
        ],
        'capability' => [
            'weight' => 0.9,
            'keywords' => ['capability', 'not registered', 'no provider', 'disabled module', 'capability.*not'],
            'patterns' => ['/capability.*not.*found/i', '/no.*provider/i', '/capability.*disabled/i', '/not.*registered/i'],
        ],
        'template' => [
            'weight' => 0.7,
            'keywords' => ['template', 'render', 'compile error', 'undefined variable', 'disyl'],
            'patterns' => ['/template.*not.*found/i', '/render.*error/i', '/compile.*error/i', '/undefined.*variable/i', '/disyl.*error/i'],
        ],
    ];

    /**
     * Classify error text into the most likely category.
     *
     * @param string $errorText The error message or evidence text
     * @return array{category: string, score: float, matched_terms: array, confidence: string}
     */
    public function classify(string $errorText): array
    {
        if (empty(trim($errorText))) {
            return [
                'category' => 'unknown',
                'score' => 0.0,
                'matched_terms' => [],
                'confidence' => 'none',
            ];
        }

        $bestCategory = 'unknown';
        $bestScore = 0.0;
        $bestTerms = [];

        foreach (self::PATTERNS as $category => $profile) {
            $score = 0.0;
            $matchedTerms = [];

            // Score keywords (case-insensitive contains)
            foreach ($profile['keywords'] as $keyword) {
                if (mb_stripos($errorText, $keyword) !== false) {
                    $kwScore = $profile['weight'] * (strlen($keyword) / 50);
                    $score += $kwScore;
                    $matchedTerms[] = $keyword;
                }
            }

            // Score regex patterns (multiplicative bonus for pattern matches)
            foreach ($profile['patterns'] as $pattern) {
                if (preg_match($pattern, $errorText)) {
                    $score *= 1.5;
                    if (!in_array($pattern, $matchedTerms, true)) {
                        $matchedTerms[] = $pattern;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCategory = $category;
                $bestTerms = $matchedTerms;
            }
        }

        // Normalize score to 0.0–1.0 range
        $normalized = min(1.0, $bestScore / 5.0);

        // Confidence level
        $confidence = $normalized >= 0.7 ? 'high'
            : ($normalized >= 0.4 ? 'medium'
            : ($normalized >= 0.1 ? 'low'
            : 'none'));

        return [
            'category' => $bestCategory,
            'score' => round($normalized, 2),
            'matched_terms' => $bestTerms,
            'confidence' => $confidence,
        ];
    }

    /**
     * Classify all evidence items and return aggregate diagnosis.
     *
     * @param array $evidence Runtime evidence
     * @return array{categories: array, dominant: string, diagnosis: string}
     */
    public function classifyAll(array $evidence): array
    {
        $classifications = [];

        foreach ($evidence as $key => $value) {
            if (is_string($value)) {
                $result = $this->classify($value);
                $classifications[] = [
                    'evidence_key' => $key,
                    'category' => $result['category'],
                    'score' => $result['score'],
                    'confidence' => $result['confidence'],
                ];
            } elseif (is_array($value)) {
                foreach ($value as $subKey => $subVal) {
                    if (is_string($subVal)) {
                        $result = $this->classify($subVal);
                        $classifications[] = [
                            'evidence_key' => $key . '.' . $subKey,
                            'category' => $result['category'],
                            'score' => $result['score'],
                            'confidence' => $result['confidence'],
                        ];
                    }
                }
            }
        }

        // Aggregate: find dominant category
        $categoryScores = [];
        foreach ($classifications as $c) {
            $cat = $c['category'];
            $categoryScores[$cat] = ($categoryScores[$cat] ?? 0) + $c['score'];
        }
        arsort($categoryScores);
        $dominant = array_key_first($categoryScores) ?? 'unknown';

        // Build diagnosis text
        $diagnosis = $this->buildDiagnosis($dominant, $categoryScores);

        return [
            'categories' => $classifications,
            'dominant' => $dominant,
            'diagnosis' => $diagnosis,
        ];
    }

    /**
     * Build a human-readable diagnosis from dominant category.
     */
    private function buildDiagnosis(string $dominant, array $scores): string
    {
        $template = match ($dominant) {
            'csrf' => 'CSRF token mismatch — likely a stale page cache or expired session.',
            'permission' => 'Permission denied — user lacks required role or capability.',
            'validation' => 'Form/data validation failed — check field constraints and input format.',
            'missing_record' => 'Record not found — the referenced entity may not exist or was deleted.',
            'network' => 'Network error — connection issue between browser and server.',
            'db' => 'Database error — query failure, constraint violation, or schema drift.',
            'session' => 'Session expired — user needs to re-authenticate.',
            'capability' => 'Capability error — module capability not registered or disabled.',
            'template' => 'Template rendering error — check DiSyL template syntax or variable presence.',
            default => 'Unrecognized error pattern — manual inspection required.',
        };

        if (count($scores) > 1) {
            $second = array_keys($scores)[1] ?? null;
            if ($second && $second !== 'unknown') {
                $template .= " Secondary signal: {$second} pattern also detected.";
            }
        }

        return $template;
    }
}
