/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, authentication, and component harnesses.
 * Handles login, tenant setup, and cleanup automatically.
 *
 * INTEGRITY FEATURES:
 *   - integrity.gap(description) — document known missing test coverage
 *   - integrity.fingerprint(path, hash) — record source file hash
 *   - Auto-writes test_results/browser/<suite>.json on suite completion
 *   - Aggregated manifest in test_results/browser/manifest.json
 *
 * Usage:
 *   import { test, expect } from './WorkbenchFixture';
 *
 *   test('dashboard loads', async ({ page, shell, integrity }) => {
 *       await shell.expectVisible();
 *   });
 *
 *   test.afterAll(async ({ integrity }) => {
 *       await integrity.writeResults();
 *   });
 *
 * @see docs/workbench/ark-workbench-ui-testing-guide.md
 */

// @ts-check
const { test: base } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { ShellHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/ShellHarness');
const { DialogHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/DialogHarness');
const { TableHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/TableHarness');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const ADMIN_USER = process.env.ADMIN_USER || 'paladmin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'pAl123456';

const RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');

/**
 * Create the integrity store for a test suite.
 * Each spec file gets its own store instance.
 */
function createIntegrityStore(suiteName) {
    /** @type {Array<{test: string, status: string, detail: string}>} */
    const results = [];
    /** @type {string[]} */
    const gaps = [];
    /** @type {Record<string, string>} */
    const fingerprints = {};
    const startedAt = new Date().toISOString();

    return {
        /** Record a test result */
        record(testName, passed, detail = '') {
            results.push({
                test: testName,
                status: passed ? 'pass' : 'fail',
                detail,
            });
        },

        /** Document a known coverage gap */
        gap(description) {
            gaps.push(description);
        },

        /** Record a source file fingerprint */
        fingerprint(filePath, hash) {
            // If no hash provided, compute it from the file
            const fullPath = path.resolve(__dirname, '../../', filePath);
            try {
                const content = fs.readFileSync(fullPath, 'utf-8');
                const h = crypto.createHash('md5').update(content).digest('hex');
                fingerprints[filePath] = h.substring(0, 16);
            } catch (e) {
                fingerprints[filePath] = `FILE_NOT_FOUND: ${e.message}`;
            }
        },

        /** Write results JSON and update manifest */
        async writeResults() {
            const finishedAt = new Date().toISOString();
            const passed = results.filter(r => r.status === 'pass').length;
            const failed = results.filter(r => r.status === 'fail').length;

            const data = {
                suite: suiteName,
                started: startedAt,
                finished: finishedAt,
                summary: {
                    passed,
                    failed,
                    total: results.length,
                },
                source_fingerprints: { ...fingerprints },
                results: [...results],
                gaps: [...gaps],
            };

            // Ensure results directory exists
            if (!fs.existsSync(RESULTS_DIR)) {
                fs.mkdirSync(RESULTS_DIR, { recursive: true });
            }

            // Write suite-specific results
            const suiteFile = path.join(RESULTS_DIR, `${suiteName}.json`);
            fs.writeFileSync(suiteFile, JSON.stringify(data, null, 2));

            // Update manifest
            const manifestFile = path.join(RESULTS_DIR, 'manifest.json');
            let manifest = { suites: {}, updated: finishedAt };
            if (fs.existsSync(manifestFile)) {
                try {
                    manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf-8'));
                } catch (e) { /* start fresh */ }
            }
            manifest.suites[suiteName] = {
                passed,
                failed,
                total: results.length,
                gaps: gaps.length,
                fingerprints: Object.keys(fingerprints).length,
                finished: finishedAt,
            };
            manifest.updated = finishedAt;
            fs.writeFileSync(manifestFile, JSON.stringify(manifest, null, 2));

            // Print manifest update
            console.log(`\n  📄 Results: test_results/browser/${suiteName}.json`);

            return data;
        },
    };
}

/**
 * Extended test fixture providing pre-authenticated page, harnesses, and integrity store.
 *
 * @type {import('@playwright/test').TestFixture<{
 *   page: import('@playwright/test').Page,
 *   shell: ShellHarness,
 *   dialog: DialogHarness,
 *   table: TableHarness,
 *   appUrl: string,
 *   loginAs: (username: string, password: string) => Promise<void>,
 *   integrity: ReturnType<typeof createIntegrityStore>
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

    // Integrity store — injected per spec file describe block
    integrity: async ({ }, use) => {
        // The suite name is set by each spec file via integrity.suiteName
        const store = createIntegrityStore('unnamed-suite');
        await use(store);
    },
});

// Re-export expect and other utilities
const { expect } = require('@playwright/test');

module.exports = { test: fixture, expect, APP_URL, createIntegrityStore };
