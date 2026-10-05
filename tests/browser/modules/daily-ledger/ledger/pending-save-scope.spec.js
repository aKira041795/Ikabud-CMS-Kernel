// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

async function loginAs(page, username, password) {
    await page.context().clearCookies();
    await page.goto(APP_URL + BASE + '/login');
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="full_name"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(function (url) { return url.pathname.indexOf('/login') === -1; }),
        page.click('button[type="submit"], input[type="submit"]')
    ]);
    await page.goto(APP_URL + BASE + '/ledger', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
}

test.describe('Daily Ledger — Pending Save Scope', () => {
    // Lane drop-queue-loud-retry: switching actors must not touch the stored queue.
    // The auto-migration that re-scoped a retired actor's entries onto the current
    // sheet was removed because it would replay stranded entries onto closed days.
    test('a different cashier cannot quarantine, migrate or drop the first cashier queue', async ({ page, integrity }) => {
        test.setTimeout(90000);
        integrity.fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

        var password = process.env.TEST_CASHIER_PASS || 'cmiputak123';
        await loginAs(page, process.env.TEST_CASHIER_A || 'cashier-miputak', password);

        var seeded = await page.evaluate(function () {
            var key = [
                'daily-ledger:pending-saves', String(window.DL_TENANT_SCOPE), String(window.DL_USER_ID),
                String(window.BRANCH_ID), String(window.LEDGER_DATE), String(window.SHIFT)
            ].join(':');
            var payload = {
                schema_version: 3, module: 'daily-ledger', tenant_scope: String(window.DL_TENANT_SCOPE),
                actor_id: String(window.DL_USER_ID), branch_id: String(window.BRANCH_ID),
                date: String(window.LEDGER_DATE), shift: String(window.SHIFT),
                product_id: '1', field: 'beg_bal', value: 17,
                created_at: '2026-10-05T00:00:00.000Z'
            };
            localStorage.setItem(key, JSON.stringify([payload]));
            return { key: key, payload: payload, raw: localStorage.getItem(key) };
        });

        await loginAs(page, process.env.TEST_CASHIER_B || 'cashier-miputakAM', password);
        var afterOtherActor = await page.evaluate(function (sourceKey) {
            var quarantineCount = 0;
            Object.keys(localStorage).filter(function (key) {
                return key.indexOf('daily-ledger:pending-saves:quarantine:') === 0;
            }).forEach(function (key) {
                quarantineCount += JSON.parse(localStorage.getItem(key) || '[]').length;
            });
            return {
                source: JSON.parse(localStorage.getItem(sourceKey) || '[]'),
                raw: localStorage.getItem(sourceKey),
                quarantineCount: quarantineCount,
                warning: (document.querySelector('#global-status') || {}).textContent || ''
            };
        }, seeded.key);

        expect(afterOtherActor.source, 'actor A queue must remain under actor A').toEqual([seeded.payload]);
        expect(afterOtherActor.raw, 'actor A queue must be byte-identical').toBe(seeded.raw);
        expect(afterOtherActor.quarantineCount, 'switching actors must not quarantine a valid queue').toBe(0);
        expect(afterOtherActor.warning).toContain('1 still on this device');
        expect(afterOtherActor.warning).toContain(seeded.payload.date);
        expect(afterOtherActor.warning).toContain(seeded.payload.shift);
    });

    // Legacy bbs_pending_saves entries are stranded data too: preserve them exactly.
    // They are reported as retained (queued) rather than silently quarantined/deleted.
    test('legacy pending saves are preserved instead of quarantined or removed', async ({ page, shell, integrity }) => {
        integrity.fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
        integrity.fingerprint('templates/modules/daily-ledger/layouts/app.disyl');

        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        var context = await page.evaluate(function () {
            return {
                actor: String(window.DL_USER_ID || ''),
                tenant: String(window.DL_TENANT_SCOPE || ''),
                branch: String(window.BRANCH_ID || ''),
                date: String(window.LEDGER_DATE || '')
            };
        });

        expect(context.actor, 'Ledger page must expose stable actor id').not.toBe('');
        expect(context.branch, 'Ledger page must expose active branch id').not.toBe('');
        expect(context.date, 'Ledger page must expose active ledger date').not.toBe('');

        var legacyRaw = JSON.stringify([
            { product_id: '1', field: 'beg_bal', value: 5, date: context.date }
        ]);
        await page.evaluate(function (raw) {
            localStorage.setItem('bbs_pending_saves', raw);
        }, legacyRaw);

        await page.reload({ waitUntil: 'networkidle' });

        await expect(page.locator('#global-status')).toHaveAttribute('data-state', 'held');
        await expect(page.locator('#global-status')).toContainText('1 still on this device');

        var state = await page.evaluate(function () {
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                keys.push(String(localStorage.key(i) || ''));
            }
            var quarantineCount = 0;
            keys.filter(function (key) {
                return key.indexOf('daily-ledger:pending-saves:quarantine:') === 0;
            }).forEach(function (key) {
                quarantineCount += JSON.parse(localStorage.getItem(key) || '[]').length;
            });
            return {
                legacyPresent: keys.indexOf('bbs_pending_saves') >= 0,
                legacyRaw: localStorage.getItem('bbs_pending_saves'),
                quarantineCount: quarantineCount
            };
        });

        expect(state.legacyPresent, 'Legacy pending-save key must be retained').toBe(true);
        expect(state.legacyRaw, 'Legacy pending-save content must be byte-identical').toBe(legacyRaw);
        expect(state.quarantineCount, 'Legacy entries must not be quarantined').toBe(0);
    });
});
