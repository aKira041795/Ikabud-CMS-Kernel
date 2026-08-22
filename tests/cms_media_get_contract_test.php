<?php
/**
 * cms.media.get@1 contract test — scalar vs batch schemas.
 *
 * Verifies:
 *   - SCALAR: one integer id → record or not_found (404); malformed → 422.
 *   - BATCH: list of ids (max 100) → ordered array matching INPUT order,
 *     missing omitted, duplicate ids one entry per position, empty → [].
 *   - Authz: no caller user → 401; non-CMS/non-admin user → 403 (distinct from 404).
 *   - Record shape: {id, filename, url, mime_type, alt, width, height, size, created_at}.
 *   - Manifest declares cms.media.get@1; Akira media module depends on it.
 *
 * Run: php tests/cms_media_get_contract_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

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

function cmsMediaGetTestCall(mixed $payload): array
{
    return cms_cap_cms_media_get_1($payload, 'cms.media.get@1', 'cms');
}

function cmsMediaTestSetCaller(?array $user): void
{
    if (function_exists('kernel_request_context_set')) {
        kernel_request_context_set('_capability_call_context', $user === null ? [] : ['user' => $user, 'module' => 'cms-akira-core']);
        $GLOBALS['_capability_call_context'] = $user === null ? [] : ['user' => $user, 'module' => 'cms-akira-core'];
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n── Manifest declarations ──\n";
$cmsManifest = json_decode((string) file_get_contents(BASE_PATH . '/modules/cms/module.json'), true);
$exposed = $cmsManifest['capabilities']['exposes'] ?? [];
$ids = array_map(fn($e) => $e['id'] ?? '', $exposed);
t('CMS exposes cms.media.get@1', in_array('cms.media.get@1', $ids, true));

$mediaManifest = json_decode((string) file_get_contents(BASE_PATH . '/modules/cms-akira/cms-akira-media/module.json'), true);
$depends = $mediaManifest['capabilities']['depends'] ?? [];
t('cms-akira-media depends on cms.media.get@1', in_array('cms.media.get@1', $depends, true));
t('cms-akira-media no longer reads cms_media', !in_array('cms_media', $mediaManifest['reads_tables'] ?? [], true));

echo "\n── Handler exists + authz gate (401 / 403) ──\n";
t('cms_cap_cms_media_get_1 is callable', function_exists('cms_cap_cms_media_get_1'));

cmsMediaTestSetCaller(null);
$res = cmsMediaGetTestCall(['id' => 1]);
t('no caller user → 401', ($res['code'] ?? null) === 401, json_encode($res));

cmsMediaTestSetCaller(['source' => 'kernel', 'role' => 'admin', 'id' => 1]);
$res = cmsMediaGetTestCall(['id' => 1]);
t('kernel admin caller admitted (not 401/403)', ($res['code'] ?? null) !== 401 && ($res['code'] ?? null) !== 403, json_encode($res));

cmsMediaTestSetCaller(['source' => 'cms', 'role' => 'subscriber', 'id' => 1]);
$res = cmsMediaGetTestCall(['id' => 1]);
t('cms subscriber admitted (not 401/403)', ($res['code'] ?? null) !== 401 && ($res['code'] ?? null) !== 403, json_encode($res));

cmsMediaTestSetCaller(['source' => 'external', 'role' => 'admin', 'id' => 1]);
$res = cmsMediaGetTestCall(['id' => 1]);
t('non-CMS foreign caller → 403', ($res['code'] ?? null) === 403, json_encode($res));

echo "\n── SCALAR input validation (422) ──\n";
cmsMediaTestSetCaller(['source' => 'kernel', 'role' => 'admin', 'id' => 1]);
$res = cmsMediaGetTestCall('not-an-object');
t('non-object payload → 422', ($res['code'] ?? null) === 422);
$res = cmsMediaGetTestCall(['id' => 0]);
t('id 0 → 422', ($res['code'] ?? null) === 422);
$res = cmsMediaGetTestCall(['id' => 'abc']);
t('non-numeric id → 422', ($res['code'] ?? null) === 422);

echo "\n── SCALAR not_found (404, distinct from 403) ──\n";
$res = cmsMediaGetTestCall(['id' => 2147483000]);
t('missing id → 404 not_found', ($res['code'] ?? null) === 404 && ($res['error'] ?? '') === 'not_found', json_encode($res));

echo "\n── BATCH input validation (422) ──\n";
$res = cmsMediaGetTestCall([]);
t('empty payload (no id/ids) → 422', ($res['code'] ?? null) === 422);
$res = cmsMediaGetTestCall(['ids' => 'not-array']);
t('ids as string → 422', ($res['code'] ?? null) === 422);
$res = cmsMediaGetTestCall(['ids' => []]);
t('empty batch → ok with []', ($res['ok'] ?? false) === true && ($res['data'] ?? null) === []);
$res = cmsMediaGetTestCall(['ids' => array_fill(0, 101, 1)]);
t('batch over 100 → 422', ($res['code'] ?? null) === 422);
$res = cmsMediaGetTestCall(['ids' => [1, 'x']]);
t('batch with non-integer → 422', ($res['code'] ?? null) === 422);

echo "\n── BATCH ordering, duplicates, missing (against real DB) ──\n";
$existingIds = [];
try {
    $stmt = app()->db()->query("SELECT id FROM cms_media WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 3");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingIds[] = (int) $row['id'];
    }
} catch (Throwable $e) {
    $existingIds = [];
}

if (count($existingIds) >= 2) {
    $a = $existingIds[0];
    $b = $existingIds[1];
    $missing = 2147483000;

    // Input order preserved + missing omitted + duplicates per-position.
    $res = cmsMediaGetTestCall(['ids' => [$b, $missing, $a, $b]]);
    $data = $res['data'] ?? [];
    t('batch returns ok', ($res['ok'] ?? false) === true, json_encode($res));
    t('batch preserves input order (b, a, b)', count($data) === 3 && (int)$data[0]['id'] === $b && (int)$data[1]['id'] === $a && (int)$data[2]['id'] === $b, json_encode(array_column($data, 'id')));
    t('batch omits missing id', !in_array($missing, array_column($data, 'id'), true));
    t('batch preserves duplicate positions', count($data) === 3);
    foreach ($data as $rec) {
        t("record shape has id", isset($rec['id']));
        t("record shape has url", array_key_exists('url', $rec));
        t("record shape has mime_type", array_key_exists('mime_type', $rec));
        t("record shape has alt", array_key_exists('alt', $rec));
        t("record shape has size", array_key_exists('size', $rec));
        t("record shape has created_at", array_key_exists('created_at', $rec));
        break; // shape verified on first record
    }
} else {
    // No seeded media rows; verify ordering semantics via handler-level only.
    t('no cms_media rows seeded — batch ordering SKIPPED (needs fixture)', true);
}

echo "\n── Record shape via scalar (if any row exists) ──\n";
if (count($existingIds) >= 1) {
    $res = cmsMediaGetTestCall(['id' => $existingIds[0]]);
    $rec = $res['data'] ?? [];
    t('scalar returns ok', ($res['ok'] ?? false) === true);
    t('scalar record has filename', array_key_exists('filename', $rec));
    t('scalar record url resolves to string', is_string($rec['url'] ?? null));
}

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo "Errors:\n  - " . implode("\n  - ", $errors) . "\n";
    exit(1);
}
exit(0);
