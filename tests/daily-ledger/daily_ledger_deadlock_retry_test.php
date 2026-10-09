<?php

declare(strict_types=1);

/**
 * The ledger's lock-conflict retry.
 *
 * The save path used to deadlock on a fresh business day: concurrent saves gap-locked the
 * same index supremum and InnoDB killed them with 1213 (reproduced at 40% failures with 10
 * concurrent same-branch saves). Removing the redundant FOR UPDATE took the observed failure
 * rate to zero, but nothing HANDLED a deadlock -- the class of error was made rarer, not
 * survivable. `dl_runWithDeadlockRetry()` closes that gap.
 *
 * This suite proves the retry on real MySQL errors, and proves it refuses to retry the wrong
 * things. Both directions matter: a retry that fires on a constraint violation turns one
 * honest failure into three.
 *
 * Non-destructive: every lock is taken with SELECT ... FOR UPDATE. Nothing is written.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-deadlock-retry', TestHarness::MODE_INTEGRATION, 'baronledger.test');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers.php');

// The retry records every conflict it recovers from. This suite deliberately causes
// conflicts, so those lines are expected here rather than a symptom.
$h->allowLogLines('daily-ledger lock conflict, retrying');

$base = $h->basePath();
require_once $base . '/modules/daily-ledger/helpers.php';

// ── Tenant DB connections (same recipe the load harness uses) ─────────────────
$env = [];
$envPath = $base . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}

/** @return PDO a FRESH connection (never share one across a fork) */
$connect = static function () use ($env): PDO {
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=baronledger;charset=utf8mb4',
            $env['DB_HOST'] ?? 'localhost',
            (int) ($env['DB_PORT'] ?? 3306)
        ),
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
};

try {
    $probe = $connect();
} catch (Throwable $e) {
    $h->test('tenant DB is reachable for lock tests', false, $e->getMessage());
    $h->done();
    exit(1);
}

