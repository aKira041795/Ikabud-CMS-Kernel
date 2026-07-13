/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, component harnesses, and annotation-based
 * metadata collection (gaps, fingerprints) for WorkbenchReporter.
 *
 * Tests do NOT need afterEach/afterAll hooks — the reporter collects
 * pass/fail automatically.
 *
 * USAGE (module adapter):
 *   // tests/browser/MyAdapter.js
 *   var { createWorkbenchTest } = require('./WorkbenchFixture');
 *   module.exports = createWorkbenchTest({ appUrl, loginPath, landingPath, ... });
 *
 * USAGE (spec file):
 *   var { test, expect } = require('../MyAdapter');
 *   test('works', async function({ page, shell, integrity }) {
 *       integrity.gap('Some known limitation');
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

function createWorkbenchTest(config) {
    var appUrl = config.appUrl;
    var loginPath = config.loginPath;
    var landingPath = config.landingPath;
    var adminUser = config.adminUser || 'admin';
    var adminPass = config.adminPass || 'password';

    var fixture = base.test.extend({
        appUrl: [appUrl, { option: true }],

        page: async function(_a, use) {
            var page = _a.page;
            await page.goto('' + appUrl + loginPath);
            await page.fill('input[name="username"]', adminUser);
            await page.fill('input[name="password"]', adminPass);
            await page.click('button[type="submit"]');
            await page.waitForURL('**' + landingPath);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await use(page);
        },

        shell: async function(_a, use) { await use(new ShellHarness(_a.page)); },
        dialog: async function(_a, use) { await use(new DialogHarness(_a.page)); },
        table: async function(_a, use) { await use(new TableHarness(_a.page)); },

        loginAs: [async function(_a, use) {
            var page = _a.page;
            await use(async function(username, password) {
                await page.goto('' + appUrl + loginPath);
                await page.fill('input[name="username"]', username);
                await page.fill('input[name="password"]', password);
                await page.click('button[type="submit"]');
                await page.waitForURL('**' + landingPath);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            });
        }, { auto: false }],

        // integrity: pushes gaps and fingerprints as test annotations
        // WorkbenchReporter.onTestEnd reads annotations automatically
        integrity: async function(_a, use, testInfo) {
            var gaps = [];
            var fingerprints = {};

            await use({
                gap: function(desc) {
                    gaps.push(desc);
                    testInfo.annotations.push({ type: 'wb-gap', description: desc });
                },
                fingerprint: function(fp) {
                    var fullPath = path.resolve(__dirname, '../../', fp);
                    var hash = 'FILE_NOT_FOUND';
                    try {
                        var content = fs.readFileSync(fullPath, 'utf-8');
                        hash = crypto.createHash('md5').update(content).digest('hex').substring(0, 16);
                    } catch (e) { /* keep FILE_NOT_FOUND */ }
                    fingerprints[fp] = hash;
                    testInfo.annotations.push({
                        type: 'wb-fingerprint',
                        description: JSON.stringify({ file: fp, hash: hash }),
                    });
                },
            });
        },
    });

    return { test: fixture, expect: base.expect };
}

// Default PAL adapter
var pal = createWorkbenchTest({
    appUrl: process.env.APP_URL || 'http://palsystem.test',
    loginPath: '/project-audit-ledger/login',
    landingPath: '/admin/project-audit-ledger',
    adminUser: process.env.ADMIN_USER || 'paladmin',
    adminPass: process.env.ADMIN_PASS || 'pAl123456',
});

module.exports = { test: pal.test, expect: pal.expect, createWorkbenchTest: createWorkbenchTest };
