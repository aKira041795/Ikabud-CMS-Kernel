/**
 * Daily Ledger — Contract Verification Spec
 *
 * Drives every claimed GET route from the workbench contract through
 * the real browser and reports actionable diagnostics.
 *
 * DIAGNOSTIC CONTRACT (every failure includes):
 *   - Exact HTTP status code
 *   - Route-exists vs entity-missing classification
 *   - Shell rendering check
 *   - PHP error detection in page body
 *   - Specific recommendation for fix
 *
 * Env:
 *   TEST_BASE_URL   — target site (e.g. https://baronledger.test)
 *   TEST_ADMIN_USER — admin username
 *   TEST_ADMIN_PASS — admin password
 */

// @ts-check
var { test, expect } = require('./daily-ledger-adapter');
var path = require('path');
var fs = require('fs');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

// ── Load workbench contract ────────────────────────────────────
var contractPath = path.resolve(__dirname, '../../../../modules/daily-ledger/workbench-contract.json');
var contract = JSON.parse(fs.readFileSync(contractPath, 'utf-8'));
var claimedGetRoutes = (contract.ownership && contract.ownership.routes && contract.ownership.routes.GET) || [];

// ── Load module.json for nav routes ────────────────────────────
var manifestPath = path.resolve(__dirname, '../../../../modules/daily-ledger/module.json');
var manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));
var collectNavRoutes = function (entries, routes) {
    for (var i = 0; i < entries.length; i++) {
        var entry = entries[i] || {};
        if (entry.url) routes.push(entry.url);
        if (Array.isArray(entry.children)) collectNavRoutes(entry.children, routes);
    }
};
var manifestNavRoutes = [];
collectNavRoutes(manifest.nav || [], manifestNavRoutes);

// Merge and dedupe
var allRoutes = Array.from(new Set(claimedGetRoutes.concat(manifestNavRoutes)));

// Filter: only page routes (skip API endpoints for browser verification)
var pageRoutes = allRoutes.filter(function (r) {
    return r.indexOf('/api/') < 0;
});

// Filter: skip dynamic entity routes with params
var staticRoutes = pageRoutes.filter(function (r) {
    return r.indexOf('{') < 0 && r.indexOf(':') < 0;
});

/**
 * Visit a route and collect structured diagnostic issues.
 * Never throws — returns issue array.
 */
async function diagnoseRoute(page, url, label) {
    var issues = [];
    var resp = null;
    var status = 0;
    var bodyText = '';

    try {
        resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
        status = resp ? resp.status() : 0;
    } catch (e) {
        issues.push({
            kind: 'navigation-error', severity: 'critical', where: label,
            detail: 'Navigation failed: ' + (e.message || '').substring(0, 200)
        });
        return issues;
    }

    try { bodyText = await page.locator('body').textContent(); } catch (e) { bodyText = ''; }

    var isApi = url.indexOf('/api/v1/') >= 0;

    // ── Status diagnosis ──
    if (status === 302 || status === 301) {
        var loc = resp ? (resp.headers()['location'] || '') : '';
        if (loc.indexOf('/login') >= 0 || loc.indexOf('/daily-ledger/login') >= 0) {
            issues.push({
                kind: 'auth-redirect', severity: 'critical', where: label,
                detail: 'Redirected to login — session expired or auth required'
            });
        } else {
            issues.push({
                kind: 'redirect', severity: 'info', where: label,
                detail: 'HTTP ' + status + ' -> ' + loc
            });
        }
        return issues;
    }

    if (status === 404) {
        issues.push({
            kind: 'missing-route', severity: 'critical', where: label,
            detail: 'HTTP 404 — route claimed in contract but no handler registered',
            recommendation: 'Register route in routes.php or remove from workbench-contract.json',
        });
        return issues;
    }

    if (status >= 500) {
        issues.push({
            kind: 'server-error', severity: 'critical', where: label,
            detail: 'HTTP ' + status + ' — server error on claimed route',
        });
        return issues;
    }

    // ── Shell rendering ──
    if (!isApi) {
        var shell = page.locator('[data-wb-component="app-shell"]').first();
        try {
            await shell.waitFor({ state: 'visible', timeout: 5000 });
        } catch (e) {
            issues.push({
                kind: 'shell-missing', severity: 'major', where: label,
                detail: 'App shell not rendered on page route',
                recommendation: 'Verify the page template extends the correct layout',
            });
        }
    }

    // ── PHP error detection ──
    if (bodyText.indexOf('Fatal error') >= 0 || bodyText.indexOf('Stack trace') >= 0) {
        issues.push({
            kind: 'php-error', severity: 'critical', where: label,
            detail: 'PHP error leaked into page body',
        });
    }
    if (bodyText.indexOf('Warning:') >= 0 || bodyText.indexOf('Notice:') >= 0) {
        issues.push({
            kind: 'php-warning', severity: 'major', where: label,
            detail: 'PHP warning/notice in page output',
        });
    }

    return issues;
}

test.describe('Daily Ledger — Contract Verification', () => {

    test.describe('Static Page Routes (' + staticRoutes.length + ' routes)', () => {

        // Run each static route as its own test
        staticRoutes.forEach(function (route) {
            var fullUrl = APP_URL + route;
            var label = 'GET ' + route;

            test(label, async function ({ page, integrity }) {
                integrity.fingerprint('modules/daily-ledger/routes.php');
                integrity.fingerprint('modules/daily-ledger/workbench-contract.json');

                var issues = await diagnoseRoute(page, fullUrl, label);

                if (issues.length === 0) {
                    // Route is healthy
                    return;
                }

                // Report each issue
                for (var i = 0; i < issues.length; i++) {
                    var issue = issues[i];
                    integrity.issue({
                        kind: issue.kind,
                        severity: issue.severity,
                        where: issue.where,
                        detail: issue.detail,
                        recommendation: issue.recommendation || '',
                    });
                }

                // Critical issues should fail the test
                var criticalIssues = issues.filter(function (i) { return i.severity === 'critical'; });
                expect(criticalIssues.length, 'No critical issues for ' + label).toBe(0);
            });
        });
    });

    test.describe('Summary', () => {

        test('all declared page routes are verifiable', async ({ integrity }) => {
            integrity.fingerprint('modules/daily-ledger/module.json');

            var totalClaimed = claimedGetRoutes.length;
            var pageRouteCount = pageRoutes.length;
            var staticRouteCount = staticRoutes.length;
            var apiRouteCount = totalClaimed - pageRouteCount;

            // Structural assertion: contract has a reasonable route count
            expect(totalClaimed, 'Workbench contract must declare GET routes').toBeGreaterThanOrEqual(30);
            expect(pageRouteCount, 'Must have page (non-API) routes').toBeGreaterThanOrEqual(10);
            expect(apiRouteCount, 'Must have API GET routes').toBeGreaterThanOrEqual(15);

            integrity.gap('Dynamic/parameterized routes not verified — need entity IDs: ' +
                (pageRouteCount - staticRouteCount) + ' routes skipped');
            integrity.gap('API GET routes not browser-verified: ' + apiRouteCount + ' routes — use PHP integration tests');
        });
    });

    test.describe('Documented Gaps', () => {

        test('browser contract gaps documented', async ({ integrity }) => {
            integrity.gap('POST routes not browser-verified — use workbench:run PHP tests');
            integrity.gap('Multi-tenant isolation: routes must scope to active tenant — needs cross-tenant fixture');
            integrity.gap('Rate-limited endpoints: login, forgot-password, reset-password — skip in contract crawl');
        });
    });
});
