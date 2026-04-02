#!/usr/bin/env php
<?php
declare(strict_types=1);

use Ikabud\Kernel\DiSyL\TemplateEngine;

require __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';

function benchmarkUsage(): void
{
    echo "DiSyL benchmark harness\n";
    echo "Usage: php scripts/benchmark-disyl.php [--iterations=N] [--samples=N] [--warmup=N] [--json]\n";
}

function benchmarkOptions(array $argv): array
{
    $options = [
        'iterations' => 3000,
        'samples' => 5,
        'warmup' => 1,
        'json' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            benchmarkUsage();
            exit(0);
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException('Unknown option: ' . $arg);
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        if (!in_array($name, ['iterations', 'samples', 'warmup'], true) || !ctype_digit($value)) {
            throw new InvalidArgumentException('Invalid option: ' . $arg);
        }

        $options[$name] = max(1, (int) $value);
    }

    return $options;
}

function benchmarkDigest(mixed $value): int
{
    if (is_string($value)) {
        return (int) sprintf('%u', crc32($value));
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) sprintf('%u', crc32(sprintf('%.12F', $value)));
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if ($value === null) {
        return 0;
    }

    return (int) sprintf('%u', crc32(serialize($value)));
}

function benchmarkBatch(callable $scenario, int $iterations): array
{
    $sink = 0;
    $startedAt = hrtime(true);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $sink ^= benchmarkDigest($scenario());
    }

    return [hrtime(true) - $startedAt, $sink];
}

function benchmarkMedian(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    if ($count % 2 === 1) {
        return $values[$middle];
    }

    return ($values[$middle - 1] + $values[$middle]) / 2;
}

function benchmarkScenario(callable $scenario, int $iterations, int $samples, int $warmup): array
{
    for ($warm = 0; $warm < $warmup; $warm++) {
        benchmarkBatch($scenario, $iterations);
    }

    $sampleNs = [];
    $sink = 0;
    for ($sample = 0; $sample < $samples; $sample++) {
        [$elapsedNs, $batchSink] = benchmarkBatch($scenario, $iterations);
        $sampleNs[] = $elapsedNs;
        $sink ^= $batchSink;
    }

    $perOpUs = array_map(
        static fn (int $elapsedNs): float => $elapsedNs / $iterations / 1000,
        $sampleNs
    );

    return [
        'mean_us' => array_sum($perOpUs) / count($perOpUs),
        'median_us' => benchmarkMedian($perOpUs),
        'min_us' => min($perOpUs),
        'max_us' => max($perOpUs),
        'samples' => $samples,
        'iterations' => $iterations,
        'checksum' => $sink,
    ];
}

function benchmarkFormat(float $value): string
{
    return str_pad(number_format($value, 2), 10, ' ', STR_PAD_LEFT);
}

function benchmarkLine(string $name, array $metrics): string
{
    return sprintf(
        "%-34s %s %s %s %s\n",
        $name,
        benchmarkFormat($metrics['median_us']),
        benchmarkFormat($metrics['mean_us']),
        benchmarkFormat($metrics['min_us']),
        benchmarkFormat($metrics['max_us'])
    );
}

function benchmarkDir(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function benchmarkRemoveDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $path . '/' . $entry;
        if (is_dir($entryPath)) {
            benchmarkRemoveDir($entryPath);
            continue;
        }

        @unlink($entryPath);
    }

    @rmdir($path);
}

