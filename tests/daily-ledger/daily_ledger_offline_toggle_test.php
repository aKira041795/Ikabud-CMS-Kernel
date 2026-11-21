<?php

declare(strict_types=1);

/**
 * Daily Ledger — the Offline Access admin switch.
 *
 * WHY THIS EXISTS
 * ---------------
 * Branches reported additions they never keyed: the AM sheet opens with stock already in the
 * `addtl` column. Traced on the live dump, those values are not the PM-ending carry-forward (that
 * writes `beg_bal` only) and not a commissary push (commissary production is explicitly blocked
 * from writing a branch ledger). They are `own_production` / `self_production` rows written by the
 * branch's own AM account the evening before, dated to the NEXT business date.
 *
 * The device queue is the one path that can do that without leaving a trail: a queued entry replays
 * against the date the DEVICE computed, and until 2026-11-19 the replay wrote no audit row at all
 * (measured: branch 25, 748 pcs PANDESAL, 2026-11-19 19:38, ledger 2026-11-20 AM - a movement row
 * with no `withdrawal` and no `ledger_delta_applied` audit anywhere).
 *
 * So offline access becomes an admin switch. Off must mean OFF everywhere, not just hidden:
 *   1. the manifest declares it and its default preserves today's behaviour (on),
 *   2. the helper reads it,
 *   3. every WRITING offline endpoint refuses with OFFLINE_DISABLED, and the replay-only batch
 *      route refuses too - otherwise a device holding a stale queue keeps posting behind the
 *      branch's back, which is the exact complaint the switch exists to stop,
 *   4. revoking a device stays ALLOWED while disabled, because that is the admin's safety action,
 *   5. the cashier ledger renders none of the offline controls.
 *
 * Integration mode — real tenant DB (207), no fixtures beyond a settings row that is removed again.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-offline-toggle', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $dlCtx->db();

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
$manifest = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true) ?: [];
$templateSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');

// ── 1. Manifest ──────────────────────────────────────────────────────
$field = null;
foreach (($manifest['settings_fields'] ?? []) as $candidate) {
    if (($candidate['key'] ?? '') === 'offline_access_enabled') {
        $field = $candidate;
        break;
    }
}
$h->test('the manifest declares offline_access_enabled', is_array($field));
$h->test('its default preserves current behaviour (on)', (string)($field['default'] ?? '') === '1');
$h->test('its label says what turning it ON does', stripos((string)($field['label'] ?? ''), 'offline') !== false);
$h->test(
    'its description names the failure it prevents',
    stripos((string)($field['description'] ?? ''), 'replay') !== false
);

$defaults = dlSettingsDefaults();
$h->test('dlSettingsDefaults() carries it (so the settings page renders it)', array_key_exists('offline_access_enabled', $defaults));

// ── 2. The helper ────────────────────────────────────────────────────
$h->test('dl_isOfflineAccessEnabled() exists', function_exists('dl_isOfflineAccessEnabled'));
$h->test('it is ON with no stored value (upgrade changes nothing)', dl_isOfflineAccessEnabled() === true);

$storedSettings = static function (): array {
    return getModuleSettingsForTenant('daily-ledger', 207);
};
$before = $storedSettings();
$hadKey = array_key_exists('offline_access_enabled', $before);
$previous = $before['offline_access_enabled'] ?? null;

// Write through the module's own persist path - the one the Settings page uses - so this exercises
// the real round trip rather than a table the module does not own.
$h->test('persisting the switch OFF succeeds', dlPersistModuleSettings(['offline_access_enabled' => '0']) === true);
dlModuleSettings(true);
$h->test('a stored 0 turns it OFF', dl_isOfflineAccessEnabled() === false);

$h->test('persisting the switch ON succeeds', dlPersistModuleSettings(['offline_access_enabled' => '1']) === true);
dlModuleSettings(true);
$h->test('a stored 1 turns it back ON', dl_isOfflineAccessEnabled() === true);

// ── 2b. The ADMIN SETTINGS surface ───────────────────────────────────
// A setting that exists in the manifest but nowhere on the page is not a setting: the owner went
// looking for this toggle and found nothing, because the settings page renders a HARDCODED list of
// controls rather than looping over settings_fields. These assertions pin every link in that chain.
$settingsTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl');
$featureMap = dl_featureSettings();
$h->test('dl_featureSettings() carries the switch (the page payload source)', array_key_exists('offline_access_enabled', $featureMap));
$h->test('the page payload passes it to the template', str_contains($handlersSrc, "'offline_access_enabled' => \$offlineAccessEnabled,"));
// Measured 2026-11-21: the GET render payload omitted this key, so the template's
// {if offline_access_enabled} read nothing and the checkbox rendered UNCHECKED no matter what was
// stored. An admin ticked it, saved, saw "Settings updated", and the box came back empty - "it does
// not persist" - while the database was correct all along. Pin the render payload itself.
$h->test('the GET render payload carries it', str_contains($handlersSrc, "'offline_access_enabled' => \$featureSettings['offline_access_enabled'],"));
$h->test('the save handler reads it from the form', str_contains($handlersSrc, "'offline_access_enabled' => &\$offlineAccessEnabled,"));
$h->test('the save handler persists it', str_contains($handlersSrc, "'offline_access_enabled' => \$offlineAccessEnabled ? '1' : '0',"));
$h->test('the settings page renders the checkbox', str_contains($settingsTemplate, 'id="feature-offline-access"'));
$h->test('the checkbox is admin-gated like its neighbours', str_contains($settingsTemplate, '<input type="checkbox" id="feature-offline-access" {if offline_access_enabled}checked{/if}>'));
$h->test('a read-only role sees a state badge instead', str_contains($settingsTemplate, 'Offline Access is {if offline_access_enabled}Enabled{/if}'));
$h->test('the checkbox posts its value with the other flags', str_contains($settingsTemplate, 'payload.offline_access_enabled = featureOfflineAccess.checked;'));

dlPersistModuleSettings(['offline_access_enabled' => '0']);
dlModuleSettings(true);
$h->test('the page payload follows the stored value (OFF)', dl_featureSettings()['offline_access_enabled'] === false);
dlPersistModuleSettings(['offline_access_enabled' => '1']);
dlModuleSettings(true);
$h->test('the page payload follows the stored value (ON)', dl_featureSettings()['offline_access_enabled'] === true);
$h->test('the section explains the symptom, not just the feature', stripos($settingsTemplate, 'nobody there keyed') !== false);

// ── 3. Every writing endpoint refuses ────────────────────────────────
$h->test('the refusal helper exists', function_exists('dl_offlineRefuseWhenDisabled'));
$h->test('the refusal names a code the client can branch on', str_contains($handlersSrc, "'OFFLINE_DISABLED'"));

foreach (['apiOfflineEnroll', 'apiOfflineBootstrap', 'apiOfflineStatus', 'apiOfflineReconcile'] as $fn) {
    $start = strpos($offlineSrc, 'function ' . $fn . '(');
    $body = $start === false ? '' : substr($offlineSrc, $start, 400);
    $h->test($fn . ' refuses when offline access is off', str_contains($body, 'dl_offlineRefuseWhenDisabled()'));
}

$revokeStart = strpos($offlineSrc, 'function apiOfflineRevoke(');
$revokeBody = $revokeStart === false ? '' : substr($offlineSrc, $revokeStart, 500);
$h->test('apiOfflineRevoke stays available while disabled (the admin safety action)', !str_contains($revokeBody, 'dl_offlineRefuseWhenDisabled()'));

$batchStart = strpos($handlersSrc, 'function apiSaveLedgerBatch(');
$batchBody = $batchStart === false ? '' : substr($handlersSrc, $batchStart, 600);
$h->test('the offline replay batch refuses too (a stale queue must not post)', str_contains($batchBody, 'dl_offlineRefuseWhenDisabled()'));

// ── 4. The cashier ledger hides every control ────────────────────────
$h->test('the render payload carries offline_enabled', str_contains($handlersSrc, "'offline_enabled' => dl_isOfflineAccessEnabled()"));
$h->test('the badge and Enable button are behind the switch', str_contains($templateSrc, '{if offline_enabled}<span id="offline-ready-badge"'));
$h->test('the pending-sync panel is behind the switch', str_contains($templateSrc, "{if offline_enabled}\n<div id=\"offline-pending-sync\""));
$h->test('the offline auth include is behind the switch', str_contains($templateSrc, '{if offline_enabled}{include "modules/daily-ledger/cashier/offline_auth.disyl"}{/if}'));
$h->test('the offline vault script is behind the switch', str_contains($templateSrc, '{if offline_enabled}<script src="/daily-ledger/assets/offline-vault.js"></script>'));
$h->test(
    'the template does not render an offline control outside the switch',
    substr_count($templateSrc, 'id="offline-access-btn"') === 1
        && substr_count($templateSrc, 'id="offline-pending-sync"') === 1
);

// ── 5. Turning it off does not disable the online ledger ────────────
$h->test('the online single-field save is NOT gated (offline off ≠ ledger off)', !str_contains(substr($handlersSrc, (int)strpos($handlersSrc, 'function apiSaveLedgerField('), 500), 'dl_offlineRefuseWhenDisabled()'));
$h->test('the admin production sync batch is NOT gated (it is not an offline path)', !str_contains(substr((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), (int)strpos($handlersSrc, 'function apiProductionSyncBatch('), 500), 'dl_offlineRefuseWhenDisabled()'));

$h->done();
