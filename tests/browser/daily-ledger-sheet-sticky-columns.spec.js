// @ts-check
/**
 * Daily Production Sheet — the identifying columns must stay PINNED on a 10-inch tablet.
 *
 * THE PROBLEM (measured 2026-10-08): the sheet renders 15 columns and is 1348px wide
 * (`200px + 14 * 82px`), while the scroll container inside a 10" tablet viewport is only
 * ~494px. So the encoder scrolls sideways to reach the branch columns and loses sight of WHICH
 * product row they are typing into, and of that row's BEG and ADDTL figures.
 *
 * THE CRITERION is measured from the RENDERED page, in the states a real tablet produces: the
 * sheet actually scrolled sideways, with the pinned cells' geometry AND their stacking checked at
 * the pixel level (elementFromPoint), because a cell can be geometrically in the right place and
 * still be painted over by a column sliding beneath it.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-sheet-sticky-columns.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

// The sheet is 178 rows / ~2.5MB and this spec drives it at two viewports.
test.setTimeout(240000);

const PRODUCTION = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };

// Two real 10-inch tablet shapes. The portrait one is the tight case: its 800px viewport leaves a
// 494px scroll container, so the pinned columns are the difference between usable and not.
const TABLET_PORTRAIT = { width: 800, height: 1280 };
const TABLET_LANDSCAPE = { width: 1280, height: 800 };

// The pinned left group, in rendered order. Product keeps its measured 200px floor.
const LEFT = [
    { name: 'Product', selector: 'td:nth-child(1)' },
    { name: 'BEG', selector: 'td:nth-child(2)' },
    { name: 'ADDTL', selector: 'td:nth-child(3)' },
];

async function login(page) {
    await page.goto('/daily-ledger/login');
    await page.waitForSelector('input[name="username"]', { timeout: 30000 });
    await page.fill('input[name="username"]', PRODUCTION.username);
    await page.fill('input[name="full_name"]', PRODUCTION.fullName);
    await page.fill('input[name="password"]', PRODUCTION.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

async function openSheet(page) {
    // NOT waitForLoadState('networkidle'): the sheet polls and is ~2.5MB, so the network never
    // goes idle and that wait times the whole spec out. Anchor on the rendered rows instead.
    await page.goto('/daily-ledger/admin/commissary', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#tab-daily-sheet table', { timeout: 120000 });
    await page.waitForSelector('#tab-daily-sheet tbody tr.daily-sheet-product-row', { timeout: 120000 });
}

/**
 * Scroll the sheet sideways by a FRACTION of its overflow, then measure the pinned cells against
 * the scroll container and probe what is actually painted at each pinned column's margin.
 *
 * The fraction matters: at full-right scroll the LAST column is naturally on screen, so a
 * right-edge assertion there passes with no pinning at all. Mid-scroll puts the ending column
 * off-screen to the right AND the identifying columns off-screen to the left, so the same
 * measurement discriminates both ends.
 */
async function measureScrolled(page, fraction) {
    return page.evaluate((frac) => {
        const wrap = document.querySelector('#tab-daily-sheet .table-wrap');
        const table = document.querySelector('#tab-daily-sheet table');
        if (!wrap || !table) return null;

        wrap.scrollLeft = Math.round((wrap.scrollWidth - wrap.clientWidth) * frac);

        const headText = (th) => (th.textContent || '').replace(/\s+/g, ' ').trim();
        const heads = Array.from(table.querySelectorAll('thead th'));
        const row = table.querySelector('tbody tr.daily-sheet-product-row');
        const cells = Array.from(row.children);
        const wr = wrap.getBoundingClientRect();
        const rr = row.getBoundingClientRect();

        // A probe line inside the row, clamped so it is inside the container's visible box.
        const y = Math.min(Math.max(rr.top + rr.height / 2, wr.top + 6), wr.bottom - 6);

        const box = (el) => {
            const r = el.getBoundingClientRect();
            return {
                left: Math.round((r.left - wr.left) * 10) / 10,
                right: Math.round((r.right - wr.left) * 10) / 10,
                width: Math.round(r.width * 10) / 10,
            };
        };

        // What is painted at a viewport x, and does it belong to the cell we expect to own that
        // pixel? This answers "visible AND on top", not merely "positioned there" — a cell can be
        // in the right place and still be covered by a column sliding beneath it.
        const hitAt = (x, cell) => {
            const el = document.elementFromPoint(x, y);
            return el
                ? { tag: el.tagName.toLowerCase(), inTarget: cell.contains(el) }
                : { tag: null, inTarget: false };
        };

        // The ENDING balance column, located by its header rather than by a frozen offset.
        const balIndex = heads.findIndex((th) => /ACTUAL BAL/i.test(headText(th)));
        const bal = balIndex >= 0 ? cells[balIndex] : null;

        return {
            scrolled: Math.round(wrap.scrollLeft),
            overflow: wrap.scrollWidth - wrap.clientWidth,
            wrapperLeft: Math.round(wr.left),
            wrapperRight: Math.round(wr.right),
            wrapperWidth: Math.round(wr.width),
            // The SCROLLPORT's content width, not the border box: a vertical scrollbar would make
            // the border box wider than the area a pinned-right cell can actually reach.
            contentWidth: wrap.clientWidth,
            headerCells: heads.length,
            bodyCells: cells.length,
            balIndex,
            headers: heads.map(headText),
            product: box(cells[0]),
            beg: box(cells[1]),
            addtl: box(cells[2]),
            bal: bal ? box(bal) : null,
            productLabel: (cells[0].innerText || '').replace(/\s+/g, ' ').trim(),
            hitProduct: hitAt(wr.left + 20, cells[0]),
            hitBeg: hitAt(cells[1].getBoundingClientRect().left + 12, cells[1]),
            hitAddtl: hitAt(cells[2].getBoundingClientRect().left + 12, cells[2]),
            hitBal: bal ? hitAt(wr.left + wrap.clientWidth - 12, bal) : null,
        };
    }, fraction);
}

