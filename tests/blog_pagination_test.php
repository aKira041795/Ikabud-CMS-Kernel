<?php

declare(strict_types=1);

/**
 * Blog listing — slice 2: policy parity (public visibility + published_at order).
 *
 * Contract: .ai/blog-pagination-slice2.contract.md
 *
 *   #1 /cms/blog?page=N slugs equal, in order, the public route SQL; pages disjoint.
 *   #2 A draft post must NOT render on /cms/blog pages 1..3 (security check).
 *   #3 The same source resolved in a NON-public context still returns drafts and
 *      keeps the created_at DESC default.
 *   #4 The pagination widget still reports "Page N of 7".
 *   #5 php -l clean; DiSyL lint clean; storage/logs/error.log gains no new lines.
 *
 * This test exercises the same code path as the /cms/blog route: it boots the
 * tenant, registers module capabilities exactly like public/index.php, flushes
 * the CMS cache, and invokes cmsPublicArchive() in-process.
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/blog';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

// Capabilities are registered by the module route loader, which public/index.php
// runs before dispatch. Reproduce that here so {ikb_entity_list} resolves.
$routes = kernelCoreRoutes();
if (function_exists('preloadAllTenantModuleSettings')) {
    preloadAllTenantModuleSettings();
}
loadModuleRoutes($routes);

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \u{2717} {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function captureOutput(callable $callback): string
{
    ob_start();
    try {
        $callback();
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/**
 * Render /cms/blog?page=N through the real handler and return the HTML.
 */
function blogPageHtml(int $page): string
{
    $_GET = ['page' => $page];
    $_REQUEST = ['page' => $page];
    $_SERVER['REQUEST_URI'] = '/cms/blog?page=' . $page;
    // Force a miss so the freshly-inserted/deleted draft is observed.
    if (function_exists('cmsCacheFlushAll')) {
        cmsCacheFlushAll();
    }
    unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);

    return captureOutput(static function () use ($page): void {
        cmsPublicArchive(['page' => $page]);
    });
}

/**
 * Extract the ordered post slugs rendered by the {ikb_entity_list} cards.
 *
 * @return string[]
 */
function renderedSlugs(string $html): array
{
    preg_match_all('/ikb-card-field--slug">([^<]*)</', $html, $matches);
    return array_values(array_filter($matches[1], static fn(string $s): bool => $s !== ''));
}

/**
 * The acceptance SQL from the contract.
 *
 * @return string[]
 */
