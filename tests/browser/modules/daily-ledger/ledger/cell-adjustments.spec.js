const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = path.resolve(__dirname, '../../../../daily-ledger/daily_ledger_cell_browser_fixture.php');
const run = mode => execFileSync('php', [fixture, mode], { encoding: 'utf8' });
let scope;
test.setTimeout(90000);
test.beforeEach(() => { scope = JSON.parse(run('setup')); });
test.afterEach(() => { run('cleanup'); });

async function login(page, shift = 'AM') {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill('browser-ledger-cell');
    await page.getByLabel('Full Name', { exact: true }).fill('Browser Ledger Cell');
    await page.getByLabel('Password', { exact: true }).fill('BrowserCell!2031');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/admin/);
    await page.goto(`/daily-ledger/ledger?branch_id=${scope.branch_id}&date=${scope.date}&shift=${shift}`);
    await expect(page.locator('#ledger-body .sales-computed')).toHaveText('105');
}

async function api(page, suffix, payload) {
    return page.evaluate(async ({ suffix, payload }) => {
        const response = await fetch(window.BASE + '/api/v1/cashier/ledger/' + suffix, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json', 'X-CSRF-Token': window.DL_CSRF,
                Authorization: 'Bearer ' + window.DL_TOKEN
            },
            body: JSON.stringify({ branch_id: window.BRANCH_ID, date: window.LEDGER_DATE, shift: window.SHIFT, ...payload }),
        });
        return response.json();
    }, { suffix, payload });
}

