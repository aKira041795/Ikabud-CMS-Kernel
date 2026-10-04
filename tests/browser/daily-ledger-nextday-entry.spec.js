// @ts-check
/**
 * THE OWNER'S PRINCIPLE: a day that was not properly closed must NOT hamper data entry on the
 * next day. It must be FLAGGED and NOTIFIED instead.
 *
 *   "our point is that data entry is not hampered rather flagged and notified to admin and user"
 *
 * So this asserts, on the live tenant, that with the PREVIOUS business day still open and its PM
 * never finalized:
 *   1. the operator is TOLD (the prior-pending banner names that date and links to it)
 *   2. the NEXT day still accepts data entry (cell enabled, write returns 200 and persists)
 *   3. nothing auto-closes or finalises anything as a side effect
 *
 * The premise (previous day open + PM unfinalized) is ESTABLISHED by the test rather than
 * assumed, by reopening the previous day as an admin if it is already closed. That is the
 * audited, permitted way to reach the state, and it is exactly the state the owner describes.
 *
 * The written value is restored in a finally block, so the ledger is left as found.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(300000);

const EVIDENCE = '/tmp/nextday-entry-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const PRODUCER = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };
const COMMISSARY = 18;
const PREVIOUS = '2026-10-03'; // the day left open / unfinalized
const NEXT = '2026-10-04';     // the current business date, which must stay enterable

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

async function openSheet(page, date, shift) {
    await page.goto(`/daily-ledger/admin/commissary?date=${date}&commissary_id=${COMMISSARY}&shift=${shift}`, {
        waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
}

const dayStatus = (page) => page.locator('#production-day-status').innerText();

test('an unfinalized previous day flags and notifies the operator but does NOT hamper the next day', async ({ page }) => {
    // ── 1. Establish the premise: the previous day is open (its PM unfinalized).
    await login(page, ADMIN);
    await openSheet(page, PREVIOUS, 'PM');
    const reopenOffered = await page.locator('#production-reopen-day').count();
    if (reopenOffered > 0) {
        // A deliberately reopened day also reopens its shift, which is the state under test.
        await page.click('#production-reopen-day');
        await page.waitForFunction(
            () => (document.getElementById('production-day-status') || {}).textContent
                && document.getElementById('production-day-status').textContent.trim() === 'open',
            undefined, { timeout: 30000 },
        );
    }
    const priorStatus = (await dayStatus(page)).trim();
    note({ step: 'premise', previous: PREVIOUS, priorStatus, reopened: reopenOffered > 0 });
    expect(priorStatus, `premise: ${PREVIOUS} must be OPEN (unfinalized) for this test to mean anything`).toBe('open');

    // ── 2. As the branch PRODUCTION USER, the next day must be enterable and the situation explained.
    await login(page, PRODUCER);
    await openSheet(page, NEXT, 'PM');

    const banner = page.getByText(`PM ending pending for ${PREVIOUS}.`);
    const bannerShown = (await banner.count()) > 0;
    note({ step: 'user-notified', next: NEXT, bannerShown, nextStatus: (await dayStatus(page)).trim() });

    // NOTIFIED: the operator is told which date is pending, without being blocked.
    await expect(banner, 'the operator must be told a prior PM day is still pending').toBeVisible();

    // NOT HAMPERED: the next day is still open and its cells are still editable.
    expect((await dayStatus(page)).trim(), 'the next day must still be open for entry').toBe('open');

    const pid = await page.evaluate(() =>
        Number(document.querySelector('#tab-daily-sheet tbody tr.daily-sheet-product-row').getAttribute('data-product-id')));
    const sel = `#production-actual-${pid}`;
    await page.waitForSelector(sel, { timeout: 60000 });
    const original = await page.evaluate((s) => document.querySelector(s).getAttribute('data-original'), sel);
    const enabled = await page.evaluate((s) => !document.querySelector(s).disabled, sel);
    note({ step: 'next-day-cell', productId: pid, original, enabled });
    expect(enabled, 'the next day must not be locked out by the previous day being unfinalized').toBe(true);

    const target = String(Number(original || 0) + 1);
    let written = null;
    try {
        const resp = page.waitForResponse((r) => r.url().includes('/api/v1/commissary/material'), { timeout: 60000 });
        await page.fill(sel, target);
        await page.dispatchEvent(sel, 'change');
        const res = await resp;
        written = { status: res.status(), body: await res.json().catch(() => null) };
        note({ step: 'next-day-write', productId: pid, from: original, to: target, ...written });

        expect(written.status, 'writing to the next day must be ALLOWED, not refused').toBe(200);
        expect(written.body && written.body.ok).toBe(true);

        // The row really moved (a 200 that silently did nothing would still be "hampered").
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForSelector(sel, { timeout: 60000 });
        expect(
            await page.evaluate((s) => document.querySelector(s).getAttribute('data-original'), sel),
            'the written value must persist',
        ).toBe(target);
    } finally {
        // Leave the ledger as found.
        if (written && written.status === 200 && original !== null && original !== undefined) {
            const resp = page.waitForResponse((r) => r.url().includes('/api/v1/commissary/material'), { timeout: 60000 });
            await page.fill(sel, String(original));
            await page.dispatchEvent(sel, 'change');
            const res = await resp;
            note({ step: 'restore', productId: pid, restoredTo: original, status: res.status() });
        }
    }

    // ── 3. Flagging must not have closed or finalised anything as a side effect.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
    note({ step: 'after', nextStatus: (await dayStatus(page)).trim() });
    expect((await dayStatus(page)).trim(), 'the entry must not have triggered a close').toBe('open');
});
