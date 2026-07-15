<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ModuleComprehensionProvider,
    ActionContract,
    ChainLink,
};

/**
 * Provider Coverage Scorer.
 *
 * Compares actual browser/test evidence to declared provider chains and
 * scores the completeness and accuracy of the module's comprehension provider.
 *
 * Scoring dimensions:
 *   1. Missing UI links — evidence of UI interaction but no chain link declared
 *   2. Missing DB effects — evidence of DB changes but no chain link declared
 *   3. Missing audit/event assertions — evidence of audit/event activity but no link
 *   4. Stale chain descriptions — description doesn't match evidence text
 *   5. Vague chain descriptions — description is too generic to be useful
 *   6. Unused chain links — declared links that never appear in evidence
 *   7. Probe coverage — what percentage of DB links have SQL probes
 *
 * Each dimension produces a score (0.0–1.0) and actionable suggestions.
 */
class ProviderCoverageScorer
{
    /** Minimum description length to be considered "not vague" */
    private const MIN_DESCRIPTION_LENGTH = 15;

    /** Words that make a description vague */
    private const VAGUE_WORDS = ['check', 'verify', 'ensure', 'should', 'need to', 'make sure'];

    /**
     * Score a provider's coverage against runtime evidence.
     *
     * @param ModuleComprehensionProvider $provider The provider to score
     * @param array $evidence Runtime evidence from test execution
     * @return array{
     *   overall_score: float,
     *   dimensions: array<string, array{score: float, details: string}>,
     *   suggestions: array<int, string>,
     * }
     */
    public function score(ModuleComprehensionProvider $provider, array $evidence): array
    {
        $actions = $provider->actions();
        $suggestions = [];

        // Dimension 1: UI chain completeness
        $uiScore = $this->scoreUiCoverage($actions, $evidence, $suggestions);

        // Dimension 2: DB effect coverage
        $dbScore = $this->scoreDbCoverage($actions, $evidence, $suggestions);

        // Dimension 3: Audit/event assertion coverage
        $eventScore = $this->scoreEventCoverage($actions, $evidence, $suggestions);

        // Dimension 4: Description freshness (match between description and evidence)
        $freshnessScore = $this->scoreDescriptionFreshness($actions, $evidence, $suggestions);

        // Dimension 5: Description clarity (avoid vague descriptions)
        $clarityScore = $this->scoreDescriptionClarity($actions, $suggestions);

        // Dimension 6: Unused links
        $usageScore = $this->scoreLinkUsage($actions, $evidence, $suggestions);

        // Dimension 7: Probe coverage
        $probeScore = $this->scoreProbeCoverage($actions, $suggestions);

        $dimensions = [
            'ui_coverage' => ['score' => $uiScore, 'details' => $this->dimensionDetail($uiScore, 'UI chain links')],
            'db_coverage' => ['score' => $dbScore, 'details' => $this->dimensionDetail($dbScore, 'DB effect links')],
            'event_audit_coverage' => ['score' => $eventScore, 'details' => $this->dimensionDetail($eventScore, 'Event/audit links')],
            'description_freshness' => ['score' => $freshnessScore, 'details' => $this->dimensionDetail($freshnessScore, 'Description-evidence match')],
            'description_clarity' => ['score' => $clarityScore, 'details' => $this->dimensionDetail($clarityScore, 'Description vagueness')],
            'link_usage' => ['score' => $usageScore, 'details' => $this->dimensionDetail($usageScore, 'Links used in evidence')],
            'probe_coverage' => ['score' => $probeScore, 'details' => $this->dimensionDetail($probeScore, 'SQL probe coverage')],
        ];

        // Overall: weighted average
        $weights = [
            'ui_coverage' => 0.20,
            'db_coverage' => 0.20,
            'event_audit_coverage' => 0.10,
            'description_freshness' => 0.15,
            'description_clarity' => 0.15,
            'link_usage' => 0.10,
            'probe_coverage' => 0.10,
        ];

        $overall = 0.0;
        foreach ($dimensions as $key => $dim) {
            $overall += $dim['score'] * ($weights[$key] ?? 0.1);
        }

        return [
            'overall_score' => round($overall, 4),
            'dimensions' => $dimensions,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Score whether UI interactions in evidence have corresponding chain links.
     */
    private function scoreUiCoverage(array $actions, array $evidence, array &$suggestions): float
    {
        $uiEvidenceCount = 0;
        $uiLinkCount = 0;

        foreach ($evidence as $key => $value) {
            if (str_starts_with((string)$key, '_')) continue;
            $lower = mb_strtolower((string)$key);
            if (preg_match('/click|visible|render|button|link|href|selector|dom|element/i', $lower)) {
                $uiEvidenceCount++;
            }
        }

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                if ($link->category === 'ui' || $link->category === 'verify') {
                    $uiLinkCount++;
                }
            }
        }

        // If there's UI evidence but no UI links, score is 0
        if ($uiEvidenceCount > 0 && $uiLinkCount === 0) {
            $suggestions[] = "UI evidence detected ({$uiEvidenceCount} keys) but no UI chain links declared. Consider adding button.visible, ui.status_updated, or verify.* links.";
            return 0.0;
        }

        if ($uiEvidenceCount === 0 && $uiLinkCount > 0) {
            // UI links declared but no evidence — might be a test issue
            $suggestions[] = "UI chain links declared ({$uiLinkCount}) but no UI evidence collected. Check that ActionObserver captures UI interactions.";
            return 0.3;
        }

        if ($uiEvidenceCount === 0 && $uiLinkCount === 0) {
            return 1.0; // No data — can't score
        }

        // Ratio of declared vs. detected — cap at 1.0
        $ratio = min(1.0, $uiLinkCount / max(1, $uiEvidenceCount));
        return round($ratio, 4);
    }

