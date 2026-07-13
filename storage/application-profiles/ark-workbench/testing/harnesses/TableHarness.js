/**
 * TableHarness — Stable testing API for workbench:responsive_table.
 *
 * Provides entity-oriented access to table rows without CSS class
 * knowledge. Supports row counts, cell values, clickable rows, and
 * empty-state assertions.
 *
 * Usage:
 *   const table = TableHarness.from(page);
 *   await table.expectRowCount(5);
 *   const row = await table.rowByEntityId('JO-123');
 *   await row.click();
 *
 * @see storage/application-profiles/ark-workbench/components/data/responsive_table.disyl
 */

// @ts-check

const { expect } = require('@playwright/test');

/**
 * @typedef {import('@playwright/test').Page} Page
 * @typedef {import('@playwright/test').Locator} Locator
 */

class TableHarness {
    /** @param {Page} page */
    constructor(page) {
        this.page = page;
    }

    /** Locate the responsive table component */
    get locator() {
        return this.page.locator('[data-wb-component="responsive-table"]');
    }

    /** Get the inner <table> element */
    get table() {
        return this.locator.locator('table');
    }

    /** Get all data rows (not header) */
    get rows() {
        return this.table.locator('tbody tr');
    }

    /** Get column headers */
    get headers() {
        return this.table.locator('thead th[scope="col"]');
    }

    /** Assert minimum column count */
    async expectMinColumns(min) {
        const count = await this.headers.count();
        expect(count).toBeGreaterThanOrEqual(min);
    }

    /** Assert exact row count */
    async expectRowCount(expected) {
        const count = await this.rows.count();
        expect(count).toBe(expected);
    }

    /** Assert at least N rows */
    async expectMinRows(min) {
        const count = await this.rows.count();
        expect(count).toBeGreaterThanOrEqual(min);
    }

    /** Assert the table is in empty state */
    async expectEmpty() {
        const rows = await this.rows.count();
        expect(rows).toBe(0);
        // Empty message should be visible
        const body = await this.table.locator('tbody').textContent();
        expect(body?.trim().length).toBeGreaterThan(0);
    }

    /** Find a row by its data-wb-entity-id attribute */
    rowByEntityId(entityId) {
        return this.rows.locator(`[data-wb-entity-id="${entityId}"]`).first();
    }

    /** Assert a row exists with the given entity ID */
    async expectEntityPresent(entityId) {
        const row = this.rowByEntityId(entityId);
        await expect(row).toBeVisible();
    }

    /** Click a row by entity ID (navigates via data-wb-href) */
    async openRow(entityId) {
        const row = this.rowByEntityId(entityId);
        await row.click();
    }

    /** Assert the table has data-wb-entity and data-wb-state */
    async expectEntityMetadata(expectedEntity, expectedState) {
        if (expectedEntity) {
            await expect(this.table).toHaveAttribute('data-wb-entity', expectedEntity);
        }
        if (expectedState) {
            await expect(this.table).toHaveAttribute('data-wb-state', expectedState);
        }
    }

    /** Get cell content for a specific entity by column key */
    async cellValue(entityId, columnKey) {
        const row = this.rowByEntityId(entityId);
        const cell = row.locator(`td[data-label]`).nth(0); // rough — needs column mapping
        return await cell.textContent();
    }

    /** Assert a specific cell value */
    async expectCellValue(entityId, columnLabel, expected) {
        const row = this.rowByEntityId(entityId);
        const cell = row.locator(`td[data-label="${columnLabel}"]`);
        await expect(cell).toContainText(expected);
    }
}

module.exports = { TableHarness };
