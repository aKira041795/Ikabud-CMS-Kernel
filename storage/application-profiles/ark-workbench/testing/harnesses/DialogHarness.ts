/**
 * DialogHarness — Stable testing API for workbench:dialog.
 *
 * Tests interact with this harness rather than knowing the dialog's
 * internal DOM structure. Insulates tests from markup changes.
 *
 * Usage:
 *   const dialog = DialogHarness.from(page);
 *   await dialog.expectTitle('Confirm');
 *   await dialog.confirm();
 *   await dialog.expectClosed();
 *
 * @see storage/application-profiles/ark-workbench/components/interaction/dialog.disyl
 */

// @ts-check

/** @typedef {import('@playwright/test').Page} Page */

class DialogHarness {
    /** @param {Page} page */
    constructor(page) {
        this.page = page;
    }

    /** Locate a dialog by its data-wb-component attribute */
    get locator() {
        return this.page.locator('[data-wb-component="dialog"]');
    }

    /** Locate a specific dialog by ID */
    byId(id) {
        return this.page.locator(`#${id}`);
    }

    /** Open a dialog by clicking its trigger */
    async open(triggerSelector) {
        await this.page.locator(triggerSelector).click();
        await this.locator.waitFor({ state: 'visible', timeout: 5000 });
    }

    /** Assert the dialog is visible */
    async expectVisible() {
        await expect(this.locator).toBeVisible();
    }

    /** Assert the dialog is hidden */
    async expectClosed() {
        await expect(this.locator).toBeHidden();
    }

    /** Assert the dialog title */
    async expectTitle(expected) {
        const dialog = this.locator;
        const titleId = await dialog.getAttribute('aria-labelledby');
        if (titleId) {
            await expect(this.page.locator(`#${titleId}`)).toContainText(expected);
        }
    }

    /** Assert the dialog variant attribute */
    async expectVariant(expected) {
        await expect(this.locator).toHaveAttribute('data-wb-dialog-variant', expected);
    }

    /** Click the primary confirm button (first button in footer section) */
    async confirm() {
        await this.locator.locator('button[type="submit"]').first().click();
    }

    /** Click the cancel button */
    async cancel() {
        await this.locator.locator('[data-wb-dialog-close]').click();
    }

    /** Assert aria-modal is true */
    async expectModal() {
        await expect(this.locator).toHaveAttribute('aria-modal', 'true');
    }

    /** Assert backdrop close behavior */
    async expectBackdropClose(expected = true) {
        const value = await this.locator.getAttribute('data-wb-close-on-backdrop');
        expect(value).toBe(expected ? 'true' : 'false');
    }

    /** Assert focus is trapped within dialog (Tab cycles) */
    async expectFocusTrapped() {
        const focusable = this.locator.locator(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const count = await focusable.count();
        expect(count).toBeGreaterThanOrEqual(1);

        // Tab to last element, then Tab again — should stay in dialog
        await focusable.last().focus();
        await this.page.keyboard.press('Tab');
        const activeDialog = await this.page.evaluate(() => {
            const el = document.activeElement;
            return el?.closest('[role="dialog"]') ? true : false;
        });
        expect(activeDialog).toBe(true);
        module.exports = { DialogHarness };

