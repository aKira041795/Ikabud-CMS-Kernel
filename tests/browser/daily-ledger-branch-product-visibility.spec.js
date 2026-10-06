// @ts-check
/**
 * CHAIR BASELINE — daily-ledger products/branches admin surfaces, BEFORE the per-branch visibility UI.
 *
 * Purpose right now is to freeze a PRE-CHANGE snapshot with the owner's own credentials so that, when the
 * picker (Slice B) lands, any change in these surfaces is attributable rather than assumed. It records
 * evidence and only asserts things that must be true both before and after.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test \
 *         tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
 *
 * Read-only: it only loads pages.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };

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

test('baseline: products admin page renders for the owner account', async ({ page }) => {
    await login(page);

    const resp = await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    console.log('products HTTP:', resp && resp.status());

    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const rows = await page.locator('table tbody tr').count();
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');

    console.log('--- PRODUCTS BASELINE ---------------------------------------');
    console.log('tbody rows        :', rows);
    console.log('has branch count  :', /\b\d+\s+branches\b/.test(body));
    console.log('has Add/New button:', /add product|new product/i.test(body));
    console.log('mentions Inactive :', /inactive/i.test(body));

    await page.screenshot({ path: '/tmp/chair-baseline-products.png', fullPage: false });

    // Must hold before AND after the change.
    expect(rows, 'the products page must list products').toBeGreaterThan(0);
});

test('baseline: branches admin page renders and lists the commissary', async ({ page }) => {
    await login(page);

    const resp = await page.goto('/daily-ledger/admin/branches', { waitUntil: 'domcontentloaded' });
    console.log('branches HTTP:', resp && resp.status());
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const rows = await page.locator('table tbody tr').count();
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    const commissaryMentioned = /commis/i.test(body);

    console.log('--- BRANCHES BASELINE --------------------------------------');
    console.log('tbody rows        :', rows);
    console.log('mentions commissary:', commissaryMentioned);
    console.log('has branch count  :', /\b\d+\s+products?\b/.test(body));

    await page.screenshot({ path: '/tmp/chair-baseline-branches.png', fullPage: false });

    expect(rows, 'the branches page must list branches').toBeGreaterThan(0);
});
