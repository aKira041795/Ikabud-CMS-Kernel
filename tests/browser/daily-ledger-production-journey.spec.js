// @ts-check
/**
 * Daily Ledger — end-to-end production journey (tenant 207 / baronledger.test).
 *
 * One browser journey across the whole product chain:
 *   A production beginning entry (commissary AM)
 *   B AM ending -> PM beginning carry-over, and no affordance for a zero AM ending
 *   C production addition per destination branch (per-branch split on the sheet)
 *   D dispatch a delivery from the commissary to branch 1
 *   E branch 1 receives exactly as sent (copied basis, zero variance, admin can read it)
 *   F dispatch to branch 2 and receive short by 2 (corrected qty persisted + variance flag)
 *   G as admin: is the discrepancy visible, and does it survive an AM/PM shift filter?
 *
 * The fixture seeds its own commissary / two branches / three products / a
 * production user + PM-bound production user / two cashiers / one admin.
 * `collectBrowserErrors` + `expectNoBrowserErrors` run over the whole journey.
 * No waitForTimeout is used: every wait is an observable UI condition.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = path.join(__dirname, '..', 'daily-ledger', 'daily_ledger_production_journey_browser_fixture.php');
const PASSWORD = 'Journey!207Pass';

/** Run the PHP fixture and return its stdout. */
function runFixture(mode) {
    return execFileSync('php', [fixture, mode], {
        cwd: path.join(__dirname, '..', '..'),
        encoding: 'utf8',
    });
}

function collectBrowserErrors(page) {
    const errors = { console: [], page: [], requestFailed: [], server: [] };
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.console.push(message.text());
        }
    });
    page.on('pageerror', (error) => errors.page.push(String(error)));
    page.on('requestfailed', (request) => {
        const failure = request.failure();
        const errorText = failure ? failure.errorText : '';
        // A navigation canceling an in-flight background poll or in-place
        // refresh is not a failed write. The cashier ledger's own post-receive
        // reload cancels its day-status/today pollers; the in-place Commissary
        // refresh is canceled by the next page load. Genuine transport failures
        // (connection refused, DNS, TLS, timeouts) and 5xx are still captured.
        if (errorText.includes('net::ERR_ABORTED')) {
            return;
        }
        errors.requestFailed.push(`${request.method()} ${request.url()} ${errorText}`.trim());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            errors.server.push(`${response.status()} ${response.url()}`);
        }
    });
    return errors;
}

function expectNoBrowserErrors(errors) {
    expect(errors.console, 'console.error messages').toEqual([]);
    expect(errors.page, 'uncaught page errors').toEqual([]);
    expect(errors.requestFailed, 'failed network requests').toEqual([]);
    expect(errors.server, '5xx responses').toEqual([]);
}

/**
 * Log in at the module-owned login page. The session cookie is cleared first so
 * one test can switch between the production user, both cashiers and the admin.
 */
async function login(page, username, fullName) {
    await page.context().clearCookies();
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill(username);
    await page.getByLabel('Full Name').fill(fullName);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/daily-ledger\/(ledger|admin\/)/);
}

/** URL for the production/commissary Daily Sheet. */
function sheetUrl(seed, shift) {
    return `/daily-ledger/admin/commissary?date=${seed.date}&commissary_id=${seed.commissary}&shift=${shift}`;
}

/** Wait for the in-place main-content swap to settle on a specific locator. */
async function expectCommissarySheet(page) {
    await expect(page.getByRole('heading', { name: /Commissary|Daily Sheet/ })).toBeVisible();
    await expect(page.locator('.daily-sheet-product-row').first()).toBeVisible();
}

/**
 * Click Receive Now and wait for the observable success toast and the modal to
 * close. The success path also fires an in-place refresh; waiting for the
 * network to settle keeps the next sign-in from racing that refresh.
 */
async function receiveAndWait(page, modal) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/cashier/ledger/receive-delivery') && r.request().method() === 'POST'),
        modal.getByRole('button', { name: 'Receive Now' }).click(),
    ]);
    expect(response.ok(), `receive-delivery returned HTTP ${response.status()}`).toBeTruthy();
    // The ledger success path is a full reload; settle it before the next sign-in.
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('#ledger-body')).toBeVisible();
}

