// @ts-check
/**
 * Daily Ledger — Oct 1..4 2026 production journey on the Daily Production Sheet.
 *
 * Simulates real entries across four business days and both shifts, then proves:
 *   - AM and PM land on SEPARATE rows (shift-scoped writes)
 *   - carry-over is a CHAIN: PM BEG = AM ending, and the next day's AM BEG = the previous
 *     PM ending (8 hops, crossing both shift and day boundaries)
 *   - "Carry beginnings forward" WRITES the preceding ending into an unrecorded beginning
 *   - Close PM Shift -> Close Day closes the day, and Reopen Day lifts it again
 *
 * Why the filler step exists: a fully-manual day (this tenant has POS disabled) refuses to
 * close until the PM shift is finalized, and the PM shift refuses to finalize while ANY active
 * product lacks a PM ending — 178 of them on this commissary. So the filler records a 0 ending
 * for every other active product through the SAME endpoint the sheet's own cells use.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-oct1-4-journey.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test.setTimeout(900000); // four days x two shifts x a 178-product completeness sweep

// Evidence is APPENDED to a file, not only logged. When Playwright kills a worker on a timeout
// it discards the worker's buffered stdout, which is exactly how a previous run left a fully
// populated Oct 1 on disk with zero hop lines in the log.
const EVIDENCE = '/tmp/journey-evidence.jsonl';
const note = (obj) => fs.appendFileSync(EVIDENCE, JSON.stringify(obj) + '\n');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const COMMISSARY = 18;
const DATES = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'];
const MATERIAL_URL = '/daily-ledger/api/v1/commissary/material';

// The planned chain: date -> [AM ending, PM ending]. Each shift's BEG must equal the PREVIOUS
// ending, so the sequence 10, 7, 12, 9, 13, 6, 8, 8 is walked one hop at a time.
const PLAN = {
    '2026-10-01': { amEnd: 10, pmEnd: 7 },
    '2026-10-02': { amEnd: 12, pmEnd: 9 },
    '2026-10-03': { amEnd: 13, pmEnd: 6 },
    '2026-10-04': { amEnd: 8, pmEnd: 8 },
};

async function login(page) {
    await page.goto('/daily-ledger/login');
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    // Wait for the app SHELL to render, never for `networkidle`: this suite hammers the sheet with
    // hundreds of writes, and "no network for 500ms" is not a property it can rely on.
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

const sheetUrl = (date, shift) =>
    `/daily-ledger/admin/commissary?date=${date}&commissary_id=${COMMISSARY}&shift=${shift}`;

async function openSheet(page, date, shift) {
    await page.goto(sheetUrl(date, shift), { waitUntil: 'domcontentloaded' });
    // Element-based readiness. `networkidle` was the reason a run burned its whole 900s budget
    // waiting for a page that was already fully rendered.
    await page.waitForSelector('#production-day-status', { timeout: 60000 });
    await page.waitForSelector('#tab-daily-sheet table tbody tr.daily-sheet-product-row', { timeout: 60000 });
}

const dayStatus = (page) => page.locator('#production-day-status').innerText();

/** Bring this date+shift into an EDITABLE state, whatever state it is in.
 *  - day closed            -> the day-level "Reopen Day" control
 *  - day open + shift done -> the shift-level "Reopen Shift" control
 *  Both call the same endpoint, but the sheet only offers the affordance that matches the state,
 *  so a test that assumes one of them strands itself on the other. */
