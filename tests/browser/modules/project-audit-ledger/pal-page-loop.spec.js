/**
 * PAL Admin Page Loop — crawls every admin page as an authenticated admin,
 * simulating navigation, and reports HTTP status, console errors, page
 * crashes, and server render failures. Catches 500s and template issues
 * that manual testing would hit.
 *
 * Usage:
 *   APP_URL=http://palsystem.test npx playwright test tests/browser/modules/project-audit-ledger/pal-page-loop.spec.js
 *
 * Env:
 *   APP_URL    - app base URL (default http://palsystem.test)
 *   ADMIN_USER - default admin
 *   ADMIN_PASS - default pal1234
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'pal1234';

const PAGES = [
    { url: '/admin/project-audit-ledger', label: 'Dashboard' },
    { url: '/admin/project-audit-ledger/projects', label: 'Project List' },
    { url: '/admin/project-audit-ledger/projects/create', label: 'Project Form' },
    { url: '/admin/project-audit-ledger/clients', label: 'Client List' },
    { url: '/admin/project-audit-ledger/clients/create', label: 'Client Form' },
    { url: '/admin/project-audit-ledger/suppliers', label: 'Supplier List' },
    { url: '/admin/project-audit-ledger/suppliers/create', label: 'Supplier Form' },
    { url: '/admin/project-audit-ledger/team-lead', label: 'Team Lead Dashboard' },
    { url: '/admin/project-audit-ledger/team-lead/fabrication', label: 'Team Lead Fabrication' },
    { url: '/admin/project-audit-ledger/team-lead/cash-advances', label: 'Team Lead Cash Advances' },
    { url: '/admin/project-audit-ledger/team-lead/cash-advances/create', label: 'Team Lead CA Form' },
    { url: '/admin/project-audit-ledger/team-lead/mobilization', label: 'Team Lead Mobilization' },
    { url: '/admin/project-audit-ledger/team-lead/mobilization/create', label: 'Team Lead Mob Form' },
    { url: '/admin/project-audit-ledger/team-lead/attendance', label: 'Team Lead Attendance' },
    { url: '/admin/project-audit-ledger/expenses', label: 'Expense List' },
    { url: '/admin/project-audit-ledger/expenses/create', label: 'Expense Form' },
    { url: '/admin/project-audit-ledger/purchases', label: 'Purchase List' },
    { url: '/admin/project-audit-ledger/purchases/create', label: 'Purchase Form' },
    { url: '/admin/project-audit-ledger/inventory', label: 'Inventory List' },
    { url: '/admin/project-audit-ledger/inventory/movements', label: 'Stock Movements' },
    { url: '/admin/project-audit-ledger/material-issuance', label: 'Issuance List (MI)' },
    { url: '/admin/project-audit-ledger/material-issuance/create', label: 'Issuance Form (MI)' },
    { url: '/admin/project-audit-ledger/issuances', label: 'Issuance List' },
    { url: '/admin/project-audit-ledger/issuances/create', label: 'Issuance Form' },
    { url: '/admin/project-audit-ledger/issuances/returns', label: 'Material Returns' },
    { url: '/admin/project-audit-ledger/material-returns', label: 'Material Returns (MR)' },
    { url: '/admin/project-audit-ledger/material-returns/create', label: 'Material Return Form' },
    { url: '/admin/project-audit-ledger/fabrication', label: 'Fabrication' },
    { url: '/admin/project-audit-ledger/fabrication/allocations', label: 'Fabrication Allocations' },
    { url: '/admin/project-audit-ledger/fabrication/payments', label: 'Fabrication Payments' },
    { url: '/admin/project-audit-ledger/fabrication/payments/create', label: 'Fab Payment Form' },
    { url: '/admin/project-audit-ledger/sales', label: 'Sales List' },
    { url: '/admin/project-audit-ledger/sales/create', label: 'Sales Form' },
    { url: '/admin/project-audit-ledger/collections', label: 'Collection List' },
    { url: '/admin/project-audit-ledger/collections/create', label: 'Collection Form' },
    { url: '/admin/project-audit-ledger/cash-advances', label: 'Cash Advance List' },
    { url: '/admin/project-audit-ledger/cash-advances/create', label: 'Cash Advance Form' },
    { url: '/admin/project-audit-ledger/approvals', label: 'Approvals' },
    { url: '/admin/project-audit-ledger/quotations', label: 'Quotation List' },
    { url: '/admin/project-audit-ledger/quotations/create', label: 'Quotation Form' },
    { url: '/admin/project-audit-ledger/mobilization', label: 'Mobilization List' },
    { url: '/admin/project-audit-ledger/reports', label: 'Reports Center' },
    { url: '/admin/project-audit-ledger/bom', label: 'BOM' },
    { url: '/admin/project-audit-ledger/audit', label: 'Audit Trail' },
    { url: '/admin/project-audit-ledger/audit-trail', label: 'Audit Trail (2)' },
    { url: '/admin/project-audit-ledger/settings', label: 'Settings' },
    { url: '/admin/project-audit-ledger/users', label: 'Users' },
];

async function login(page) {
    await page.goto(APP_URL + '/project-audit-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL('**/admin/project-audit-ledger', { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

test.describe('PAL admin page loop', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    for (const p of PAGES) {
        test(`renders ${p.label} (${p.url})`, async ({ page }) => {
            test.setTimeout(30000);
            const consoleErrors = [];
            const pageErrors = [];
            let mainResponse = null;

            page.on('console', (msg) => {
                if (msg.type() === 'error') consoleErrors.push(msg.text());
            });
            page.on('pageerror', (err) => pageErrors.push(String(err)));

            const resp = await page.goto(APP_URL + p.url, { waitUntil: 'domcontentloaded' });
            mainResponse = resp;
            await page.waitForLoadState('networkidle').catch(() => { });

            const status = mainResponse ? mainResponse.status() : 0;

            // Collect body-level error markers (server-side render failures)
            const bodyText = await page.locator('body').innerText().catch(() => '');
            const errorMarkers = [];
            if (/render_failure|Render failed|Fatal error|Uncaught TypeError|SQLSTATE/i.test(bodyText)) {
                errorMarkers.push('server error marker in body');
            }

            console.log(`[${status}] ${p.url} :: consoleErrors=${consoleErrors.length} pageErrors=${pageErrors.length}`);
            consoleErrors.slice(0, 5).forEach((e) => console.log('   console.error: ' + e));
            pageErrors.slice(0, 5).forEach((e) => console.log('   pageerror: ' + e));
            errorMarkers.slice(0, 5).forEach((e) => console.log('   marker: ' + e));

            // Redirect to login = session lost / auth failure
            const finalUrl = page.url();
            if (finalUrl.includes('/project-audit-ledger/login')) {
                console.log('   ⚠ redirected to login (auth/tenant issue)');
            }

            expect(status, `${p.url} returned ${status}`).toBeLessThan(500);
            expect(pageErrors, `${p.url} page errors`).toEqual([]);
        });
    }
});
