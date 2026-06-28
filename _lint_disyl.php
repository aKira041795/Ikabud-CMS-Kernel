<?php
/**
 * DiSyL Template Linter — CLI syntax and structure validator.
 *
 * Scans all .disyl template files in the project, parses each through
 * the v4 Parser to detect syntax errors, validates include paths, and
 * reports results in a CI-friendly format.
 *
 * Usage:
 *   php _lint_disyl.php                  # lint all templates
 *   php _lint_disyl.php --path templates/modules/cms  # lint a directory
 *   php _lint_disyl.php --ci             # CI mode (only output errors)
 *   php _lint_disyl.php --fix            # remove trailing whitespace (basic fix)
 *
 * Exit code: 0 = all valid, 1 = errors found
 *
 * @package Ikabud
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────
require_once __DIR__ . '/bootstrap.php';

use Ikabud\Kernel\DiSyL\v4\Parser;

// ── Configuration ────────────────────────────────────────────────────────
$projectRoot = __DIR__;
$searchPaths = [];
$ciMode = false;
$fixMode = false;

// Parse CLI args
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--path' && $i + 1 < $argc) {
        $searchPaths[] = rtrim($argv[++$i], '/');
    } elseif ($arg === '--ci') {
        $ciMode = true;
    } elseif ($arg === '--fix') {
        $fixMode = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "DiSyL Template Linter v1.0\n";
        echo "Usage: php _lint_disyl.php [options]\n";
        echo "  --path <dir>    Lint a specific directory (default: all)\n";
        echo "  --ci            CI mode — only output errors\n";
        echo "  --fix           Auto-fix trailing whitespace\n";
        echo "  --help, -h      Show this help\n";
        exit(0);
    }
}

if (empty($searchPaths)) {
    $searchPaths = [
        'templates',
        'storage/cms-themes',
    ];
}

// ── File discovery ───────────────────────────────────────────────────────
$files = [];
foreach ($searchPaths as $path) {
    $fullPath = $projectRoot . '/' . $path;
    if (!is_dir($fullPath)) {
        if (!$ciMode) {
            echo "[INFO] Path not found: {$path}\n";
        }
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'disyl') {
            $files[] = $file->getRealPath();
        }
    }
}

sort($files);
$totalFiles = count($files);

if (!$ciMode) {
    echo "DiSyL Template Linter v1.0\n";
    echo "─────────────────────────────────\n";
    echo "Found {$totalFiles} template(s) to validate\n\n";
}

// ── Validation ───────────────────────────────────────────────────────────
$errors = [];
$warnings = [];
$parser = new Parser();
$fixCount = 0;

foreach ($files as $filePath) {
    $relativePath = str_replace($projectRoot . '/', '', $filePath);
    $source = file_get_contents($filePath);
    if ($source === false || $source === '') {
        $errors[] = [$relativePath, 0, 'Unable to read file or file is empty'];
        continue;
    }

    // Strip DiSyL comments ({# ... #}) before structural checks so that
    // example include/extends paths in documentation comments don't
    // produce false-positive "path not found" errors.
    $sourceStripped = preg_replace('/\{#.*?#\}/s', '', $source);

    // ── Basic structural checks ──
    $lines = explode("\n", $source);
    $fileErrors = [];

    // Check 1: Balanced {block} / {/block}
    $blockOpen = preg_match_all('/\{block\s/', $sourceStripped);
    $blockClose = preg_match_all('/\{\/block\}/', $sourceStripped);
    if ($blockOpen !== $blockClose) {
        $fileErrors[] = "Mismatched {block}/{\/block}: {$blockOpen} opening(s), {$blockClose} closing(s)";
    }

    // Check 2: Balanced {if} / {/if}
    $ifOpen = preg_match_all('/\{if\s/', $sourceStripped);
    $ifClose = preg_match_all('/\{\/if\}/', $sourceStripped);
    if ($ifOpen !== $ifClose) {
        $fileErrors[] = "Mismatched {if}/{\/if}: {$ifOpen} opening(s), {$ifClose} closing(s)";
    }

    // Check 3: Balanced {for} / {/for}
    $forOpen = preg_match_all('/\{for\s/', $sourceStripped);
    $forClose = preg_match_all('/\{\/for\}/', $sourceStripped);
    if ($forOpen !== $forClose) {
        $fileErrors[] = "Mismatched {for}/{\/for}: {$forOpen} opening(s), {$forClose} closing(s)";
    }

    // Check 4: Balanced {foreach} / {/foreach}
    $feOpen = preg_match_all('/\{foreach\s/', $sourceStripped);
    $feClose = preg_match_all('/\{\/foreach\}/', $sourceStripped);
    if ($feOpen !== $feClose) {
        $fileErrors[] = "Mismatched {foreach}/{\/foreach}: {$feOpen} opening(s), {$feClose} closing(s)";
    }

    // Check 5: Balanced {while} / {/while}
    $whileOpen = preg_match_all('/\{while\s/', $sourceStripped);
    $whileClose = preg_match_all('/\{\/while\}/', $sourceStripped);
    if ($whileOpen !== $whileClose) {
        $fileErrors[] = "Mismatched {while}/{\/while}: {$whileOpen} opening(s), {$whileClose} closing(s)";
    }

    // Check 6: {include} paths resolve to existing files
    if (preg_match_all('/\{include\s+"([^"]+)"/', $sourceStripped, $includeMatches)) {
        foreach ($includeMatches[1] as $includePath) {
            // Skip dynamic includes (resolved at runtime by CMS theme system)
            if (str_starts_with($includePath, '_cms_')) {
                continue;
            }
            $resolved = resolveTemplatePathForLint($includePath, $projectRoot, dirname($filePath));
            if ($resolved === null) {
                $fileErrors[] = "Include path not found: '{$includePath}'";
            }
        }
    }

    // Check 7: {extends} paths resolve
    if (preg_match('/\{extends\s+"([^"]+)"/', $sourceStripped, $extMatches)) {
        // Skip dynamic extends (resolved at runtime by CMS theme system)
        if (!str_starts_with($extMatches[1], '_cms_')) {
            $resolved = resolveTemplatePathForLint($extMatches[1], $projectRoot, dirname($filePath));
            if ($resolved === null) {
                $fileErrors[] = "Extends path not found: '{$extMatches[1]}'";
            }
        }
    }

    // Check 8: v4 Parser — catch syntax errors
    try {
        $ast = $parser->parse($source, $relativePath);
    } catch (\Throwable $e) {
        // Extract line number from error message if available
        $line = 0;
        if (preg_match('/line (\d+)/', $e->getMessage(), $lm)) {
            $line = (int)$lm[1];
        }
        $fileErrors[] = "Parse error near line {$line}: {$e->getMessage()}";
    }

    // Check 9: Trailing whitespace (fixable)
    if ($fixMode) {
        $fixed = 0;
        $newLines = [];
        foreach ($lines as $ln => $line) {
            $trimmed = rtrim($line);
            if ($trimmed !== $line) {
                $fixed++;
                $fixCount++;
            }
            $newLines[] = $trimmed;
        }
        if ($fixed > 0 && !$ciMode) {
            $warnings[] = [$relativePath, 0, "Fixed {$fixed} line(s) with trailing whitespace"];
        }
        if ($fixed > 0) {
            file_put_contents($filePath, implode("\n", $newLines) . "\n");
        }
    }

    // Report errors for this file
    foreach ($fileErrors as $err) {
        $errors[] = [$relativePath, 0, $err];
    }
}

// ── Results ──────────────────────────────────────────────────────────────
$errorCount = count($errors);
$warnCount = count($warnings);

if (!$ciMode) {
    echo "\n";
}

if ($errorCount > 0) {
    echo "\n─── ERRORS ──────────────────────────────────\n";
    foreach ($errors as [$file, $line, $msg]) {
        $loc = $line > 0 ? ":{$line}" : '';
        echo "  ✗ {$file}{$loc} — {$msg}\n";
    }
    echo "\n";
}

if (!$ciMode && $warnCount > 0) {
    echo "─── WARNINGS ────────────────────────────────\n";
    foreach ($warnings as [$file, $line, $msg]) {
        echo "  ⚠ {$file} — {$msg}\n";
    }
    echo "\n";
}

$passCount = $totalFiles - count(array_unique(array_map(fn($e) => $e[0], $errors)));

if (!$ciMode) {
    echo "─────────────────────────────────\n";
    echo "  ✓ {$passCount} file(s) valid\n";
    if ($fixMode) {
        echo "  🔧 {$fixCount} trailing whitespace fix(es)\n";
    }
    if ($errorCount > 0) {
        echo "  ✗ {$errorCount} error(s)\n";
    }
    echo "\n";
}

exit($errorCount > 0 ? 1 : 0);

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * Resolve a DiSyL template include/extends path to an actual file.
 *
 * @param string $path        The include/extends path from the template
 * @param string $projectRoot Project root directory
 * @param string $fromDir     Directory of the file containing the include (for relative resolution)
 */
