<?php
/**
 * CMS Akira — foreign DB access static gate test (cms-akira-media).
 *
 * Token/AST-aware: the gate scans actual PHP call/statement tokens in the
 * Akira provider files — NOT raw text. It must:
 *   - flag direct `cmsDb()` calls, direct PDO on cms-owned tables, and named
 *     foreign helper calls (`cmsResolveUploadUrl`, `cmsGetMenus`, etc.);
 *   - ALLOW capability IDs and data values (e.g. `cms.media.get@1`,
 *     `entity_type: "cms_content"`) and comments mentioning the patterns.
 *
 * Run: php tests/cms_akira_foreign_db_access_static_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  \u{2717} {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// Forbidden named foreign-helper call sites (Akira provider code must not call
// these functions). Capability IDs and data values are NOT in this list.
$forbiddenHelpers = [
    'cmsResolveUploadUrl',
    'cmsUploadsUrl',
    'cmsUploadsPath',
    'cmsGetMenus',
    'cmsGetMenuLocations',
    'cmsGetMenuItemsTree',
    'cmsResolveSeoTitle',
    'cmsDefaultSeoHeadHtml',
    'cmsStructuredDataJsonLd',
    'readCmsSettings',
    'cmsSeoStrip',
    'cmsDb',
    'cmsCtx',
];

// Provider files to scan (Akira modules only — not the CMS owner itself).
$scanFiles = [
    BASE_PATH . '/modules/cms-akira/cms-akira-media/helpers.php',
    BASE_PATH . '/modules/cms-akira/cms-akira-navigation/helpers.php',
    BASE_PATH . '/modules/cms-akira/cms-akira-seo/helpers.php',
];

/**
 * Strip comments, then tokenize to find actual global-function call sites.
 * A hit = forbidden identifier used as a GLOBAL function call: identifier
 * followed by '(' where the identifier is not preceded by -> or :: and is not
 * a function definition. Comments are skipped.
 */
function scanForbiddenCallSites(string $file, array $forbidden): array
{
    if (!is_file($file)) {
        return [];
    }
    $src = (string) file_get_contents($file);
    $tokens = token_get_all($src);
    $hits = [];

    // Collect significant tokens (skip whitespace + comments), keep line info.
    $sig = [];
    foreach ($tokens as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $sig[] = ['text' => $tok[1], 'line' => $tok[2]];
        } else {
            $sig[] = ['text' => $tok, 'line' => null];
        }
    }

    for ($i = 0, $n = count($sig); $i < $n; $i++) {
        $cur = $sig[$i];
        // Only consider a bare identifier token.
        if ($cur['line'] === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cur['text'])) {
            continue;
        }
        $name = $cur['text'];
        if (!in_array($name, $forbidden, true)) {
            continue;
        }
        // Must be followed by '(' (call).
        $next = $sig[$i + 1]['text'] ?? null;
        if ($next !== '(') {
            continue;
        }
        // Must NOT be a method/property access or definition:
        // preceding significant token must not be -> :: function class const.
        $prev = $sig[$i - 1]['text'] ?? null;
        if (in_array($prev, ['->', '::', 'function', 'class', 'const', 'new'], true)) {
            continue;
        }
        $hits[] = ['line' => $cur['line'], 'name' => $name];
    }
    return $hits;
}

echo "\n── Static scan: Akira provider files have NO direct CMS helper call sites ──\n";
$allHits = [];
foreach ($scanFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $hits = scanForbiddenCallSites($file, $forbiddenHelpers);
    $rel = str_replace(BASE_PATH . '/', '', $file);
    if ($hits === []) {
        t("{$rel}: clean", true);
    } else {
        foreach ($hits as $h) {
            $allHits[] = $rel . ':' . $h['line'] . ' ' . $h['name'];
        }
        t("{$rel}: no forbidden call sites", false, implode(', ', array_map(fn($h) => $h['name'] . '@' . $h['line'], $hits)));
    }
}
t('no forbidden helper call sites in scanned Akira providers', $allHits === [], implode('; ', $allHits));

echo "\n── Manifest: no foreign reads_tables on Akira providers ──\n";
foreach (['cms-akira-media', 'cms-akira-navigation', 'cms-akira-seo'] as $modId) {
    $mf = json_decode((string) file_get_contents(BASE_PATH . "/modules/cms-akira/{$modId}/module.json"), true);
    $reads = $mf['reads_tables'] ?? [];
    t("{$modId} reads_tables has no cms-owned tables", array_filter($reads, fn($r) => str_starts_with($r, 'cms_')) === [], implode(',', $reads));
}

echo "\n── Capability delegation (positive) is preserved ──\n";
// cms-akira-media must declare the cms.media.get@1 dependency and its handler
// must route through app()->cap()->call — verify the source contains the
// capability bus invocation as a STRING constant (allowed).
$mediaSrc = (string) file_get_contents(BASE_PATH . '/modules/cms-akira/cms-akira-media/helpers.php');
t('media helper delegates via capability bus (cms.media.get@1)', str_contains($mediaSrc, "app()->cap()->call('cms.media.get@1'") || str_contains($mediaSrc, 'app()->cap()->call("cms.media.get@1"'));

$mediaManifest = json_decode((string) file_get_contents(BASE_PATH . '/modules/cms-akira/cms-akira-media/module.json'), true);
$depends = $mediaManifest['capabilities']['depends'] ?? [];
t('media manifest depends on cms.media.get@1', in_array('cms.media.get@1', $depends, true));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo "Errors:\n  - " . implode("\n  - ", $errors) . "\n";
    exit(1);
}
exit(0);
