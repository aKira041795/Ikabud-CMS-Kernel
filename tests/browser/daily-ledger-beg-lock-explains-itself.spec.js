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
const SHIFT_SWITCH = '[role="group"][aria-label="Shift"]';

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

    // --- the All view: locked, with the reason, the remedy and the SWITCH all on screen ----------------
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

    // The switch beside the sheet is the whole point of the note: a remedy the reader cannot reach is
    // not a remedy. It is gated on nothing, so the ADMIN (the role that edits beginnings) has it at the
    // cell, not only in the filter form at the top of the page.
    const shiftSwitch = page.locator(SHIFT_SWITCH);
    await expect(shiftSwitch, 'the shift switch must sit beside the sheet, not only in the filter form')
        .toHaveCount(1);
    const shiftChips = (await shiftSwitch.innerText()).replace(/\s+/g, ' ').trim();
    expect(shiftChips, 'the switch must offer AM and PM').toMatch(/AM\s+PM/);

    // --- using it: editable, and therefore NOT explained (the note must not be decoration) -----------
    await shiftSwitch.getByRole('link', { name: 'AM', exact: true }).click();
    await page.waitForURL(/shift=AM/, { timeout: 30000 });
    await page.waitForSelector(BEG, { timeout: 60000 });
    const amTotal = await page.locator(BEG).count();
    const amDisabled = await page.locator(`${BEG}[disabled]`).count();
    expect(amTotal, 'the AM view must render beginnings').toBeGreaterThan(0);
    expect(amDisabled, 'with a shift selected on an open day, every beginning is editable').toBe(0);
    await expect(note, 'the note must be absent when there is nothing to explain').toHaveCount(0);

    // PM is the other half of the flow: it carries from the same date's AM ending, and must be editable too.
    await page.locator(SHIFT_SWITCH).getByRole('link', { name: 'PM', exact: true }).click();
    await page.waitForURL(/shift=PM/, { timeout: 30000 });
    await page.waitForSelector(BEG, { timeout: 60000 });
    const pmDisabled = await page.locator(`${BEG}[disabled]`).count();
    expect(pmDisabled, 'the PM shift must be editable on an open day').toBe(0);

    // --- parity with the production view: day and shift are ONE control block -------------------------
    // Owner: "preserve the UI at production to admin commissary view. it will take a lot of rediscovery."
    // The admin must meet the SAME inline controls the production view has, not a different layout for the
    // same sheet with the day control only in the filter form at the top.
    //
    // Runs LAST on purpose: it navigates to a different day, and whether that day is open or closed is the
    // sheet's own business. Asserting editability after it would be reading a different day's lock state —
    // which is exactly how this step first broke the PM assertion above.
    const oneBlock = await page.evaluate(() => {
        const day = document.querySelector('#production-date-picker');
        const shift = document.querySelector('[role="group"][aria-label="Shift"]');
        return day !== null && shift !== null && day.parentElement === shift.parentElement;
    });
    expect(oneBlock, 'the day control and the shift switch must be one block, as they are in the production view')
        .toBe(true);

    // ...and changing the day must not silently drop the shift, which would re-lock every beginning
    // underneath the operator. Compared against the shift that was ACTUALLY selected before the change,
    // not a literal: the invariant is "the day change preserves the shift", and hard-coding AM made this
    // assertion fail on a correct tree the moment the step above left the sheet on PM.
    const shiftBefore = new URL(page.url()).searchParams.get('shift');
    expect(shiftBefore, 'the step above must have selected a shift to preserve').not.toBeNull();
    const datePicker = page.locator('#production-date-picker');
    await datePicker.evaluate((el, v) => {
        el.value = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, '2026-10-08');
    await page.waitForURL(/date=2026-10-08/, { timeout: 30000 });
    await page.waitForSelector(BEG, { timeout: 60000 });
    expect(new URL(page.url()).searchParams.get('shift'),
        'changing the day must keep the selected shift, or the beginnings re-lock underneath the operator')
        .toBe(shiftBefore);

    console.log(`All view: ${allDisabled}/${allTotal} beginnings disabled, note present, switch [${shiftChips}]. `
        + `After the switch: AM ${amDisabled}/${amTotal} disabled, PM ${pmDisabled} disabled, no note. `
        + `Day+shift one block: ${oneBlock}; changing the day kept shift=${shiftBefore}.`);
});
