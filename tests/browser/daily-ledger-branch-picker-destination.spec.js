// @ts-check
/**
 * The picker's destination must survive switching, because Save writes the WHOLE visible list.
 *
 * Owner: "the hide button still stuck at bagting. when i select rizal commis, the process did not
 * switch to rizal commis" / "it will still save changes to bagting".
 *
 * Measured cause: this page's script block renders OUTSIDE `#main-content` (layout app.disyl:728 puts
 * the content inside main, line 734 puts `{block scripts}` after it), so the in-place refresh that
 * `dlLoadForm` performed to switch destinations never re-ran it. The header and the URL updated to the
 * new branch while `PICKER_BRANCH_ID` kept the value from the original page load -- so the save posted
 * the PREVIOUS branch. That is also what the audit trail showed: unassignments landing on Bagting,
 * which is the default first branch, while the operator believed they were editing RIZAL-COMMIS.
 *
 * These tests are read-only: the save request is intercepted and aborted, so nothing is written.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(240000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const TAB = '/daily-ledger/admin/products?tab=assignment';
const COMMISSARY_ID = '18';

async function login(page) {
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
}

const state = (page) => page.evaluate(() => {
    const header = document.querySelector('[data-picker-branch-name]');
    const idEl = document.querySelector('[data-picker-branch-id]');
    const select = document.getElementById('picker-branch');
    return {
        header: header ? header.textContent.trim() : null,
        contentId: idEl ? idEl.getAttribute('data-picker-branch-id') : null,
        select: select ? select.value : null,
        label: document.getElementById('picker-save').textContent.trim(),
        url: location.search,
    };
});

test('switching destination re-initialises the picker instead of leaving it on the old branch', async ({ page }) => {
    await login(page);
    await page.goto(TAB, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-branch', { timeout: 60000 });
    await page.waitForTimeout(700);

    const before = await state(page);
    console.log('before switch:', JSON.stringify(before));
    // The default is the first active branch by name, and that is precisely how a save could land
    // somewhere the operator never chose.
    expect(before.select, 'a destination is selected by default').not.toBeNull();

    await page.selectOption('#picker-branch', COMMISSARY_ID);
    await page.waitForSelector('#picker-branch', { timeout: 60000 });
    await page.waitForTimeout(1200);

    const after = await state(page);
    console.log('after switch :', JSON.stringify(after));
    // The three things that must agree. Before the fix the id and the label stayed on the OLD branch
    // while the header switched -- which is exactly the reported symptom.
    expect(after.select, 'the dropdown shows the new destination').toBe(COMMISSARY_ID);
    expect(after.contentId, 'the visible list belongs to the new destination').toBe(COMMISSARY_ID);
    expect(after.header, 'the header names the new destination').toContain('RIZAL-COMMIS');
    expect(after.label, 'the save control names the destination it will write').toContain('RIZAL-COMMIS');
    expect(after.label, 'and it is not still offering the old branch').not.toContain('Bagting');
});

test('the save posts the destination the visible list belongs to, not the previous one', async ({ page }) => {
    await login(page);
    await page.goto(TAB, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-branch', { timeout: 60000 });
    await page.waitForTimeout(700);
    const before = await state(page);
    expect(before.contentId, 'this test needs the default destination to be a DIFFERENT branch, or switching proves nothing').not.toBe(COMMISSARY_ID);

    await page.selectOption('#picker-branch', COMMISSARY_ID);
    await page.waitForSelector('#picker-branch', { timeout: 60000 });
    await page.waitForTimeout(1200);

    // Capture the payload and abort: this proves what would be written without writing it.
    let payload = null;
    await page.route('**/api/v1/admin/branches/products', async (route) => {
        try { payload = JSON.parse(route.request().postData() || '{}'); } catch (e) { payload = { parse: 'failed' }; }
        await route.abort();
    });

    await page.uncheck('#picker-table .picker-check:checked');
    await page.click('#picker-save');
    await page.waitForTimeout(1500);

    console.log('posted payload branch_id:', payload && payload.branch_id, 'from', before.contentId);
    expect(payload, 'the save was attempted').not.toBeNull();
    expect(payload.branch_id, 'the save must target the destination shown on screen').toBe(Number(COMMISSARY_ID));
});
