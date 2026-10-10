<?php
declare(strict_types=1);

/**
 * Probe: is IncludeResolver::processIncludes() O(N x content) in the number of includes?
 *
 * Read of the code (kernel/DiSyL/Component/IncludeResolver.php:58-76):
 *   processIncludes() loops MAX_INCLUDE_ITERATIONS times calling processNextInclude(),
 *   which finds ONE {include } and returns the whole content with that one replaced.
 *   Each pass therefore re-scans the content from position 0. If that is right, N sibling
 *   includes need N passes, each a full scan -> quadratic in N for a fixed page size.
 *
 * That matters because the mechanism is already known to be expensive: the 2026-09-26 note
 * measured two include-carried style partials at 113.47 + 53.43 ms PER REQUEST on the
 * interpreted pipeline, and {include} has no compiled cache.
 *
 * The read could be wrong in a way that matters: if processNextInclude() replaced ALL
 * includes it could find in a pass, this would be linear. So measure instead of assuming.
 *
 * Timing only. No repo files are read or written.
 */

require __DIR__ . '/../../../kernel/DiSyL/Component/IncludeResolver.php';

use Ikabud\Kernel\DiSyL\Component\IncludeResolver;

/** Build content of a roughly fixed size containing $n include tags. */
function contentWithIncludes(int $n, int $padChars): string
{
    $chunk = str_repeat('x', max(1, $padChars));
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $out .= "<div>{$chunk}";
        $out .= '{include "partial' . $i . '"}{}';
        $out .= "{$chunk}</div>\n";
    }
    return $out;
}

function buildResolver(): IncludeResolver
{
    return new IncludeResolver(
        // $compile: what the engine would do to the included source. Kept trivial so the
        // measurement is the resolver's own scanning, not compilation.
        static fn(string $source, array $ctx): string => $source,
        // $resolveTemplatePath
        static fn(string $name): string => '/tmp/' . $name . '.disyl',
        // $parseInlineObject
        static fn(string $s, array $ctx): array => [],
        // $resolveValueWithFilters
        static fn(string $s, array $ctx) => $s,
        // $logError
        static function (string $m): void {
        },
        // $readIncludeSource: SourceCache read. Trivial, so what is timed is the scan.
        static fn(string $path): string|false => 'partial body'
    );
}

$pad = 400;           // tuned so total content is page-sized
$counts = [1, 2, 4, 8, 16, 20];

echo "IncludeResolver::processIncludes() — fixed page size, varying include count\n";
echo str_pad('includes', 10) . str_pad('content B', 12) . str_pad('median ms', 12) . "ms per include\n";
echo str_repeat('-', 56) . "\n";

$prevPer = null;
foreach ($counts as $n) {
    $content = contentWithIncludes($n, $pad);
    $samples = [];
    for ($s = 0; $s < 5; $s++) {
        $r = buildResolver();
        $t = hrtime(true);
        $r->processIncludes($content, []);
        $samples[] = (hrtime(true) - $t) / 1e6;
    }
    sort($samples);
    $median = $samples[2];
    $per = $median / $n;
    $trend = $prevPer === null ? '' : ($per > $prevPer * 1.25 ? '  <- per-include rising' : '  <- per-include ~flat');
    printf("%-10d%-12d%-12.3f%.4f%s\n", $n, strlen($content), $median, $per, $trend);
    $prevPer = $per;
}

echo "\nIf per-include ms is FLAT, the resolver is linear and my read of the loop is wrong.\n";
echo "If it RISES with N, each pass re-scans the whole content and the cost is quadratic.\n";
