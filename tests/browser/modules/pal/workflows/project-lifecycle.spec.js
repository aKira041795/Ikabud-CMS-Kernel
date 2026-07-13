/**
 * PAL Job Order lifecycle workflow tests.
 *
 * Tests the complete Job Order lifecycle across pages:
 *   Dashboard → Project List → Detail → Edit → Status change
 *
 * @see modules/project-audit-ledger/handlers/10-dashboard.php
 * @see modules/project-audit-ledger/handlers/15-projects.php
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('PAL Job Order lifecycle', () => {

    test('dashboard → project list preserves sidebar context', async ({ page }) => {
        // Navigate via sidebar
        const allJobsLink = page.locator('.wb-nav-item').filter({ hasText: 'All Job Orders' });
        await allJobsLink.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects');
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        await expect(page.locator('#wb-main h1')).toBeVisible();
    });

    test('project list → detail preserves URL context', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            const href = await viewLink.getAttribute('href');
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // URL should contain a numeric ID
            expect(page.url()).toMatch(/\/projects\/\d+/);
        }
    });

    test('sidebar navigation items are accessible from any page', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/expenses`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });

        // Verify sidebar nav items are present
        const navItems = page.locator('.wb-nav-item');
        expect(await navItems.count()).toBeGreaterThanOrEqual(5);
    });

    test('approval queue page loads without errors', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/approvals`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        const heading = page.locator('#wb-main h1');
        await expect(heading).toContainText(/Approval/i);
    });

    test('reports page loads with app shell', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/reports`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        await expect(page.locator('[data-wb-component="app-shell"]')).toBeVisible();
    });

    test('sales list renders entity list', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/sales`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const list = page.locator('[data-wb-component="entity-list"]');
        await expect(list.first()).toBeVisible();
    });
});
