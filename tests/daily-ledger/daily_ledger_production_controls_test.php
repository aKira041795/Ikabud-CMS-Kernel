<?php

declare(strict_types=1);

/**
 * Daily Production Sheet — day & shift controls.
 *
 * Acceptance oracle for .ai/daily-ledger-production-controls.contract.md.
 * Every assertion names what a revert would break:
 * - removing the date picker strands production users on today with no way to look back;
 * - removing Close Day leaves the production day closable by nobody but a cashier;
 * - widening the reopen control past admin re-opens the surface the owner restricted;
 * - dropping production_in_charge from the close allowlist re-blocks the requested actor;
 * - removing the PM auto-close leaves an unfinalized PM keeping the day open;
 * - force-finalizing with missing endings destroys the completeness gate;
 * - unlocking a FINALIZED shift while merely viewing the sheet lifts the immutability
 *   boundary with no audited reopen ("day open + PM finalized" is the normal end state).
 *
 * The auto-close section reopens the shift explicitly before the flagged path, because that
 * path only describes an OPEN shift (contract R3). An earlier revision asserted the flagged
 * path against a pre-finalized shift, which contradicted R3 and induced an unlock.
 *
 * All fixtures live on one synthetic product and one throwaway business date and are
 * removed in finally. No tenant setting is written.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-controls', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$productId = 99880;
$producerUserId = 99881;
$adminUserId = 99882;
$branchId = 18;
$date = '2019-03-03';   // a throwaway business date that has already ended

$cleanup = static function () use ($db, $productId, $producerUserId, $adminUserId, $branchId, $date): void {
    // D4 now raises an integrity notification when the auto-close flags a day
    // closed-without-PM-finalize; remove the fixture's rows so the
    // notification-count invariant the tenant suites assert is not polluted.
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE aggregate_key LIKE :k)')->execute([':k' => '%' . $date . '%']);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :k')->execute([':k' => '%' . $date . '%']);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE ledger_date = :d')->execute([':d' => $date]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d')->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d')->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare('DELETE FROM audit_logs WHERE entity_id LIKE :p')->execute([':p' => $branchId . '-' . $date . '%']);
    $db->prepare('DELETE FROM dl_branch_products WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (:a, :b)')->execute([':a' => $producerUserId, ':b' => $adminUserId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (:a, :b)')->execute([':a' => $producerUserId, ':b' => $adminUserId]);
};

$cleanup();

$countFor = static function (string $table, string $column, int|string $value) use ($db): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :v");
    $stmt->execute([':v' => $value]);
    return (int)$stmt->fetchColumn();
};

try {
    // ── Fixtures ────────────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "production_in_charge", "PM", 1)')
        ->execute([':id' => $producerUserId, ':u' => 'fixture-prodctl-pm', ':p' => 'not-a-login-hash', ':n' => 'Fixture Controls Producer']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)')
        ->execute([':u' => $producerUserId, ':b' => $branchId]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "admin", NULL, 1)')
        ->execute([':id' => $adminUserId, ':u' => 'fixture-prodctl-admin', ':p' => 'not-a-login-hash', ':n' => 'Fixture Controls Admin']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99880, 1)')
        ->execute([':id' => $productId, ':sku' => 'FIX-CTL-99880', ':n' => 'Fixture Controls Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)')
        ->execute([':b' => $branchId, ':p' => $productId]);

    $h->test(
        'throwaway business date starts clean (revert cannot be proven on a dirty fixture)',
        $countFor('dl_commissary_product_ledger', 'ledger_date', $date) === 0
            && $countFor('dl_ledger_shift_status', 'ledger_date', $date) === 0
    );

    // One finalized PM shift so an admin render has a reopenable shift.
    $db->prepare(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at)
         VALUES (:b, :d, "PM", "finalized", :u, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = "finalized", finalized_by = VALUES(finalized_by), finalized_at = CURRENT_TIMESTAMP'
    )->execute([':b' => $branchId, ':d' => $date, ':u' => $adminUserId]);

    $producerTokens = dl_generateAuthTokens([
        'sub' => 'production_in_charge:' . $producerUserId,
        'id' => $producerUserId,
        'username' => 'fixture-prodctl-pm',
        'name' => 'Fixture Controls Producer',
        'role' => 'production_in_charge',
        'source' => 'daily-ledger',
    ]);
    $adminTokens = dl_generateAuthTokens([
        'sub' => 'admin:' . $adminUserId,
        'id' => $adminUserId,
        'username' => 'fixture-prodctl-admin',
        'name' => 'Fixture Controls Admin',
        'role' => 'admin',
        'source' => 'daily-ledger',
    ]);

    $render = static function (string $token, array $query): string {
        $_COOKIE[dlCookieName()] = $token;
        $_GET = $query;
        ob_start();
        handleAdminCommissary();
        return (string)ob_get_clean();
    };

    $producerHtml = $render($producerTokens['token'], [
        'date' => $date,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'AM',
    ]);
    $adminHtml = $render($adminTokens['token'], [
        'date' => $date,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'PM',
    ]);

    // ── A. Date picker ──────────────────────────────────────────────────────────
    $h->test(
        'production view renders a date picker bound to the viewed date',
        str_contains($producerHtml, 'id="production-date-picker"')
            && str_contains($producerHtml, 'name="date"')
            && str_contains($producerHtml, 'value="' . $date . '"'),
        json_encode(['date_picker' => str_contains($producerHtml, 'id="production-date-picker"')])
    );

    // ── B. Close Day ────────────────────────────────────────────────────────────
    $h->test(
        'production view offers Close Day to the production user',
        str_contains($producerHtml, 'id="production-close-day"'),
        json_encode(['close_day' => str_contains($producerHtml, 'id="production-close-day"')])
    );
    $h->test(
        'admin view offers Close Day too',
        str_contains($adminHtml, 'id="production-close-day"')
    );

    // ── C. Reopen is admin-only ─────────────────────────────────────────────────
    $h->test(
        'production user is never offered Reopen (revert re-exposes the restricted action)',
        !str_contains($producerHtml, 'id="production-reopen-day"')
    );

    // PARITY (live browser check, 2026-10-04): the cashier ledger always shows an
    // open/closed pill at the head of its action bar. Without it the Close Day button just
    // disappears once the day closes, with nothing saying why.
    $h->test(
        'the sheet shows the day status the way the cashier ledger does (revert removes the only closed-day signal)',
        str_contains($producerHtml, 'id="production-day-status"')
            && str_contains($adminHtml, 'id="production-day-status"')
    );
    // PARITY: the cashier offers Reopen only when the day is CLOSED. Offering it on an open
    // day writes a false closed->open `reopen_day` audit row for a transition that never
    // happened. The day is open here, so Reopen must be absent.
    $h->test(
        'Reopen is NOT offered while the day is open (revert writes a false reopen_day audit row)',
        !str_contains($adminHtml, 'id="production-reopen-day"')
    );

    // The sheet must NAME the commissary it encodes for. The page previously showed the date and
    // the shift but never the hub, so an operator (or an admin viewing "All Commissaries", where
    // the sheet silently targets the first one) had no on-screen confirmation of which commissary
    // the day, the shift and the Close Day / Reopen controls actually belong to.
    $expectedCommissaryName = (string)$db->query('SELECT name FROM dl_branches WHERE id = ' . (int)$branchId)->fetchColumn();
    $h->test(
        'the sheet names the commissary it is encoding for (revert leaves the hub unnamed on screen)',
        $expectedCommissaryName !== ''
            && str_contains($producerHtml, 'id="production-commissary-name"')
            && str_contains($producerHtml, $expectedCommissaryName)
            && str_contains($adminHtml, 'id="production-commissary-name"')
            && str_contains($adminHtml, $expectedCommissaryName),
        json_encode(['expected' => $expectedCommissaryName])
    );

    $db->prepare(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
         VALUES (:b, :d, "closed", :u, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = "closed", closed_by = VALUES(closed_by), closed_at = CURRENT_TIMESTAMP'
    )->execute([':b' => $branchId, ':d' => $date, ':u' => $adminUserId]);
    $adminClosedHtml = $render($adminTokens['token'], [
        'date' => $date,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'PM',
    ]);
    $h->test(
        'admin is offered Reopen once the day is closed (revert leaves a closed day unopenable)',
        str_contains($adminClosedHtml, 'id="production-reopen-day"'),
        json_encode(['reopen_when_closed' => str_contains($adminClosedHtml, 'id="production-reopen-day"')])
    );
    $h->test(
        'Close Day disappears once the day is closed, and the status says so',
        !str_contains($adminClosedHtml, 'id="production-close-day"')
            && str_contains($adminClosedHtml, 'id="production-day-status"')
    );

    // ── D. Close-day actor allowlist ────────────────────────────────────────────
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $closeDayBody = '';
    if (preg_match('/function apiCloseDay\(.*?\n\}/s', $handlersSource, $m)) {
        $closeDayBody = $m[0];
    }
    $h->test(
        'apiCloseDay admits production_in_charge (revert re-blocks the requested actor)',
        $closeDayBody !== '' && str_contains($closeDayBody, "'production_in_charge'")
    );
    $h->test(
        'apiReopenDay still refuses production_in_charge (revert lets the encoder lift a closed day)',
        (bool)preg_match('/function apiReopenDay\(.*?dlCurrentUser\(\[.*?\]\)/s', $handlersSource, $rm)
            && !str_contains($rm[0], 'production_in_charge')
    );
    // SETTLED 2026-10-04 (closes the standing supervisor ruling): reopen authority is the
    // `ledger.override` PERMISSION, not a hard-coded role. In this tenant only admin holds it, so
    // the behaviour is admin-only — but the control and the endpoint now read the same source, so
    // they can never disagree again (hiding a button while the endpoint admits the role is not a
    // control), and the owner keeps the authority configurable in Settings instead of having it
    // baked into the template's render logic.
    $canReopenExpr = '';
    if (preg_match('/\$canReopenDay\s*=\s*([^;]+);/', $handlersSource, $cr)) {
        $canReopenExpr = trim($cr[1]);
    }
    $h->test(
        "the sheet's Reopen control is driven by the ledger.override permission, not a hard-coded role (got: {$canReopenExpr})",
        $canReopenExpr !== ''
            && str_contains($canReopenExpr, 'ledger.override')
            && !preg_match('/^\$?role\s*===?\s*[\'\"]admin[\'\"]$/', $canReopenExpr)
    );

    // ── E. PM auto-close ────────────────────────────────────────────────────────
    if (!function_exists('dl_maybeAutoFinalizeCommissaryPmShift')) {
        $h->fail(
            'PM auto-close evaluator exists (an unfinalized PM otherwise keeps the day open forever)',
            'dl_maybeAutoFinalizeCommissaryPmShift is not defined'
        );
    } else {
        // The render section above finalized the PM shift so an admin view has a reopenable
        // shift. The flagged path only describes an OPEN shift (contract R3), so reopen it
        // here rather than asserting a state the contract never described.
        $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':d' => $date]);

        // D3: the flagged path only fires for a product that MOVED but has no
        // ending. Seed one so the completeness gate is exercised, not a
        // no-movement product that is now (correctly) not a gap.
        $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':d' => $date]);
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (:b, :p, :d, "PM", 5, 0, 0, 0, NULL)')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

        $flagged = dl_maybeAutoFinalizeCommissaryPmShift($branchId, $date, $adminUserId);
        $shiftStatus = $db->prepare('SELECT status, pending_notified_at FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"');
        $shiftStatus->execute([':b' => $branchId, ':d' => $date]);
        $row = $shiftStatus->fetch(PDO::FETCH_ASSOC) ?: [];
        $h->test(
            'incomplete PM endings are FLAGGED, never force-finalized (revert destroys the completeness gate)',
            ($flagged['flagged'] ?? false) === true
                && ($flagged['finalized'] ?? true) === false
                && ($flagged['missing'] ?? 0) > 0
                && (string)($row['status'] ?? '') === 'open'
                && (string)($row['pending_notified_at'] ?? '') !== '',
            json_encode(['flagged' => $flagged, 'row' => $row])
        );
        $auditCount = static function () use ($db, $branchId, $date): int {
            $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = "auto_close_shift" AND entity_id LIKE :p');
            $stmt->execute([':p' => $branchId . '-' . $date . '%']);
            return (int)$stmt->fetchColumn();
        };
        $firstAudit = $auditCount();
        $h->test('a flagged PM auto-close writes exactly one audit row', $firstAudit === 1, 'audit=' . $firstAudit);
        dl_maybeAutoFinalizeCommissaryPmShift($branchId, $date, $adminUserId);
        $h->test('a repeat pass does not duplicate the flag or its audit (idempotent)', $auditCount() === $firstAudit, 'audit=' . $auditCount());

        // A legitimately finalized shift must NOT be unlocked by evaluating it. "Day open +
        // PM finalized" is the NORMAL end-of-day state, so unlocking here silently lifts the
        // immutability boundary with no audited reopen.
        $db->prepare('UPDATE dl_ledger_shift_status SET status = "finalized", finalized_by = :u, finalized_at = CURRENT_TIMESTAMP WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')
            ->execute([':u' => $adminUserId, ':b' => $branchId, ':d' => $date]);
        $onFinalized = dl_maybeAutoFinalizeCommissaryPmShift($branchId, $date, $adminUserId);
        $stillFinalized = $shiftStatus;
        $stillFinalized->execute([':b' => $branchId, ':d' => $date]);
        $finalizedRow = $stillFinalized->fetch(PDO::FETCH_ASSOC) ?: [];
        $h->test(
            'a FINALIZED shift is never unlocked by the evaluator (revert lets a page view lift a signed-off lock)',
            (string)($finalizedRow['status'] ?? '') === 'finalized'
                && ($onFinalized['finalized'] ?? false) === true
                && ($onFinalized['flagged'] ?? true) === false
                && $auditCount() === $firstAudit,
            json_encode(['result' => $onFinalized, 'row' => $finalizedRow, 'audit' => $auditCount()])
        );

        // Satisfy the completeness gate for every active product of the commissary, then
        // re-evaluate from an OPEN shift so the auto-finalize transition is what is proven.
        $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':d' => $date]);
        $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE ledger_date = :d')->execute([':d' => $date]);
        $db->prepare(
            'INSERT INTO dl_commissary_product_ledger
                (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
             SELECT :b, p.id, :d, "PM", 0, 0, 0, 0, 0
               FROM dl_products p
               INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :b2 AND bp.is_active = 1
              WHERE p.is_active = 1'
        )->execute([':b' => $branchId, ':d' => $date, ':b2' => $branchId]);

        $finalized = dl_maybeAutoFinalizeCommissaryPmShift($branchId, $date, $adminUserId);
        $shiftStatus->execute([':b' => $branchId, ':d' => $date]);
        $after = $shiftStatus->fetch(PDO::FETCH_ASSOC) ?: [];
        $h->test(
            'a complete PM closes itself (revert leaves the ended shift open)',
            ($finalized['finalized'] ?? false) === true
                && (string)($after['status'] ?? '') === 'finalized',
            json_encode(['result' => $finalized, 'row' => $after])
        );
        // (d) A DELIBERATELY REOPENED day must not be re-finalised on the next evaluation.
        //     apiReopenDay() sets reopened_at and reopens BOTH shifts precisely so that a signed-off
        //     day can still be corrected. Without this exemption the very next page load re-finalises
        //     the PM shift, so the correction is impossible and the sheet reads day=open while every
        //     cell is locked -- the exact trap the cashier ledger's own day auto-close avoids by
        //     skipping a day with reopened_at (see dl_maybeAutoCloseBranchDay).
        $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL, pending_notified_at = NULL WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':d' => $date]);
        $db->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, reopened_by, reopened_at)
             VALUES (:b, :d, "open", :u, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = "open", reopened_by = VALUES(reopened_by), reopened_at = CURRENT_TIMESTAMP'
        )->execute([':b' => $branchId, ':d' => $date, ':u' => $adminUserId]);
        $afterReopen = dl_maybeAutoFinalizeCommissaryPmShift($branchId, $date, $adminUserId);
        $reopenRowStmt = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"');
        $reopenRowStmt->execute([':b' => $branchId, ':d' => $date]);
        $rowAfterReopen = $reopenRowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $h->test(
            'a deliberately reopened day is NOT re-finalised by the auto-close (revert re-locks a shift the admin just reopened)',
            ($afterReopen['finalized'] ?? true) === false
                && ($afterReopen['flagged'] ?? true) === false
                && (string)($rowAfterReopen['status'] ?? '') === 'open',
            json_encode(['result' => $afterReopen, 'row' => $rowAfterReopen])
        );
    }

    // ─── recorded-entry edit on a reopened day ────────────────────────────────────────────
    // Owner requirement: a closed day reopened by an admin must be editable by the branch's
    // production user, as in the cashier ledger. S7b 3 (an edit of a recorded entry needs
    // production.override) must survive for a day that was NOT deliberately reopened.
    //
    // The refusal is inline in apiSaveCommissaryMaterial(), which ends in $ctx->json(), so the
    // policy is asserted through the predicate the contract requires (R7). This is also the
    // assertion that CATCHES THE HOLE: Close Day never clears reopened_at and never finalizes
    // AM, so "closed day + stale reopened_at + open AM shift" is reachable - measured on
    // tenant 207, branch 18, 2026-10-01 and 2026-10-02.
    $h->section('recorded-entry edit on a reopened day');

    $seam = 'dl_deliberateReopenUnlocksEntryEdit';
    $seamOk = function_exists($seam);
    $h->test(
        'the deliberate-reopen exemption is a testable predicate (' . $seam . ')',
        $seamOk,
        $seamOk ? 'present' : 'MISSING - the exemption is inline, so its policy cannot be asserted'
    );

    if ($seamOk) {
        $setDay = static function (string $status, bool $reopened) use ($db, $branchId, $date, $adminUserId): void {
            $db->prepare(
                'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, reopened_by, reopened_at)
                 VALUES (:b, :d, :s, :u, ' . ($reopened ? 'CURRENT_TIMESTAMP' : 'NULL') . ')
                 ON DUPLICATE KEY UPDATE status = VALUES(status), reopened_by = VALUES(reopened_by),
                     reopened_at = ' . ($reopened ? 'CURRENT_TIMESTAMP' : 'NULL')
            )->execute([':b' => $branchId, ':d' => $date, ':s' => $status, ':u' => $adminUserId]);
        };
        $setShift = static function (string $shift, string $status) use ($db, $branchId, $date): void {
            $db->prepare(
                'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
                 VALUES (:b, :d, :sh, :s)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            )->execute([':b' => $branchId, ':d' => $date, ':sh' => $shift, ':s' => $status]);
        };
        $unlocks = static fn(?string $shift): bool => (bool)$seam($db, $branchId, $date, $shift);

        $setShift('AM', 'open');
        $setShift('PM', 'open');

        // THE HOLE. A closed day that still carries a stale reopened_at must stay protected:
        // Close Day does not clear that column, and AM is never finalized by Close Day.
        $setDay('closed', true);
        $h->test(
            'a CLOSED day stays protected despite a stale reopened_at and an unfinalized AM shift',
            $unlocks('AM') === false,
            'allowed=' . var_export($unlocks('AM'), true)
                . ' (a true here lets a producer write to a closed day)'
        );

        // The owner's requirement: the deliberate reopen IS the authorisation, as in the
        // cashier ledger, where reopening lifts the lock.
        $setDay('open', true);
        $h->test(
            'a deliberately reopened OPEN day unlocks the entry for the branch producer (AM)',
            $unlocks('AM') === true,
            'allowed=' . var_export($unlocks('AM'), true)
        );
        $h->test(
            'a deliberately reopened OPEN day unlocks the entry for the branch producer (PM)',
            $unlocks('PM') === true,
            'allowed=' . var_export($unlocks('PM'), true)
        );

        // S7b 3 preserved: an ordinary open day is not an authorisation.
        $setDay('open', false);
        $h->test(
            'an ordinary OPEN day does NOT unlock a recorded entry (S7b 3 preserved)',
            $unlocks('AM') === false,
            'allowed=' . var_export($unlocks('AM'), true)
        );

        // A finalized shift stays immutable even on a reopened day.
        $setDay('open', true);
        $setShift('PM', 'finalized');
        $h->test(
            'a FINALIZED shift stays immutable even on a deliberately reopened day',
            $unlocks('PM') === false,
            'allowed=' . var_export($unlocks('PM'), true)
        );
        $setShift('PM', 'open');

        // An unresolved shift cannot be judged, so it must refuse (the sheet is
        // reference-only in that state: production_reference_only when $shift === null).
        $h->test(
            'an unresolved shift (null) is never unlocked',
            $unlocks(null) === false,
            'allowed=' . var_export($unlocks(null), true)
        );

        // A brand-new day with no day row at all reads 'open' but was never reopened, so it
        // must not unlock either - otherwise every untouched day would become editable.
        $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d')
            ->execute([':b' => $branchId, ':d' => $date]);
        $h->test(
            'a day with no day-status row is not treated as reopened',
            $unlocks('AM') === false,
            'allowed=' . var_export($unlocks('AM'), true)
        );
    }

    // ─── PM-close failure guidance (cashier-ledger parity) ────────────────────────────────
    // The cashier ledger answers a failed PM close with a PERSISTENT panel naming the blocking
    // products, and warns when the previous business day is still open on an unfinalized PM.
    // The production sheet had neither: only a toast, and the names jammed into the message
    // string by the handler (/tmp 20 at that). The prior-pending rule is date-driven, so its
    // decision is asserted through the predicate the contract requires (R5) - which also keeps
    // the test off the real business dates.
    $h->section('PM-close failure guidance');

    $priorSeam = 'dl_priorPendingPmDay';
    $priorOk = function_exists($priorSeam);
    $h->test(
        'the prior-pending-PM rule is a testable predicate (' . $priorSeam . ')',
        $priorOk,
        $priorOk ? 'present' : 'MISSING - the rule is date-driven, so it cannot be asserted off real dates'
    );

    if ($priorOk) {
        // A throwaway pair: "today" is the day AFTER the throwaway ledger date, so the prior
        // date the rule inspects is the fixture date the cleanup already owns.
        $today = '2019-03-04';
        $setPriorDay = static function (?string $status) use ($db, $branchId, $date, $adminUserId): void {
            if ($status === null) {
                $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d')
                    ->execute([':b' => $branchId, ':d' => $date]);
                return;
            }
            $db->prepare(
                'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
                 VALUES (:b, :d, :s, :u, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            )->execute([':b' => $branchId, ':d' => $date, ':s' => $status, ':u' => $adminUserId]);
        };
        $setPriorShift = static function (string $status) use ($db, $branchId, $date): void {
            $db->prepare(
                'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
                 VALUES (:b, :d, "PM", :s)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            )->execute([':b' => $branchId, ':d' => $date, ':s' => $status]);
        };
        $prior = static fn(string $viewed): ?string => $priorSeam($db, $branchId, $today, $viewed);

        // The state the banner exists for: yesterday still open, its PM never finalized.
        $setPriorDay('open');
        $setPriorShift('open');
        $h->test(
            'a prior business day left open on an unfinalized PM is reported (the banner case)',
            $prior($today) === $date,
            'got=' . var_export($prior($today), true) . ' expected=' . $date
        );

        // Viewing that very date is not "prior" - no self-reference.
        $h->test(
            'the viewed date itself is never reported as a prior pending day',
            $prior($date) === null,
            'got=' . var_export($prior($date), true)
        );

        // A finalized prior PM is not pending.
        $setPriorShift('finalized');
        $h->test(
            'a finalized prior PM is not reported (revert nags about a signed-off shift)',
            $prior($today) === null,
            'got=' . var_export($prior($today), true)
        );

        // A closed prior day is not pending either - the day lock is what matters.
        $setPriorShift('open');
        $setPriorDay('closed');
        $h->test(
            'a CLOSED prior day is not reported even with an unfinalized PM shift',
            $prior($today) === null,
            'got=' . var_export($prior($today), true)
        );

        // No day row at all reads 'open' from dl_getDayStatus; it must still be treated as
        // pending, because that is exactly the never-started day the operator must recover.
        $setPriorDay(null);
        $h->test(
            'a prior day with no day-status row is still reported as pending',
            $prior($today) === $date,
            'got=' . var_export($prior($today), true)
        );
    }

    // The rendered sheet, on the REAL business date, must agree with the predicate - so this
    // asserts the template honours the rule without depending on what the live state happens
    // to be, and without writing to any real date.
    $bizDate = dl_businessDate();
    $expectedPrior = $priorOk ? $priorSeam($db, $branchId, $bizDate, $bizDate) : null;
    $guidanceHtml = $render($adminTokens['token'], [
        'date' => $bizDate,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'PM',
    ]);
    $bannerShown = str_contains($guidanceHtml, 'PM ending pending for ');
    $h->test(
        'the prior-pending banner renders exactly when the rule reports a pending prior PM day',
        $bannerShown === ($expectedPrior !== null),
        json_encode(['banner_shown' => $bannerShown, 'expected_prior' => $expectedPrior, 'business_date' => $bizDate])
    );

    // The persistent panel is the whole point of the port: a failed close must land somewhere
    // that stays on screen, not only in a transient toast.
    $h->test(
        'the production sheet renders the persistent PM-close failure panel (role=alert)',
        str_contains($guidanceHtml, 'id="finalize-pm-result"')
            && (bool)preg_match('/id="finalize-pm-result"[^>]*role="alert"/s', $guidanceHtml),
        'finalize-pm-result=' . (str_contains($guidanceHtml, 'id="finalize-pm-result"') ? 'present' : 'MISSING')
    );

    // ─── flagging a day closed without finalizing it ──────────────────────────────────────
    // Owner principle: data entry is not hampered, it is FLAGGED AND NOTIFIED to admin and user.
    // The flag itself (dl_ledger_shift_status.pending_notified_at) is written by the PM
    // auto-close and reset on reopen, but - measured - read by nothing: the admin management log
    // filters its audit rows to action='production_ledger_change', so an unclosed day was
    // visible on no screen at all. A DAY LEFT UNCLOSED MUST BE VISIBLE, for any date (not only
    // yesterday, which the prior-pending banner already covers) and to BOTH actors.
    //
    // FIXTURE HAZARD, and why the freeze below is load-bearing: handleAdminCommissary runs
    // dl_maybeAutoFinalizeCommissaryPmShift() ON RENDER. With a COMPLETE PM ledger on a past
    // date that evaluator FINALIZES the shift, so the admin's render silently changed the state
    // the following assertions were about to read - which made "flagged + open" and "finalized
    // + stale flag" unsatisfiable together for any stateless predicate. The evaluator's own
    // documented exemption is used to pin the state instead: a day with reopened_at set is
    // "left exactly as the admin left it", so nothing this section seeds is mutated by a render.
    $h->section('a day closed without finalizing is FLAGGED, not silent');

    $flagDate = $date; // the throwaway fixture date - deliberately NOT yesterday
    $setPmShift = static function (string $status) use ($db, $branchId, $flagDate): void {
        $db->prepare(
            'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
             VALUES (:b, :d, "PM", :s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        )->execute([':b' => $branchId, ':d' => $flagDate, ':s' => $status]);
    };
    $setPmFlag = static function (bool $flag) use ($db, $branchId, $flagDate): void {
        $db->prepare(
            'UPDATE dl_ledger_shift_status SET pending_notified_at = '
            . ($flag ? 'CURRENT_TIMESTAMP' : 'NULL')
            . ' WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"'
        )->execute([':b' => $branchId, ':d' => $flagDate]);
    };
    $flagOnRow = static function () use ($db, $branchId, $flagDate): bool {
        $q = $db->prepare(
            'SELECT pending_notified_at FROM dl_ledger_shift_status
              WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"'
        );
        $q->execute([':b' => $branchId, ':d' => $flagDate]);
        $v = $q->fetchColumn();
        return $v !== false && $v !== null && (string)$v !== '';
    };
    $freezeAutoClose = static function (bool $frozen) use ($db, $branchId, $flagDate, $adminUserId): void {
        $db->prepare(
            'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, reopened_by, reopened_at)
             VALUES (:b, :d, "open", :u, ' . ($frozen ? 'CURRENT_TIMESTAMP' : 'NULL') . ')
             ON DUPLICATE KEY UPDATE status = "open", reopened_by = :u2,
                 reopened_at = ' . ($frozen ? 'CURRENT_TIMESTAMP' : 'NULL')
        )->execute([':b' => $branchId, ':d' => $flagDate, ':u' => $adminUserId, ':u2' => $adminUserId]);
    };
    $sheetFor = static fn(string $token): string => $render($token, [
        'date' => $flagDate,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'PM',
    ]);
    $flagMarker = 'id="production-pm-flag"';

    // An INCOMPLETE PM ledger is the state the flagged path exists for; it also keeps the
    // evaluator on its flag branch rather than its finalize branch. D3: the row must
    // carry MOVEMENT or it is not a gap and the evaluator would finalize instead.
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND ledger_date = :d AND shift = "PM"')
        ->execute([':b' => $branchId, ':d' => $flagDate]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (:b, :p, :d, "PM", 5, 0, 0, 0, NULL)')
        ->execute([':b' => $branchId, ':p' => $productId, ':d' => $flagDate]);
    $freezeAutoClose(true);

    // Flagged while still unfinalized: the state the auto-close creates. The banner must name the
    // day and the reason.
    $setPmShift('open');
    $setPmFlag(true);
    $flaggedHtml = $sheetFor($adminTokens['token']);
    $h->test(
        'a flagged day left without finalization is VISIBLE on the sheet, naming the date and the reason',
        str_contains($flaggedHtml, $flagMarker)
            && str_contains($flaggedHtml, $flagDate)
            && stripos($flaggedHtml, 'closed without finalizing') !== false,
        json_encode([
            'marker' => str_contains($flaggedHtml, $flagMarker),
            'date_shown' => str_contains($flaggedHtml, $flagDate),
            'reason_shown' => stripos($flaggedHtml, 'closed without finalizing') !== false,
        ])
    );

    // The admin is not the only one who must be told - the operator sees the same sheet.
    $producerFlaggedHtml = $sheetFor($producerTokens['token']);
    $h->test(
        'the operator is flagged too, not only the admin (the sheet is shared)',
        str_contains($producerFlaggedHtml, $flagMarker),
        'producer_marker=' . (str_contains($producerFlaggedHtml, $flagMarker) ? 'present' : 'MISSING')
    );

    // No flag on the row and a frozen evaluator => nothing to report; the prior-pending rule
    // owns the "still open yesterday" case, this banner must not duplicate it.
    $setPmFlag(false);
    $h->test(
        'an unflagged shift does not raise the flag banner',
        !str_contains($sheetFor($adminTokens['token']), $flagMarker),
        'marker_absent=' . (!str_contains($sheetFor($adminTokens['token']), $flagMarker) ? 'yes' : 'NO')
    );

    // A finalized shift is a completed day, not a flagged one.
    $setPmShift('finalized');
    $setPmFlag(true);
    $finalizedFlagged = $sheetFor($adminTokens['token']);
    $h->test(
        'a FINALIZED shift never shows the flag banner, even with a stale flag on the row',
        !str_contains($finalizedFlagged, $flagMarker),
        'marker_absent=' . (!str_contains($finalizedFlagged, $flagMarker) ? 'yes' : 'NO')
    );

    // THE NOTIFICATION MUST NOT WAIT FOR A RELOAD. With the evaluator free to run, the very
    // render that flags the day has to notify on that render - reading the flag before the
    // evaluator would leave the admin looking at an unclosed day with no warning until refresh.
    $setPmShift('open');
    $setPmFlag(false);
    $freezeAutoClose(false);
    $firstSight = $sheetFor($adminTokens['token']);
    $h->test(
        'the render that flags the day also notifies on that render (no reload required)',
        $flagOnRow() && str_contains($firstSight, $flagMarker),
        json_encode(['flag_now_on_row' => $flagOnRow(), 'banner_shown' => str_contains($firstSight, $flagMarker)])
    );

    // ─── smart settlement of an unfinalized shift ─────────────────────────────────────────
    // Owner: "i want the daily ledger to be also smart", for BOTH sheets, accepting the chair's
    // two caveats: a derived ending must never be indistinguishable from a COUNTED one (C1), and
    // rule 3 is refused when the next beginning was carried, because then it is a copy of the very
    // ending being estimated (C2).
    //
    // The ladder is asserted as a pure truth table so it needs no fixtures and no browser: the
    // helper takes the counted ending, whether the shift is finalized, the movements computed by
    // the CALLER's own invariant, and (for rule 3) the next beginning plus whether that beginning
    // is independent.
    $h->section('settling a shift nobody finalized');

    $settle = 'dl_settleUnfinalizedRow';
    $settleOk = function_exists($settle);
    $h->test(
        'the settlement ladder is a testable pure predicate (' . $settle . ')',
        $settleOk,
        $settleOk ? 'present' : 'MISSING - the decision is not exposed, so none of its rules can be asserted'
    );

    if ($settleOk) {
        $row = static fn(?int $end, bool $fin, int $mv, ?int $next = null, bool $indep = false): array
            => (array)$settle($end, $fin, $mv, $next, $indep);
        $describe = static fn(array $r): string => json_encode($r);

        // A counted ending is a count. With movements 100 and an ending of 30 the invariant gives
        // 70 sold, and a SIGNED-OFF shift is official.
        $signed = $row(30, true, 100);
        $h->test(
            'a counted ending on a finalized shift is official, with sales from the invariant',
            ($signed['rung'] ?? '') === 'counted'
                && (int)($signed['ending'] ?? -1) === 30
                && (int)($signed['sales'] ?? -1) === 70
                && ($signed['official'] ?? false) === true,
            $describe($signed)
        );

        // The same count on an UNSIGNED shift is not official - it is provisional, and the number
        // is unchanged. A derived number must never be more trusted than this, less trusted.
        $unsigned = $row(30, false, 100);
        $h->test(
            'the same count on an unfinalized shift is counted-unsigned and NOT official',
            ($unsigned['rung'] ?? '') === 'counted-unsigned'
                && (int)($unsigned['sales'] ?? -1) === 70
                && ($unsigned['official'] ?? true) === false,
            $describe($unsigned)
        );

        // A RECORDED ZERO is a count, not a missing value. It must never be mistaken for the
        // zero-forced rung, which exists only for a NULL ending.
        $zeroCounted = $row(0, true, 100);
        $h->test(
            'a recorded ZERO ending is a count (sales 100), never confused with zero-forced',
            ($zeroCounted['rung'] ?? '') === 'counted'
                && (int)($zeroCounted['sales'] ?? -1) === 100
                && ($zeroCounted['official'] ?? false) === true,
            $describe($zeroCounted)
        );

        // Rule 2: nobody counted, but stock moved. The ending is DEFINED as the movements so the
        // shift settles at zero sales - labelled, never official.
        $derived = $row(null, false, 100);
        $h->test(
            'rule 2: an uncounted ending with movements settles to ending=movements, sales=0, not official',
            ($derived['rung'] ?? '') === 'derived-from-movements'
                && (int)($derived['ending'] ?? -1) === 100
                && (int)($derived['sales'] ?? -1) === 0
                && ($derived['official'] ?? true) === false,
            $describe($derived)
        );

        // Rule 1. With every movement zero the invariant is max(0, 0 - bal_end) = 0 for ANY
        // non-negative ending, so this is arithmetic rather than an assumption.
        $forced = $row(null, false, 0);
        $h->test(
            'rule 1: an uncounted ending with all movements zero settles to sales 0',
            ($forced['rung'] ?? '') === 'zero-forced'
                && (int)($forced['sales'] ?? -1) === 0
                && ($forced['official'] ?? true) === false,
            $describe($forced)
        );

        // Rule 3: the next shift's independently counted beginning IS evidence of this ending.
        $fromNext = $row(null, false, 100, 40, true);
        $h->test(
            'rule 3: an INDEPENDENT next beginning supplies the missing ending',
            ($fromNext['rung'] ?? '') === 'derived-next-beginning'
                && (int)($fromNext['ending'] ?? -1) === 40
                && (int)($fromNext['sales'] ?? -1) === 60
                && ($fromNext['official'] ?? true) === false,
            $describe($fromNext)
        );

        // THE CIRCULARITY GUARD (caveat C2). When that beginning was CARRIED it is a copy of the
        // missing ending, so using it would be estimating a value from itself. It must fall
        // through to rule 2 - the same answer as having no next beginning at all.
        $carried = $row(null, false, 100, 40, false);
        $h->test(
            'rule 3 is REFUSED when the next beginning was carried (it is a copy of the missing ending)',
            ($carried['rung'] ?? '') !== 'derived-next-beginning'
                && ($carried['rung'] ?? '') === 'derived-from-movements'
                && (int)($carried['ending'] ?? -1) === 100
                && (int)($carried['sales'] ?? -1) === 0,
            $describe($carried)
        );
    }

    // R5: the report's provisional rule is inline inside dl_reportSalesData(), so it is asserted
    // through the predicate the contract requires. MEASURED DEFECT: an AM row with a recorded
    // ending on an unfinalized AM shift satisfies neither clause today, so unsigned AM sales land
    // in the OFFICIAL total.
    $prov = 'dl_rowIsProvisional';
    $provOk = function_exists($prov);
    $h->test(
        'the provisional rule is a testable predicate (' . $prov . ')',
        $provOk,
        $provOk ? 'present' : 'MISSING - the report buckets inline, so unsigned AM sales cannot be asserted'
    );

    if ($provOk) {
        $h->test(
            'an unfinalized AM shift with a recorded ending is provisional, not official',
            (bool)$prov(['bal_end' => 30, 'shift' => 'AM', 'shift_status' => 'open']) === true,
            'AM/open with a recorded ending must not be official'
        );
        $h->test(
            'an unfinalized PM shift with a recorded ending is provisional',
            (bool)$prov(['bal_end' => 30, 'shift' => 'PM', 'shift_status' => 'open']) === true,
            'PM/open'
        );
        $h->test(
            'a FINALIZED shift with a recorded ending is not provisional',
            (bool)$prov(['bal_end' => 30, 'shift' => 'AM', 'shift_status' => 'finalized']) === false,
            'AM/finalized must be official'
        );
        $h->test(
            'a missing ending is provisional whatever the shift',
            (bool)$prov(['bal_end' => null, 'shift' => 'AM', 'shift_status' => 'finalized']) === true
                && (bool)$prov(['bal_end' => null, 'shift' => 'AM', 'shift_status' => 'open']) === true,
            'NULL ending'
        );

        // THE NO-SHIFT-ROW CASE, and why it is pinned: a MISSING dl_ledger_shift_status row is
        // ambiguous - it can mean "never finalized" OR "this shift was never tracked at all".
        // Measured on tenant 207: 3,149 AM rows have a recorded ending and NO shift row (20,680
        // units), while PM has only 46 such rows (1,602 units). Treating "no row" as unfinalized
        // for BOTH shifts would move 20,680 units of AM sales out of the official total - a
        // restatement of history on the strength of an ambiguity, NOT a fix.
        // So the historical bucketing is PRESERVED exactly for the no-row case (PM provisional,
        // AM official), and the fix applies only where the data is unambiguous: a shift row that
        // EXISTS and says not-finalized.
        $h->test(
            'AM with NO shift-status row keeps its historical official bucket (no restatement)',
            (bool)$prov(['bal_end' => 30, 'shift' => 'AM']) === false,
            'AM/no-row must stay official - 3,149 rows / 20,680 units hang on this'
        );
        $h->test(
            'PM with NO shift-status row keeps its historical provisional bucket (no restatement)',
            (bool)$prov(['bal_end' => 30, 'shift' => 'PM']) === true,
            'PM/no-row was already provisional'
        );
    }

    // ─── settle -> verify for finality -> revert ─────────────────────────────────────────
    // Owner: "move it but allow admin to verify for finality. that will solve the irreversible
    // status". The settled ending IS written into the counted column - but tagged, provisional
    // while unverified, and REVERSIBLE, which is what makes writing it acceptable. The guarantee
    // that survives is caveat C1: a derived ending must never be indistinguishable from a count.
    $h->section('settle, verify for finality, revert');

    $settleShift = 'dl_settlePendingEndingsForShift';
    $verifyShift = 'dl_verifySettledEndingsForShift';
    $revertShift = 'dl_revertSettledEndingsForShift';
    $lifecycleOk = function_exists($settleShift) && function_exists($verifyShift) && function_exists($revertShift);
    $h->test(
        'the settle/verify/revert lifecycle is exposed as testable services',
        $lifecycleOk,
        $lifecycleOk ? 'present' : 'MISSING - the derived write cannot be asserted without them'
    );

    if ($lifecycleOk) {
        $admin = ['id' => $adminUserId, 'role' => 'admin'];
        $producer = ['id' => $producerUserId, 'role' => 'production_in_charge'];

        // An OPEN PM shift (so settling is eligible) with the fixture ledger row PENDING.
        $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
                      VALUES (:b, :d, "PM", "open")
                      ON DUPLICATE KEY UPDATE status = "open"')
            ->execute([':b' => $branchId, ':d' => $date]);
        $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND ledger_date = :d')
            ->execute([':b' => $branchId, ':d' => $date]);
        $db->prepare('INSERT INTO dl_commissary_product_ledger
                        (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty,
                         dispatched_qty, wastage_qty, actual_end_qty)
                      VALUES (:b, :p, :d, "PM", 10, 0, 0, 0, NULL)')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
        $readRow = static function () use ($db, $branchId, $productId, $date): array {
            $q = $db->prepare('SELECT actual_end_qty, end_source, end_settled_at, end_verified_by, calc_variance
                               FROM dl_commissary_product_ledger
                               WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"');
            $q->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
            return (array)($q->fetch(PDO::FETCH_ASSOC) ?: []);
        };
        $auditCount = static function (string $action) use ($db, $branchId, $date): int {
            $q = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE module = "daily-ledger"
                               AND action = :a AND branch_id = :b AND created_at >= (NOW() - INTERVAL 1 HOUR)');
            $q->execute([':a' => $action, ':b' => $branchId]);
            return (int)$q->fetchColumn();
        };

        // SETTLE: movements = 10 + 0 - 0 - 0 = 10, so rule 2 settles the ending to 10.
        $settled = dl_settlePendingEndingsForShift($db, $branchId, $date, 'PM', $producer, true);
        $afterSettle = $readRow();
        $h->test(
            'settle writes the derived ending AND tags its provenance',
            (int)($afterSettle['actual_end_qty'] ?? -1) === 10
                && (string)($afterSettle['end_source'] ?? '') === 'derived-from-movements'
                && ($afterSettle['end_settled_at'] ?? null) !== null
                && (int)($settled['settled'] ?? 0) === 1,
            json_encode(['result' => $settled, 'row' => $afterSettle])
        );

        // THE C1 GUARANTEE: a settled row is provisional, i.e. it can never be official before a
        // human certifies it, even though an ending is now present.
        $h->test(
            'a settled row reads PROVISIONAL, never official, before verification',
            (bool)dl_rowIsProvisional([
                'bal_end' => 10, 'shift' => 'PM', 'shift_status' => 'open',
                'end_source' => (string)$afterSettle['end_source'],
            ]) === true,
            'end_source=' . (string)($afterSettle['end_source'] ?? '<none>')
        );

        // M4/R6b: a settle must not silence the variance signal by manufacturing a zero variance.
        $h->test(
            'a settled row manufactures NO variance (the "nobody counted this" signal survives)',
            ($afterSettle['calc_variance'] ?? null) === null,
            'calc_variance=' . var_export($afterSettle['calc_variance'] ?? null, true)
        );

        // A COUNTED ending is never overwritten by a settle.
        $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 50, end_source = NULL
                      WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
        $auditsBefore = $auditCount('settle_derived_ending');
        $again = dl_settlePendingEndingsForShift($db, $branchId, $date, 'PM', $producer, true);
        $afterNoop = $readRow();
        $h->test(
            'settle never overwrites a counted ending and is idempotent (no write, no audit)',
            (int)($afterNoop['actual_end_qty'] ?? -1) === 50
                && ($afterNoop['end_source'] ?? null) === null
                && (int)($again['settled'] ?? -1) === 0
                && $auditCount('settle_derived_ending') === $auditsBefore,
            json_encode(['row' => $afterNoop, 'again' => $again, 'audit_same' => $auditCount('settle_derived_ending') === $auditsBefore])
        );

        // Back to a derived row for the verify/revert half.
        $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 10,
                        end_source = "derived-from-movements", end_settled_at = NOW(), end_verified_by = NULL
                      WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

        // REVERT is the irreversibility guarantee: an unverified derived row returns to pending.
        $reverted = dl_revertSettledEndingsForShift($db, $branchId, $date, 'PM', $admin, true);
        $afterRevert = $readRow();
        $h->test(
            'revert returns an UNVERIFIED derived row to pending (nothing is irreversible)',
            ($afterRevert['actual_end_qty'] ?? null) === null
                && ($afterRevert['end_source'] ?? null) === null
                && (int)($reverted['reverted'] ?? 0) === 1,
            json_encode(['result' => $reverted, 'row' => $afterRevert])
        );

        // VERIFY is admin-only, and refusal must change nothing.
        $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 10,
                        end_source = "derived-from-movements", end_settled_at = NOW(), end_verified_by = NULL
                      WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
        $refused = false;
        try {
            dl_verifySettledEndingsForShift($db, $branchId, $date, 'PM', $producer, true);
        } catch (\Throwable $e) {
            $refused = true;
        }
        $afterRefusal = $readRow();
        $h->test(
            'verify is refused for a non-admin, and the refusal changes NOTHING',
            $refused
                && (string)($afterRefusal['end_source'] ?? '') === 'derived-from-movements'
                && ($afterRefusal['end_verified_by'] ?? null) === null,
            json_encode(['refused' => $refused, 'row' => $afterRefusal])
        );

        // VERIFY promotes the same NUMBER to a count - a certification, not a recomputation.
        $verified = dl_verifySettledEndingsForShift($db, $branchId, $date, 'PM', $admin, true);
        $afterVerify = $readRow();
        $h->test(
            'an admin verify promotes a derived row to a COUNT without changing the number',
            (int)($afterVerify['actual_end_qty'] ?? -1) === 10
                && ($afterVerify['end_source'] ?? null) === null
                && (int)($afterVerify['end_verified_by'] ?? 0) === $adminUserId
                && (int)($verified['verified'] ?? 0) === 1,
            json_encode(['result' => $verified, 'row' => $afterVerify])
        );

        // A certified count is a separate, deliberate thing - revert must NOT touch it.
        $revertVerified = dl_revertSettledEndingsForShift($db, $branchId, $date, 'PM', $admin, true);
        $afterRevertVerified = $readRow();
        $h->test(
            'revert does NOT undo an admin-verified count (certified means certified)',
            (int)($afterRevertVerified['actual_end_qty'] ?? -1) === 10
                && (int)($afterRevertVerified['end_verified_by'] ?? 0) === $adminUserId
                && (int)($revertVerified['reverted'] ?? -1) === 0,
            json_encode(['result' => $revertVerified, 'row' => $afterRevertVerified])
        );
    }

    // ─── the admin finality control must be VISIBLE, and admin-only ───────────────────────
    // An admin cannot certify what they cannot see, so the counts are asserted exactly through the
    // panel's data attributes. And the verify/revert controls must be ABSENT (not merely disabled)
    // for a non-admin: otherwise the person who benefits from an assumption is offered the button
    // that certifies it.
    $h->section('the settle/verify control surface');

    // One derived, unverified ending on the fixture shift.
    $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
                  VALUES (:b, :d, "PM", "open") ON DUPLICATE KEY UPDATE status = "open"')
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare('UPDATE dl_commissary_product_ledger
                     SET actual_end_qty = 10, end_source = "derived-from-movements",
                         end_settled_at = NOW(), end_verified_by = NULL
                   WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"')
        ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

    $panelFor = static fn(string $token): string => $render($token, [
        'date' => $date,
        'commissary_id' => (string)$branchId,
        'branch_id' => '',
        'shift' => 'PM',
    ]);
    $panelCounts = static function (string $html): array {
        if (!preg_match('/id="settled-panel"[^>]*data-pending="(\d+)"[^>]*data-unverified="(\d+)"[^>]*data-verified="(\d+)"/s', $html, $m)
            && !preg_match('/id="settled-panel"[^>]*data-verified="(\d+)"[^>]*data-unverified="(\d+)"[^>]*data-pending="(\d+)"/s', $html, $m)) {
            return [];
        }
        return ['pending' => (int)$m[1], 'unverified' => (int)$m[2], 'verified' => (int)$m[3]];
    };

    $adminPanel = $panelFor($adminTokens['token']);
    $adminCounts = $panelCounts($adminPanel);
    $h->test(
        'the sheet renders the settled-endings panel with the pending/unverified/verified counts',
        str_contains($adminPanel, 'id="settled-panel"')
            && ($adminCounts['unverified'] ?? -1) === 1
            && array_key_exists('pending', $adminCounts),
        json_encode($adminCounts)
    );

    $h->test(
        'an ADMIN is offered the verify-for-finality control while endings await verification',
        ($adminCounts['unverified'] ?? 0) > 0
            && str_contains($adminPanel, 'id="verify-settled-endings"')
            && str_contains($adminPanel, 'id="revert-settled-endings"'),
        'verify=' . (str_contains($adminPanel, 'id="verify-settled-endings"') ? 'present' : 'MISSING')
            . ' unverified=' . ($adminCounts['unverified'] ?? 'null')
    );

    $producerPanel = $panelFor($producerTokens['token']);
    $h->test(
        'a NON-ADMIN is never offered the verify or revert control (absent, not disabled)',
        !str_contains($producerPanel, 'id="verify-settled-endings"')
            && !str_contains($producerPanel, 'id="revert-settled-endings"'),
        'verify_present_for_producer=' . (str_contains($producerPanel, 'id="verify-settled-endings"') ? 'YES (leak)' : 'no')
    );

    // With nothing awaiting verification the control is not offered either - so its presence really
    // does track the unverified count rather than the role alone.
    $db->prepare('UPDATE dl_commissary_product_ledger
                     SET end_source = NULL, end_verified_by = :u, end_verified_at = NOW()
                   WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"')
        ->execute([':u' => $adminUserId, ':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $verifiedPanel = $panelFor($adminTokens['token']);
    $verifiedCounts = $panelCounts($verifiedPanel);
    $h->test(
        'nothing awaiting verification means no verify control, and the verified count reflects it',
        ($verifiedCounts['unverified'] ?? -1) === 0
            && ($verifiedCounts['verified'] ?? 0) >= 1
            && !str_contains($verifiedPanel, 'id="verify-settled-endings"'),
        json_encode($verifiedCounts)
    );

    // ─── the lifecycle is scoped to the actor's OWN branches ─────────────────────────────
    // Measured: the production user is assigned to branch 18 ONLY (accessible=1), while the admin
    // reaches 11 branches including 8. Every other write path in this module checks
    // dl_accessibleBranchIds before writing; a settle that did not would let a supervisor or
    // production user craft a POST and write derived endings into a branch they are not assigned
    // to. The branch arrives from the client, so this must be checked server-side.
    $foreignRefused = false;
    try {
        dl_settlePendingEndingsForShift($db, 8, $date, 'PM', ['id' => $producerUserId, 'role' => 'production_in_charge'], true);
    } catch (\Throwable $e) {
        $foreignRefused = true;
    }
    $h->test(
        'settle is refused for a branch the actor cannot access (the branch comes from the client)',
        $foreignRefused,
        'refused=' . var_export($foreignRefused, true) . ' - branch 8 is outside the producer\'s accessible set'
    );
} finally {
    $cleanup();
}

$h->test(
    'fixtures are fully removed (a leaked day row would poison the next run)',
    $countFor('dl_commissary_product_ledger', 'ledger_date', $date) === 0
        && $countFor('dl_ledger_shift_status', 'ledger_date', $date) === 0
        && $countFor('dl_users', 'id', $producerUserId) === 0
);
$h->done();
