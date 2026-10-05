// @ts-check
/**
 * Lane: drop-queue-loud-retry
 *
 * Acceptance for the owner directive (2026-10-05): a ledger cell save must never be
 * silently queued and must never present an unconfirmed write as saved. The offline
 * path is deferred; every already-stored entry must survive byte-for-byte.
 *
 *   A. Forced save failure -> explicit "NOT saved / retry", cell visibly unsaved,
 *      and NO new pending entry for that edit.
 *   B. BYTE-IDENTITY of pending + foreign + legacy + quarantine entries across a
 *      page load and a sync attempt.
 *   C. A server-confirmed save still succeeds (no regression).
 *   D. Static: the auto-migration functions are gone (checked in the unit test,
 *      this spec focuses on A/B/C).
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const fixture = path.resolve(__dirname, '../../../../daily-ledger/daily_ledger_cell_browser_fixture.php');
const run = mode => execFileSync('php', [fixture, mode], { encoding: 'utf8' });
let scope;

test.setTimeout(90000);
test.beforeEach(() => { scope = JSON.parse(run('setup')); });
test.afterEach(() => { run('cleanup'); });

/**
 * Deterministic readiness guard: the server renders one <tr data-product-id="..."> per
 * branch product into #ledger-body, so waiting for that row proves the ledger document
 * loaded and the sheet populated. It reads NO mutable business value (sales, beg_bal,
 * bal_end, ...) because other specs in this directory mutate the shared fixture data.
 * If the sheet fails to load no row appears, and this guard fails.
 */
async function waitForLedgerReady(page) {
    await expect(page.locator('#ledger-body tr[data-product-id]').first()).toBeVisible();
}

async function login(page, shift = 'AM') {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill('browser-ledger-cell');
    await page.getByLabel('Full Name', { exact: true }).fill('Browser Ledger Cell');
    await page.getByLabel('Password', { exact: true }).fill('BrowserCell!2031');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/admin/);
    await page.goto(`/daily-ledger/ledger?branch_id=${scope.branch_id}&date=${scope.date}&shift=${shift}`);
    await waitForLedgerReady(page);
}

/** Build the localStorage keys this suite cares about, from the live page globals. */
function keyInfo() {
    var tenant = String(window.DL_TENANT_SCOPE || '');
    var actor = String(window.DL_USER_ID || '');
    var branch = String(window.BRANCH_ID || '');
    var date = String(window.LEDGER_DATE || '');
    var shift = String(window.SHIFT || 'AM');
    var prefix = 'daily-ledger:pending-saves';
    return {
        tenant: tenant,
        actor: actor,
        branch: branch,
        date: date,
        shift: shift,
        pending: [prefix, tenant, actor, branch, date, shift].join(':'),
        foreign: [prefix, tenant, 'some-other-cashier', branch, date, shift].join(':'),
        legacy: 'bbs_pending_saves',
        quarantine: [prefix, 'quarantine', tenant, actor].join(':')
    };
}

var raw = (el) => el === null ? null : String(el);

async function snapshot(page, keys) {
    return page.evaluate(function (keys) {
        var out = {};
        keys.forEach(function (k) { out[k] = localStorage.getItem(k); });
        return out;
    }, keys);
}

test('A. forced save failure is loud, reverts the cell, and queues nothing', async ({ page }) => {
    await login(page);

    // Prove there is no pending entry for this edit before/after.
    var info = await page.evaluate(keyInfo);
    await page.evaluate(function (k) { localStorage.removeItem(k); }, info.pending);

    // Force EVERY save attempt to fail at the transport layer.
    await page.route('**/api/v1/cashier/ledger/save', async function (route) {
        await route.abort('failed');
    });

    var cell = page.locator('[data-field="beg_bal"]').first();
    await expect(cell).toHaveValue('20');
    await cell.fill('25');
    await cell.dispatchEvent('change');

    // Explicit, persistent, actionable failure.
    var failure = page.locator('#global-status');
    await expect(failure).toHaveAttribute('data-state', 'not-saved');
    await expect(failure).toContainText(/Not saved/i);
    await expect(failure).toContainText(/Retry/i);

    // The cell is visibly unsaved: rolled back to the server's value, not the typed one.
    await expect(cell).toHaveValue('20');
    await expect(cell).not.toHaveClass(/saved/);

    // No new pending/queued entry was created for this edit.
    var pendingAfter = await page.evaluate(function (k) { return localStorage.getItem(k); }, info.pending);
    expect(pendingAfter, 'a failed save must not queue a new pending entry').toBeNull();
});

