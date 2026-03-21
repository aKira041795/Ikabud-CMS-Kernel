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
    private string $cacheDir;
    private string $manifestFile;
    private array $manifest = [];
    private TemplateCompiler $compiler;
    
    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->manifestFile = $this->cacheDir . '/.manifest.json';
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
        
        // Check if recompilation needed
        if ($entry && $entry['hash'] === $hash && file_exists($entry['output'])) {
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
        ];
        $this->saveManifest();
        
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
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', basename($path, '.disyl'));
        return 'Template_' . $name . '_' . substr(md5($path), 0, 8);
    }
    
    public function invalidate(string $templatePath): void
    {
        unset($this->manifest[$templatePath]);
        $this->saveManifest();
    }
    
    public function getStats(): array
    {
        return [
            'templates' => count($this->manifest),
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
