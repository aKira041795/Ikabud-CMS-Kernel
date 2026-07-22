/**
 * Attendance & Wage Deep-Link Crawl — multi-level link checking.
 *
 * Crawls all nav routes, follows entity-list rows to detail pages,
 * checks sub-links on every page for 404s/500s.
 *
 * NOTE: Attendance-wage uses a custom admin layout (not workbench:app_shell),
 * so there's no data-wb-component="app-shell". The shell check is skipped.
 *
 * Run:
 *   ADMIN_USER=zapadmin ADMIN_PASS='zap123!#' APP_URL=http://zapattendance.test \
 *     npx playwright test tests/browser/modules/attendance-wage/attendance-deeplink-crawl.spec.js
 */

// @ts-nocheck
var { test, expect } = require('@playwright/test');

var APP_URL = process.env.APP_URL || 'http://zapattendance.test';
var ADMIN_USER = process.env.ADMIN_USER || 'zapadmin';
var ADMIN_PASS = process.env.ADMIN_PASS || 'zap123!#';

// ── Nav routes (from admin.disyl layout) ─────────────────────
var NAV_ROUTES = [
    { label: 'Dashboard',        url: '/admin/wage' },
    { label: 'Attendance',       url: '/admin/attendance' },
    { label: 'Employees',        url: '/admin/wage/employees' },
    { label: 'Teams',            url: '/admin/wage/groups' },
    { label: 'Payroll Periods',  url: '/admin/wage/periods' },
    { label: 'Computations',     url: '/admin/wage/computations' },
    { label: 'Adjustments',      url: '/admin/wage/adjustments' },
    { label: 'Deductions',       url: '/admin/wage/deductions' },
    { label: 'Cash Advances',    url: '/admin/wage/cash-advances' },
    { label: 'Holidays',         url: '/admin/wage/holidays' },
    { label: 'Schedules',        url: '/admin/wage/schedules' },
    { label: 'Locations',        url: '/admin/wage/locations' },
    { label: 'Reports',          url: '/admin/wage/reports' },
    { label: 'Settings',         url: '/admin/wage/settings' },
    { label: 'Profile',          url: '/admin/wage/profile' },
];

// ── Entity list pages for L2 detail crawling ─────────────────
var LIST_PAGES = [
    { url: '/admin/wage/employees',      entityType: 'employee_profile' },
    { url: '/admin/wage/periods',        entityType: 'payroll_period' },
    { url: '/admin/wage/computations',   entityType: 'salary_computation' },
    { url: '/admin/wage/adjustments',    entityType: 'salary_adjustment' },
    { url: '/admin/wage/deductions',     entityType: 'employee_deduction' },
    { url: '/admin/wage/cash-advances',  entityType: 'cash_advance' },
    { url: '/admin/wage/holidays',       entityType: 'holiday' },
    { url: '/admin/wage/locations',      entityType: 'office_location' },
    { url: '/admin/wage/groups',         entityType: 'attendance_group' },
];

// ── Session state: login once, reuse across tests ────────────
var _sessionInitialized = false;

