/**
 * Daily Ledger — Cashier Full Name at Login (PW-1)
 *
 * Validates the client requirement:
 *   1. The login form has a Full Name field for cashiers.
 *   2. The entered full name is saved.
 *   3. The top nav shows the full name beside the branch-shift username.
 *
 * Env:
 *   TEST_BASE_URL    — target site (e.g. http://baronledger.test)
 *   TEST_CASHIER_USER — cashier username (branch-shift label, e.g. cashier-miputakAM)
 *   TEST_CASHIER_PASS — cashier password
 *   TEST_CASHIER_NAME — full name to type at login (e.g. "Maria Miputak")
 */

// @ts-check
var { test, expect } = require('@playwright/test');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var CASHIER_USER = process.env.TEST_CASHIER_USER || 'cashier-miputakAM';
var CASHIER_PASS = process.env.TEST_CASHIER_PASS || 'cmiputak123';
var CASHIER_NAME = process.env.TEST_CASHIER_NAME || 'Maria Miputak';

test.describe('Daily Ledger — Cashier Full Name at Login', () => {

    test('login form has a Full Name field', async ({ page }) => {
        await page.goto(APP_URL + '/daily-ledger/login');
        await page.waitForLoadState('domcontentloaded');

        await expect(page.locator('input[name="full_name"]'),
            'Full Name field must be present on the login form').toBeVisible();
        await expect(page.locator('label[for="full_name"]'),
            'Full Name field must have a label').toContainText('Full Name');
    });

    test('cashier logs in with full name and sees it beside username in top nav', async ({ page }) => {
        await page.goto(APP_URL + '/daily-ledger/login');
        await page.waitForLoadState('domcontentloaded');

        await page.fill('input[name="username"]', CASHIER_USER);
        await page.fill('input[name="full_name"]', CASHIER_NAME);
        await page.fill('input[name="password"]', CASHIER_PASS);

        // Cashier role lands on the ledger
        await Promise.all([
            page.waitForURL('**/daily-ledger/ledger**', { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // Top nav must show the entered full name (primary line)
        var header = page.locator('header');
        await expect(header, 'Top nav must show the entered full name')
            .toContainText(CASHIER_NAME, { timeout: 10000 });
        // ...beside the branch-shift username (secondary line)
        await expect(header, 'Top nav must show the cashier username')
            .toContainText(CASHIER_USER);
        // ...and the role
        await expect(header, 'Top nav must show the cashier role')
            .toContainText('cashier');
    });
});
