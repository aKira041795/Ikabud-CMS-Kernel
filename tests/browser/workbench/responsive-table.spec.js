/**
 * Browser tests for ARK Workbench responsive-table component.
 *
 * Run: npx playwright test tests/browser/workbench/responsive-table.spec.js
 *
 * @see storage/application-profiles/ark-workbench/components/data/responsive_table.disyl
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('workbench:responsive_table', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });
    });

    test('renders with data-wb-component attribute', async ({ page }) => {
        const table = page.locator('[data-wb-component="responsive-table"] table');
        await expect(table).toBeVisible();
    });

    test('has column headers with scope attribute', async ({ page }) => {
        const headers = page.locator('[data-wb-component="responsive-table"] th[scope="col"]');
        const count = await headers.count();
        expect(count).toBeGreaterThanOrEqual(1);
    });

    test('each row has data-label on cells', async ({ page }) => {
        const cells = page.locator('[data-wb-component="responsive-table"] td[data-label]');
        const count = await cells.count();
        expect(count).toBeGreaterThanOrEqual(1);

        // Verify first cell has a non-empty data-label
        const firstLabel = await cells.first().getAttribute('data-label');
        expect(firstLabel?.length).toBeGreaterThan(0);
    });

    test('clickable rows have data-wb-href', async ({ page }) => {
        const clickableRows = page.locator('[data-wb-component="responsive-table"] tr[data-wb-href]');
        const count = await clickableRows.count();
        if (count > 0) {
            const href = await clickableRows.first().getAttribute('data-wb-href');
            expect(href).toBeTruthy();
        }
    });

    test('entity rows have data-wb-entity-id', async ({ page }) => {
        const rows = page.locator('[data-wb-component="responsive-table"] tr[data-wb-entity-id]');
        const count = await rows.count();
        if (count > 0) {
            const entityId = await rows.first().getAttribute('data-wb-entity-id');
            expect(entityId).toBeTruthy();
        }
    });
});
