// @ts-check
/**
 * Consignee dispatch journey: a dispatch recorded at the branch must reach the Consignee
 * Dispatch Report, and the report must be narrowable by consignee, product and shift.
 *
 * Asserts the OUTCOME an admin sees, not the mechanism:
 *   1. a range with no dispatches reports ZERO lines (the negative control — without it a
 *      "row is present" assertion could pass on a report that renders rows unconditionally)
 *   2. a real dispatch to a consignee is accepted by the server
 *   3. the report shows the DR, the consignee, the product, the quantity and a DISPATCH
 *      value equal to quantity x unit price, with spoilage and the still-collectible figure
 *      reported alongside it (a ledger that proves deliveries AND pullouts shows both)
 *   4. it shows the DR exactly ONCE (consignee dispatch is exactly-once: a repeated save
 *      replays rather than double-crediting)
 *   5. the Consignee / Product filters keep the line; Shift=PM REMOVES it (a filter that
 *      cannot exclude is not a filter); Clear restores the full range
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-consignee-dispatch-flow.spec.js --reporter=line
 *
 * The DR is FIXED so the spec is idempotent: the first run creates the dispatch and every
 * later run replays it. That is the app's own guarantee (apiCreateCashierDispatch returns
 * {ok:true, replayed:true} for a consignee DR that already exists), so re-running neither
 * grows the ledger nor needs a cleanup step.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(180000);

const EVIDENCE = '/tmp/consignee-dispatch-flow-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const DR = 'E2E-CDR-FLOW-0001';
const CONSIGNEE_KEY = 'consignee:99750';
const CONSIGNEE_NAME = 'Lee Plaza';
const QTY = 2;
// A range no dispatch can plausibly occupy — the control that proves the report can be empty.
const EMPTY_FROM = '2020-01-01';
const EMPTY_TO = '2020-01-02';

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

async function openReport(page, query) {
    await page.goto('/daily-ledger/admin/consignee-dispatch-report' + (query || ''), { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#consignee-dispatch-report-table', { timeout: 60000 });
    // The count is server-rendered; reading it proves the page settled, not just the skeleton.
    await page.waitForSelector('#consignee-dispatch-count', { timeout: 30000 });
}

/** Body rows, whitespace-collapsed. The empty state is itself a row, so this is never length 0. */
const bodyRows = (page) => page.$$eval('#consignee-dispatch-report-table tbody tr',
    (rows) => rows.map((r) => r.innerText.replace(/\s+/g, ' ').trim()));

/**
 * Header labels in rendered order. Columns are resolved BY NAME, never by a frozen offset: the
 * valuation was split into gross / spoilage / still-collectible, and an offset would silently
 * read the wrong one.
 */
const headers = (page) => page.$$eval('#consignee-dispatch-report-table thead th',
    (ths) => ths.map((th) => th.innerText.replace(/\s+/g, ' ').trim()));

const countText = (page) => page.locator('#consignee-dispatch-count').innerText();

/** How many body rows mention this DR. 1 = present exactly once, 0 = absent. */
const rowsForDr = (rows, dr) => rows.filter((t) => t.includes(dr));

const isoDate = (d) => d.toISOString().slice(0, 10);

