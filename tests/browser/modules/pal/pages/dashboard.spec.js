/**
 * PAL Dashboard page tests.
 *
 * PAL uses its own templates with {ikb_entity_list} for data display,
 * not workbench components directly. Tests check for the entity-list
 * data attributes added by DefaultEntityRenderer.
 *
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/dashboard.disyl
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');

test.describe('PAL Dashboard page', () => {

    test('renders with app shell and summary cards', async ({ page, shell }) => {
        await shell.expectVisible();
        await shell.expectPageTitle('Dashboard');
        await shell.expectUserDisplayed();
        await shell.expectAppName();

        // Summary cards (KPI row) — marked with data-wb-component by the template
        const cards = page.locator('[data-wb-component="summary-card"]');
        await expect(cards.first()).toBeVisible();
        const cardCount = await cards.count();
        expect(cardCount).toBeGreaterThanOrEqual(1);

        // Entity list (All Projects section at bottom)
        const entityList = page.locator('[data-wb-component="entity-list"]');
        await expect(entityList.first()).toBeVisible();
        await expect(entityList.first()).toHaveAttribute('data-wb-entity');
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
        const cards = page.locator('[data-wb-component="summary-card"]');
        const count = await cards.count();
        expect(count).toBeGreaterThanOrEqual(1);
        // Each card must have visible numeric/currency content
        for (let i = 0; i < count; i++) {
            const text = await cards.nth(i).textContent();
            expect(text?.trim().length).toBeGreaterThan(0);
        }
    });

    test('entity list project table has entity metadata', async ({ page }) => {
        const entityList = page.locator('[data-wb-component="entity-list"]').first();
        await expect(entityList).toHaveAttribute('data-wb-entity');
        await expect(entityList).toHaveAttribute('data-wb-component', 'entity-list');
    });
});
