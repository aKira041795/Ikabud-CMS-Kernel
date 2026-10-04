// @ts-check
/**
 * Daily Ledger — Daily Production Sheet column/cell uniformity.
 *
 * Measures the RENDERED geometry of the sheet table under normal screen media: every
 * column's width, every cell's padding, every entry control's box, and whether a control
 * overflows its own column's content box. Cells are judged uniform from numbers, not by eye.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-sheet-columns.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

// This spec logs in, then measures a 178-row / 2.5MB sheet several times and mutates the DOM
// twice to prove the columns react. Playwright's 30s default is too tight for that workload and
// made the acceptance gate itself flaky, so the budget is explicit here.
test.setTimeout(180000);

const PRODUCTION = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };

async function login(page, account) {
    await page.goto('/daily-ledger/login');
    await page.fill('input[name="username"]', account.username);
    await page.fill('input[name="full_name"]', account.fullName);
    await page.fill('input[name="password"]', account.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 25000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
}

async function measure(page) {
    return page.evaluate(() => {
        const table = document.querySelector('#tab-daily-sheet table');
        if (!table) return null;
        const headCells = Array.from(table.querySelectorAll('thead th'));
        const firstRow = table.querySelector('tbody tr.daily-sheet-product-row');
        const bodyCells = firstRow ? Array.from(firstRow.children) : [];

        const cellInfo = (el, i) => {
            const s = getComputedStyle(el);
            const padL = parseFloat(s.paddingLeft) || 0;
            const padR = parseFloat(s.paddingRight) || 0;
            const control = el.querySelector('input, button');
            let controlBox = null;
            let overflows = null;
            if (control) {
                const cs = getComputedStyle(control);
                controlBox = {
                    tag: control.tagName.toLowerCase(),
                    cls: (control.className || '').toString().split(/\s+/).filter((c) => /^(w-|form-input|ledger-trigger|production-)/.test(c)).join(' '),
                    w: Math.round(control.getBoundingClientRect().width * 10) / 10,
                    h: Math.round(control.getBoundingClientRect().height * 10) / 10,
                    marginLeft: cs.marginLeft,
                    boxSizing: cs.boxSizing,
                };
                // The room the cell actually offers, minus its horizontal padding.
                const contentBox = el.clientWidth - padL - padR;
                overflows = controlBox.w > contentBox + 0.5;
            }
            return {
                i,
                header: headCells[i] ? (headCells[i].textContent || '').replace(/\s+/g, ' ').trim() : null,
                w: Math.round(el.getBoundingClientRect().width * 10) / 10,
                declaredCss: s.width,
                padL, padR,
                padCss: `${s.paddingLeft} ${s.paddingRight}`,
                control: controlBox,
                overflows,
            };
        };

        return {
            columns: bodyCells.length,
            headerCells: headCells.length,
            head: headCells.map((el, i) => cellInfo(el, i)),
            body: bodyCells.map((el, i) => cellInfo(el, i)),
        };
    });
}

test('Daily Production Sheet columns and cells are uniform', async ({ page }) => {
    await login(page, PRODUCTION);
    await page.goto('/daily-ledger/admin/commissary');
    await page.waitForLoadState('networkidle');

    const m = await measure(page);
    expect(m, 'the Daily Sheet table must exist').not.toBeNull();
    console.log('SHEET_GEOMETRY ' + JSON.stringify(m));
    await page.screenshot({ path: '/tmp/sheet-columns-before.png', clip: { x: 260, y: 260, width: 1400, height: 420 } });

    // ── Uniformity contract, measured from the rendered geometry ────────────────────
    // Column 0 is the product name; every column after it is a numeric/entry column and
    // must share one width so the sheet has a rhythm.
    const numeric = m.body.slice(1);
    const controls = m.body.filter((c) => c.control);

    // 1. No entry control may be wider than the room its own column offers. Today three
    //    are (BEG 80 in 62, ADDTL 80 in 72, ACTUAL 96 in 88), which is why the declared
    //    column widths never hold and the cells drift apart.
    const overflowing = m.body.filter((c) => c.overflows).map((c) => `${c.header}: ${c.control.w}px in ${Math.round(c.w - c.padL - c.padR)}px`);
    expect(overflowing, 'no entry control may overflow its column content box').toEqual([]);

    // 2. Every cell shares ONE padding. The 10 branch cells currently use 2/2 while every
    //    other cell uses 6/6, so their contents sit 4px differently from their neighbours.
    const paddings = [...new Set(m.body.map((c) => `${c.padL}/${c.padR}`))];
    expect(paddings, 'all sheet cells must share one horizontal padding').toHaveLength(1);
    expect(m.body[0].padL, 'the product column must use the same padding as the rest').toBe(m.body[1].padL);

    // 3. Every entry control is the SAME box. Today there are four widths (80/80/52x10/96)
    //    and two heights (39 inputs vs 34 triggers).
    const controlWidths = [...new Set(controls.map((c) => Math.round(c.control.w)))];
    const controlHeights = [...new Set(controls.map((c) => Math.round(c.control.h)))];
    expect(controlWidths, 'all entry controls must be one width').toHaveLength(1);
    expect(controlHeights, 'all entry controls must be one height').toHaveLength(1);

    // 4. One column width for every numeric/entry column. Today: 74, 84, 82x10, 58, 100.
    const numericWidths = numeric.map((c) => Math.round(c.w));
    expect(
        Math.max(...numericWidths) - Math.min(...numericWidths),
        `numeric columns must share one width, got ${JSON.stringify(numericWidths)}`,
    ).toBeLessThanOrEqual(1);

    // 5. The product column keeps its measured 200px floor (long tail: 246px longest name,
    //    10 of 174 over 200px) and is the only column allowed to differ.
    expect(m.body[0].w, 'the product column must keep at least 200px').toBeGreaterThanOrEqual(200);

    // 5b. Guard the risk that a fixed table layout introduces: the product column holds the
    //     longest text on the sheet (longest name 246px), so it must keep wrapping inside its
    //     cell instead of spilling over the BEG column.
    const productOverflow = await page.evaluate(() => {
        const cells = Array.from(document.querySelectorAll('#tab-daily-sheet tbody tr.daily-sheet-product-row > td:nth-child(1)'));
        const bad = cells
            .map((c) => ({
                text: (c.textContent || '').trim().slice(0, 40),
                clientWidth: c.clientWidth,
                scrollWidth: c.scrollWidth,
                whiteSpace: getComputedStyle(c).whiteSpace,
                overflowWrap: getComputedStyle(c).overflowWrap,
            }))
            .filter((r) => r.scrollWidth > r.clientWidth + 1);
        return { count: bad.length, total: cells.length, worst: bad.slice(0, 3) };
    });
    expect(
        productOverflow.count,
        `the product-name column must not spill out of its cells: ${JSON.stringify(productOverflow)}`,
    ).toBe(0);

    // ── 6. DYNAMIC WIDTHS: adding branch columns must AUTO-ADJUST ─────────────────────
    // The numeric cells are not a fixed width. When a branch is added the numeric columns must
    // share the available space and get NARROWER; they must not simply push the sheet wider
    // (which is what fixed per-column widths do) nor stay put and overflow.
    const baselineNumeric = Math.round(m.body[1].w);

    const injectBranchColumns = (extra) => page.evaluate((n) => {
        const table = document.querySelector('#tab-daily-sheet table');
        const rows = Array.from(table.querySelectorAll('thead tr, tbody tr.daily-sheet-product-row'));
        for (const tr of rows) {
            const cells = Array.from(tr.children);
            const template = cells[3]; // first branch column
            if (!template) continue;
            for (let i = 0; i < n; i++) {
                const clone = template.cloneNode(true);
                clone.setAttribute('data-cloned-test-column', '1');
                template.parentNode.insertBefore(clone, template.nextSibling);
            }
        }
    }, extra);

    await injectBranchColumns(3);
    const grown = await measure(page);
    const grownNumeric = Math.round(grown.body[1].w);

    expect(
        grownNumeric,
        `adding 3 branch columns must make the numeric columns NARROWER (before ${baselineNumeric}px, after ${grownNumeric}px)`,
    ).toBeLessThan(baselineNumeric);

    const grownWidths = grown.body.slice(1).map((c) => Math.round(c.w));
    expect(
        Math.max(...grownWidths) - Math.min(...grownWidths),
        `numeric columns must STAY uniform after extra columns are added, got ${JSON.stringify(grownWidths)}`,
    ).toBeLessThanOrEqual(1);

    const grownOverflow = grown.body.filter((c) => c.overflows).map((c) => c.header);
    expect(grownOverflow, 'no control may overflow once extra columns are added').toEqual([]);

    expect(
        grown.body[0].w,
        'the product column must not be squeezed when branches are added',
    ).toBeGreaterThanOrEqual(200);

    // 7. FLOOR: past a point the sheet must SCROLL rather than crush an entry control to an
    //    unusable size. This is the counterweight that stops "dynamic" from meaning "unreadable".
    await injectBranchColumns(20);
    const crushed = await measure(page);
    const crushedControlWidths = crushed.body.filter((c) => c.control).map((c) => c.control.w);
    expect(
        Math.min(...crushedControlWidths),
        `an entry control must not be crushed below 48px (got ${Math.min(...crushedControlWidths)}px)`,
    ).toBeGreaterThanOrEqual(48);
    const scroll = await page.evaluate(() => {
        const wrap = document.querySelector('#tab-daily-sheet .table-wrap');
        return { clientWidth: wrap.clientWidth, scrollWidth: wrap.scrollWidth };
    });
    expect(
        scroll.scrollWidth,
        'once the floor is reached the wrapper must scroll horizontally rather than crush cells',
    ).toBeGreaterThan(scroll.clientWidth);
});
