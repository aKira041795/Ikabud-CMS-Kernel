// @ts-check
/**
 * A closed day, reopened by an admin, must then be editable by the admin AND updateable by the
 * production user assigned to that branch — the cashier ledger's reopen-to-correct behaviour.
 *
 * Reproduces the owner's report end to end and asserts the OUTCOME in the database-backed UI:
 *   1. admin sees a closed day and a Reopen control
 *   2. after the reopen the day reads open
 *   3. the ADMIN can edit it
 *   4. the PRODUCTION user assigned to that branch can update it (cells enabled, write persists)
 *   5. it STAYS open afterwards (nothing silently re-closes or re-finalises it)
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(300000);

const EVIDENCE = '/tmp/reopen-edit-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const PRODUCER = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };
const COMMISSARY = 18;
const DATE = '2026-10-03'; // a closed day with its PM shift already finalized

async function login(page, who) {
    // The app keeps a session cookie, so a second login in the same context would land on the
    // dashboard and never render a form. Start every login from a clean session.
    await page.context().clearCookies();
    await page.goto('/daily-ledger/login');
    await page.waitForSelector('input[name="username"]', { timeout: 30000 });
    await page.fill('input[name="username"]', who.username);
    await page.fill('input[name="full_name"]', who.fullName);
    await page.fill('input[name="password"]', who.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

const sheetUrl = (date, shift) =>
    `/daily-ledger/admin/commissary?date=${date}&commissary_id=${COMMISSARY}&shift=${shift}`;

async function openSheet(page, date, shift) {
    await page.goto(sheetUrl(date, shift), { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
    await page.waitForSelector('#tab-daily-sheet table tbody tr.daily-sheet-product-row', { timeout: 60000 });
}

const status = (page) => page.locator('#production-day-status').innerText();
const pidOnSheet = (page) => page.evaluate(() =>
    Number(document.querySelector('#tab-daily-sheet tbody tr.daily-sheet-product-row').getAttribute('data-product-id')));

/** Is the ACTUAL cell the production user would type into actually enabled? */
const actualEnabled = (page, pid) => page.evaluate((p) => {
    const el = document.querySelector('#production-actual-' + p);
    return el ? !el.disabled : null;
}, pid);

async function saveAndConfirm(page, pid, value, label) {
    const sel = `#production-actual-${pid}`;
    await page.waitForSelector(sel, { timeout: 30000 });
    await page.fill(sel, String(value));
    // Capture the WRITE itself, not just the DOM: a cell can be enabled while the server still
    // refuses the save, and only the response distinguishes the two.
    const resp = page.waitForResponse((r) => r.url().includes('/api/v1/commissary/material'), { timeout: 60000 });
    await page.dispatchEvent(sel, 'change');
    const res = await resp;
    const body = await res.json().catch(() => null);
    note({ step: 'save', label, productId: pid, value, status: res.status(), body });
    await page.waitForFunction(
        ([s, v]) => { const el = document.querySelector(s); return el && el.getAttribute('data-original') === String(v); },
        [sel, value],
        { timeout: 30000 },
    );
    return { status: res.status(), body };
}

test('admin reopens a closed day; admin edits it and the branch production user updates it', async ({ page }) => {
    fs.writeFileSync(EVIDENCE, '');

    // ---- 1. admin: establish the precondition -- the day is CLOSED --------------------
    await login(page, ADMIN);
    await openSheet(page, DATE, 'PM');
    let before = (await status(page)).trim();
    if (before !== 'closed') {
        // A previous run may have left it open (a deliberate reopen STAYS open, by design), so
        // close it here. A fully-manual day refuses to close until its PM shift is finalised
        // (422 PM_ENDING_PENDING), and apiReopenDay reopens BOTH shifts -- so PM may need doing
        // again first. That is the real re-close workflow, not test scaffolding.
        note({ step: 'admin-close-to-set-up', date: DATE, was: before });
        const pmBtn = await page.$('#finalize-production-pm');
        if (pmBtn) {
            const fin = page.waitForResponse((r) => r.url().includes('finalize-pm'), { timeout: 60000 });
            await page.click('#finalize-production-pm');
            const finRes = await fin;
            note({ step: 'admin-finalise-pm', status: finRes.status(), body: await finRes.json().catch(() => null) });
        }
        await page.waitForSelector('#production-close-day', { timeout: 30000 });
        const closing = page.waitForResponse((r) => r.url().includes('close-day'), { timeout: 60000 });
        await page.click('#production-close-day');
        const closedRes = await closing;
        note({ step: 'admin-close-result', status: closedRes.status(), body: await closedRes.json().catch(() => null) });
        await openSheet(page, DATE, 'PM');
        before = (await status(page)).trim();
    }
    const reopenOffered = await page.locator('#production-reopen-day').count();
    note({ step: 'admin-before', date: DATE, dayStatus: before, reopenOffered });
    expect(before, `${DATE} must be closed before it can be reopened`).toBe('closed');
    expect(reopenOffered, 'a closed day must offer Reopen to an admin').toBe(1);

    // ---- 2. reopen, as the cashier ledger allows ---------------------------------------
    await page.click('#production-reopen-day');
    await page.waitForFunction(() => {
        const el = document.querySelector('#production-day-status');
        return el && el.textContent.trim() === 'open';
    }, null, { timeout: 30000 });
    const pid = await pidOnSheet(page);
    note({ step: 'admin-reopened', date: DATE, dayStatus: (await status(page)).trim(), productId: pid });
    expect((await status(page)).trim()).toBe('open');

    // ---- 3. the ADMIN can edit it ------------------------------------------------------
    const adminEnabled = await actualEnabled(page, pid);
    expect(adminEnabled, 'admin must be able to edit after reopening').toBe(true);
    await saveAndConfirm(page, pid, 41, 'admin');
    note({ step: 'admin-edit', productId: pid, value: 41, enabled: adminEnabled });

    // ---- 4. the PRODUCTION user assigned to the branch can update it -------------------
    await login(page, PRODUCER); // login() clears the session, so this is a real switch
    await openSheet(page, DATE, 'PM');
    const producerStatus = (await status(page)).trim();
    const producerEnabled = await actualEnabled(page, pid);
    note({ step: 'producer-view', date: DATE, dayStatus: producerStatus, productId: pid, actualEnabled: producerEnabled });
    expect(producerStatus, 'the reopened day must still read open for the production user').toBe('open');
    expect(producerEnabled, 'the production user assigned to the branch must be able to update the reopened day').toBe(true);
    await saveAndConfirm(page, pid, 42, 'producer');

    // ---- 5. it must STAY open and keep the update --------------------------------------
    await openSheet(page, DATE, 'PM');
    const afterReload = (await status(page)).trim();
    const persisted = await page.evaluate((p) => {
        const el = document.querySelector('#production-actual-' + p);
        return el ? el.value : null;
    }, pid);
    note({ step: 'after-producer-edit', dayStatus: afterReload, persistedActually: persisted });
    expect(afterReload, 'the day must NOT be silently re-closed after the update').toBe('open');
    expect(Number(persisted), 'the production user\u2019s update must persist').toBe(42);

    console.log('REOPEN_EDIT ' + JSON.stringify({ productId: pid, dayStatusBefore: before, dayStatusAfter: afterReload, persisted }));
});
