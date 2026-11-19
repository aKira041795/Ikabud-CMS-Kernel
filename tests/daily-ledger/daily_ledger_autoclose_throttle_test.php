<?php

declare(strict_types=1);

/**
 * Daily Ledger — the auto-close sweep must not re-run the same per-branch pass 40 times a minute.
 *
 * MEASURED DEFECT (2026-11-20, tenant 207, 28 active branches): /daily-ledger/admin/dashboard took
 * 2,744 ms and issued 352 queries with auto-close enabled, versus 436 ms and 40 queries with it
 * disabled. The 2.3 s / 312 queries were the auto-close fan-out: ~11 queries per branch, paid again
 * on every dashboard view, because the common outcome ('deferred' - endings still owed) leaves the
 * branch eligible the moment the request finishes.
 *
 * Each assertion names what breaks if it regresses:
 * - TTL 0 must run every pass: that is the pre-throttle behaviour, and the escape hatch if a
 *   throttle ever delays a close that mattered;
 * - a fresh mark must suppress the next pass: without it the dashboard returns to 312 queries;
 * - an EXPIRED mark must permit the pass: otherwise a deferred day could never close again,
 *   which would be a far worse defect than the slowness this fixes;
 * - the throttle is per branch: one branch's recent pass must not silence another branch's close.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-autoclose-throttle', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// Isolate the markers so the suite never disturbs the web user's directory.
$dir = sys_get_temp_dir() . '/dl-autoclose-pass-' . getmypid();
@mkdir($dir, 0777, true);
foreach (glob($dir . '/*') ?: [] as $stale) {
    @unlink($stale);
}
putenv('DL_AUTOCLOSE_PASS_DIR=' . $dir);

echo "auto-close pass throttle\n";

// 1. TTL 0 = no throttle: the pre-2026-11-20 behaviour, always due.
putenv('DL_AUTOCLOSE_PASS_TTL=0');
check('TTL 0 runs every pass (throttle disabled)', dl_autoClosePassDue(4242) === true);
dl_autoClosePassMark(4242);
check('TTL 0 still due immediately after a mark', dl_autoClosePassDue(4242) === true);

// 2. A fresh mark must suppress the next pass.
putenv('DL_AUTOCLOSE_PASS_TTL=60');
dl_autoClosePassMark(4242);
check('fresh mark suppresses the next pass', dl_autoClosePassDue(4242) === false);
check('marker file was created in the override dir', is_file($dir . '/4242.pass'));

// 3. An expired mark must permit the pass, or a deferred day could never close.
$path = $dir . '/4242.pass';
@touch($path, time() - 120);
clearstatcache(true, $path);
check('expired mark permits the pass again', dl_autoClosePassDue(4242) === true, 'mtime=' . @filemtime($path));

// 4. Per branch: another branch's mark must not silence this one.
dl_autoClosePassMark(7777);
check('a different branch is still due', dl_autoClosePassDue(8888) === true);
check('the marked branch is not due', dl_autoClosePassDue(7777) === false);

// 5. Default TTL: unthrottled under CLI (one deliberate run beats a retry storm), throttled for web
//    page loads. Both directions are asserted so neither can silently flip.
putenv('DL_AUTOCLOSE_PASS_TTL');
check('CLI default is unthrottled', dl_autoClosePassTtl() === 0, 'ttl=' . dl_autoClosePassTtl());
check('CLI default therefore allows every pass', dl_autoClosePassDue(4242) === true);
putenv('DL_AUTOCLOSE_PASS_TTL=45');
check('explicit env TTL is honoured', dl_autoClosePassTtl() === 45, 'ttl=' . dl_autoClosePassTtl());
putenv('DL_AUTOCLOSE_PASS_TTL');

// 6. The wiring exists: the sweep must consult the gate, not just define it.
$source = (string)file_get_contents(__DIR__ . '/../../modules/daily-ledger/handlers.php');
$bodyStart = strpos($source, 'function dl_maybeAutoCloseBranchDay(');
$body = $bodyStart === false ? '' : substr($source, $bodyStart, 3000);
check('dl_maybeAutoCloseBranchDay consults dl_autoClosePassDue()', str_contains($body, 'dl_autoClosePassDue('));
check('dl_maybeAutoCloseBranchDay marks the pass', str_contains($body, 'dl_autoClosePassMark('));

foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);
putenv('DL_AUTOCLOSE_PASS_DIR');
putenv('DL_AUTOCLOSE_PASS_TTL');

$total = $passed + $failed;
echo "\n  {$passed}/{$total} passed\n";
$h->done($failed === 0);
exit($failed === 0 ? 0 : 1);
