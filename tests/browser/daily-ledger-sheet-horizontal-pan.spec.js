// @ts-check
/**
 * Laptop ergonomics for the wide Daily Sheet.
 *
 * Owner: "for laptop users, add a horizontal scroller below the branches... current behaviour, users
 * need to scroll down just to see the horizontal scroller. maybe a left right mouse swipe capability?"
 *
 * A bound sheet scroll box (see the sticky-header spec) already moves the native scrollbar up from
 * the bottom of a ~10,000px table to the bottom of the sheet box. On top of that this covers the
 * swipe: drag anywhere non-interactive to pan sideways, or Shift+wheel.
 *
 * The must-refuse case matters more than the must-allow one here: panning must NOT start from an
 * entry control, or pressing into a BEG/ADDTL cell to record a figure would slide the sheet instead
 * of focusing the input.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(240000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const SHEET = '/daily-ledger/admin/commissary';
const WRAP = '#tab-daily-sheet .dl-sheet-scroll';

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

async function openSheet(page) {
    await login(page);
    await page.goto(SHEET, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#production-ledger-table thead th[data-branch-id]', { timeout: 60000 });
    // Bring the table into view so body-row coordinates land inside the viewport. Not too far: the
    // app's own header occupies the top of the window, and scrolling the sheet's top above it pushes
    // the sticky header band behind that header, which is a different (unrelated) problem.
    await page.locator('#main-content').evaluate((el) => { el.scrollTop = 300; });
    await page.waitForTimeout(150);
}

const scrollLeft = (page) => page.locator(WRAP).evaluate((el) => el.scrollLeft);

test('dragging the sheet sideways pans it (mouse swipe)', async ({ page }) => {
    await openSheet(page);
    const before = await scrollLeft(page);
    console.log('scrollLeft before drag:', before);

    // Press on a branch header: a plain th, so it is not an entry control.
    const cell = await page.locator('#production-ledger-table thead th[data-branch-id]').nth(5).boundingBox();
    const cx = Math.round(cell.x + cell.width / 2);
    const cy = Math.round(cell.y + cell.height / 2);

    await page.mouse.move(cx, cy);
    await page.mouse.down();
    await page.mouse.move(cx - 160, cy, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(150);

    const after = await scrollLeft(page);
    console.log('scrollLeft after drag :', after);
    expect(after - before, 'dragging left must pan the sheet right').toBeGreaterThan(100);
});

test('pressing an entry control does NOT pan, so recording a figure still works', async ({ page }) => {
    await openSheet(page);
    const before = await scrollLeft(page);

    const input = await page.locator('#production-ledger-table tbody td.dl-sheet-sticky-beg input').first().boundingBox();
    const cx = Math.round(input.x + input.width / 2);
    const cy = Math.round(input.y + input.height / 2);
    const atPoint = await page.evaluate(([x, y]) => {
        const el = document.elementFromPoint(x, y);
        return el ? el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') : '(nothing)';
    }, [cx, cy]);
    console.log(`press point ${cx},${cy} -> ${atPoint}`);

    const active = () => page.evaluate(() => {
        const el = document.activeElement;
        return el ? el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : '') : '(none)';
    });

    await page.mouse.move(cx, cy);
    await page.mouse.down();
    console.log('focused on press    :', await active());
    await page.mouse.move(cx - 160, cy, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(150);

    const after = await scrollLeft(page);
    console.log(`scrollLeft before=${before} after=${after}`);
    expect(Math.abs(after - before), 'an entry control must not start a pan').toBeLessThanOrEqual(2);

    // The control's own state is logged, not asserted. On a finalised shift these inputs are
    // disabled, so this test proves the must-refuse half (no pan) but cannot also prove that a live
    // control still takes the press. That half is structural rather than state-dependent: the guard
    // tests the pressed TARGET, so it holds whether or not the control is enabled.
    const state = await page.evaluate(() => {
        const el = document.querySelector('#production-ledger-table tbody td.dl-sheet-sticky-beg input');
        return el ? { disabled: el.disabled, readOnly: el.readOnly } : null;
    });
    console.log('pressed control state:', JSON.stringify(state));
});

test('Shift+wheel scrolls the sheet sideways', async ({ page }) => {
    await openSheet(page);
    const before = await scrollLeft(page);

    const wrap = await page.locator(WRAP).boundingBox();
    await page.mouse.move(Math.round(wrap.x + wrap.width / 2), Math.round(wrap.y + 80));
    await page.keyboard.down('Shift');
    await page.mouse.wheel(0, 240);
    await page.keyboard.up('Shift');
    await page.waitForTimeout(200);

    const after = await scrollLeft(page);
    console.log(`scrollLeft before=${before} after=${after}`);
    expect(after - before, 'Shift+wheel must scroll horizontally').toBeGreaterThan(100);
});

test('dragging the sheet up and down pans it vertically too', async ({ page }) => {
    await openSheet(page);
    const before = await page.locator(WRAP).evaluate((el) => el.scrollTop);

    // A product-name cell of the FIRST row: not an entry control, and on screen. Taking a lower row
    // puts the press point below the viewport, where the drag reaches nothing at all.
    const cell = await page.locator('#production-ledger-table tbody tr:nth-child(1) td:first-child').boundingBox();
    const cx = Math.round(cell.x + 20);
    const cy = Math.round(cell.y + 20);
    const atPoint = await page.evaluate(([x, y]) => {
        const el = document.elementFromPoint(x, y);
        return el ? el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') : '(nothing)';
    }, [cx, cy]);
    console.log(`press point ${cx},${cy} -> ${atPoint} (viewport height ${page.viewportSize().height})`);

    await page.mouse.move(cx, cy);
    await page.mouse.down();
    await page.mouse.move(cx, cy - 150, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(150);

    const after = await page.locator(WRAP).evaluate((el) => el.scrollTop);
    console.log(`scrollTop before=${before} after=${after}`);
    // Dragging the rows upward scrolls the box down, exactly as dragging a map does.
    expect(after - before, 'dragging upward must move the rows up').toBeGreaterThan(100);

    // And back: dragging down returns the rows, which is what makes this a pan rather than a
    // one-way scroll. Press from the SAME point -- start anywhere above the wrap and the press
    // misses the pan surface entirely rather than panning by less.
    await page.mouse.move(cx, cy);
    await page.mouse.down();
    await page.mouse.move(cx, cy + 60, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(150);
    const back = await page.locator(WRAP).evaluate((el) => el.scrollTop);
    console.log(`scrollTop back=${back} (60px of drag from ${after})`);
    expect(Math.abs((after - back) - 60), 'the rows must follow the pointer back down').toBeLessThanOrEqual(25);
});

/**
 * The literal request: "add a horizontal scroller below the branches... users need to scroll down just
 * to see the horizontal scroller". Asserted at the point the user is actually at - the page NOT
 * scrolled to the bottom of a ~10,000px table - because that is the condition the complaint describes.
 */