    /**
     * Score whether DB effects match declared DB chain links + probes.
     */
    private function scoreDbCoverage(array $actions, array $evidence, array &$suggestions): float
    {
        $dbLinkCount = 0;
        $dbLinksWithProbes = 0;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                if ($link->category === 'db') {
                    $dbLinkCount++;
                    if ($link->probe !== null) {
                        $dbLinksWithProbes++;
                    }
                }
            }
        }

        // Check evidence for DB effects
        $hasDbEvidence = false;
        foreach ($evidence as $key => $value) {
            if (str_starts_with((string)$key, 'db.') || str_starts_with((string)$key, 'sql')) {
                $hasDbEvidence = true;
                break;
            }
        }

        if ($hasDbEvidence && $dbLinkCount === 0) {
            $suggestions[] = "DB evidence detected but no DB chain links declared. Add db.* links with SQL probes to verify database state.";
            return 0.0;
        }

        if ($dbLinkCount === 0) {
            return 1.0; // No data
        }

        // Score: links with probes / total DB links
        $probeRatio = $dbLinksWithProbes / $dbLinkCount;
        if ($probeRatio < 1.0) {
            $missing = $dbLinkCount - $dbLinksWithProbes;
            $suggestions[] = "{$missing} DB link(s) are missing SQL probes. Add probe queries to enable automated verification.";
        }

        return round(0.5 + ($probeRatio * 0.5), 4);
    }

    /**
     * Score whether event/audit effects are captured in chain links.
     */
    private function scoreEventCoverage(array $actions, array $evidence, array &$suggestions): float
    {
        $hasEventLinks = false;
        $hasAuditLinks = false;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                if ($link->category === 'event') $hasEventLinks = true;
                if ($link->category === 'audit') $hasAuditLinks = true;
            }
        }

        // Check evidence for event/audit patterns
        $evidenceText = '';
        foreach ($evidence as $key => $value) {
            if (is_string($value)) $evidenceText .= ' ' . $value;
        }

        $hasEventEvidence = (bool)preg_match('/event|trigger|fired|dispatch/i', $evidenceText);
        $hasAuditEvidence = (bool)preg_match('/audit|logged|recorded|written/i', $evidenceText);

        $issues = [];
        if ($hasEventEvidence && !$hasEventLinks) {
            $issues[] = 'event';
        }
        if ($hasAuditEvidence && !$hasAuditLinks) {
            $issues[] = 'audit';
        }

        if (!empty($issues)) {
            $suggestions[] = 'Evidence suggests ' . implode(' and ', $issues) . ' activity but no corresponding chain link declared.';
            return 0.2;
        }

        return 1.0;
    }

    /**
     * Score whether chain descriptions match evidence text.
     */
    private function scoreDescriptionFreshness(array $actions, array $evidence, array &$suggestions): float
    {
        $totalLinks = 0;
        $matchingLinks = 0;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                $totalLinks++;
                // Check if the description contains words that appear in evidence
                $descWords = str_word_count(mb_strtolower($link->description), 1);
                $hasMatch = false;

                foreach ($evidence as $key => $value) {
                    if (is_string($value)) {
                        $lowerVal = mb_strtolower($value);
                        foreach ($descWords as $word) {
                            if (strlen($word) > 3 && str_contains($lowerVal, $word)) {
                                $hasMatch = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($hasMatch) {
                    $matchingLinks++;
                }
            }
        }

        if ($totalLinks === 0) {
            return 1.0;
        }

        $ratio = $matchingLinks / $totalLinks;
        if ($ratio < 0.3) {
            $suggestions[] = "Only {$matchingLinks}/{$totalLinks} chain descriptions match evidence text. Consider updating descriptions to reflect current behavior.";
        }

        return round($ratio, 4);
    }

    /**
     * Score whether descriptions are specific enough (not vague).
     */
    private function scoreDescriptionClarity(array $actions, array &$suggestions): float
    {
        $totalLinks = 0;
        $vagueLinks = 0;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                $totalLinks++;
                $desc = $link->description;

                // Check length
                if (strlen($desc) < self::MIN_DESCRIPTION_LENGTH) {
                    $vagueLinks++;
                    continue;
                }

                // Check for vague words
                foreach (self::VAGUE_WORDS as $word) {
                    if (stripos($desc, $word) !== false) {
                        $vagueLinks++;
                        continue 2;
                    }
                }
            }
        }

        if ($totalLinks === 0) {
            return 1.0;
        }

        $clarityRatio = 1.0 - ($vagueLinks / $totalLinks);
        if ($clarityRatio < 0.7) {
            $suggestions[] = "{$vagueLinks}/{$totalLinks} chain descriptions are vague or too short. Use specific descriptions (e.g. 'Project status changes from draft to pending' instead of 'Check status').";
        }

        return round($clarityRatio, 4);
    }

    /**
     * Score whether declared links are being exercised in evidence.
     */
    private function scoreLinkUsage(array $actions, array $evidence, array &$suggestions): float
    {
        $totalLinks = 0;
        $usedLinks = 0;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                $totalLinks++;
                if (isset($evidence[$link->step])) {
                    $usedLinks++;
                }
            }
        }

        if ($totalLinks === 0) {
            return 1.0;
        }

        $usageRatio = $usedLinks / $totalLinks;
        if ($usageRatio < 0.5) {
            $unused = $totalLinks - $usedLinks;
            $suggestions[] = "{$unused}/{$totalLinks} chain links have no matching evidence. Either the test doesn't cover these steps, or the links are stale.";
        }

        return round($usageRatio, 4);
    }

    /**
     * Score the proportion of DB links that have SQL probes.
     */
    private function scoreProbeCoverage(array $actions, array &$suggestions): float
    {
        $dbLinks = 0;
        $probedLinks = 0;

        foreach ($actions as $action) {
            foreach ($action->chain as $link) {
                if ($link->category === 'db') {
                    $dbLinks++;
                    if ($link->probe !== null && $link->probe !== '') {
                        $probedLinks++;
                    }
                }
            }
        }

        if ($dbLinks === 0) {
            return 1.0; // No DB links to probe
        }

        $probeRatio = $probedLinks / $dbLinks;
        if ($probeRatio < 1.0) {
            $suggestions[] = "{$probedLinks}/{$dbLinks} DB links have SQL probes. Add probes to enable automated DB state verification.";
        }

        return round($probeRatio, 4);
    }

    private function dimensionDetail(float $score, string $label): string
    {
        return $score >= 0.8 ? "Good {$label}" : ($score >= 0.5 ? "Moderate {$label}" : "Poor {$label}");
    }
}
