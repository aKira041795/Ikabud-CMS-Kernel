// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const fixture = path.resolve(__dirname, '../../../daily-ledger/daily_ledger_cell_browser_fixture.php');
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
    await expect(page.locator('#ledger-body tr[data-product-id]').first()).toBeVisible();
}

async function seedHeld(page, count = 2) {
    return page.evaluate(function (count) {
        var key = [
            'daily-ledger:pending-saves', String(window.DL_TENANT_SCOPE), String(window.DL_USER_ID),
            String(window.BRANCH_ID), String(window.LEDGER_DATE), String(window.SHIFT)
        ].join(':');
        var rows = [];
        for (var i = 0; i < count; i++) {
            rows.push({
                schema_version: 3, module: 'daily-ledger', tenant_scope: String(window.DL_TENANT_SCOPE),
                actor_id: String(window.DL_USER_ID), branch_id: String(window.BRANCH_ID),
                date: String(window.LEDGER_DATE), shift: String(window.SHIFT),
                product_id: String(900 + i), field: 'beg_bal', value: 7 + i,
                created_at: '2026-10-05T00:00:0' + i + '.000Z'
            });
        }
        localStorage.setItem(key, JSON.stringify(rows));
        window.renderHeldDataStatus();
        return { key: key, raw: localStorage.getItem(key), branch: String(window.BRANCH_ID), date: String(window.LEDGER_DATE), shift: String(window.SHIFT) };
    }, count);
}

test('A/C/F. held refusal is visible with context and retry outcome, without data loss', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/cashier/ledger/save', route => route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ ok: false, reason: 'expired', error: 'Offline access has expired.' })
    }));
    const held = await seedHeld(page, 2);

    await expect(page.locator('#global-status')).toContainText('2 still on this device');
    await expect(page.locator('#global-status')).toContainText(held.branch);
    await expect(page.locator('#global-status')).toContainText(held.date);
    await expect(page.locator('#global-status')).toContainText(held.shift);
    await expect(page.locator('#global-status')).toContainText('products 900, 901');
    await page.locator('#held-retry').click();
    await expect(page.locator('#global-status')).toContainText(/offline access (has )?expired/i);
    expect(await page.evaluate(key => localStorage.getItem(key), held.key)).toBe(held.raw);
});

test('A2. whole-batch reconcile refusal is recorded, visible, and preserves every vault operation', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/offline/reconcile', route => route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ ok: false, reason: 'expired', error: 'Offline access has expired.' })
    }));
    const result = await page.evaluate(async function () {
        var operations = [
            { client_op_id: 'held-op-0001', type: 'ledger_save', base_version: 1, created_at: '2026-10-05T00:00:00Z', payload: { branch_id: window.BRANCH_ID, date: window.LEDGER_DATE, shift: window.SHIFT, product_id: '41', field: 'beg_bal', value: 3 } },
            { client_op_id: 'held-op-0002', type: 'ledger_save', base_version: 1, created_at: '2026-10-05T00:01:00Z', payload: { branch_id: window.BRANCH_ID, date: window.LEDGER_DATE, shift: window.SHIFT, product_id: '42', field: 'bal_end', value: 2 } }
        ];
        var removed = [];
        window.DLOfflineVault = {
            isUnlocked: function () { return true; },
            getStoredEnrollmentId: function () { return Promise.resolve('enrollment-test'); },
            ensureDeviceId: function () { return Promise.resolve('device-secret-123'); },
            listOperations: function () { return Promise.resolve(operations.slice()); },
            countPending: function () { return Promise.resolve(operations.length); },
            countPendingLocked: function () { return Promise.resolve(operations.length); },
            listQuarantine: function () { return Promise.resolve([]); },
            saveReceipt: function () { return Promise.resolve(); },
            removeOperation: function (id) { removed.push(id); operations = operations.filter(function (op) { return op.client_op_id !== id; }); return Promise.resolve(); }
        };
        window.vaultQueueActive = true;
        window.vaultPendingCount = operations.length;
        await window.drainVault();
        var diagnosticKey = Object.keys(localStorage).find(function (key) { return key.indexOf('daily-ledger:sync-diagnostics:') === 0; });
        return {
            remaining: operations.length,
            removed: removed,
            diagnostic: JSON.parse(localStorage.getItem(diagnosticKey) || '[]').slice(-1)[0] || null
        };
    });
    await expect(page.locator('#global-status')).toContainText(/2 still on this device/i);
    await expect(page.locator('#global-status')).toContainText(/offline access has expired/i);
    await expect(page.locator('#global-status')).not.toContainText(/device-secret-123|reason: expired/i);
    expect(result.remaining).toBe(2);
    expect(result.removed).toEqual([]);
    expect(result.diagnostic.reason).toBe('expired');
    expect(result.diagnostic.held_count).toBe(2);
});

