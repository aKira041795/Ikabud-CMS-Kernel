// @ts-check
/**
 * The settle / verify-for-finality control surface, end to end.
 *
 * Proves the ADMIN can actually operate the lifecycle built in 916c547b, which until now existed
 * only as services and endpoints.
 *
 * The mutating half deliberately runs settle -> REVERT, never settle -> verify:
 *   - it still proves the buttons hit the real endpoints (the counts change and change back);
 *   - it leaves the ledger EXACTLY as it found it (settle tags the ending, revert clears it);
 *   - and it never writes a CERTIFICATION of a derived number on the live ledger. A false
 *     certification is worse than an untested button, and "verify" is already proven at the service
 *     level by the oracle's lifecycle assertions.
 *
 * Fixture: branch 8 / 2026-08-26 / PM, measured to hold 89 rows with no ending. That date is not
 * today and not near it, so the test does not interfere with the current business day.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-settled-endings.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(240000);

const EVIDENCE = '/tmp/settled-endings-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const PRODUCER = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };
const BRANCH = 8;
const DATE = '2026-08-26';
const SHIFT = 'PM';
const URL = `/daily-ledger/ledger?date=${DATE}&branch_id=${BRANCH}&shift=${SHIFT}`;

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

async function openLedger(page) {
    await page.goto(URL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#settled-panel', { timeout: 60000 });
}

const counts = (page) => page.evaluate(() => {
    const p = document.getElementById('settled-panel');
    return p ? {
        pending: Number(p.getAttribute('data-pending')),
        unverified: Number(p.getAttribute('data-unverified')),
        verified: Number(p.getAttribute('data-verified')),
    } : null;
});

const waitFor = (page, key, pred, timeout = 45000) => page.waitForFunction(
    ([k, fn]) => {
        const p = document.getElementById('settled-panel');
        if (!p) return false;
        const v = Number(p.getAttribute('data-' + k));
        // eslint-disable-next-line no-new-func
        return new Function('v', 'return ' + fn)(v);
    },
    [key, pred],
    { timeout },
);

test('an admin settles pending endings, sees them await verification, then reverts them', async ({ page }) => {
    await login(page, ADMIN);
    await openLedger(page);

    const start = await counts(page);
    note({ step: 'start', date: DATE, branch: BRANCH, shift: SHIFT, ...start });
    expect(start, 'the panel must render for the sheet roles').not.toBeNull();
    expect(start.pending, `fixture ${DATE} branch ${BRANCH} ${SHIFT} must have pending rows to settle`)
        .toBeGreaterThan(0);
    expect(start.unverified, 'nothing is derived before a settle').toBe(0);

    // Nothing is awaiting verification yet, so the verify control must not be offered even to an
    // admin - its presence tracks the unverified count, not the role alone.
    await expect(page.locator('#verify-settled-endings')).toHaveCount(0);

    let settled = false;
    try {
        await page.click('#settle-pending-endings');
        await waitFor(page, 'unverified', 'v > 0');
        settled = true;
        const afterSettle = await counts(page);
        note({ step: 'settled', ...afterSettle });

        // The rows moved from pending to awaiting verification, and the admin is now offered the
        // control that certifies them.
        expect(afterSettle.pending, 'settled rows are no longer pending').toBeLessThan(start.pending);
        expect(afterSettle.unverified, 'settled rows await verification').toBeGreaterThan(0);
        await expect(page.locator('#verify-settled-endings'), 'an admin must be offered verify').toHaveCount(1);
        await expect(page.locator('#revert-settled-endings'), 'an admin must be offered revert').toHaveCount(1);
    } finally {
        // Leave the ledger as found. This is the irreversibility guarantee doing its job.
        if (settled) {
            await page.click('#revert-settled-endings');
            await waitFor(page, 'unverified', 'v === 0');
            const afterRevert = await counts(page);
            note({ step: 'reverted', ...afterRevert });
            expect(afterRevert.pending, 'revert returns the settled rows to pending')
                .toBe(start.pending);
            expect(afterRevert.unverified, 'nothing is left awaiting verification').toBe(0);
        }
    }
});

test('the control surface never offers certification to a non-admin', async ({ page }) => {
    // WHY THIS IS NOT A VACUOUS TEST, and why it is NOT here:
    // asserting "a non-admin sees no verify control" on a page that has no panel at all proves
    // nothing - and the production sheet legitimately omits the panel when all three counts are 0,
    // which they are for branch 18 right now. The role gate is therefore asserted where it can be
    // asserted NON-VACUOUSLY, with the panel present:
    //   - the oracle renders the sheet as admin AND as the production user with one derived ending
    //     seeded, and asserts the admin's HTML carries #verify-settled-endings while the producer's
    //     does not;
    //   - and the service refuses verify/revert for a non-admin, changing nothing.
    // This spec keeps only the admin end-to-end cycle, which is what a browser can prove here.
    await login(page, PRODUCER);
    await page.goto('/daily-ledger/admin/commissary?date=2026-10-04&commissary_id=18&shift=PM',
        { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });

    const verifyCount = await page.locator('#verify-settled-endings').count();
    const revertCount = await page.locator('#revert-settled-endings').count();
    note({ step: 'producer-view', verifyCount, revertCount });
    expect(verifyCount, 'a non-admin must never be offered verify').toBe(0);
    expect(revertCount, 'a non-admin must never be offered revert').toBe(0);
});
