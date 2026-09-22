// @ts-check
/**
 * Daily Ledger — "Recompute sales" carries the unrecorded beginnings forward.
 *
 * The per-row "Use previous ending" link carries ONE product by hand. Asking the cashier
 * to open it on every row is the friction this covers: one press of Recompute sales has
 * to carry the preceding shift's ending into every beginning that was never recorded, in
 * a single batch, and leave a beginning somebody actually recorded alone.
 *
 * The sheet is seeded by daily_ledger_recompute_carry_fixture.php on its own branch, so
 * this spec never reads or writes an operational ledger.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = path.resolve(__dirname, '../../../../daily-ledger/daily_ledger_recompute_carry_fixture.php');
const run = mode => execFileSync('php', [fixture, mode], { encoding: 'utf8' });

let scope;
test.setTimeout(90000);
test.beforeEach(() => { scope = JSON.parse(run('setup')); });
test.afterEach(() => { run('cleanup'); });

const rowOf = (page, productId) => page.locator('tr[data-product-id="' + productId + '"]');
const begOf = (page, productId) => rowOf(page, productId).locator('[data-field="beg_bal"]');
const salesOf = (page, productId) => rowOf(page, productId).locator('.sales-computed');
const lastToast = page => page.locator('#toast-container > div').last();

async function login(page) {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill(scope.user);
    await page.getByLabel('Full Name', { exact: true }).fill(scope.full_name);
    await page.getByLabel('Password', { exact: true }).fill(scope.password);
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/admin/);
}

test('one press carries every unrecorded beginning forward and leaves a recorded one alone', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await login(page);
    await page.goto('/daily-ledger/ledger?branch_id=' + scope.branch_id + '&date=' + scope.date + '&shift=PM');
    await expect(page.locator('#ledger-body tr')).toHaveCount(3);

    const [first, second, recorded] = scope.products;

    // Baseline: the two unrecorded beginnings sit at 0 and offer the per-row link, the
    // recorded one keeps its 25 and offers nothing.
    await expect(begOf(page, first.product_id)).toHaveValue('0');
    await expect(begOf(page, second.product_id)).toHaveValue('0');
    await expect(begOf(page, recorded.product_id)).toHaveValue('25');
    await expect(page.getByRole('button', { name: 'Use AM ending' })).toHaveCount(2);
    await expect(salesOf(page, first.product_id)).toHaveText(String(first.sales_before));

    // Reading the sheet is not a write: a reload carries nothing.
    await page.reload();
    await expect(begOf(page, first.product_id)).toHaveValue('0');
    await expect(begOf(page, second.product_id)).toHaveValue('0');

    // The one press.
    await page.locator('#recompute-sales-btn').click();
    await expect(page.locator('#recompute-sales-btn')).toBeEnabled();
    await expect(lastToast(page)).toContainText('Carried 2 beginnings forward from the previous ending.');

    // Each unrecorded beginning now holds the AM ending; sales moved with it.
    await expect(begOf(page, first.product_id)).toHaveValue(String(first.carried));
    await expect(begOf(page, second.product_id)).toHaveValue(String(second.carried));
    await expect(salesOf(page, first.product_id)).toHaveText(String(first.sales_after));
    await expect(salesOf(page, second.product_id)).toHaveText(String(second.sales_after));

    // The recorded beginning was never overwritten, so its sales did not move.
    await expect(begOf(page, recorded.product_id)).toHaveValue('25');
    await expect(salesOf(page, recorded.product_id)).toHaveText(String(recorded.sales_before));

    // Persisted, not painted: the stored beginnings and derived sales agree, and the
    // counts this press did not touch are intact.
    const storedPm = JSON.parse(run('read')).filter(r => r.shift === 'PM');
    for (const product of scope.products) {
        const stored = storedPm.find(r => Number(r.product_id) === product.product_id);
        expect(stored, 'stored PM row for product ' + product.product_id).toBeTruthy();
        expect(Number(stored.beg_bal), 'beginning for product ' + product.product_id).toBe(product.carried);
        expect(Number(stored.sales), 'sales for product ' + product.product_id).toBe(product.sales_after);
        expect(Number(stored.addtl), 'additional for product ' + product.product_id).toBe(scope.pm_addtl);
        expect(Number(stored.withdraw), 'withdrawal for product ' + product.product_id).toBe(scope.pm_withdraw);
        expect(Number(stored.bal_end), 'ending for product ' + product.product_id).toBe(scope.pm_ending);
    }

    // A second press has nothing left to carry.
    await page.locator('#recompute-sales-btn').click();
    await expect(page.locator('#recompute-sales-btn')).toBeEnabled();
    await expect(lastToast(page)).toContainText('Sales already match the saved values.');
    await expect(lastToast(page)).not.toContainText('Carried');
    for (const product of scope.products) {
        await expect(begOf(page, product.product_id)).toHaveValue(String(product.carried));
    }

    // Persisted across a reload, and the per-row links are gone because they are now moot.
    await page.reload();
    await expect(begOf(page, first.product_id)).toHaveValue(String(first.carried));
    await expect(page.getByRole('button', { name: 'Use AM ending' })).toHaveCount(0);

    expect(errors).toEqual([]);
});

test('the pinned bar offers Close Day while open, Reopen Day once closed, and a closed day says why it cannot recompute', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await login(page);
    await page.goto('/daily-ledger/ledger?branch_id=' + scope.branch_id + '&date=' + scope.date + '&shift=PM');
    await expect(page.locator('#ledger-body tr')).toHaveCount(3);

    // The sheet is 174 products long on a real branch, so the day's own action has to be
    // reachable without scrolling: it lives in the pinned bar at the top.
    const bar = page.locator('#ledger-action-bar');
    await expect(bar.locator('#close-day-btn')).toBeVisible();
    await expect(bar.locator('#reopen-day-btn')).toHaveCount(0);

    // Closed day: the same slot switches to the reopen, so nobody scrolls hunting for it.
    run('close');
    await page.reload();
    await expect(bar.locator('#action-bar-day-status')).toHaveText('closed');
    await expect(bar.locator('#reopen-day-btn')).toBeVisible();
    await expect(bar.locator('#close-day-btn')).toHaveCount(0);

    // Recompute sales cannot carry anything into a closed day. It must say so rather than
    // re-read and report success, which reads as "the numbers are already right".
    const [first, second, recorded] = scope.products;
    await page.locator('#recompute-sales-btn').click();
    await expect(lastToast(page)).toContainText('This day is closed');
    await expect(lastToast(page)).toContainText('Reopen Day');
    const afterRefusal = JSON.parse(run('read')).filter(r => r.shift === 'PM');
    for (const product of scope.products) {
        const stored = afterRefusal.find(r => Number(r.product_id) === product.product_id);
        expect(Number(stored.beg_bal), 'a closed day must not carry product ' + product.product_id).toBe(product.pm_beginning);
    }
    await expect(begOf(page, first.product_id)).toHaveValue('0');

    // Reopened: the close is back and the same press now does the carry.
    run('open');
    await page.reload();
    await expect(bar.locator('#action-bar-day-status')).toHaveText('open');
    await expect(bar.locator('#close-day-btn')).toBeVisible();
    await expect(bar.locator('#reopen-day-btn')).toHaveCount(0);

    await page.locator('#recompute-sales-btn').click();
    await expect(page.locator('#recompute-sales-btn')).toBeEnabled();
    await expect(lastToast(page)).toContainText('Carried 2 beginnings forward');
    await expect(begOf(page, first.product_id)).toHaveValue(String(first.carried));
    await expect(begOf(page, second.product_id)).toHaveValue(String(second.carried));
    await expect(begOf(page, recorded.product_id)).toHaveValue('25');

    expect(errors).toEqual([]);
});
