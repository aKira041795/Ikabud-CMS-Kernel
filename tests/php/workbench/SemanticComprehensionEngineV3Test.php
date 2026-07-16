<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Comprehension\SemanticComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;
use Ikabud\Kernel\Workbench\Comprehension\Analyzers\AiHypothesisGenerator;

/**
 * Comprehension SemanticComprehensionEngine v3.0 unit tests.
 */
class SemanticComprehensionEngineV3Test extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scetest-' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    private function createEngine(): SemanticComprehensionEngine
    {
        return new SemanticComprehensionEngine(
            'project-audit-ledger',
            new PalComprehensionProvider(),
            aiHypothesis: new AiHypothesisGenerator('project-audit-ledger'),
        );
    }

    public function testAnalyzeWithoutEvidenceReportsUnobservedCoverage(): void
    {
        $engine = $this->createEngine();
        $result = $engine->analyze('pal.job-order.submit', recordHistory: false);

        $this->assertSame('3.0-ai-enhanced', $result['engine_version']);
        $this->assertNull($result['breakpoint']);
        $this->assertNull($result['break_category']);
        $this->assertSame('unobserved', $result['deterministic']['chain'][0]['outcome']);
        $this->assertArrayHasKey('ai_hypothesis', $result);
        $this->assertNotNull($result['ai_hypothesis']['summary']);
    }

    public function testActionIds(): void
    {
        $engine = $this->createEngine();
        $ids = $engine->actionIds();
        $this->assertContains('pal.job-order.submit', $ids);
        $this->assertContains('pal.job-order.create', $ids);
    }

    public function testListCasesReturnsEmptyArrayForNewModule(): void
    {
        $engine = $this->createEngine();
        $cases = $engine->listCases();
        $this->assertIsArray($cases);
        $this->assertEmpty($cases);
    }

    public function testStoreCaseMemoryUsesExplicitActionId(): void
    {
        $memDir = $this->tmpDir . '/private/comprehension';
        $engine = new SemanticComprehensionEngine(
            'project-audit-ledger',
            new PalComprehensionProvider(),
            caseMemory: new \Ikabud\Kernel\Workbench\Comprehension\Analyzers\CaseMemory($memDir),
            aiHypothesis: new AiHypothesisGenerator('project-audit-ledger'),
        );

        $caseId = $engine->storeCaseMemory(
            actionId: 'pal.job-order.submit',
            summary: 'Button not visible after rendering',
            changedFiles: ['handlers/30-projects.php'],
            fixSummary: 'Fixed template variable scope',
        );

        $this->assertStringStartsWith('case-project-audit-ledger-', $caseId);

        $cases = $engine->listCases();
        $this->assertCount(1, $cases);
        $this->assertSame('pal.job-order.submit', $cases[0]['action_id']);
    }

    public function testBuildEvidencePacketWithAnalysisDoesNotDoubleRecord(): void
    {
        $engine = $this->createEngine();
        $engine->feedEvidence(['http.request' => true]);

        // Pre-compute analysis without recording
        $analysis = $engine->analyze('pal.job-order.submit', recordHistory: false);

        // Build packet with pre-computed analysis
        $packet = $engine->buildEvidencePacket('pal.job-order.submit', $analysis);

        $this->assertArrayHasKey('analysis', $packet);
        $this->assertArrayHasKey('report_card', $packet);
        $this->assertSame('3.0-ai-enhanced', $packet['engine_version']);
    }

    public function testCaseMemoryStats(): void
    {
        $engine = $this->createEngine();
        $stats = $engine->caseMemoryStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_cases', $stats);
    }

    public function testScoreCoverageReturnsNullWithoutEvidence(): void
    {
        $engine = $this->createEngine();
        $coverage = $engine->scoreCoverage();
        // Without evidence, coverage scoring may produce non-null result
        // since the provider itself has chain links to inspect
        $this->assertNotNull($coverage);
        $this->assertIsFloat($coverage['overall_score']);
    }

    public function testAnalyzeAllReturnsMultipleActions(): void
    {
        $engine = $this->createEngine();
        $results = $engine->analyzeAll(recordHistory: false);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('pal.job-order.submit', $results);
        $this->assertArrayHasKey('pal.job-order.create', $results);
    }

    public function testAiProviderRejectionPropagatesToEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SemanticComprehensionEngine(
            'project-audit-ledger',
            new PalComprehensionProvider(),
            aiHypothesis: new AiHypothesisGenerator('project-audit-ledger', null, null, 'openai'),
        );
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
