<?php
/**
 * Are declared content types renderable with no bespoke PHP?
 *
 * This is the substrate claim made falsifiable. A content type declared in
 * `cms_content_types` must be reachable at the generic public routes
 * `/cms/{type}` and `/cms/{type}/{slug}`, and must render through the
 * entity-view pipeline - without anyone writing a controller or a template for
 * that type specifically.
 *
 * Measured 2026-09-26: five non-hardcoded types (course, service,
 * portfolio-item, lesson, product) each rendered a full public archive through
 * entity views. Before that, nobody had asserted it, so it was an accident
 * rather than a guarantee. This test turns it into a guarantee.
 *
 * Run: php tests/cms_declared_type_render_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
    } else {
        $fail++;
        echo "  \u{2717} {$label}" . ($detail !== '' ? "  -- {$detail}" : '') . "\n";
    }
}

function httpGet(string $host, string $path): array
{
    $cmd = 'curl -s -H ' . escapeshellarg('Host: ' . $host)
         . ' --max-time 25 -w ' . escapeshellarg('\n%{http_code}')
         . ' ' . escapeshellarg('http://127.0.0.1' . $path) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, "\n");
    if ($pos === false) {
        return [0, ''];
    }
    return [(int) substr($out, $pos + 1), substr($out, 0, $pos)];
}

$host   = getenv('CMS_TEST_HOST') ?: 'cmsnew.test';
$tenant = (int) (getenv('CMS_TEST_TENANT') ?: 1);

echo "\nDeclared content types render without bespoke PHP\n";
echo "  host={$host} tenant={$tenant}\n\n";

// ── The declared types ──────────────────────────────────────────────────────
$types = [];
try {
    $db = app()->dbForTenant($tenant);
    $types = $db->query('SELECT slug, label FROM cms_content_types WHERE is_active = 1 ORDER BY slug')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "  cannot read declared content types: {$e->getMessage()}\n");
}

t('the tenant declares at least one active content type', count($types) > 0, 'count=' . count($types));

// Types that predate the generic routes and have their own handlers. The claim
// is about the OTHER ones - types nobody wrote code for.
$hardcoded = ['page' => true, 'post' => true];
$declared  = array_values(array_filter($types, static fn(array $r): bool => !isset($hardcoded[(string) $r['slug']])));

t('there are declared types with no bespoke handler', count($declared) > 0, 'count=' . count($declared));

if (count($declared) === 0) {
    echo "\n  nothing to assert - refusing to report a vacuous pass\n";
    exit(1);
}

// ── The generic route must exist for each declared type ─────────────────────
$routes = require __DIR__ . '/../modules/cms/routes.php';
$routeMap = [];
foreach ($routes as $method => $entries) {
    if (is_array($entries)) {
        foreach ($entries as $pattern => $handler) {
            $routeMap[$method . ' ' . $pattern] = $handler;
        }
    }
}
t(
    'a generic list route exists for declared types',
    ($routeMap['GET /cms/{type}'] ?? null) === 'cms:cmsPublicEntityList',
    (string) ($routeMap['GET /cms/{type}'] ?? 'missing')
);
t(
    'a generic detail route exists for declared types',
    ($routeMap['GET /cms/{type}/{slug}'] ?? null) === 'cms:cmsPublicEntityView',
    (string) ($routeMap['GET /cms/{type}/{slug}'] ?? 'missing')
);

// ── Reachability: no server means FAIL, never a silent skip ─────────────────
[$probeCode] = httpGet($host, '/cms/blog');
if ($probeCode !== 200) {
    fwrite(STDERR, "\n  {$host} is not answering (probe http={$probeCode}).\n"
        . "  This test asserts observable HTTP behaviour, so it fails rather than skipping:\n"
        . "  a skipped reachability check is how a broken claim passes.\n");
    exit(1);
}
t('the site is reachable', true);

// ── Each declared type renders an entity view ───────────────────────────────
echo "\n";
$rendered = 0;
foreach ($declared as $row) {
    $slug = (string) $row['slug'];
    [$code, $body] = httpGet($host, '/cms/' . $slug);

    $hasList = str_contains($body, 'data-entity-kind="list-item"') || str_contains($body, 'entity-card');
    $ok = ($code === 200) && $hasList;
    if ($ok) {
        $rendered++;
    }

    $detail = $code === 200
        ? ($hasList ? 'entity view rendered' : 'http 200 but no entity-view output')
        : "http {$code}";
    t(sprintf('/cms/%-16s renders an entity view', $slug), $ok, $detail);

    // Detail page, if the list exposed one
    if (preg_match('~/cms/' . preg_quote($slug, '~') . '/([a-z0-9-]+)~', $body, $m)) {
        [$dCode, $dBody] = httpGet($host, '/cms/' . $slug . '/' . $m[1]);
        t(
            sprintf('/cms/%-16s detail renders', $slug),
            $dCode === 200 && $dBody !== '',
            "http {$dCode}"
        );
    }
}

t('every declared type rendered', $rendered === count($declared), "{$rendered}/" . count($declared));

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
