/**
 * PAL Project List, Detail, and Form page tests.
 *
 * PAL uses {ikb_entity_list} for list views and custom detail layouts
 * with pal_detail_header, not workbench components directly. Tests
 * validate against the entity-list component attributes added by
 * DefaultEntityRenderer, and check for PAL-specific UI patterns.
 *
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/projects-list.disyl
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/project-detail.disyl
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('PAL Project pages', () => {

    // ── Project List ──

    test('project list renders with entity-list component', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const entityList = page.locator('[data-wb-component="entity-list"]');
        await expect(entityList.first()).toBeVisible();
        await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_project');

        // Page should show "Job Orders" or "Projects" title
        const heading = page.locator('#wb-main h1');
        await expect(heading).toContainText(/Job Orders|Projects/i);
    });

    test('project list has create button and search', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        // New Job Order button (use first() to disambiguate from sidebar nav link)
        const createBtn = page.locator('a[href*="projects/create"]').first();
        await expect(createBtn).toBeVisible();

        // Search input (entity list provides it)
        const searchInput = page.locator('input[type="search"], input[placeholder*="Search"]').first();
        if (await searchInput.count() > 0) {
            await expect(searchInput).toBeVisible();
        }
    });

    test('project list navigates to detail via View action', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');
            const heading = page.locator('#wb-main h1');
            await expect(heading).toBeVisible();
        }
    });

    // ── Project Detail ──

    test('project detail page loads with header content', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // Page should have visible content (header, status, amounts)
            const h1 = page.locator('#wb-main h1');
            await expect(h1).toBeVisible();

            const mainContent = page.locator('#wb-main');
            const text = await mainContent.textContent() || '';
            expect(text.length).toBeGreaterThan(50);
        }
    });

    test('project detail URL contains numeric ID', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        // Find the first project's detail link from the "View" action
        const viewLink = page.locator('a:has-text("View")').first();
        const href = await viewLink.getAttribute('href');
        if (href) {
            const id = href.split('/').pop();
            if (id && /^\d+$/.test(id)) {
                await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/${id}`);
                await page.waitForSelector('#wb-main', { timeout: 10000 });
                expect(page.url()).toContain(id);
            }
        }
    });

    // ── Create Project Form ──

    test('create project form has required fields', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form', { timeout: 10000 });

        // Form should have a title
        const formTitle = page.locator('h2').filter({ hasText: /Job Order/i });
        await expect(formTitle.first()).toBeVisible();

        // Required form inputs
        await expect(page.locator('input[name="title"]')).toBeVisible();

        // Form has multiple input fields
        const form = page.locator('form');
        const inputs = form.locator('input, select, textarea');
        const count = await inputs.count();
        expect(count).toBeGreaterThanOrEqual(3);
    });
});