test('A2. a transport failure never fabricates a success toast', async ({ page }) => {
    await login(page);
    await page.route('**/api/v1/cashier/ledger/save', async function (route) {
        await route.abort('failed');
    });

    var toasts = [];
    await page.evaluate(function () {
        window.__toasts = [];
        var orig = window.showToast;
        window.showToast = function (message, type) {
            window.__toasts.push({ message: message, type: type });
            if (orig) return orig.apply(this, arguments);
        };
    });

    var cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('31');
    await cell.dispatchEvent('change');
    await expect(page.locator('#global-status')).toHaveAttribute('data-state', 'not-saved');
    toasts = await page.evaluate(function () { return window.__toasts || []; });
    var success = toasts.filter(function (t) { return t.type === 'success' && /saved/i.test(t.message); });
    expect(success, 'a failed save must not emit a success toast').toEqual([]);
});

test('A3. a non-2xx (403 deterministic) rejection is persistent, actionable, and queues nothing', async ({ page }) => {
    await login(page);
    var info = await page.evaluate(keyInfo);
    await page.evaluate(function (k) { localStorage.removeItem(k); }, info.pending);
    await page.evaluate(function () {
        Object.keys(localStorage).filter(function (k) {
            return k.indexOf('daily-ledger:pending-saves:quarantine:') === 0;
        }).forEach(function (k) { localStorage.removeItem(k); });
    });

    await page.route('**/api/v1/cashier/ledger/save', async function (route) {
        await route.fulfill({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ ok: false, error: 'Reference only' })
        });
    });

    var cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('25');
    await cell.dispatchEvent('change');

    await expect(page.locator('#global-status')).toHaveAttribute('data-state', 'not-saved');
    await expect(page.locator('#global-status')).toContainText(/Not saved/i);
    await expect(page.locator('#global-status')).toContainText(/Retry/i);
    await expect(cell).toHaveValue('20');
    await expect(cell).not.toHaveClass(/saved/);

    var state = await page.evaluate(function (info) {
        var quarantine = 0;
        Object.keys(localStorage).filter(function (k) {
            return k.indexOf('daily-ledger:pending-saves:quarantine:') === 0;
        }).forEach(function (k) { quarantine += JSON.parse(localStorage.getItem(k) || '[]').length; });
        return { pending: localStorage.getItem(info.pending), quarantine: quarantine };
    }, info);
    expect(state.pending, 'a refused save must not queue a new pending entry').toBeNull();
    expect(state.quarantine, 'a refused save must not mint a new quarantined entry').toBe(0);
});

test('A4. Retry re-sends the value the cashier typed, without re-entering it', async ({ page }) => {
    await login(page);
    var info = await page.evaluate(keyInfo);
    await page.evaluate(function (k) { localStorage.removeItem(k); }, info.pending);

    var attempts = [];
    await page.route('**/api/v1/cashier/ledger/save', async function (route) {
        var body = route.request().postDataJSON();
        attempts.push(body);
        if (attempts.length === 1) {
            await route.abort('failed');
            return;
        }
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ ok: true, value: body.value })
        });
    });

    var cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('25');
    await cell.dispatchEvent('change');
    await expect(page.locator('#global-status')).toHaveAttribute('data-state', 'not-saved');

    // The operator never re-types: the one status control replays the captured value.
    await page.locator('#global-status').click();
    await expect(page.locator('#global-status')).toHaveText('All saved');
    await expect(cell).toHaveClass(/saved/);
    expect(attempts.length).toBe(2);
    expect(attempts[0].value).toBe(25);
    expect(attempts[1].value).toBe(25);
    expect(await page.evaluate(function (k) { return localStorage.getItem(k); }, info.pending)).toBeNull();
});