/** Fill and submit the commissary "Dispatch to Branch" form, waiting on the POST. */
async function dispatchFromCommissary(page, branchId, dr, items) {
    await page.locator('#dispatch-dest-branch-id').selectOption(String(branchId));
    await page.locator('#dispatch-dr-number').fill(dr);
    for (const [pid, qty] of items) {
        await page.locator(`.dispatch-qty-input[data-product-id="${pid}"]`).fill(String(qty));
    }
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/commissary/dispatch') && r.request().method() === 'POST'),
        page.locator('#submit-dispatch').click(),
    ]);
    expect(response.ok(), `commissary dispatch returned HTTP ${response.status()}`).toBeTruthy();
    await page.waitForLoadState('networkidle').catch(() => {});
}

/** Open the receive modal, narrow to one DR and return its locators. */
async function openReceiveForDr(page, dr) {
    await page.getByRole('button', { name: 'Open Receive Stock' }).click();
    const modal = page.locator('[role="dialog"][aria-labelledby="receive-modal-title"]');
    await expect(modal.getByRole('heading', { name: 'Receive Stock' })).toBeVisible();
    await modal.getByPlaceholder('Enter the paper DR number').fill(dr);
    await expect(modal.getByRole('button', { name: 'Receive Now' })).toBeVisible();
    return modal;
}

let seed;

test.beforeEach(() => {
    const out = runFixture('setup');
    const line = out.split('\n').find((l) => l.startsWith('JOURNEY_FIXTURE='));
    expect(line, 'fixture must print JOURNEY_FIXTURE').toBeTruthy();
    seed = JSON.parse(line.slice('JOURNEY_FIXTURE='.length));
});

test.afterEach(() => {
    runFixture('cleanup');
});

