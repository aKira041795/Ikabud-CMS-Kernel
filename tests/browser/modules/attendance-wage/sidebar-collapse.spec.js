// @ts-nocheck
var { test, expect } = require('@playwright/test');
var path = require('path');

test.use({ storageState: path.resolve('test_results/browser/.auth/attendance-wage-admin.json') });

var APP_URL = process.env.APP_URL || 'http://zapattendance.test';
var ADMIN_USER = process.env.ADMIN_USER || 'zapadmin';
var ADMIN_PASS = process.env.ADMIN_PASS || 'zap123!#';

test.describe('attendance-wage:sidebar-collapse', function () {
    test.use({ viewport: { width: 1280, height: 900 } });

    test('desktop sidebar collapses and expands', async function ({ page }) {
        await page.goto(APP_URL + '/admin/wage', { waitUntil: 'networkidle' });

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
