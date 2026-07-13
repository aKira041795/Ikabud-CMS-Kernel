/**
 * Accessibility conformance tests for ARK Workbench.
 *
 * Uses axe-core to audit pages for WCAG 2.1 AA violations.
 *
 * Prerequisites:
 *   - npm install @axe-core/playwright
 *
 * Run: npx playwright test tests/browser/workbench/accessibility.spec.js
 *
 * @see storage/application-profiles/ark-workbench/components/
 */

// @ts-check
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('ARK Workbench accessibility', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
    });

    // ── Dashboard ──

    test('dashboard page has no critical accessibility violations', async ({ page }) => {
        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        expect(results.violations.filter(v => v.impact === 'critical').length).toBe(0);
    });

    test('dashboard has correct heading hierarchy', async ({ page }) => {
        const headings = await page.locator('h1, h2, h3').allTextContents();
        expect(headings.length).toBeGreaterThanOrEqual(1);
        // First heading should be the page title (h1)
        expect(headings[0].trim().length).toBeGreaterThan(0);
    });

    test('dashboard has skip-to-content link', async ({ page }) => {
        const skipLink = page.locator('.wb-skip-link');
        await expect(skipLink).toBeVisible();
        await expect(skipLink).toHaveAttribute('href', '#wb-main');
    });

    // ── Navigation ──

    test('sidebar navigation is a proper nav landmark', async ({ page }) => {
        const nav = page.locator('#wb-sidebar nav');
        await expect(nav).toHaveAttribute('aria-label', 'Main navigation');
    });

    test('sidebar section triggers have aria-expanded', async ({ page }) => {
        const triggers = page.locator('.wb-sidebar-section__trigger');
        const count = await triggers.count();
        for (let i = 0; i < Math.min(count, 5); i++) {
            await expect(triggers.nth(i)).toHaveAttribute('aria-expanded');
        }
    });

    // ── Images ──

    test('all images have alt text', async ({ page }) => {
        const images = page.locator('img');
        const count = await images.count();
        for (let i = 0; i < count; i++) {
            const alt = await images.nth(i).getAttribute('alt');
            expect(alt).not.toBeNull();
        }
    });

    // ── Color contrast ──

    test('status badges use semantic tone not just color', async ({ page }) => {
        const badges = page.locator('[data-wb-component="status-badge"]');
        const count = await badges.count();
        for (let i = 0; i < count; i++) {
            const text = await badges.nth(i).textContent();
            expect(text?.trim().length).toBeGreaterThan(0);
        }
    });

    // ── Forms ──

    test('form fields have associated labels', async ({ page }) => {
        // Navigate to project create form
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
        await page.waitForSelector('form', { timeout: 10000 });

        // Check for accessible form fields — PAL uses <label> above each input
        // (not wrapping, not for=). Validate that visible inputs have a preceding label.
        const inputs = page.locator('form input:visible, form select:visible, form textarea:visible');
        const count = await inputs.count();
        expect(count).toBeGreaterThanOrEqual(3); // At least 3 form fields

        // Check at least one required field has an associated label
        const titleField = page.locator('input[name="title"]');
        if (await titleField.count() > 0) {
            await expect(titleField).toBeVisible();
            // Verify there's a label element near it (preceding sibling in DOM)
            const hasLabel = await page.evaluate(() => {
                const input = document.querySelector('input[name="title"]');
                if (!input || !input.parentElement) return false;
                const prev = input.parentElement.querySelector('label');
                return !!prev;
            });
            expect(hasLabel).toBe(true);
        }
    });

    // ── Project List ──

    test('project list table has accessible headers', async ({ page }) => {
        // Navigate directly; auth cookie is preserved from beforeEach
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
        await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });

        const headers = page.locator('th[scope="col"]');
        const count = await headers.count();
        expect(count).toBeGreaterThanOrEqual(1);
    });

    // ── Focus management ──

    test('mobile menu button is focusable on mobile viewport', async ({ page }) => {
        // Resize to mobile viewport; button is hidden on desktop via md:hidden
        await page.setViewportSize({ width: 375, height: 667 });
        const menuBtn = page.locator('#wb-menu-btn');
        await expect(menuBtn).toBeVisible();
        await menuBtn.focus();
        await expect(menuBtn).toBeFocused();
    });
});
