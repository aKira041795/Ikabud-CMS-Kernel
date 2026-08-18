/**
 * AW (Attendance & Wage) Admin Page Loop — crawls every admin page as the
 * zapadmin user, checking HTTP status, console errors, page errors, and
 * server-side render failures.
 *
 * Run:
 *   ADMIN_USER=zapadmin ADMIN_PASS='zap123!#' APP_URL=http://zapattendance.test \
 *     npx playwright test tests/browser/modules/attendance-wage/aw-page-loop.spec.js
 */

// @ts-nocheck
var { test, expect } = require('@playwright/test');

var APP_URL = process.env.APP_URL || 'http://zapattendance.test';
var ADMIN_USER = process.env.ADMIN_USER || 'zapadmin';
var ADMIN_PASS = process.env.ADMIN_PASS || 'zap123!#';

var PAGES = [
    { url: '/admin/wage', label: 'Dashboard' },
    { url: '/admin/attendance', label: 'Attendance' },
    { url: '/admin/attendance/history', label: 'Attendance History' },
    { url: '/admin/attendance/report', label: 'Attendance Report' },
    { url: '/admin/wage/employees', label: 'Employees' },
    { url: '/admin/wage/employees/create', label: 'Employee Form' },
    { url: '/admin/wage/periods', label: 'Payroll Periods' },
    { url: '/admin/wage/periods/create', label: 'Payroll Period Form' },
    { url: '/admin/wage/computations', label: 'Computations' },
    { url: '/admin/wage/adjustments', label: 'Adjustments' },
    { url: '/admin/wage/adjustments/create', label: 'Adjustment Form' },
    { url: '/admin/wage/deductions', label: 'Deductions' },
    { url: '/admin/wage/deductions/create', label: 'Deduction Form' },
    { url: '/admin/wage/cash-advances', label: 'Cash Advances' },
    { url: '/admin/wage/cash-advances/create', label: 'Cash Advance Form' },
    { url: '/admin/wage/holidays', label: 'Holidays' },
    { url: '/admin/wage/schedules', label: 'Schedules' },
    { url: '/admin/wage/reports', label: 'Reports' },
    { url: '/admin/wage/reports/summary', label: 'Reports Summary' },
    { url: '/admin/wage/locations', label: 'Locations' },
    { url: '/admin/wage/locations/create', label: 'Location Form' },
    { url: '/admin/wage/benefits-calculator', label: 'Benefits Calculator' },
    { url: '/admin/wage/migration', label: 'Migration' },
    { url: '/admin/wage/settings', label: 'Settings' },
    { url: '/admin/wage/groups', label: 'Teams/Groups' },
    { url: '/admin/wage/groups/create', label: 'Group Form' },
    { url: '/admin/wage/profile', label: 'Profile' },
];

test('AW admin page loop — all pages', async ({ page }) => {
    test.setTimeout(420000);

    // Login once (single login avoids the AW login rate limiter)
    await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'domcontentloaded' });
    var body = '';
    try { body = await page.locator('body').innerText({ timeout: 2000 }); } catch (e) { }
    if (body && body.indexOf('Too many login') >= 0) {
        var m = body.match(/retry_after["':]\s*(\d+)/);
        var waitSec = m ? parseInt(m[1], 10) + 5 : 120;
        console.log('  ⏳ Rate limited. Waiting ' + waitSec + 's...');
        await new Promise(function (r) { setTimeout(r, waitSec * 1000); });
        await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'domcontentloaded' });
    }
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL('**/admin/wage**', { timeout: 20000 }),
        page.click('button[type="submit"]'),
    ]);
    console.log('  ✅ Login successful');

    var failures = [];
    for (var p of PAGES) {
        var consoleErrors = [];
        var pageErrors = [];
        var mainResponse = null;

        page.on('console', function (msg) {
            if (msg.type() === 'error' && msg.text().indexOf('cdn.tailwindcss.com') < 0) consoleErrors.push(msg.text());
        });
        page.on('pageerror', function (err) { pageErrors.push(String(err)); });

        var resp = await page.goto(APP_URL + p.url, { waitUntil: 'domcontentloaded' });
        mainResponse = resp;
        await page.waitForLoadState('networkidle').catch(function () { });

        var status = mainResponse ? mainResponse.status() : 0;
        var bodyText = await page.locator('body').innerText().catch(function () { return ''; });
        var markers = [];
        if (/render_failure|Render failed|Fatal error|Uncaught TypeError|SQLSTATE|Whoops/i.test(bodyText)) {
            markers.push('server error marker in body');
        }

        console.log('[' + status + '] ' + p.url + ' :: consoleErrors=' + consoleErrors.length + ' pageErrors=' + pageErrors.length);
        consoleErrors.slice(0, 4).forEach(function (e) { console.log('   console.error: ' + e); });
        pageErrors.slice(0, 4).forEach(function (e) { console.log('   pageerror: ' + e); });
        markers.forEach(function (e) { console.log('   marker: ' + e); });

        var finalUrl = page.url();
        if (finalUrl.indexOf('/attendance-wage/login') >= 0) {
            console.log('   ⚠ redirected to login (auth/tenant issue)');
        }

        if (status >= 500) failures.push(p.url + ' -> HTTP ' + status);
        if (pageErrors.length) failures.push(p.url + ' -> page errors: ' + pageErrors.join('; '));
        if (markers.length) failures.push(p.url + ' -> ' + markers.join('; '));
    }

    if (failures.length) {
        console.log('── FAILURES ──');
        failures.forEach(function (f) { console.log('  ❌ ' + f); });
    }
    expect(failures, 'crawl failures').toEqual([]);
});
