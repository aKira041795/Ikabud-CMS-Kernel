<?php

declare(strict_types=1);

/**
 * Daily Ledger — the variance review note must be writable, and must survive a
 * status change
 *
 * `dl_variance_flags.review_note` has existed since the original schema and the
 * variances report has always SELECTed it (`helpers/reporting.php`), but nothing ever
 * wrote it. An admin could mark a flag Investigated or Corrected and had nowhere to
 * record what actually happened — which is exactly the note needed when a cashier's
 * count does not line up with the digital ledger, the paper sheet and the cash
 * remitted. The column, the report and the export all existed; only the writer was
 * missing.
 *
 * The contract that matters is present-vs-absent, and it is the reason the note is
 * safe to add to an existing endpoint:
 *
 *   review_note present, non-empty  ->  store it
 *   review_note present, empty      ->  clear it
 *   review_note ABSENT              ->  leave the stored note ALONE
 *
 * Absent must not clear, because `updateVarianceStatus()` and `bulkUpdateStatus()`
 * post only {variance_id, status}. If absent meant "clear", every status change would
 * silently wipe the note an admin had just written. Same present/absent contract the
 * ledger batch save uses for its optional columns.
 *
 * The suite also pins the existence check: UPDATE rowCount() reports CHANGED rows, not
 * matched ones, so re-submitting an identical status inside the same second changed
 * nothing and the old code answered "Variance not found" for a row that exists.
 * Saving a note twice hits that path, so the check is now a separate SELECT.
 *
 * Integration mode — isolated fixtures on branch 99073, full cleanup.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-variance-review-note', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

$db = $dlCtx->db();

$branchId = 99073;
$productId = 99073;
$date = '2031-01-15';

$teardown = static function () use ($db, $branchId, $productId): void {
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
};
$teardown();

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-VARNOTE', ':name' => 'Variance Note Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 10.0, 0, 1)',
    [':id' => $productId, ':sku' => 'VARNOTE-TEST', ':name' => 'Variance Note Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);

/** Seed one flag and return its id. */
$seedFlag = static function () use ($db, $branchId, $productId, $date): int {
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance, is_reviewed, resolution_status)
         VALUES (:b, :p, :d, \'handoff\', \'PM\', 3, 0, \'unreviewed\')',
        [':b' => $branchId, ':p' => $productId, ':d' => $date]
    );
    return (int)$db->lastInsertId();
};

/**
 * The exact statement apiUpdateVarianceStatus runs, so this asserts the shipped SQL
 * rather than a paraphrase of it. $hasNote models whether the key was present in the
 * request body.
 */
$applyUpdate = static function (int $id, string $status, bool $hasNote, string $note) use ($db): void {
    $stmt = $db->prepare(
        'UPDATE dl_variance_flags
         SET resolution_status = :st,
             reviewed_by = :rb,
             reviewed_at = CURRENT_TIMESTAMP,
             is_reviewed = CASE WHEN :st2 = \'unreviewed\' THEN 0 ELSE 1 END,
             review_note = IF(:has_note, :note, review_note)
         WHERE id = :id'
    );
    $stmt->execute([
        ':st' => $status,
        ':st2' => $status,
        ':rb' => 999999,
        ':has_note' => $hasNote ? 1 : 0,
        ':note' => $hasNote ? $note : null,
        ':id' => $id,
    ]);
};

$readNote = static function (int $id) use ($db): ?string {
    $val = $db->query('SELECT review_note FROM dl_variance_flags WHERE id = :id', [':id' => $id])->fetchColumn();
    return $val === false || $val === null ? null : (string)$val;
};
$readRow = static function (int $id) use ($db): array {
    return (array)$db->query(
        'SELECT resolution_status, is_reviewed, review_note FROM dl_variance_flags WHERE id = :id',
        [':id' => $id]
    )->fetch(PDO::FETCH_ASSOC);
};

// ─── The note can actually be written ─────────────────────────────────
$h->section('Writing a review note');

$flagId = $seedFlag();
$h->test('a fresh flag has no note', $readNote($flagId) === null, 'note=' . var_export($readNote($flagId), true));

$applyUpdate($flagId, 'investigated', true, 'cashier short 3 pcs vs paper sheet');
$h->test('a note is stored', $readNote($flagId) === 'cashier short 3 pcs vs paper sheet', (string)$readNote($flagId));

$row = $readRow($flagId);
$h->test('the same call still sets the status', (string)$row['resolution_status'] === 'investigated', json_encode($row));
$h->test('a reviewed status marks the flag reviewed', (int)$row['is_reviewed'] === 1, json_encode($row));

// ─── Absence must PRESERVE, not clear (the whole point of the contract) ─
$h->section('A status-only update must not wipe the note');

$applyUpdate($flagId, 'corrected', false, '');
$h->test(
    'an absent review_note preserves the stored note',
    $readNote($flagId) === 'cashier short 3 pcs vs paper sheet',
    'note=' . var_export($readNote($flagId), true)
);
$h->test('the status-only update still applied', (string)$readRow($flagId)['resolution_status'] === 'corrected');

// ─── Present-and-empty CLEARS, which is how an admin removes a wrong note ─
$h->section('An empty note clears it');

$applyUpdate($flagId, 'corrected', true, '');
$h->test('an empty note clears the stored note', $readNote($flagId) === '', 'note=' . var_export($readNote($flagId), true));

$applyUpdate($flagId, 'investigated', true, 'remitted cash matched after recount');
$h->test('a note can be rewritten after clearing', $readNote($flagId) === 'remitted cash matched after recount', (string)$readNote($flagId));