async function makeEditable(page, pid, date, shift) {
    await openSheet(page, date, shift);
    if ((await dayStatus(page)).trim() !== 'open') {
        note({ type: 'reopen', date, shift, via: 'day' });
        await page.click('#production-reopen-day');
    } else {
        const actual = page.locator(`#production-actual-${pid}`);
        if (await actual.isDisabled()) {
            note({ type: 'reopen', date, shift, via: 'shift' });
            const shiftReopen = page.locator('button:has-text("Reopen Shift")');
            if (await shiftReopen.count()) await shiftReopen.first().click();
        }
    }
    await page.waitForFunction(
        (sel) => { const el = document.querySelector(sel); return el && !el.disabled; },
        `#production-actual-${pid}`,
        { timeout: 60000 },
    );
    await page.waitForFunction(
        () => { const el = document.querySelector('#production-day-status'); return el && el.textContent.trim() === 'open'; },
        null,
        { timeout: 30000 },
    );
    return (await dayStatus(page)).trim();
}

/** Reopen the day if it is closed, and wait until the sheet actually reports open. */
async function ensureDayOpen(page, date) {
    await openSheet(page, date, 'AM');
    if ((await dayStatus(page)).trim() !== 'open') {
        await page.click('#production-reopen-day');
        await page.waitForFunction(() => {
            const el = document.querySelector('#production-day-status');
            return el && el.textContent.trim() === 'open';
        }, null, { timeout: 30000 });
    }
    return (await dayStatus(page)).trim();
}

/** Save one BEG/ACTUAL cell through the sheet's own handler and wait for it to persist. */
async function saveCell(page, kind, pid, value) {
    const sel = kind === 'beg' ? `#production-beg-${pid}` : `#production-actual-${pid}`;
    await page.waitForSelector(sel, { timeout: 30000 });
    await page.fill(sel, String(value));
    await page.dispatchEvent(sel, 'change');
    await page.waitForFunction(
        ([s, v]) => {
            const el = document.querySelector(s);
            return el && el.getAttribute('data-original') === String(v);
        },
        [sel, value],
        { timeout: 30000 },
    );
}

/** What the sheet proposes for this BEG, and why — the carry-over signal under test. */
async function carrySignal(page, pid) {
    return page.evaluate((p) => {
        const input = document.querySelector('#production-beg-' + p);
        if (!input) return null;
        const cell = input.closest('td');
        const src = cell ? cell.querySelector('.production-carry-source') : null;
        return {
            value: input.value,
            suggestion: input.getAttribute('data-suggestion'),
            recorded: input.getAttribute('data-recorded'),
            source: src ? src.textContent.replace(/\s+/g, ' ').trim() : null,
        };
    }, pid);
}

/** Record a 0 PM ending for every active product except the ones already given a real ending. */
async function satisfyPmCompleteness(page, date, realEndings) {
    return page.evaluate(async ([url, d, cid, skip]) => {
        const rows = Array.from(document.querySelectorAll('#tab-daily-sheet tbody tr.daily-sheet-product-row'));
        const ids = rows.map((r) => Number(r.getAttribute('data-product-id'))).filter((id) => id && !skip.includes(id));
        const post = (pid) => fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.DL_CSRF || '',
                'Authorization': window.DL_TOKEN ? ('Bearer ' + window.DL_TOKEN) : '',
            },
            body: JSON.stringify({
                entity: 'product_count',
                date: d,
                shift: 'PM',
                commissary_branch_id: cid,
                product_id: pid,
                actual_end_qty: 0,
                submission_id: 'journey-filler-' + d + '-' + pid,
            }),
        }).then((r) => ({ pid, ok: r.ok, status: r.status }));
        // Small batches: 178 sequential round trips are needlessly slow, and each row is
        // independent -- but 25 at once was enough to leave the next page load waiting.
        let ok = 0, failed = [];
        for (let i = 0; i < ids.length; i += 10) {
            const results = await Promise.all(ids.slice(i, i + 10).map(post));
            ok += results.filter((r) => r.ok).length;
            failed = failed.concat(results.filter((r) => !r.ok));
        }
        return { attempted: ids.length, ok, failed: failed.slice(0, 3) };
    }, [MATERIAL_URL, date, COMMISSARY, realEndings]);
}

