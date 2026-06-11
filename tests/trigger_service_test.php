<?php
/**
 * TriggerService — Unit Tests
 *
 * Verifies per-request trigger state management: pending registrations,
 * trigger cache, and reset behaviour.
 *
 * Run: php tests/trigger_service_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\TriggerService;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "══════════════════════════════════════════\n";
echo "  TriggerService — Unit Test Suite\n";
echo "══════════════════════════════════════════\n\n";

// ── Section 1: Pending Registrations ──────────────────────────────────────
echo "── Pending Registrations ──\n";

$ts = new TriggerService();

// 1.1 — Initial state is empty
t('initial pending registrations empty', $ts->getPendingRegistrations() === []);

// 1.2 — Add a pending registration
$ts->addPendingRegistration('cms', ['cms.content.published', 'cms.content.updated']);
$pending = $ts->getPendingRegistrations();
t('pending contains cms', isset($pending['cms']));
t('cms has 2 events', count($pending['cms'] ?? []) === 2);

// 1.3 — Add another module's registrations
$ts->addPendingRegistration('ecommerce', ['ecommerce.order.placed']);
$pending = $ts->getPendingRegistrations();
t('pending contains both modules', isset($pending['cms'], $pending['ecommerce']));
t('ecommerce has 1 event', count($pending['ecommerce'] ?? []) === 1);

// 1.4 — consumePendingRegistrations returns and clears
$consumed = $ts->consumePendingRegistrations();
t('consume returns 2 entries', count($consumed) === 2);
t('consume includes cms', isset($consumed['cms']));
t('consume includes ecommerce', isset($consumed['ecommerce']));
t('pending empty after consume', $ts->getPendingRegistrations() === []);

// 1.5 — Second consume on empty state returns empty
$consumed2 = $ts->consumePendingRegistrations();
t('second consume returns empty', $consumed2 === []);

echo "\n";

// ── Section 2: Trigger Cache ──────────────────────────────────────────────
echo "── Trigger Cache ──\n";

$ts2 = new TriggerService();

// 2.1 — No cache initially
t('no cache for unknown event', $ts2->getCachedTriggers('cms.content.published') === null);
t('hasCachedTriggers returns false for unknown', $ts2->hasCachedTriggers('cms.content.published') === false);

// 2.2 — Cache and retrieve triggers
$triggers = [
    ['capability' => 'sms.send@1', 'module' => 'sms'],
    ['capability' => 'email.send@1', 'module' => 'email'],
];
$ts2->cacheTriggers('cms.content.published', $triggers);
t('hasCachedTriggers after cache', $ts2->hasCachedTriggers('cms.content.published') === true);
t('getCachedTriggers returns cached data', $ts2->getCachedTriggers('cms.content.published') === $triggers);
t('getCachedTriggers count', count($ts2->getCachedTriggers('cms.content.published') ?? []) === 2);

// 2.3 — Unknown key still returns null after caching another key
t('unknown key still null after caching other', $ts2->getCachedTriggers('other.event') === null);
t('hasCachedTriggers false for other event', $ts2->hasCachedTriggers('other.event') === false);

// 2.4 — Cache with empty array
$ts2->cacheTriggers('empty.event', []);
t('empty triggers cached', $ts2->getCachedTriggers('empty.event') === []);
t('hasCachedTriggers true for empty', $ts2->hasCachedTriggers('empty.event') === true);

echo "\n";

// ── Section 3: Reset ─────────────────────────────────────────────────────
echo "── Reset ──\n";

$ts3 = new TriggerService();
$ts3->addPendingRegistration('wms', ['wms.stock.low']);
$ts3->cacheTriggers('wms.stock.low', [['capability' => 'email.send@1']]);

t('pending not empty before reset', $ts3->getPendingRegistrations() !== []);
t('cache not empty before reset', $ts3->hasCachedTriggers('wms.stock.low') === true);

$ts3->reset();

t('pending empty after reset', $ts3->getPendingRegistrations() === []);
t('cache empty after reset', $ts3->hasCachedTriggers('wms.stock.low') === false);
t('getCachedTriggers null after reset', $ts3->getCachedTriggers('wms.stock.low') === null);

echo "\n";

// ── Section 4: Overwrite behaviour ───────────────────────────────────────
echo "── Overwrite ──\n";

$ts4 = new TriggerService();
$ts4->addPendingRegistration('guidance', ['guidance.case.created']);
$ts4->addPendingRegistration('guidance', ['guidance.case.created', 'guidance.case.closed']);
t('overwrite replaces previous events', count($ts4->getPendingRegistrations()['guidance'] ?? []) === 2);

$ts4->cacheTriggers('test.event', ['first' => true]);
$ts4->cacheTriggers('test.event', ['second' => true]);
t('cache overwrite replaces previous', ($ts4->getCachedTriggers('test.event')['second'] ?? null) === true);

echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════\n";
printf("  PASS: %d  FAIL: %d\n", $pass, $fail);
echo "══════════════════════════════════════════\n";

exit($fail > 0 ? 1 : 0);