// ─── Reverting to unreviewed clears the reviewed flag but keeps the note ─
$applyUpdate($flagId, 'unreviewed', false, '');
$row = $readRow($flagId);
$h->test('reverting to unreviewed clears is_reviewed', (int)$row['is_reviewed'] === 0, json_encode($row));
$h->test('reverting to unreviewed keeps the note', (string)$row['review_note'] === 'remitted cash matched after recount', json_encode($row));

// ─── The existence check must be accurate, not rowCount-based ─────────
$h->section('Existence is checked independently of rowCount');

$existsMissing = static function (int $id) use ($db): int {
    $val = $db->query('SELECT id FROM dl_variance_flags WHERE id = :id LIMIT 1', [':id' => $id])->fetchColumn();
    return (int)($val ?: 0);
};
$h->test('a missing id reports 0 from the existence check', $existsMissing(999999999) === 0);
$h->test('an existing id reports itself', $existsMissing($flagId) === $flagId, 'got ' . $existsMissing($flagId));

// The bug the check replaces: an identical re-save changes nothing, so rowCount() is 0
// for a row that is plainly there. Assert the driver really behaves that way, so this
// test fails loudly if the driver ever starts reporting matched rows instead.
$applyUpdate($flagId, 'unreviewed', false, '');
$probe = $db->prepare('UPDATE dl_variance_flags SET resolution_status = :st WHERE id = :id');
$probe->execute([':st' => 'unreviewed', ':id' => $flagId]);
$h->test(
    'an unchanged UPDATE reports rowCount() 0 (so rowCount cannot prove existence)',
    $probe->rowCount() === 0,
    'rowCount=' . $probe->rowCount()
);

// ─── Handler source: the writer and the guard are actually shipped ────
$h->section('The handler ships the note writer and the existence guard');

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$start = strpos($handlersSrc, 'function apiUpdateVarianceStatus(');
$end = strpos($handlersSrc, 'function apiCreateProduct(', $start === false ? 0 : $start);
$fn = ($start === false || $end === false) ? '' : substr($handlersSrc, $start, $end - $start);

$h->test('apiUpdateVarianceStatus was located', $fn !== '' && $start !== false);
$h->test('the handler reads a review_note from the request', str_contains($fn, "array_key_exists('review_note'"));
$h->test('an absent key is distinguished from an empty one', str_contains($fn, '$hasNote ?'));
$h->test('the handler writes review_note', str_contains($fn, 'review_note = IF(:has_note, :note, review_note)'));
$h->test('the note is length-capped', str_contains($fn, 'DL_VARIANCE_NOTE_MAX'));
$h->test('existence is checked with its own SELECT', str_contains($fn, 'SELECT id FROM dl_variance_flags WHERE id = :id LIMIT 1'));
$h->test(
    'the old rowCount-based 404 is gone',
    !str_contains($fn, 'if ($stmt->rowCount() <= 0)'),
    'a rowCount guard would reject a genuine identical re-save'
);

$h->test('the note cap constant exists and is sane', defined('DL_VARIANCE_NOTE_MAX') && DL_VARIANCE_NOTE_MAX >= 100 && DL_VARIANCE_NOTE_MAX <= 4000, 'cap=' . (defined('DL_VARIANCE_NOTE_MAX') ? DL_VARIANCE_NOTE_MAX : 'undefined'));

// ─── Template: the note is editable in BOTH table variants, and escaped ─
$h->section('The variances template exposes the note');

$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/variances.disyl');
$h->test('the note input is rendered', substr_count($tpl, 'data-note-id=') === 2, 'count=' . substr_count($tpl, 'data-note-id=') . ' (grouped + list views)');
$h->test('the note input carries the stored value', substr_count($tpl, 'data-saved=') === 2, 'count=' . substr_count($tpl, 'data-saved='));
$h->test('the save handler is wired to blur', substr_count($tpl, 'onblur="updateVarianceNote(this)"') === 2);
$h->test('updateVarianceNote is defined', str_contains($tpl, 'function updateVarianceNote(input)'));
$h->test('the note is JS-escaped into the value attribute', substr_count($tpl, '{v.review_note | esc_html') >= 2, 'count=' . substr_count($tpl, '{v.review_note | esc_html'));
$h->test('read-only viewers still see the note', str_contains($tpl, '{v.review_note | esc_html}</div>'));
$h->test(
    'a status change sends no review_note key (so it cannot clear the note)',
    str_contains($tpl, 'body: JSON.stringify({ variance_id: parseInt(id), status: status })'),
    'status-only payload must omit the key'
);
$h->test('the note save sends the key', str_contains($tpl, 'review_note: note'));

// DiSyL parses {...} inside an HTML attribute as a TEMPLATE EXPRESSION, so the natural
// inline form -- onkeydown="if(k){a();b();}" -- is silently EATEN and renders as
// "if(k)", which is a JavaScript syntax error. The linter now flags this class, but only
// as a warning for the legacy files, so pin it here: a regression in THIS file must fail.
// (It shipped once: both table variants had it until a render check in the reconciliation
// suite exposed it.)
$h->test(
    'no inline event handler in this template has a statement body',
    preg_match('/\son[a-z]+\s*=\s*"[^"]*\)\s*\{/', $tpl) === 0,
    'DiSyL would eat the braced body and leave invalid JS'
);
$h->test('Enter-to-save is a delegated listener', str_contains($tpl, "addEventListener('keydown'"));

// ─── Reports already read the note, so a written note reaches the export ─
$h->section('The report surfaces the stored note');

$reportingSrc = (string)file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php');
$h->test('the variances report selects review_note', str_contains($reportingSrc, 'vf.review_note'), 'the column was already read; only the writer was missing');

$teardown();
$h->done();
