/**
 * BehaviorFlow — Runtime behavioral testing engine.
 *
 * Unlike the static diagnostic (which checks if elements exist),
 * BehaviorFlow INTERACTS with the page like a real user:
 *   - Clicks buttons (Add Item, Save, Submit)
 *   - Fills form fields
 *   - Monitors toast messages and warnings
 *   - Detects unsaved-changes dialogs
 *   - Validates that interactions produce expected state changes
 *
 * This catches the issues a static analysis never can:
 *   - "You might lose data on reload" toasts with no save
 *   - "Add Item" buttons that don't create rows
 *   - Forms that submit but don't redirect
 *   - Validation errors that don't clear
 *
 * @see ModuleDiagnostic.js for static structure checks
 */

// @ts-check
const { expect } = require('@playwright/test');

class BehaviorFlow {

    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        this.page = page;
        /** @type {Array<{type:string, severity:string, message:string, url:string}>} */
        this.observations = [];
        this._toastObserver = null;
    }

    /**
     * Start monitoring for toast messages and browser dialogs.
     */
    async startMonitoring() {
        // Monitor toast messages (common pattern: showToast(), toast container, alert banners)
        this._toastObserver = this.page.locator('#toast, #wb-toast-container, [data-wb-component="toast"], .toast, [class*="toast"]');
    }

    /**
     * Get all visible toast/notification messages currently on the page.
     * @returns {Promise<string[]>}
     */
    async getVisibleToasts() {
        const toasts = [];
        const locators = [
            '#toast.show, #toast:not(.hidden)',
            '#wb-toast-container [role="status"], #wb-toast-container .show',
            '[data-wb-component="toast"]:not(.hidden)',
            '.toast:not(.hidden)',
            '[class*="toast"]:not(.hidden)',
            '[role="alert"]:visible',
        ];
        for (const sel of locators) {
            const els = this.page.locator(sel);
            const count = await els.count().catch(() => 0);
            for (let i = 0; i < count; i++) {
                const text = await els.nth(i).textContent().catch(() => '');
                if (text && text.trim()) toasts.push(text.trim());
            }
        }
        return [...new Set(toasts)];
    }

    /**
     * Detect unsaved-changes warnings (beforeunload, dirty flags).
     * @returns {Promise<boolean>}
     */
    async hasUnsavedChangesWarning() {
        return await this.page.evaluate(() => {
            // Check for beforeunload handler
            const beforeUnload = window.onbeforeunload;
            if (beforeUnload) return true;
            // Check for common dirty-flag patterns
            const dirty = document.querySelector('[data-dirty="true"], .is-dirty, .unsaved');
            if (dirty) return true;
            return false;
        }).catch(() => false);
    }

    /**
     * Wait for a toast message to appear (with timeout).
     * @param {number} timeoutMs
     * @returns {Promise<string|null>} The toast text, or null if none appeared
     */
    async waitForToast(timeoutMs = 3000) {
        const start = Date.now();
        while (Date.now() - start < timeoutMs) {
            const toasts = await this.getVisibleToasts();
            if (toasts.length > 0) return toasts.join(' | ');
            await this.page.waitForTimeout(200);
        }
        return null;
    }

    /**
     * Click a button by its text content (flexible matching).
     * @param {string|RegExp} text
     */
    async clickButton(text) {
        const btn = this.page.locator('button, a[role="button"], [data-wb-action]').filter({ hasText: text }).first();
        await btn.click();
        await this.page.waitForTimeout(300);
    }

    /**
     * Fill a form field by its label text or name.
     * @param {string|RegExp} labelOrName
     * @param {string} value
     */
    async fillField(labelOrName, value) {
        // Try by label text first
        const byLabel = this.page.locator(`label`).filter({ hasText: labelOrName }).first();
        if (await byLabel.isVisible({ timeout: 500 }).catch(() => false)) {
            const forAttr = await byLabel.getAttribute('for').catch(() => null);
            if (forAttr) {
                const input = this.page.locator(`#${forAttr}`);
                if (await input.isVisible({ timeout: 300 }).catch(() => false)) {
                    await input.fill(value);
                    return;
                }
            }
            // Input wrapped in label
            const wrapped = byLabel.locator('input, select, textarea');
            if (await wrapped.count() > 0) {
                await wrapped.first().fill(value);
                return;
            }
        }
        // Try by name
        const byName = this.page.locator(`[name="${labelOrName}"]`).first();
        if (await byName.isVisible({ timeout: 300 }).catch(() => false)) {
            await byName.fill(value);
            return;
        }
        console.log(`  ℹ Could not find field: ${labelOrName}`);
    }

    /**
     * Run the complete job order creation flow as a human would.
     * This catches runtime UX issues: missing toasts, unsaved warnings, broken interactions.
     */
    async runJobOrderFlow() {
        const base = '/admin/project-audit-ledger';
        const observations = [];

        // ── Step 1: Navigate to create form ──
        console.log('\n  🔄 Flow: Create Job Order');
        await this.page.goto(`${process.env.APP_URL || 'http://palsystem.test'}${base}/projects/create`);
        await this.page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // ── Step 2: Fill title ──
        const title = `FlowTest-${Date.now()}`;
        await this.fillField('title', title);
        console.log(`  ✓ Filled title: ${title}`);

        // ── Step 3: Try to add a line item ──
        const addItemBtn = this.page.locator('button:has-text("Add"), [data-wb-action="add-item"], button:has-text("Item")').first();
        if (await addItemBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await addItemBtn.click();
            await this.page.waitForTimeout(500);
            console.log('  ✓ Clicked Add Item button');

            // Check if new row appeared
            const newRow = this.page.locator('[class*="item-row"], [class*="po-row"], [class*="line-item"], tr:last-child input').first();
            const rowAppeared = await newRow.isVisible({ timeout: 2000 }).catch(() => false);
            if (rowAppeared) {
                console.log('  ✓ New line item row appeared');
            } else {
                observations.push({
                    type: 'interaction',
                    severity: 'warning',
                    message: 'Add Item button clicked but no new row appeared — JS may be broken',
                    url: this.page.url(),
                });
            }

            // Try to fill the new row
            const firstInput = this.page.locator('[class*="item-row"]:last-child input, [class*="po-row"]:last-child input, tr:last-child input').first();
            if (await firstInput.isVisible({ timeout: 1000 }).catch(() => false)) {
                await firstInput.fill('Test item');
                console.log('  ✓ Filled line item field');
            }
        } else {
            console.log('  ℹ No Add Item button found');
        }

        // ── Step 4: Check for unsaved changes warning ──
        const hasUnsaved = await this.hasUnsavedChangesWarning();
        if (hasUnsaved) {
            observations.push({
                type: 'ux',
                severity: 'info',
                message: 'Page warns about unsaved changes — indicates form state tracking works',
                url: this.page.url(),
            });
            console.log('  ✓ Unsaved changes protection active');
        }

        // ── Step 5: Try submitting ──
        const submitBtn = this.page.locator('button[type="submit"], button:has-text("Save"), button:has-text("Create"), button:has-text("Submit")').first();
        if (await submitBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
            await submitBtn.click();
            console.log('  ✓ Clicked submit');

            // Check for toast/success message
            const toast = await this.waitForToast(3000);
            if (toast) {
                observations.push({
                    type: 'toast',
                    severity: toast.includes('error') || toast.includes('Error') ? 'error' : 'info',
                    message: toast,
                    url: this.page.url(),
                });
                console.log(`  📢 Toast: "${toast}"`);

                // Detect "you might lose data" pattern
                if (/lose|unsaved|reload|discard/.test(toast)) {
                    observations.push({
                        type: 'ux-warning',
                        severity: 'warning',
                        message: `Confusing UX: "${toast}" — user may not know how to save properly`,
                        url: this.page.url(),
                    });
                }
            }

            // Check if we redirected
            await this.page.waitForTimeout(1500);
            const currentUrl = this.page.url();
            const redirected = !currentUrl.includes('/create');
            if (redirected) {
                observations.push({
                    type: 'navigation',
                    severity: 'info',
                    message: `Form submitted successfully → redirected to ${currentUrl}`,
                    url: currentUrl,
                });
                console.log(`  ✓ Redirected to: ${currentUrl}`);
            } else {
                observations.push({
                    type: 'navigation',
                    severity: currentUrl.includes('/create') ? 'warning' : 'info',
                    message: 'Form submitted but still on same page — may need JS reload',
                    url: currentUrl,
                });
            }
        }

        this.observations.push(...observations);
        return observations;
    }

    /**
     * Generate a behavioral flow report.
     */
    generateReport() {
        const warnings = this.observations.filter(o => o.severity === 'warning');
        const errors = this.observations.filter(o => o.severity === 'error');
        const info = this.observations.filter(o => o.severity === 'info');

        let report = `\n═══════════════════════════════════════════════════\n`;
        report += `  BEHAVIORAL FLOW REPORT\n`;
        report += `  ${this.observations.length} observations (${warnings.length} warnings, ${errors.length} errors, ${info.length} info)\n`;
        report += `═══════════════════════════════════════════════════\n`;

        for (const obs of this.observations) {
            const icon = obs.severity === 'error' ? '🔴' : obs.severity === 'warning' ? '🟠' : 'ℹ️';
            report += `  ${icon} [${obs.type}] ${obs.message}\n`;
        }

        return report;
    }
}

module.exports = { BehaviorFlow };