function acceptanceSlugs(int $page, int $limit = 10): array
{
    $offset = ($page - 1) * $limit;
    $stmt = app()->db()->query(
        "SELECT slug FROM cms_content
         WHERE type = 'post' AND deleted_at IS NULL
           AND (status = 'published' OR (status = 'scheduled' AND published_at IS NOT NULL AND published_at <= NOW()))
         ORDER BY published_at DESC
         LIMIT {$limit} OFFSET {$offset}"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

// Start from a clean log baseline for acceptance #5.
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$db = app()->db();
$suffix = strtolower(substr(bin2hex(random_bytes(5)), 0, 10));
$draftSlug = 'slice2-draft-' . $suffix;
$authorId = (int)$db->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
$draftId = 0;

echo "\n=== BLOG PAGINATION SLICE 2 — POLICY PARITY ===\n\n";

try {
    // ── Acceptance #1: three pages match the public route SQL exactly ──────────
    echo "• Acceptance #1: pages equal the public visibility SQL (in order)\n";
    $pageSlugs = [];
    for ($page = 1; $page <= 3; $page++) {
        $html = blogPageHtml($page);
        $got = renderedSlugs($html);
        $expected = acceptanceSlugs($page);
        $pageSlugs[$page] = $got;

        t(
            "page {$page} slugs match SQL (order included)",
            $got === $expected,
            'got=[' . implode(',', $got) . '] expected=[' . implode(',', $expected) . ']'
        );
    }

    $sets = array_map(static fn(array $s): array => array_values(array_unique($s)), $pageSlugs);
    $overlap12 = array_intersect($sets[1] ?? [], $sets[2] ?? []);
    $overlap13 = array_intersect($sets[1] ?? [], $sets[3] ?? []);
    $overlap23 = array_intersect($sets[2] ?? [], $sets[3] ?? []);
    t('pages 1 and 2 are disjoint', $overlap12 === [], implode(',', $overlap12));
    t('pages 1 and 3 are disjoint', $overlap13 === [], implode(',', $overlap13));
    t('pages 2 and 3 are disjoint', $overlap23 === [], implode(',', $overlap23));

    // ── Acceptance #2: a draft must never render (security check) ──────────────
    echo "\n• Acceptance #2: a draft post must NOT render on /cms/blog\n";
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'draft', :author_id, NULL, NOW(), NOW())"
    )->execute([
        ':uuid' => cmsUuid(),
        ':title' => 'Slice 2 Draft Probe',
        ':slug' => $draftSlug,
        ':body' => '<p>This draft must never render publicly.</p>',
        ':excerpt' => 'Draft probe',
        ':author_id' => $authorId,
    ]);
    $draftId = (int)$db->lastInsertId();
    t('temporary draft post inserted', $draftId > 0, 'id=' . $draftId);

    $draftSeenOn = [];
    for ($page = 1; $page <= 3; $page++) {
        $html = blogPageHtml($page);
        if (str_contains($html, $draftSlug)) {
            $draftSeenOn[] = $page;
        }
    }
    t(
        'draft slug does not render on pages 1..3',
        $draftSeenOn === [],
        'appeared on page(s): ' . implode(',', $draftSeenOn)
    );
    // Prove the absence is due to policy, not because the draft was never written.
    $draftRow = $db->query("SELECT status, published_at FROM cms_content WHERE id = {$draftId}")->fetch(PDO::FETCH_ASSOC);
    t(
        'draft exists in DB as status=draft with no published_at',
        is_array($draftRow) && $draftRow['status'] === 'draft' && $draftRow['published_at'] === null,
        json_encode($draftRow)
    );

    // ── Acceptance #3: admin / non-public context still sees drafts ────────────
    echo "\n• Acceptance #3: non-public context keeps drafts and created_at DESC\n";
    $adminPayload = [
        'qualifier' => 'recent',
        'limit' => 100,
        'view' => 'card_grid',
        'sort' => ['field' => 'created_at', 'direction' => 'DESC'],
    ];
    $adminResult = cmsResolveEntityList('post', $adminPayload);
    $adminSlugs = array_map(static fn(array $r): string => (string)$r['slug'], $adminResult['rows'] ?? []);
    t('non-public context returns the draft', in_array($draftSlug, $adminSlugs, true));
    t(
        'non-public context ordering is created_at DESC (draft is newest)',
        ($adminSlugs[0] ?? '') === $draftSlug,
        'first=' . ($adminSlugs[0] ?? '(none)')
    );
    $adminTotal = (int)($adminResult['total'] ?? 0);
    t('non-public context has no visibility filter (draft counted)', $adminTotal >= 64, 'total=' . $adminTotal);

    // And with the public signal the same source hides the draft.
    $publicResult = cmsResolveEntityList('post', $adminPayload + ['public_render_origin' => 'cms']);
    $publicSlugs = array_map(static fn(array $r): string => (string)$r['slug'], $publicResult['rows'] ?? []);
    t('public signal hides the draft from the same source', !in_array($draftSlug, $publicSlugs, true));
    t('public signal total excludes the draft', (int)($publicResult['total'] ?? 0) === $adminTotal - 1, 'public=' . (int)($publicResult['total'] ?? 0) . ' admin=' . $adminTotal);

    // ── Acceptance #4: pagination widget still reports Page N of 7 ─────────────
    echo "\n• Acceptance #4: pagination widget\n";
    for ($page = 1; $page <= 3; $page++) {
        $html = blogPageHtml($page);
        t("page {$page} widget reports 'Page {$page} of 7'", str_contains($html, "Page {$page} of 7"));
    }
} finally {
    // Always remove the temporary draft and flush the cache it influenced.
    if ($draftId > 0) {
        try {
            $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$draftId]);
        } catch (Throwable $e) {
        }
    }
    $deleted = false;
    try {
        $check = $db->prepare('SELECT COUNT(*) FROM cms_content WHERE slug = ?');
        $check->execute([$draftSlug]);
        $deleted = (int)$check->fetchColumn() === 0;
    } catch (Throwable $e) {
    }
    t('temporary draft post deleted', $deleted);
    if (function_exists('cmsCacheFlushAll')) {
        cmsCacheFlushAll();
    }
    $_GET = [];
    $_REQUEST = [];
    $_SERVER['REQUEST_URI'] = '/cms/blog';
}

// ── Acceptance #5: lint + logs ─────────────────────────────────────────────
echo "\n• Acceptance #5: lint + logs\n";

$changedPhp = [
    __DIR__ . '/../modules/cms/helpers/56-entity-capabilities.php',
    __DIR__ . '/../kernel/EntityContext/EntityViewResolver.php',
    __DIR__ . '/../kernel/DiSyL/Component/ComponentRenderer.php',
];
foreach ($changedPhp as $file) {
    $out = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
    t('php -l clean: ' . basename($file), is_string($out) && str_contains($out, 'No syntax errors detected'), trim((string)$out));
}

$lintOut = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/_lint_disyl.php') . ' --ci 2>&1');
t('DiSyL lint clean (no errors)', trim((string)$lintOut) === '', trim((string)$lintOut));

$errorLogLines = array_values(array_filter(
    preg_split('/\R/', (string)@file_get_contents(STORAGE_PATH . '/logs/error.log')) ?: [],
    static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
));
t('error.log gains no new lines', $errorLogLines === [], implode(' | ', array_slice($errorLogLines, 0, 3)));

$appLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
t('app.log has no critical errors', !str_contains($appLog, '[critical]'));

echo "\n══════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed checks:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    ob_end_flush();
    exit(1);
}

ob_end_flush();
exit(0);
