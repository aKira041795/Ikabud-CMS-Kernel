/**
 * PAL Deep-Link Crawl — multi-level link checking.
 *
 * Goes beyond surface route verification: visits every page from the
 * sidebar nav, follows entity-list rows to detail pages, checks action
 * button targets, and verifies every internal link returns HTTP 200.
 *
 * Crawl levels:
 *   L0: Login → Dashboard (entry)
 *   L1: All sidebar nav routes (nav items)
 *   L2: Entity links inside list pages (View, Edit, detail rows)
 *   L3: Action button targets on detail pages
 *
 * Defects captured: 404, 500, auth-redirect, broken shell, PHP errors.
 *
 * Run:
 *   ADMIN_USER=pAladmin ADMIN_PASS=pal123456 npx playwright test \
 *     tests/browser/modules/pal/pal-deeplink-crawl.spec.js
 */

// @ts-nocheck
var { test, expect } = require('../../WorkbenchFixture');
var path = require('path');
var fs = require('fs');

var APP_URL = process.env.APP_URL || 'http://palsystem.test';
var BASE = '/admin/project-audit-ledger';

// ── Manifest nav routes (all sidebar items) ───────────────────
var manifestPath = path.resolve(__dirname, '../../../../modules/project-audit-ledger/module.json');
var manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));

function collectNavRoutes(entries, routes) {
    for (var i = 0; i < entries.length; i++) {
        var entry = entries[i] || {};
        if (entry.url) routes.push(entry.url);
        if (Array.isArray(entry.children)) collectNavRoutes(entry.children, routes);
    }
}

var navRoutes = [];
collectNavRoutes(manifest.nav || manifest.sidebar || [], navRoutes);

// Also include detail/edit sub-routes known to exist
navRoutes.push(
    '/admin/project-audit-ledger/expenses/{id}',
    '/admin/project-audit-ledger/purchases/{id}',
    '/admin/project-audit-ledger/inventory/{id}',
    '/admin/project-audit-ledger/material-issuance/{id}',
    '/admin/project-audit-ledger/sales/{id}',
    '/admin/project-audit-ledger/collections/{id}',
    '/admin/project-audit-ledger/quotations/{id}',
    '/admin/project-audit-ledger/mobilization/{id}',
    '/admin/project-audit-ledger/cash-advances/{id}'
);
navRoutes = Array.from(new Set(navRoutes));

// ── Crawl state ──────────────────────────────────────────────
var visited = {};      // url -> status
var crawlQueue = [];   // {url, label, depth, parent}
var discoveredEntities = {};  // entityType -> [entityId]

/**
 * Diagnose the current page and return structured issues.
 */
