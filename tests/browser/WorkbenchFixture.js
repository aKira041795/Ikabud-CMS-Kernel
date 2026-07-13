/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, authentication, and component harnesses.
 * Handles login, tenant setup, and cleanup automatically.
 *
 * Usage:
 *   import { test, expect } from './WorkbenchFixture';
 *
 *   test('dashboard loads', async ({ page, shell, table }) => {
 *       await shell.expectVisible();
 *       await shell.expectPageTitle('Dashboard');
 *   });
 *
 * @see docs/workbench/ark-workbench-ui-testing-guide.md
 */

// @ts-check
const { test: base } = require('@playwright/test');
const { ShellHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/ShellHarness');
const { DialogHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/DialogHarness');
const { TableHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/TableHarness');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const ADMIN_USER = process.env.ADMIN_USER || 'paladmin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'pAl123456';

/**
 * Extended test fixture providing pre-authenticated page and harnesses.
 *
 * @type {import('@playwright/test').TestFixture<{
 *   page: import('@playwright/test').Page,
 *   shell: ShellHarness,
 *   dialog: DialogHarness,
 *   table: TableHarness,
 *   appUrl: string,
 *   loginAs: (username: string, password: string) => Promise<void>
 * }>}
 */
const fixture = base.extend({
    // Provide APP_URL for use in tests
    appUrl: [APP_URL, { option: true }],

    // Provide a loginAs helper
    loginAs: async ({ page }, use) => {
        await use(async (username, password) => {
            await page.goto(`${APP_URL}/project-audit-ledger/login`);
            await page.fill('input[name="username"]', username);
            await page.fill('input[name="password"]', password);
            await page.click('button[type="submit"]');
            await page.waitForURL('**/admin/project-audit-ledger');
            await page.waitForSelector(
                '[data-wb-component="app-shell"]',
                { timeout: 10000 }
            );
        });
    },

    // Auto-authenticate before each test by default
    page: async ({ page }, use) => {
        // Authenticate
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', ADMIN_USER);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector(
            '[data-wb-component="app-shell"]',
            { timeout: 10000 }
        );
        await use(page);
    },

    // Provide component harnesses
    shell: async ({ page }, use) => {
        await use(new ShellHarness(page));
    },
    dialog: async ({ page }, use) => {
        await use(new DialogHarness(page));
    },
    table: async ({ page }, use) => {
        await use(new TableHarness(page));
    },
});

// Re-export expect and other utilities
const { expect } = require('@playwright/test');

module.exports = { test: fixture, expect, APP_URL };
