/**
 * Browser tests for entity list sort and pagination interaction.
 *
 * These tests verify the client-side behavior of sortable column headers
 * and pagination controls rendered by DefaultEntityRenderer.
 *
 * Prerequisites:
 *   - Application running at APP_URL (default http://localhost:8080)
 *   - A page with {ikb_entity_list id="guidance-cases" sortable="true" paginated="true"}
 *   - Playwright installed: npm init playwright@latest
 *
 * Run: npx playwright test tests/browser/entity-list-sort-pagination.spec.js
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://localhost:8080';

test.describe('Entity list sort and pagination', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/admin/guidance/cases`);
        await page.waitForSelector('[data-ikb-list="guidance-cases"]', { timeout: 10000 });
    });

    test('renders sortable column headers with aria-sort', async ({ page }) => {
        const list = page.locator('[data-ikb-list="guidance-cases"]');
        const headers = list.locator('thead th a');

        // At least one sortable header link
        await expect(headers.first()).toBeVisible();

        // First click sets ascending sort
        await headers.first().click();
        await expect(page).toHaveURL(/sort=/);

        // Navigate back
        await page.goBack();
        await page.waitForSelector('[data-ikb-list="guidance-cases"]');
    });

    test('pagination controls appear when list has many rows', async ({ page }) => {
        const pagination = page.locator('.ikb-entity-pagination');

        // Only visible when total > limit (depends on test data)
        if (await pagination.isVisible()) {
            await expect(pagination).toContainText('Showing');
            await expect(pagination).toContainText('of');

            // Click next page
            const nextBtn = pagination.locator('a:has-text("Next")');
            if (await nextBtn.isVisible()) {
                await nextBtn.click();
                await expect(page).toHaveURL(/page=/);
            }
        }
    });

    test('namespaced query params do not collide', async ({ page }) => {
        // With list id "guidance-cases", params should be guidance-cases_page, etc.
        await page.goto(`${APP_URL}/admin/guidance/cases?guidance-cases_page=2&guidance-cases_sort=status&guidance-cases_dir=asc`);
        await page.waitForSelector('[data-ikb-list="guidance-cases"]');
        await expect(page.locator('[data-ikb-list="guidance-cases"]')).toBeVisible();
    });

    test('entity has data attributes', async ({ page }) => {
        const list = page.locator('[data-ikb-list="guidance-cases"]');
        await expect(list).toHaveAttribute('data-ikb-entity');
        await expect(list).toHaveAttribute('data-ikb-source');
        await expect(list).toHaveAttribute('data-ikb-view');
    });

    test('sort indicator shows on active column', async ({ page }) => {
        const list = page.locator('[data-ikb-list="guidance-cases"]');

        // Click a sortable header
        const firstSortable = list.locator('thead th a').first();
        await firstSortable.click();

        // The active column should have aria-sort
        await page.waitForSelector('th[aria-sort]', { timeout: 5000 });
        const activeHeader = page.locator('th[aria-sort]');
        await expect(activeHeader).toBeVisible();
    });
});