test('E. successful held retry is idempotent and a repeated retry does not apply twice', async ({ page }) => {
    await login(page);
    let reconciles = 0;
    await page.route('**/api/v1/offline/reconcile', route => {
        reconciles++;
        const body = route.request().postDataJSON();
        return route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ ok: true, results: body.operations.map(function (op) {
                return { client_op_id: op.client_op_id, status: 'applied', result: { ok: true } };
            }) })
        });
    });
    const result = await page.evaluate(async function () {
        var operations = [{ client_op_id: 'held-op-once', type: 'ledger_save', base_version: 1, created_at: '2026-10-05T00:00:00Z', payload: { branch_id: window.BRANCH_ID, date: window.LEDGER_DATE, shift: window.SHIFT, product_id: '41', field: 'beg_bal', value: 3 } }];
        var removals = 0;
        window.DLOfflineVault = {
            isUnlocked: function () { return true; },
            getStoredEnrollmentId: function () { return Promise.resolve('enrollment-test'); },
            ensureDeviceId: function () { return Promise.resolve('device-test'); },
            listOperations: function () { return Promise.resolve(operations.slice()); },
            countPending: function () { return Promise.resolve(operations.length); },
            countPendingLocked: function () { return Promise.resolve(operations.length); },
            listQuarantine: function () { return Promise.resolve([]); },
            saveReceipt: function () { return Promise.resolve(); },
            removeOperation: function (id) { removals++; operations = operations.filter(function (op) { return op.client_op_id !== id; }); return Promise.resolve(); }
        };
        window.vaultQueueActive = true;
        window.vaultPendingCount = 1;
        await window.dlRetryHeldData();
        await window.dlRetryHeldData();
        return { remaining: operations.length, removals: removals };
    });
    expect(result.remaining).toBe(0);
    expect(result.removals).toBe(1);
    expect(reconciles).toBe(1);
    await expect(page.locator('#global-status')).toHaveText('All saved');
});

test('B. network evidence and refusal render different truthful causes', async ({ page }) => {
    await login(page);
    let mode = 'network';
    await page.route('**/api/v1/cashier/ledger/save', async route => {
        if (mode === 'network') return route.abort('failed');
        return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, reason: 'expired', error: 'Offline access has expired.' }) });
    });
    const cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('31');
    await cell.dispatchEvent('change');
    await expect(page.locator('#global-status')).toContainText(/Not saved.*connection is slow.*Retry/i);

    mode = 'refusal';
    await cell.fill('32');
    await cell.dispatchEvent('change');
    await expect(page.locator('#global-status')).toContainText(/Not saved.*offline access.*expired.*Retry/i);
    await expect(page.locator('#global-status')).not.toContainText(/connection is slow/i);
});

test('D. discard names the held sheet and cancellation is byte-identical', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/cashier/ledger/save', route => route.abort('failed'));
    const held = await seedHeld(page, 2);
    let prompt = '';
    page.once('dialog', async dialog => { prompt = dialog.message(); await dialog.dismiss(); });
    await page.locator('#held-discard').click();
    await expect.poll(() => prompt).toContain('2');
    expect(prompt).toContain(held.date);
    expect(prompt).toContain(held.shift);
    expect(prompt).toMatch(/re-encode/i);
    expect(await page.evaluate(key => localStorage.getItem(key), held.key)).toBe(held.raw);

    page.once('dialog', dialog => dialog.accept());
    await page.locator('#held-discard').click();
    await expect(page.locator('#global-status')).toHaveText('All saved');
    expect(await page.evaluate(key => localStorage.getItem(key), held.key)).toBeNull();
});

test('E. failed automatic and manual sends preserve held bytes', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/cashier/ledger/save', route => route.abort('failed'));
    const held = await seedHeld(page, 2);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#ledger-body tr[data-product-id]').first()).toBeVisible();
    expect(await page.evaluate(key => localStorage.getItem(key), held.key)).toBe(held.raw);
    await page.locator('#held-retry').click();
    expect(await page.evaluate(key => localStorage.getItem(key), held.key)).toBe(held.raw);
});

test('G/L. exactly one status surface owns three states; held actions only appear for held data', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/cashier/ledger/save', route => route.abort('failed'));
    await expect(page.locator('#global-status')).toHaveText('All saved');
    expect(await page.locator('#held-retry').count()).toBe(0);
    expect(await page.locator('#held-discard').count()).toBe(0);
    expect(await page.locator('[data-cashier-save-status]').count()).toBe(1);

    await seedHeld(page, 1);
    await expect(page.locator('#global-status')).toContainText('1 still on this device');
    await expect(page.locator('#held-retry')).toBeVisible();
    await expect(page.locator('#held-discard')).toBeVisible();
});

test('H/K. healthy cell save remains one edit with no dialog or confirmation', async ({ page }) => {
    await login(page);
    let saves = 0;
    let dialogs = 0;
    page.on('dialog', async dialog => { dialogs++; await dialog.dismiss(); });
    await page.route('**/api/v1/cashier/ledger/save', route => {
        saves++;
        const body = route.request().postDataJSON();
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, value: body.value }) });
    });
    const cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('33');
    await cell.dispatchEvent('change');
    await expect(cell).toHaveClass(/saved/);
    expect(saves).toBe(1);
    expect(dialogs).toBe(0);
});

test('I/J. interaction, online, and visible events retry promptly; handover is unambiguous', async ({ page }) => {
    await login(page);
    let attempts = 0;
    let recover = false;
    await page.route('**/api/v1/cashier/ledger/save', route => {
        attempts++;
        if (!recover) return route.abort('failed');
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    });
    await seedHeld(page, 1);
    await expect(page.locator('#global-status')).toContainText(/office can see/i);

    let before = attempts;
    await page.locator('#ledger-search').click();
    await expect.poll(() => attempts).toBeGreaterThan(before);

    await page.waitForTimeout(1100);
    before = attempts;
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect.poll(() => attempts).toBeGreaterThan(before);

    await page.waitForTimeout(1100);
    before = attempts;
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect.poll(() => attempts).toBeGreaterThan(before);

    await page.waitForTimeout(1100);
    recover = true;
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(page.locator('#global-status')).toHaveText('All saved');
});

test('M. no cashier page, route, tab, wizard, or non-discard modal was introduced', async () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../../../templates/modules/daily-ledger/cashier/ledger.disyl'), 'utf8');
    expect(src).not.toContain('held-data-page');
    expect(src).not.toContain('held-data-tab');
    expect(src).not.toContain('held-data-wizard');
    expect(src).not.toContain('held-data-modal');
    expect(src).toContain("confirm(message)");
});
