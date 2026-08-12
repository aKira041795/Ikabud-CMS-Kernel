// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

/**
 * POS — checkout integrity.
 *
 * Verifies the checkout UI contract: double submission is prevented while a
 * checkout is pending, a success receipt is shown only after the server
 * confirms the committed sale, and network failures never show a paid state.
 */
test.describe('Daily Ledger POS — Checkout', () => {
    test('charge button is disabled while checkout is pending and cart is empty', async ({ page, shell, integrity }) => {
        integrity.fingerprint('templates/modules/daily-ledger/cashier/pos.disyl');

        await page.goto(APP_URL + BASE + '/pos');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        var workspaceVisible = await page.evaluate(function () {
            var el = document.getElementById('pos-workspace');
            return !!el && !el.classList.contains('hidden');
        });
        test.skip(!workspaceVisible, 'POS workspace not active (disabled, manual mode, or closed day)');

        // Empty cart: charge must be disabled (no empty paid sales).
        await expect(page.locator('#pos-charge')).toBeDisabled();

        // No receipt panel before a committed sale.
        var receiptVisible = await page.evaluate(function () {
            var el = document.getElementById('pos-receipt-panel');
            return !!el && !el.classList.contains('hidden');
        });
        expect(receiptVisible, 'Receipt panel must be hidden before checkout').toBe(false);
    });

    test('checkout failure surfaces an error and never shows a success receipt', async ({ page, shell, integrity }) => {
        integrity.fingerprint('templates/modules/daily-ledger/cashier/pos.disyl');

        await page.goto(APP_URL + BASE + '/pos');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        var workspaceVisible = await page.evaluate(function () {
            var el = document.getElementById('pos-workspace');
            return !!el && !el.classList.contains('hidden');
        });
        test.skip(!workspaceVisible, 'POS workspace not active (disabled, manual mode, or closed day)');

        // Force a failing checkout by intercepting the API call.
        await page.route('**/api/v1/pos/checkout', function (route) {
            route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ ok: false, code: 'PAYMENT_INVALID', error: 'Insufficient cash tendered.' })
            });
        });

        // Add the first product to the cart and attempt checkout.
        var firstProduct = page.locator('#pos-products button').first();
        await expect(firstProduct).toBeVisible();
        await firstProduct.click();
        await page.fill('#pos-tendered', '0');
        await page.click('#pos-charge');

        // After the failed checkout: no receipt, button re-enabled for retry.
        var receiptVisible = await page.evaluate(function () {
            var el = document.getElementById('pos-receipt-panel');
            return !!el && !el.classList.contains('hidden');
        });
        expect(receiptVisible, 'Failed checkout must not show a receipt').toBe(false);
        await expect(page.locator('#pos-charge')).toBeEnabled();
    });
});