test('a consignee dispatch reaches the report, and the report filters can narrow it', async ({ page }) => {
    fs.writeFileSync(EVIDENCE, '');

    await login(page);

    // ---- 1. CONTROL: an empty range must report zero lines ------------------------------
    await openReport(page, `?date_from=${EMPTY_FROM}&date_to=${EMPTY_TO}`);
    const emptyRows = await bodyRows(page);
    const emptyCount = await countText(page);
    note({ step: 'control-empty-range', rows: emptyRows, count: emptyCount });
    expect(emptyRows.length, 'an empty range must render exactly one row: the empty state').toBe(1);
    expect(emptyRows[0], 'the empty range must say it has nothing').toMatch(/No recorded consignee dispatches/i);
    expect(emptyCount, 'the count must read zero, not merely lack rows').toMatch(/^0 line/);

    // ---- 2. run a real dispatch: branch -> consignee ------------------------------------
    await page.goto('/daily-ledger/ledger', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[onclick="openDispatchModal()"]', { timeout: 60000 });
    const ctx = await page.evaluate(() => ({
        branchId: window.BRANCH_ID, shift: window.SHIFT, date: window.LEDGER_DATE, dayStatus: window.DAY_STATUS,
    }));
    note({ step: 'ledger-context', ...ctx });
    expect(ctx.dayStatus, 'the day must be open to accept a dispatch').toBe('open');

    await page.click('[onclick="openDispatchModal()"]');
    const modal = page.locator('[x-data="dispatchModal()"]');
    await expect(modal.locator('select[x-model="form.destination_key"]')).toBeVisible({ timeout: 30000 });

    // The consignee must be offered as a destination at all — that is the UI half of the flow.
    const destOptions = await modal.locator('select[x-model="form.destination_key"] option').evaluateAll(
        (os) => os.map((o) => o.value));
    note({ step: 'destinations', options: destOptions });
    expect(destOptions, 'the consignee must be selectable as a destination').toContain(CONSIGNEE_KEY);

    // Pick a product the ledger can actually dispatch.
    const productSelect = modal.locator('select[x-model="line.product_id"]').first();
    const productOpts = await productSelect.locator('option').evaluateAll(
        (os) => os.map((o) => ({ value: o.value, label: o.textContent.trim() })).filter((o) => o.value !== ''));
    expect(productOpts.length, 'the dispatch modal must offer at least one product').toBeGreaterThan(0);
    const product = productOpts[0];
    note({ step: 'chosen-product', product });

    await modal.locator('input[x-model="form.dr_number"]').fill(DR);
    await modal.locator('select[x-model="form.destination_key"]').selectOption(CONSIGNEE_KEY);
    await productSelect.selectOption(product.value);
    await modal.locator('input[x-model="line.quantity"]').first().fill(String(QTY));

    const saving = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/api/v1/cashier/ledger/dispatch'),
        { timeout: 60000 },
    );
    await modal.getByRole('button', { name: /Save Transfer Out/i }).click();
    const res = await saving;
    const raw = await res.text().catch(() => '');
    await expect(res.status(), 'the dispatch must be accepted').toBe(200);
    note({ step: 'dispatch', status: res.status(), method: res.request().method(), raw: raw.slice(0, 400) });

    // The body is NOT asserted here. finish_response_if_possible() flushes the response and
    // closes the connection early (a deliberate 21x latency win), so the JSON can arrive as an
    // empty body through the automation channel even though the browser parses it fine. Asserting
    // it would report a product failure for a transport artefact.
    //
    // The modal closing IS the outcome proof: dispatchModal().saveChanges() calls closeModal()
    // only inside `if (data && data.ok)`, so a closed modal means the client received and parsed
    // ok:true. A rejection leaves the modal open with errorMsg set, which is read out below.
    try {
        await expect(modal.locator('select[x-model="form.destination_key"]')).toBeHidden({ timeout: 30000 });
    } catch (e) {
        const shown = await modal.locator('[x-text="errorMsg"]').first().innerText().catch(() => '');
        note({ step: 'dispatch-modal-stayed-open', errorMsg: shown });
        throw new Error(`the dispatch modal did not close, so the save was not accepted. On-screen error: ${JSON.stringify(shown)}`);
    }
    note({ step: 'dispatch-accepted', modalClosed: true });

    // ---- 3+4. the report must show it, once, correctly valued ---------------------------
    const today = ctx.date || isoDate(new Date());
    await openReport(page, `?date_from=${today}&date_to=${today}`);
    const rows = await bodyRows(page);
    const mine = rowsForDr(rows, DR);
    note({ step: 'report-after-dispatch', date: today, rows, count: await countText(page), matched: mine.length });

    expect(mine.length, `the report must show the dispatched DR ${DR} exactly once`).toBe(1);
    const line = mine[0];
    expect(line, 'the row must name the consignee').toContain(CONSIGNEE_NAME);
    expect(line, 'the row must carry the dispatched quantity').toMatch(new RegExp(`\\b${QTY}\\b`));
    expect(line, 'the row must show the DR in the verification column').toContain(DR);

    // The valuation claim is computed from the cells ACTUALLY RENDERED rather than from a
    // hard-coded figure, and the columns are resolved by header name (see headers()).
    const heads = await headers(page);
    const at = (re) => heads.findIndex((h) => re.test(h));
    const COL = {
        qty: at(/^Quantity$/i), ret: at(/Returns\s*\/\s*Pullout/i), price: at(/^Unit price/i),
        gross: at(/^Dispatch value$/i), spoil: at(/Spoilage value/i), net: at(/^Still collectible$/i),
    };
    note({ step: 'report-columns', heads, COL });
    for (const [name, idx] of Object.entries(COL)) {
        expect(idx, `the report must render a "${name}" column`).toBeGreaterThanOrEqual(0);
    }
    expect(COL.ret, 'the Returns column must follow Quantity').toBe(COL.qty + 1);
    expect(COL.gross, 'the gross dispatch value must follow the unit price').toBeGreaterThan(COL.price);
    expect(COL.spoil, 'spoilage must be shown beside the dispatch value').toBeGreaterThan(COL.gross);
    expect(COL.net, 'the still-collectible figure must be shown').toBeGreaterThan(COL.spoil);

    const cells = await page.$$eval('#consignee-dispatch-report-table tbody tr', (rs, cols) => rs.map((r) => {
        const t = r.innerText.replace(/\s+/g, ' ').trim();
        if (!t.includes('E2E-CDR-FLOW')) return null;
        const tds = Array.from(r.querySelectorAll('td'));
        const returnedInput = tds[cols.ret].querySelector('input[type="number"]');
        const head = (i) => tds[i].innerText.trim().split(/\s|,/)[0];
        return {
            returned: returnedInput ? returnedInput.value : tds[cols.ret].innerText.trim(),
            qty: head(cols.qty), price: head(cols.price),
            gross: head(cols.gross), spoil: head(cols.spoil), net: head(cols.net),
        };
    }).filter(Boolean), COL);
    note({ step: 'valuation-cells', cells });
    expect(cells.length, 'the DR row must be parseable into cells').toBe(1);
    const num = (s) => Number(String(s).replace(/[^0-9.\-]/g, ''));
    expect(num(cells[0].gross), 'the GROSS dispatch value must be the dispatched quantity x unit price')
        .toBeCloseTo(num(cells[0].qty) * num(cells[0].price), 2);
    expect(num(cells[0].spoil), 'spoilage must be the returned quantity x unit price')
        .toBeCloseTo(num(cells[0].returned) * num(cells[0].price), 2);
    expect(num(cells[0].net), 'the still-collectible figure must be (quantity - returned) x unit price')
        .toBeCloseTo((num(cells[0].qty) - num(cells[0].returned)) * num(cells[0].price), 2);

    // ---- 5. an entered return persists and moves value from dispatched to spoiled --------
    const reportRow = page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DR }).first();
    const returnInput = reportRow.locator('input[type="number"]');
    await expect(returnInput, 'an admin must be able to enter a return after Quantity').toBeVisible();
    const returnedBefore = Number(await returnInput.inputValue());
    const returnTarget = returnedBefore + 1;
    await returnInput.fill(String(returnTarget));
    const returnSave = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/deliveries/consignee-return'),
        { timeout: 60000 },
    );
    await reportRow.getByRole('button', { name: /Save/i }).click();
    const returnResponse = await returnSave;
    expect(returnResponse.status(), 'the return must be accepted').toBe(200);
    await openReport(page, `?date_from=${today}&date_to=${today}`);
    const afterRow = page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DR }).first();
    const returnedAfter = Number(await afterRow.locator('input[type="number"]').inputValue());
    const cellAt = async (i) => num(await afterRow.locator('td').nth(i).innerText());
    const grossAfter = await cellAt(COL.gross);
    const spoilAfter = await cellAt(COL.spoil);
    const netAfter = await cellAt(COL.net);
    note({ step: 'after-return', returnedAfter, grossAfter, spoilAfter, netAfter, before: cells[0] });
    expect(returnedAfter, 'the entered return must be echoed after reload').toBe(returnTarget);
    expect(netAfter, 'one returned piece must remove exactly one snapshot price from the collectible figure')
        .toBeCloseTo(num(cells[0].net) - num(cells[0].price), 2);
    expect(spoilAfter, 'that same piece must appear in the spoilage figure')
        .toBeCloseTo(num(cells[0].spoil) + num(cells[0].price), 2);
    expect(grossAfter, 'the GROSS dispatch value must not move — it is the delivery evidence')
        .toBeCloseTo(num(cells[0].gross), 2);

    // Restore the total; the effect history remains append-only.
    const restoreRow = page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DR }).first();
    await restoreRow.locator('input[type="number"]').fill(String(returnedBefore));
    const restoreSave = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/deliveries/consignee-return'),
        { timeout: 60000 },
    );
    await restoreRow.getByRole('button', { name: /Save/i }).click();
    await restoreSave;
    await openReport(page, `?date_from=${today}&date_to=${today}`);
    expect(Number(await page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DR })
        .first().locator('input[type="number"]').inputValue()), 'the return total must restore cleanly').toBe(returnedBefore);

    // ---- 6. the filters must narrow, and must be able to EXCLUDE ------------------------
    const range = `date_from=${today}&date_to=${today}`;

    await openReport(page, `?${range}&consignee_id=99750`);
    expect(rowsForDr(await bodyRows(page), DR).length, 'filtering by the consignee must keep the line').toBe(1);

    await openReport(page, `?${range}&product_id=${product.value}`);
    expect(rowsForDr(await bodyRows(page), DR).length, 'filtering by the product must keep the line').toBe(1);

    await openReport(page, `?${range}&shift=AM`);
    expect(rowsForDr(await bodyRows(page), DR).length, 'the AM dispatch must survive a Shift=AM filter').toBe(1);

    await openReport(page, `?${range}&shift=PM`);
    const pmRows = await bodyRows(page);
    note({ step: 'filter-shift-pm', rows: pmRows, count: await countText(page) });
    expect(rowsForDr(pmRows, DR).length,
        'a Shift=PM filter must EXCLUDE an AM dispatch — a filter that cannot exclude is not a filter').toBe(0);

    // An applied filter must offer a way back, and Clear must restore the line.
    await openReport(page, `?${range}&shift=PM`);
    const clear = page.locator('#cdr-clear-filters');
    await expect(clear, 'an applied filter must offer Clear').toHaveCount(1);
    await clear.click();
    await page.waitForSelector('#consignee-dispatch-report-table', { timeout: 60000 });
    expect(rowsForDr(await bodyRows(page), DR).length, 'Clear must restore the unfiltered range').toBe(1);

    // The selected filter must survive the round trip in the control itself.
    await openReport(page, `?${range}&shift=AM&consignee_id=99750`);
    const echoed = await page.evaluate(() => ({
        shift: document.querySelector('#cdr-shift').value,
        consignee: document.querySelector('#cdr-consignee').value,
    }));
    note({ step: 'filter-echo', echoed });
    expect(echoed.shift, 'the applied shift must be echoed in the control').toBe('AM');
    expect(echoed.consignee, 'the applied consignee must be echoed in the control').toBe('99750');

    console.log('CONSIGNEE_DISPATCH_FLOW ' + JSON.stringify({ dr: DR, date: today, product, qty: QTY }));
});
