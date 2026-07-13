/**
 * DialogHarness — Stable testing API for workbench:dialog.
 *
 * Tests interact with this harness rather than knowing the dialog's
 * internal DOM structure. Insulates tests from markup changes.
 *
 * Usage:
 *   const dialog = new DialogHarness(page);
 *   await dialog.expectTitle('Confirm');
 *   await dialog.confirm();
 *   await dialog.expectClosed();
 *
 * @see storage/application-profiles/ark-workbench/components/interaction/dialog.disyl
 */

// @ts-check

const { expect } = require('@playwright/test');

class DialogHarness {
    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        this.page = page;
    }

    get locator() {
        return this.page.locator('[data-wb-component="dialog"]');
    }

    byId(id) {
        return this.page.locator(`#${id}`);
    }

    async open(triggerSelector) {
        await this.page.locator(triggerSelector).click();
        await this.locator.waitFor({ state: 'visible', timeout: 5000 });
    }

    async expectVisible() {
        await expect(this.locator).toBeVisible();
    }

    async expectClosed() {
        await expect(this.locator).toBeHidden();
    }

    async expectTitle(expected) {
        const dialog = this.locator;
        const titleId = await dialog.getAttribute('aria-labelledby');
        if (titleId) {
            await expect(this.page.locator(`#${titleId}`)).toContainText(expected);
        }
    }

    async expectVariant(expected) {
        await expect(this.locator).toHaveAttribute('data-wb-dialog-variant', expected);
    }

    async confirm() {
        await this.locator.locator('button[type="submit"]').first().click();
    }

    async cancel() {
        await this.locator.locator('[data-wb-dialog-close]').click();
    }

    async expectModal() {
        await expect(this.locator).toHaveAttribute('aria-modal', 'true');
    }

    async expectBackdropClose(expected = true) {
        const value = await this.locator.getAttribute('data-wb-close-on-backdrop');
        expect(value).toBe(expected ? 'true' : 'false');
    }

    async expectFocusTrapped() {
        const focusable = this.locator.locator(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const count = await focusable.count();
        expect(count).toBeGreaterThanOrEqual(1);

        await focusable.last().focus();
        await this.page.keyboard.press('Tab');
        const activeDialog = await this.page.evaluate(() => {
            const el = document.activeElement;
            return el?.closest('[role="dialog"]') ? true : false;
        });
        expect(activeDialog).toBe(true);
    }
}

module.exports = { DialogHarness };
