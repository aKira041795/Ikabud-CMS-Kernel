// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

test.describe('Daily Ledger — Pending Save Scope', () => {
    test('legacy pending saves are quarantined instead of replayed', async ({ page, shell, integrity }) => {
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

        await page.evaluate(function () {
            localStorage.setItem('bbs_pending_saves', JSON.stringify([
                { product_id: '1', field: 'beg_bal', value: 5, date: window.LEDGER_DATE }
            ]));
        });

        await page.reload({ waitUntil: 'networkidle' });

        await expect(page.locator('#pending-save-warning')).toBeVisible();
        await expect(page.locator('#pending-save-warning-text')).toContainText('stale offline save');

        var state = await page.evaluate(function () {
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                keys.push(String(localStorage.key(i) || ''));
            }
            var quarantineKey = keys.find(function (key) {
                return key.indexOf('daily-ledger:pending-saves:quarantine:') === 0;
            }) || '';
            var quarantined = quarantineKey ? JSON.parse(localStorage.getItem(quarantineKey) || '[]') : [];
            return {
                legacyPresent: keys.indexOf('bbs_pending_saves') >= 0,
                quarantineKey: quarantineKey,
                quarantinedCount: Array.isArray(quarantined) ? quarantined.length : 0
            };
        });

        expect(state.legacyPresent, 'Legacy pending-save key should be removed').toBe(false);
        expect(state.quarantineKey, 'Quarantine key should be created').not.toBe('');
        expect(state.quarantinedCount, 'Legacy entry should be quarantined').toBeGreaterThan(0);

        await page.evaluate(function () {
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                keys.push(String(localStorage.key(i) || ''));
            }
            keys.forEach(function (key) {
                if (key.indexOf('daily-ledger:pending-saves') === 0 || key === 'bbs_pending_saves') {
                    localStorage.removeItem(key);
                }
            });
        });
    });
});
