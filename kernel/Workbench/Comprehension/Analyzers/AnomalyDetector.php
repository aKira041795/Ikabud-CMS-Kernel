<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

/**
 * Layer 5b: Anomaly Detector.
 *
 * Flags evidence that doesn't match any declared chain link — these
 * are "unexpected observations" that might indicate:
 *   - Side effects the provider didn't declare
 *   - Error recovery code paths (retries, fallbacks)
 *   - Security events (rate limiting, IP bans)
 *   - Background processes firing unexpectedly
 *   - Data corruption or phantom writes
 */
class AnomalyDetector
{
    /**
     * Detect anomalies in runtime evidence against declared chains.
     *
     * @param array $evidence All runtime evidence
     * @param array $declaredSteps Array of step names from all actions
     * @param array $declaredCategories Array of category names from all actions
     * @return array<int, array{evidence_key: string, evidence_value: string, reason: string, severity: string}>
     */
    public function detect(array $evidence, array $declaredSteps, array $declaredCategories): array
    {
        $anomalies = [];

        foreach ($evidence as $key => $value) {
            // Skip metadata keys
            if (str_starts_with((string)$key, '_')) {
                continue;
            }

            // 1. Unknown step evidence
            if (!in_array($key, $declaredSteps, true)) {
                $isCategory = in_array($key, $declaredCategories, true);
                if (!$isCategory) {
                    $anomalies[] = [
                        'evidence_key' => (string)$key,
                        'evidence_value' => $this->summarize($value),
                        'reason' => "Evidence key '{$key}' does not match any declared chain step or category",
                        'severity' => 'info',
                    ];
                }
            }

            // 2. Error evidence where value contains error indicators but success was expected
            if (is_string($value) && $this->isErrorIndicator($value)) {
                $anomalies[] = [
                    'evidence_key' => (string)$key,
                    'evidence_value' => mb_substr($value, 0, 200),
                    'reason' => "Evidence contains error indicators at key '{$key}'",
                    'severity' => 'warning',
                ];
            }

            // 3. Unexpectedly large evidence values (suggesting debug/verbose mode)
            if (is_string($value) && strlen($value) > 5000) {
                $anomalies[] = [
                    'evidence_key' => (string)$key,
                    'evidence_value' => '[' . strlen($value) . ' chars]',
                    'reason' => "Unusually large evidence value ({strlen($value)} bytes) at key '{$key}' — possible debug output",
                    'severity' => 'info',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Check if evidence value has a response that should have been declared.
     * Helps find missing chain links.
     *
     * @return array<int, array{step_suggestion: string, evidence_key: string, reason: string}>
     */
    public function suggestMissingLinks(array $evidence): array
    {
        $suggestions = [];
        $suggested = [];

        foreach ($evidence as $key => $value) {
            if (is_string($value)) {
                $lower = mb_strtolower($value);

                // Suggest audit link if log/audit pattern detected
                if (preg_match('/audit|logged|written|recorded/i', $lower) && !in_array('audit', $suggested)) {
                    $suggestions[] = [
                        'step_suggestion' => 'audit.log',
                        'evidence_key' => (string)$key,
                        'reason' => 'Evidence contains audit/logging pattern but no audit chain link declared',
                    ];
                    $suggested[] = 'audit';
                }

                // Suggest event link if event/trigger pattern detected
                if (preg_match('/event|trigger|fired|dispatch/i', $lower) && !in_array('event', $suggested)) {
                    $suggestions[] = [
                        'step_suggestion' => 'event.fire',
                        'evidence_key' => (string)$key,
                        'reason' => 'Evidence contains event/trigger pattern but no event chain link declared',
                    ];
                    $suggested[] = 'event';
                }

                // Suggest email link if email/mail pattern detected
                if (preg_match('/email|mail.*sent|notification/i', $lower) && !in_array('email', $suggested)) {
                    $suggestions[] = [
                        'step_suggestion' => 'email.send',
                        'evidence_key' => (string)$key,
                        'reason' => 'Evidence contains email/notification pattern but no email chain link declared',
                    ];
                    $suggested[] = 'email';
                }
            }
        }

        return $suggestions;
    }

    private function isErrorIndicator(string $value): bool
    {
        $errorPatterns = [
            '/\berror\b/i',
            '/\bfailed\b/i',
            '/\bexception\b/i',
            '/\bwarning\b/i',
            '/\bfatal\b/i',
            '/\bcrash\b/i',
            '/\btimeout\b/i',
            '/\bunable to\b/i',
            '/\bcannot\b/i',
            '/\bdenied\b/i',
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function summarize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 200);
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            return '[' . count($value) . ' items]';
        }
        if ($value === null) {
            return 'null';
        }
        return gettype($value);
    }
}
