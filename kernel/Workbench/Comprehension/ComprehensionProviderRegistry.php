<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Comprehension;
use Ikabud\Kernel\Workbench\Comprehension\Contracts\ModuleComprehensionProvider;

final class ComprehensionProviderRegistry
{
    /** @var array<string, callable|string> */ private array $providers = [];
    public function __construct(private readonly string $projectRoot)
    {
        $this->providers['project-audit-ledger'] = PalComprehensionProvider::class;
    }
    public function register(string $moduleId, callable|string $factory): void { $this->providers[$moduleId] = $factory; }
    public function has(string $moduleId): bool { return isset($this->providers[$moduleId]) || is_file($this->conventionFile($moduleId)); }
    public function resolve(string $moduleId): ModuleComprehensionProvider
    {
        $factory = $this->providers[$moduleId] ?? null;
        if ($factory === null) {
            $file = $this->conventionFile($moduleId);
            if (is_file($file)) { $class = require $file; $factory = is_string($class) ? $class : null; }
        }
        if ($factory === null) throw new \RuntimeException("No comprehension provider for '{$moduleId}'");
        $provider = is_callable($factory) ? $factory() : new $factory();
        if (!$provider instanceof ModuleComprehensionProvider) throw new \UnexpectedValueException("Invalid comprehension provider for '{$moduleId}'");
        return $provider;
    }
    public function modules(): array { return array_keys($this->providers); }
    private function conventionFile(string $moduleId): string { return $this->projectRoot . '/modules/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleId) . '/WorkbenchComprehensionProvider.php'; }
}
