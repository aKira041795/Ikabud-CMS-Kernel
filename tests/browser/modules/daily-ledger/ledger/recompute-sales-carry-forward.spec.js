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
    await expect(page.locator('#ledger-body tr')).toHaveCount(4);

    const [first, second, recorded, started] = scope.products;

    // Opening the sheet adopts the ending for a shift nobody has started: no row existed for
    // "started", so it arrives filled with no press from anyone. The row that DOES exist
    // holding a deliberate 0 is left alone - that distinction is the whole safety rule.
    await expect(begOf(page, started.product_id)).toHaveValue(String(started.carried));
    await expect(begOf(page, first.product_id)).toHaveValue('0');
    await expect(begOf(page, second.product_id)).toHaveValue('0');
    await expect(begOf(page, recorded.product_id)).toHaveValue('25');
    // A beginning adopted with no ending yet is Pending, not a fabricated 0 sale.
    await expect(salesOf(page, started.product_id)).toHaveText('Pending');
    const adoptedRow = JSON.parse(run('read')).find(r => Number(r.product_id) === started.product_id && r.shift === 'PM');
    expect(adoptedRow, 'opening the sheet must have stored the adopted beginning').toBeTruthy();
    expect(Number(adoptedRow.beg_bal)).toBe(started.carried);
    // sales is derived and NULL while no ending is recorded: an adopted beginning must leave
    // the stored column PENDING, never a fabricated 0 that reports would read as "no sales".
    expect(adoptedRow.sales, 'an adopted beginning with no ending stays pending').toBeNull();

    // Baseline: the two unrecorded beginnings that already had a row still offer the
    // per-row link, the recorded one keeps its 25 and offers nothing.
    await expect(page.getByRole('button', { name: 'Use AM ending' })).toHaveCount(2);
    await expect(salesOf(page, first.product_id)).toHaveText(String(first.sales_before));

    // Re-opening is not a second write: every row that already exists keeps its beginning.
    await page.reload();
    await expect(begOf(page, first.product_id)).toHaveValue('0');
    await expect(begOf(page, second.product_id)).toHaveValue('0');
    await expect(begOf(page, started.product_id)).toHaveValue(String(started.carried));

    // The bar offers the carry by name and count, so the operator can see what one press
    // would do before pressing it.
    const carryBtn = page.locator('#carry-beginnings-btn');
    await expect(carryBtn).toBeVisible();
    await expect(carryBtn).toHaveText('Carry 2 beginnings forward');

    // Recompute sales is a repaint now: pressing it must leave the beginnings alone. A write
    // hiding inside "recompute" is exactly what this split removes.
    await page.locator('#recompute-sales-btn').click();
    await expect(page.locator('#recompute-sales-btn')).toBeEnabled();
    await expect(begOf(page, first.product_id)).toHaveValue('0');
    await expect(lastToast(page)).toContainText('Sales already match the saved values.');

    // The one press.
    await carryBtn.click();
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
    // counts this press did not touch are intact. The adopted row is checked separately -
    // it was created by the open, so it carries no counts and no ending.
    const storedPm = JSON.parse(run('read')).filter(r => r.shift === 'PM');
    for (const product of scope.products.filter(p => p.pm_row_exists)) {
        const stored = storedPm.find(r => Number(r.product_id) === product.product_id);
        expect(stored, 'stored PM row for product ' + product.product_id).toBeTruthy();
        expect(Number(stored.beg_bal), 'beginning for product ' + product.product_id).toBe(product.carried);
        expect(Number(stored.sales), 'sales for product ' + product.product_id).toBe(product.sales_after);
        expect(Number(stored.addtl), 'additional for product ' + product.product_id).toBe(scope.pm_addtl);
        expect(Number(stored.withdraw), 'withdrawal for product ' + product.product_id).toBe(scope.pm_withdraw);
        expect(Number(stored.bal_end), 'ending for product ' + product.product_id).toBe(scope.pm_ending);
    }
    const keptAdopted = storedPm.find(r => Number(r.product_id) === started.product_id);
    expect(Number(keptAdopted.beg_bal), 'the adopted beginning survives the carry').toBe(started.carried);
    expect(Number(keptAdopted.addtl), 'the adopted row keeps no counts of its own').toBe(0);
    expect(keptAdopted.bal_end, 'the adopted row still has no ending').toBeNull();

    // Nothing left to carry, so the bar stops offering it.
    await expect(carryBtn).toBeHidden();
    await expect(page.locator('#carry-beginnings-note')).toBeHidden();

    // And the repaint still stands on its own terms.
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

    // The cashier still owns the number. A correction to an adopted beginning is an ordinary
    // cell edit, and the difference from the reference ending is RECORDED - `handoff` for a
    // PM beginning against the AM ending - so disagreeing with the carry is auditable and not
    // silently swallowed by it.
    const corrected = started.carried + 4;
    const beginningCell = begOf(page, started.product_id);
    const savePosted = page.waitForResponse(r => r.url().includes('/api/v1/cashier/ledger/save') && r.request().method() === 'POST');
    await beginningCell.fill(String(corrected));
    // The cell saves on change, which is what leaving the field does - not on input alone.
    await beginningCell.press('Tab');
    const saveResult = await savePosted;
    expect(saveResult.ok(), 'the corrected beginning must be accepted: ' + (await saveResult.text())).toBe(true);
    await expect(beginningCell).toHaveClass(/saved/);
    await expect.poll(() => {
        const flags = JSON.parse(run('read-variance'))
            .filter(f => Number(f.product_id) === started.product_id && f.kind === 'handoff');
        return flags.length ? Number(flags[0].variance) : null;
    }).toBe(corrected - started.carried);
    await page.reload();
    await expect(begOf(page, started.product_id)).toHaveValue(String(corrected));

    expect(errors).toEqual([]);
});