function resolveTemplatePathForLint(string $path, string $projectRoot, string $fromDir = ''): ?string
{
    $projectRoot = __DIR__;

    // 0. Resolve relative to the file being linted (e.g., blocks/pricing/... relative to theme/public/)
    if ($fromDir !== '') {
        $relative = $fromDir . '/' . ltrim($path, '/');
        if (is_file($relative)) {
            return $relative;
        }
        if (is_file($relative . '.disyl')) {
            return $relative . '.disyl';
        }
    }

    // 1. Direct path
    $direct = $projectRoot . '/' . ltrim($path, '/');
    if (is_file($direct)) {
        return $direct;
    }

    // 2. Try with .disyl extension
    $withExt = $direct . '.disyl';
    if (is_file($withExt)) {
        return $withExt;
    }

    // 3. Relative to templates/
    $inTemplates = $projectRoot . '/templates/' . ltrim($path, '/');
    if (is_file($inTemplates)) {
        return $inTemplates;
    }
    if (is_file($inTemplates . '.disyl')) {
        return $inTemplates . '.disyl';
    }

    // 4. Module template alias resolution
    if (preg_match('#^modules/([^/]+)/(.*)$#', $path, $m)) {
        $moduleTemplate = $projectRoot . '/templates/' . $path;
        if (is_file($moduleTemplate)) {
            return $moduleTemplate;
        }
        if (is_file($moduleTemplate . '.disyl')) {
            return $moduleTemplate . '.disyl';
        }
    }

    return null;
}
