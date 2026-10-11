<?php
/**
 * Compiled-template cache: distinct resolved sources must not share an artifact.
 *
 * THE UNPROVEN QUESTION THIS SETTLES (raised by Sol and the chair, 2026-10-11)
 * -------------------------------------------------------------------------
 * `storage/cache/compiled/` is GLOBAL - not tenant-partitioned, unlike `pagecache_t1`,
 * `cms_settings_t1` and the rest. Sol could not certify from the source that this is safe, and the
 * chair agreed: "Not proven. They can be safe by construction, but only if compiled artifacts are
 * tenant-independent programs."
 *
 * It IS safe, and this test proves the load-bearing half of the argument:
 *
 *   TemplateCache::getClassName() derives the artifact identity from
 *     md5($templatePath . ':' . sha1_file($templatePath) . ':v' . COMPILER_VERSION)
 *   so the RESOLVED SOURCE PATH is part of the key. Two tenants whose themes resolve to different
 *   files therefore cannot collide, even when the two files have identical content. And because the
 *   compiled artifact is a program whose tenant data arrives at execution time through RenderContext,
 *   a shared artifact for a SHARED source is correct by construction.
 *
 * The other half of the argument - that resolution returns the real themed file and never the shared
 * `_cms_active_theme` symlink - is proven by tests/theme_alias_resolution_test.php (25 cases). This
 * test covers what that one cannot: that the identity is keyed on the resolved path.
 *
 * The assertions are OUTCOME level: what each template RENDERS, and that both artifacts survive
 * side by side. Not "a particular hash was computed".
 *
 * Run: php tests/compiled_template_tenant_isolation_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;

$passed = 0;
$failed = 0;
function ctiAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
    } else {
        echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

$tmp = sys_get_temp_dir() . '/cti_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
$themeA = $tmp . '/theme_a';
$themeB = $tmp . '/theme_b';
mkdir($themeA, 0777, true);
mkdir($themeB, 0777, true);

// Same basename in both themes - exactly what two tenants with two themes look like. Different
// content, so a collision is directly observable in the rendered output.
$srcA = $themeA . '/page.disyl';
$srcB = $themeB . '/page.disyl';
file_put_contents($srcA, 'MARKER_A');
file_put_contents($srcB, 'MARKER_B');

$rmrf = function (string $dir) use (&$rmrf): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? $rmrf($f) : @unlink($f);
    }
    @rmdir($dir);
};

/** @return array<int,string> every compiled artifact under a dir */
$compiledFiles = function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $out = [];
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
};

// ── A) The identity is keyed on the resolved source path ────────────────────────
$cache = new TemplateCache($tmp . '/compiled', true);
$getClassName = new ReflectionMethod($cache, 'getClassName');

$nameA = $getClassName->invoke($cache, $srcA);
$nameB = $getClassName->invoke($cache, $srcB);

ctiAssert(
    '★ two themes resolving to different files get DIFFERENT artifact identities',
    $nameA !== $nameB,
    "both mapped to {$nameA}"
);
ctiAssert(
    'the same source path is stable (the cache actually reuses)',
    $getClassName->invoke($cache, $srcA) === $nameA
);

// The decisive one: identical CONTENT must still not collide, because the path is in the key. If a
// future change keyed the artifact on content alone, two tenants that happen to produce identical
// templates would silently share one artifact.
$twinA = $tmp . '/twin_a/page.disyl';
$twinB = $tmp . '/twin_b/page.disyl';
mkdir(dirname($twinA), 0777, true);
mkdir(dirname($twinB), 0777, true);
file_put_contents($twinA, 'IDENTICAL_BODY');
file_put_contents($twinB, 'IDENTICAL_BODY');
ctiAssert(
    '★ identical content at different paths still gets different identities',
    $getClassName->invoke($cache, $twinA) !== $getClassName->invoke($cache, $twinB),
    'the artifact key is not path-sensitive: two tenants could share one artifact'
);

// And stale source must not keep serving its old artifact.
file_put_contents($srcA, 'MARKER_A_EDITED');
ctiAssert(
    'editing the source changes the identity (no stale artifact)',
    $getClassName->invoke($cache, $srcA) !== $nameA
);
file_put_contents($srcA, 'MARKER_A');

// ── B) Behavioural isolation in ONE shared compiled dir ─────────────────────────
// Order A -> B -> A, with the shared directory left intact throughout, is the sequence that would
// expose an overwrite: if B's compile replaced A's artifact, the third step would render B.
$outA1 = trim((string)$cache->get($srcA)->execute());
$outB1 = trim((string)$cache->get($srcB)->execute());
$outA2 = trim((string)$cache->get($srcA)->execute());

ctiAssert("tenant A's template renders A's body", str_contains($outA1, 'MARKER_A'), $outA1);
ctiAssert("tenant B's template renders B's body", str_contains($outB1, 'MARKER_B'), $outB1);
ctiAssert(
    "★ returning to A after B still renders A (no shared-artifact overwrite)",
    str_contains($outA2, 'MARKER_A') && !str_contains($outA2, 'MARKER_B'),
    $outA2
);

$artifacts = $compiledFiles($tmp . '/compiled');
ctiAssert(
    '★ both artifacts coexist side by side in the shared compiled dir',
    count($artifacts) >= 2,
    implode(', ', $artifacts)
);

// ── C) The reverse order, in a fresh shared compiled dir ───────────────────────
// A→B→A passing could still be an artefact of which file was written first, so the other order is
// run cold.
$tmpC = $tmp . '/run_c';
$cacheC = new TemplateCache($tmpC, true);
$outB2 = trim((string)$cacheC->get($srcB)->execute());
$outA3 = trim((string)$cacheC->get($srcA)->execute());
$outB3 = trim((string)$cacheC->get($srcB)->execute());

ctiAssert("reverse order: B renders B", str_contains($outB2, 'MARKER_B'), $outB2);
ctiAssert("reverse order: A renders A", str_contains($outA3, 'MARKER_A'), $outA3);
ctiAssert(
    "★ reverse order: returning to B still renders B",
    str_contains($outB3, 'MARKER_B') && !str_contains($outB3, 'MARKER_A'),
    $outB3
);

// ── Cleanup ────────────────────────────────────────────────────────────────────
$rmrf($tmp);
ctiAssert('the test leaves no temp directories behind', !is_dir($tmp));

echo "\n============================================================\n";
echo "Compiled template isolation tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