/** The three pinned cells must sit flush at the container's left edge, in order, and be on top. */
function expectLeftGroupPinned(m, viewportLabel) {
    const where = `${viewportLabel}: `;
    expect(m.scrolled, `${where}the sheet must actually be scrolled sideways, or nothing is proven`)
        .toBeGreaterThan(100);
    // Column 1 pinned at the container's left edge.
    expect(Math.abs(m.product.left), `${where}the Product column must stay pinned at the left edge (left=${m.product.left})`)
        .toBeLessThanOrEqual(2);
    // Columns 2 and 3 butted against their predecessor, so there is no gap and no overlap.
    expect(Math.abs(m.beg.left - m.product.right),
        `${where}BEG must sit immediately after Product (beg.left=${m.beg.left} product.right=${m.product.right})`)
        .toBeLessThanOrEqual(2);
    expect(Math.abs(m.addtl.left - m.beg.right),
        `${where}ADDTL must sit immediately after BEG (addtl.left=${m.addtl.left} beg.right=${m.beg.right})`)
        .toBeLessThanOrEqual(2);

    // The product name must still have room to read.
    expect(m.product.width, `${where}the Product column must keep its readable width`)
        .toBeGreaterThanOrEqual(190);
    expect(m.productLabel.length, `${where}the Product cell must still show a product name`)
        .toBeGreaterThan(0);

    // ...and it must be the thing PAINTED at those pixels, not a column sliding beneath it.
    expect(m.hitProduct.inTarget,
        `${where}the pixel 20px into the Product column must belong to the Product cell (got ${JSON.stringify(m.hitProduct)})`)
        .toBe(true);
    expect(m.hitBeg.inTarget,
        `${where}the pixel at the BEG column must belong to the BEG cell (got ${JSON.stringify(m.hitBeg)})`)
        .toBe(true);
    expect(m.hitAddtl.inTarget,
        `${where}the pixel at the ADDTL column must belong to the ADDTL cell (got ${JSON.stringify(m.hitAddtl)})`)
        .toBe(true);
}

test('a 10-inch tablet keeps the Product, BEG and ADDTL columns visible while the sheet scrolls',
    async ({ page }) => {
        await page.setViewportSize(TABLET_PORTRAIT);
        await login(page);
        await openSheet(page);

        // ── portrait: the tight case the owner reported ────────────────────────────────
        await page.setViewportSize(TABLET_PORTRAIT);
        await page.waitForTimeout(600);
        const portrait = await measureScrolled(page, 1);
        expect(portrait, 'the Daily Sheet table must exist').not.toBeNull();
        console.log('SHEET_STICKY ' + JSON.stringify({ portrait }));
        expectLeftGroupPinned(portrait, 'portrait 800x1280');

        // The pinned group must not swallow the sheet. At portrait the scroll container is only
        // ~494px, so pinning more than the identifying columns would leave nothing to scroll in.
        expect(portrait.contentWidth - portrait.addtl.right,
            `portrait 800x1280: the pinned columns must leave a usable scrolling area (pinned ${portrait.addtl.right} of ${portrait.contentWidth}px)`)
            .toBeGreaterThanOrEqual(120);

        // ── landscape: same requirement, more room ─────────────────────────────────────
        // Mid-scroll, so BOTH the identifying columns and the ending balance are off-screen in
        // the unpinned case and the measurement can tell the difference.
        await page.setViewportSize(TABLET_LANDSCAPE);
        await page.waitForTimeout(800);
        const landscape = await measureScrolled(page, 0.5);
        console.log('SHEET_STICKY ' + JSON.stringify({ landscape }));
        expectLeftGroupPinned(landscape, 'landscape 1280x800');

        // ── the ENDING balance column pinned at the right edge ──────────────────────────
        // On the portrait tablet the pinned left group already occupies most of a 494px
        // container, so pinning another column there would leave the sheet unusable. The ending
        // figure is therefore required where it is implementable, and portrait is allowed to omit
        // it. Asserted at landscape, where the viewport can afford it.
        expect(landscape.balIndex, 'landscape: the sheet must still have an ACTUAL BAL column')
            .toBeGreaterThanOrEqual(0);
        expect(landscape.contentWidth - landscape.bal.right,
            `landscape: the ending balance column must be pinned at the right edge (bal.right=${landscape.bal.right} scrollport=${landscape.contentWidth})`)
            .toBeLessThan(6);
        expect(landscape.hitBal.inTarget,
            `landscape: the pixel at the right edge must belong to the ending balance cell (got ${JSON.stringify(landscape.hitBal)})`)
            .toBe(true);
    });