test('production -> delivery -> receiving -> admin variance (baronledger.test, tenant 207)', async ({ page }) => {
    // The journey crosses eight sign-ins and several in-place refreshes; the
    // default 30s is not enough. Every wait is still an observable condition.
    test.setTimeout(240000);
    const errors = collectBrowserErrors(page);

    // ── A. Production beginning entry on the commissary AM ────────────────
    await login(page, seed.production_user, 'Journey Producer');
    await page.goto(sheetUrl(seed, 'AM'));
    await expectCommissarySheet(page);

    const begProducts = [seed.product_one, seed.product_two, seed.product_three];
    for (const pid of begProducts) {
        const input = page.locator(`#production-beg-${pid}`);
        await input.fill('5');
        await input.blur();
        await expect(input).toHaveAttribute('data-recorded', '1');
    }
    await page.reload();
    await expectCommissarySheet(page);
    for (const pid of begProducts) {
        await expect(page.locator(`#production-beg-${pid}`)).toHaveValue('5');
        await expect(page.locator(`#production-beg-${pid}`)).toHaveAttribute('data-recorded', '1');
    }

    // ── B. Zero AM ending must NOT offer a carry ──────────────────────────
    await page.getByRole('group', { name: 'Shift' }).getByRole('link', { name: 'PM' }).click();
    await page.waitForURL(/shift=PM/);
    await expectCommissarySheet(page);
    await expect(page.locator('#production-carry-beginnings-btn')).toBeHidden();
    await expect(page.locator(`#production-beg-${seed.product_one}`)).toHaveAttribute('data-suggestion', '0');
    await expect(page.locator(`#production-beg-${seed.product_one}`)).toHaveValue('0');

    // Set AM endings > 0 as the production user.
    await page.getByRole('group', { name: 'Shift' }).getByRole('link', { name: 'AM' }).click();
    await page.waitForURL(/shift=AM/);
    await expectCommissarySheet(page);
    const amEndings = new Map([
        [seed.product_one, 12],
        [seed.product_two, 9],
        [seed.product_three, 7],
    ]);
    for (const [pid, ending] of amEndings) {
        const input = page.locator(`#production-actual-${pid}`);
        await input.fill(String(ending));
        await input.blur();
        await expect(input).toHaveAttribute('data-original', String(ending));
    }

    // Move to PM: the carry must now be offered and equal the AM ending exactly.
    await page.getByRole('group', { name: 'Shift' }).getByRole('link', { name: 'PM' }).click();
    await page.waitForURL(/shift=PM/);
    await expectCommissarySheet(page);
    const carryButton = page.locator('#production-carry-beginnings-btn');
    await expect(carryButton).toBeVisible();
    await expect(carryButton).toContainText('Carry 3 beginnings forward');
    for (const [pid, ending] of amEndings) {
        await expect(page.locator(`#production-beg-${pid}`)).toHaveAttribute('data-suggestion', String(ending));
        await expect(page.locator(`#production-beg-${pid}`)).toHaveValue('0');
    }
    await carryButton.click();
    for (const [pid, ending] of amEndings) {
        await expect(page.locator(`#production-beg-${pid}`)).toHaveValue(String(ending));
    }
    await page.reload();
    await expectCommissarySheet(page);
    for (const [pid, ending] of amEndings) {
        await expect(page.locator(`#production-beg-${pid}`)).toHaveValue(String(ending));
    }

    // ── C. Production addition per destination branch ─────────────────────
    const additions = new Map([
        [seed.product_one, 50],
        [seed.product_two, 30],
        [seed.product_three, 20],
    ]);
    for (const [pid, qty] of additions) {
        await page.getByRole('button', { name: 'Record Addition' }).click();
        const modal = page.locator('#production-addition-modal');
        await modal.locator('#production-addition-product').selectOption(String(pid));
        await modal.locator('#production-addition-qty').fill(String(qty));
        await modal.getByRole('button', { name: 'Record Addition' }).click();
        await expect(modal).toBeHidden();
        await expect(page.locator(`#production-addtl-${pid}`)).toHaveText(String(qty));
    }

    // Per-branch split: P3 to branch 1 = 5 and to branch 2 = 2. A total alone
    // cannot prove the attribution, so assert each branch cell separately.
    await page.locator(`.production-branch-trigger[data-product="${seed.product_three}"][data-branch="${seed.branch_one}"]`).click();
    let cellModal = page.locator('#branch-cell-modal');
    await cellModal.locator('#branch-cell-qty').fill('5');
    await cellModal.getByRole('button', { name: 'Save Entry' }).click();
    await expect(cellModal).toBeHidden();
    await expect(page.locator(`.production-branch-value[data-product="${seed.product_three}"][data-branch-id="${seed.branch_one}"]`)).toHaveText('5');

    await page.locator(`.production-branch-trigger[data-product="${seed.product_three}"][data-branch="${seed.branch_two}"]`).click();
    cellModal = page.locator('#branch-cell-modal');
    await cellModal.locator('#branch-cell-qty').fill('2');
    await cellModal.getByRole('button', { name: 'Save Entry' }).click();
    await expect(cellModal).toBeHidden();
    await expect(page.locator(`.production-branch-value[data-product="${seed.product_three}"][data-branch-id="${seed.branch_two}"]`)).toHaveText('2');
    await expect(page.locator(`.production-branch-value[data-product="${seed.product_three}"][data-branch-id="${seed.branch_one}"]`)).toHaveText('5');

    // Browser errors so far must be genuinely empty.
    expectNoBrowserErrors(errors);

    // ── D. Dispatch a delivery from the commissary to branch 1 ────────────
    await login(page, seed.admin, 'Journey Admin');
    const exactDr = `JRN-EXACT-${seed.branch_one}`;
    await page.goto(`/daily-ledger/admin/commissary?date=${seed.date}`);
    await expectCommissarySheet(page);
    await dispatchFromCommissary(page, seed.branch_one, exactDr, [[seed.product_one, 10], [seed.product_two, 4]]);

    // ── E. Receive exactly as sent at branch 1 ────────────────────────────
    await login(page, seed.cashier_one, 'Journey Cashier One');
    await page.waitForURL(/\/daily-ledger\/ledger/);
    let modal = await openReceiveForDr(page, exactDr);
    await modal.getByRole('combobox').first().selectOption('AM');
    await modal.getByRole('combobox').nth(1).selectOption('PM');
    await receiveAndWait(page, modal);

    // Admin reads the receipt with its line items (the 1f442aee path).
    await login(page, seed.admin, 'Journey Admin');
    await page.goto(`/daily-ledger/admin/deliveries?branch_id=${seed.branch_one}`);
    const exactRow = page.locator('tbody', { hasText: exactDr });
    await expect(exactRow.getByRole('button', { name: 'View Receipt' })).toBeVisible();
    await exactRow.getByRole('button', { name: 'View Receipt' }).click();
    const exactDetail = exactRow.locator('tr', { hasText: 'Receipt Details' });
    await expect(page.getByText('Receipt Details')).toBeVisible();
    await expect(exactDetail).toContainText('Journey Product One');
    await expect(exactDetail).toContainText('Journey Product Two');
    await expect(exactDetail).toContainText('Received into:');
    // Sent == received and variance 0 on every line.
    const exactLineOne = exactDetail.locator('tr', { hasText: 'Journey Product One' });
    await expect(exactLineOne).toContainText('10');
    await expect(exactLineOne.locator('td').last()).toHaveText('0');
    const exactLineTwo = exactDetail.locator('tr', { hasText: 'Journey Product Two' });
    await expect(exactLineTwo).toContainText('4');
    await expect(exactLineTwo.locator('td').last()).toHaveText('0');

    // The recorded basis is copied ("not independently counted") in the trace UI.
    await page.goto(`/daily-ledger/admin/trace?dr=${exactDr}&date_from=${seed.date}&date_to=${seed.date}&branch_id=${seed.branch_one}`);
    await expect(page.getByText('not independently counted', { exact: true }).first()).toBeVisible();

    // ── F. Dispatch to branch 2 and receive short by 2 ────────────────────
    await login(page, seed.admin, 'Journey Admin');
    const shortDr = `JRN-SHORT-${seed.branch_two}`;
    await page.goto(`/daily-ledger/admin/commissary?date=${seed.date}`);
    await expectCommissarySheet(page);
    await dispatchFromCommissary(page, seed.branch_two, shortDr, [[seed.product_one, 8], [seed.product_two, 6]]);

    await login(page, seed.cashier_two, 'Journey Cashier Two');
    await page.waitForURL(/\/daily-ledger\/ledger/);
    modal = await openReceiveForDr(page, shortDr);
    await modal.getByRole('combobox').first().selectOption('AM');
    await modal.getByRole('combobox').nth(1).selectOption('PM');
    // The paper DR is the truth: correct the received quantity to 4 (sent 6).
    await modal.getByRole('row', { name: /Journey Product Two/ }).getByRole('spinbutton').fill('4');
    await receiveAndWait(page, modal);

    // The corrected quantity persisted (the sent 6 did not overwrite it).
    await login(page, seed.admin, 'Journey Admin');
    await page.goto(`/daily-ledger/admin/trace?dr=${shortDr}&date_from=${seed.date}&date_to=${seed.date}&branch_id=${seed.branch_two}`);
    await expect(page.getByText('independently counted', { exact: true }).first()).toBeVisible();
    const varianceSection = page.locator('section').filter({ hasText: 'Variance (sent vs received)' });
    const shortLine = varianceSection.locator('tr', { hasText: 'Journey Product Two' }).first();
    await expect(shortLine).toContainText('6');
    await expect(shortLine).toContainText('4');
    await expect(shortLine).toContainText('-2');

    // Browser errors across the whole chain (including both receipts) must be empty.
    expectNoBrowserErrors(errors);

    // ── G. Can the admin notice it easily? ────────────────────────────────
    // The contract's requirement: the discrepancy is on the variance dashboard,
    // survives an explicit AM filter AND an explicit PM filter, shows both shift
    // facts, and shows the signed magnitude.
    await page.goto(`/daily-ledger/admin/variances?branch_id=${seed.branch_two}&kind=delivery&date_from=${seed.date}&date_to=${seed.date}`);
    await expect(page.getByRole('heading', { name: 'Variance Dashboard' })).toBeVisible();
    const shortDiscrepancy = page.getByText(/Sent 6 · cashier counted 4/);
    await expect(
        shortDiscrepancy,
        'the short receipt must raise an admin-visible delivery variance (dl_variance_flags)'
    ).toBeVisible({ timeout: 5000 });
    await expect(page.getByText(/Produced: PM/)).toBeVisible();
    await expect(page.getByText(/Received into: AM/)).toBeVisible();

    // Explicit AM filter must not hide it.
    await page.locator('select[name="shift"]').selectOption('AM');
    await page.waitForFunction(() => window.location.search.includes('shift=AM'));
    await expect(page.getByText(/Sent 6 · cashier counted 4/)).toBeVisible();

    // Explicit PM filter must not hide it.
    await page.locator('select[name="shift"]').selectOption('PM');
    await page.waitForFunction(() => window.location.search.includes('shift=PM'));
    await expect(page.getByText(/Sent 6 · cashier counted 4/)).toBeVisible();

    // The seeded historical flag proves the NULL rendering is explicit.
    await page.locator('select[name="shift"]').selectOption('');
    await page.waitForFunction(() => !window.location.search.includes('shift='));
    await expect(page.getByText(/Produced: not recorded/)).toBeVisible();
    await expect(page.getByText(/Received into: not recorded/)).toBeVisible();

    expectNoBrowserErrors(errors);
});
