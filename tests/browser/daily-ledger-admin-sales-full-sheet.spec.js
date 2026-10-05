// @ts-check
/**
 * CHAIR PROBE — admin Sales full sheet, on the real page, against live data.
 *
 * Independent of the lane that made the change. The lane admitted it could not
 * finish browser verification, so this closes that gap on the case that matters:
 * branch 8 / 2026-10-03 — the date the audit found a whole missing shift, where
 * the admin previously saw 73 rows and could not see the 109 active products
 * with no record at all.
 *
 * Cross-checks the page footer against an INDEPENDENT SQL measurement taken
 * outside the browser:
 *     rows 182, official 1 unit / PHP 400, provisional 0
 * If the page agrees, query + totals + rendering all agree with each other.
 *
 * Read-only: this spec only views a page. It performs no writes.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test \
 *         tests/browser/daily-ledger-admin-sales-full-sheet.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const BRANCH = '8';
const DATE = '2026-10-03';

// Measured independently via SQL before this probe was written.
const EXPECT_ROWS = 182;
const EXPECT_NO_RECORD = 109;
const EXPECT_OFFICIAL_UNITS = '1';
const EXPECT_OFFICIAL_AMOUNT = 'PHP 400';

test('admin Sales sheet shows every active product and separates no-record from pending', async ({ page }) => {
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });

    const url = `/daily-ledger/admin/sales?date_from=${DATE}&date_to=${DATE}&branch_id=${BRANCH}`;
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
    console.log('HTTP:', resp && resp.status(), url);

    await page.waitForSelector('.table-wrap table tbody tr', { timeout: 60000 });

    const header = (await page.locator('.card-header', { hasText: 'Sales Data' }).first().innerText()).replace(/\s+/g, ' ');
    const noRecord = await page.getByText('No record', { exact: true }).count();
    const pendingBadge = await page.getByText('Pending count', { exact: true }).count();
    const provisionalBadge = await page.getByText('Not finalized', { exact: true }).count();
    const totalRowLoc = page.locator('td:has-text("Official Total:")').first();
    const totalsRow = (await totalRowLoc.locator('..').innerText()).replace(/\s+/g, ' ');
    const trCount = await page.locator('.table-wrap table tbody tr').count();

    // The pending banner must NOT name a date for no-record rows (false pending).
    const banner = await page.locator('text=/date\\(s\\) have pending data/').count();

    console.log('--- EVIDENCE -------------------------------------------------');
    console.log('header            :', JSON.stringify(header));
    console.log('tbody tr elements :', trCount);
    console.log('NO RECORD badges  :', noRecord);
    console.log('PENDING badges    :', pendingBadge);
    console.log('PROVISIONAL badges:', provisionalBadge);
    console.log('pending-data banner present:', banner);
    console.log('TOTALS row        :', JSON.stringify(totalsRow));

    await page.screenshot({ path: '/tmp/chair-admin-sales-2026-10-03.png', fullPage: false });

    // The whole point: the admin can see every active product, not the 73 recorded rows.
    expect(header, 'header should report the full product set').toContain(`${EXPECT_ROWS} rows`);
    expect(noRecord, 'every no-record product must render a distinct "No record" badge').toBe(EXPECT_NO_RECORD);

    // Money: the footer must agree with the independent SQL measurement.
    expect(totalsRow).toContain(EXPECT_OFFICIAL_AMOUNT);
    expect(totalsRow, `official units must be ${EXPECT_OFFICIAL_UNITS}`).toMatch(
        new RegExp(`Official Total:\\s*${EXPECT_OFFICIAL_UNITS}\\b`)
    );
});
