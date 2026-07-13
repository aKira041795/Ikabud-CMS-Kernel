/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, component harnesses, and integrity tracking.
 * Pass/fail recording is handled by WorkbenchReporter (not this fixture).
 *
 * USAGE (module adapter):
 *   const { createWorkbenchTest } = require('./WorkbenchFixture');
 *   module.exports = createWorkbenchTest({ appUrl, loginPath, landingPath, ... });
 *
 * USAGE (spec file):
 *   const { test, expect } = require('../MyAdapter');
 *   test('works', async ({ page, shell }) => { ... });
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

        // Integrity: gaps and fingerprints only (results by reporter)
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

        integrity: [async function(_a, use, workerInfo) {
            var file = workerInfo.file || 'unknown';
            var project = workerInfo.project.name || 'chromium';
            var suiteName = file
                .replace(/^.*tests\/browser\//, '')
                .replace(/\.spec\.js$/, '')
                .replace(/[/\\]/g, '-') + '--' + project;
            var RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');
            var gaps = [];
            var fingerprints = {};

            await use({
                gap: function(desc) { gaps.push(desc); },
                fingerprint: function(fp) {
                    var fullPath = path.resolve(__dirname, '../../', fp);
                    try {
                        var content = fs.readFileSync(fullPath, 'utf-8');
                        fingerprints[fp] = crypto.createHash('md5').update(content).digest('hex').substring(0, 16);
                    } catch (e) { fingerprints[fp] = 'FILE_NOT_FOUND'; }
                },
                writeResults: async function() {
                    // Reporter handles pass/fail. We write gaps + fingerprints.
                    if (!fs.existsSync(RESULTS_DIR)) fs.mkdirSync(RESULTS_DIR, { recursive: true });
                    var existing = {};
                    try {
                        var suiteFile = path.join(RESULTS_DIR, suiteName + '.json');
                        if (fs.existsSync(suiteFile)) existing = JSON.parse(fs.readFileSync(suiteFile, 'utf-8'));
                    } catch (e) { /* skip */ }
                    existing.gaps = gaps;
                    existing.source_fingerprints = Object.assign(existing.source_fingerprints || {}, fingerprints);
                    fs.writeFileSync(path.join(RESULTS_DIR, suiteName + '.json'), JSON.stringify(existing, null, 2));
                    console.log('  📄 Gaps+fingerprints: test_results/browser/' + suiteName + '.json');
                },
            });
        }, { scope: 'worker' }],
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
