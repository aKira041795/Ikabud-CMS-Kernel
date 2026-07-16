<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Extensions;

final class ExtensionRegistry
{
    /** @var array<string,object> */ private array $extensions = [];
    public function register(object $extension): void
    {
        if (!$extension instanceof EvidenceCollectorExtension && !$extension instanceof GateExtension && !$extension instanceof ExporterExtension) throw new \InvalidArgumentException('Unsupported Workbench extension');
        $id = $extension->id();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]+$/', $id) || isset($this->extensions[$id])) throw new \InvalidArgumentException('Invalid or duplicate extension id');
        $this->extensions[$id] = $extension;
    }
    public function ids(): array { $ids = array_keys($this->extensions); sort($ids); return $ids; }
    public function runCollector(string $id, array $context, string $authoritativeDigest): array
    {
        $extension = $this->extensions[$id] ?? null;
        if (!$extension instanceof EvidenceCollectorExtension) throw new \RuntimeException('Evidence collector not found');
        $observations = $extension->collect($context);
        foreach ($observations as $observation) {
            if (($observation['authoritative_digest'] ?? $authoritativeDigest) !== $authoritativeDigest) throw new \RuntimeException('Extension attempted to replace authoritative truth');
            if (!isset($observation['source'], $observation['outcome'])) throw new \RuntimeException('Extension evidence lacks provenance or outcome');
        }
        return $observations;
    }
    public function runGate(string $id, array $evidence): array
    {
        $extension = $this->extensions[$id] ?? null;
        if (!$extension instanceof GateExtension) throw new \RuntimeException('Gate not found');
        $result = $extension->evaluate($evidence);
        if (!isset($result['passed'], $result['reason'], $result['evidence_links']) || !is_bool($result['passed'])) throw new \RuntimeException('Invalid gate result');
        return $result;
    }
}
