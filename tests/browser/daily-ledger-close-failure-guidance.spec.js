// @ts-check
/**
 * Production sheet: PM-close FAILURE GUIDANCE at parity with the cashier ledger.
 *
 * Why this exists: the cashier ledger answers a failed PM close with a PERSISTENT panel that
 * names the blocking products (cashier/ledger.disyl:153 + :2129-2160). The production sheet
 * answered with only a transient toast, and the server jammed up to 20 product names into the
 * message string (handlers.php:16896), so a production user who could not close the PM shift
 * had nothing to work from once the toast faded.
 *
 * Two assertions, both against the real endpoint on the real tenant:
 *   1. a failed close returns STRUCTURED missing_products (not names inside a string)
 *   2. the failure lands in a PERSISTENT role="alert" panel that lists those products
 *
 * 2026-09-01 is used deliberately: branch 18 holds ledger rows for 2026-10-01..04 ONLY, so this
 * date starts empty. D3 (contract 2026-10-05) says a day with NO movement is not a gap and is
 * auto-finalized, so the spec seeds ONE product with a PM beginning and no ending: that is a real
 * gap, which is what the failure-guidance path exists for. The seed is idempotent.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-close-failure-guidance.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(240000);

const EVIDENCE = '/tmp/close-guidance-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const COMMISSARY = 18;
const DATE = '2026-09-01'; // before the branch's data range (2026-10-01..04): seeded by the spec

async function login(page, who) {
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

async function openSheet(page, date) {
    await page.goto(`/daily-ledger/admin/commissary?date=${date}&commissary_id=${COMMISSARY}&shift=PM`, {
        waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
}

/**
 * Record a PM beginning (activity) with no ending on the target day, so the day
 * is a real gap. A product is taken from a FUTURE sheet (which is never
 * auto-finalized), then POSTed before the target sheet is opened, so the
 * request-triggered auto-finalize sees the gap and refuses to finalize.
 */
async function seedMovedProduct(page, targetDate, branch) {
    // A previous run may have auto-finalized the shift before this seed existed;
    // reopen it (admin-only, audited) so the seed is not refused. The movement
    // row then keeps the auto-finalize on its flag branch.
    await page.evaluate(async ({ targetDate, branch }) => {
        await fetch('/daily-ledger/api/v1/admin/reopen-day', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.DL_CSRF || '',
                'Authorization': window.DL_TOKEN ? ('Bearer ' + window.DL_TOKEN) : '',
            },
            body: JSON.stringify({ branch_id: branch, date: targetDate }),
        });
    }, { targetDate, branch });
    const pid = await page.evaluate(async ({ branch }) => {
        const r = await fetch('/daily-ledger/admin/commissary?date=2030-01-01&commissary_id=' + branch + '&shift=PM', { credentials: 'same-origin' });
        const html = await r.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const row = doc.querySelector('#tab-daily-sheet tbody tr.daily-sheet-product-row');
        return row ? Number(row.getAttribute('data-product-id')) : 0;
    }, { branch });
    if (!pid) throw new Error('no product row on the future sheet to seed from');
    const res = await page.evaluate(async ({ targetDate, branch, pid }) => {
        const r = await fetch('/daily-ledger/api/v1/commissary/material', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.DL_CSRF || '',
                'Authorization': window.DL_TOKEN ? ('Bearer ' + window.DL_TOKEN) : '',
            },
            body: JSON.stringify({
                entity: 'product_beg', date: targetDate, commissary_branch_id: branch,
                product_id: pid, shift: 'PM', beg_qty: 1,
                submission_id: 'close-guidance-seed-' + targetDate,
            }),
        });
        return { status: r.status, body: await r.json().catch(() => null) };
    }, { targetDate, branch, pid });
    note({ step: 'seed-moved-product', date: targetDate, productId: pid, status: res.status, body: res.body });
    return pid;
}

test('a failed PM close returns structured missing_products, not names inside the message', async ({ page }) => {
    await login(page, ADMIN);
    await seedMovedProduct(page, DATE, COMMISSARY);
    await openSheet(page, DATE);

    const res = await page.evaluate(async ({ date, branch }) => {
        const r = await fetch('/daily-ledger/api/v1/commissary/finalize-pm', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.DL_CSRF || '',
                'Authorization': window.DL_TOKEN ? 'Bearer ' + window.DL_TOKEN : '',
            },
            body: JSON.stringify({ date, commissary_branch_id: branch }),
        });
        return { status: r.status, body: await r.json().catch(() => null) };
    }, { date: DATE, branch: COMMISSARY });

    note({
        step: 'api-finalize-pm', date: DATE, status: res.status, ok: res.body && res.body.ok,
        missingCount: res.body && Array.isArray(res.body.missing_products) ? res.body.missing_products.length : null
    });

    expect(res.status, 'an incomplete day must refuse the close').toBe(422);
    expect(res.body && res.body.ok).toBe(false);

    // The whole point: the UI can only list products if the server sends them as data.
    const missing = res.body && res.body.missing_products;
    expect(Array.isArray(missing), 'missing_products must be an array, not a string').toBe(true);
    expect(missing.length).toBeGreaterThan(0);
    expect(typeof missing[0].product_id).toBe('number');
    expect(typeof missing[0].name).toBe('string');
    expect(missing[0].name.length).toBeGreaterThan(0);

    // ...and the message must stay SHORT, because the list now carries the detail. The old
    // handler concatenated every name into it, which is what this replaces.
    expect(String(res.body.error).length, 'the message must not re-embed the product list')
        .toBeLessThan(120);
});

test('a failed PM close lands in a persistent role="alert" panel that lists the blocking products', async ({ page }) => {
    await login(page, ADMIN);
    await seedMovedProduct(page, DATE, COMMISSARY);
    await openSheet(page, DATE);

    const panel = page.locator('#finalize-pm-result');
    // It must exist and be HIDDEN up front, or "it appeared" proves nothing.
    expect(await panel.count(), 'the sheet must carry the persistent failure panel').toBeGreaterThan(0);
    await expect(panel, 'the panel starts hidden').toBeHidden();

    // Drive the sheet's own close path (the button is a hint; the handler is server-authoritative).
    await page.evaluate(() => window.finalizeProductionPm());

    await expect(panel, 'a failed close must leave a PERSISTENT panel on screen').toBeVisible({ timeout: 30000 });
    await expect(panel).toHaveAttribute('role', 'alert');

    const text = await panel.innerText();
    expect(text, 'the panel must say what is missing').toContain('missing a PM ending count');

    // The list is the guidance: names the operator can act on, one per line.
    const items = panel.locator('ul li');
    const itemCount = await items.count();
    note({ step: 'panel', date: DATE, itemCount, firstItem: itemCount ? await items.first().innerText() : null });
    expect(itemCount, 'the panel must list the blocking products').toBeGreaterThan(0);
    expect((await items.first().innerText()).trim().length).toBeGreaterThan(0);

    // It is a PANEL, not a toast: still there after the toast would have faded.
    await page.waitForTimeout(2500);
    await expect(panel, 'the panel must persist, not fade like a toast').toBeVisible();
});
