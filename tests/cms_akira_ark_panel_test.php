<?php

declare(strict_types=1);

/**
 * CMS Akira — ARK POC Read-Only Panel Test.
 *
 * Freezes the ARK surfacing contract:
 *   - `akira.ark.status@1` read-only capability is exposed by `cms-akira-core`
 *     and depends on `cms.themes.list@1` (boundary-compliant theme read).
 *   - The panel is contributed to `cms.dashboard.widgets` (NOT a second
 *     `cms.sidebar`), route `/admin/ark-status`, permission `dashboard.view`.
 *   - The capability is READ-ONLY: it never mutates selection or registries
 *     (repeat invocation is deterministic).
 *   - The CMS admin dashboard consumes `cms.dashboard.widgets` and renders a
 *     widgets section.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$coreDir = dirname(__DIR__) . '/modules/cms-akira/cms-akira-core';
$manifest = json_decode((string)file_get_contents($coreDir . '/module.json'), true);
$capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
$exposes = is_array($capabilities['exposes'] ?? null) ? $capabilities['exposes'] : [];
$depends = is_array($capabilities['depends'] ?? null) ? $capabilities['depends'] : [];
$contribs = is_array($manifest['admin_contributions'] ?? null) ? $manifest['admin_contributions'] : [];

echo "\n=== CMS AKIRA ARK PANEL (READ-ONLY) ===\n";

// ── Manifest contract ──────────────────────────────────────────────
$exposedIds = array_column($exposes, 'id');
t('core exposes akira.ark.status@1', in_array('akira.ark.status@1', $exposedIds, true), json_encode($exposedIds));

$arkCap = null;
foreach ($exposes as $cap) {
    if (($cap['id'] ?? '') === 'akira.ark.status@1') {
        $arkCap = $cap;
    }
}
t('akira.ark.status@1 modes include first', is_array($arkCap) && in_array('first', $arkCap['modes'] ?? [], true));

t('core depends on cms.themes.list@1', in_array('cms.themes.list@1', $depends, true), json_encode($depends));

// ── Dashboard-widget contribution (NOT a second cms.sidebar) ───────
$arkContrib = null;
foreach ($contribs as $c) {
    if (($c['id'] ?? '') === 'cms-akira-core.ark-panel') {
        $arkContrib = $c;
    }
}
t('core declares cms-akira-core.ark-panel contribution', is_array($arkContrib));
t('contribution host = cms', (string)($arkContrib['host'] ?? '') === 'cms', json_encode($arkContrib ?? []));
t('contribution location = dashboard.widgets', (string)($arkContrib['location'] ?? '') === 'dashboard.widgets');
t('contribution route = /admin/ark-status', (string)($arkContrib['route'] ?? '') === '/admin/ark-status');
t('contribution permission = dashboard.view', (string)($arkContrib['permission'] ?? '') === 'dashboard.view');
t('core does NOT contribute a second cms.sidebar', count(array_filter($contribs, static fn (array $c): bool => ($c['location'] ?? '') === 'cms.sidebar' || ($c['location'] ?? '') === 'sidebar')) === 0);

// ── Route registration ─────────────────────────────────────────────
$routes = include $coreDir . '/routes.php';
$getRoutes = is_array($routes['GET'] ?? null) ? $routes['GET'] : [];
t('/admin/ark-status route registered', isset($getRoutes['/admin/ark-status']), json_encode(array_keys($getRoutes)));

// ── Panel template exists ──────────────────────────────────────────
$templatePath = dirname(__DIR__) . '/templates/modules/cms-akira-core/pages/ark-status.disyl';
t('ark-status.disyl panel template exists', is_file($templatePath));
if (is_file($templatePath)) {
    $tpl = (string)file_get_contents($templatePath);
    t('panel template is read-only (no mutation surface)', !preg_match('/\{ikb_form|\{form|method="(POST|DELETE|PUT)"/i', $tpl));
}

// ── Capability runtime contract (read-only, deterministic) ─────────
require_once $coreDir . '/helpers/capabilities.php';

$result1 = cac_cap_akira_ark_status_1([], 'akira.ark.status@1', 'cms-akira-core');
t('capability resolves ok', ($result1['ok'] ?? false) === true, json_encode($result1));
$data = is_array($result1['data'] ?? null) ? $result1['data'] : [];
t('capability reports read_only=true', ($data['read_only'] ?? false) === true);
t('capability returns theme status object', is_array($data['theme'] ?? null) && ($data['theme']['name'] ?? '') === 'ark', json_encode($data['theme'] ?? []));
t('capability returns profile status object', is_array($data['profile'] ?? null) && ($data['profile']['name'] ?? '') === 'ark-workbench', json_encode($data['profile'] ?? []));

// Determinism / read-only: a second identical invocation must not change
// registration state (no silent mutation between calls).
$result2 = cac_cap_akira_ark_status_1([], 'akira.ark.status@1', 'cms-akira-core');
$data2 = is_array($result2['data'] ?? null) ? $result2['data'] : [];
$r1 = ($data['theme']['registered'] ?? false) === true;
$r2 = ($data2['theme']['registered'] ?? false) === true;
t('repeat invocation is deterministic (theme registration stable)', $r1 === $r2);
$p1 = ($data['profile']['registered'] ?? false) === true;
$p2 = ($data2['profile']['registered'] ?? false) === true;
t('repeat invocation is deterministic (profile registration stable)', $p1 === $p2);

// ── CMS dashboard consumer wiring (static) ─────────────────────────
$adminHandler = (string)@file_get_contents(dirname(__DIR__) . '/modules/cms/handlers/15-admin.php');
t('cmsAdminDashboard collects dashboard.widgets contributions', is_string($adminHandler) && str_contains($adminHandler, "kernelContributionsForHostLocation('cms', 'dashboard.widgets'"));
t('cmsAdminDashboard passes dashboard_widgets to template', is_string($adminHandler) && str_contains($adminHandler, "'dashboard_widgets'"));

$dashTpl = (string)@file_get_contents(dirname(__DIR__) . '/templates/modules/cms/admin/dashboard.disyl');
t('dashboard template renders dashboard_widgets', is_string($dashTpl) && str_contains($dashTpl, '{foreach dashboard_widgets as widget}'));

// ── Logs must stay clean (no capability errors from the panel) ─────
$appLog = (string)file_get_contents(STORAGE_PATH . '/logs/app.log');
$errLog = (string)file_get_contents(STORAGE_PATH . '/logs/error.log');
t('no errors in app.log', trim($appLog) === '', trim($appLog) !== '' ? substr(trim($appLog), 0, 200) : '');
t('no errors in error.log', trim($errLog) === '', trim($errLog) !== '' ? substr(trim($errLog), 0, 200) : '');

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
if ($errors) {
    echo "Errors:\n  - " . implode("\n  - ", $errors) . "\n";
}

exit($fail > 0 ? 1 : 0);
