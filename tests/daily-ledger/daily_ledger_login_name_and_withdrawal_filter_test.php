<?php

declare(strict_types=1);

/**
 * Daily Ledger — login name capture + the Activity "Withdrawal" filter.
 *
 * Locks in the contract .ai/login-name-and-withdrawal-filter-contract.md:
 *   AC1  both action-filter maps match the withdrawal actions actually written
 *   AC2  the withdrawal filter returns the withdrawal row instead of zero
 *   AC3  the repair file targets by id AND username and is re-runnable
 *   AC4  the login name can only capture an empty profile, never overwrite one
 *   AC5  an audit row carries the per-event name; a later rename cannot rewrite it
 *   AC6  a non-daily-ledger audit row keeps metadata_json NULL (shape unchanged)
 *
 * Tenant 207 (baronledger). Every fixture row is high-id / far-dated and removed
 * in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-login-name-withdrawal-filter', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('kernel/Contracts/ModuleContext.php');
$h->fingerprint('modules/daily-ledger/database/repair_login_names_20260918.sql');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();
$appDb = app()->db();

$fixtureUser = 997701;
$fixtureDate = '2099-01-01';
$cleanup = static function () use ($appDb, $fixtureUser, $fixtureDate): void {
    $appDb->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$fixtureUser]);
    $appDb->prepare('DELETE FROM audit_logs WHERE DATE(created_at) = ? AND actor_module_user_id = ?')->execute([$fixtureDate, $fixtureUser]);
    $appDb->prepare("DELETE FROM audit_logs WHERE DATE(created_at) = ? AND action IN ('field_update','withdrawal') AND entity_type = 'probe'")->execute([$fixtureDate]);
};
$cleanup();

// ─── AC1: both maps ─────────────────────────────────────────────────
$h->section('AC1: both action-filter maps');

$handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$withdrawalMaps = [];
if (preg_match_all("/'withdrawal' => \[(.*?)\],/s", $handlersSource, $mapMatches)) {
    foreach ($mapMatches[1] as $raw) {
        $withdrawalMaps[] = preg_replace('/\s+/', ' ', trim($raw));
    }
}
$expectedWithdrawal = "'withdrawal', 'withdrawal_updated', 'production_withdrawal'";
$h->test('exactly two withdrawal filter maps exist', count($withdrawalMaps) === 2, (string)count($withdrawalMaps));
$h->test('both maps match the written actions and keep production_withdrawal last',
    count($withdrawalMaps) === 2 && $withdrawalMaps[0] === $expectedWithdrawal && $withdrawalMaps[1] === $expectedWithdrawal,
    json_encode($withdrawalMaps));
$h->test('the old broken production_withdrawal-only map is gone',
    !str_contains($handlersSource, "'withdrawal' => ['production_withdrawal'],"));

$unchangedFilters = [
    "'output' => ['production_output'],",
    "'product' => ['create_product', 'update_product'],",
    "'user' => ['create_user', 'update_user', 'delete_user', 'restore_user'],",
    "'commissary' => ['create_commissary_run', 'update_commissary_run', 'delete_commissary_run', 'save_commissary_material'],",
    "'ledger' => ['field_update', 'row_update', 'close_day', 'reopen_day'],",
    "'variance' => ['variance_status'],",
];
foreach ($unchangedFilters as $unchanged) {
    $h->test('unchanged filter: ' . $unchanged, str_contains($handlersSource, $unchanged));
}

// ─── AC2: the filter returns the withdrawal row ─────────────────────
$h->section('AC2: Activity withdrawal filter returns rows');

$appDb->prepare('INSERT INTO audit_logs (module, actor_module_user_id, actor_source, action, entity_type, entity_id, new_data, metadata_json, created_at) '
    . "VALUES ('daily-ledger', ?, 'daily-ledger', 'withdrawal', 'probe', 'W-1', '{}', '{\"actor_name\":\"Probe Name\",\"actor_username\":\"probe-filter\"}', ?)")
    ->execute([$fixtureUser, $fixtureDate . ' 12:00:00']);

$token = dl_generateAuthTokens(['sub' => 'admin:1', 'id' => 1, 'username' => 'Ledger-Admin', 'name' => 'Jean', 'full_name' => 'Jean', 'role' => 'admin', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $token['token'];

$renderEntries = static function (string $filter, string $date) use ($h): array {
    $_GET = ['action_filter' => $filter, 'date_from' => $date, 'date_to' => $date];
    \Ikabud\Kernel\Http\Input::reset();
    ob_start();
    handleAdminActivity();
    $html = (string)ob_get_clean();
    return [
        'entries' => preg_match('/(\d+)\s+entries/', $html, $m) ? (int)$m[1] : -1,
        'empty' => str_contains($html, 'No activity found for selected date range'),
        'html' => $html,
    ];
};

$withdrawalView = $renderEntries('withdrawal', $fixtureDate);
$h->test('withdrawal filter finds the isolated withdrawal row', $withdrawalView['entries'] === 1, json_encode($withdrawalView['entries']));
$h->test('withdrawal filter is not the empty state', $withdrawalView['empty'] === false);

$outputView = $renderEntries('output', $fixtureDate);
$h->test('output filter still returns zero for that isolated date', $outputView['entries'] === 0, json_encode($outputView['entries']));
$h->test('output filter shows the empty state', $outputView['empty'] === true);

// ─── AC3: repair file ───────────────────────────────────────────────
$h->section('AC3: repair file targets id AND username, re-runnable');

$repairSql = (string)file_get_contents($base . '/modules/daily-ledger/database/repair_login_names_20260918.sql');
$h->test('repair targets id 20 by id AND username',
    str_contains($repairSql, "id = 20 AND username = 'shiela_baina'"));
$h->test('repair targets id 21 by id AND username',
    str_contains($repairSql, "id = 21 AND username = 'admin_view'"));
$h->test('repair is conditional so a second run is a no-op',
    substr_count($repairSql, 'full_name IS NULL OR full_name <>') === 4);
$h->test('repair writes an audit_logs record', str_contains($repairSql, 'INSERT INTO audit_logs'));

// ─── AC4: login capture semantics ───────────────────────────────────
$h->section('AC4: personal accounts capture an empty profile only; shared accounts track the latest');

$h->test('login persist is guarded on an empty stored name',
    str_contains($handlersSource, '(full_name IS NULL OR full_name ='));
$h->test('login logs a refused overwrite with both values and the id',
    str_contains($handlersSource, 'daily-ledger auth full_name overwrite refused')
    && str_contains($handlersSource, "'stored_full_name'")
    && str_contains($handlersSource, "'entered_full_name'"));
// The empty-only guard above is the PERSONAL-account rule. A shared branch
// account (cashier) instead reads its roles from the explicit helper added for
// .ai/shared-account-latest-holder-contract.md and updates on every login.
$h->test('the login applies the explicit shared-role helper, not an inline role literal',
    str_contains($handlersSource, 'in_array($role, dl_sharedBranchAccountRoles(), true)'));
$h->test('the shared list is cashier only and excludes production_in_charge',
    dl_sharedBranchAccountRoles() === ['cashier'],
    json_encode(dl_sharedBranchAccountRoles()));
$h->test('the shared-name update is logged as information with both values',
    str_contains($handlersSource, 'daily-ledger auth full_name updated for shared account')
    && str_contains($handlersSource, "'previous_full_name'")
    && str_contains($handlersSource, "'new_full_name'"));

// Exercise the exact guarded UPDATE: an established name must not change; an
// empty one must be captured exactly once.
$appDb->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "admin", 1)')
    ->execute([$fixtureUser, 'probe-login-name-' . $fixtureUser, password_hash('x', PASSWORD_BCRYPT), 'Established Name']);
$guardedUpdate = 'UPDATE dl_users SET full_name = :fn WHERE id = :id AND deleted_at IS NULL AND (full_name IS NULL OR full_name = \'\')';
$appDb->prepare($guardedUpdate)->execute([':fn' => 'Attempted Overwrite', ':id' => $fixtureUser]);
$stored = $appDb->query('SELECT full_name FROM dl_users WHERE id = ' . $fixtureUser)->fetchColumn();
$h->test('guarded update does not overwrite an established name', $stored === 'Established Name', var_export($stored, true));

$appDb->prepare('UPDATE dl_users SET full_name = ? WHERE id = ?')->execute(['', $fixtureUser]);
$appDb->prepare($guardedUpdate)->execute([':fn' => 'Captured Once', ':id' => $fixtureUser]);
$stored = $appDb->query('SELECT full_name FROM dl_users WHERE id = ' . $fixtureUser)->fetchColumn();
$h->test('guarded update captures an empty profile', $stored === 'Captured Once', var_export($stored, true));
$appDb->prepare($guardedUpdate)->execute([':fn' => 'Second Attempt', ':id' => $fixtureUser]);
$stored = $appDb->query('SELECT full_name FROM dl_users WHERE id = ' . $fixtureUser)->fetchColumn();
$h->test('guarded update ignores a second capture attempt', $stored === 'Captured Once', var_export($stored, true));

// ─── AC5: per-event name is frozen ──────────────────────────────────
$h->section('AC5: the per-event name survives a rename');

$appDb->prepare('UPDATE dl_users SET full_name = ? WHERE id = ?')->execute(['Name At Event Time', $fixtureUser]);
app()->setUser([
    'sub' => 'admin:' . $fixtureUser, 'id' => $fixtureUser, 'username' => 'probe-login-name-' . $fixtureUser,
    'name' => 'Name At Event Time', 'full_name' => 'Name At Event Time', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$ctx->audit('field_update', null, 'probe', 'EVT-FROZEN', null, ['note' => 'frozen']);
$appDb->prepare("UPDATE audit_logs SET created_at = ? WHERE action='field_update' AND entity_type='probe' AND entity_id='EVT-FROZEN'")->execute([$fixtureDate . ' 12:05:00']);
$appDb->prepare('UPDATE dl_users SET full_name = ? WHERE id = ?')->execute(['Renamed Later', $fixtureUser]);

$frozenRow = $appDb->query("SELECT metadata_json FROM audit_logs WHERE action='field_update' AND entity_type='probe' AND entity_id='EVT-FROZEN' ORDER BY id DESC LIMIT 1")->fetchColumn();
$frozenMeta = json_decode((string)$frozenRow, true);
$h->test('audit row is stamped with the session name', ($frozenMeta['actor_name'] ?? null) === 'Name At Event Time', var_export($frozenRow, true));

$frozenView = $renderEntries('', $fixtureDate);
$h->test('activity view shows the frozen name for that event', str_contains($frozenView['html'], 'Name At Event Time'));
$eventChunk = '';
$pos = strpos($frozenView['html'], 'EVT-FROZEN');
if ($pos !== false) {
    $eventChunk = substr($frozenView['html'], max(0, $pos - 2000), 4000);
}
$h->test('activity view does not relabel the event with the renamed profile', !str_contains($eventChunk, 'Renamed Later'), $eventChunk);

// ─── AC6: other modules unchanged ───────────────────────────────────
$h->section('AC6: non-daily-ledger audit rows keep their shape');

$other = new \Ikabud\Kernel\Contracts\ModuleContext(app(), 'bakeshop', $ctx->db(), []);
$other->audit('probe_other_module_event', null, 'probe', 'OTHER-1', null, ['x' => 1]);
$otherRow = $appDb->query("SELECT module, metadata_json FROM audit_logs WHERE action='probe_other_module_event' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$h->test('a non-daily-ledger row has NULL metadata_json', is_array($otherRow) && $otherRow['metadata_json'] === null, json_encode($otherRow));
$appDb->prepare("DELETE FROM audit_logs WHERE action='probe_other_module_event'")->execute();

$cleanup();

$h->done();
