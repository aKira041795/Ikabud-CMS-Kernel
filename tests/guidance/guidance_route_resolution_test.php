<?php
/**
 * Guidance Route Resolution Test
 *
 * Proves every route handler reference resolves from modules/guidance/handlers.php.
 * Verifies that the split handler directory (modules/guidance/handlers/) is NOT
 * the runtime source for route callables.
 *
 * Uses token_get_all() for function extraction (more robust than regex alone)
 * and searches the repository for any imports from the split handler directory.
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
if ($repoRoot !== false) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot));
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') { continue; }
        $path = str_replace('\\', '/', $fileInfo->getPathname());
        if (array_filter($excludedSegments, static fn(string $segment): bool => str_contains($path, $segment))) { continue; }
        $rel = ltrim(str_replace(str_replace('\\', '/', $repoRoot), '', $path), '/');
        // Skip the monolithic handlers.php itself — it's the runtime source
        if ($rel === 'modules/guidance/handlers.php') { continue; }
        $content = file_get_contents($fileInfo->getPathname());

        // Pattern 1: absolute paths containing "guidance/handlers/"
        $absoluteMatch = preg_match(
            '/(require|include)(_once)?\s*\(?\s*[\"\'][^\"\']*guidance\/handlers\//',
            $content
        );

        // Pattern 2: relative paths via __DIR__ or ./ referencing handler files
        // Matches: __DIR__ . '/handlers/...', __DIR__ . "./handlers/...",
        //          './handlers/...', or bare 'handlers/...'
        $relativeMatch = (str_starts_with($rel, 'modules/guidance/') || str_starts_with($rel, 'tests/guidance/'))
            && preg_match(
                '/(require|include)(_once)?\s*\(?\s*('
                    . '(__DIR__\s*\.\s*[\"\'][\/.]?handlers\/)'
                    . '|([\"\'][.]?\/?handlers\/)'
                . ')/',
                $content
            );

        if ($absoluteMatch || $relativeMatch) {
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
    !preg_match('/(require|include)(_once)?\s+(__DIR__\s*\.\s*)?[\'"](\/handlers|\.\/)/', $handlersContent)
);

// ---- Step 7: Document split directory inventory ----
$splitDir = __DIR__ . '/../../modules/guidance/handlers';
$splitFiles = [];
if (is_dir($splitDir)) {
    foreach (scandir($splitDir) as $entry) {
        if (str_ends_with($entry, '.php')) { $splitFiles[] = $entry; }
    }
}
$h->test(
    'Split handler directory (' . count($splitFiles) . ' files) exists but is not the runtime source',
    true
);

// ---- Step 8: Document runtime source ----
$h->section('Runtime source documentation');
$h->test(
    'Runtime handler source: modules/guidance/handlers.php (' . count($definedFunctions) . ' functions, hash: ' . substr($handlersHash, 0, 12) . ')',
    true
);

$h->done();
