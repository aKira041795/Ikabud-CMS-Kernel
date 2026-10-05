// @ts-check
/**
 * CHAIR PROBE — admin Sales full sheet, on the real page, against live data.
 *
 * Independent of the lane that made the change. This closes the browser-verification gap
 * twice admitted by lanes, and it does so by CROSS-CHECKING the rendered footer against money
 * the chair measured independently in SQL against the ROW-DRIVEN RECORD — the same numbers the
 * governed reports/exports compute. If the page agrees per date, then query, totals, view and
 * record all agree with one another.
 *
 * These expectations were measured with raw SQL on branch 8 (tenant 207), NOT read from the
 * implementation's own tests:
 *     2026-09-25  official 1849 / 15509.49   provisional    0 /     0.00
 *     2026-10-03  official    1 /   400.00   provisional    0 /     0.00
 *     2026-10-04  official    0 /     0.00   provisional    0 /     0.00
 *     2026-10-05  official 1364 / 11401.48   provisional    2 /   800.00
 *
 * 2026-10-03 row expectations: 178 rows and 105 "No record" badges.
 *   182 active products, minus the 4 created AFTER 2026-10-03 (which must NOT be projected
 *   backwards in time), = 178. The 105 no-record rows are products with no ledger row anywhere
 *   on that date. The other 73 rows are REAL rows bearing activity with no ending, and they must
 *   keep rendering as amber "Pending count" — that is the data an encoder has to fill.
 *
 * Read-only: this spec only views pages. It performs no writes.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test \
 *         tests/browser/daily-ledger-admin-sales-full-sheet.spec.js --reporter=line --retries=0
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(240000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const BRANCH = '8';

// date -> { officialUnits, officialAmountShown } measured in SQL against the row-driven RECORD.
// NOTE the amounts below are the ZERO-DECIMAL renderings the page actually produces
// (15,509.49 -> "15,509"). The template line `PHP {grand_amount | number_format}` drops
// centavos. That is PRE-EXISTING behaviour, not introduced by the full-sheet work, and it is
// recorded here explicitly rather than silently accepted: an official total that rounds to the
// peso can hide a sub-peso discrepancy from the accountant auditing this page.
const MONEY = [
    { date: '2026-09-25', officialUnits: 1849, officialAmountShown: '15,509' },
    { date: '2026-10-03', officialUnits: 1, officialAmountShown: '400' },
    { date: '2026-10-04', officialUnits: 0, officialAmountShown: '0' },
    { date: '2026-10-05', officialUnits: 1364, officialAmountShown: '11,401' },
];

async function login(page) {
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

const unitsOf = (footer) => {
    const m = footer.match(/Official Total:\s*([\d,]+)/);
    return m ? Number(m[1].replace(/,/g, '')) : null;
};

test('admin Sales footer agrees with the row-driven RECORD money for every date', async ({ page }) => {
    await login(page);

    for (const exp of MONEY) {
        await page.goto(`/daily-ledger/admin/sales?date_from=${exp.date}&date_to=${exp.date}&branch_id=${BRANCH}`, {
            waitUntil: 'domcontentloaded',
        });
        await page.waitForSelector('.table-wrap table tbody tr', { timeout: 60000 });

        const header = (await page.locator('.card-header', { hasText: 'Sales Data' }).first().innerText()).replace(/\s+/g, ' ');
        const footer = (await page.locator('td:has-text("Official Total:")').first().locator('..').innerText()).replace(/\s+/g, ' ');
        const units = unitsOf(footer);

        console.log(`[money] ${exp.date}  header="${header}"  footer="${footer}"  units=${units} expected=${exp.officialUnits}`);

        expect(units, `${exp.date}: official units must match the row-driven record`).toBe(exp.officialUnits);
        expect(footer, `${exp.date}: official amount must match the row-driven record`).toContain(exp.officialAmountShown);
    }
});

test('2026-10-03 shows the whole sheet, separates no-record from pending, and invents no future product', async ({ page }) => {
    await login(page);

    await page.goto(`/daily-ledger/admin/sales?date_from=2026-10-03&date_to=2026-10-03&branch_id=${BRANCH}`, {
        waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('.table-wrap table tbody tr', { timeout: 60000 });

    const header = (await page.locator('.card-header', { hasText: 'Sales Data' }).first().innerText()).replace(/\s+/g, ' ');
    const noRecord = await page.getByText('No record', { exact: true }).count();
    const pending = await page.getByText('Pending count', { exact: true }).count();
    const footer = (await page.locator('td:has-text("Official Total:")').first().locator('..').innerText()).replace(/\s+/g, ' ');

    console.log('--- EVIDENCE -------------------------------------------------');
    console.log('header       :', JSON.stringify(header));
    console.log('NO RECORD    :', noRecord, '(expect 105 — 4 post-date products excluded)');
    console.log('PENDING      :', pending, '(expect 71: the 73 AM rows, 2 of which DO have an ending)');
    console.log('footer       :', JSON.stringify(footer));
    const cov = page.locator('#shift-coverage-disclosure');
    console.log('coverage     :', (await cov.count()) ? JSON.stringify((await cov.first().innerText()).replace(/\s+/g, ' ')) : '(absent)');

    await page.screenshot({ path: '/tmp/chair-admin-sales-2026-10-03-fixed.png', fullPage: false });

    // 182 active products MINUS the 4 created after this date. The bound must hold.
    expect(header, 'products created after the viewed date must not be projected backwards').toContain('178 rows');
    expect(noRecord, 'no-record rows must be exactly the products that existed and had no row').toBe(105);
    // 73 products have an AM row and no PM row on this date, but only 71 LACK an ending — two of
    // those rows carry an ending and are therefore not pending. 71 is the figure the original
    // audit recorded for this date ("73 rows, 71 missing endings"); the chair's first draft of
    // this probe asserted 73 and was wrong.
    expect(pending, 'genuine activity-bearing gaps must still be called out').toBe(71);

    // And the money is still the record's money.
    expect(unitsOf(footer)).toBe(1);
    expect(footer).toContain('PHP 400');
});

/**
 * The wide window is the stress case for FIX A's UNION derived table: MySQL 5.7 cannot merge a
 * UNION derived table, so it materialises ~12k pair rows before joining. This proves on the real
 * page that (a) it still returns the RECORD's money over a 35-day span, (b) it does not become
 * pathological, and (c) the corrected truncation disclosure actually renders when the cap bites.
 */