test('modal create and edit immediately update counts and sales before the rows refresh, retaining Edit', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    // Hold the secondary GET: the POST response itself must paint the cells.
    let release;
    let refreshed;
    const hold = new Promise(resolve => { release = resolve; });
    await page.route('**/ledger/rows?**', async route => { await hold; await route.continue(); });
    const modal = page.locator('[x-data="withdrawalModal()"]');
    await page.locator('.addtl-trigger').click();
    await modal.locator('[x-model="header.reason_code"]').selectOption('encoder_omission');
    await modal.locator('[x-model="line.quantity"]').fill('7');
    await modal.getByRole('button', { name: 'Save Adjustment' }).click();
    await expect(modal).toBeHidden();
    await expect(page.locator('.addtl-value')).toHaveText('107');
    await expect(page.locator('.sales-computed')).toHaveText('112');
    await expect(page.locator('[data-adj-edit]')).toHaveCount(1);

    // Switch the adjustment from additional to withdrawal; both cells must change.
    await page.locator('[data-adj-edit]').click();
    await modal.locator('[x-model="header.withdrawal_type"]').selectOption('used');
    await modal.locator('[x-model="header.reason_code"]').selectOption('other');
    await modal.locator('[x-model="header.custom_reason"]').fill('Browser correction');
    await modal.locator('[x-model="line.quantity"]').fill('3');
    await modal.getByRole('button', { name: 'Save Adjustment' }).click();
    await expect(modal).toBeHidden();
    await expect(page.locator('.addtl-value')).toHaveText('100');
    await expect(page.locator('.withdraw-total')).toHaveText('8');
    await expect(page.locator('.sales-computed')).toHaveText('102');
    await expect(page.locator('[data-adj-edit]')).toHaveCount(1);
    refreshed = page.waitForResponse(response => response.url().includes('/ledger/rows?'));
    release();
    await refreshed;
    await page.unroute('**/ledger/rows?**');
    await page.reload();
    await expect(page.locator('.sales-computed')).toHaveText('102');
    await expect(page.locator('[data-adj-edit]')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('zero beginning stays zero across refresh; one carry press adopts the AM ending; partial batch preserves other counts', async ({ page }) => {
    await login(page, 'PM');
    expect((await api(page, 'save', { product_id: scope.product_id, field: 'beg_bal', value: 0 })).ok).toBe(true);
    await page.reload();
    // Reading the sheet must not invent a beginning: a reload leaves the saved 0 alone.
    await expect(page.locator('[data-field="beg_bal"]')).toHaveValue('0');
    await expect(page.locator('.sales-computed')).toHaveText('85');
    // The explicit carry press adopts the AM ending for the whole sheet - no per-row link.
    await page.locator('#carry-beginnings-btn').click();
    await expect(page.locator('[data-field="beg_bal"]')).toHaveValue('10');
    await expect(page.locator('.sales-computed')).toHaveText('95');
    expect(Number(JSON.parse(run('read')).find(row => row.shift === 'PM').beg_bal)).toBe(10);
    // The row link stays for a single correction: put the beginning back to 0 and use it.
    expect((await api(page, 'save', { product_id: scope.product_id, field: 'beg_bal', value: 0 })).ok).toBe(true);
    await page.reload();
    await expect(page.locator('[data-field="beg_bal"]')).toHaveValue('0');
    await page.getByRole('button', { name: 'Use AM ending' }).click();
    await expect(page.locator('[data-field="beg_bal"]')).toHaveClass(/saved/);
    await expect(page.locator('.sales-computed')).toHaveText('95');
    expect((await api(page, 'save-batch', { rows: [{ product_id: scope.product_id, bal_end: 20 }] })).ok).toBe(true);
    await page.reload();
    await expect(page.locator('[data-field="beg_bal"]')).toHaveValue('10');
    await expect(page.locator('.addtl-value')).toHaveText('100');
    await expect(page.locator('.withdraw-total')).toHaveText('5');
    await expect(page.locator('.sales-computed')).toHaveText('85');
    const stored = JSON.parse(run('read')).find(row => row.shift === 'PM');
    expect(Number(stored.sales)).toBe(85);
});

test('date navigation refreshes JavaScript scope and box quantities retain Edit after a page refresh', async ({ page }) => {
    await login(page);
    const result = await api(page, 'withdrawals', {
        idempotency_key: 'cell-box-' + Date.now(),
        header: { withdrawal_type: 'used', reason_code: 'testing' },
        lines: [{ product_id: scope.product_id, quantity: 2, unit: 'box' }],
    });
    expect(result.ok).toBe(true);
    await page.evaluate(() => window.refreshMainContent());
    await expect(page.locator('#today-adjustments')).toContainText('2 box (24 pcs)');
    await expect(page.locator('[data-adj-edit]')).toHaveCount(1);
    await page.locator('#ledger-date').fill('2030-03-16');
    await page.locator('#ledger-date').dispatchEvent('change');
    await page.waitForURL(/date=2030-03-16/);
    await expect.poll(() => page.evaluate(() => window.LEDGER_DATE)).toBe('2030-03-16');
    await expect(page.locator('[data-field="bal_end"]')).toHaveValue('');
    await expect(page.locator('.sales-computed')).toHaveText('Pending');
});

test('pullout, charge and signed corrections update withdrawal and daily sales consistently', async ({ page }) => {
    await login(page);
    const modal = page.locator('[x-data="withdrawalModal()"]');
    for (const [type, quantity, withdraw, sales] of [
        ['pullout', '4', '9', '101'],
        ['charge', '2', '11', '99'],
        ['correction', '-3', '8', '102'],
    ]) {
        await page.locator('.withdraw-trigger').click();
        await modal.locator('[x-model="header.withdrawal_type"]').selectOption(type);
        await modal.locator('[x-model="line.quantity"]').fill(quantity);
        await modal.getByRole('button', { name: 'Save Adjustment' }).click();
        await expect(modal).toBeHidden();
        await expect(page.locator('.withdraw-total')).toHaveText(withdraw);
        await expect(page.locator('.sales-computed')).toHaveText(sales);
    }
    await page.reload();
    await expect(page.locator('[data-adj-edit]')).toHaveCount(3);
    await expect(page.locator('.sales-computed')).toHaveText('102');
    const rows = JSON.parse(run('read'));
    expect(Number(rows.find(row => row.shift === 'AM').sales)).toBe(102);
    expect(Number(rows.find(row => row.shift === 'PM').sales)).toBe(105);
});

test('reconciliation repairs both null mismatch directions and is idempotent', () => {
    run('stale-sales');
    const script = path.resolve(__dirname, '../../../../../scripts/daily-ledger-reconcile-sales.php');
    const reconcile = apply => JSON.parse(execFileSync('php', [script, '--tenant=207', '--branch=99471', ...(apply ? ['--apply'] : [])], { encoding: 'utf8' }));
    expect(Number(reconcile(false).before.affected_rows)).toBe(2);
    expect(reconcile(true).updated_rows).toBe(2);
    const rows = JSON.parse(run('read'));
    expect(Number(rows.find(row => row.shift === 'AM').sales)).toBe(105);
    expect(rows.find(row => row.shift === 'PM').sales).toBeNull();
    expect(Number(reconcile(true).before.affected_rows)).toBe(0);
});

// The duplicate guard exists to stop a REPLAY. Keyed on content alone it also stopped a
// legitimate repeat - "another 3 arrived, same reason, same person" - for the rest of the
// day and shift, and taking the first one back did not free it (the correcting -3 is its
// own row). The submission's idempotency key is what tells the two apart.
test('identical Add Stock is recorded on a new submission, and a replay still does not double-apply', async ({ page }) => {
    await login(page);
    const header = { withdrawal_type: 'adjustment_add', reason_code: 'encoder_omission' };
    const lines = [{ product_id: scope.product_id, quantity: 3, unit: 'pcs' }];
    // Unique per run: the API caches a response per idempotency key, so a fixed key would be
    // answered from the previous run's cache and prove nothing about the duplicate guard.
    const tag = 'dedup-spec-' + Date.now();
    const addtlOf = () => Number(JSON.parse(run('read')).find(row => row.shift === 'AM').addtl);
    const before = addtlOf();

    const first = await api(page, 'withdrawals', { idempotency_key: tag + '-A', header, lines });
    expect(first.ok).toBe(true);
    expect(addtlOf(), 'the first entry applies').toBe(before + 3);

    // Same content, NEW submission: a genuine repeat, and it must be recorded.
    const second = await api(page, 'withdrawals', { idempotency_key: tag + '-B', header, lines });
    expect(second.ok).toBe(true);
    expect(second.duplicate, 'a new submission must not be reported as a duplicate').toBeFalsy();
    expect(addtlOf(), 'the repeat applies as well').toBe(before + 6);

    // Same content, SAME submission: replayed, so it must not apply a third time.
    const replay = await api(page, 'withdrawals', { idempotency_key: tag + '-A', header, lines });
    expect(replay.ok).toBe(true);
    expect(addtlOf(), 'a replayed submission must not apply again').toBe(before + 6);
});

// The live case, in the order it happened: add, take it back, then add the same amount for
// real. The take-back is its OWN row, so before the identity fix the original +9 kept owning
// its fingerprint and the third step was refused as "identical withdrawal already recorded"
// for the rest of the day and shift.
test('taking an Add Stock back does not claim the amount forever', async ({ page }) => {
    await login(page);
    const header = { withdrawal_type: 'adjustment_add', reason_code: 'encoder_omission' };
    const amount = 9;
    const tag = 'takeback-spec-' + Date.now();
    const addtlOf = () => Number(JSON.parse(run('read')).find(row => row.shift === 'AM').addtl);
    const before = addtlOf();
    const line = qty => [{ product_id: scope.product_id, quantity: qty, unit: 'pcs' }];

    const added = await api(page, 'withdrawals', { idempotency_key: tag + '-add', header, lines: line(amount) });
    expect(added.ok).toBe(true);
    expect(addtlOf(), 'the entry applies').toBe(before + amount);

    const undone = await api(page, 'withdrawals', { idempotency_key: tag + '-undo', header, lines: line(-amount) });
    expect(undone.ok).toBe(true);
    expect(addtlOf(), 'the take-back puts the ledger back').toBe(before);

    const again = await api(page, 'withdrawals', { idempotency_key: tag + '-again', header, lines: line(amount) });
    expect(again.ok).toBe(true);
    expect(again.duplicate, 'the same amount must be recordable again after a take-back').toBeFalsy();
    expect(addtlOf(), 'and it applies').toBe(before + amount);
});
