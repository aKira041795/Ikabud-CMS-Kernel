<?php

declare(strict_types=1);

/**
 * Daily Ledger integrity email toggle.
 *
 * Proves the contract added on 2026-10-20:
 *   1. `integrity_email_enabled = 0` stops outbound email.
 *   2. The in-app notification row is STILL created — the audit trail is not weakened.
 *   3. `integrity_email_enabled = 1` resumes email to active admins.
 *
 * `sendEmail` is stubbed before any bootstrap so the CLI path (which sends
 * synchronously) cannot touch SMTP. Fixtures use reserved 99579x ids and are
 * removed in a finally block.
 */

$GLOBALS['dl_toggle_test_emails'] = [];
function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    $GLOBALS['dl_toggle_test_emails'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
}

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-integrity-email-toggle', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('templates/modules/daily-ledger/admin/settings.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$userId = 995799;
$branchId = 995799;
$fixtureEmail = 'integrity-toggle-995799@example.com';
$keyPrefix = 'test-email-toggle-995799';
$originalSettings = getModuleSettings('daily-ledger');
$originalSettings = is_array($originalSettings) ? $originalSettings : [];

$cleanup = static function () use ($db, $userId, $keyPrefix): void {
    $db->prepare(
        'DELETE FROM dl_integrity_notification_recipients
          WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE aggregate_key LIKE ?)'
    )->execute([$keyPrefix . '%']);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE ?')->execute([$keyPrefix . '%']);
    $db->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$userId]);
};

$applySetting = static function (string $value) use ($originalSettings): void {
    saveModuleSettings('daily-ledger', array_merge($originalSettings, ['integrity_email_enabled' => $value]));
    dlModuleSettings(true);
};

$notificationExists = static function (string $key) use ($db): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key = ?');
    $stmt->execute([$key]);
    return (int)$stmt->fetchColumn() > 0;
};

$cleanup();

try {
    $db->prepare(
        'INSERT INTO dl_users (id, username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, "admin", 1, NOW(), NOW())'
    )->execute([$userId, 'integrity-toggle-995799', $fixtureEmail, password_hash('fixture-only', PASSWORD_BCRYPT), 'Integrity Toggle Admin']);

    $h->section('email off keeps the in-app finding');
    $applySetting('0');
    $GLOBALS['dl_toggle_test_emails'] = [];
    $offKey = $keyPrefix . '-off';
    $offId = dl_raiseIntegrityNotification(
        $db,
        $offKey,
        'unresolved_origin',
        $branchId,
        'dl_branch_receivings',
        $branchId,
        'Toggle probe off',
        'Email must not be sent while the setting is off.',
        true
    );
    $h->test('setting off records the in-app notification', $offId !== null && $notificationExists($offKey));
    $h->test('setting off sends no email', $GLOBALS['dl_toggle_test_emails'] === [], json_encode($GLOBALS['dl_toggle_test_emails']));

    $h->section('email on delivers to the active admin');
    $applySetting('1');
    $GLOBALS['dl_toggle_test_emails'] = [];
    $onKey = $keyPrefix . '-on';
    $onId = dl_raiseIntegrityNotification(
        $db,
        $onKey,
        'unresolved_origin',
        $branchId,
        'dl_branch_receivings',
        $branchId,
        'Toggle probe on',
        'Email must be sent while the setting is on.',
        true
    );
    $recipients = array_column($GLOBALS['dl_toggle_test_emails'], 'to');
    $h->test('setting on records the in-app notification', $onId !== null && $notificationExists($onKey));
    $h->test('setting on emails the active admin', in_array($fixtureEmail, $recipients, true), json_encode($recipients));
    $subjects = array_column($GLOBALS['dl_toggle_test_emails'], 'subject');
    $h->test(
        'email subject carries the Daily Ledger prefix and finding title',
        in_array('[Daily Ledger] Toggle probe on', $subjects, true),
        json_encode($subjects)
    );
} finally {
    saveModuleSettings('daily-ledger', $originalSettings);
    dlModuleSettings(true);
    $cleanup();
}

$h->done();
