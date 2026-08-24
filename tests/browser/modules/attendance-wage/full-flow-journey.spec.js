// @ts-nocheck
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const APP_URL = process.env.APP_URL || 'http://zapattendance.test';
const ADMIN_STATE = path.resolve('test_results/browser/.auth/attendance-wage-admin.json');
const EVIDENCE_DIR = path.resolve('test_results/browser/attendance-wage-full-flow');
const FIXED = { start: '2026-06-01', end: '2026-06-15', pay: '2026-06-20', holiday: '2026-06-12' };

test.use({
    storageState: ADMIN_STATE,
    permissions: ['geolocation'],
    geolocation: { latitude: 14.5995, longitude: 120.9842 },
});
test.describe.configure({ mode: 'serial' });

async function expectOk(response, label) {
    expect(response.status(), label).toBeLessThan(400);
    const body = await response.json();
    expect(body.ok, label + ': ' + JSON.stringify(body)).toBe(true);
    return body;
}
async function submitForm(page, buttonName, responsePart) {
    const responsePromise = page.waitForResponse(r => r.url().includes(responsePart) && r.request().method() === 'POST');
    await page.getByRole('button', { name: buttonName }).click();
    const response = await responsePromise;
    expect(response.status()).toBeLessThan(400);
    return response;
}
function cents(value) { return Math.round(Number(value) * 100); }
function expectMoney(actual, expectedCents, label) { expect(cents(actual), label).toBe(expectedCents); }
function financialEvidence(periodId, advanceId = 0) {
    return JSON.parse(execFileSync('php', ['tests/browser/modules/attendance-wage/fixtures/read-financial-evidence.php'], {
        cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
        env: { ...process.env, AW_E2E_TENANT_ID: '441', AW_E2E_ALLOW_RESET: '1', APP_URL,
            AW_E2E_PERIOD_ID: String(periodId), AW_E2E_ADVANCE_ID: String(advanceId) }
    }));
}
async function postJson(request, route, data, label) {
    return expectOk(await request.post(APP_URL + route, { data }), label);
}
async function createEmployee(page, index, salaryType, basicSalary, hourlyRate) {
    await page.goto(APP_URL + '/admin/wage/employees/create');
    const key = String(index).padStart(3, '0');
    await page.getByLabel(/Last Name/i).fill('Flow' + key);
    await page.getByLabel(/First Name/i).fill('AW-E2E-' + salaryType);
    await page.getByLabel(/Middle Name/i).fill('QA');
    await page.getByLabel(/Employee Number/i).fill('AW-E2E-' + salaryType.toUpperCase() + '-' + key);
    await page.getByLabel(/Hire Date/i).fill('2020-01-15');
    await page.getByLabel('Status', { exact: true }).selectOption('regular');
    await page.getByLabel(/Position/i).fill('QA ' + salaryType);
    await page.getByLabel(/Department/i).fill('AW-E2E Payroll');
    await page.getByLabel(/Salary Type/i).selectOption(salaryType);
    await page.getByLabel(/Basic Salary/i).fill(String(basicSalary));
    await page.getByLabel(/Hourly Rate/i).fill(String(hourlyRate));
    await page.getByLabel(/SSS Number/i).fill('33-' + key + '000-1');
    await page.getByLabel(/PhilHealth Number/i).fill('22-' + key + '000-2');
    await page.getByLabel(/Pag-IBIG Number/i).fill('1111-' + key + '-0000');
    await page.getByLabel(/TIN Number/i).fill('999-' + key + '-000');
    await submitForm(page, /Save Employee/i, '/api/v1/wage/employees');
    await expect(page).toHaveURL(/\/admin\/wage\/employees/);
    await expect(page.getByText('AW-E2E-' + salaryType, { exact: false }).first()).toBeVisible();
}

