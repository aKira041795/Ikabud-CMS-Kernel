// @ts-check
/**
 * The Daily Sheet's destination header row (branch and consignee names) must stay FIXED when the
 * sheet is scrolled up or down; only the product rows move.
 *
 * Owner: "when scrolling up or down, keep the branch names fixed too, meaning, it does not move with
 * the scrolling. only the product names move up or down".
 *
 * Both directions are asserted, because a header that is merely hidden on scroll would also "not
 * move" in a naive check:
 *   - the header's viewport Y must stay put (within a pixel or two),
 *   - a product row's Y must move up by roughly the scroll distance,
 *   - and the header must still be on screen afterwards, or fixing it would be pointless.
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

const topOf = (page, selector) => page.locator(selector).first().evaluate((el) => Math.round(el.getBoundingClientRect().top));

/**
 * A sticky element sticks to its NEAREST scrollport. For this sheet that is .table-wrap: its
 * `overflow-x: auto` forces overflow-y to compute to auto as well, and the bounded max-height is
 * what makes it actually scroll vertically. Scrolling #main-content would prove nothing, because
 * the header is not sticky relative to it.
 */
const scroller = (page) => page.locator('#tab-daily-sheet .dl-sheet-scroll');

test('the branch/consignee header row stays fixed while the product rows scroll', async ({ page }) => {
    await login(page);
    await page.goto(SHEET, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-ledger-table thead th[data-branch-id]', { timeout: 60000 });

    const header = '#production-ledger-table thead th[data-branch-id]';
    const row = '#production-ledger-table tbody tr:nth-child(6) td:first-child';

    const headerBefore = await topOf(page, header);
    const rowBefore = await topOf(page, row);
    console.log(`before scroll: headerY=${headerBefore} rowY=${rowBefore}`);

    const scrolled = await scroller(page).evaluate((el) => { el.scrollTop = 500; return el.scrollTop; });
    console.log('scrollTop applied:', scrolled);
    expect(scrolled, 'the sheet must be tall enough to scroll, or this test proves nothing').toBeGreaterThan(200);

    await page.waitForTimeout(150); // let sticky layout settle

    const headerAfter = await topOf(page, header);
    const rowAfter = await topOf(page, row);
    console.log(`after scroll:  headerY=${headerAfter} rowY=${rowAfter}`);

    // The header must not have travelled with the content.
    expect(Math.abs(headerAfter - headerBefore), 'the header row must stay put').toBeLessThanOrEqual(2);

    // ...and the rows must have moved, so the assertion above is not passing because nothing moved.
    expect(rowBefore - rowAfter, 'the product rows must scroll under the header').toBeGreaterThan(200);

    // "Fixed" for an inner scroller means pinned to the top of its OWN scroll area, not to the top of
    // the viewport, so compare against the wrap. It must also still be on screen afterwards, because
    // a header that scrolled out of view would also "not move" relative to itself.
    const wrapBox = await page.locator('#tab-daily-sheet .dl-sheet-scroll').boundingBox();
    const box = await page.locator(header).first().boundingBox();
    expect(box, 'the sticky header must still be visible').not.toBeNull();
    console.log(`wrapY=${Math.round(wrapBox.y)} headerY=${Math.round(box.y)}`);
    expect(Math.abs(box.y - wrapBox.y), 'the header must be pinned to the top of its scroll area').toBeLessThanOrEqual(2);
    const viewport = page.viewportSize();
    expect(box.y, 'the header must remain within the viewport').toBeGreaterThanOrEqual(0);
    expect(box.y, 'the header must be visible, not pushed below the fold').toBeLessThan(viewport.height);

    // And the corner must still be the Product column, not a branch header painted over it.
    const productHeader = '#production-ledger-table thead th.dl-sheet-sticky-product';
    const productBox = await page.locator(productHeader).first().boundingBox();
    console.log(`product header: x=${Math.round(productBox.x)} y=${Math.round(productBox.y)}`);
    // Pinned to the left edge of its SCROLL AREA, which is not the viewport edge (the sidebar and
    // page padding sit outside), so compare with the wrap rather than with 0.
    expect(Math.abs(productBox.x - wrapBox.x), 'the Product header must be pinned to the left of its scroll area').toBeLessThanOrEqual(2);
    expect(Math.abs(productBox.y - wrapBox.y), 'the Product header must also stick vertically').toBeLessThanOrEqual(2);
});

test('scrolling back up leaves the header and rows where they started', async ({ page }) => {
    await login(page);
    await page.goto(SHEET, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-ledger-table thead th[data-branch-id]', { timeout: 60000 });

    const header = '#production-ledger-table thead th[data-branch-id]';
    const row = '#production-ledger-table tbody tr:nth-child(6) td:first-child';

    const headerStart = await topOf(page, header);
    const rowStart = await topOf(page, row);

    await scroller(page).evaluate((el) => { el.scrollTop = 600; });
    await page.waitForTimeout(150);
    const headerScrolled = await topOf(page, header);

    await scroller(page).evaluate((el) => { el.scrollTop = 0; });
    await page.waitForTimeout(150);
    const headerBack = await topOf(page, header);
    const rowBack = await topOf(page, row);

    console.log(`header: start=${headerStart} scrolled=${headerScrolled} back=${headerBack}; row back=${rowBack}`);
    expect(Math.abs(headerScrolled - headerStart)).toBeLessThanOrEqual(2);
    expect(Math.abs(headerBack - headerStart), 'returning to the top restores the header position').toBeLessThanOrEqual(2);
    expect(Math.abs(rowBack - rowStart), 'returning to the top restores the row position').toBeLessThanOrEqual(2);
});

/**
 * Reported: while scrolling sideways, branch names painted OVER BEG and ADDTL.
 *
 * Cause was z-index specificity, not geometry. The rule that makes the header row sticky selects the
 * table by id, which outranks the plain `.dl-sheet-sticky-*` selectors, so the pinned header cells
 * never received their higher z-index: every header cell was equal, and DOM order resolved the tie in
 * favour of the later branch headers.
 *
 * Asserted by HIT-TESTING, because z-index numbers alone do not tell you what a user sees. Each probe
 * reports the cell TAG as well, so a body probe that accidentally lands on the sticky header (a TH)
 * cannot pass as if it had found the body cell.
 */
test('a sliding branch header never covers the pinned BEG / ADDTL columns', async ({ page }) => {
    await login(page);
    await page.goto(SHEET, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-ledger-table thead th[data-branch-id]', { timeout: 60000 });

    // Bring the table up the viewport first, but not above the app's own header: with the page at the
    // top the sheet's scroll area starts around y=630 on a 720px viewport, so only the header band is
    // on screen and the body probes below would hit nothing at all.
    await page.locator('#main-content').evaluate((el) => { el.scrollTop = 300; });
    // Then drag the branch headers leftwards across the pinned columns.
    await page.locator('#tab-daily-sheet .dl-sheet-scroll').evaluate((el) => { el.scrollLeft = 420; });
    await page.waitForTimeout(150);

    const probe = await page.evaluate(() => {
        const table = document.getElementById('production-ledger-table');
        const wrap = document.querySelector('#tab-daily-sheet .dl-sheet-scroll');
        // `covered` is the whole question: whatever sits on top at this cell's own centre must belong
        // to this cell. Comparing a rendered string was the wrong shape of assertion - a cell carries
        // several classes and `className` does not start with the marker one.
        const hit = (el) => {
            if (!el) { return { covered: false, y: -1, by: '(absent)' }; }
            const r = el.getBoundingClientRect();
            const x = Math.round(r.left + Math.min(6, r.width / 2));
            const y = Math.round(r.top + Math.min(6, r.height / 2));
            const top = document.elementFromPoint(x, y);
            if (!top) { return { covered: false, y, by: '(nothing at ' + x + ',' + y + ')' }; }
            const owner = top.closest('th,td');
            return { covered: owner === el, y, by: owner ? owner.tagName + '.' + owner.className.trim().split(/\s+/)[0] : top.tagName };
        };
        const classes = ['dl-sheet-sticky-product', 'dl-sheet-sticky-beg', 'dl-sheet-sticky-addtl'];
        return {
            scrollLeft: wrap.scrollLeft,
            headerCells: classes.map((c) => ({ cls: c, probe: hit(table.querySelector('thead th.' + c)) })),
            bodyCells: classes.map((c) => ({ cls: c, probe: hit(table.querySelector('tbody td.' + c)) })),
        };
    });

    console.log('scrollLeft=' + probe.scrollLeft);
    probe.headerCells.forEach((h) => console.log(`  header ${h.cls} -> covered=${h.probe.covered} by ${h.probe.by} @y=${h.probe.y}`));
    probe.bodyCells.forEach((b) => console.log(`  body   ${b.cls} -> covered=${b.probe.covered} by ${b.probe.by} @y=${b.probe.y}`));

    // A pinned HEADER cell must be topmost at its own centre, or a sliding branch name has painted
    // over it...
    for (const h of probe.headerCells) {
        expect(h.probe.covered, `${h.cls} header must be topmost at its own centre, but ${h.probe.by} covers it`).toBe(true);
    }
    // ...and so must the pinned BODY cells. Requiring the topmost element's own cell to BE the probed
    // cell is what stops this passing by landing on the sticky header instead.
    for (const b of probe.bodyCells) {
        expect(b.probe.covered, `${b.cls} body cell must be topmost at its own centre, but ${b.probe.by} covers it`).toBe(true);
    }
});
