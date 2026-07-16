/**
 * PAL Contract Verification — drives every claimed route through the real
 * browser and reports actionable diagnostics into the Workbench Test Cockpit.
 *
 * DIAGNOSTIC CONTRACT (every failure includes):
 *   - Exact HTTP status code
 *   - Route-exists vs entity-missing classification
 *   - Shell rendering check
 *   - PHP error detection in page body
 *   - Specific recommendation for fix
 *
 * Run:
 *   ADMIN_USER=pAladmin ADMIN_PASS=pal123456 npx playwright test \
 *     tests/browser/modules/pal/pal-contract-verification.spec.js
 */

// @ts-nocheck
var { test, expect } = require('../../WorkbenchFixture');
var path = require('path');
var fs = require('fs');

var BASE = '/admin/project-audit-ledger';
var APP_URL = process.env.APP_URL || 'http://palsystem.test';

// ── Load test contract ────────────────────────────────────────
var contractPath = path.resolve(__dirname, '../../../../modules/project-audit-ledger/test-contract.json');
var contract = JSON.parse(fs.readFileSync(contractPath, 'utf-8'));
var testContract = contract.test_contract || {};
var claimedRoutes = (testContract.routes_claimed || {}).GET || [];
var manifestPath = path.resolve(__dirname, '../../../../modules/project-audit-ledger/module.json');
var manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));
var collectNavRoutes = function (entries, routes) {
    for (var i = 0; i < entries.length; i++) {
        var entry = entries[i] || {};
        if (entry.url) routes.push(entry.url);
        if (Array.isArray(entry.children)) collectNavRoutes(entry.children, routes);
    }
};
var manifestNavRoutes = [];
collectNavRoutes(manifest.nav || manifest.sidebar || [], manifestNavRoutes);
claimedRoutes = Array.from(new Set(claimedRoutes.concat(manifestNavRoutes)));

/**
 * Visit a route and collect all diagnostic issues found.
 * Never throws — returns structured issue array.
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

    try { bodyText = await page.locator('body').textContent(); } catch (e) { }

    var isApi = url.indexOf('/api/v1/') >= 0;
    var isEntityRoute = /\{\w+\}/.test(label) || /\/\d+$/.test(url);

    // ── Status diagnosis ──
    if (status === 302 || status === 301) {
        var loc = resp ? (resp.headers()['location'] || '') : '';
        if (loc.indexOf('/login') >= 0) {
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
            kind: isEntityRoute ? 'entity-not-found' : 'missing-route',
            severity: isEntityRoute ? 'minor' : 'critical',
            where: label,
            detail: isEntityRoute
                ? 'HTTP 404 — entity not found (expected — no record with that ID exists)'
                : 'HTTP 404 — route claimed in contract but no handler registered',
            recommendation: isEntityRoute
                ? ''
                : 'Register route in routes.php or remove from test-contract.json',
        });
        return issues;
    }

    if (status === 500) {
        issues.push({
            kind: 'server-error', severity: 'critical', where: label,
            detail: 'HTTP 500 — handler threw uncaught exception',
            recommendation: 'Check PHP error log for stack trace'
        });
        return issues;
    }

    // ── PHP error in body ──
    if (bodyText && (bodyText.indexOf('Internal Server Error') >= 0 ||
        bodyText.indexOf('Fatal error') >= 0)) {
        issues.push({
            kind: 'php-error-in-page', severity: 'critical', where: label,
            detail: 'PHP error rendered in page body'
        });
    }

    // ── Shell check (admin pages only, skip team-lead which has its own shell) ──
    if (!isApi && label.indexOf('team-lead') !== 0) {
        var hasShell = false;
        try {
            hasShell = (await page.locator('[data-wb-component="app-shell"]').count()) > 0;
        } catch (e) { }
        if (!hasShell) {
            issues.push({
                kind: 'broken-page', severity: 'major', where: label,
                detail: 'App shell did not render — wrong template or missing layout',
                recommendation: 'Check template extends admin layout with app-shell'
            });
        }
    }

    return issues;
}

test.describe('pal:contract-verification', function () {

    // ── A. Every claimed GET route ─────────────────────────────
    test.describe('A. Route coverage', function () {

        for (var ri = 0; ri < claimedRoutes.length; ri++) {
            (function (route) {
                var label = route.replace('/admin/project-audit-ledger/', '') || 'dashboard';
                test('GET ' + label, async function ({ page, integrity }) {
                    var url = APP_URL + route.replace(/\{(\w+)\}/g, '1');
                    var issues = await diagnoseRoute(page, url, label);

                    for (var i = 0; i < issues.length; i++) {
                        integrity.issue(issues[i]);
                    }

                    var criticals = issues.filter(function (i) { return i.severity === 'critical'; });
                    if (criticals.length > 0) {
                        expect(criticals[0].detail,
                            'CRITICAL: ' + label + ' — ' + criticals[0].detail
                        ).toBe('');
                    }
                });
            })(claimedRoutes[ri]);
        }
    });

    // ── B. Workbench components ────────────────────────────────
    test.describe('B. Workbench components', function () {

        test('dashboard matches visual baseline', async function ({ page }) {
            await page.goto(APP_URL + BASE, { waitUntil: 'networkidle', timeout: 30000 });
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            // Visual baseline: captures screenshot, compares on subsequent runs.
            // Update baseline with: npx playwright test --update-snapshots
            await expect(page).toHaveScreenshot('pal-dashboard.png', {
                maxDiffPixelRatio: 0.02,  // allow 2% diff for anti-aliasing
            });
        });

        test('dashboard has summary-card', async function ({ page, integrity }) {
            await page.goto(APP_URL + BASE, { waitUntil: 'networkidle', timeout: 30000 });
            var n = await page.locator('[data-wb-component="summary-card"]').count();
            if (n === 0) {
                integrity.issue({
                    kind: 'missing-component', severity: 'major',
                    where: 'dashboard',
                    detail: 'summary-card not found — KPI row may be missing',
                    recommendation: 'Add summary-card or {ikb_entity_list} to dashboard'
                });
            }
        });

        test('project-list has entity-list', async function ({ page, integrity }) {
            await page.goto(APP_URL + BASE + '/projects', { waitUntil: 'networkidle', timeout: 30000 });
            var n = await page.locator('[data-wb-component="entity-list"]').count();
            if (n === 0) {
                integrity.issue({
                    kind: 'missing-component', severity: 'major',
                    where: '/projects',
                    detail: 'entity-list not found — table may be plain HTML',
                    recommendation: 'Convert project list to {ikb_entity_list}'
                });
            }
        });

        test('status badge has visible text', async function ({ page, integrity }) {
            await page.goto(APP_URL + BASE + '/projects', { waitUntil: 'networkidle', timeout: 30000 });
            var badges = page.locator('[data-wb-component="status-badge"]');
            if ((await badges.count()) > 0) {
                var txt = await badges.first().textContent();
                if (!txt || txt.trim() === '') {
                    integrity.issue({
                        kind: 'a11y', severity: 'major',
                        where: 'status-badge',
                        detail: 'No visible text — color-only indicator'
                    });
                }
            }
        });
    });
});