test('the pinned bar offers Close Day while open, Reopen Day once closed, and names a blocked carry before it is pressed', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await login(page);
    await page.goto('/daily-ledger/ledger?branch_id=' + scope.branch_id + '&date=' + scope.date + '&shift=PM');
    await expect(page.locator('#ledger-body tr')).toHaveCount(4);

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

    // A closed day cannot store a carried beginning, so the bar says so BEFORE the press:
    // the count turns into the reason, and the write affordance is not offered at all.
    await expect(bar.locator('#carry-beginnings-btn')).toBeHidden();
    await expect(bar.locator('#carry-beginnings-note')).toBeVisible();
    await expect(bar.locator('#carry-beginnings-note')).toContainText('2 beginnings waiting on a reopen');
    await expect(bar.locator('#carry-beginnings-note')).toContainText('this day is closed');

    // Recompute sales is unaffected by the day state: it repaints the sales column, reports
    // that, and populates no beginning - so a closed day is no longer an error path.
    const [first, second, recorded] = scope.products;
    await page.locator('#recompute-sales-btn').click();
    await expect(page.locator('#recompute-sales-btn')).toBeEnabled();
    await expect(lastToast(page)).toContainText('Sales already match the saved values.');
    const afterRepaint = JSON.parse(run('read')).filter(r => r.shift === 'PM');
    for (const product of scope.products.filter(p => p.pm_row_exists)) {
        const stored = afterRepaint.find(r => Number(r.product_id) === product.product_id);
        expect(Number(stored.beg_bal), 'a repaint must not carry product ' + product.product_id).toBe(product.pm_beginning);
    }
    await expect(begOf(page, first.product_id)).toHaveValue('0');

    // Reopened: the close is back, the carry is offered again, and one press still does it.
    run('open');
    await page.reload();
    await expect(bar.locator('#action-bar-day-status')).toHaveText('open');
    await expect(bar.locator('#close-day-btn')).toBeVisible();
    await expect(bar.locator('#reopen-day-btn')).toHaveCount(0);

    const carryBtn = bar.locator('#carry-beginnings-btn');
    await expect(carryBtn).toHaveText('Carry 2 beginnings forward');
    await carryBtn.click();
    await expect(lastToast(page)).toContainText('Carried 2 beginnings forward');
    await expect(begOf(page, first.product_id)).toHaveValue(String(first.carried));
    await expect(begOf(page, second.product_id)).toHaveValue(String(second.carried));
    await expect(begOf(page, recorded.product_id)).toHaveValue('25');

    expect(errors).toEqual([]);
});
