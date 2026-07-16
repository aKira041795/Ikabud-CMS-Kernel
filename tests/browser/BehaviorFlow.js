/**
 * BehaviorFlow — Runtime behavioral testing engine (module-agnostic).
 *
 * Provides generic interaction primitives. Module-specific behaviors
 * extend this class and call the primitives.
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
     * @param {object} [opts]
     * @param {string} [opts.basePath] - Module admin base path (e.g. '/admin/project-audit-ledger')
     * @param {string} [opts.appUrl] - Application base URL
     */
    constructor(page, opts) {
        this.page = page;
        this.basePath = opts?.basePath || '/admin';
        this.appUrl = opts?.appUrl || process.env.APP_URL || 'http://palsystem.test';
        /** @type {Array<{type:string, severity:string, message:string, url:string, step?:string}>} */
        this.observations = [];
        this._toastObserver = null;
        this.telemetry = { started_at: null, finished_at: null, duration_ms: 0, interactions: 0, successful_steps: 0, failures: 0, clicks: 0, field_entries: 0, navigation_depth: 0 };
    }

    /**
     * Start monitoring for toast messages and browser dialogs.
     */
    async startMonitoring() {
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
     * @returns {Promise<{detected: boolean, method: string}>}
     */
    async hasUnsavedChangesWarning() {
        return await this.page.evaluate(() => {
            // Check for beforeunload handler (onbeforeunload property)
            if (window.onbeforeunload) {
                return { detected: true, method: 'onbeforeunload-property' };
            }
            // Check for dirty-flag patterns
            const dirty = document.querySelector('[data-dirty="true"], .is-dirty, .unsaved');
            if (dirty) return { detected: true, method: 'dirty-flag' };
            return { detected: false, method: 'none' };
        }).catch(() => ({ detected: false, method: 'error' }));
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
        this.telemetry.interactions++; this.telemetry.clicks++;
        const btn = this.page.locator('button, a[role="button"], [data-wb-action]').filter({ hasText: text }).first();
        await btn.click();
        this.telemetry.successful_steps++;
        await this.page.waitForTimeout(300);
    }

    /**
     * Click a button identified by data-wb-action attribute.
     * @param {string} actionKey
     */
    async clickAction(actionKey) {
        this.telemetry.interactions++; this.telemetry.clicks++;
        const btn = this.page.locator(`[data-wb-action="${actionKey}"]`).first();
        await btn.click();
        this.telemetry.successful_steps++;
        await this.page.waitForTimeout(300);
    }

    /**
     * Fill a form field by its label text or name.
     * @param {string|RegExp} labelOrName
     * @param {string} value
     */
    async fillField(labelOrName, value) {
        this.telemetry.interactions++; this.telemetry.field_entries++;
        // Try by label text first
        const byLabel = this.page.locator('label').filter({ hasText: labelOrName }).first();
        if (await byLabel.isVisible({ timeout: 500 }).catch(() => false)) {
            const forAttr = await byLabel.getAttribute('for').catch(() => null);
            if (forAttr) {
                const input = this.page.locator(`#${forAttr}`);
                if (await input.isVisible({ timeout: 300 }).catch(() => false)) {
                    await input.fill(value);
                    this.telemetry.successful_steps++;
                    return;
                }
            }
            // Input wrapped in label
            const wrapped = byLabel.locator('input, select, textarea');
            if (await wrapped.count() > 0) {
                await wrapped.first().fill(value);
                this.telemetry.successful_steps++;
                return;
            }
        }
        // Try by field key
        const byField = this.page.locator(`[data-wb-field="${labelOrName}"]`).first();
        if (await byField.isVisible({ timeout: 300 }).catch(() => false)) {
            await byField.fill(value);
            this.telemetry.successful_steps++;
            return;
        }
        // Try by name
        const byName = this.page.locator(`[name="${labelOrName}"]`).first();
        if (await byName.isVisible({ timeout: 300 }).catch(() => false)) {
            await byName.fill(value);
            this.telemetry.successful_steps++;
            return;
        }
        this._observe('interaction', 'warning', `Could not find field: ${labelOrName}`);
        this.telemetry.failures++;
    }

    /**
     * Submit a form and check for redirect.
     * @param {object} [opts]
     * @param {string} [opts.formKey] - data-wb-form selector
     * @param {RegExp} [opts.redirectPattern] - Expected redirect URL pattern after submit
     * @returns {Promise<{redirected: boolean, url: string, toast: string|null}>}
     */
    async submitForm(opts) {
        this.telemetry.interactions++; this.telemetry.clicks++;
        const formKey = opts?.formKey;
        const redirectPattern = opts?.redirectPattern;

        const submitBtn = formKey
            ? this.page.locator(`[data-wb-form="${formKey}"] button[type="submit"], [data-wb-form="${formKey}"] [data-wb-action]`).first()
            : this.page.locator('button[type="submit"], [data-wb-action="save"], [data-wb-action="create"]').first();

        if (!(await submitBtn.isVisible({ timeout: 1000 }).catch(() => false))) {
            this._observe('interaction', 'warning', 'No submit button found');
            this.telemetry.failures++;
            return { redirected: false, url: this.page.url(), toast: null };
        }

        await submitBtn.click();
        this.telemetry.successful_steps++;
        console.log('  ✓ Clicked submit');

        const toast = await this.waitForToast(3000);
        if (toast) {
            this._observe('toast', toast.includes('error') || toast.includes('Error') ? 'error' : 'info', toast);
            console.log(`  📢 Toast: "${toast}"`);
            if (/lose|unsaved|reload|discard/.test(toast)) {
                this._observe('ux-warning', 'warning', `Confusing UX: "${toast}"`);
            }
        }

        await this.page.waitForTimeout(1500);
        const currentUrl = this.page.url();
        const redirected = redirectPattern ? redirectPattern.test(currentUrl) : !currentUrl.includes('/create');

        if (redirected) {
            this._observe('navigation', 'info', `Form submitted → redirected to ${currentUrl}`);
            console.log(`  ✓ Redirected to: ${currentUrl}`);
        } else {
            this._observe('navigation', 'warning', 'Form submitted but still on same page — may need JS reload');
        }
        return { redirected, url: currentUrl, toast };
    }

    /**
     * Add a generic observation.
     * @param {string} type
     * @param {string} severity
     * @param {string} message
     * @param {string} [step]
     */
    _observe(type, severity, message, step) {
        this.observations.push({ type, severity, message, url: this.page.url(), step: step || type });
    }

    /**
     * Generate a behavioral flow report.
     */
    generateReport() {
        const warnings = this.observations.filter(o => o.severity === 'warning');
        const errors = this.observations.filter(o => o.severity === 'error' || o.severity === 'critical');
        const info = this.observations.filter(o => o.severity === 'info');

        let report = `\n═══════════════════════════════════════════════════\n`;
        report += `  BEHAVIORAL FLOW REPORT\n`;
        report += `  ${this.observations.length} observations (${errors.length} errors, ${warnings.length} warnings, ${info.length} info)\n`;
        report += `═══════════════════════════════════════════════════\n`;

        for (const obs of this.observations) {
            const icon = obs.severity === 'error' || obs.severity === 'critical' ? '🔴' : obs.severity === 'warning' ? '🟠' : 'ℹ️';
            report += `  ${icon} [${obs.type}] ${obs.message}\n`;
        }

        return report;
    }

    getTelemetry() {
        return { ...this.telemetry };
    }

    /**
     * Default no-op scenario for modules without registered behavior flows.
     * @returns {Promise<Array>} Empty observations array
     */
    async runDefaultScenario() {
        console.log('  ℹ No behavior flow registered for this module — skipping');
        return [];
    }
}


