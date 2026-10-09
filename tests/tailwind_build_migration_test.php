<?php

declare(strict_types=1);

/**
 * Pins the invariants of the Tailwind runtime-JIT -> compiled-stylesheet migration.
 *
 * The migration replaced `https://cdn.tailwindcss.com` (a runtime compiler that ships ~120 KB of
 * JS and needs 'unsafe-eval') with a prebuilt stylesheet. Nothing enforced that afterwards, so the
 * invariant had already silently broken twice: two pages that emit their HTML from raw PHP strings
 * (src/http/page-handlers.php, modules/ecommerce/handlers/00-bootstrap.php) were never covered by
 * the template sweep, and the build's content globs did not include PHP at all -- so the utilities
 * those pages use were not guaranteed to be in the bundle.
 *
 * The checks below fail if any of that returns.
 */

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('tailwind-build-migration');

$h->fingerprint('tailwind.app.config.js');
$h->fingerprint('public/assets/tailwind/app.css');
$h->fingerprint('src/http/page-handlers.php');
$h->fingerprint('modules/ecommerce/handlers/00-bootstrap.php');

$base = $h->basePath();
$cssPath = $base . '/public/assets/tailwind/app.css';
$configPath = $base . '/tailwind.app.config.js';

/**
 * Files that may legitimately name the runtime CDN even though no page may load it:
 * the CSP allowlist (a policy grant) and the daily-ledger PWA's deliberately self-hosted copy.
 */
$cdnAllowed = [
    'kernel/Http/SecurityHeaders.php',
];

/**
 * Find source files that would cause a browser to FETCH the runtime compiler.
 *
 * @param array<string,string> $sources path => contents
 * @return array<int,string> offending paths
 */
function twRuntimeCdnOffenders(array $sources): array
{
    $offenders = [];
    foreach ($sources as $path => $body) {
        // A fetch, not a mention: the string must appear inside a src/href, so a comment or a
        // test description that merely names the CDN is not an offence.
        if (preg_match('/(?:src|href)\s*=\s*["\']https:\/\/cdn\.tailwindcss\.com/i', $body) === 1) {
            $offenders[] = $path;
        }
    }
    return $offenders;
}

function twCollectSources(string $base, array $roots, array $skipPrefixes): array
{
    $sources = [];
    foreach ($roots as $root) {
        $dir = $base . '/' . $root;
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (!in_array($file->getExtension(), ['php', 'disyl'], true)) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($base) + 1);
            foreach ($skipPrefixes as $skip) {
                if (str_starts_with($rel, $skip)) {
                    continue 2;
                }
            }
            $sources[$rel] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($sources);
    return $sources;
}

$h->section('The detector can refuse (must-fail case)');

// If this ever passes trivially, every "no offenders" assertion below is worthless.
$h->test(
    'detector flags a synthetic page that loads the runtime CDN',
    twRuntimeCdnOffenders([
        'synthetic.php' => '<script src="https://cdn.tailwindcss.com"></script>',
    ]) === ['synthetic.php']
);
$h->test(
    'detector ignores a plain mention that is not a fetch',
    twRuntimeCdnOffenders([
        'comment.php' => '// we used to load https://cdn.tailwindcss.com but no longer do',
    ]) === []
);

$h->section('No page source loads the runtime compiler');

$sources = twCollectSources(
    $base,
    ['templates', 'modules', 'src', 'kernel', 'storage'],
    array_merge($cdnAllowed, ['storage/cache/', 'storage/logs/', 'storage/backups/'])
);
$h->test('source sweep found a non-trivial number of files', count($sources) > 200);
$offenders = twRuntimeCdnOffenders($sources);
$h->test(
    'no template or PHP source fetches the runtime CDN'
        . ($offenders ? ' -- offenders: ' . implode(', ', array_slice($offenders, 0, 5)) : ''),
    $offenders === []
);

$h->section('The two PHP-emitted pages use the compiled bundle');

$perfPage = (string) file_get_contents($base . '/src/http/page-handlers.php');
$h->test(
    'superadmin perf page links the compiled stylesheet',
    str_contains($perfPage, '/assets/tailwind/app.css')
);
$errorPage = (string) file_get_contents($base . '/modules/ecommerce/handlers/00-bootstrap.php');
$h->test(
    'ecommerce module 403 page links the compiled stylesheet',
    str_contains($errorPage, '/assets/tailwind/app.css')
);

$h->section('The build covers the surfaces that emit markup');

$config = (string) file_get_contents($configPath);
// PHP emits Tailwind markup from raw strings. Without these globs a utility used only in
// PHP-emitted HTML is absent from the bundle and the page renders unstyled.
$h->test('config globs include DiSyL templates', str_contains($config, "./templates/**/*.disyl"));
$h->test('config globs include PHP under src/', str_contains($config, "./src/**/*.php"));
$h->test('config globs include PHP under modules/', str_contains($config, "./modules/**/*.php"));
$h->test('config globs include PHP under kernel/', str_contains($config, "./kernel/**/*.php"));

$h->section('The compiled bundle is real and complete');

$h->test('bundle exists', is_file($cssPath));
$css = (string) file_get_contents($cssPath);
$h->test('bundle is substantial (> 50 KB)', strlen($css) > 50000);
$h->test('bundle contains no @tailwind directive', !str_contains($css, '@tailwind'));
$h->test('bundle is not the runtime JIT script', !str_contains($css, 'cdn.tailwindcss.com'));

/**
 * Is a utility defined? Tailwind groups utilities sharing a declaration into one rule
 * ('.border-b,.border-y{'), so a naive substring test for '.border-b{' returns a false negative.
 * That exact mistake produced a phantom "border-b is missing" during verification.
 */
function twUtilityDefined(string $css, string $utility): bool
{
    $escaped = (string) preg_replace('/([^a-zA-Z0-9_-])/', '\\\\$1', $utility);
    return preg_match('/(^|[,}\s])\.' . preg_quote($escaped, '/') . '(?=[,{:\s])/', $css) === 1;
}

// Utilities whose only source is PHP-emitted markup. They were absent from the bundle before the
// content globs covered PHP, so these assertions pin the fix rather than the general case.
foreach (['hover:underline', 'bg-orange-700', 'rounded-r-lg', 'font-light', 'lowercase', 'isolate'] as $utility) {
    $h->test("bundle defines {$utility} (PHP-emitted markup)", twUtilityDefined($css, $utility));
}

// Known-present control: if the helper cannot see these, its "defined" results mean nothing.
foreach (['border-b', 'flex', 'text-gray-500'] as $utility) {
    $h->test("utility lookup control finds {$utility}", twUtilityDefined($css, $utility));
}

$h->done();
