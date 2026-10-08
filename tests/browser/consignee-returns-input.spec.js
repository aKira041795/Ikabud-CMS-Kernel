// @ts-check
/**
 * Consignee Dispatch Report — the admin enters returns/pullouts on the report itself.
 *
 * This is the BROWSER half of the acceptance criterion: the column must exist in the RENDERED
 * page, sit AFTER Quantity, be an input an admin can type into, and a value typed into it must
 * persist and reduce what the report says is collectible.
 *
 * It fails on the base tree (there is no such column) and passes once the column exists, so it
 * discriminates rather than merely guarding.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/consignee-returns-input.spec.js --reporter=line
 *
 * The spec restores the return to 0 at the end so the ledger is left as it was found. The
 * append-only effect rows it creates are history and are deliberately not deleted.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(180000);

const EVIDENCE = '/tmp/consignee-returns-input-evidence.jsonl';
const note = (o) => fs.appendFileSync(EVIDENCE, JSON.stringify(o) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
// 2026-10-08 AM carries a dispatched consignee line whose shift is OPEN, so a return is allowed.
const DATE = '2026-10-08';

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

async function openReport(page) {
    await page.goto(`/daily-ledger/admin/consignee-dispatch-report?date_from=${DATE}&date_to=${DATE}`,
        { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#consignee-dispatch-report-table', { timeout: 60000 });
    await page.waitForSelector('#consignee-dispatch-count', { timeout: 30000 });
}

/** Header labels in rendered order — the column ORDER is part of the requirement. */
const headers = (page) => page.$$eval('#consignee-dispatch-report-table thead th',
    (ths) => ths.map((th) => th.innerText.replace(/\s+/g, ' ').trim()));

/** The row whose first cell is the fixture date, as collapsed text. */
const rows = (page) => page.$$eval('#consignee-dispatch-report-table tbody tr',
    (rs) => rs.map((r) => r.innerText.replace(/\s+/g, ' ').trim()));

const returnedInput = (page) => page.locator('#consignee-dispatch-report-table tbody input[type="number"]').first();

