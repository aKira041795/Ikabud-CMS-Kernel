// @ts-check
/**
 * The Daily Sheet's destination filter is a VIEW switch, never a data switch.
 *
 * Owner (2026-10-08): "at consignees daily sheet, the consignee name is a row, it must also be the same as
 * in branches, columned and vertically set."
 * Client (2026-10-09), relaying the same requirement from the operator's side:
 *   "consignees beginning and additional have same data with branches because it is just a continuation of
 *    the branches sheet, it's just that it is set in a different tab for UI/UX. Total and balance therefore
 *    is just the full process of the commissary daily sheet if it's one continuous sheet."
 *
 * So there is ONE sheet and ONE row model. `destination=branches|consignees|all` decides only WHICH
 * destination columns are itemised; BEG, ADDTL, TOTAL and ACTUAL BAL are the same numbers on every filter,
 * and TOTAL/ACTUAL BAL cover the whole sheet (branch AND consignee departures) — not the sum of the columns
 * a particular filter happens to be showing.
 *
 * Before `c897db16` the Consignees tab was a CUSTODY ledger: each consignee was a ROW carrying its own
 * BEG/ADDTL, and TOTAL was that filter's own column sum. This spec exists so that shape cannot come back
 * silently — it is the client's requirement written as an executable oracle.
 *
 * WHAT MAKES THIS NON-VACUOUS
 * ---------------------------
 * Asserting "the three views agree" would also pass if the filter did nothing at all (three identical
 * renderings compared with themselves). So the spec first asserts the three views genuinely DIFFER — each
 * itemises a different destination column set — and only then that the four numeric columns are identical.
 * A filter that stopped filtering, or a view that started reading a different row model, fails one half or
 * the other; there is no single fault that makes both halves trivially true.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-destination-filter-shares-the-sheet.spec.js --reporter=line
 * Pin a bucket with activity (more rows are checked, and the equality is exercised on real numbers):
 *       SHEET_DATE=2026-10-08 SHEET_SHIFT=PM SHEET_COMMISSARY=18 APP_URL=http://baronledger.test npx playwright test ...
 *
 * Not covered here, deliberately: the sheet's product universe and where BEG/ADDTL are SOURCED from
 * (`dl_production_movements`, the carry-forward suggestion). Those are separate questions recorded in
 * docs/engineering/commissary-coherence-audit-2026-10-08.md §4, and neither is what this requirement is about.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const SHEET = '/daily-ledger/admin/commissary';

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

/** Optional bucket pin. Empty means "whatever the sheet opens on", which is the operator's own view. */
function query(destination) {
    const params = new URLSearchParams({ destination });
    if (process.env.SHEET_DATE) params.set('date', process.env.SHEET_DATE);
    if (process.env.SHEET_SHIFT) params.set('shift', process.env.SHEET_SHIFT);
    if (process.env.SHEET_COMMISSARY) params.set('commissary_id', process.env.SHEET_COMMISSARY);
    return `?${params.toString()}`;
}

/**
 * The four numbers the requirement is about, read per product, plus the destination column sets that prove
 * the three views are actually three different renderings.
 *
 * `data-suggestion` is read alongside `value` because BEG and ACTUAL BAL each carry a derived suggestion
 * (the carry, and the book balance). If the filter changed the SUGGESTION while leaving the recorded value
 * alone, the operator would see a different number on the consignee tab — so both are compared.
 */
