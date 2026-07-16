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
            if (is_file($file)) {
                $class = require_once $file;
                $factory = is_string($class) ? $class : null;
                if ($factory !== null) $this->providers[$moduleId] = $factory;
            }
        }
        if ($factory === null) throw new \RuntimeException("No comprehension provider for '{$moduleId}'");
        $provider = is_callable($factory) ? $factory() : new $factory();
        if (!$provider instanceof ModuleComprehensionProvider) throw new \UnexpectedValueException("Invalid comprehension provider for '{$moduleId}'");
        return $provider;
    }
    public function modules(): array
    {
        $ids = array_keys($this->providers);
        $modulesRoot = $this->projectRoot . '/modules';
        if (is_dir($modulesRoot)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getFilename() !== 'WorkbenchComprehensionProvider.php') continue;
                $manifestPath = $file->getPath() . '/module.json';
                $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
                if (is_array($manifest) && is_string($manifest['id'] ?? null)) $ids[] = $manifest['id'];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
    private function conventionFile(string $moduleId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $moduleId);
        $direct = $this->projectRoot . '/modules/' . $safeId . '/WorkbenchComprehensionProvider.php';
        if (is_file($direct)) return $direct;

        // Module discovery supports grouped directories (for example modules/healthcare/ehr).
        $modulesRoot = $this->projectRoot . '/modules';
        if (!is_dir($modulesRoot)) return $direct;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'module.json') continue;
            $manifest = json_decode((string)file_get_contents($file->getPathname()), true);
            if (is_array($manifest) && ($manifest['id'] ?? null) === $moduleId) {
                return $file->getPath() . '/WorkbenchComprehensionProvider.php';
            }
        }
        return $direct;
    }
}
