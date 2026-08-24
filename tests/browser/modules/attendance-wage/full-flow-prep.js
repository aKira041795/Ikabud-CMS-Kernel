#!/usr/bin/env node
'use strict';

const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const APP_URL = (process.env.APP_URL || '').replace(/\/$/, '');
const tenantId = process.env.AW_E2E_TENANT_ID;
const allowReset = process.env.AW_E2E_ALLOW_RESET;
const username = process.env.ADMIN_USER;
const password = process.env.ADMIN_PASS;
const authDir = path.resolve('test-results/.auth');
const adminState = path.join(authDir, 'attendance-wage-admin.json');
const leastState = path.join(authDir, 'attendance-wage-supervisor.json');
// Playwright clears its default test-results output directory at startup.
// Keep runtime mirrors in the separately gitignored reporter artifact tree.
const persistentAuthDir = path.resolve('test_results/browser/.auth');
const persistentAdminState = path.join(persistentAuthDir, 'attendance-wage-admin.json');
const persistentLeastState = path.join(persistentAuthDir, 'attendance-wage-supervisor.json');
const evidenceDir = path.resolve('test_results/browser/attendance-wage-full-flow');

function fail(message, details) {
    if (details) console.error(JSON.stringify(details, null, 2));
    throw new Error(message);
}
function assertGuard() {
    if (tenantId !== '441' || allowReset !== '1') fail('Refusing destructive prep: AW_E2E_TENANT_ID=441 and AW_E2E_ALLOW_RESET=1 are required.');
    if (APP_URL !== 'http://zapattendance.test') fail('Refusing destructive prep: APP_URL must be http://zapattendance.test.');
    if (!username || !password) fail('ADMIN_USER and ADMIN_PASS are required; credentials are environment-only.');
}
async function loginOnce(page, user, pass, label) {
    const get = await page.goto(APP_URL + '/attendance-wage/login', { waitUntil: 'domcontentloaded' });
    if (get && get.status() === 429) fail(label + ' login page rate limited', { status: 429, retryAfter: get.headers()['retry-after'] || null });
    // Legacy auth template labels are not associated with their controls.
    await page.locator('input[name="username"]').fill(user);
    await page.locator('input[name="password"]').fill(pass);
    const responsePromise = page.waitForResponse(r => r.url().includes('/attendance-wage/auth/login') && r.request().method() === 'POST');
    await page.getByRole('button', { name: /Sign In/i }).click();
    const response = await responsePromise;
    if (response.status() === 429) fail(label + ' login rate limited', { status: 429, retryAfter: response.headers()['retry-after'] || null });
    await page.waitForLoadState('domcontentloaded');
    if (page.url().includes('too_many_attempts')) fail(label + ' login rate limited', { status: response.status(), retryAfter: response.headers()['retry-after'] || null, url: page.url() });
    if (!page.url().includes('/admin/wage')) fail(label + ' login failed; no retry attempted', { status: response.status(), url: page.url() });
}
async function postForm(context, route, form) {
    const response = await context.request.post(APP_URL + route, { form, headers: { Accept: 'application/json' } });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch (_) { fail('Expected JSON from ' + route, { status: response.status(), body: text.slice(0, 500) }); }
    if (!response.ok() || !body.ok) fail('Request failed: ' + route, { status: response.status(), body });
    return body;
}
function rowsFrom(body) {
    if (Array.isArray(body)) return body;
    for (const key of ['data', 'records', 'employees', 'periods', 'computations', 'adjustments', 'deductions', 'cash_advances', 'holidays', 'locations', 'groups']) {
        if (Array.isArray(body && body[key])) return body[key];
    }
    return null;
}

(async () => {
    assertGuard();
    fs.mkdirSync(authDir, { recursive: true, mode: 0o700 });
    fs.mkdirSync(persistentAuthDir, { recursive: true, mode: 0o700 });
    fs.mkdirSync(evidenceDir, { recursive: true });
    // Apply module-local schema before reset/fixture use; fail closed on error.
    execFileSync('php', ['ikabud', 'tenant:migrate', '441', 'attendance-wage'], {
        cwd: process.cwd(), stdio: ['ignore', 'pipe', 'pipe'], env: process.env
    });
    const browser = await chromium.launch();
    try {
        const admin = await browser.newContext();
        const page = await admin.newPage();
        await loginOnce(page, username, password, 'admin');
        await admin.storageState({ path: adminState });
        await admin.storageState({ path: persistentAdminState });

        const backup = await postForm(admin, '/api/v1/wage/settings/backup', {});
        const fileName = backup.backup && backup.backup.file_name;
        if (!fileName) fail('Backup response omitted file_name', backup);
        const download = await admin.request.get(APP_URL + '/api/v1/wage/settings/backup/download?file=' + encodeURIComponent(fileName));
        if (!download.ok()) fail('Backup download failed', { status: download.status(), fileName });
        const backupPath = path.join(evidenceDir, path.basename(fileName));
        fs.writeFileSync(backupPath, await download.body(), { mode: 0o600 });
        if (fs.statSync(backupPath).size === 0) fail('Downloaded backup is empty', { backupPath });

        await postForm(admin, '/api/v1/wage/settings/data-reset', { mode: 'full', keep_users: '1' });
        const emptyRoutes = [
            '/api/v1/wage/employees', '/api/v1/wage/periods', '/api/v1/wage/computations',
            '/api/v1/wage/adjustments', '/api/v1/wage/deductions', '/api/v1/wage/cash-advances',
            '/api/v1/wage/holidays', '/api/v1/wage/locations'
        ];
        for (const route of emptyRoutes) {
            const response = await admin.request.get(APP_URL + route, { headers: { Accept: 'application/json' } });
            if (!response.ok()) fail('Post-reset verification request failed', { route, status: response.status() });
            const body = await response.json();
            const rows = rowsFrom(body);
            if (rows === null || rows.length !== 0) fail('Post-reset target group is not empty', { route, body });
        }

        const leastPassword = crypto.randomBytes(24).toString('base64url');
        const fixtureOutput = execFileSync('php', ['tests/browser/modules/attendance-wage/fixtures/full-flow-seed.php'], {
            cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env, AW_E2E_LEAST_PASSWORD: leastPassword }
        }).trim();
        const fixture = JSON.parse(fixtureOutput);
        if (!fixture.ok || fixture.tenant_id !== 441 || fixture.contribution_bands !== 5) fail('Fixture verification failed', fixture);

        const least = await browser.newContext();
        const leastPage = await least.newPage();
        await loginOnce(leastPage, 'AW-E2E-supervisor', leastPassword, 'least-privilege');
        await least.storageState({ path: leastState });
        await least.storageState({ path: persistentLeastState });
        await least.close();
        await admin.close();

        const evidence = {
            prepared_at: new Date().toISOString(), tenant_id: 441,
            backup_path: backupPath, backup_bytes: fs.statSync(backupPath).size,
            admin_storage_state: adminState, supervisor_storage_state: leastState,
            password_logins: 2, otp_sends: 0, fixture
        };
        fs.writeFileSync(path.join(evidenceDir, 'prep.json'), JSON.stringify(evidence, null, 2));
        console.log(JSON.stringify(evidence, null, 2));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.stack || error.message); process.exit(1); });
