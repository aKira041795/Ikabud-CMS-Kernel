// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'pal1234';
const PRINTER_USER = process.env.PRINTER_USER || 'printer';
const PRINTER_PASS = process.env.PRINTER_PASS || 'printer1234';
const JOB_NO = 'PW-PRINT-' + Date.now().toString().slice(-8);
const CLIENT = 'Playwright Printer Client';
const MATERIAL = 'Playwright Sticker Vinyl';
const NOTE = 'Ready for pickup after trim';

async function login(page, username, password, expectedUrl) {
    await page.goto(APP_URL + '/project-audit-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(expectedUrl, { timeout: 20000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function logout(page) {
    await page.goto(APP_URL + '/project-audit-ledger/logout', { waitUntil: 'domcontentloaded' });
}

async function csrfToken(page) {
    const locator = page.locator('input[name="_token"]').first();
    return locator.inputValue().catch(() => '');
}

async function apiPost(page, url, data) {
    const token = await csrfToken(page);
    const body = new URLSearchParams(data || {});
    if (token) body.set('_token', token);
    return page.evaluate(async ({ url, body }) => {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) { json = { raw: text }; }
        return { status: res.status, json };
    }, { url, body: body.toString() });
}

async function ensurePrinterUser(page) {
    await page.goto(APP_URL + '/admin/project-audit-ledger/users', { waitUntil: 'domcontentloaded' });
    if ((await page.locator('body').textContent())?.includes(PRINTER_USER)) {
        return;
    }
    const result = await apiPost(page, '/api/v1/project-audit-ledger/users', {
        username: PRINTER_USER,
        email: 'printer@example.test',
        full_name: 'Printer User',
        password: PRINTER_PASS,
        role: 'printer',
    });
    expect(result.status, JSON.stringify(result.json)).toBeLessThan(400);
    expect(result.json && result.json.ok, JSON.stringify(result.json)).toBeTruthy();
}

async function seedPendingPrintJob(page) {
    await page.goto(APP_URL + '/admin/project-audit-ledger/printing/jobs', { waitUntil: 'domcontentloaded' });
    const result = await apiPost(page, '/api/v1/project-audit-ledger/printing/jobs', {
        job_number: JOB_NO,
        client_name: CLIENT,
        material_label: MATERIAL,
        width: '24',
        height: '36',
        size_unit: 'in',
        quantity: '2',
        cost: '480.00',
    });
    expect(result.status, JSON.stringify(result.json)).toBeLessThan(400);
    expect(result.json && result.json.ok, JSON.stringify(result.json)).toBeTruthy();
}

test('PAL printer role journey', async ({ page }) => {
    test.setTimeout(180000);

    await login(page, ADMIN_USER, ADMIN_PASS, '**/admin/project-audit-ledger');
    await ensurePrinterUser(page);
    await seedPendingPrintJob(page);
    await logout(page);

    await login(page, PRINTER_USER, PRINTER_PASS, '**/admin/project-audit-ledger/printing');
    await expect(page).toHaveURL(/\/admin\/project-audit-ledger\/printing$/);
    const printerRow = page.locator('tr', { hasText: JOB_NO });
    await expect(printerRow).toBeVisible();
    await expect(printerRow).toContainText(CLIENT);
    await expect(printerRow).toContainText('24 x 36 in');
    await printerRow.locator('select[name="comment_option_key"]').selectOption('done');
    await printerRow.locator('textarea[name="comment_text"]').fill(NOTE);
    await printerRow.getByRole('button', { name: 'Mark Done' }).click();
    await expect(printerRow).toHaveCount(0);
    await logout(page);

    await login(page, ADMIN_USER, ADMIN_PASS, '**/admin/project-audit-ledger');
    await page.goto(APP_URL + '/admin/project-audit-ledger/printing/jobs', { waitUntil: 'domcontentloaded' });
    const adminRow = page.locator('tr', { hasText: JOB_NO });
    await expect(adminRow).toBeVisible();
    await expect(adminRow).toContainText('done');
    await expect(adminRow).toContainText(CLIENT);
    await expect(adminRow).toContainText('24 x 36 in');
    await expect(adminRow).toContainText('₱480.00');
    await expect(adminRow).toContainText('Done');
    await expect(adminRow).toContainText(NOTE);
    await expect(adminRow).toContainText('Printer User');
    await expect(adminRow).toContainText(/\d{4}-\d{2}-\d{2}/);
});
