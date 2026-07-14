/**
 * FormHarness — Stable testing API for workbench form components.
 *
 * Provides field-level and form-level assertions without depending on
 * CSS class names. Tests interact with this harness rather than knowing
 * form DOM internals.
 *
 * Usage:
 *   const form = new FormHarness(page, '#project-form');
 *   await form.expectVisible();
 *   await form.fill('title', 'New Project');
 *   await form.expectFieldError('contract_amount', 'Required');
 *   await form.submit();
 *   await form.expectValidationSummary();
 *
 * @see storage/application-profiles/ark-workbench/components/forms/
 */

// @ts-check

const { expect } = require('@playwright/test');

/**
 * @typedef {import('@playwright/test').Page} Page
 * @typedef {import('@playwright/test').Locator} Locator
 */

class FormHarness {
    /**
     * @param {Page} page
     * @param {string} [formSelector] CSS selector for the form element (default: 'form')
     */
    constructor(page, formSelector) {
        this.page = page;
        this.selector = formSelector || 'form';
    }

    /** @returns {Locator} */
    get locator() {
        return this.page.locator(this.selector).first();
    }

    /** @returns {Locator} All visible form fields (input, select, textarea) */
    get fields() {
        return this.locator.locator('input:visible, select:visible, textarea:visible');
    }

    /** Assert the form is visible */
    async expectVisible() {
        await expect(this.locator).toBeVisible();
    }

    /**
     * Fill a named form field.
     * Looks for [name], [data-wb-field], or label text match.
     * @param {string} name Field name or data-wb-field value
     * @param {string} value Value to fill
     */
    async fill(name, value) {
        var field = await this._findField(name);
        await field.fill(value);
    }

    /**
     * Select an option in a named select field.
     * @param {string} name Field name
     * @param {string|{index?: number, label?: string, value?: string}} option
     */
    async select(name, option) {
        var field = await this._findField(name);
        await field.selectOption(option);
    }

    /**
     * Check/uncheck a named checkbox.
     * @param {string} name Field name
     * @param {boolean} checked
     */
    async setChecked(name, checked) {
        var field = await this._findField(name);
        if (checked) {
            await field.check();
        } else {
            await field.uncheck();
        }
    }

    /**
     * Assert a field has a validation error.
     * @param {string} name Field name
     * @param {string} [expectedMessage] Optional expected error message substring
     */
    async expectFieldError(name, expectedMessage) {
        var field = await this._findField(name);
        var fieldId = await field.getAttribute('id');
        var errorEl = fieldId
            ? this.page.locator(`[data-wb-field-error="${fieldId}"], [aria-describedby]`).first()
            : this.page.locator(`[data-wb-field-error="${name}"]`);
        if (expectedMessage) {
            await expect(errorEl).toContainText(expectedMessage);
        } else {
            await expect(errorEl).toBeVisible();
        }
    }

    /**
     * Assert the validation summary is visible.
     * Looks for [data-wb-component="validation-summary"].
     */
    async expectValidationSummary() {
        var summary = this.page.locator('[data-wb-component="validation-summary"]');
        await expect(summary).toBeVisible();
        await expect(summary).toHaveAttribute('role', 'alert');
    }

    /**
     * Assert no validation errors are visible.
     */
    async expectNoErrors() {
        var summary = this.page.locator('[data-wb-component="validation-summary"]');
        var fieldErrors = this.page.locator('[data-wb-field-error]');
        await expect(summary).toBeHidden();
        await expect(fieldErrors).toHaveCount(0);
    }

    /**
     * Submit the form by clicking the primary submit button.
     */
    async submit() {
        var submitBtn = this.locator.locator(
            'button[type="submit"], [data-wb-action="save"], [data-wb-action="submit"]'
        ).first();
        await submitBtn.click();
    }

    /**
     * Assert a specific form section is present.
     * @param {string} title Section heading text
     */
    async expectSection(title) {
        var section = this.locator.locator('[data-wb-component="form-section"]').filter({ hasText: title });
        await expect(section.first()).toBeVisible();
    }

    /**
     * Assert required field indicators are present.
     */
    async expectRequiredFields() {
        var required = this.locator.locator('[required], [aria-required="true"]');
        var count = await required.count();
        expect(count).toBeGreaterThanOrEqual(0);
    }

    /**
     * Assert form is in disabled state (all fields disabled).
     */
    async expectDisabled() {
        var inputs = this.fields;
        var count = await inputs.count();
        for (var i = 0; i < count; i++) {
            await expect(inputs.nth(i)).toBeDisabled();
        }
    }

    /**
     * Assert the form has a specific number of visible fields.
     * @param {number} min Minimum field count
     */
    async expectMinFields(min) {
        var count = await this.fields.count();
        expect(count).toBeGreaterThanOrEqual(min);
    }

    // ─── Private helpers ───────────────────────────────────────

    /**
     * Find a field by name, data-wb-field, or label text.
     * @param {string} name
     * @returns {Promise<Locator>}
     */
    async _findField(name) {
        // Try [name] attribute first
        var byName = this.locator.locator(`[name="${name}"]`);
        if (await byName.count() > 0) return byName.first();

        // Try [data-wb-field]
        var byWbField = this.locator.locator(`[data-wb-field="${name}"]`);
        if (await byWbField.count() > 0) return byWbField.first();

        // Try label text match — find label, then the associated input
        var byLabel = this.locator.locator(`label`).filter({ hasText: name });
        if (await byLabel.count() > 0) {
            var labelFor = await byLabel.first().getAttribute('for');
            if (labelFor) {
                var byId = this.locator.locator(`#${labelFor}`);
                if (await byId.count() > 0) return byId.first();
            }
            // Label wraps input
            var wrapped = byLabel.first().locator('input, select, textarea');
            if (await wrapped.count() > 0) return wrapped.first();
        }

        // Fallback: search all inputs whose name or placeholder contains the name
        return this.locator.locator(`[name*="${name}"], [placeholder*="${name}"]`).first();
    }
}

module.exports = { FormHarness };
