<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\AiHypothesisGenerator;

/**
 * Comprehension AiHypothesisGenerator unit tests.
 */
class ComprehensionAiHypothesisGeneratorTest extends \PHPUnit\Framework\TestCase
{
    public function testDefaultProviderIsHeuristic(): void
    {
        $gen = new AiHypothesisGenerator('test-module');
        // Constructor succeeds — provider validation passed
        $this->assertInstanceOf(AiHypothesisGenerator::class, $gen);
    }

    public function testExplicitHeuristicProviderAccepted(): void
    {
        $gen = new AiHypothesisGenerator('test-module', null, null, 'heuristic');
        $this->assertInstanceOf(AiHypothesisGenerator::class, $gen);
    }

    public function testUnsupportedProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported AI provider 'openai'");
        new AiHypothesisGenerator('test-module', null, null, 'openai');
    }

    public function testCopilotProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported AI provider 'copilot'");
        new AiHypothesisGenerator('test-module', null, null, 'copilot');
    }

    public function testEmptyProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiHypothesisGenerator('test-module', null, null, '');
    }

    public function testGenerateReturnsHypothesisForMissingEvidence(): void
    {
        $gen = new AiHypothesisGenerator('test-module');
        $result = $gen->generate(
            ['breakpoint' => null, 'break_category' => null, 'action' => 'test.act', 'confidence' => ['score' => 0.9, 'label' => 'high'], 'root_cause_hypothesis' => ['summary' => 'All good', 'severity' => 'success'], 'anomalies' => ['unexpected_evidence' => []]],
            [],
        );
        $this->assertSame('All good', $result->summary);
        $this->assertSame(0.9, $result->confidence);
        $this->assertSame('success', $result->severity);
    }

    public function testGenerateReturnsHypothesisWithBreakpoint(): void
    {
        $gen = new AiHypothesisGenerator('test-module');
        $result = $gen->generate(
            [
                'breakpoint' => 'button.visible',
                'break_category' => 'ui',
                'action' => 'test.act',
                'confidence' => ['score' => 0.35, 'label' => 'low'],
                'root_cause_hypothesis' => ['summary' => 'UI element not visible', 'severity' => 'warning'],
                'anomalies' => ['unexpected_evidence' => []],
                'diagnosis' => ['primary_classification' => ['category' => 'template']],
            ],
            [],
        );
        $this->assertStringContainsString("button.visible", $result->summary);
        $this->assertSame('warning', $result->severity);
        $this->assertNotEmpty($result->doNotChangeBoundary);
    }

    public function testRemediationPlanReturnedForBreakpoint(): void
    {
        $gen = new AiHypothesisGenerator('test-module');
        $plan = $gen->generateRemediationPlan(
            [
                'breakpoint' => 'http.request',
                'break_category' => 'http',
                'action' => 'test.act',
                'diagnosis' => ['primary_classification' => ['category' => 'csrf']],
            ],
            [],
        );
        $this->assertNotNull($plan);
        $this->assertSame('http.request', $plan->failingStep);
        $this->assertStringContainsString('CSRF', $plan->invariantViolated);
    }

    public function testRemediationPlanNullForNoBreakpoint(): void
    {
        $gen = new AiHypothesisGenerator('test-module');
        $plan = $gen->generateRemediationPlan(
            ['breakpoint' => null, 'break_category' => null, 'action' => 'test.act'],
            [],
        );
        $this->assertNull($plan);
    }
}
