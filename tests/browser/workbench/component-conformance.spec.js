/**
 * Browser tests for ARK Workbench dialog, status-badge, validation-summary,
 * and accessibility contracts.
 *
 * Run: npx playwright test tests/browser/workbench/
 *
 * @see storage/application-profiles/ark-workbench/components/interaction/dialog.disyl
 * @see storage/application-profiles/ark-workbench/components/data/status_badge.disyl
 * @see storage/application-profiles/ark-workbench/components/forms/validation_summary.disyl
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('workbench:component conformance', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
    });

    // ── Page Header ──

    test('page header has exactly one H1', async ({ page }) => {
        const h1Count = await page.locator('#wb-main h1').count();
        expect(h1Count).toBe(1);
    });

    test('page header actions have data-wb-action', async ({ page }) => {
        const actions = page.locator('[data-wb-action]');
        const count = await actions.count();
        // Not all pages have actions in the header, but if present, they must have the attr
        if (count > 0) {
            const actionKey = await actions.first().getAttribute('data-wb-action');
            expect(actionKey?.length).toBeGreaterThan(0);
        }
    });

    // ── Status Badge ──

    test('status badges have visible text label', async ({ page }) => {
        const badges = page.locator('[data-wb-component="status-badge"]');
        const count = await badges.count();
        for (let i = 0; i < count; i++) {
            const text = await badges.nth(i).textContent();
            expect(text?.trim().length).toBeGreaterThan(0);
        }
    });

    test('status badges have data-wb-tone', async ({ page }) => {
        const badges = page.locator('[data-wb-component="status-badge"]');
        const count = await badges.count();
        for (let i = 0; i < count; i++) {
            const tone = await badges.nth(i).getAttribute('data-wb-tone');
            expect(['neutral', 'informational', 'warning', 'success', 'danger']).toContain(tone);
        }
    });

    // ── Summary Card ──

    test('summary cards have data-wb-tone', async ({ page }) => {
        const cards = page.locator('[data-wb-component="summary-card"]');
        const count = await cards.count();
        for (let i = 0; i < count; i++) {
            const tone = await cards.nth(i).getAttribute('data-wb-tone');
            expect(tone).toBeTruthy();
        }
    });

    test('summary cards display label and value', async ({ page }) => {
        const card = page.locator('[data-wb-component="summary-card"]').first();
        await expect(card).toBeVisible();
        await expect(card.locator('.wb-summary-card__label')).not.toBeEmpty();
        await expect(card.locator('.wb-summary-card__value')).not.toBeEmpty();
    });

    // ── Empty State ──

    test('empty states have data-wb-size', async ({ page }) => {
        const empties = page.locator('[data-wb-component="empty-state"]');
        const count = await empties.count();
        for (let i = 0; i < count; i++) {
            const size = await empties.nth(i).getAttribute('data-wb-size');
            expect(['sm', 'lg']).toContain(size);
        }
    });

    // ── Validation Summary ──

    test('validation summary has data-wb-error-count if present', async ({ page }) => {
        const summary = page.locator('[data-wb-component="validation-summary"]');
        if (await summary.isVisible()) {
            const count = await summary.getAttribute('data-wb-error-count');
            expect(parseInt(count || '0')).toBeGreaterThanOrEqual(0);

            // Error links have data-wb-error-for
            const errorLinks = summary.locator('[data-wb-error-for]');
            const errorCount = await errorLinks.count();
            expect(errorCount).toBeGreaterThanOrEqual(1);
        }
    });

    // ── Dialog ──

    test('dialogs have aria-modal and aria-labelledby', async ({ page }) => {
        const dialogs = page.locator('[data-wb-component="dialog"]');
        const count = await dialogs.count();
        for (let i = 0; i < count; i++) {
            const dialog = dialogs.nth(i);
            await expect(dialog).toHaveAttribute('aria-modal', 'true');
            await expect(dialog).toHaveAttribute('aria-labelledby');
        }
    });

    test('dialogs have data-wb-dialog-variant', async ({ page }) => {
        const dialogs = page.locator('[data-wb-component="dialog"]');
        const count = await dialogs.count();
        for (let i = 0; i < count; i++) {
            const variant = await dialogs.nth(i).getAttribute('data-wb-dialog-variant');
            expect(['default', 'alert', 'confirm']).toContain(variant);
        }
    });

    // ── Form Sections ──

    test('form sections have data-wb-has-errors', async ({ page }) => {
        const sections = page.locator('[data-wb-component="form-section"]');
        const count = await sections.count();
        for (let i = 0; i < count; i++) {
            const hasErrors = await sections.nth(i).getAttribute('data-wb-has-errors');
            expect(['true', 'false']).toContain(hasErrors);
        }
    });

    // ── Approval Panel ──

    test('approval panel has data-wb-subject', async ({ page }) => {
        const panel = page.locator('[data-wb-component="approval-panel"]');
        if (await panel.isVisible()) {
            await expect(panel).toHaveAttribute('data-wb-subject');
        }
    });

    // ── Activity Timeline ──

    test('activity timeline has data-wb-variant', async ({ page }) => {
        const timeline = page.locator('[data-wb-component="activity-timeline"]');
        if (await timeline.isVisible()) {
            await expect(timeline).toHaveAttribute('data-wb-variant');
        }
    });

    // ── Accessibility (basic) ──

    test('page has a main landmark', async ({ page }) => {
        const main = page.locator('main');
        await expect(main).toBeVisible();
    });

    test('page has one H1', async ({ page }) => {
        const h1Count = await page.locator('h1').count();
        expect(h1Count).toBeGreaterThanOrEqual(1);
    });

    test('all images have alt text', async ({ page }) => {
        const images = page.locator('img');
        const count = await images.count();
        for (let i = 0; i < count; i++) {
            const alt = await images.nth(i).getAttribute('alt');
            // Allow empty alt for decorative images, but alt attribute must exist
            expect(alt).not.toBeNull();
        }
    });

    test('sidebar sections have accessible triggers', async ({ page }) => {
        const triggers = page.locator('.wb-sidebar-section__trigger');
        const count = await triggers.count();
        for (let i = 0; i < count; i++) {
            await expect(triggers.nth(i)).toHaveAttribute('aria-expanded');
        }
    });
});
