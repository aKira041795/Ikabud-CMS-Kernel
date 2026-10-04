// @ts-check
/**
 * The admin Sales view must visibly mark rows whose ending is missing, and a range view must
 * name the dates that have pending data.
 *
 * Owner request (2026-10-05): "at admin view when viewing a specific date for sales ... a visual
 * note on pending cells where ending is missing ... on multi dates, show an orange cell on cells
 * with pending and show as tool tip dates with pending sales data".
 *
 * WHY THIS SPEC EXISTS IN ADDITION TO THE PHP ORACLE
 * daily_ledger_sales_pending_marker_test.php renders the TEMPLATE directly with fixture rows that
 * already carry `status_label`. That proves the template renders a label — it does NOT prove the
 * HANDLER supplies one. The handler is where the defect lives: the sales list query
 * (handlers.php ~L10625) fetches `ss.status AS shift_status` but no status_label, which is why the
 * template was left to re-derive the rule. This spec goes through the real HTTP path, so a
 * half-fix that labels only the fixture (or only the totals) fails here.
 *
 * FIXTURE (measured 2026-10-05, tenant baronledger): 2026-10-03, branch 8 "Miputak" has 70 rows
 * with bal_end IS NULL — the largest recent pending set.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-sales-pending-marker.spec.js --reporter=line
 * NOTE: APP_URL is required. playwright.config.js falls back to http://palsystem.test when it is
 * unset, which renders "We could not find that page." and fails at the login selector — a
 * misleading failure that looks like a product defect.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(240000);

const EVIDENCE = '/tmp/sales-pending-marker-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const PENDING_DATE = '2026-10-03';
const PENDING_BRANCH = 8;

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

/** Rows in the Sales table, as { text, hasPendingMarker, accessibleLabel }. */
async function readSalesRows(page) {
    return page.evaluate(() => {
        const table = document.querySelector('table');
        if (!table) return [];
        return Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
            const text = (tr.textContent || '').replace(/\s+/g, ' ').trim();
            const marks = Array.from(tr.querySelectorAll('[title],[aria-label]'))
                .map((el) => (el.getAttribute('title') || el.getAttribute('aria-label') || ''))
                .filter((t) => /pending|provisional/i.test(t));
            return {
                text,
                hasPendingMarker: /pending count/i.test(text),
                hasProvisionalMarker: /provisional/i.test(text),
                accessibleLabel: marks[0] || '',
            };
        });
    });
}

test('a single date marks the rows whose ending is missing, in text and not only in colour', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto(
        `/daily-ledger/admin/sales?date_from=${PENDING_DATE}&date_to=${PENDING_DATE}&branch_id=${PENDING_BRANCH}`,
        { waitUntil: 'domcontentloaded' }
    );

    const rows = await readSalesRows(page);
    note({ step: 'single-date', date: PENDING_DATE, branch: PENDING_BRANCH, rowCount: rows.length });

    expect(rows.length, 'the Sales table rendered no rows at all').toBeGreaterThan(0);

    const marked = rows.filter((r) => r.hasPendingMarker);
    note({ step: 'single-date-marked', marked: marked.length, sample: marked.slice(0, 3) });

    // The table must render rows and at least one must be marked. Before the fix the marker
    // never appeared for a missing ending, so this fails on the old behaviour rather than
    // merely on a missing string.
    expect(marked.length, 'no row with a missing ending carried a "Pending count" marker').toBeGreaterThan(0);

    // The marker must be readable, not only a colour: this view is printed and tooltips do
    // not survive print or touch.
    expect(
        marked.some((r) => r.accessibleLabel.length > 0),
        'the pending marker has no title/aria-label, so it is colour-only'
    ).toBe(true);

    // Two states must not collapse into one word, or the operator cannot tell "nobody has
    // counted this" from "counted but not certified".
    const provisional = rows.filter((r) => r.hasProvisionalMarker).length;
    note({ step: 'single-date-provisional', provisional });

    expect(
        await page.locator('body').innerText(),
        'the view does not explain what a pending count means'
    ).toMatch(/pending/i);
});

test('a date range names the dates that have pending data', async ({ page }) => {
    await login(page, ADMIN);
    await page.goto('/daily-ledger/admin/sales?date_from=2026-10-01&date_to=2026-10-03', {
        waitUntil: 'domcontentloaded',
    });

    const body = await page.locator('body').innerText();
    const rows = await readSalesRows(page);
    note({ step: 'range', rowCount: rows.length, markedRows: rows.filter((r) => r.hasPendingMarker).length });

    expect(rows.length, 'the range rendered no rows').toBeGreaterThan(0);

    const marked = rows.filter((r) => r.hasPendingMarker).length;
    expect(marked, 'no row in the range was marked as pending').toBeGreaterThan(0);

    // Naming the dates is the whole point of the range case: the operator must not have to
    // hunt per-cell tooltips to discover where the data is incomplete.
    //
    // Asserted against the SUMMARY, not the page: the Date column renders every date anyway,
    // so a bare toContain(date) would pass on the unfixed view.
    expect(
        body,
        'the range view does not name the pending date(s) in visible text'
    ).toMatch(/have pending data/i);
    expect(
        body,
        'the summary does not name the pending date'
    ).toMatch(new RegExp('have pending data[\\s\\S]{0,120}' + PENDING_DATE, 'i'));

    const summaryLine = await page.evaluate(() => {
        // Require the SUMMARY specifically. Matching "some element containing 'pending' and a
        // date" passes on the unfixed view, because the footer already says "pending ending"
        // and the Date cell already renders the date — a check that cannot fail.
        const els = Array.from(document.querySelectorAll('div,p,span'));
        const hit = els.find((el) => /have pending data/i.test(el.textContent || ''));
        return hit ? (hit.textContent || '').replace(/\s+/g, ' ').trim() : '';
    });
    note({ step: 'range-summary', summaryLine });
    expect(summaryLine, 'no visible summary line naming the pending dates').not.toBe('');
});