async function readSheet(page, destination) {
    await page.goto(SHEET + query(destination), { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-ledger-table tbody tr.daily-sheet-product-row', { timeout: 60000 });
    return page.evaluate(() => {
        const text = (root, sel) => {
            const el = root.querySelector(sel);
            return el === null ? null : el.innerText.replace(/\s+/g, ' ').trim();
        };
        const input = (root, sel) => {
            const el = root.querySelector(sel);
            return el === null ? null : { value: el.value, suggestion: el.getAttribute('data-suggestion') };
        };
        const rows = Array.from(document.querySelectorAll('#production-ledger-table tbody tr.daily-sheet-product-row'))
            .map((tr) => ({
                productId: tr.getAttribute('data-product-id'),
                label: text(tr, 'td.dl-sheet-sticky-product'),
                beg: input(tr, 'td.dl-sheet-sticky-beg input.production-beg-input'),
                addtl: text(tr, 'span.production-addtl-value'),
                total: text(tr, 'td.production-total'),
                actualBal: input(tr, 'td.dl-sheet-sticky-actual input.production-actual-input'),
                variance: text(tr, 'td.production-variance'),
            }));
        // Destination columns are identified by their OWN data attributes, not by position.
        const heads = Array.from(document.querySelectorAll('#production-ledger-table thead th'));
        return {
            rows,
            branchColumns: heads.filter((th) => th.hasAttribute('data-branch-id')).map((th) => th.getAttribute('data-branch-id')),
            consigneeColumns: heads.filter((th) => th.hasAttribute('data-consignee-id')).map((th) => th.getAttribute('data-consignee-id')),
            labelledColumns: heads.filter((th) => th.hasAttribute('data-branch-id') || th.hasAttribute('data-consignee-id'))
                .map((th) => th.innerText.replace(/\s+/g, ' ').trim()),
        };
    });
}

/** Which of the four numbers differ between two views, as readable lines. Empty array means identical. */
function differences(left, right, leftName, rightName) {
    const out = [];
    const rightByProduct = new Map(right.rows.map((r) => [r.productId, r]));
    for (const l of left.rows) {
        const r = rightByProduct.get(l.productId);
        if (r === undefined) {
            out.push(`${rightName} is missing product ${l.productId} (${l.label}), which ${leftName} shows`);
            continue;
        }
        const pairs = [
            ['BEG', l.beg?.value, r.beg?.value],
            ['BEG suggestion', l.beg?.suggestion, r.beg?.suggestion],
            ['ADDTL', l.addtl, r.addtl],
            ['TOTAL', l.total, r.total],
            ['ACTUAL BAL', l.actualBal?.value, r.actualBal?.value],
            ['ACTUAL BAL suggestion', l.actualBal?.suggestion, r.actualBal?.suggestion],
        ];
        for (const [name, a, b] of pairs) {
            if (a !== b) out.push(`${l.label} (pid ${l.productId}): ${name} ${leftName}=${a} but ${rightName}=${b}`);
        }
    }
    if (right.rows.length !== left.rows.length) {
        out.push(`${rightName} renders ${right.rows.length} product row(s), ${leftName} renders ${left.rows.length}`);
    }
    return out;
}

test('the destination filter changes only the destination columns, never the sheet numbers', async ({ page }) => {
    await login(page);

    const branches = await readSheet(page, 'branches');

    // Feature gate, not a data gate: without the Consignees filter control there is nothing to compare.
    const consigneeFilter = page.locator('#production-destination-filter-consignees');
    if (await consigneeFilter.count() === 0) {
        test.skip(true, 'Consignees are disabled for this tenant: the Consignees view does not exist.');
    }

    const consignees = await readSheet(page, 'consignees');
    const all = await readSheet(page, 'all');

    // --- half 1: the three views must genuinely differ, or half 2 proves nothing ---------------------
    expect(branches.rows.length, 'the Branches view must render the sheet, not an empty state').toBeGreaterThan(0);
    expect(branches.branchColumns.length, 'the Branches view must itemise branch columns').toBeGreaterThan(0);
    expect(consignees.consigneeColumns.length, 'the Consignees view must itemise consignee columns').toBeGreaterThan(0);

    expect(branches.consigneeColumns, 'the Branches view must NOT itemise consignee columns').toEqual([]);
    expect(consignees.branchColumns, 'the Consignees view must NOT itemise branch columns').toEqual([]);
    expect(branches.branchColumns, 'the Branches and Consignees views must itemise different columns, or the '
        + 'comparison below is one page compared with itself').not.toEqual(consignees.consigneeColumns);

    expect(all.branchColumns, 'the All view must itemise the branch columns the Branches view shows')
        .toEqual(branches.branchColumns);
    expect(all.consigneeColumns, 'the All view must itemise the consignee columns the Consignees view shows')
        .toEqual(consignees.consigneeColumns);
    expect(all.labelledColumns, 'the All view is the one continuous sheet: branch columns then consignee columns')
        .toEqual([...branches.labelledColumns, ...consignees.labelledColumns]);

    // --- half 2: the requirement ---------------------------------------------------------------------
    const consigneeDiffs = differences(branches, consignees, 'Branches', 'Consignees');
    expect(consigneeDiffs, 'BEG, ADDTL, TOTAL and ACTUAL BAL must be the SAME data on the Consignees view as on '
        + 'the Branches view: the consignee tab is a continuation of the same sheet, not its own ledger')
        .toEqual([]);

    const allDiffs = differences(branches, all, 'Branches', 'All');
    expect(allDiffs, 'the All view must carry the same sheet numbers as the Branches view').toEqual([]);

    // Evidence, so a green run states what it actually compared instead of only that it did not fail. A
    // count of 0 means that number was compared STRUCTURALLY on an all-zero column, not that it is wrong:
    // the equality still holds across the three views, but the bucket carried no figure to disagree about.
    const nonZero = (pick) => branches.rows.filter((r) => Number(pick(r)) !== 0).length;
    console.log(`compared ${branches.rows.length} product row(s) across 3 views; non-zero `
        + `BEG ${nonZero((r) => r.beg?.value)} (suggestion ${nonZero((r) => r.beg?.suggestion)}), `
        + `ADDTL ${nonZero((r) => r.addtl)}, TOTAL ${nonZero((r) => r.total)}, `
        + `ACTUAL BAL ${nonZero((r) => r.actualBal?.value)} (suggestion ${nonZero((r) => r.actualBal?.suggestion)})`);
});