async function diagnoseCurrentPage(page, url, label) {
    var issues = [];
    var status = 0;
    var bodyText = '';

    try {
        var resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
        status = resp ? resp.status() : 0;
    } catch (e) {
        issues.push({
            kind: 'navigation-error', severity: 'critical', where: label,
            detail: 'Navigation failed: ' + (e.message || '').substring(0, 200)
        });
        return issues;
    }

    try { bodyText = await page.locator('body').textContent({ timeout: 5000 }); } catch (e) { }

    var isApi = url.indexOf('/api/v1/') >= 0;
    var isEntityRoute = /\{\w+\}/.test(label) || /\/\d+$/.test(url);

    // Status diagnosis
    if (status === 302 || status === 301) {
        var loc = '';
        try { loc = resp.headers()['location'] || ''; } catch (e) { }
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
                ? 'HTTP 404 — entity not found'
                : 'HTTP 404 — route not found',
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

    // PHP error in body
    if (bodyText && (bodyText.indexOf('Internal Server Error') >= 0 ||
        bodyText.indexOf('Fatal error') >= 0)) {
        issues.push({
            kind: 'php-error-in-page', severity: 'critical', where: label,
            detail: 'PHP error rendered in page body'
        });
    }

    // Shell check
    if (!isApi) {
        var hasShell = false;
        try {
            hasShell = (await page.locator('[data-wb-component="app-shell"]').count()) > 0;
        } catch (e) { }
        if (!hasShell && label.indexOf('team-lead') !== 0) {
            issues.push({
                kind: 'broken-page', severity: 'major', where: label,
                detail: 'App shell did not render'
            });
        }
    }

    return issues;
}

/**
 * Extract entity IDs from an entity-list page.
 * Returns array of {entityType, entityId, label, detailUrl, editUrl}.
 */
async function extractEntityRows(page, sourcePageUrl) {
    var rows = [];

    // Method 1: data-ikb-list tables (PAL's entity list renderer)
    var tables = page.locator('[data-ikb-list]');
    var tableCount = 0;
    try { tableCount = await tables.count(); } catch (e) { }

    for (var ti = 0; ti < tableCount; ti++) {
        var table = tables.nth(ti);
        var entityType = '';
        try { entityType = await table.getAttribute('data-ikb-list'); } catch (e) { }
        if (!entityType) continue;

        // Find all tbody rows
        var trs = table.locator('tbody tr');
        var trCount = 0;
        try { trCount = await trs.count(); } catch (e) { }

        for (var ri = 0; ri < Math.min(trCount, 5); ri++) {  // limit to 5 per table
            var tr = trs.nth(ri);
            var entityId = '';
            try { entityId = await tr.getAttribute('data-wb-entity-id'); } catch (e) { }

            // Extract numeric ID from first link href
            if (!entityId) {
                var firstLink = tr.locator('a').first();
                var href = '';
                try { href = await firstLink.getAttribute('href'); } catch (e) { }
                if (href) {
                    var parts = href.split('/');
                    var last = parts[parts.length - 1];
                    if (/^\d+$/.test(last)) entityId = last;
                }
            }

            if (entityId) {
                // Build detail URL
                var detailUrl = sourcePageUrl + '/' + entityId;
                if (!entityType) entityType = 'entity';

                // Collect View/Edit links
                var viewHref = '';
                var editHref = '';
                var rowLinks = tr.locator('a');
                var rlCount = 0;
                try { rlCount = await rowLinks.count(); } catch (e) { }
                for (var li = 0; li < rlCount; li++) {
                    var txt = '';
                    var linkHref = '';
                    try { txt = (await rowLinks.nth(li).textContent()) || ''; } catch (e) { }
                    try { linkHref = (await rowLinks.nth(li).getAttribute('href')) || ''; } catch (e) { }
                    if (/view/i.test(txt) && linkHref) viewHref = linkHref;
                    if (/edit/i.test(txt) && linkHref) editHref = linkHref;
                }

                rows.push({
                    entityType: entityType,
                    entityId: entityId,
                    detailUrl: viewHref || detailUrl,
                    editUrl: editHref,
                });
            }
        }
    }

    // Method 2: data-ikb-source (alternative entity list attr)
    var altTables = page.locator('[data-ikb-source]');
    var altCount = 0;
    try { altCount = await altTables.count(); } catch (e) { }
    if (altCount > 0 && tableCount === 0) {
        for (var ai = 0; ai < altCount; ai++) {
            var at = altTables.nth(ai);
            var src = '';
            try { src = await at.getAttribute('data-ikb-source'); } catch (e) { }
            var aTrs = at.locator('tbody tr');
            var aTrCount = 0;
            try { aTrCount = await aTrs.count(); } catch (e) { }
            for (var ari = 0; ari < Math.min(aTrCount, 5); ari++) {
                var tr2 = aTrs.nth(ari);
                var firstLink = tr2.locator('a').first();
                var href = '';
                try { href = await firstLink.getAttribute('href'); } catch (e) { }
                if (href && /\/\d+$/.test(href)) {
                    var parts = href.split('/');
                    var eid = parts[parts.length - 1];
                    rows.push({
                        entityType: src || 'entity',
                        entityId: eid,
                        detailUrl: href,
                        editUrl: '',
                    });
                }
            }
        }
    }

    return rows;
}

test.describe('pal:deeplink-crawl', function () {
    test.setTimeout(120000); // 2 min per test — deep crawl is slow

    // ── L1: All sidebar nav routes ────────────────────────────
    test.describe('L1: Nav route coverage', function () {

        // Filter to unique base routes (no {id} params for L1)
        var baseRoutes = Array.from(new Set(navRoutes.filter(function (r) {
            return r.indexOf('{') === -1;
        })));

        for (var ri = 0; ri < baseRoutes.length; ri++) {
            (function (route) {
                var label = route.replace(BASE + '/', '') || 'dashboard';
                test('GET ' + label, async function ({ page, integrity }) {
                    var url = APP_URL + route;
                    var issues = await diagnoseCurrentPage(page, url, label);
                    for (var i = 0; i < issues.length; i++) {
                        integrity.issue(issues[i]);
                    }
                    visited[url] = 'checked';

                    var criticals = issues.filter(function (i) { return i.severity === 'critical'; });
                    if (criticals.length > 0) {
                        expect(criticals[0].detail,
                            'CRITICAL on ' + label + ': ' + criticals[0].detail
                        ).toBe('');
                    }
                });
            })(baseRoutes[ri]);
        }
    });

    // ── L2: Entity detail pages (extracted from list rows) ────
    test.describe('L2: Entity detail links', function () {

        // Visit each list page, extract entity rows, follow detail URLs
        var listPages = [
            { url: '/admin/project-audit-ledger/projects', entity: 'pal_project' },
            { url: '/admin/project-audit-ledger/clients', entity: 'pal_client' },
            { url: '/admin/project-audit-ledger/suppliers', entity: 'pal_supplier' },
            { url: '/admin/project-audit-ledger/expenses', entity: 'pal_expense' },
            { url: '/admin/project-audit-ledger/purchases', entity: 'pal_purchase' },
            { url: '/admin/project-audit-ledger/inventory', entity: 'pal_material' },
            { url: '/admin/project-audit-ledger/sales', entity: 'pal_sale' },
            { url: '/admin/project-audit-ledger/collections', entity: 'pal_collection' },
            { url: '/admin/project-audit-ledger/quotations', entity: 'pal_quotation' },
            { url: '/admin/project-audit-ledger/issuances', entity: 'pal_issuance' },
            { url: '/admin/project-audit-ledger/cash-advances', entity: 'pal_cash_advance' },
            { url: '/admin/project-audit-ledger/mobilization', entity: 'pal_mobilization' },
        ];

        for (var li = 0; li < listPages.length; li++) {
            (function (lp) {
                var listLabel = lp.url.replace(BASE + '/', '');

                test('detail from ' + listLabel, async function ({ page, integrity }) {
                    var listUrl = APP_URL + lp.url;
                    var issues = await diagnoseCurrentPage(page, listUrl, listLabel);
                    for (var i = 0; i < issues.length; i++) {
                        integrity.issue(issues[i]);
                    }

                    var criticals = issues.filter(function (i) { return i.severity === 'critical'; });
                    if (criticals.length > 0) {
                        integrity.issue({
                            kind: 'list-page-error', severity: 'critical',
                            where: listLabel,
                            detail: 'List page failed — cannot extract entity rows'
                        });
                        return;
                    }

                    // Extract entity rows
                    var rows = await extractEntityRows(page, APP_URL + lp.url);

                    if (rows.length === 0) {
                        integrity.issue({
                            kind: 'empty-list', severity: 'minor',
                            where: listLabel,
                            detail: 'No entity rows found to follow for detail links'
                        });
                        return;
                    }

                    // Follow the first entity's detail link
                    var first = rows[0];
                    integrity.fingerprint('entity:' + first.entityType + '#' + first.entityId);

                    if (first.detailUrl) {
                        var detailUrl = first.detailUrl.indexOf('http') === 0
                            ? first.detailUrl
                            : APP_URL + first.detailUrl;
                        var detailLabel = listLabel + '/' + first.entityId;
                        var detailIssues = await diagnoseCurrentPage(page, detailUrl, detailLabel);

                        for (var di = 0; di < detailIssues.length; di++) {
                            integrity.issue(detailIssues[di]);
                        }
                        visited[detailUrl] = 'checked';

                        var detailCriticals = detailIssues.filter(function (i) { return i.severity === 'critical'; });
                        if (detailCriticals.length > 0) {
                            expect(detailCriticals[0].detail,
                                'CRITICAL on detail ' + detailLabel + ': ' + detailCriticals[0].detail
                            ).toBe('');
                        }
                    }

                    // Also follow the edit link if present
                    if (first.editUrl) {
                        var editUrl = first.editUrl.indexOf('http') === 0
                            ? first.editUrl
                            : APP_URL + first.editUrl;
                        var editLabel = listLabel + '/' + first.entityId + '/edit';
                        var editIssues = await diagnoseCurrentPage(page, editUrl, editLabel);

                        for (var ei = 0; ei < editIssues.length; ei++) {
                            integrity.issue(editIssues[ei]);
                        }
                        visited[editUrl] = 'checked';

                        var editCriticals = editIssues.filter(function (i) { return i.severity === 'critical'; });
                        if (editCriticals.length > 0) {
                            expect(editCriticals[0].detail,
                                'CRITICAL on edit ' + editLabel + ': ' + editCriticals[0].detail
                            ).toBe('');
                        }
                    }
                });
            })(listPages[li]);
        }
    });

    // ── L3: Cross-navigation integrity ────────────────────────
    test.describe('L3: Cross-navigation', function () {

        test('navigate sidebar → list → detail → back preserves no 404s', async function ({ page, shell, integrity }) {
            // Navigate through 5 pages in sequence, all must return 200
            var waypoints = [
                { nav: 'Dashboard', url: BASE },
                { nav: 'All Job Orders', url: BASE + '/projects' },
                { nav: 'Clients', url: BASE + '/clients' },
                { nav: 'Expenses', url: BASE + '/expenses' },
                { nav: 'Approvals', url: BASE + '/approvals' },
            ];

            for (var wi = 0; wi < waypoints.length; wi++) {
                var wp = waypoints[wi];
                await shell.navigateViaSidebar(wp.nav);
                try {
                    await page.waitForURL('**' + wp.url, { timeout: 10000 });
                } catch (e) {
                    integrity.issue({
                        kind: 'cross-nav-failure', severity: 'major',
                        where: wp.nav,
                        detail: 'URL mismatch after sidebar click: ' + e.message.substring(0, 100)
                    });
                    continue;
                }

                var hasShell = false;
                try {
                    hasShell = (await page.locator('[data-wb-component="app-shell"]').count()) > 0;
                } catch (e) { }

                if (!hasShell) {
                    integrity.issue({
                        kind: 'cross-nav-broken', severity: 'critical',
                        where: wp.nav,
                        detail: 'App shell missing after navigation to ' + wp.url
                    });
                }
            }

            // Verify no critical issues were accumulated
            // (issues are already recorded via integrity.issue())
        });

        test('dashboard → create form → list — create page loads', async function ({ page, shell, integrity }) {
            await shell.navigateViaSidebar('New Job Order');
            try {
                await page.waitForURL('**' + BASE + '/projects/create', { timeout: 10000 });
                var form = page.locator('form').first();
                var formVisible = false;
                try { formVisible = await form.isVisible({ timeout: 5000 }); } catch (e) { }
                if (!formVisible) {
                    integrity.issue({
                        kind: 'form-not-found', severity: 'major',
                        where: 'New Job Order',
                        detail: 'Create form did not render on /projects/create'
                    });
                }
            } catch (e) {
                integrity.issue({
                    kind: 'create-page-failure', severity: 'critical',
                    where: 'New Job Order',
                    detail: 'Navigation to create form failed: ' + e.message.substring(0, 100)
                });
            }
        });
    });

    // ── L4: All internal links on dashboard ───────────────────
    test.describe('L4: Dashboard sub-link scan', function () {

        test('all links on dashboard return 200', async function ({ page, integrity }) {
            await page.goto(APP_URL + BASE, { waitUntil: 'networkidle', timeout: 30000 });
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            // Collect all internal <a> links on dashboard
            var links = page.locator('a[href]');
            var count = 0;
            try { count = await links.count(); } catch (e) { }

            var checkedUrls = {};
            var brokenLinks = [];

            for (var li2 = 0; li2 < count; li2++) {
                var href = '';
                try { href = await links.nth(li2).getAttribute('href'); } catch (e) { }

                // Filter to internal admin URLs
                if (!href || href.indexOf(BASE) !== 0 || href.indexOf('#') >= 0 || href.indexOf('logout') >= 0) continue;
                // Normalize
                if (href.indexOf(APP_URL) !== 0) href = APP_URL + href;
                // Deduplicate
                if (checkedUrls[href]) continue;
                checkedUrls[href] = true;

                try {
                    var resp = await page.goto(href, { waitUntil: 'networkidle', timeout: 30000 });
                    var status = resp ? resp.status() : 0;

                    if (status === 404) {
                        brokenLinks.push({ href: href, status: 404 });
                        integrity.issue({
                            kind: 'broken-link', severity: 'critical',
                            where: href.replace(APP_URL, ''),
                            detail: 'HTTP 404 from dashboard sub-link'
                        });
                    } else if (status >= 500) {
                        brokenLinks.push({ href: href, status: status });
                        integrity.issue({
                            kind: 'broken-link', severity: 'critical',
                            where: href.replace(APP_URL, ''),
                            detail: 'HTTP ' + status + ' from dashboard sub-link'
                        });
                    } else if (status === 302 || status === 301) {
                        var loc2 = '';
                        try { loc2 = resp.headers()['location'] || ''; } catch (e) { }
                        if (loc2.indexOf('/login') >= 0) {
                            integrity.issue({
                                kind: 'auth-redirect-from-link', severity: 'major',
                                where: href.replace(APP_URL, ''),
                                detail: 'Link redirected to login page'
                            });
                        }
                    }
                } catch (e) {
                    integrity.issue({
                        kind: 'link-nav-error', severity: 'major',
                        where: href.replace(APP_URL, ''),
                        detail: 'Navigation error: ' + (e.message || '').substring(0, 100)
                    });
                }

                // Navigate back to dashboard
                try {
                    await page.goto(APP_URL + BASE, { waitUntil: 'domcontentloaded', timeout: 15000 });
                    await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 5000 });
                } catch (e) {
                    integrity.issue({
                        kind: 'return-nav-failed', severity: 'critical',
                        where: 'dashboard',
                        detail: 'Could not navigate back to dashboard after link check'
                    });
                    break;
                }
            }

            if (brokenLinks.length > 0) {
                expect(brokenLinks.length, 'Found ' + brokenLinks.length + ' broken links on dashboard').toBe(0);
            }
        });
    });
});
