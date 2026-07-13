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

function createWorkbenchTest(config) {
    var appUrl = config.appUrl;
    var loginPath = config.loginPath;
    var landingPath = config.landingPath;
    var adminUser = config.adminUser || 'admin';
    var adminPass = config.adminPass || 'password';

    var fixture = base.test.extend({
        appUrl: [appUrl, { option: true }],

        page: async function ({ page }, use, testInfo) {
            // Auto-capture browser console errors
            var consoleErrors = [];
            page.on('console', function (msg) {
                if (msg.type() === 'error') consoleErrors.push(msg.text());
            });
            page.on('pageerror', function (err) {
                consoleErrors.push('[uncaught] ' + err.message);
            });

            await page.goto('' + appUrl + loginPath);
            await page.fill('input[name="username"]', adminUser);
            await page.fill('input[name="password"]', adminPass);
            await page.click('button[type="submit"]');
            await page.waitForURL('**' + landingPath);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await use(page);

            // Flush captured console errors as issues
            for (var i = 0; i < consoleErrors.length; i++) {
                testInfo.annotations.push({
                    type: 'wb-issue',
                    description: JSON.stringify({
                        kind: 'console-error',
                        severity: 'major',
                        detail: consoleErrors[i],
                    }),
                });
            }
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
                // Structured issue reporting
                issue: function (opts) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({
                            kind: opts.kind || 'bug',
                            severity: opts.severity || 'major',
                            where: opts.where || '',
                            detail: opts.detail || '',
                            recommendation: opts.recommendation || '',
                        }),
                    });
                },
                friction: function (detail) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({ kind: 'friction', severity: 'minor', detail: detail }),
                    });
                },
                perf: function (label, ms) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({ kind: 'perf', severity: ms > 3000 ? 'major' : 'minor', where: label, detail: ms + 'ms' }),
                    });
                },
                a11y: function (detail) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({ kind: 'a11y', severity: 'major', detail: detail }),
                    });
                },
            });
        },

        // ── DEPRECATED: seed fixtures removed in favor of instruction-based E2E.
        // Use pal-lifecycle-interactive.spec.js which creates data through the real browser UI.
        // To quickly populate dev DB: php tests/pal/pal_seed_lifecycle.php --tenant=502

    });

    return { test: fixture, expect: base.expect };
}

// Default PAL adapter — credentials MUST come from environment.
// Never hardcode real passwords. Set in CI or .env.local:
//   ADMIN_USER=xxx ADMIN_PASS=xxx
var pal = createWorkbenchTest({
    appUrl: process.env.APP_URL || 'http://palsystem.test',
    loginPath: '/project-audit-ledger/login',
    landingPath: '/admin/project-audit-ledger',
    adminUser: process.env.ADMIN_USER,
    adminPass: process.env.ADMIN_PASS,
});

module.exports = { test: pal.test, expect: pal.expect, createWorkbenchTest: createWorkbenchTest };
