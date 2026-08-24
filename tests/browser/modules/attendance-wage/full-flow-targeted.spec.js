// @ts-nocheck
const { test, expect } = require('@playwright/test');
const path = require('path');

const APP_URL = process.env.APP_URL || 'http://zapattendance.test';
const ADMIN_STATE = path.resolve('test_results/browser/.auth/attendance-wage-admin.json');
const SUPERVISOR_STATE = path.resolve('test_results/browser/.auth/attendance-wage-supervisor.json');
const ERROR_MARKERS = /Undefined variable|render_failure|Render failed|Fatal error|SQLSTATE|Warning:|Notice:/i;

test.use({ storageState: ADMIN_STATE });

function observe(page) {
    const faults = [];
    page.on('pageerror', e => faults.push('pageerror: ' + e.message));
    page.on('console', m => {
        if (m.type() === 'error' && !/cdn\.tailwindcss\.com/i.test(m.text())) faults.push('console: ' + m.text());
    });
    page.on('response', r => { if (r.status() >= 500) faults.push('HTTP ' + r.status() + ' ' + r.url()); });
    return faults;
}

async function expectCleanPage(page, route, heading) {
    const faults = observe(page);
    const response = await page.goto(APP_URL + route, { waitUntil: 'domcontentloaded' });
    expect(response && response.status()).toBeLessThan(500);
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
    await expect(page.locator('body')).not.toContainText(ERROR_MARKERS);
    expect(faults).toEqual([]);
}

test.describe('attendance-wage full-flow targeted', () => {
    test('PW-1 records render contract covers all-employees and unknown-employee paths', async ({ page }) => {
        await expectCleanPage(page, '/admin/attendance', /Attendance Records/i);
        await expect(page.getByText(/No active employees found/i)).toBeVisible();
        await expectCleanPage(page, '/admin/attendance?employee_id=999999999', /Attendance Records/i);
        await expect(page.getByText(/No active employees found/i)).toBeVisible();
    });

    test('PW-1 seeded benefit bands assert independent boundary constants', async ({ request }) => {
        const low = await request.post(APP_URL + '/api/v1/wage/benefits/calculate', { data: { salary: 1000 } });
        expect(low.status()).toBe(200);
        const lowBody = await low.json();
        expect(lowBody.ok).toBe(true);
        expect(lowBody.data.sss).toEqual({ employee: 45, employer: 95 });
        expect(lowBody.data.philhealth).toEqual({ employee: 250, employer: 250 });
        expect(lowBody.data.pagibig).toEqual({ employee: 50, employer: 50 });
        expect(lowBody.data.total_employee).toBe(345);
        expect(lowBody.data.total_employer).toBe(395);

        const boundary = await request.post(APP_URL + '/api/v1/wage/benefits/calculate', { data: { salary: 20000 } });
        expect(boundary.status()).toBe(200);
        const body = await boundary.json();
        expect(body.data.sss).toEqual({ employee: 1000, employer: 2000 });
        expect(body.data.philhealth).toEqual({ employee: 500, employer: 500 });
        expect(body.data.pagibig).toEqual({ employee: 100, employer: 100 });
        expect(body.data.total_employee).toBe(1600);
        expect(body.data.total_employer).toBe(2600);
    });

    test('PW-1 unauthenticated page/API guards and kiosk exception', async ({ browser }) => {
        const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await anonymous.newPage();
        const kiosk = await page.goto(APP_URL + '/attendance-wage/kiosk');
        expect(kiosk.status()).toBe(200);
        await expect(page.getByRole('heading', { name: /Welcome/i })).toBeVisible();
        await page.goto(APP_URL + '/admin/wage/settings');
        await expect(page).toHaveURL(/\/attendance-wage\/login/);
        const api = await anonymous.request.post(APP_URL + '/api/v1/wage/settings/data-reset', { form: { mode: 'full', keep_users: '1' } });
        expect(api.status()).toBe(401);
        await anonymous.close();
    });

    test('PW-1 least-role cannot reset, backup, compute, approve, or pay', async ({ browser }) => {
        const least = await browser.newContext({ storageState: SUPERVISOR_STATE });
        const cases = [
            ['/api/v1/wage/settings/data-reset', { mode: 'full', keep_users: '1' }],
            ['/api/v1/wage/settings/backup', {}],
            ['/api/v1/wage/compute', { employee_id: '999999', period_id: '999999' }],
            ['/api/v1/wage/computations/999999/approve', {}],
            ['/api/v1/wage/computations/999999/pay', {}],
        ];
        for (const [route, form] of cases) {
            const response = await least.request.post(APP_URL + route, { form });
            expect(response.status(), route).toBe(403);
        }
        await least.close();
    });
});