test('a horizontal scrollbar is reachable without scrolling to the foot of the table', async ({ page }) => {
    await openSheet(page);
    const viewport = page.viewportSize();

    const bar = page.locator('#dl-sheet-hscroll');
    await expect(bar, 'the reachable scrollbar must be on screen').toBeVisible();

    const barBox = await bar.boundingBox();
    const foot = barBox.y + barBox.height;
    const wrapBox = await page.locator(WRAP).boundingBox();
    console.log(`bar y=${Math.round(barBox.y)} h=${Math.round(barBox.height)} foot=${Math.round(foot)} viewportHeight=${viewport.height}`);
    console.log(`table foot y=${Math.round(wrapBox.y + wrapBox.height)}`);

    // The complaint, stated as a fact about the page: at this scroll position the table's own
    // scrollbar is below the fold...
    expect(wrapBox.y + wrapBox.height, 'the table foot must be below the fold for this to prove anything').toBeGreaterThan(viewport.height);
    // ...and the reachable bar is on screen anyway. This pair is what discriminates: a bar that only
    // appears once you have scrolled to the table's foot would fail the second assertion.
    expect(foot, 'the reachable bar must be on screen while the table foot is not').toBeLessThanOrEqual(viewport.height);

    // Whether the platform DRAWS a scrollbar is not something this test can dictate: Playwright's
    // headless Chromium runs with --hide-scrollbars, so nothing reserves height there. Measure that
    // first, and only then claim the bar is grabbable -- otherwise this asserts the environment.
    const scrollbarPx = await page.evaluate(() => {
        const probe = document.createElement('div');
        probe.style.cssText = 'width:100px;height:100px;overflow:scroll;position:absolute;top:-9999px';
        document.body.appendChild(probe);
        const reserved = probe.offsetWidth - probe.clientWidth;
        probe.remove();
        return reserved;
    });
    console.log(`platform scrollbar reserves ${scrollbarPx}px; bar height=${Math.round(barBox.height)}px`);
    if (scrollbarPx > 0) {
        // Measured 12px with a real scrollbar (10px styled thumb + 2px border). A hairline here would
        // mean the platform gave the bar an overlay scrollbar and there is nothing to grab.
        expect(barBox.height, 'the bar must render something grabbable').toBeGreaterThanOrEqual(8);
    } else {
        console.log('scrollbars are hidden in this environment, so the drawn height is not measurable');
    }

    // Whatever the platform draws, the bar must have somewhere to scroll, or it is decoration.
    const range = await bar.evaluate((el) => el.scrollWidth - el.clientWidth);
    console.log('bar scrollable range:', range);
    expect(range, 'the bar must span the table, so dragging it moves the sheet').toBeGreaterThan(100);

    // Dragging this bar must move the sheet, or it is decoration.
    await bar.evaluate((el) => { el.scrollLeft = 320; });
    await page.waitForTimeout(200);
    const fromBar = await scrollLeft(page);
    console.log('wrap after dragging the bar:', fromBar);
    expect(fromBar, 'the bar must drive the sheet').toBeGreaterThan(200);

    // And it must follow the sheet the other way, or the two disagree about where you are.
    await page.locator(WRAP).evaluate((el) => { el.scrollLeft = 90; });
    await page.waitForTimeout(200);
    const barLeft = await page.locator('#dl-sheet-hscroll').evaluate((el) => el.scrollLeft);
    console.log('bar after scrolling the sheet:', barLeft);
    expect(Math.abs(barLeft - 90), 'the bar must follow the sheet').toBeLessThanOrEqual(2);
});
