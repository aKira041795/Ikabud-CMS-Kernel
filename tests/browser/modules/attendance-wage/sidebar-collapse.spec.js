// @ts-nocheck
var { test, expect } = require('@playwright/test');

var APP_URL = process.env.APP_URL || 'http://zapattendance.test';
var ADMIN_USER = process.env.ADMIN_USER || 'zapadmin';
var ADMIN_PASS = process.env.ADMIN_PASS || 'zap123!#';

test.describe('attendance-wage:sidebar-collapse', function () {
    test.use({ viewport: { width: 1280, height: 900 } });

    test('desktop sidebar collapses and expands', async function ({ page }) {
        await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle' });

        async function loginOnce() {
            await page.fill('input[name="username"]', ADMIN_USER);
            await page.fill('input[name="password"]', ADMIN_PASS);
            await page.click('button[type="submit"]');
            await page.waitForURL('**/admin/wage**', { timeout: 15000 });
        }

        try {
            await loginOnce();
        } catch (error) {
            var bodyText = await page.locator('body').textContent().catch(function () { return ''; });
            if (bodyText && bodyText.indexOf('Too many login') >= 0) {
                var match = bodyText.match(/retry_after["':]\s*(\d+)/);
                var waitSec = match ? parseInt(match[1], 10) + 5 : 120;
                await page.waitForFunction(function (targetTime) { return Date.now() >= targetTime; }, Date.now() + (waitSec * 1000));
                await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle' });
                await loginOnce();
            } else {
                throw error;
            }
        }

        var sidebar = page.locator('aside').first();
        var toggle = page.getByRole('button', { name: 'Collapse sidebar' });
        var dashboardLabel = page.locator('aside nav a[href="/admin/wage"] span').filter({ hasText: 'Dashboard' });

        await expect(sidebar).toHaveClass(/w-64/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(dashboardLabel).toBeVisible();

        await toggle.click();

        await expect(sidebar).toHaveClass(/w-20/);
        await expect(page.getByRole('button', { name: 'Expand sidebar' })).toHaveAttribute('aria-expanded', 'false');
        await expect(dashboardLabel).toBeHidden();

        await page.getByRole('button', { name: 'Expand sidebar' }).click();

        await expect(sidebar).toHaveClass(/w-64/);
        await expect(page.getByRole('button', { name: 'Collapse sidebar' })).toHaveAttribute('aria-expanded', 'true');
        await expect(dashboardLabel).toBeVisible();

        await page.getByRole('button', { name: 'Collapse sidebar' }).click();
        await page.locator('aside nav a[href="/admin/attendance"]').click();
        await expect(page).toHaveURL(/\/admin\/attendance/);
    });
});
