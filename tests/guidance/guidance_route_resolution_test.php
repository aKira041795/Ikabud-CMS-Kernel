<?php
/**
 * Guidance Route Resolution Test
 *
 * Proves every route handler reference resolves from modules/guidance/handlers.php.
 * Verifies that the deleted split handler directory (modules/guidance/handlers/)
 * is neither restored nor imported as a second runtime source.
 *
 * Uses token_get_all() for function extraction and import-statement inspection,
 * avoiding regex-only checks that can miss concatenated PHP paths.
 *
 * Pure logic — no bootstrap, no DB required. handlers.php cannot be require'd
 * directly without kernel bootstrap, so we validate by token analysis + php -l.
 *
 * Usage: php tests/guidance/guidance_route_resolution_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('guidance-route-resolution');

$h->fingerprint('modules/guidance/routes.php');
$h->fingerprint('modules/guidance/handlers.php');

// ---- Step 1: Validate file syntax ----
$h->section('File syntax');
$output = [];
exec('php -l ' . escapeshellarg(__DIR__ . '/../../modules/guidance/handlers.php') . ' 2>&1', $output, $exitCode);
$h->test('handlers.php has valid PHP syntax', $exitCode === 0);

// ---- Step 2: Load routes ----
$routes = require __DIR__ . '/../../modules/guidance/routes.php';
if (!is_array($routes)) {
    $h->section('FATAL: routes file did not return an array');
    $h->test('routes.php returns array', false);
    $h->done();
    exit(1);
}

$handlerRefs = [];
$totalRoutes = 0;
foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    if (!isset($routes[$method]) || !is_array($routes[$method])) {
        continue;
    }
    foreach ($routes[$method] as $path => $handler) {
        $totalRoutes++;
        if (str_starts_with($handler, 'guidance:')) {
            $fnName = substr($handler, strlen('guidance:'));
            $handlerRefs[$fnName] = ['method' => $method, 'path' => $path, 'handler' => $handler];
        }
    }
}

$h->section("Route inventory: {$totalRoutes} total routes, " . count($handlerRefs) . ' unique handler references');

// ---- Step 3: Extract function names via token_get_all() ----
$handlersFile = __DIR__ . '/../../modules/guidance/handlers.php';
$handlersContent = file_get_contents($handlersFile);
$handlersHash = md5_file($handlersFile);

$definedFunctions = [];
$tokens = @token_get_all($handlersContent);
$tokenCount = count($tokens);
for ($i = 0; $i < $tokenCount; $i++) {
    if ($tokens[$i][0] === T_FUNCTION) {
        $j = $i + 1;
        while ($j < $tokenCount && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j < $tokenCount && $tokens[$j][0] === T_STRING) {
            $definedFunctions[] = $tokens[$j][1];
        }
    }
}

$h->section("handlers.php defines " . count($definedFunctions) . ' functions (via token_get_all)');

// ---- Step 4: Verify every route handler is defined in handlers.php ----
$h->section('Route handler resolution — every guidance:* callable exists in handlers.php');

$missing = [];
$resolved = [];
foreach ($handlerRefs as $fnName => $info) {
    $existsInHandlers = in_array($fnName, $definedFunctions, true);
    if ($existsInHandlers) {
        $resolved[] = $fnName;
    } else {
        $missing[] = $fnName;
    }
    $h->test(
        "{$info['handler']} -> {$fnName}() " . ($existsInHandlers ? 'FOUND in handlers.php' : 'MISSING from handlers.php'),
        $existsInHandlers
    );
}

$h->section('Resolution summary');
$h->test(
    count($resolved) . '/' . count($handlerRefs) . " unique handler references resolve from handlers.php",
    count($resolved) === count($handlerRefs)
);
if (!empty($missing)) {
    $h->gap('Unresolved handlers: ' . implode(', ', $missing));
}

// ---- Step 5: Repository-wide check — no file imports from split handler dir ----
$h->section('Repository-wide split-handler import prohibition');

$importHits = [];
$repoRoot = realpath(__DIR__ . '/../..');
$excludedSegments = ['/.git/', '/vendor/', '/node_modules/', '/storage/cache/', '/test_results/'];
$splitImportStatements = static function (string $content, string $sourceRelativePath): array {
    $hits = [];
    $tokens = token_get_all($content);
    $importTokens = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];
    $tokenCount = count($tokens);
    $isGuidanceSource = str_starts_with(str_replace('\\', '/', $sourceRelativePath), 'modules/guidance/');

    for ($i = 0; $i < $tokenCount; $i++) {
        if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $importTokens, true)) {
            continue;
        }
        $statement = $tokens[$i][1];
        $stringLiterals = [];
        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $stringLiterals[] = str_replace('\\', '/', substr($tokens[$j][1], 1, -1));
            }
            if ($tokens[$j] === ';') {
                break;
            }
        }

        $normalized = str_replace('\\', '/', $statement);
        $referencesSplit = str_contains($normalized, 'guidance/handlers/');
        if (!$referencesSplit && $isGuidanceSource) {
            foreach ($stringLiterals as $literal) {
                $trimmedLiteral = trim($literal, '/.');
                if ($trimmedLiteral === 'handlers' || str_starts_with($trimmedLiteral, 'handlers/')) {
                    $referencesSplit = true;
                    break;
                }
            }
        }
        if ($referencesSplit) {
            $hits[] = trim($statement);
        }
    }

    return $hits;
};
$guardFixture = "<?php require_once __DIR__ . '/handlers/10-auth.php';";
$h->test(
    'Split-handler import guard detects guidance-relative imports',
    $splitImportStatements($guardFixture, 'modules/guidance/handlers.php') !== []
);
if ($repoRoot !== false) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot));
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') { continue; }
        $path = str_replace('\\', '/', $fileInfo->getPathname());
        if (array_filter($excludedSegments, static fn(string $segment): bool => str_contains($path, $segment))) { continue; }
        $rel = ltrim(str_replace(str_replace('\\', '/', $repoRoot), '', $path), '/');
        $content = (string)file_get_contents($fileInfo->getPathname());
        if ($splitImportStatements($content, $rel) !== []) {
            $importHits[] = $rel;
        }
    }
}

if (empty($importHits)) {
    $h->test('No file in modules/guidance/, tests/, or src/ imports from the split handler directory', true);
} else {
    $h->test(
        count($importHits) . ' file(s) import from guidance/handlers/: ' . implode(', ', $importHits),
        false
    );
    $h->gap('Remove imports from guidance/handlers/ subdirectory');
}

// ---- Step 6: Verify handlers.php does NOT import from split dir ----
$h->test(
    'handlers.php does not import from handlers/ subdirectory',
    $splitImportStatements($handlersContent, 'modules/guidance/handlers.php') === []
);

// ---- Step 7: Guard against restoring the dead split ----
$splitDir = __DIR__ . '/../../modules/guidance/handlers';
$h->test(
    'Deleted split handler directory is not present',
    !is_dir($splitDir)
);

// ---- Step 8: Document runtime source ----
$h->section('Runtime source documentation');
$h->test(
    'Runtime handler source: modules/guidance/handlers.php (' . count($definedFunctions) . ' functions, hash: ' . substr($handlersHash, 0, 12) . ')',
    true
);

$h->done();