async function closePmShift(page) {
    // A business date that has ENDED and whose PM endings are now complete is finalised by the
    // sheet's own auto-close when it loads, so the manual button is gone. Both outcomes are
    // correct -- record which path ran rather than assuming the manual one.
    const manual = await page.$('#finalize-production-pm');
    if (!manual) {
        const finalized = await page.evaluate(() => document.body.innerText.includes('The PM shift is finalized'));
        return { mode: 'auto', status: 0, body: null, finalizedBanner: finalized };
    }
    const action = page.waitForResponse((r) => r.url().includes('finalize-pm'), { timeout: 60000 });
    await page.click('#finalize-production-pm');
    const res = await action;
    return { mode: 'manual', status: res.status(), body: await res.json().catch(() => null) };
}

async function closeDay(page) {
    await page.waitForSelector('#production-close-day', { timeout: 30000 });
    const action = page.waitForResponse((r) => r.url().includes('close-day'), { timeout: 60000 });
    await page.click('#production-close-day');
    const res = await action;
    return { status: res.status(), body: await res.json().catch(() => null) };
}

test('Oct 1-4: AM/PM shifts, carry-over chain, close day and reopen', async ({ page }) => {
    fs.writeFileSync(EVIDENCE, '');
    await login(page);

    // The test product is whichever row the sheet renders first — no hard-coded id.
    await openSheet(page, DATES[0], 'AM');
    const productId = await page.evaluate(() =>
        Number(document.querySelector('#tab-daily-sheet tbody tr.daily-sheet-product-row').getAttribute('data-product-id')));
    expect(productId, 'the sheet must expose a product row').toBeGreaterThan(0);

    const evidence = { productId, hops: [], closings: [], filler: [] };

    for (let i = 0; i < DATES.length; i++) {
        const date = DATES[i];
        const plan = PLAN[date];
        const previousEnding = i === 0 ? 0 : PLAN[DATES[i - 1]].pmEnd;

        // ---- ensure the day AND the shift are editable ------------------------------------
        evidence[date] = { dayAndShiftEditable: await makeEditable(page, productId, date, 'AM') };
        expect(evidence[date].dayAndShiftEditable, `${date} must be editable before entering data`).toBe('open');

        // ---- AM: previous day's PM ending must be offered as the beginning ---------------
        await openSheet(page, date, 'AM');
        const amBefore = await carrySignal(page, productId);
        evidence.hops.push({ date, shift: 'AM', expected: previousEnding, before: amBefore });
        if (i > 0) {
            expect(
                Number(amBefore.suggestion),
                `${date} AM must be offered the previous PM ending (${previousEnding})`,
            ).toBe(previousEnding);
        }
        await page.evaluate(() => window.carryProductionBeginnings && window.carryProductionBeginnings());
        await page.waitForTimeout(1500);
        const amAfter = await carrySignal(page, productId);
        if (i > 0) {
            expect(Number(amAfter.value), `${date} AM beginning must carry ${previousEnding}`).toBe(previousEnding);
        }
        await saveCell(page, 'actual', productId, plan.amEnd);
        console.log('JOURNEY_HOP ' + JSON.stringify({ date, shift: 'AM', expected: previousEnding, suggestion: amBefore.suggestion, carried: amAfter.value, ending: plan.amEnd }));
        note({ type: 'hop', date, shift: 'AM', expected: previousEnding, suggestion: amBefore.suggestion, carried: amAfter.value, ending: plan.amEnd });

        // ---- PM: this shift's beginning must carry THIS day's AM ending ------------------
        // Navigating here re-runs the auto-close seam, which finalises a past date's PM shift as
        // soon as its endings are complete -- so the shift must be reopened BEFORE it can be
        // edited or carried, not assumed to still be open from the AM step.
        await makeEditable(page, productId, date, 'PM');
        const pmBefore = await carrySignal(page, productId);
        evidence.hops.push({ date, shift: 'PM', expected: plan.amEnd, before: pmBefore });
        expect(Number(pmBefore.suggestion), `${date} PM must be offered the AM ending (${plan.amEnd})`).toBe(plan.amEnd);
        await page.evaluate(() => window.carryProductionBeginnings && window.carryProductionBeginnings());
        await page.waitForTimeout(1500);
        const pmAfter = await carrySignal(page, productId);
        expect(Number(pmAfter.value), `${date} PM beginning must carry the AM ending (${plan.amEnd})`).toBe(plan.amEnd);
        await saveCell(page, 'actual', productId, plan.pmEnd);
        console.log('JOURNEY_HOP ' + JSON.stringify({ date, shift: 'PM', expected: plan.amEnd, suggestion: pmBefore.suggestion, carried: pmAfter.value, ending: plan.pmEnd }));
        note({ type: 'hop', date, shift: 'PM', expected: plan.amEnd, suggestion: pmBefore.suggestion, carried: pmAfter.value, ending: plan.pmEnd });

        // ---- the two shifts must be SEPARATE rows ---------------------------------------
        const shiftRows = await page.evaluate(([p, d]) => {
            // The AM rendering of the same date must still show the AM ending, not the PM one.
            return fetch('/daily-ledger/admin/commissary?date=' + d + '&commissary_id=' + 18 + '&shift=AM', {
                credentials: 'same-origin',
            }).then((r) => r.text()).then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const amActual = doc.querySelector('#production-actual-' + p);
                return { amActual: amActual ? amActual.value : null };
            });
        }, [productId, date]);
        expect(Number(shiftRows.amActual), `${date} AM must still hold its own ending (${plan.amEnd}), not the PM one`).toBe(plan.amEnd);

        // ---- completeness sweep, then Close PM Shift and Close Day -----------------------
        evidence.filler.push({ date, ...(await satisfyPmCompleteness(page, date, [productId])) });
        note({ type: 'filler', date, filler: evidence.filler[evidence.filler.length - 1] });

        await openSheet(page, date, 'PM');
        const pm = await closePmShift(page);
        if (pm.mode === 'manual') {
            expect(pm.status, `${date} Close PM Shift must succeed (${JSON.stringify(pm.body)})`).toBe(200);
        } else {
            // Past date: the auto-close finalised it on load. Assert that positively.
            expect(pm.finalizedBanner, `${date} PM must have been finalised by the auto-close before Close Day`).toBe(true);
        }
        const closed = await closeDay(page);
        await openSheet(page, date, 'PM');
        const status = (await dayStatus(page)).trim();
        evidence.closings.push({ date, closePm: pm, closeDay: closed, dayStatus: status });
        note({ type: 'closing', date, pmMode: pm.mode, closeDayStatus: closed.status, dayStatus: status });
        expect(closed.status, `${date} Close Day must succeed (${JSON.stringify(closed.body)})`).toBe(200);
        expect(status, `${date} must read closed after Close Day`).toBe('closed');
        console.log('JOURNEY_DAY ' + JSON.stringify({
            date, pmMode: pm.mode, closeDayStatus: closed.status, dayStatus: status,
            filler: evidence.filler[evidence.filler.length - 1],
        }));
    }

    // ---- reopen: the last day must be liftable again ------------------------------------
    await openSheet(page, DATES[3], 'PM');
    await page.waitForSelector('#production-reopen-day', { timeout: 30000 });
    await page.click('#production-reopen-day');
    await page.waitForFunction(() => {
        const el = document.querySelector('#production-day-status');
        return el && el.textContent.trim() === 'open';
    }, null, { timeout: 30000 });
    expect((await dayStatus(page)).trim(), 'Reopen Day must lift the closed day').toBe('open');

    // Editing must work again after the reopen — the reopen would be worthless otherwise.
    await saveCell(page, 'actual', productId, PLAN[DATES[3]].pmEnd);
    evidence.reopenedEditable = true;
    note({ type: 'reopen', date: DATES[3], editableAfterReopen: true });

    console.log('JOURNEY ' + JSON.stringify(evidence));
});
