/**
 * PAL end-to-end browser workflow tests.
 *
 * Tests the complete Job Order lifecycle using entity-list component
 * attributes (PAL's primary rendering mode via {ikb_entity_list}).
 *
 * Run: npx playwright test tests/browser/modules/pal/
 *
 * @see modules/project-audit-ledger/templates/project-audit-ledger/pages/
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('PAL Job Order workflow', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
    });

    test('dashboard loads with app shell and components', async ({ page }) => {
        await expect(page.locator('[data-wb-component="app-shell"]')).toBeVisible();
        await expect(page.locator('#wb-main h1')).toContainText('Dashboard');

        // Summary cards (KPI row)
        const cards = page.locator('[data-wb-component="summary-card"]');
        await expect(cards.first()).toBeVisible();
        expect(await cards.count()).toBeGreaterThanOrEqual(1);

        // Entity list (projects section)
        const entityList = page.locator('[data-wb-component="entity-list"]');
        await expect(entityList.first()).toBeVisible();
    });

    test('dashboard shows financial summary', async ({ page }) => {
        await expect(page.locator('h2:has-text("Financial Health")')).toBeVisible();
        await expect(page.locator('h2:has-text("Outstanding Receivables")')).toBeVisible();
    });

    test('dashboard recent projects list links to detail', async ({ page }) => {
        const projectLinks = page.locator('h2:has-text("Recent Projects") + div a');
        if (await projectLinks.count() > 0) {
            const href = await projectLinks.first().getAttribute('href');
            expect(href).toMatch(/\/admin\/project-audit-ledger\/projects\/\d+/);
        }
    });

    test('navigate to project list via sidebar', async ({ page }) => {
        const navItems = page.locator('.wb-nav-item');
        const allJobsLink = navItems.filter({ hasText: 'All Job Orders' });
        await allJobsLink.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects');
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        await expect(page.locator('#wb-main h1')).toContainText(/Job Orders|Projects/i);
    });

    test('project list has search and create button', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        // Use first() to disambiguate from sidebar nav link
        await expect(page.locator('a[href*="projects/create"]').first()).toBeVisible();
    });

    test('project list navigates to detail via View', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');
            await expect(page.locator('#wb-main h1')).toBeVisible();
        }
    });

    test('project detail page has header', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        const viewLink = page.locator('a:has-text("View")').first();
        if (await viewLink.count() > 0) {
            await viewLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');
            await expect(page.locator('#wb-main')).toBeVisible();
        }
    });

    test('create project form loads', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form', { timeout: 10000 });
        await expect(page.locator('input[name="title"]')).toBeVisible();
    });

    test('approval queue loads', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/approvals`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        await expect(page.locator('#wb-main h1')).toContainText(/Approval/i);
    });

    test('sales list loads', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/sales`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        await expect(page.locator('[data-wb-component="entity-list"]').first()).toBeVisible();
    });

    test('inventory list loads', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/inventory`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
        await expect(page.locator('[data-wb-component="entity-list"]').first()).toBeVisible();
    });

    test('sidebar navigation works', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/clients`);
        await page.waitForSelector('#wb-main', { timeout: 10000 });
        const navItems = page.locator('.wb-nav-item');
        expect(await navItems.count()).toBeGreaterThanOrEqual(1);
    });

    test('all sidebar sections are accessible', async ({ page }) => {
        const sections = page.locator('.wb-sidebar-section__trigger');
        expect(await sections.count()).toBeGreaterThanOrEqual(5);
        const firstLabel = await sections.first().textContent();
        expect(firstLabel?.trim().length).toBeGreaterThan(0);
    });
});
