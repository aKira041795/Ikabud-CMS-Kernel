// @ts-check
/**
 * A read-only cell must say WHY, and name the control that unlocks it.
 *
 * Owner: "i noticed that beginning cell cannot be edited? it should be editable".
 *
 * The beginning IS editable — per shift. It is locked in the sheet's "All" view because a beginning
 * belongs to exactly one shift: `dl_fetchCommissaryBeginningSuggestions()` reads a single shift's
 * ending, and the ledger row the value is written to is keyed (commissary, product, date, SHIFT). In
 * "All" there is no shift for it to belong to, so writing it would create a row the AM/PM carry never
 * reads — the operator's number would vanish. The lock is right; the SILENCE was the defect: the BEG
 * cell was disabled while the ADDTL trigger and the ACTUAL BAL cell in the same row stayed editable,
 * so it read as a broken cell, and nothing on screen said which control would help.
 *
 * Both directions are asserted, because a note that is always present would also "explain" the lock:
 *   - All view, open day   -> every BEG disabled AND the note present, naming AM/PM
 *   - AM view,  open day   -> no BEG disabled AND the note ABSENT
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-beg-lock-explains-itself.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const SHEET = '/daily-ledger/admin/commissary';
const NOTE = '#production-beg-shift-locked-note';
const BEG = '#production-ledger-table .production-beg-input';

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

async function open(page, query) {
    await page.goto(SHEET + query, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${BEG}`, { timeout: 60000 });
}

test('the beginning is editable per shift, and the All view says so instead of just disabling it', async ({ page }) => {
    await login(page);

    // --- the All view: locked, with the reason and the remedy on screen ------------------------------
    await open(page, '');
    const allTotal = await page.locator(BEG).count();
    const allDisabled = await page.locator(`${BEG}[disabled]`).count();
    expect(allTotal, 'the sheet must render beginnings to talk about').toBeGreaterThan(0);
    expect(allDisabled, 'in the All view every beginning is read-only').toBe(allTotal);

    const note = page.locator(NOTE);
    await expect(note, 'a disabled cell must not be left unexplained').toHaveCount(1);
    const noteText = (await note.innerText()).replace(/\s+/g, ' ').trim();
    expect(noteText, 'the note must name the reason').toMatch(/per shift/i);
    expect(noteText, 'the note must name the control that unlocks it').toMatch(/\bAM\b/);
    expect(noteText, 'the note must name the control that unlocks it').toMatch(/\bPM\b/);

    // --- the AM view: editable, and therefore NOT explained (the note must not be decoration) --------
    await open(page, '?shift=AM');
    const amTotal = await page.locator(BEG).count();
    const amDisabled = await page.locator(`${BEG}[disabled]`).count();
    expect(amTotal, 'the AM view must render beginnings').toBeGreaterThan(0);
    expect(amDisabled, 'with a shift selected on an open day, every beginning is editable').toBe(0);
    await expect(note, 'the note must be absent when there is nothing to explain').toHaveCount(0);

    console.log(`All view: ${allDisabled}/${allTotal} beginnings disabled + note present. `
        + `AM view: ${amDisabled}/${amTotal} disabled, no note.`);
});