test('wide range: money still matches the record, stays responsive, and discloses the cap honestly', async ({ page }) => {
    await login(page);

    const t0 = Date.now();
    await page.goto(`/daily-ledger/admin/sales?date_from=2026-09-01&date_to=2026-10-05&branch_id=${BRANCH}`, {
        waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('.table-wrap table tbody tr', { timeout: 90000 });
    const elapsed = Date.now() - t0;

    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    const footer = (await page.locator('td:has-text("Official Total:")').first().locator('..').innerText()).replace(/\s+/g, ' ');
    const units = unitsOf(footer);

    console.log('--- WIDE RANGE -----------------------------------------------');
    console.log('elapsed ms   :', elapsed);
    console.log('footer       :', JSON.stringify(footer));
    console.log('truncated    :', /Showing the newest/.test(body));
    const covWide = page.locator('#shift-coverage-disclosure');
    console.log('coverage     :', (await covWide.count()) ? JSON.stringify((await covWide.first().innerText()).replace(/\s+/g, ' ')) : '(absent)');
    console.log('disclosure   :', (body.match(/The daily sales report[^.]*\./) || body.match(/Reports .*?omitted from this page[^.]*\./) || [''])[0]);

    await page.screenshot({ path: '/tmp/chair-admin-sales-wide-range.png', fullPage: false });

    // Measured independently in SQL for 2026-09-01..2026-10-05, branch 8, row-driven RECORD:
    // official 64692 units / PHP 571330.61.
    expect(units, 'a 35-day window must still total exactly the record').toBe(64692);

    // A derived-table UNION must not make a shared-hosted page pathological.
    expect(elapsed, 'wide range must stay responsive').toBeLessThan(30000);

    // The cap DID bite, so the corrected copy must be present and must no longer claim that the
    // governed report contains the no-record rows.
    expect(body, 'the cap must be disclosed').toContain('Showing the newest');
    expect(body, 'the report must no longer be presented as the full version of this view')
        .not.toContain('to view and export the full set');
    expect(body, 'the omission count must be disclosed').toMatch(/no-record row\(s\) are omitted from this page/);
});
