/**
 * PAL Dashboard page tests.
 *
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/dashboard.disyl
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');

test.describe('PAL Dashboard page', () => {

    test('renders with all workbench components', async ({ page, shell, table }) => {
        await shell.expectVisible();
        await shell.expectPageTitle('Dashboard');
        await shell.expectUserDisplayed();
        await shell.expectAppName();

        // Summary cards (KPI row)
        const cards = page.locator('[data-wb-component="summary-card"]');
        await expect(cards.first()).toBeVisible();
        const cardCount = await cards.count();
        expect(cardCount).toBeGreaterThanOrEqual(1);

        // Responsive table (All Projects)
        await table.expectMinColumns(3);
    });

    test('displays financial summary sections', async ({ page }) => {
        await expect(page.locator('h2:has-text("Financial Health")')).toBeVisible();
        await expect(page.locator('h2:has-text("Outstanding Receivables")')).toBeVisible();
        await expect(page.locator('h2:has-text("Recent Projects")')).toBeVisible();
    });

    test('sidebar has correct active navigation', async ({ page, shell }) => {
        await shell.expectActiveNav('Dashboard');
    });

    test('dashboard stat cards show formatted values', async ({ page }) => {
        const values = page.locator('[data-wb-component="summary-card"] .wb-summary-card__value');
        const count = await values.count();
        for (let i = 0; i < count; i++) {
            const text = await values.nth(i).textContent();
            expect(text?.trim().length).toBeGreaterThan(0);
        }
    });

    test('all projects table has entity metadata', async ({ page, table }) => {
        await table.expectEntityMetadata('pal_project', 'populated');
    });
});
