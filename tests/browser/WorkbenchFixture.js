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
var { FormHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/FormHarness');
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
            // ── Diagnostic capture ───────────────────────────
            var captured = [];
            var startTime = Date.now();

            // Browser console errors
            page.on('console', function (msg) {
                if (msg.type() === 'error') {
                    captured.push({
                        kind: 'console-error',
                        severity: 'major',
                        detail: msg.text(),
                        url: page.url(),
                        browser: testInfo.project?.name || 'chromium',
                        timestamp: new Date().toISOString(),
                    });
                }
            });

            // Uncaught JS exceptions
            page.on('pageerror', function (err) {
                captured.push({
                    kind: 'pageerror',
                    severity: 'major',
                    detail: err.message,
                    stack: (err.stack || '').substring(0, 500),
                    url: page.url(),
                    browser: testInfo.project?.name || 'chromium',
                    timestamp: new Date().toISOString(),
                });
            });

            // Failed network requests (4xx, 5xx, connection failures)
            page.on('requestfailed', function (request) {
                var failure = request.failure();
                if (failure) {
                    captured.push({
                        kind: 'network-error',
                        severity: 'major',
                        where: request.method() + ' ' + request.url(),
                        detail: failure.errorText || 'Request failed',
                        timestamp: new Date().toISOString(),
                    });
                }
            });

            // HTTP error responses (500, 404 on app routes, etc.)
            page.on('response', function (response) {
                var status = response.status();
                var url = response.url();
                // Only flag 5xx and app-level 404s (not CDN/external)
                if (status >= 500 || (status === 404 && url.indexOf(appUrl) === 0)) {
                    captured.push({
                        kind: 'http-error',
                        severity: 'critical',
                        where: response.request().method() + ' ' + url,
                        detail: 'HTTP ' + status + ' ' + response.statusText(),
                        timestamp: new Date().toISOString(),
                    });
                }
            });

            await page.goto('' + appUrl + loginPath);
            await page.fill('input[name="username"]', adminUser);
            await page.fill('input[name="password"]', adminPass);
            await page.click('button[type="submit"]');
            await page.waitForURL('**' + landingPath);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await use(page);

            // Flush captured diagnostics as structured issues
            for (var i = 0; i < captured.length; i++) {
                testInfo.annotations.push({
                    type: 'wb-issue',
                    description: JSON.stringify(captured[i]),
                });
            }
        },

        shell: async function ({ page }, use) { await use(new ShellHarness(page)); },
        dialog: async function ({ page }, use) { await use(new DialogHarness(page)); },
        table: async function ({ page }, use) { await use(new TableHarness(page)); },
        form: async function ({ page }, use) { await use(new FormHarness(page)); },

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
            var integrityObj = {
                // Properties (set by tests dynamically)
                evidenceFile: null,
                actionId: null,
                moduleId: null,
                runId: process.env.WB_RUN_ID || '',

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

                // Mark an expected HTTP status to suppress noise in the issue report
                expectedHttp: function (opts) {
                    testInfo.annotations.push({
                        type: 'wb-expected-http',
                        description: JSON.stringify({
                            route: opts.route,
                            status: opts.status,
                            reason: opts.reason || '',
                        }),
                    });
                },
                friction: function (detail) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({ kind: 'friction', severity: 'minor', detail: detail }),
                    });
                },
                perf: function (label, ms, threshold) {
                    var t = typeof threshold === 'number' ? threshold : 3000;
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({
                            kind: 'perf',
                            severity: ms > t ? 'major' : 'minor',
                            where: label,
                            detail: ms + 'ms > ' + t + 'ms threshold',
                            threshold: t,
                        }),
                    });
                },
                a11y: function (detail) {
                    testInfo.annotations.push({
                        type: 'wb-issue',
                        description: JSON.stringify({ kind: 'a11y', severity: 'major', detail: detail }),
                    });
                },

                // ── Self-healing locator: tries fallback chain until element found ──
                // Order: data-wb-* attributes → getByRole → getByText → CSS selector
                // Returns null if nothing matches.
                locate: async function (page, opts) {
                    var strategies = [];

                    // 1. data-wb-action
                    if (opts.action) {
                        strategies.push({ type: 'data-wb-action', sel: '[data-wb-action="' + opts.action + '"]' });
                    }
                    // 2. data-wb-entity-type + optional entity-id
                    if (opts.entityType) {
                        var sel = '[data-wb-entity-type="' + opts.entityType + '"]';
                        if (opts.entityId) sel += '[data-wb-entity-id="' + opts.entityId + '"]';
                        strategies.push({ type: 'data-wb-entity', sel: sel });
                    }
                    // 3. data-wb-component
                    if (opts.component) {
                        strategies.push({ type: 'data-wb-component', sel: '[data-wb-component="' + opts.component + '"]' });
                    }
                    // 4. getByRole + name
                    if (opts.role) {
                        var roleOpts = {};
                        if (opts.name) roleOpts.name = opts.name;
                        strategies.push({ type: 'role', role: opts.role, name: opts.name || '' });
                    }
                    // 5. getByText (exact or substring)
                    if (opts.text) {
                        strategies.push({ type: 'text', text: opts.text });
                    }
                    // 6. getByTestId
                    if (opts.testId) {
                        strategies.push({ type: 'testid', sel: '[data-testid="' + opts.testId + '"]' });
                    }
                    // 7. Raw CSS fallback
                    if (opts.css) {
                        strategies.push({ type: 'css', sel: opts.css });
                    }

                    for (var i = 0; i < strategies.length; i++) {
                        var s = strategies[i];
                        try {
                            var el = null;
                            if (s.type === 'role') {
                                el = page.getByRole(s.role, s.name ? { name: s.name } : {});
                            } else if (s.type === 'text') {
                                el = page.getByText(s.text, { exact: false });
                            } else {
                                el = page.locator(s.sel);
                            }
                            var visible = await el.isVisible({ timeout: 2000 }).catch(function () { return false; });
                            if (visible) {
                                // Report which strategy succeeded (for debugging)
                                var via = '';
                                if (s.type === 'data-wb-action') via = 'data-wb-action';
                                else if (s.type === 'data-wb-entity') via = 'data-wb-entity';
                                else if (s.type === 'data-wb-component') via = 'data-wb-component';
                                else if (s.type === 'role') via = 'role=' + s.role;
                                else if (s.type === 'text') via = 'text';
                                else if (s.type === 'testid') via = 'testid';
                                else via = 'css';
                                testInfo.annotations.push({
                                    type: 'wb-debug',
                                    description: JSON.stringify({ located_by: via, target: (opts.action || opts.name || opts.text || opts.css || ''), strategies_tried: i + 1 }),
                                });
                                return el;
                            }
                        } catch (e) { /* try next strategy */ }
                    }

                    // None matched — report which strategies were attempted
                    var tried = strategies.map(function (s) { return s.type; }).join(', ');
                    testInfo.annotations.push({
                        type: 'wb-debug',
                        description: JSON.stringify({ locator_miss: true, target: (opts.action || opts.name || opts.text || opts.css || ''), strategies_tried: tried }),
                    });
                    return null;
                },

                // Evidence metadata for comprehension engine (set after observer.done())
                evidence: function (opts) {
                    testInfo.annotations.push({
                        type: 'wb-evidence',
                        description: JSON.stringify({
                            file: opts.file,
                            module: opts.module || 'project-audit-ledger',
                            action: opts.action,
                            entity_type: opts.entity_type,
                            entity_id: opts.entity_id,
                            tenant_id: opts.tenant_id,
                            run_id: opts.run_id,
                        }),
                    });
                },
            };
            await use(integrityObj);
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
