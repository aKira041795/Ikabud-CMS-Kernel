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
