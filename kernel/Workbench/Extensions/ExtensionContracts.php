<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Extensions;

interface EvidenceCollectorExtension
{
    public function id(): string;
    /** @return list<array<string,mixed>> */
    public function collect(array $readOnlyContext): array;
}

interface GateExtension
{
    public function id(): string;
    /** @return array{passed:bool,reason:string,evidence_links:list<string>} */
    public function evaluate(array $readOnlyEvidence): array;
}

interface ExporterExtension
{
    public function id(): string;
    public function export(array $readOnlyRun): string;
}
