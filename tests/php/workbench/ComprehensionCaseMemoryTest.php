<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\CaseMemory;
use Ikabud\Kernel\Workbench\Comprehension\Contracts\CaseMemoryEntry;

/**
 * Comprehension CaseMemory unit tests.
 */
class ComprehensionCaseMemoryTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cm-test-' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    public function testStoreAndList(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');

        $mem->store(new CaseMemoryEntry(
            id: 'case-test-1',
            moduleId: 'project-audit-ledger',
            actionId: 'pal.job-order.submit',
            summary: 'Button not visible after render',
            changedFiles: ['handlers/30-projects.php'],
            fixSummary: 'Added missing template variable',
        ));

        $list = $mem->listByModule('project-audit-ledger');
        $this->assertCount(1, $list);
        $this->assertSame('case-test-1', $list[0]['id']);
        $this->assertSame('pal.job-order.submit', $list[0]['action_id']);
    }

    public function testListFiltersByModule(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');

        $mem->store(new CaseMemoryEntry(
            id: 'case-pal-1',
            moduleId: 'project-audit-ledger',
            actionId: 'pal.job-order.submit',
            summary: 'PAL submit fails',
        ));
        $mem->store(new CaseMemoryEntry(
            id: 'case-att-1',
            moduleId: 'attendance-wage',
            actionId: 'att.check-in',
            summary: 'Check-in fails',
        ));

        $this->assertCount(1, $mem->listByModule('project-audit-ledger'));
        $this->assertCount(1, $mem->listByModule('attendance-wage'));
        $this->assertCount(0, $mem->listByModule('wms'));
    }

    public function testDeleteRemovesFromIndex(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');

        $mem->store(new CaseMemoryEntry(
            id: 'case-to-delete',
            moduleId: 'module-x',
            actionId: 'act.x',
            summary: 'Will be deleted',
        ));

        $this->assertTrue($mem->delete('case-to-delete'));
        $this->assertCount(0, $mem->listByModule('module-x'));
        $this->assertSame(0, $mem->stats()['total_cases']);
    }

    public function testDeleteNonExistentReturnsFalse(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');
        $this->assertFalse($mem->delete('nonexistent-case'));
    }

    public function testFindSimilarByExactAction(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');

        $mem->store(new CaseMemoryEntry(
            id: 'case-sim-1',
            moduleId: 'module-x',
            actionId: 'act.submit',
            summary: 'Submit failure',
        ));

        $similar = $mem->findSimilar('module-x', 'act.submit');
        $this->assertCount(1, $similar);
        $this->assertSame('case-sim-1', $similar[0]['case']->id);
    }

    public function testStats(): void
    {
        $mem = new CaseMemory($this->tmpDir . '/private/comprehension');

        $mem->store(new CaseMemoryEntry('c1', 'mod-a', 'a1', 'Bug 1'));
        $mem->store(new CaseMemoryEntry('c2', 'mod-a', 'a2', 'Bug 2'));
        $mem->store(new CaseMemoryEntry('c3', 'mod-b', 'b1', 'Bug 3'));

        $stats = $mem->stats();
        $this->assertSame(3, $stats['total_cases']);
        $this->assertArrayHasKey('mod-a', $stats['modules']);
        $this->assertArrayHasKey('mod-b', $stats['modules']);
    }

    public function testStoreThrowsOnInvalidPath(): void
    {
        $mem = new CaseMemory('/nonexistent/path/that/will/fail/comprehension');

        $this->expectException(\RuntimeException::class);
        $mem->store(new CaseMemoryEntry(
            id: 'case-fail',
            moduleId: 'mod',
            actionId: 'act',
            summary: 'Should fail',
        ));
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
