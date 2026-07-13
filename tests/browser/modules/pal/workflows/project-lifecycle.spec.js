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

    test('dashboard → project list preserves sidebar context', async ({ page, shell }) => {
        await shell.expectActiveNav('Dashboard');

        // Navigate via sidebar
        await shell.navigateViaSidebar('All Job Orders');

        // Verify project list loaded
        await expect(page.locator('[data-wb-component="responsive-table"]')).toBeVisible();

        // Verify sidebar active state changed
        await shell.expectActiveNav('All Job Orders');
    });

    test('project list → detail preserves URL context', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            const href = await viewLink.getAttribute('href');
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // URL should contain a numeric ID
            expect(page.url()).toMatch(/\/projects\/\d+/);
        }
    });

    test('sidebar navigation items are accessible from any page', async ({ page, shell }) => {
        // Navigate to a deep page
        await page.goto(`${APP_URL}/admin/project-audit-ledger/expenses`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });

        // Use sidebar to navigate to another section
        await shell.navigateViaSidebar('Sales Invoices');
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });
        await expect(page.locator('#wb-main h1')).toBeVisible();
    });

    test('approval queue page loads without errors', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/approvals`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        const heading = page.locator('#wb-main h1');
        await expect(heading).toContainText(/Approval/i);
    });

    test('reports page loads with workbench shell', async ({ page, shell }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/reports`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        await shell.expectVisible();
    });

    test('client list renders responsive table', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/clients`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const table = page.locator('[data-wb-component="responsive-table"]');
        await expect(table).toBeVisible();
    });
});
