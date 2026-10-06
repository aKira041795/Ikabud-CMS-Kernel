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

// ─────────────────────────────────────────────────────────────────────────────
// Slice B picker — READ-ONLY. These tests load the tab, exercise the client-side
// search filter, and inspect the deep link. They NEVER click save and never
// change a checkbox against the live tenant.
// ─────────────────────────────────────────────────────────────────────────────
test('picker: products view exposes Products + Show in Branches tabs', async ({ page }) => {
    await login(page);
    const resp = await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    console.log('products (tabs) HTTP:', resp && resp.status());
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    await expect(page.locator('[data-products-tabs]')).toBeVisible();
    await expect(page.locator('[data-tab-products]')).toBeVisible();
    await expect(page.locator('[data-tab-assignment]')).toBeVisible();
    // With no tab parameter the product list is the only table shown.
    await expect(page.locator('#picker-table')).toHaveCount(0);
});

test('picker: assignment tab renders branch selector, search and checklist', async ({ page }) => {
    await login(page);
    const resp = await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    console.log('assignment tab HTTP:', resp && resp.status());
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    await expect(page.locator('[data-tab-assignment]')).toBeVisible();
    const branch = page.locator('[data-picker-branch]');
    await expect(branch).toBeVisible();
    const branchOptions = await branch.locator('option').count();

    await expect(page.locator('[data-picker-search]')).toBeVisible();
    const checks = page.locator('[data-product-check]');
    const total = await checks.count();

    console.log('--- PICKER ASSIGNMENT TAB -----------------------------------');
    console.log('branch options    :', branchOptions);
    console.log('product checkboxes:', total);

    expect(branchOptions, 'the assignment tab must offer at least one branch').toBeGreaterThan(0);
    expect(total, 'the assignment tab must list products with checkboxes').toBeGreaterThan(0);
    await page.screenshot({ path: '/tmp/chair-slice-b-assignment-tab.png', fullPage: false });

    // The branch being edited is always visible on screen.
    await expect(page.locator('[data-picker-branch-name]')).toBeVisible();
});

test('picker: client-side search filters the checklist', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    const total = await page.locator('[data-product-check]').count();
    const firstSku = (await page.locator('#picker-table tbody tr.picker-row').first().locator('.picker-sku').innerText()).trim();
    const token = firstSku;

    await page.fill('[data-picker-search]', token);
    await page.waitForTimeout(200);
    const visible = await page.locator('#picker-table tbody tr.picker-row:not(.is-hidden)').count();

    console.log('--- PICKER SEARCH FILTER -----------------------------------');
    console.log('filter token      :', token);
    console.log('visible / total   :', visible, '/', total);

    expect(visible, 'the filter must keep at least the matching product').toBeGreaterThan(0);
    expect(visible, 'the filter must hide non-matching products').toBeLessThan(total);
    // Read-only: the search box does not save anything.
});

test('picker: switching branches stays on the same assignment tab (read-only)', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    const branch = page.locator('[data-picker-branch]');
    const optionCount = await branch.locator('option').count();
    if (optionCount < 2) {
        test.skip(true, 'need at least two branches to switch');
        return;
    }
    const first = (await page.locator('[data-picker-branch-name]').innerText()).trim();
    const secondValue = await branch.locator('option').nth(1).getAttribute('value');
    await branch.selectOption(secondValue);
    await page.waitForFunction(
        (previous) => {
            const el = document.querySelector('[data-picker-branch-name]');
            return !!el && el.textContent.trim() !== previous;
        },
        first,
        { timeout: 120000 }
    );
    const second = (await page.locator('[data-picker-branch-name]').innerText()).trim();
    console.log('--- PICKER BRANCH SWITCH -----------------------------------');
    console.log('from / to         :', first, '/', second);
    console.log('url               :', page.url());

    expect(second).not.toBe(first);
    expect(page.url()).toContain('tab=assignment');
    expect(page.url()).toContain('branch_id=' + secondValue);
    // Read-only: no checkbox was changed and no save was clicked.
});

test('picker: branches page links to the SAME assignment tab preselecting the branch', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/branches', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const link = page.locator('a[data-branch-products]').first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    console.log('branch products deep link:', href);

    expect(href).toContain('/admin/products?tab=assignment&branch_id=');
});

/**
 * CHAIR VISUAL/TRUTHFULNESS CHECK.
 *
 * Measured invariant (tenant 207): 182 products x 19 branches = 3458 active pairs, 0 hidden. So for branch 8
 * every one of its 182 products is assigned and every checkbox MUST render checked. This asserts the UI
 * reflects REAL assignments rather than merely rendering.
 */
test('picker: checkboxes reflect the real assignments for the branch', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment&branch_id=8', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[data-product-check]', { timeout: 60000 });

    const boxes = page.locator('input[data-product-check]');
    const total = await boxes.count();
    let checked = 0;
    for (let i = 0; i < total; i++) {
        if (await boxes.nth(i).isChecked()) { checked++; }
    }

    // Is the on-screen "ticked" counter correct on FIRST render, before any interaction?
    const counter = (await page.locator('#picker-checked-count').innerText()).trim();

    console.log('--- PICKER TRUTHFULNESS -------------------------------------');
    console.log('checkboxes        :', total);
    console.log('checked           :', checked);
    console.log('counter on load   :', JSON.stringify(counter), '(should equal checked, not 0)');
    console.log('branch selector   :', await page.locator('select').first().inputValue().catch(() => 'n/a'));

    await page.screenshot({ path: '/tmp/chair-assignment-tab.png', fullPage: false });

    // Dump the REAL rendered markup so a design review can work from the shipped artefact, not a description.
    try {
        require('fs').writeFileSync('/tmp/chair-assignment-tab.html', await page.content());
        console.log('rendered HTML     : /tmp/chair-assignment-tab.html');
    } catch (e) {
        console.log('rendered HTML     : could not dump ->', String(e).slice(0, 80));
    }

    expect(total, 'the branch must list its 182 assigned products').toBe(182);
    expect(checked, 'all 182 are assigned to every branch (0 hidden pairs) so all must be checked').toBe(182);
    // The counter must be truthful on first paint; a stale "0" would mislead the admin.
    expect(counter, 'the ticked counter must be correct before any interaction').toBe(String(checked));
});