test('an admin can enter a consignee return on the report, after the Quantity column', async ({ page }) => {
    fs.writeFileSync(EVIDENCE, '');
    await login(page);
    await openReport(page);

    const before = await headers(page);
    const rowsBefore = await rows(page);
    note({ step: 'headers', before, rows: rowsBefore });

    // ---- the columns exist, in the required order -------------------------------------
    // Resolved BY HEADER NAME, not by a frozen offset: the valuation was split into gross /
    // spoilage / still-collectible, and a hard-coded offset would silently read the wrong one.
    const qtyAt = before.findIndex((h) => /^Quantity$/i.test(h));
    const retAt = before.findIndex((h) => /Returns\s*\/\s*Pullout/i.test(h));
    const unitAt = before.findIndex((h) => /^Unit price/i.test(h));
    const grossAt = before.findIndex((h) => /^Dispatch value$/i.test(h));
    const spoilAt = before.findIndex((h) => /Spoilage value/i.test(h));
    const netAt = before.findIndex((h) => /^Still collectible$/i.test(h));
    note({ step: 'column-positions', qtyAt, retAt, unitAt, grossAt, spoilAt, netAt });
    expect(qtyAt, 'the report must still have a Quantity column').toBeGreaterThanOrEqual(0);
    expect(retAt, 'the report must offer a Returns / Pullout column').toBeGreaterThanOrEqual(0);
    expect(retAt, 'the Returns / Pullout column must come AFTER Quantity').toBe(qtyAt + 1);
    expect(unitAt, 'the unit price must follow the return column').toBeGreaterThan(retAt);
    expect(grossAt, 'the GROSS dispatched value must still be shown (the delivery evidence)')
        .toBeGreaterThan(unitAt);
    expect(spoilAt, 'the written-off spoilage value must be shown beside the delivery value')
        .toBeGreaterThan(grossAt);
    expect(netAt, 'the still-collectible figure must be shown').toBeGreaterThan(spoilAt);

    // ---- the fixture line is on screen, so the assertion below cannot pass vacuously -----
    const lineRow = page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DATE }).first();
    await expect(lineRow, `the fixture line for ${DATE} must be present`).toBeVisible();

    // ---- it is an INPUT an admin can use ------------------------------------------------
    const input = returnedInput(page);
    await expect(input, 'the Returns / Pullout cell must be a numeric input').toBeVisible();
    const startReturned = Number(await input.inputValue());
    note({ step: 'input-initial', startReturned });
    expect(Number.isFinite(startReturned), 'the input must carry a numeric value').toBe(true);

    // The values before the change, so each movement can be proven rather than assumed.
    const cellNumber = async (at) => {
        const tds = await lineRow.locator('td').allInnerTexts();
        return Number(String(tds[at] || '').replace(/[^0-9.\-]/g, ''));
    };
    const netBefore = await cellNumber(netAt);
    const grossBefore = await cellNumber(grossAt);
    const spoilBefore = await cellNumber(spoilAt);
    note({ step: 'value-before', netBefore, grossBefore, spoilBefore });

    // ---- entering a return persists and reduces the reported value ----------------------
    const target = startReturned + 1;
    await input.fill(String(target));
    const saving = page.waitForResponse(
        (r) => r.request().method() === 'POST' && /consignee-return/i.test(r.url()),
        { timeout: 60000 },
    );
    await lineRow.getByRole('button', { name: /save/i }).first().click();
    const res = await saving;
    note({ step: 'save', status: res.status(), target });

    await openReport(page);
    const rowsAfter = await rows(page);
    const afterReturned = Number(await returnedInput(page).inputValue());
    const netAfter = await cellNumber(netAt);
    const grossAfter = await cellNumber(grossAt);
    const spoilAfter = await cellNumber(spoilAt);
    // One extra returned piece moves exactly one unit price from "dispatched" to "spoiled". The
    // unit price is unchanged, so the collectible figure drops by one unit price - while the
    // GROSS dispatch value must NOT move, because it is the proof the delivery happened.
    const unitPrice = await cellNumber(unitAt);
    note({ step: 'after-save', rows: rowsAfter, afterReturned, netBefore, netAfter, grossBefore, grossAfter, spoilBefore, spoilAfter, unitPrice });

    expect(afterReturned, 'the entered return must be echoed back on reload').toBe(target);
    expect(netAfter, 'one more returned piece must reduce the collectible figure by exactly one unit price')
        .toBeCloseTo(netBefore - unitPrice, 2);
    expect(spoilAfter, 'the spoilage (written off) value must rise by exactly one unit price')
        .toBeCloseTo(spoilBefore + unitPrice, 2);
    expect(grossAfter, 'the GROSS dispatch value must not move - netting it would destroy the delivery evidence')
        .toBeCloseTo(grossBefore, 2);

    // ---- restore: set the return back to its original figure ---------------------------
    await openReport(page);
    const restoreInput = returnedInput(page);
    await restoreInput.fill(String(startReturned));
    const restoring = page.waitForResponse(
        (r) => r.request().method() === 'POST' && /consignee-return/i.test(r.url()),
        { timeout: 60000 },
    );
    await page.locator('#consignee-dispatch-report-table tbody tr', { hasText: DATE }).first()
        .getByRole('button', { name: /save/i }).first().click();
    await restoring;
    await openReport(page);
    const finalReturned = Number(await returnedInput(page).inputValue());
    note({ step: 'restored', finalReturned });
    expect(finalReturned, 'the spec must leave the return as it found it').toBe(startReturned);

    console.log('CONSIGNEE_RETURNS_INPUT ' + JSON.stringify({ qtyAt, retAt, unitAt, grossAt, spoilAt, netAt, startReturned, target, netBefore, netAfter }));
});