try {
    $options = benchmarkOptions($argv);

    $templateDir = sys_get_temp_dir() . '/disyl-benchmark-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $cacheDir = $templateDir . '/cache';
    benchmarkDir($cacheDir);

    register_shutdown_function(static function () use ($templateDir): void {
        benchmarkRemoveDir($templateDir);
    });

    $engine = new TemplateEngine($templateDir, $cacheDir, true);

    $processControlStructures = new ReflectionMethod(TemplateEngine::class, 'processControlStructures');
    $processControlStructures->setAccessible(true);

    $processVariables = new ReflectionMethod(TemplateEngine::class, 'processVariables');
    $processVariables->setAccessible(true);

    $processScriptVariables = new ReflectionMethod(TemplateEngine::class, 'processScriptVariables');
    $processScriptVariables->setAccessible(true);

    $resolveValueWithFilters = new ReflectionMethod(TemplateEngine::class, 'resolveValueWithFilters');
    $resolveValueWithFilters->setAccessible(true);

    $buildOutputCacheKey = new ReflectionMethod(TemplateEngine::class, 'buildOutputCacheKey');
    $buildOutputCacheKey->setAccessible(true);

    $controlTemplate = <<<'DISYL'
{foreach users as user}
  {if user.active}
    <li>{user.name}</li>
  {else}
    <span>{user.name}</span>
  {/if}
{/foreach}
DISYL;

    $controlContext = [
        'users' => [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Cara', 'active' => true],
        ],
    ];

    $simpleVariablesTemplate = '<h1>{name}</h1><p>{count}</p><small>{user.role}</small>';
    $filteredVariablesTemplate = '<h1>{name | upper}</h1><p>{total | number_format:2}</p><small>{user.role | title}</small>';
    $scriptVariablesTemplate = 'const name = "{name}"; const count = {count};';

    $sharedContext = [
        'name' => 'Alice Example',
        'count' => 42,
        'total' => 1234.5,
        'user' => ['role' => 'store manager', 'name' => 'Alice Example'],
    ];

    $fastCacheContext = [
        'user' => ['name' => 'Alice', 'role' => 'admin'],
        'count' => 42,
        'flags' => ['preview' => true, 'beta' => false],
    ];
    $fallbackCacheContext = [
        'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 1]]]]]]]],
    ];

    $scenarios = [
        'renderString plain' => static fn (): string => $engine->renderString('<div>plain content only</div>', []),
        'renderString variables' => static fn (): string => $engine->renderString($simpleVariablesTemplate, $sharedContext),
        'renderString script-aware' => static fn (): string => $engine->renderString('<script>const cfg = { label: "{name}", nested: { ok: true } };</script>', $sharedContext),
        'processControlStructures nested' => static fn (): string => $processControlStructures->invoke($engine, $controlTemplate, $controlContext),
        'processVariables simple' => static fn (): string => $processVariables->invoke($engine, $simpleVariablesTemplate, $sharedContext),
        'processVariables filtered' => static fn (): string => $processVariables->invoke($engine, $filteredVariablesTemplate, $sharedContext),
        'processScriptVariables simple' => static fn (): string => $processScriptVariables->invoke($engine, $scriptVariablesTemplate, $sharedContext),
        'resolveValue plain' => static fn (): mixed => $resolveValueWithFilters->invoke($engine, 'name', $sharedContext),
        'resolveValue dot-path' => static fn (): mixed => $resolveValueWithFilters->invoke($engine, 'user.name', $sharedContext),
        'resolveValue filtered' => static fn (): mixed => $resolveValueWithFilters->invoke($engine, 'name | upper', $sharedContext),
        'buildOutputCacheKey fast' => static fn (): string => $buildOutputCacheKey->invoke($engine, '/tmp/example.disyl', $fastCacheContext),
        'buildOutputCacheKey fallback' => static fn (): string => $buildOutputCacheKey->invoke($engine, '/tmp/example.disyl', $fallbackCacheContext),
    ];

    $results = [];
    foreach ($scenarios as $name => $scenario) {
        $results[$name] = benchmarkScenario($scenario, $options['iterations'], $options['samples'], $options['warmup']);
    }

    if ($options['json']) {
        echo json_encode([
            'php_version' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'iterations' => $options['iterations'],
            'samples' => $options['samples'],
            'warmup' => $options['warmup'],
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    echo "DiSyL benchmark harness\n";
    echo 'PHP ' . PHP_VERSION . ' on ' . PHP_OS_FAMILY . "\n";
    echo 'iterations=' . $options['iterations'] . ', samples=' . $options['samples'] . ', warmup=' . $options['warmup'] . "\n\n";
    echo sprintf("%-34s %10s %10s %10s %10s\n", 'scenario', 'median', 'mean', 'min', 'max');
    echo str_repeat('-', 78) . "\n";

    foreach ($results as $name => $metrics) {
        echo benchmarkLine($name, $metrics);
    }

    echo "\nAll values are microseconds per operation. Compare medians on the same machine.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    benchmarkUsage();
    exit(1);
}