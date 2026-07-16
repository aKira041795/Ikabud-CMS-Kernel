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
        'navigation' => [
            'weight' => 1.0,
            'keywords' => ['broken navigation', 'navigation route', 'sidebar link', 'route returned 404', 'navigation dependency'],
            'patterns' => ['/broken.*navigation/i', '/sidebar.*404/i', '/navigation.*route/i'],
        ],
        'tenant_isolation' => [
            'weight' => 1.0,
            'keywords' => ['cross-tenant', 'tenant leak', 'wrong tenant', 'tenant isolation', 'foreign tenant'],
            'patterns' => ['/cross[- ]tenant/i', '/tenant.*leak/i', '/wrong.*tenant/i'],
        ],
        'accessibility' => [
            'weight' => 0.9,
            'keywords' => ['accessible name', 'keyboard trap', 'heading order', 'aria', 'focus invisible'],
            'patterns' => ['/accessible.*name/i', '/keyboard.*trap/i', '/heading.*order/i', '/aria[- ]/i'],
        ],
        'workflow' => [
            'weight' => 0.9,
            'keywords' => ['invalid transition', 'workflow state', 'state remained', 'transition rejected'],
            'patterns' => ['/invalid.*transition/i', '/workflow.*state/i', '/state.*remained/i'],
        ],
        'effect' => [
            'weight' => 0.9,
            'keywords' => ['effect missing', 'status unchanged', 'row not created', 'persisted effect'],
            'patterns' => ['/effect.*missing/i', '/status.*unchanged/i', '/row.*not.*created/i'],
        ],
        'event' => [
            'weight' => 0.9,
            'keywords' => ['event not fired', 'listener not executed', 'event missing', 'domain event'],
            'patterns' => ['/event.*not.*fired/i', '/listener.*not.*executed/i', '/event.*missing/i'],
        ],
        'audit' => [
            'weight' => 0.9,
            'keywords' => ['audit missing', 'audit not written', 'audit trail absent', 'no audit record'],
            'patterns' => ['/audit.*missing/i', '/audit.*not.*written/i', '/no.*audit.*record/i'],
        ],
        'flaky' => [
            'weight' => 0.8,
            'keywords' => ['intermittent', 'flaky', 'passes on retry', 'nondeterministic'],
            'patterns' => ['/pass.*on.*retry/i', '/intermittent/i', '/nondeterministic/i'],
        ],
        'environment' => [
            'weight' => 0.8,
            'keywords' => ['probe error', 'environment unavailable', 'database unreachable', 'service unavailable'],
            'patterns' => ['/probe.*error/i', '/environment.*unavailable/i', '/service.*unavailable/i'],
        ],
        'performance' => [
            'weight' => 0.8,
            'keywords' => ['performance budget', 'slow response', 'latency exceeded', 'timeout budget'],
            'patterns' => ['/performance.*budget/i', '/latency.*exceeded/i', '/slow.*response/i'],
        ],
        'dependency' => [
            'weight' => 0.9,
            'keywords' => ['undeclared dependency', 'dependency missing', 'companion module', 'provider unavailable'],
            'patterns' => ['/undeclared.*dependency/i', '/dependency.*missing/i', '/provider.*unavailable/i'],
        ],
        'integration' => [
            'weight' => 0.8,
            'keywords' => ['contract mismatch', 'integration response', 'schema mismatch', 'provider contract'],
            'patterns' => ['/contract.*mismatch/i', '/schema.*mismatch/i', '/provider.*contract/i'],
        ],
        'coverage' => [
            'weight' => 0.7,
            'keywords' => ['not observed', 'coverage gap', 'unobserved effect', 'skipped invariant'],
            'patterns' => ['/not.*observed/i', '/coverage.*gap/i', '/unobserved/i'],
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
        $ranked = $this->classifyTop($errorText, 1);
        return $ranked[0] ?? [
            'category' => 'unknown',
            'score' => 0.0,
            'matched_terms' => [],
            'confidence' => 'none',
        ];
    }

    /**
     * Return ranked evidence-supported classifications for benchmark and diagnosis use.
     *
     * @return array<int, array{category: string, score: float, matched_terms: array, confidence: string}>
     */
    public function classifyTop(string $errorText, int $limit = 3): array
    {
        if (trim($errorText) === '' || $limit < 1) return [];

        $ranked = [];
        foreach (self::PATTERNS as $category => $profile) {
            $score = 0.0;
            $matchedTerms = [];
            foreach ($profile['keywords'] as $keyword) {
                if (mb_stripos($errorText, $keyword) !== false) {
                    $score += $profile['weight'] * (strlen($keyword) / 50);
                    $matchedTerms[] = $keyword;
                }
            }
            foreach ($profile['patterns'] as $pattern) {
                if (preg_match($pattern, $errorText)) {
                    $score = max($profile['weight'] * 0.1, $score * 1.5);
                    $matchedTerms[] = $pattern;
                }
            }
            if ($score <= 0.0) continue;
            $normalized = min(1.0, $score / 5.0);
            $ranked[] = [
                'category' => $category,
                'score' => round($normalized, 2),
                'matched_terms' => array_values(array_unique($matchedTerms)),
                'confidence' => $normalized >= 0.7 ? 'high' : ($normalized >= 0.4 ? 'medium' : ($normalized >= 0.1 ? 'low' : 'none')),
            ];
        }
        usort($ranked, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['category'], $b['category']));
        return array_slice($ranked, 0, $limit);
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
            'navigation' => 'Navigation contract failed — inspect route ownership and declared dependencies.',
            'tenant_isolation' => 'Tenant isolation failed — evidence indicates cross-tenant access or leakage.',
            'accessibility' => 'Accessibility contract failed — inspect names, focus, keyboard, and structure.',
            'workflow' => 'Workflow contract failed — the observed state transition differs from the declaration.',
            'effect' => 'Expected effect is missing — inspect persistence, service, and postcondition evidence.',
            'event' => 'Expected domain event or listener effect was not observed.',
            'audit' => 'Audit invariant failed — the required audit record was not observed.',
            'flaky' => 'Intermittent behavior detected — quarantine requires governed recurrence evidence.',
            'environment' => 'Environment or probe failure prevented an authoritative product verdict.',
            'performance' => 'Performance budget failed — observed latency exceeds the declared threshold.',
            'dependency' => 'Module dependency contract failed — inspect declarations and provider availability.',
            'integration' => 'Integration contract mismatch — provider and consumer evidence disagree.',
            'coverage' => 'Required evidence was not observed; this is a coverage gap, not a product failure.',
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
