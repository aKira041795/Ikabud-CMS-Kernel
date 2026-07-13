/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, component harnesses, and annotation-based
 * metadata collection (gaps, fingerprints) for WorkbenchReporter.
 *
 * Tests do NOT need afterEach/afterAll hooks — the reporter collects
 * pass/fail automatically via onTestEnd reading testInfo.annotations.
 *
 * USAGE (module adapter):
 *   var { createWorkbenchTest } = require('./WorkbenchFixture');
 *   module.exports = createWorkbenchTest({ appUrl, loginPath, landingPath, ... });
 *
 * USAGE (spec file):
 *   var { test, expect } = require('../MyAdapter');
 *   test('works', async function({ page, integrity }) {
 *       integrity.gap('Known limitation');
 *       integrity.fingerprint('modules/foo/bar.php');
 *   });
 */

// @ts-check
var base = require('@playwright/test');
var { ShellHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/ShellHarness');
var { DialogHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/DialogHarness');
var { TableHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/TableHarness');
var crypto = require('crypto');
var fs = require('fs');
var path = require('path');
var execSync = require('child_process').execSync;

function createWorkbenchTest(config) {
    var appUrl = config.appUrl;
    var loginPath = config.loginPath;
    var landingPath = config.landingPath;
    var adminUser = config.adminUser || 'admin';
    var adminPass = config.adminPass || 'password';

    var fixture = base.test.extend({
        appUrl: [appUrl, { option: true }],

        page: async function ({ page }, use) {
            await page.goto('' + appUrl + loginPath);
            await page.fill('input[name="username"]', adminUser);
            await page.fill('input[name="password"]', adminPass);
            await page.click('button[type="submit"]');
            await page.waitForURL('**' + landingPath);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await use(page);
        },

        shell: async function ({ page }, use) { await use(new ShellHarness(page)); },
        dialog: async function ({ page }, use) { await use(new DialogHarness(page)); },
        table: async function ({ page }, use) { await use(new TableHarness(page)); },

        loginAs: [async function ({ page }, use) {
            await use(async function (username, password) {
                await page.goto('' + appUrl + loginPath);
                await page.fill('input[name="username"]', username);
                await page.fill('input[name="password"]', password);
                await page.click('button[type="submit"]');
                await page.waitForURL('**' + landingPath);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            });
        }, { auto: false }],

        // Test-scoped integrity: pushes gaps and fingerprints as Playwright annotations.
        // WorkbenchReporter.onTestEnd reads testInfo.annotations for every test.
        // Works in any test or test.beforeEach hook.
        integrity: async function ({ }, use, testInfo) {
            await use({
                gap: function (description) {
                    testInfo.annotations.push({ type: 'wb-gap', description: description });
                },
                fingerprint: function (relativePath) {
                    var fullPath = path.resolve(__dirname, '../../', relativePath);
                    var hash = 'FILE_NOT_FOUND';
                    try {
                        var content = fs.readFileSync(fullPath, 'utf-8');
                        hash = crypto.createHash('sha256').update(content).digest('hex').substring(0, 16);
                    } catch (e) { /* keep FILE_NOT_FOUND */ }
                    testInfo.annotations.push({
                        type: 'wb-fingerprint',
                        description: JSON.stringify({ file: relativePath, hash: hash }),
                    });
                },
            });
        },

        // Worker-scoped PAL lifecycle seed: creates test data, fatal on failure.
        // Tenant defaults to PAL_TEST_TENANT or 990000+workerIndex for concurrency safety.
        palLifecycleSeed: [async function ({ }, use, workerInfo) {
            var seedPath = path.resolve(__dirname, '../pal/pal_seed_lifecycle.php');
            var tenant = process.env.PAL_TEST_TENANT || String(990000 + (workerInfo.parallelIndex || 0));

            console.log('  🌱 Seeding PAL lifecycle (tenant=' + tenant + ')...');
            var output = execSync(
                'php ' + seedPath + ' --tenant=' + tenant,
                { encoding: 'utf-8', timeout: 15000 }
            );
            var data = JSON.parse(output);

            if (!data.ok) {
                throw new Error('PAL lifecycle seed failed: ' + (data.error || 'unknown'));
            }

            console.log('  🌱 Seeded: project #' + data.project.id + ' (' + data.project.title + ')');

            try {
                await use(data);
            } finally {
                try {
                    execSync(
                        'php ' + seedPath + ' --cleanup --tenant=' + tenant,
                        { encoding: 'utf-8', timeout: 10000 }
                    );
                    console.log('  🧹 Cleaned up tenant ' + tenant);
                } catch (e) {
                    console.error('  ❌ PAL lifecycle cleanup FAILED: ' + e.message);
                    throw e; // Fatal — leftover state corrupts future runs
                }
            }
        }, { scope: 'worker' }],

        // Worker-scoped PAL interactive seed: creates minimal draft project for
        // browser-driven workflow tests. Same isolation as palLifecycleSeed.
        palInteractiveSeed: [async function ({ }, use, workerInfo) {
            var seedPath = path.resolve(__dirname, '../pal/pal_seed_interactive.php');
            var tenant = process.env.PAL_TEST_TENANT || String(991000 + (workerInfo.parallelIndex || 0));

            console.log('  🌱 Seeding PAL interactive (tenant=' + tenant + ')...');
            var output = execSync(
                'php ' + seedPath + ' --tenant=' + tenant,
                { encoding: 'utf-8', timeout: 15000 }
            );
            var data = JSON.parse(output);

            if (!data.ok) {
                throw new Error('PAL interactive seed failed: ' + (data.error || 'unknown'));
            }

            console.log('  🌱 Seeded: project #' + data.project_id + ' (status: ' + data.project_status + ')');

            try {
                await use(data);
            } finally {
                try {
                    execSync(
                        'php ' + seedPath + ' --cleanup --tenant=' + tenant,
                        { encoding: 'utf-8', timeout: 10000 }
                    );
                    console.log('  🧹 Cleaned up interactive tenant ' + tenant);
                } catch (e) {
                    console.error('  ❌ PAL interactive cleanup FAILED: ' + e.message);
                    throw e;
                }
            }
        }, { scope: 'worker' }],
    });

    return { test: fixture, expect: base.expect };
}

// Default PAL adapter
var pal = createWorkbenchTest({
    appUrl: process.env.APP_URL || 'http://palsystem.test',
    loginPath: '/project-audit-ledger/login',
    landingPath: '/admin/project-audit-ledger',
    adminUser: process.env.ADMIN_USER || 'pAladmin',
    adminPass: process.env.ADMIN_PASS || 'pal123456',
});

module.exports = { test: pal.test, expect: pal.expect, createWorkbenchTest: createWorkbenchTest };
