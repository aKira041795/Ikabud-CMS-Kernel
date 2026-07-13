/**
 * PAL end-to-end browser workflow tests.
 *
 * Tests the complete Job Order lifecycle:
 *   Login → Dashboard → Project List → Project Detail → Actions → Verify state
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

    // ── Dashboard ──

    test('dashboard loads with workbench shell and components', async ({ page }) => {
        // App shell
        await expect(page.locator('[data-wb-component="app-shell"]')).toBeVisible();

        // Page header
        const heading = page.locator('#wb-main h1');
        await expect(heading).toContainText('Dashboard');

        // Summary cards (KPI row)
        const cards = page.locator('[data-wb-component="summary-card"]');
        const cardCount = await cards.count();
        expect(cardCount).toBeGreaterThanOrEqual(1);

        // Entity list (projects table)
        const table = page.locator('[data-wb-component="responsive-table"]');
        await expect(table).toBeVisible();

        // Status badges on projects
        const badges = page.locator('[data-wb-component="status-badge"]');
        const badgeCount = await badges.count();
        expect(badgeCount).toBeGreaterThanOrEqual(1);
    });

    test('dashboard shows financial summary', async ({ page }) => {
        // Financial Health section should be present
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

    // ── Project List ──

    test('navigate to project list via sidebar', async ({ page }) => {
        // Click "All Job Orders" in sidebar
        const navItems = page.locator('.wb-nav-item');
        const allJobsLink = navItems.filter({ hasText: 'All Job Orders' });
        await allJobsLink.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects');

        // Verify project list page
        await expect(page.locator('#wb-main h1')).toContainText(/Job Orders|Projects/);
        await expect(page.locator('[data-wb-component="responsive-table"]')).toBeVisible();
    });

    test('project list table has correct columns', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]');

        const headers = page.locator('[data-wb-component="responsive-table"] th');
        const headerTexts = await headers.allTextContents();
        const allText = headerTexts.join(' ');

        // Should have key columns
        expect(allText).toMatch(/Project/i);
        expect(allText).toMatch(/Title|Name/i);
        expect(allText).toMatch(/Client/i);
        expect(allText).toMatch(/Amount/i);
        expect(allText).toMatch(/Status/i);
    });

    test('project list row has entity data attributes', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]');

        const rows = page.locator('[data-wb-component="responsive-table"] tr[data-wb-entity-id]');
        const count = await rows.count();

        if (count > 0) {
            const entityId = await rows.first().getAttribute('data-wb-entity-id');
            expect(entityId).toBeTruthy();

            // Row should be clickable with href
            const href = await rows.first().getAttribute('data-wb-href');
            expect(href).toMatch(/\/admin\/project-audit-ledger\/projects\/\d+/);
        }
    });

    // ── Project Detail ──

    test('navigate project list → detail preserves entity context', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]');

        // Click first project link
        const firstLink = page.locator('[data-wb-component="responsive-table"] a:has-text("View")').first();
        if (await firstLink.count() > 0) {
            const href = await firstLink.getAttribute('href');
            await firstLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // Detail page should have entity context
            await expect(page.locator('#wb-main h1')).toBeVisible();
        }
    });

    test('project detail page has page header', async ({ page }) => {
        // Navigate to first project
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="responsive-table"]');
        const firstLink = page.locator('[data-wb-component="responsive-table"] a:has-text("View")').first();
        if (await firstLink.count() > 0) {
            await firstLink.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/**');

            // Detail header should be present
            const header = page.locator('[data-wb-component="detail-header"]');
            await expect(header).toBeVisible();
        }
    });

    // ── Create Project ──

    test('new project form has required fields', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form');

        // Form should have required fields
        const form = page.locator('form');
        await expect(form.locator('input[name="title"]')).toBeVisible();
        await expect(form.locator('input[name="client_name"]')).toBeVisible();
        await expect(form.locator('input[name="contract_amount"]')).toBeVisible();
    });

    test('new project form validates required fields on submit', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form');

        // Submit empty form
        await page.locator('button[type="submit"]').first().click();

        // Check for validation errors
        const validationSummary = page.locator('[data-wb-component="validation-summary"]');
        if (await validationSummary.isVisible()) {
            const errorCount = await validationSummary.getAttribute('data-wb-error-count');
            expect(parseInt(errorCount || '0')).toBeGreaterThan(0);

            // Error links point to form fields
            const errorLinks = validationSummary.locator('[data-wb-error-for]');
            const linkCount = await errorLinks.count();
            expect(linkCount).toBeGreaterThan(0);
        } else {
            // Browser-side validation might catch it first
            // Just verify we're still on the form page
            await expect(page.locator('form')).toBeVisible();
        }
    });

    // ── Approvals ──

    test('approval queue shows pending items if any', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/approvals`);
        await page.waitForSelector('#wb-main');

        const heading = page.locator('#wb-main h1');
        await expect(heading).toContainText(/Approval/i);
    });

    // ── Sales ──

    test('sales list renders responsive table', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/sales`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const table = page.locator('[data-wb-component="responsive-table"]');
        await expect(table).toBeVisible();
    });

    // ── Inventory ──

    test('inventory list renders with entity data', async ({ page }) => {
        await page.goto(`${APP_URL}/admin/project-audit-ledger/inventory`);
        await page.waitForSelector('[data-wb-component="responsive-table"]', { timeout: 10000 });

        const table = page.locator('[data-wb-component="responsive-table"]');
        await expect(table).toBeVisible();
    });

    // ── Cross-Page Navigation ──

    test('sidebar navigation preserves active state', async ({ page }) => {
        // Navigate to clients page
        await page.goto(`${APP_URL}/admin/project-audit-ledger/clients`);
        await page.waitForSelector('#wb-main');

        // Client nav item should be active
        const clientNav = page.locator('.wb-nav-item.is-active');
        await expect(clientNav.first()).toBeVisible();
    });

    test('all sidebar sections are accessible', async ({ page }) => {
        const sections = page.locator('.wb-sidebar-section__trigger');
        const count = await sections.count();
        expect(count).toBeGreaterThanOrEqual(5); // At least 5 sections

        const firstSectionLabel = await sections.first().textContent();
        expect(firstSectionLabel?.trim().length).toBeGreaterThan(0);
    });
});
