const { test, expect } = require('@playwright/test');
const fs = require('fs');

/**
 * The show/hide combo must filter the DISPATCH product list per destination (owner model 2026-11-19).
 *
 * Before this change the New Delivery (DR) modal listed every product the hub held stock of and never
 * consulted dl_branch_products, so measured at RIZAL-COMMIS 41 rows were offered of which Fishport
 * shows only 27. The rows are the same table the "With box?" control reads, so the boxes must survive.
 *
 * Run: APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-dispatch-show-hide.spec.js --reporter=line
 */

test.setTimeout(180000);

const EVIDENCE = '/tmp/dispatch-show-hide-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };

async function login(page) {
    await page.context().clearCookies();
    await page.goto('/daily-ledger/login');
    await page.waitForSelector('input[name="username"]', { timeout: 30000 });
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

/** Rows the operator can actually see and type into. */
const visibleRows = (page) => page.$$eval('#dispatch-panel tbody tr', (rows) =>
    rows.filter((r) => r.style.display !== 'none' && r.querySelector('.dispatch-qty-input'))
        .map((r) => {
            const input = r.querySelector('.dispatch-qty-input');
            return {
                name: (input && input.getAttribute('data-product-name')) || '',
                category: (input && input.getAttribute('data-category')) || '',
                id: (input && input.getAttribute('data-product-id')) || '',
            };
        }));

test('the dispatch modal offers only what the chosen destination shows', async ({ page }) => {
    fs.writeFileSync(EVIDENCE, '');
    await login(page);

    await page.goto('/daily-ledger/admin/commissary', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });

    const trigger = page.locator('button:has-text("New Delivery")');
    await expect(trigger, 'the PIC/admin must be able to open the modal').toBeVisible({ timeout: 30000 });
    await trigger.click();
    await expect(page.locator('#dispatch-panel')).toBeVisible({ timeout: 30000 });

    const beforeRows = await visibleRows(page);
    note({ step: 'modal-opened', visible: beforeRows.length, names: beforeRows.map((r) => r.name).slice(0, 8) });
    expect(beforeRows.length, 'the modal must list the hub\'s stock-bearing products').toBeGreaterThan(0);

    const dest = page.locator('#dispatch-dest-branch-id');
    const options = await dest.locator('option').evaluateAll((os) =>
        os.filter((o) => o.value && (o.getAttribute('data-type') || 'branch') === 'branch')
            .map((o) => ({ value: o.value, label: o.textContent.trim() })));
    note({ step: 'destinations', options });
    expect(options.length, 'there must be a branch to dispatch to').toBeGreaterThan(0);

    // Pick every real destination and check the DOM against what THAT payload implies. Comparing to
    // the payload (rather than to "fewer than before") keeps the test honest in both directions: a
    // destination that shows everything must keep every row, and one that hides three must lose
    // exactly three. A test that only asserted "narrowing happened somewhere" would pass vacuously
    // on this tenant, where most destinations show all 41 rows.
    const offeredIds = beforeRows.map((r) => String(r.id));
    const offeredById = new Map(beforeRows.map((r) => [String(r.id), r]));
    let narrowedSomewhere = 0;

    // Watch the network rather than assume the request fires: a prefill that never leaves the browser
    // and one that returns an error are different defects, and a bare waitForResponse cannot tell them
    // apart (its timeout only says "no matching response"). Bodies are captured, not re-fetched.
    const prefillBodies = [];
    page.on('response', (r) => {
        if (r.url().includes('dispatch-prefill')) {
            prefillBodies.push(r.json().catch(() => null));
        }
    });
    page.on('pageerror', (e) => note({ step: 'pageerror', message: String(e) }));

    for (const option of options) {
        const seen = prefillBodies.length;
        await dest.selectOption(option.value);
        const deadline = Date.now() + 30000;
        while (prefillBodies.length === seen && Date.now() < deadline) {
            await page.waitForTimeout(100);
        }
        expect(prefillBodies.length, `selecting ${option.label} must call the prefill endpoint`)
            .toBeGreaterThan(seen);

        const prefillBody = await prefillBodies[seen];
        note({
            step: 'prefill', destination: option.label, mode: prefillBody && prefillBody.mode,
            shown: prefillBody && prefillBody.shown_product_ids && prefillBody.shown_product_ids.length
        });
        await page.waitForTimeout(200);

        const shown = new Set((prefillBody.shown_product_ids || []).map(String));
        const afterRows = await visibleRows(page);

        // Expected: every offered row the destination shows, plus every packaging row (exempt).
        const expected = offeredIds.filter((id) => {
            const row = offeredById.get(id) || { category: '', name: '' };
            const packaging = row.category === 'other' && /box/i.test(row.name);
            return packaging || shown.has(id);
        });

        note({
            step: 'destination',
            destination: prefillBody.destination_name,
            mode: prefillBody.mode,
            shown_products: shown.size,
            expected_visible: expected.length,
            actual_visible: afterRows.length,
        });

        expect(Array.isArray(prefillBody.shown_product_ids),
            'the prefill must report the destination\'s shown products, or the modal cannot narrow itself')
            .toBe(true);

        if (prefillBody.mode === 'sheet') {
            // Sheet-first mode shows only the sheet's own lines: a different, narrower rule.
            continue;
        }

        const offenders = afterRows.filter((r) => r.category !== 'other' && !shown.has(String(r.id)));
        expect(offenders, `destination ${prefillBody.destination_name} must not offer a product it does not show`)
            .toHaveLength(0);

        expect(afterRows.map((r) => String(r.id)).sort(),
            `visible rows for ${prefillBody.destination_name} must equal what its payload implies`)
            .toEqual(expected.sort());

        if (afterRows.length < beforeRows.length) {
            narrowedSomewhere++;
        }
    }

    note({ step: 'narrowed-destinations', count: narrowedSomewhere, of: options.length });

    // The boxes are the reason the filter exempts packaging: they are hidden at the hub but travel
    // with cakes. If the exemption ever regresses, "With box?" loses its options silently.
    const cakeRows = beforeRows.filter((r) => r.category === 'cake');
    const boxRows = beforeRows.filter((r) => r.category === 'other' && /box/i.test(r.name));
    note({ step: 'packaging', cakes: cakeRows.length, boxes: boxRows.length });
    if (cakeRows.length > 0) {
        expect(boxRows.length, 'a cake row must still have box options to choose from').toBeGreaterThan(0);
    }

    // ---- product search, same behaviour as the cashier modal -------------------------------
    // Owner 2026-11-19: "add the product search in the PIC modal now ... same as the cashier modal
    // search". The cashier rule is lowercase `name + ' ' + sku` contains the query.
    const searchBox = page.locator('#dispatch-product-search');
    await expect(searchBox, 'the modal must offer a product search').toBeVisible({ timeout: 10000 });

    const baseline = await visibleRows(page);
    expect(baseline.length, 'there must be rows to search through').toBeGreaterThan(1);

    const target = baseline[Math.floor(baseline.length / 2)];
    const term = target.name.trim().toLowerCase().split(/\s+/)[0];
    await searchBox.fill(term);
    await page.waitForTimeout(150);
    const searched = await visibleRows(page);
    note({ step: 'search', term, before: baseline.length, after: searched.length });

    expect(searched.length, 'typing must narrow the list').toBeLessThan(baseline.length);
    const nonMatching = searched.filter((r) => !`${r.name} ${r.id}`.toLowerCase().includes(term));
    expect(nonMatching, `every visible row must match "${term}"`).toHaveLength(0);

    // A search must never resurrect a row the destination filter hid: the results have to be a SUBSET
    // of what was visible before typing. Two filters writing `display` from different places is exactly
    // how a hidden product reappears mid-search.
    const baselineIds = new Set(baseline.map((r) => String(r.id)));
    const resurrected = searched.filter((r) => !baselineIds.has(String(r.id)));
    expect(resurrected, 'a search must not show a product the destination does not carry')
        .toHaveLength(0);

    // Nothing matches: say so, with the term, exactly as the cashier modal does.
    await searchBox.fill('zzzz-no-such-product');
    await page.waitForTimeout(150);
    expect(await visibleRows(page), 'a nonsense search must show no product rows').toHaveLength(0);
    await expect(page.locator('#dispatch-search-empty')).toBeVisible();
    await expect(page.locator('#dispatch-search-empty-term')).toHaveText('zzzz-no-such-product');

    // Escape clears the term and the list comes back.
    await searchBox.press('Escape');
    await page.waitForTimeout(150);
    const restored = await visibleRows(page);
    note({ step: 'search-escape', restored: restored.length, baseline: baseline.length });
    expect(restored.length, 'Escape must restore the full destination-filtered list').toBe(baseline.length);
    await expect(searchBox).toHaveValue('');

    // Evidence for the UI half: the search must sit with the rows it filters, not float somewhere else.
    await searchBox.fill(term);
    await page.waitForTimeout(150);
    const searchBoxRect = await searchBox.boundingBox();
    const tableRect = await page.locator('#dispatch-panel table').first().boundingBox();
    note({ step: 'layout', searchBox: searchBoxRect, table: tableRect });
    expect(searchBoxRect.y, 'the search must sit above the table it filters')
        .toBeLessThan(tableRect.y);
    expect(Math.abs(searchBoxRect.x - tableRect.x),
        'the search must share the table\'s left edge, not be inset from it').toBeLessThan(4);
    await page.locator('#dispatch-panel').screenshot({ path: 'test-results/dispatch-search.png' });
});
