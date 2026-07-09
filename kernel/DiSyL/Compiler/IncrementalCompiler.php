<?php
/**
 * DiSyL v7.0 Incremental Compiler
 * Only recompiles changed templates and their dependents.
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 7.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

class IncrementalCompiler
{
    private const GC_INTERVAL_SECONDS = 300;
    private const GC_STALE_GRACE_SECONDS = 3600;
    private const GC_SCAN_LIMIT = 200;
    private const GC_DELETE_LIMIT = 20;

    private string $cacheDir;
    private string $manifestFile;
    private string $gcStateFile;
    private array $manifest = [];
    private TemplateCompiler $compiler;
    
    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->manifestFile = $this->cacheDir . '/.manifest.json';
        $this->gcStateFile = $this->cacheDir . '/.gc-state.json';
        $this->compiler = new TemplateCompiler();
        $this->loadManifest();
    }
    
    private function loadManifest(): void
    {
        if (file_exists($this->manifestFile)) {
            $this->manifest = json_decode(file_get_contents($this->manifestFile), true) ?? [];
        }
    }
    
    private function saveManifest(): void
    {
        file_put_contents($this->manifestFile, json_encode($this->manifest, JSON_PRETTY_PRINT));
    }
    
    public function compile(string $templatePath): CompileResult
    {
        $hash = $this->getFileHash($templatePath);
        $entry = $this->manifest[$templatePath] ?? null;
        
        // Check if recompilation needed (hash match + same compiler version + output exists)
        if ($entry
            && $entry['hash'] === $hash
            && ($entry['compilerVersion'] ?? 0) === TemplateCompiler::COMPILER_VERSION
            && file_exists($entry['output'])) {
            return new CompileResult($entry['output'], false, 0);
        }
        
        $startTime = microtime(true);
        
        // Parse and compile
        $parser = new \Ikabud\Kernel\DiSyL\v4\Parser();
        $source = file_get_contents($templatePath);
        $ast = $parser->parse($source, $templatePath);
        
        // Extract dependencies
        $deps = $this->extractDependencies($ast);
        
        // Compile
        $className = $this->getClassName($templatePath);
        $code = $this->compiler->compile($ast, $className);
        
        // Write output
        $outputPath = $this->cacheDir . '/' . $className . '.php';
        file_put_contents($outputPath, $code);
        
        // Update manifest
        $this->manifest[$templatePath] = [
            'hash' => $hash,
            'output' => $outputPath,
            'className' => $className,
            'dependencies' => $deps,
            'compiledAt' => time(),
            'compilerVersion' => TemplateCompiler::COMPILER_VERSION,
        ];
        $this->saveManifest();
        $this->runCacheGcIfDue();
        
        $duration = (microtime(true) - $startTime) * 1000;
        
        return new CompileResult($outputPath, true, $duration);
    }
    
    public function compileAll(string $templatesDir): array
    {
        $results = [];
        $files = glob($templatesDir . '/**/*.disyl') ?: [];
        $files = array_merge($files, glob($templatesDir . '/*.disyl') ?: []);
        
        foreach ($files as $file) {
            $results[$file] = $this->compile($file);
        }
        
        // Recompile dependents of changed files
        $changed = array_filter($results, fn($r) => $r->wasRecompiled);
        if (!empty($changed)) {
            $this->recompileDependents(array_keys($changed), $results);
        }
        
        return $results;
    }
    
    private function recompileDependents(array $changedFiles, array &$results): void
    {
        foreach ($this->manifest as $path => $entry) {
            if (!is_array($entry)) continue;
            if (isset($results[$path]) && $results[$path]->wasRecompiled) continue;
            
            $deps = $entry['dependencies'] ?? [];
            foreach ($changedFiles as $changed) {
                if (in_array(basename($changed, '.disyl'), $deps)) {
                    // Force recompile by clearing hash
                    $this->manifest[$path]['hash'] = '';
                    $results[$path] = $this->compile($path);
                    break;
                }
            }
        }
    }
    
    private function extractDependencies($ast): array
    {
        $deps = [];
        $this->walkAST($ast, function($node) use (&$deps) {
            if ($node->getType() === 'include') {
                $deps[] = $node->getTemplate();
            }
            if ($node->getType() === 'control' && $node->getTag() === 'extends') {
                $deps[] = $node->getAttribute('template');
            }
        });
        return array_unique($deps);
    }
    
    private function walkAST($node, callable $callback): void
    {
        $callback($node);
        if (method_exists($node, 'getChildren')) {
            foreach ($node->getChildren() as $child) {
                $this->walkAST($child, $callback);
            }
        }
        if (method_exists($node, 'getBody') && $node->getBody()) {
            $this->walkAST($node->getBody(), $callback);
        }
    }
    
    private function getFileHash(string $path): string
    {
        return md5_file($path) ?: '';
    }
    
    private function getClassName(string $path): string
    {
        $version = TemplateCompiler::COMPILER_VERSION;
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', basename($path, '.disyl'));
        return 'Template_' . $name . '_v' . $version . '_' . substr(md5($path . ':v' . $version), 0, 8);
    }

    private function runCacheGcIfDue(): void
    {
        $lastRun = $this->getLastGcRun();
        $now = time();

        if ($lastRun > 0 && ($now - $lastRun) < self::GC_INTERVAL_SECONDS) {
            return;
        }

        $activeOutputs = [];
        foreach ($this->manifest as $entry) {
            if (!is_array($entry)) continue;
            $output = $entry['output'] ?? null;
            if (is_string($output) && $output !== '') {
                $activeOutputs[$output] = true;
            }
        }

        $scanCount = 0;
        $deleteCount = 0;
        $files = glob($this->cacheDir . '/Template_*.php') ?: [];
        foreach ($files as $file) {
            if ($scanCount >= self::GC_SCAN_LIMIT || $deleteCount >= self::GC_DELETE_LIMIT) {
                break;
            }
            $scanCount++;

            if (isset($activeOutputs[$file])) {
                continue;
            }

            $mtime = @filemtime($file);
            if ($mtime !== false && ($now - $mtime) < self::GC_STALE_GRACE_SECONDS) {
                continue;
            }

            if (@unlink($file)) {
                $deleteCount++;
            }
        }

        $this->setLastGcRun($now);
    }

    private function getLastGcRun(): int
    {
        if (!file_exists($this->gcStateFile)) {
            return 0;
        }

        $raw = @file_get_contents($this->gcStateFile);
        if (!is_string($raw) || $raw === '') {
            return 0;
        }

        $state = json_decode($raw, true);
        if (!is_array($state)) {
            return 0;
        }

        $lastRun = $state['lastRun'] ?? 0;
        return is_int($lastRun) ? $lastRun : 0;
    }

    private function setLastGcRun(int $timestamp): void
    {
        @file_put_contents($this->gcStateFile, json_encode(['lastRun' => $timestamp], JSON_PRETTY_PRINT));
    }
    
    public function invalidate(string $templatePath): void
    {
        unset($this->manifest[$templatePath]);
        $this->saveManifest();
    }
    
    public function getStats(): array
    {
        $templateCount = 0;
        foreach ($this->manifest as $entry) {
            if (is_array($entry)) {
                $templateCount++;
            }
        }

        return [
            'templates' => $templateCount,
            'cacheDir' => $this->cacheDir,
        ];
    }
}

class CompileResult
{
    public function __construct(
        public string $outputPath,
        public bool $wasRecompiled,
        public float $durationMs
    ) {}
}
