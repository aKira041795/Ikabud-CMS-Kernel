// @ts-check
/**
 * The prior-pending banner's "Open prior PM ledger" link must actually open the prior ledger.
 *
 * Owner report: "open prior ledger says 404."
 *
 * Reproduces the defect in a browser and then pins the fix. The banner is rendered on the
 * production sheet when the previous business day is still open on an unfinalized PM shift, and its
 * link is built in templates/modules/daily-ledger/admin/commissary.disyl. The cashier ledger's
 * equivalent banner links as "{base_url}/ledger" — because {base_url} ALREADY carries the module
 * prefix — so a link written as "{base_url}/daily-ledger/..." resolves to
 * "/daily-ledger/daily-ledger/..." and 404s.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-prior-ledger-link.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(240000);

const EVIDENCE = '/tmp/prior-ledger-link-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const PRODUCER = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };
const COMMISSARY = 18;
const TODAY = '2026-10-04';

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

test('the prior-pending banner links to a ledger that opens', async ({ page }) => {
    await login(page, PRODUCER);
    await page.goto(`/daily-ledger/admin/commissary?date=${TODAY}&commissary_id=${COMMISSARY}&shift=PM`,
        { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-day-status', { timeout: 60000 });

    const link = page.getByRole('link', { name: /Open prior PM ledger/i });
    const linkCount = await link.count();
    note({ step: 'banner', today: TODAY, linkCount });

    // The banner only renders when a prior PM day is still pending; if the fixture state has been
    // resolved, say so plainly instead of passing vacuously.
    expect(linkCount, 'the prior-pending banner must be present for this test to mean anything').toBeGreaterThan(0);

    const href = await link.first().getAttribute('href');
    note({ step: 'href', href });

    // The module prefix must appear ONCE. {base_url} already ends with /daily-ledger.
    const occurrences = (href.match(/\/daily-ledger\//g) || []).length;
    expect(occurrences, `href repeats the module prefix: ${href}`).toBe(1);

    // The real assertion: clicking it must LAND, not 404.
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes(href.split('?')[0]), { timeout: 60000 }),
        link.first().click(),
    ]);
    note({ step: 'clicked', status: response.status(), url: response.url() });
    expect(response.status(), `clicking the banner link must not ${response.status()}`).toBeLessThan(400);

    // And it must actually open a ledger for the PRIOR date, not silently fall back to today.
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
    const dateValue = await page.evaluate(() => {
        const el = document.querySelector('#production-date-picker');
        return el ? el.value : null;
    });
    const priorDate = new URL(href, page.url()).searchParams.get('date');
    note({ step: 'landed', priorDate, dateValue });
    expect(dateValue, 'the opened ledger must be for the prior date').toBe(priorDate);
});