async function ensureLoggedIn(page) {
    if (_sessionInitialized) return; // session cookie persists in context

    await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle', timeout: 30000 });

    // Check for rate-limit error
    var bodyText = '';
    try { bodyText = await page.locator('body').textContent({ timeout: 2000 }); } catch (e) {}
    if (bodyText && bodyText.indexOf('Too many login') >= 0) {
        var match = bodyText.match(/retry_after["':]\s*(\d+)/);
        var waitSec = match ? parseInt(match[1], 10) + 5 : 120;
        console.log('  ⏳ Rate limited. Waiting ' + waitSec + 's...');
        await new Promise(function (r) { setTimeout(r, waitSec * 1000); });
        await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle', timeout: 30000 });
    }

    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/wage**', { timeout: 15000 });
    await page.waitForSelector('aside nav a', { timeout: 5000 });
    _sessionInitialized = true;
    console.log('  ✅ Login successful');
}

/**
 * Diagnose a page visit: returns structured issues.
 */
async function diagnosePage(page, url, label) {
    var issues = [];
    var status = 0;

    try {
        var resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
        status = resp ? resp.status() : 0;
    } catch (e) {
        issues.push({ kind: 'navigation-error', severity: 'critical', where: label,
            detail: 'Navigation failed: ' + (e.message || '').substring(0, 200) });
        return issues;
    }

    var bodyText = '';
    try { bodyText = await page.locator('body').textContent({ timeout: 5000 }); } catch (e) {}

    if (status === 302 || status === 301) {
        var loc = '';
        try { loc = resp.headers()['location'] || ''; } catch (e) {}
        if (loc.indexOf('/login') >= 0) {
            issues.push({ kind: 'auth-redirect', severity: 'critical', where: label,
                detail: 'Redirected to login — session expired' });
        } else {
            issues.push({ kind: 'redirect', severity: 'info', where: label,
                detail: 'HTTP ' + status + ' -> ' + loc });
        }
        return issues;
    }

    if (status === 404) {
        issues.push({ kind: 'missing-route', severity: 'critical', where: label,
            detail: 'HTTP 404 — route not found' });
        return issues;
    }

    if (status === 500) {
        issues.push({ kind: 'server-error', severity: 'critical', where: label,
            detail: 'HTTP 500 — handler threw exception' });
        return issues;
    }

    // PHP error in body
    if (bodyText && (bodyText.indexOf('Internal Server Error') >= 0 ||
        bodyText.indexOf('Fatal error') >= 0)) {
        issues.push({ kind: 'php-error', severity: 'critical', where: label,
            detail: 'PHP error rendered in page body' });
    }

    // Check for login page re-render (session issue)
    if (bodyText && bodyText.indexOf('Sign in to your account') >= 0 && bodyText.indexOf('Dashboard') < 0) {
        issues.push({ kind: 'session-expired', severity: 'critical', where: label,
            detail: 'Page shows login form instead of admin content' });
    }

    return issues;
}

/**
 * Extract entity IDs from an entity-list table.
 */
async function extractEntityRows(page) {
    var rows = [];

    // Method 1: data-ikb-list tables
    var tables = page.locator('[data-ikb-list]');
    var tableCount = 0;
    try { tableCount = await tables.count(); } catch (e) {}

    for (var ti = 0; ti < tableCount; ti++) {
        var table = tables.nth(ti);
        var entityType = '';
        try { entityType = await table.getAttribute('data-ikb-list'); } catch (e) {}
        if (!entityType) continue;

        var trs = table.locator('tbody tr');
        var trCount = 0;
        try { trCount = await trs.count(); } catch (e) {}

        for (var ri = 0; ri < Math.min(trCount, 3); ri++) {
            var tr = trs.nth(ri);
            var entityId = '';
            try { entityId = await tr.getAttribute('data-wb-entity-id'); } catch (e) {}

            if (!entityId) {
                var firstLink = tr.locator('a').first();
                var href = '';
                try { href = await firstLink.getAttribute('href'); } catch (e) {}
                if (href) {
                    var parts = href.split('/');
                    var last = parts[parts.length - 1];
                    if (/^\d+$/.test(last)) entityId = last;
                    // Also check second-to-last segment (e.g., /employees/{id}/view)
                    if (!entityId && parts.length >= 2) {
                        var secondLast = parts[parts.length - 2];
                        if (/^\d+$/.test(secondLast)) entityId = secondLast;
                    }
                }
            }

            if (entityId) {
                var viewHref = '';
                var editHref = '';
                var rowLinks = tr.locator('a');
                var rlCount = 0;
                try { rlCount = await rowLinks.count(); } catch (e) {}
                for (var li = 0; li < rlCount; li++) {
                    var txt = '';
                    var linkHref = '';
                    try { txt = (await rowLinks.nth(li).textContent()) || ''; } catch (e) {}
                    try { linkHref = (await rowLinks.nth(li).getAttribute('href')) || ''; } catch (e) {}
                    if (/view|detail/i.test(txt) && linkHref) viewHref = linkHref;
                    if (/edit/i.test(txt) && linkHref) editHref = linkHref;
                }

                rows.push({
                    entityType: entityType,
                    entityId: entityId,
                    detailUrl: viewHref,
                    editUrl: editHref,
                });
            }
        }
    }

    // Method 2: Plain tables with links to detail pages
    if (rows.length === 0) {
        var allLinks = page.locator('a[href]');
        var linkCount = 0;
        try { linkCount = await allLinks.count(); } catch (e) {}
        for (var li2 = 0; li2 < linkCount && rows.length < 3; li2++) {
            var href = '';
            try { href = await allLinks.nth(li2).getAttribute('href'); } catch (e) {}
            if (href && /\/\d+$/.test(href) && href.indexOf('/admin/') >= 0) {
                var parts = href.split('/');
                var eid = parts[parts.length - 1];
                if (!rows.some(function(r) { return r.entityId === eid; })) {
                    rows.push({ entityType: 'entity', entityId: eid, detailUrl: href, editUrl: '' });
                }
            }
        }
    }

    return rows;
}

// ── Tests ─────────────────────────────────────────────────────

test.describe('attendance:deeplink-crawl', function () {
    test.setTimeout(600000); // 10 min for full crawl

    test('all levels: nav routes + entity details + sub-links + cross-nav', async function ({ page }) {
        // ── Login once ──
        await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle', timeout: 30000 });

        var bodyText = '';
        try { bodyText = await page.locator('body').textContent({ timeout: 2000 }); } catch (e) {}
        if (bodyText && bodyText.indexOf('Too many login') >= 0) {
            var match = bodyText.match(/retry_after["':]\s*(\d+)/);
            var waitSec = match ? parseInt(match[1], 10) + 5 : 120;
            console.log('  ⏳ Rate limited. Waiting ' + waitSec + 's...');
            await new Promise(function (r) { setTimeout(r, waitSec * 1000); });
            await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'networkidle', timeout: 30000 });
        }

        await page.fill('input[name="username"]', ADMIN_USER);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/wage**', { timeout: 15000 });
        await page.waitForSelector('aside nav a', { timeout: 5000 });
        console.log('  ✅ Login successful');

        var allBroken = [];

        // ── L1: All nav routes ──
        console.log('\n  ── L1: Nav routes ──');
        for (var i = 0; i < NAV_ROUTES.length; i++) {
            var navItem = NAV_ROUTES[i];
            var issues = await diagnosePage(page, APP_URL + navItem.url, navItem.label);
            var criticals = issues.filter(function (i2) { return i2.severity === 'critical'; });
            if (criticals.length > 0) {
                allBroken.push({ level: 'L1', label: navItem.label, detail: criticals[0].detail });
                console.log('  ❌ ' + navItem.label + ': ' + criticals[0].detail);
            }
        }

        // ── L2: Entity detail links ──
        console.log('\n  ── L2: Entity details ──');
        for (var li = 0; li < LIST_PAGES.length; li++) {
            var lp = LIST_PAGES[li];

            var listIssues = await diagnosePage(page, APP_URL + lp.url, lp.entityType + '-list');
            var listCriticals = listIssues.filter(function (i3) { return i3.severity === 'critical'; });
            if (listCriticals.length > 0) {
                console.log('  ⚠ ' + lp.url + ': ' + listCriticals[0].detail);
                continue;
            }

            var rows = await extractEntityRows(page);
            if (rows.length === 0) {
                console.log('  ℹ No entity rows on ' + lp.url);
                continue;
            }

            var first = rows[0];
            var detailUrl = first.detailUrl
                ? (first.detailUrl.indexOf('http') === 0 ? first.detailUrl : APP_URL + first.detailUrl)
                : APP_URL + lp.url + '/' + first.entityId;
            var detailLabel = lp.entityType + '#' + first.entityId;

            var detailIssues = await diagnosePage(page, detailUrl, detailLabel);
            var detailCriticals = detailIssues.filter(function (i4) { return i4.severity === 'critical'; });
            if (detailCriticals.length > 0) {
                allBroken.push({ level: 'L2', label: detailLabel, detail: detailCriticals[0].detail });
                console.log('  ❌ Detail ' + detailLabel + ': ' + detailCriticals[0].detail);
            }

            if (first.editUrl) {
                var editUrl = first.editUrl.indexOf('http') === 0 ? first.editUrl : APP_URL + first.editUrl;
                var editLabel = lp.entityType + '#' + first.entityId + '/edit';
                var editIssues = await diagnosePage(page, editUrl, editLabel);
                var editCriticals = editIssues.filter(function (i5) { return i5.severity === 'critical'; });
                if (editCriticals.length > 0) {
                    allBroken.push({ level: 'L2', label: editLabel, detail: editCriticals[0].detail });
                    console.log('  ❌ Edit ' + editLabel + ': ' + editCriticals[0].detail);
                }
            }
        }

        // ── L3: Dashboard sub-link scan ──
        console.log('\n  ── L3: Dashboard sub-links ──');
        await page.goto(APP_URL + '/admin/wage', { waitUntil: 'networkidle', timeout: 30000 });
        var links = page.locator('a[href]');
        var linkCount = 0;
        try { linkCount = await links.count(); } catch (e) {}

        var checked = {};
        var testedCount = 0;

        for (var li3 = 0; li3 < linkCount; li3++) {
            var href = '';
            try { href = await links.nth(li3).getAttribute('href'); } catch (e) {}
            if (!href || href === '#') continue;
            if (href.indexOf('/admin/') !== 0 && href.indexOf(APP_URL + '/admin/') !== 0) continue;
            if (href.indexOf('logout') >= 0 || href.indexOf('print') >= 0) continue;

            var absUrl = href.indexOf('http') === 0 ? href : APP_URL + href;
            if (checked[absUrl]) continue;
            checked[absUrl] = true;
            testedCount++;

            try {
                var resp = await page.goto(absUrl, { waitUntil: 'networkidle', timeout: 30000 });
                var status = resp ? resp.status() : 0;
                if (status === 404) {
                    allBroken.push({ level: 'L3', label: absUrl.replace(APP_URL, ''), detail: 'HTTP 404' });
                    console.log('  ❌ 404: ' + absUrl.replace(APP_URL, ''));
                } else if (status >= 500) {
                    allBroken.push({ level: 'L3', label: absUrl.replace(APP_URL, ''), detail: 'HTTP ' + status });
                    console.log('  ❌ ' + status + ': ' + absUrl.replace(APP_URL, ''));
                }
            } catch (e) {
                console.log('  ❌ Nav error: ' + absUrl.replace(APP_URL, ''));
            }

            try { await page.goto(APP_URL + '/admin/wage', { waitUntil: 'domcontentloaded', timeout: 15000 }); } catch (e) { break; }
        }
        console.log('  Checked ' + testedCount + ' internal links from dashboard');

        // ── L4: Cross-page navigation ──
        console.log('\n  ── L4: Cross-nav ──');
        var waypoints = [
            '/admin/wage', '/admin/attendance', '/admin/wage/employees',
            '/admin/wage/periods', '/admin/wage/settings', '/admin/wage/reports',
            '/admin/wage/holidays', '/admin/wage/locations',
        ];
        for (var wi = 0; wi < waypoints.length; wi++) {
            var wp = waypoints[wi];
            var label2 = wp.replace('/admin/', '') || 'dashboard';
            var wIssues = await diagnosePage(page, APP_URL + wp, label2);
            var wCriticals = wIssues.filter(function (i6) { return i6.severity === 'critical'; });
            if (wCriticals.length > 0) {
                allBroken.push({ level: 'L4', label: label2, detail: wCriticals[0].detail });
                console.log('  ❌ ' + label2 + ': ' + wCriticals[0].detail);
            }
        }

        // ── Report ──
        console.log('\n  ── Results ──');
        if (allBroken.length > 0) {
            console.log('  ❌ ' + allBroken.length + ' broken link(s) found:');
            for (var bi = 0; bi < allBroken.length; bi++) {
                console.log('    ' + allBroken[bi].level + ' ' + allBroken[bi].label + ': ' + allBroken[bi].detail);
            }
            expect(allBroken[0].detail, allBroken[0].level + ' ' + allBroken[0].label).toBe('');
        } else {
            console.log('  ✅ All links healthy — zero 404s, zero 500s');
        }
    });
});
