// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

/**
 * POS — manual-mode preservation.
 *
 * With POS disabled or a manual day selected, the cashier ledger must behave
 * exactly as before: the manual grid renders, the offline field-save path is
 * untouched, and no POS checkout UI replaces it.
 */
test.describe('Daily Ledger POS — Manual Mode Preservation', () => {
    test('manual ledger renders with mode badge and no forced POS workspace', async ({ page, shell, integrity }) => {
        integrity.fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
        integrity.fingerprint('templates/modules/daily-ledger/layouts/app.disyl');

        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        // The manual ledger grid is still the primary surface.
        await expect(page.locator('#ledger-search')).toBeVisible();

        // The close-day control still exists for the manual workflow.
        await expect(page.locator('#close-day-btn')).toBeVisible();

        // POS never hijacks the ledger page: no checkout button here.
        await expect(page.locator('#pos-charge')).toHaveCount(0);
    });

    test('POS page shows an explicit state instead of a blank screen', async ({ page, shell, integrity }) => {
        integrity.fingerprint('templates/modules/daily-ledger/cashier/pos.disyl');

        await page.goto(APP_URL + BASE + '/pos');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        // One of the explicit states must be visible: disabled notice,
        // unauthorized notice, closed-day notice, mode picker, or workspace.
        var state = await page.evaluate(function () {
            function visible(id) {
                var el = document.getElementById(id);
                return !!el && !el.classList.contains('hidden');
            }
            var bodyText = document.body ? document.body.innerText : '';
            return {
                modePanel: visible('pos-mode-panel'),
                workspace: visible('pos-workspace'),
                fallback: visible('pos-fallback-panel'),
                disabledNotice: bodyText.indexOf('POS is not enabled') >= 0,
                unauthorizedNotice: bodyText.indexOf('not authorized to use POS') >= 0,
                closedNotice: bodyText.indexOf('business date is closed') >= 0
            };
        });

        var anyExplicitState = state.modePanel || state.workspace || state.fallback
            || state.disabledNotice || state.unauthorizedNotice || state.closedNotice;
        expect(anyExplicitState, 'POS page must render an explicit state').toBe(true);
    });
});