/**
 * PalBehaviorFlow — PAL (Project Audit Ledger) specific behavior flows.
 * @extends BehaviorFlow
 */
class PalBehaviorFlow extends BehaviorFlow {

    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        super(page, { basePath: '/admin/project-audit-ledger' });
        /** @type {Array<{entityType: string, entityId: number, title: string}>} */
        this.createdEntities = [];
    }

    /**
     * Run the complete job order creation flow as a human would.
     * This catches runtime UX issues: missing toasts, unsaved warnings, broken interactions.
     * @returns {Promise<Array>} Flow observations
     */
    async runJobOrderFlow() {
        this.telemetry.started_at = new Date().toISOString();
        const flowStarted = Date.now();
        const base = this.basePath;
        const observations = [];

        // ── Step 1: Navigate to create form ──
        console.log('\n  🔄 Flow: Create Job Order');
        await this.page.goto(`${this.appUrl}${base}/projects/create`);
        await this.page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // ── Step 2: Fill title ──
        const title = `FlowTest-${Date.now()}`;
        await this.fillField('title', title);
        console.log(`  ✓ Filled title: ${title}`);

        // ── Step 3: Try to add a line item ──
        const addItemBtn = this.page.locator('[data-wb-action="add-item"], [data-wb-action$=".add-item"], button:has-text("Add Item"), button:has-text("Add Line")').first();
        if (await addItemBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            const rows = this.page.locator('[class*="item-row"], [class*="po-row"], [class*="line-item"]');
            const beforeRows = await rows.count();
            await addItemBtn.click();
            this.telemetry.interactions++; this.telemetry.clicks++; this.telemetry.successful_steps++;
            await this.page.waitForTimeout(500);
            console.log('  ✓ Clicked Add Item button');

            // Check if new row appeared
            const afterRows = await rows.count();
            const newRow = rows.last();
            const rowAppeared = afterRows > beforeRows;
            if (rowAppeared) {
                console.log('  ✓ New line item row appeared');
            } else {
                this._observe('interaction', 'warning', 'Add Item button clicked but no new row appeared — JS may be broken');
            }

            // Try to fill the new row
            const firstInput = this.page.locator('[class*="item-row"]:last-child input, [class*="po-row"]:last-child input, tr:last-child input').first();
            if (await firstInput.isVisible({ timeout: 1000 }).catch(() => false)) {
                await firstInput.fill('Test item');
                this.telemetry.interactions++; this.telemetry.field_entries++; this.telemetry.successful_steps++;
                console.log('  ✓ Filled line item field');
            }
        } else {
            console.log('  ℹ No Add Item button found');
        }

        // ── Step 4: Check for unsaved changes warning ──
        const unsaved = await this.hasUnsavedChangesWarning();
        if (unsaved.detected) {
            this._observe('ux', 'info', `Page warns about unsaved changes (${unsaved.method}) — form state tracking works`);
            console.log('  ✓ Unsaved changes protection active');
        }

        // ── Step 5: Try submitting ──
        const submitResult = await this.submitForm({ redirectPattern: /\/projects\/\d+/ });
        if (submitResult.redirected) {
            // Track created entity for cleanup
            const idMatch = submitResult.url.match(/\/projects\/(\d+)/);
            if (idMatch) {
                this.createdEntities.push({ entityType: 'pal.project', entityId: parseInt(idMatch[1]), title });
            }
        }

        this.observations.push(...observations);
        this.telemetry.finished_at = new Date().toISOString();
        this.telemetry.duration_ms = Date.now() - flowStarted;
        this.telemetry.navigation_depth = new URL(this.page.url()).pathname.split('/').filter(Boolean).length;
        return this.observations;
    }

    /**
     * Run the PAL-specific default scenario (job order flow).
     * @returns {Promise<Array>}
     */
    async runDefaultScenario() {
        return await this.runJobOrderFlow();
    }

    /**
     * Clean up created entities. Returns entity list for external cleanup.
     * Caller should invoke this at the end of a test or pass the list to
     * a cleanup script via evidence.
     * @returns {Array<{entityType: string, entityId: number, title: string}>}
     */
    getCreatedEntities() {
        return this.createdEntities;
    }
}


/**
 * BehaviorRegistry — Maps module IDs to behavior flow classes.
 *
 * Usage:
 *   const BehaviorClass = BehaviorRegistry.forModule('project-audit-ledger');
 *   const flow = new BehaviorClass(page);
 *   const observations = await flow.runDefaultScenario();
 */
class BehaviorRegistry {
    constructor() {
        throw new Error('BehaviorRegistry is static — do not instantiate');
    }

    /**
     * Get the behavior flow class for a module.
     * @param {string} moduleId
     * @returns {typeof BehaviorFlow}
     */
    static forModule(moduleId) {
        const registry = {
            'project-audit-ledger': PalBehaviorFlow,
            // Future registrations:
            // 'guidance': GuidanceBehaviorFlow,
            // 'bakeshop': BakeshopBehaviorFlow,
        };
        return registry[moduleId] || BehaviorFlow;
    }
}


module.exports = { BehaviorFlow, PalBehaviorFlow, BehaviorRegistry };
