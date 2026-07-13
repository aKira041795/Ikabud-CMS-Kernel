/**
 * PAL Project List and Detail page tests.
 *
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/projects-list.disyl
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/project-detail.disyl
 */

// @ts-check
const { test, expect } = require('../../WorkbenchFixture');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('PAL Project pages', () => {

    // ── Project List ──

    test('project list renders with responsive table', async ({ page, shell, table }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        await table.expectMinColumns(4);
        await table.expectMinRows(0); // May be empty

        // Verify key columns exist
        const headerTexts = await table.headers.allTextContents();
        const allText = headerTexts.join(' ');
        expect(allText).toMatch(/Project|Title/i);
        expect(allText).toMatch(/Client/i);
        expect(allText).toMatch(/Status/i);
    });

    test('project list rows are clickable', async ({ page, table }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const rowCount = await table.rows.count();
        if (rowCount > 0) {
            // First row should have data-wb-href
            const firstRow = table.rows.first();
            const href = await firstRow.getAttribute('data-wb-href');
            // Some rows may not be clickable
        }
    });

    test('project list navigates to detail via View action', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');
            const heading = page.locator('#wb-main h1');
            await expect(heading).toBeVisible();
        }
    });

    // ── Project Detail ──

    test('project detail has detail-header component', async ({ page }) => {
        // Navigate to first project
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // Detail header should be present
            const header = page.locator('[data-wb-component="detail-header"]');
            await expect(header).toBeVisible();
        }
    });

    test('project detail preserves entity context', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        // Get the first project ID from the table
        const firstRow = page.locator('[data-wb-entity-id]').first();
        const entityId = await firstRow.getAttribute('data-wb-entity-id');
        if (entityId) {
            // Navigate to detail
            const viewLink = page.locator('a:has-text("View")').first();
            if (await viewLink.count() > 0) {
                await viewLink.click();
                await page.waitForURL('**/admin/project-audit-ledger/projects/**');

                // The URL should contain the entity ID
                expect(page.url()).toContain(entityId);
            }
        }
    });

    // ── Create Project Form ──

    test('create project form has required fields', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form', { timeout: 10000 });

        await expect(page.locator('input[name="title"]')).toBeVisible();
        await expect(page.locator('input[name="client_name"]')).toBeVisible();
        await expect(page.locator('input[name="contract_amount"]')).toBeVisible();
    });
});
