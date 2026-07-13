/**
 * WorkbenchFixture — Reusable Playwright test fixture for ARK Workbench tests.
 *
 * Provides pre-configured page, component harnesses, integrity tracking,
 * and module-agnostic authentication via createWorkbenchTest().
 *
 * USAGE (create a module adapter):
 *   // tests/browser/MyModuleAdapter.js
 *   const { createWorkbenchTest } = require('./WorkbenchFixture');
 *   module.exports = createWorkbenchTest({
 *       appUrl: process.env.APP_URL || 'http://mytenant.test',
 *       loginPath: '/my-module/login',
 *       landingPath: '/admin/my-module',
 *       adminUser: process.env.ADMIN_USER || 'admin',
 *       adminPass: process.env.ADMIN_PASS || 'password',
 *   });
 *
 * USAGE (spec file):
 *   const { test, expect } = require('../MyModuleAdapter');
 *   test('dashboard loads', async ({ page, shell }) => {
 *       await shell.expectVisible();
 *   });
 *
 * INTEGRITY:
 *   - integrity.gap(description) — document known missing coverage
 *   - integrity.fingerprint(path) — record source file hash
 *   - Auto-records Playwright pass/fail results via afterEach
 *   - Writes test_results/browser/<spec-file>--<project>.json
 *   - Compares fingerprints against baseline if fingerprint-baseline.json exists
 *   - Updates test_results/browser/manifest.json
 */

// @ts-check
const { test: base, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { ShellHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/ShellHarness');
const { DialogHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/DialogHarness');
const { TableHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/TableHarness');

const RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');

function createIntegrityStore(suiteName) {
    const results = [];
    const gaps = new Set();
    const fingerprints = {};
    const startedAt = new Date().toISOString();

    return {
        record(testName, passed, detail) {
            results.push({ test: testName, status: passed ? 'pass' : 'fail', detail: detail || '' });
        },
        gap(description) { gaps.add(description); },
        fingerprint(filePath) {
            const fullPath = path.resolve(__dirname, '../../', filePath);
            try {
                const content = fs.readFileSync(fullPath, 'utf-8');
                fingerprints[filePath] = crypto.createHash('md5').update(content).digest('hex').substring(0, 16);
            } catch (e) { fingerprints[filePath] = 'FILE_NOT_FOUND'; }
        },
        async writeResults() {
            const finishedAt = new Date().toISOString();
            const passed = results.filter(function(r) { return r.status === 'pass'; }).length;
            const failed = results.filter(function(r) { return r.status === 'fail'; }).length;
            const gapList = Array.from(gaps).sort();
            const data = {
                suite: suiteName, started: startedAt, finished: finishedAt,
                summary: { passed: passed, failed: failed, total: results.length },
                source_fingerprints: Object.assign({}, fingerprints),
                results: results.slice(), gaps: gapList,
            };
            if (!fs.existsSync(RESULTS_DIR)) fs.mkdirSync(RESULTS_DIR, { recursive: true });
            fs.writeFileSync(path.join(RESULTS_DIR, suiteName + '.json'), JSON.stringify(data, null, 2));

            // Aggregate manifest
            var manifestFile = path.join(RESULTS_DIR, 'manifest.json');
            var manifest = { suites: {}, updated: finishedAt };
            if (fs.existsSync(manifestFile)) {
                try { manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf-8')); } catch (e) { /* reset */ }
            }
            manifest.suites[suiteName] = {
                passed: passed, failed: failed, total: results.length,
                gaps: gapList.length, fingerprints: Object.keys(fingerprints).length, finished: finishedAt,
            };
            manifest.updated = finishedAt;
            fs.writeFileSync(manifestFile, JSON.stringify(manifest, null, 2));

            // Fingerprint baseline comparison
            var baselineFile = path.join(RESULTS_DIR, 'fingerprint-baseline.json');
            if (fs.existsSync(baselineFile)) {
                var baseline = JSON.parse(fs.readFileSync(baselineFile, 'utf-8'));
                for (var fp in fingerprints) {
                    if (fingerprints.hasOwnProperty(fp)) {
                        var expected = baseline[fp];
                        if (expected && expected !== fingerprints[fp]) {
                            console.warn('  ⚠ FINGERPRINT CHANGED: ' + fp);
                            console.warn('    was: ' + expected + '  now: ' + fingerprints[fp]);
                        }
                    }
                }
            }
            console.log('  📄 Results: test_results/browser/' + suiteName + '.json');
            return data;
        },
    };
}

function createWorkbenchTest(config) {
    var appUrl = config.appUrl;
    var loginPath = config.loginPath;
    var landingPath = config.landingPath;
    var adminUser = config.adminUser || 'admin';
    var adminPass = config.adminPass || 'password';
    var stores = {};

    var fixture = base.extend({
        appUrl: [appUrl, { option: true }],

        loginAs: [async function loginAsFn(_a, use) {
            var page = _a.page;
            await use(async function login(username, password) {
                await page.goto('' + appUrl + loginPath);
                await page.fill('input[name="username"]', username);
                await page.fill('input[name="password"]', password);
                await page.click('button[type="submit"]');
                await page.waitForURL('**' + landingPath);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            });
        }, { auto: false }],

        page: async function pageFn(_a, use) {
            var page = _a.page;
            await page.goto('' + appUrl + loginPath);
            await page.fill('input[name="username"]', adminUser);
            await page.fill('input[name="password"]', adminPass);
            await page.click('button[type="submit"]');
            await page.waitForURL('**' + landingPath);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await use(page);
        },

        shell: async function shellFn(_a, use) { await use(new ShellHarness(_a.page)); },
        dialog: async function dialogFn(_a, use) { await use(new DialogHarness(_a.page)); },
        table: async function tableFn(_a, use) { await use(new TableHarness(_a.page)); },

        integrity: [async function integrityFn(_a, use, workerInfo) {
            var file = workerInfo.file || 'unknown';
            var project = workerInfo.project.name || 'chromium';
            var suiteName = file
                .replace(/^.*tests\/browser\//, '')
                .replace(/\.spec\.js$/, '')
                .replace(/\//g, '-') + '--' + project;
            if (!stores[suiteName]) stores[suiteName] = createIntegrityStore(suiteName);
            await use(stores[suiteName]);
        }, { scope: 'worker' }],
    });

    return { test: fixture, expect: expect, createIntegrityStore: createIntegrityStore };
}

// Default PAL adapter (backward compatible)
var palAdapter = createWorkbenchTest({
    appUrl: process.env.APP_URL || 'http://palsystem.test',
    loginPath: '/project-audit-ledger/login',
    landingPath: '/admin/project-audit-ledger',
    adminUser: process.env.ADMIN_USER || 'paladmin',
    adminPass: process.env.ADMIN_PASS || 'pAl123456',
});

module.exports = {
    test: palAdapter.test,
    expect: palAdapter.expect,
    createWorkbenchTest: createWorkbenchTest,
    createIntegrityStore: createIntegrityStore,
};