test('PW-2 one ordered Attendance & Wage journey', async ({ page, request, browser }) => {
    test.setTimeout(360000);
    page.setDefaultTimeout(10000);
    const faults = [];
    page.on('pageerror', e => faults.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/cdn\.tailwindcss\.com/i.test(m.text())) faults.push('console: ' + m.text()); });
    page.on('response', r => { if (r.status() >= 500) faults.push('HTTP ' + r.status() + ' ' + r.url()); });
    let employees = [];
    let periodId = 0;
    let advanceId = 0;
    let paidLedger = null;

    await test.step('1. baseline/configuration', async () => {
        const settings = await request.post(APP_URL + '/api/v1/wage/settings', { form: {
            timezone: 'Asia/Manila', working_days_per_month: '22', working_hours_per_day: '8',
            overtime_calculation: 'both', round_hours_to: '0.25', pay_frequency: 'semi_monthly',
            default_rest_day: 'sunday', rest_day_rate: '1.30', night_diff_rate: '0.10',
            max_cash_advance_pct: '50', max_active_advances: '2'
        }});
        await expectOk(settings, 'save settings');
        await page.goto(APP_URL + '/admin/wage/settings');
        await expect(page.getByRole('heading', { name: /Settings/i })).toBeVisible();

        await page.goto(APP_URL + '/admin/wage/locations/create');
        await page.locator('input[name="name"]').fill('AW-E2E Manila Office');
        await page.locator('input[name="address"]').fill('Manila, Philippines');
        await page.locator('input[name="latitude"]').fill('14.5995');
        await page.locator('input[name="longitude"]').fill('120.9842');
        await page.locator('input[name="radius_meters"]').fill('250');
        await submitForm(page, /Create Location/i, '/api/v1/wage/locations');
        await expect(page.getByText('AW-E2E Manila Office')).toBeVisible();

        await page.goto(APP_URL + '/admin/wage/holidays');
        await page.locator('input[name="holiday_name"]').fill('AW-E2E Independence Day');
        await page.locator('input[name="holiday_date"]').fill(FIXED.holiday);
        await page.locator('select[name="holiday_type"]').selectOption('regular');
        await submitForm(page, /Add Holiday/i, '/api/v1/wage/holidays');
        await expect(page.getByText('AW-E2E Independence Day')).toBeVisible();
    });

    await test.step('2. employees CRUD and four salary types', async () => {
        await createEmployee(page, 1, 'hourly', 0, 100);
        await createEmployee(page, 2, 'daily', 800, 100);
        await createEmployee(page, 3, 'monthly', 22000, 125);
        await createEmployee(page, 4, 'fixed', 15000, 0);
        const list = await expectOk(await request.get(APP_URL + '/api/v1/wage/employees'), 'employee list');
        employees = list.data.filter(e => String(e.employee_number).startsWith('AW-E2E-'));
        expect(employees).toHaveLength(4);
        expect(new Set(employees.map(e => e.salary_type))).toEqual(new Set(['hourly', 'daily', 'monthly', 'fixed']));
        for (const employee of employees) {
            const detail = await expectOk(await request.get(APP_URL + '/api/v1/wage/employees/' + employee.id), 'employee identity readback');
            employee.user_id = Number(detail.data.user_id);
            expect(employee.user_id).toBeGreaterThan(0);
        }

        const hourly = employees.find(e => e.salary_type === 'hourly');
        await page.goto(APP_URL + '/admin/wage/employees/' + hourly.id);
        await page.getByLabel(/Department/i).fill('AW-E2E Payroll Edited');
        await submitForm(page, /Save Employee/i, '/api/v1/wage/employees/' + hourly.id);
        const readback = await expectOk(await request.get(APP_URL + '/api/v1/wage/employees/' + hourly.id), 'employee readback');
        expect(readback.data.department).toBe('AW-E2E Payroll Edited');
    });

    await test.step('3. kiosk explicit transitions and duplicate rejection', async () => {
        const kiosk = await browser.newContext({ permissions: ['geolocation'], geolocation: { latitude: 14.5995, longitude: 120.9842 } });
        const kioskPage = await kiosk.newPage();
        const response = await kioskPage.goto(APP_URL + '/attendance-wage/kiosk');
        expect(response.status()).toBe(200);
        await expect(kioskPage.getByRole('heading', { name: /Welcome/i })).toBeVisible();
        const search = await kiosk.request.post(APP_URL + '/api/v1/kiosk/search', { form: { q: 'AW-E2E-HOURLY-001' } });
        const searchBody = await search.json();
        const employee = searchBody.data.find(e => e.employee_number === 'AW-E2E-HOURLY-001');
        expect(employee).toBeTruthy();
        const clock = action => kiosk.request.post(APP_URL + '/api/v1/kiosk/clock', { form: {
            profile_id: String(employee.profile_id), latitude: '14.5995', longitude: '120.9842',
            onsite_place: 'AW-E2E Manila Office', action
        }});
        expect((await expectOk(await clock('clock_in'), 'explicit clock in')).action).toBe('clock_in');
        const duplicateIn = await clock('clock_in');
        expect(duplicateIn.status()).toBe(409);
        expect((await duplicateIn.json()).error).toMatch(/already clocked in|duplicate clock-in/i);
        expect((await expectOk(await clock('clock_out'), 'explicit clock out')).action).toBe('clock_out');
        const duplicateOut = await clock('clock_out');
        expect(duplicateOut.status()).toBe(409);
        expect((await duplicateOut.json()).error).toMatch(/not clocked in|duplicate clock-out/i);
        const records = await expectOk(await kiosk.request.get(APP_URL + '/api/v1/kiosk/my-records?profile_id=' + employee.profile_id), 'kiosk records');
        expect(records.records.filter(r => r.status === 'completed')).toHaveLength(1);
        await kiosk.close();
    });

    await test.step('4. team-lead real OTP flow via scoped app storage', async () => {
        const leadEmail = 'aw-e2e-team-lead@example.test';
        await page.goto(APP_URL + '/admin/wage/groups/create');
        await page.locator('input[name="name"]').fill('AW-E2E Payroll Team');
        await page.locator('select[name="leader_profile_id"]').selectOption(String(employees[0].id));
        await page.locator('input[name="pal_team_lead_email"]').fill(leadEmail);
        await page.locator('.member-checkbox').first().check();
        const groupResponse = page.waitForResponse(r => r.url().includes('/api/v1/wage/groups') && r.request().method() === 'POST');
        await page.getByRole('button', { name: /Create Group/i }).click();
        expect((await groupResponse).status()).toBeLessThan(400);

        const teamLead = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const send = await teamLead.request.post(APP_URL + '/api/v1/attendance/team-lead/send-otp', { data: { email: leadEmail } });
        const sent = await expectOk(send, 'single team-lead OTP send');
        const code = execFileSync('php', ['tests/browser/modules/attendance-wage/fixtures/read-team-lead-otp.php'], {
            cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env, AW_E2E_TENANT_ID: '441', AW_E2E_ALLOW_RESET: '1', AW_E2E_TEAM_LEAD_EMAIL: leadEmail, APP_URL }
        }).trim();
        expect(code).toMatch(/^\d{6}$/);
        const verify = await expectOk(await teamLead.request.post(APP_URL + '/api/v1/attendance/team-lead/verify-otp', {
            data: { code, token: sent.token }
        }), 'real OTP verification');
        const dashboard = await teamLead.request.get(verify.redirect);
        expect(dashboard.status()).toBe(200);
        expect(await dashboard.text()).toContain('AW-E2E Payroll Team');
        const reused = await teamLead.request.post(APP_URL + '/api/v1/attendance/team-lead/verify-otp', { data: { code, token: sent.token } });
        expect(reused.status()).toBe(422);
        await teamLead.close();
    });

    await test.step('5. admin attendance insertion, inline edit, rounding, history/report', async () => {
        const hourly = employees.find(e => e.salary_type === 'hourly');
        const records = [
            ['2026-06-02T09:00', '2026-06-02T17:00', 'regular'],
            ['2026-06-03T09:00', '2026-06-03T19:08', 'rounded overtime'],
            ['2026-06-07T09:00', '2026-06-07T17:00', 'rest day'],
            ['2026-06-08T22:00', '2026-06-09T06:00', 'night'],
            ['2026-06-12T09:00', '2026-06-12T17:00', 'holiday'],
        ];
        await page.goto(APP_URL + '/admin/attendance');
        await page.getByTestId('add-attendance-record').click();
        const form = page.locator('#adminAttendanceForm');
        await form.locator('[name="employee_profile_id"]').selectOption(String(hourly.id));
        await form.locator('[name="clock_in"]').fill(records[0][0]);
        await form.locator('[name="clock_out"]').fill(records[0][1]);
        const uiInsert = page.waitForResponse(r => r.url().includes('/api/v1/attendance/admin-records') && r.request().method() === 'POST');
        await form.getByRole('button', { name: /Save Record/i }).click();
        expect((await uiInsert).status()).toBe(200);
        await page.waitForLoadState('domcontentloaded');
        const ids = [];
        for (const [clock_in, clock_out, notes] of records.slice(1)) {
            const created = await postJson(request, '/api/v1/attendance/admin-records', { employee_profile_id: hourly.id, clock_in, clock_out, notes }, 'admin attendance ' + notes);
            ids.push(created.id);
        }
        // Exercise the rendered inline-hours control on the raw 10h08 record.
        await page.goto(APP_URL + '/admin/attendance?employee_id=' + hourly.id);
        const edit = page.getByRole('button', { name: /Click to edit hours/i }).filter({ hasText: /10\.1/ }).first();
        await expect(edit).toBeVisible();
        await edit.click();
        const hoursInput = page.locator('input[aria-label="Edit hours"]:visible').first();
        await hoursInput.fill('10.25');
        const inlineResponse = page.waitForResponse(r => r.url().includes('/api/v1/entity/update') && r.request().method() === 'POST');
        await hoursInput.press('Enter');
        expect((await inlineResponse).status()).toBe(200);
        await expect(page.getByText('10.25', { exact: true })).toBeVisible();
        for (const route of ['/admin/attendance/history', '/admin/attendance/report']) {
            const response = await page.goto(APP_URL + route);
            expect(response.status(), route).toBe(200);
            await expect(page.locator('body')).not.toContainText(/Undefined variable|SQLSTATE|Fatal error/i);
        }
    });

    await test.step('6. create-form bounded payroll period contract', async () => {
        await page.goto(APP_URL + '/admin/wage/periods/create');
        await expect(page.getByRole('heading', { name: /Create Payroll Period/i })).toBeVisible();
        await expect(page.locator('form')).toHaveAttribute('action', '/api/v1/wage/periods');
        await page.getByLabel(/Period Name/i).fill('AW-E2E June 1-15 2026');
        await page.getByLabel(/Frequency/i).selectOption('semi_monthly');
        await page.getByLabel(/Start Date/i).fill(FIXED.start);
        await page.getByLabel(/End Date/i).fill(FIXED.end);
        await page.getByLabel(/Pay Date/i).fill(FIXED.pay);
        await page.getByLabel(/Cut-off Date/i).fill(FIXED.end);
        await submitForm(page, /Create Period/i, '/api/v1/wage/periods');
        await expect(page).toHaveURL(/\/admin\/wage\/periods/);
        const periods = await expectOk(await request.get(APP_URL + '/api/v1/wage/periods'), 'period list');
        const period = periods.data.find(p => p.period_name === 'AW-E2E June 1-15 2026');
        expect(period).toBeTruthy(); periodId = Number(period.id || period.period_id);
        await page.goto(APP_URL + '/admin/wage/periods/' + periodId);
        await expect(page.getByRole('heading', { name: /Edit Payroll Period/i })).toBeVisible();
        await expect(page.locator('form')).toHaveAttribute('action', '/api/v1/wage/periods/' + periodId);
        await expect(page.getByLabel(/Period Name/i)).toHaveValue('AW-E2E June 1-15 2026');
        const fixed = employees.find(e => e.salary_type === 'fixed');
        await postJson(request, '/api/v1/wage/employees/' + fixed.id, { pagibig_applicable: false }, 'disable fixed Pag-IBIG applicability');
        const fixedRead = await expectOk(await request.get(APP_URL + '/api/v1/wage/employees/' + fixed.id), 'fixed applicability readback');
        expect(Number(fixedRead.data.pagibig_applicable)).toBe(0);
    });

    await test.step('7. approved addition/subtraction and processed employee deduction', async () => {
        const hourly = employees.find(e => e.salary_type === 'hourly');
        for (const adjustment of [
            { adjustment_type: 'bonus', amount: 123.45, description: 'AW-E2E ledger addition' },
            { adjustment_type: 'deduction', amount: 23.45, description: 'AW-E2E ledger subtraction' },
        ]) {
            await postJson(request, '/api/v1/wage/adjustments', { user_id: hourly.user_id, payroll_period_id: periodId,
                effective_date: '2026-06-10', category: 'taxable', ...adjustment }, adjustment.description);
        }
        const adjustmentList = await expectOk(await request.get(APP_URL + '/api/v1/wage/adjustments'), 'adjustment list');
        const createdAdjustments = adjustmentList.adjustments.filter(a => String(a.description).startsWith('AW-E2E ledger'));
        expect(createdAdjustments).toHaveLength(2);
        for (const adjustment of createdAdjustments) await postJson(request, '/api/v1/wage/adjustments/' + adjustment.id + '/approve', {}, 'approve adjustment');

        const deduction = await postJson(request, '/api/v1/wage/deductions', { user_id: hourly.user_id, amount: 50,
            description: 'AW-E2E active deduction', deduction_date: '2026-06-10' }, 'create deduction');
        await postJson(request, '/api/v1/wage/deductions/' + deduction.id + '/status', { status: 'processed' }, 'process deduction');
        const evidence = financialEvidence(periodId);
        expect(evidence.adjustments).toHaveLength(2);
        expect(evidence.deductions).toHaveLength(1);
    });

    await test.step('8. cash advance policy, approval, and repayment cardinality', async () => {
        const fixed = employees.find(e => e.salary_type === 'fixed');
        const advance = await postJson(request, '/api/v1/wage/cash-advances', { employee_name: fixed.id, amount: 500,
            repayment_type: 'full_next_payroll', request_date: '2026-06-05', notes: 'AW-E2E payroll advance' }, 'cash advance within policy');
        advanceId = Number(advance.id);
        const overLimit = await request.post(APP_URL + '/api/v1/wage/cash-advances', { data: { employee_name: fixed.id, amount: 8000,
            repayment_type: 'full_next_payroll', request_date: '2026-06-05' } });
        expect(overLimit.status()).toBe(422);
        expect((await overLimit.json()).error).toMatch(/policy limit/i);
        await postJson(request, '/api/v1/wage/cash-advances/' + advanceId + '/approve', {}, 'approve cash advance');
        let evidence = financialEvidence(periodId, advanceId);
        expect(evidence.repayments).toHaveLength(1);
        expect(evidence.repayments[0]).toMatchObject({ status: 'pending' });
        expectMoney(evidence.repayments[0].amount, 50000, 'scheduled repayment');
        const repeated = await request.post(APP_URL + '/api/v1/wage/cash-advances/' + advanceId + '/approve', { data: {} });
        expect((await repeated.json()).ok).toBe(false);
        evidence = financialEvidence(periodId, advanceId);
        expect(evidence.repayments).toHaveLength(1);
    });

    await test.step('9. benefits boundary, in-band, and disabled applicability', async () => {
        await page.goto(APP_URL + '/admin/wage/benefits-calculator');
        await expect(page.getByRole('heading', { name: /Benefits/i })).toBeVisible();
        const low = await postJson(request, '/api/v1/wage/benefits/calculate', { salary: 1000 }, 'benefit low band');
        expectMoney(low.data.total_employee, 34500, 'low-band employee benefits');
        expectMoney(low.data.total_employer, 39500, 'low-band employer benefits');
        const edgeLow = await postJson(request, '/api/v1/wage/benefits/calculate', { salary: 19999.99 }, 'benefit lower boundary');
        expectMoney(edgeLow.data.total_employee, 150000, '19,999.99 boundary');
        const edgeHigh = await postJson(request, '/api/v1/wage/benefits/calculate', { salary: 20000 }, 'benefit upper boundary');
        expectMoney(edgeHigh.data.total_employee, 160000, '20,000 boundary');
        expectMoney(edgeHigh.data.total_employer, 260000, '20,000 employer boundary');
    });

    await test.step('10. independent payroll ledger and exact transition cardinality', async () => {
        const hourly = employees.find(e => e.salary_type === 'hourly');
        await postJson(request, '/api/v1/wage/compute', { user_id: hourly.user_id, period_id: periodId }, 'single hourly compute');
        await postJson(request, '/api/v1/wage/compute/bulk', { period_id: periodId }, 'bulk daily/monthly/fixed compute');
        let evidence = financialEvidence(periodId, advanceId);
        expect(evidence.computations).toHaveLength(4);
        const initialIds = evidence.computations.map(c => c.computation_id);
        // Recompute while mutable: rows update in place and repayment remains one row.
        await postJson(request, '/api/v1/wage/compute', { user_id: hourly.user_id, period_id: periodId }, 'single recompute');
        await postJson(request, '/api/v1/wage/compute/bulk', { period_id: periodId }, 'bulk recompute');
        evidence = financialEvidence(periodId, advanceId);
        expect(evidence.computations.map(c => c.computation_id)).toEqual(initialIds);
        expect(evidence.repayments).toHaveLength(1);
        expect(evidence.repayments[0].status).toBe('deducted');

        const computations = await expectOk(await request.get(APP_URL + '/api/v1/wage/computations?period_id=' + periodId), 'computation ledger');
        expect(computations.data).toHaveLength(4);
        const byType = Object.fromEntries(computations.data.map(c => [c.salary_type, c]));
        const hourlyExpected = { regular_hours:2400, overtime_hours:200, double_overtime_hours:25, holiday_hours:800,
            night_shift_hours:800, rest_day_hours:800, regular_pay:240000, overtime_pay:25000,
            double_overtime_pay:3750, holiday_pay:80000, night_shift_pay:8000, rest_day_pay:80000,
            rest_day_premium:24000, total_additions:32345, salary_deductions:5000, other_deductions:2345,
            sss_employee:20734, philhealth_employee:25000, pagibig_employee:10000,
            gross_pay:493095, total_deductions:63079, income_tax:0, net_pay:430016 };
        for (const [field, expected] of Object.entries(hourlyExpected)) expectMoney(byType.hourly[field], expected, 'hourly ' + field);
        const salaryExpected = {
            daily: { regular_pay:1040000, total_additions:86667, gross_pay:1126667, sss_employee:46800, philhealth_employee:26000, pagibig_employee:10000, income_tax:0, total_deductions:82800, net_pay:1043867 },
            monthly: { regular_pay:2200000, total_additions:183333, gross_pay:2383333, sss_employee:110000, philhealth_employee:55000, pagibig_employee:10000, income_tax:25000, total_deductions:200000, net_pay:2183333 },
            fixed: { regular_pay:1500000, total_additions:125000, gross_pay:1625000, sss_employee:67500, philhealth_employee:37500, pagibig_employee:0, cash_advance_deduction:50000, income_tax:0, total_deductions:155000, net_pay:1470000 },
        };
        for (const [type, ledger] of Object.entries(salaryExpected)) for (const [field, expected] of Object.entries(ledger)) expectMoney(byType[type][field], expected, type + ' ' + field);

        for (const comp of computations.data) await postJson(request, '/api/v1/wage/computations/' + comp.computation_id + '/approve', {}, 'approve ' + comp.salary_type);
        let approved = financialEvidence(periodId, advanceId);
        expect(approved.computations).toHaveLength(4);
        expect(approved.computations.every(c => c.status === 'approved')).toBe(true);
        for (const comp of computations.data) {
            const repeatApprove = await request.post(APP_URL + '/api/v1/wage/computations/' + comp.computation_id + '/approve', { data: {} });
            expect((await repeatApprove.json()).ok).toBe(false);
        }
        expect(financialEvidence(periodId, advanceId).computations).toHaveLength(4);
        for (const comp of computations.data) await postJson(request, '/api/v1/wage/computations/' + comp.computation_id + '/pay', {}, 'pay ' + comp.salary_type);
        paidLedger = financialEvidence(periodId, advanceId);
        expect(paidLedger.computations).toHaveLength(4);
        expect(paidLedger.computations.every(c => c.status === 'paid')).toBe(true);
        expect(paidLedger.repayments).toHaveLength(1);
        expect(paidLedger.repayments[0].status).toBe('paid');
        expect(paidLedger.advances[0]).toMatchObject({ status: 'completed', balance: '0.00', paid_installments: 1 });
        const paidTotals = paidLedger.computations.map(c => [c.computation_id, c.gross_pay, c.total_deductions, c.net_pay]);
        for (const comp of computations.data) {
            const repeatPay = await request.post(APP_URL + '/api/v1/wage/computations/' + comp.computation_id + '/pay', { data: {} });
            expect((await repeatPay.json()).ok).toBe(false);
            const repeatCompute = await request.post(APP_URL + '/api/v1/wage/compute', { data: { user_id: comp.user_id, period_id: periodId } });
            expect((await repeatCompute.json()).ok).toBe(false);
        }
        const batchPay = await postJson(request, '/api/v1/wage/computations/batch/pay', { period_id: periodId }, 'repeat batch pay no-op');
        expect(batchPay.count).toBe(0);
        const finalEvidence = financialEvidence(periodId, advanceId);
        expect(finalEvidence.computations.map(c => [c.computation_id, c.gross_pay, c.total_deductions, c.net_pay])).toEqual(paidTotals);
        expect(finalEvidence.repayments).toHaveLength(1);
        expect(finalEvidence.advances[0]).toMatchObject({ balance: '0.00', paid_installments: 1 });
        fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
        fs.writeFileSync(path.join(EVIDENCE_DIR, 'financial-ledger-cardinality.json'), JSON.stringify({ expected: { hourlyExpected, salaryExpected }, paid: finalEvidence }, null, 2));
    });

    await test.step('11. summary, export, report, and payslip reconcile to paid ledger', async () => {
        await page.goto(APP_URL + '/admin/wage/reports/' + periodId);
        await expect(page.getByRole('heading', { name: '📄 AW-E2E June 1-15 2026' })).toBeVisible();
        const sum = field => paidLedger.computations.reduce((total, row) => total + cents(row[field]), 0);
        const summary = await expectOk(await request.get(APP_URL + '/api/v1/wage/reports/summary?period_id=' + periodId + '&status=paid'), 'paid summary');
        expect(summary.rows).toHaveLength(4);
        expectMoney(summary.totals.gross, sum('gross_pay'), 'summary gross');
        expectMoney(summary.totals.deductions, sum('total_deductions'), 'summary deductions');
        expectMoney(summary.totals.net, sum('net_pay'), 'summary net');

        const exportResponse = await request.get(APP_URL + '/api/v1/wage/reports/' + periodId + '/export?format=csv');
        expect(exportResponse.status()).toBe(200);
        expect(exportResponse.headers()['content-type']).toMatch(/text\/csv/);
        expect(exportResponse.headers()['content-disposition']).toMatch(/payroll_.*\.csv/i);
        const csv = (await exportResponse.body()).toString('utf8');
        for (const heading of ['Gross Pay','SSS','PhilHealth','Pag-IBIG','Manual Deductions','Cash Advance','Other Deductions','Net Pay']) expect(csv).toContain(heading);
        expect(csv).toContain('500.00');
        const hourlyPaid = paidLedger.computations.find(c => cents(c.gross_pay) === 493095);
        const payslip = await expectOk(await request.get(APP_URL + '/api/v1/wage/payslip/' + hourlyPaid.computation_id), 'paid payslip API');
        for (const field of ['gross_pay','total_deductions','net_pay']) expectMoney(payslip.data[field], cents(hourlyPaid[field]), 'payslip ' + field);
        await page.goto(APP_URL + '/admin/wage/payslip/' + hourlyPaid.computation_id);
        await expect(page.locator('body')).toContainText('AW-E2E-hourly');
        await expect(page.locator('body')).toContainText('4,300.16');
        fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
        fs.writeFileSync(path.join(EVIDENCE_DIR, 'payroll-period.csv'), csv);
        fs.writeFileSync(path.join(EVIDENCE_DIR, 'output-reconciliation.json'), JSON.stringify({ summary: summary.totals, payslip: payslip.data,
            export: { headers: exportResponse.headers(), bytes: Buffer.byteLength(csv) } }, null, 2));
    });

    await test.step('12. authorization/negative flow', async () => {
        const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const protectedApi = await anonymous.request.get(APP_URL + '/api/v1/wage/employees');
        expect(protectedApi.status()).toBe(401);
        await anonymous.close();
    });

    expect(periodId).toBeGreaterThan(0);
    expect(faults).toEqual([]);
});