// Two real rows to lock. SELECT ... FOR UPDATE only, so no row is ever changed.
$ids = $probe->query('SELECT id FROM dl_products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$idA = (int) ($ids[0] ?? 0);
$idB = (int) ($ids[1] ?? 0);
$h->test('two product rows exist to lock (else the deadlock test is vacuous)', $idA > 0 && $idB > 0 && $idA !== $idB);

$lockSql = 'SELECT id FROM dl_products WHERE id = :id FOR UPDATE';

// ── A. Classifier ─────────────────────────────────────────────────────────────
$h->section('The classifier: what counts as retryable');

$mk = static function (int $code) {
    $e = new PDOException('Lock conflict');
    $e->errorInfo = ['40001', $code, 'synthetic'];
    return $e;
};

$h->test('1213 (deadlock found) is retryable', dl_isDeadlockError($mk(1213)) === true);
$h->test('1205 (lock wait timeout) is retryable', dl_isDeadlockError($mk(1205)) === true);
// MUST-REFUSE: retrying these would turn one honest failure into three.
$h->test('1062 (duplicate key) is NOT retryable', dl_isDeadlockError($mk(1062)) === false);
$h->test('1146 (missing table) is NOT retryable', dl_isDeadlockError($mk(1146)) === false);
$h->test('a RuntimeException is NOT retryable', dl_isDeadlockError(new RuntimeException('Day is closed', 403)) === false);
$h->test('an Error is NOT retryable', dl_isDeadlockError(new Error('boom')) === false);

// ── B. A real MySQL lock conflict, one process, two connections ───────────────
$h->section('A REAL lock conflict from MySQL (lock wait timeout)');

$realClassified = false;
$realDriverCode = 0;
try {
    $blocker = $connect();
    $waiter  = $connect();
    $waiter->exec('SET SESSION innodb_lock_wait_timeout = 1');

    $blocker->beginTransaction();
    $bs = $blocker->prepare($lockSql);
    $bs->execute([':id' => $idA]);
    $bs->fetchAll();

    $waiter->beginTransaction();
    $ws = $waiter->prepare($lockSql);
    $ws->execute([':id' => $idA]);   // blocks, then MySQL raises a real error
    $ws->fetchAll();
} catch (Throwable $e) {
    $realDriverCode = (int) ($e->errorInfo[1] ?? 0);
    $realClassified = dl_isDeadlockError($e);
} finally {
    if (isset($waiter) && $waiter->inTransaction()) {
        $waiter->rollBack();
    }
    if (isset($blocker) && $blocker->inTransaction()) {
        $blocker->rollBack();
    }
}

$h->test('MySQL really raised a lock conflict (not a simulated one)', $realDriverCode > 0, 'driver code ' . $realDriverCode);
$h->test('and the classifier recognised it as retryable', $realClassified, 'code ' . $realDriverCode);

// ── C. The retry recovers from a real lock conflict ───────────────────────────
$h->section('The retry recovers (real lock conflict, blocker released between attempts)');

$blocker = $connect();
$worker  = $connect();
$worker->exec('SET SESSION innodb_lock_wait_timeout = 1');

$blocker->beginTransaction();
$bs = $blocker->prepare($lockSql);
$bs->execute([':id' => $idA]);
$bs->fetchAll();

$attempts = 0;
$recovered = false;
$failure = '';
try {
    dl_runWithDeadlockRetry($worker, static function () use ($worker, $blocker, $lockSql, $idA, &$attempts) {
        $attempts++;
        try {
            $worker->beginTransaction();
            $st = $worker->prepare($lockSql);
            $st->execute([':id' => $idA]);
            $st->fetchAll();
            $worker->rollBack();
        } catch (Throwable $e) {
            if ($worker->inTransaction()) {
                $worker->rollBack();
            }
            // Release the blocker so the NEXT attempt can succeed. Without this the
            // retry would exhaust its attempts and prove nothing.
            if ($blocker->inTransaction()) {
                $blocker->rollBack();
            }
            throw $e;
        }
    }, 3);
    $recovered = true;
} catch (Throwable $e) {
    $failure = $e->getMessage();
}
if ($blocker->inTransaction()) {
    $blocker->rollBack();
}

$h->test('the retried work completed', $recovered === true, $failure);
$h->test('it needed exactly two attempts (first blocked, second succeeded)', $attempts === 2, 'attempts=' . $attempts);

// ── D. Bounded: it must give up, not loop forever ─────────────────────────────
$h->section('The retry is BOUNDED (a permanent conflict must fail, not spin)');

$blocker2 = $connect();
$worker2  = $connect();
$worker2->exec('SET SESSION innodb_lock_wait_timeout = 1');

$blocker2->beginTransaction();
$b2 = $blocker2->prepare($lockSql);
$b2->execute([':id' => $idA]);
$b2->fetchAll();

$exhaustAttempts = 0;
$exhausted = false;
try {
    dl_runWithDeadlockRetry($worker2, static function () use ($worker2, $lockSql, $idA, &$exhaustAttempts) {
        $exhaustAttempts++;
        $worker2->beginTransaction();
        $st = $worker2->prepare($lockSql);
        $st->execute([':id' => $idA]);
        $st->fetchAll();
        $worker2->rollBack();
    }, 2);
} catch (Throwable $e) {
    $exhausted = dl_isDeadlockError($e);
}
if ($worker2->inTransaction()) {
    $worker2->rollBack();
}
if ($blocker2->inTransaction()) {
    $blocker2->rollBack();
}

$h->test('it gave up with the lock error rather than spinning', $exhausted === true);
$h->test('it attempted exactly maxAttempts times', $exhaustAttempts === 2, 'attempts=' . $exhaustAttempts);

// ── E. MUST-REFUSE: a real non-lock DB error is never retried ─────────────────
$h->section('MUST-REFUSE: a real non-lock DB error is propagated immediately');

$nonLockAttempts = 0;
$nonLockMessage = '';
try {
    dl_runWithDeadlockRetry($probe, static function () use ($probe, &$nonLockAttempts) {
        $nonLockAttempts++;
        $probe->query('SELECT id FROM dl_table_that_does_not_exist_xyz LIMIT 1');
    }, 3);
} catch (Throwable $e) {
    $nonLockMessage = $e->getMessage();
}

$h->test('the real error surfaced (it did not silently succeed)', $nonLockMessage !== '');
$h->test('and it was tried exactly ONCE — not retried', $nonLockAttempts === 1, 'attempts=' . $nonLockAttempts);

// ── F. A REAL 1213 deadlock, forced across two processes ──────────────────────
$h->section('A REAL 1213 deadlock, produced by MySQL across two processes');

if (!function_exists('pcntl_fork')) {
    $h->skip('real deadlock', 'pcntl not available');
} elseif ($idA <= 0 || $idB <= 0) {
    $h->skip('real deadlock', 'needs two product rows to lock');
} else {
    $tmp = sys_get_temp_dir() . '/dl-deadlock-' . getmypid();
    @unlink($tmp . '-child.sig');
    @unlink($tmp . '-parent.sig');
    @unlink($tmp . '-child.json');

    /**
     * One side of the cycle: lock our own row, tell the peer we hold it, wait until the
     * peer holds its row, then request the peer's row. Both sides doing that forms a cycle,
     * which InnoDB breaks instantly by killing one of them with a real 1213.
     *
     * Records every genuine deadlock it sees, then rethrows so dl_runWithDeadlockRetry does
     * the retrying -- so the count below is observed, not assumed.
     */
    $side = static function (PDO $db, int $ownId, int $peerId, string $mySig, string $peerSig, int &$attempts, int &$deadlocks) use ($lockSql): void {
        $attempts = 0;
        $deadlocks = 0;
        dl_runWithDeadlockRetry($db, static function () use ($db, $ownId, $peerId, $mySig, $peerSig, $lockSql, &$attempts, &$deadlocks) {
            $attempts++;
            try {
                $db->beginTransaction();

                $s1 = $db->prepare($lockSql);
                $s1->execute([':id' => $ownId]);
                $s1->fetchAll();

                touch($mySig);
                $deadline = microtime(true) + 5.0;
                while (!file_exists($peerSig) && microtime(true) < $deadline) {
                    usleep(5000);
                }

                $s2 = $db->prepare($lockSql);
                $s2->execute([':id' => $peerId]);   // the request that closes the cycle
                $s2->fetchAll();

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if (dl_isDeadlockError($e)) {
                    $deadlocks++;
                }
                throw $e;
            }
        }, 4);
    };

    $pid = pcntl_fork();
    if ($pid === -1) {
        $h->skip('real deadlock', 'pcntl_fork failed');
    } elseif ($pid === 0) {
        // CHILD: own row = A, then request B. Connection made AFTER the fork on purpose.
        $out = ['attempts' => 0, 'deadlocks' => 0, 'ok' => false, 'error' => ''];
        try {
            $db = $connect();
            $db->exec('SET SESSION innodb_lock_wait_timeout = 2');
            $a = 0;
            $d = 0;
            $side($db, $idA, $idB, $tmp . '-child.sig', $tmp . '-parent.sig', $a, $d);
            $out['attempts'] = $a;
            $out['deadlocks'] = $d;
            $out['ok'] = true;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        file_put_contents($tmp . '-child.json', json_encode($out));
        // Leave WITHOUT running PHP shutdown. The child inherited the harness, whose shutdown
        // handler would report this fork as an aborted suite ("treat as FAIL") and exit 1 next
        // to a passing run. exec() replaces the process image so no shutdown code runs at all;
        // the child's result is already on disk.
        if (function_exists('pcntl_exec')) {
            @pcntl_exec('/bin/true');
        }
        exit(0);
    }

    // PARENT: own row = B, then request A.
    $parentAttempts = 0;
    $parentDeadlocks = 0;
    $parentOk = false;
    $parentError = '';
    try {
        $dbP = $connect();
        $dbP->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $side($dbP, $idB, $idA, $tmp . '-parent.sig', $tmp . '-child.sig', $parentAttempts, $parentDeadlocks);
        $parentOk = true;
    } catch (Throwable $e) {
        $parentError = $e->getMessage();
    }

    pcntl_waitpid($pid, $status);
    $child = is_file($tmp . '-child.json')
        ? (json_decode((string) file_get_contents($tmp . '-child.json'), true) ?: [])
        : [];
    foreach ([$tmp . '-child.sig', $tmp . '-parent.sig', $tmp . '-child.json'] as $f) {
        @unlink($f);
    }

    $totalDeadlocks = $parentDeadlocks + (int) ($child['deadlocks'] ?? 0);
    $totalAttempts  = $parentAttempts + (int) ($child['attempts'] ?? 0);

    $h->test('MySQL really raised a 1213 on one of the two transactions', $totalDeadlocks >= 1,
        'observed deadlocks=' . $totalDeadlocks . ' (parent=' . $parentDeadlocks . ' child=' . (int) ($child['deadlocks'] ?? 0) . ')');
    $h->test('the losing side was retried and completed', $parentOk === true || ($child['ok'] ?? false) === true,
        $parentError . ' ' . (string) ($child['error'] ?? ''));
    $h->test('BOTH sides completed despite the deadlock', $parentOk === true && ($child['ok'] ?? false) === true,
        'parent_ok=' . var_export($parentOk, true) . ' child_ok=' . var_export($child['ok'] ?? null, true));
    $h->test('the cycle needed more than one attempt overall (someone did retry)', $totalAttempts > 2,
        'total attempts=' . $totalAttempts);
    $h->test('no side exceeded its 4-attempt budget', $parentAttempts <= 4 && (int) ($child['attempts'] ?? 0) <= 4,
        'parent=' . $parentAttempts . ' child=' . (int) ($child['attempts'] ?? 0));
}

// ── G. The save endpoint is wired to it ───────────────────────────────────────
$h->section('The save endpoint actually uses the retry');

$handlers = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
// Match the wiring itself rather than a byte window: the function is ~250 lines and a fixed
// window silently missed the call, which is exactly how a source probe gives a false negative.
$h->test('apiSaveLedgerField wraps its transaction in dl_runWithDeadlockRetry',
    str_contains($handlers, 'dl_runWithDeadlockRetry($ctx->db(), static function () use ($ctx, $branchId'));
$h->test('and its business refusals are still thrown inside for the existing handler',
    str_contains($handlers, 'throw new RuntimeException(dl_closedDayRefusalMessage(), 403);'));

$h->done();