test('B. pending + foreign + legacy + quarantine entries are byte-identical across load and sync', async ({ page }) => {
    await login(page);
    var info = await page.evaluate(keyInfo);

    // Register the forced-failure routes BEFORE planting the fixtures. The already
    // loaded sheet has a load-time cloud probe that calls drainPendingWork(); if the
    // fixtures exist while that probe is still in flight and these routes are not yet
    // active, the page reaches the real endpoint and consumes the pending entry before
    // the test can snapshot it. Intercepting first makes the isolation total.
    await page.route('**/api/v1/cashier/ledger/save', async function (route) { await route.abort('failed'); });
    await page.route('**/api/v1/offline/reconcile', async function (route) { await route.abort('failed'); });

    var fixture = await page.evaluate(function (info) {
        var now = '2020-01-01T00:00:00.000Z';
        var valid = {
            schema_version: 3, module: 'daily-ledger', tenant_scope: info.tenant,
            actor_id: info.actor, branch_id: info.branch, date: info.date, shift: info.shift,
            product_id: '99471', field: 'beg_bal', value: 7, created_at: now
        };
        var foreign = Object.assign({}, valid, { actor_id: 'some-other-cashier', value: 8 });
        var legacy = [{ product_id: '1', field: 'beg_bal', value: 5, date: info.date }];
        var quarantine = [{ reason: 'server-rejected', source_key: info.pending, quarantined_at: now, payload: valid }];

        localStorage.setItem(info.pending, JSON.stringify([valid]));
        localStorage.setItem(info.foreign, JSON.stringify([foreign]));
        localStorage.setItem(info.legacy, JSON.stringify(legacy));
        localStorage.setItem(info.quarantine, JSON.stringify(quarantine));
        return { valid: valid, foreign: foreign, legacy: legacy, quarantine: quarantine };
    }, info);

    var keys = [info.pending, info.foreign, info.legacy, info.quarantine];
    var before = await snapshot(page, keys);

    // Page load (runs initializePendingQueue -> syncPendingStorage).
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForLedgerReady(page);
    var afterLoad = await snapshot(page, keys);

    // Explicit sync attempt (drainPendingWork -> retryPending/replayPendingOperations).
    await page.evaluate(function () { return window.drainPendingWork(); });
    await page.waitForTimeout(800);
    var afterSync = await snapshot(page, keys);

    expect(afterLoad, 'page load must not change any stored entry').toEqual(before);
    expect(afterSync, 'a sync attempt must not change any stored entry').toEqual(before);

    // And the parsed content still holds the exact payloads (no silent re-scope).
    var parsed = await page.evaluate(function (keys) {
        var out = {};
        keys.forEach(function (k) { out[k] = localStorage.getItem(k); });
        return out;
    }, keys);
    expect(JSON.parse(parsed[info.pending])).toEqual([fixture.valid]);
    expect(JSON.parse(parsed[info.foreign])).toEqual([fixture.foreign]);
    expect(JSON.parse(parsed[info.legacy])).toEqual(fixture.legacy);
    expect(JSON.parse(parsed[info.quarantine])).toEqual(fixture.quarantine);
});

test('C. a server-confirmed save still shows success', async ({ page }) => {
    await login(page);

    var confirmed = 0;
    await page.route('**/api/v1/cashier/ledger/save', async function (route) {
        confirmed++;
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ ok: true, value: 27 })
        });
    });

    var cell = page.locator('[data-field="beg_bal"]').first();
    await cell.fill('27');
    await cell.dispatchEvent('change');

    await expect(cell).toHaveClass(/saved/);
    await expect(page.locator('#dl-write-failure')).toBeHidden();
    expect(confirmed, 'the confirmed save reached the server').toBe(1);
});

test('D. static: the auto-migration functions no longer exist in the file', async () => {
    var src = fs.readFileSync(
        path.resolve(__dirname, '../../../../../templates/modules/daily-ledger/cashier/ledger.disyl'),
        'utf8'
    );
    expect(src).not.toContain('canMigratePendingEntry');
    expect(src).not.toContain('mergeMigratedPending');
    // The only destructive purge control stays removed as well.
    expect(src).not.toContain('clearQuarantinedPending');
    expect(src).not.toContain('Purge stale saves');
});
