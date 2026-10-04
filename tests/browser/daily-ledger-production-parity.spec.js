// @ts-check
/**
 * Daily Ledger — production Daily Sheet vs cashier ledger, FEATURE PARITY probe.
 *
 * Read-only verification: logs in as a production-in-charge user and as a cashier,
 * inventories the controls each ledger actually renders, and screenshots both.
 * It asserts only what the delivered contract requires (date picker, Close Day,
 * admin-only Reopen); everything else is reported as an inventory so parity gaps
 * are judged from evidence rather than from source reading.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-production-parity.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

// Each test logs in against the live app and reads a 2.5MB sheet; the 30s default is too tight.
test.setTimeout(180000);

const PRODUCTION = {
    username: 'prod-rizal',
    fullName: 'Sheila Baina',
    password: 'prodrizal123',
    label: 'production_in_charge',
};

const CASHIER = {
    username: 'cashier-miputakAM',
    fullName: 'Jorely Verano',
    password: 'cmiputak123',
    label: 'cashier',
};

async function login(page, account) {
    await page.goto('/daily-ledger/login');
    await page.fill('input[name="username"]', account.username);
    await page.fill('input[name="full_name"]', account.fullName);
    await page.fill('input[name="password"]', account.password);
    // The form POSTs to /daily-ledger/auth/login via fetch and then assigns
    // window.location from the JSON `redirect`. Waiting on networkidle alone races
    // that assignment, so wait for the URL to actually leave the login page.
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 25000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
    return page.url();
}

/** Presence inventory of the controls that give each ledger its parity surface. */
async function inventory(page) {
    return page.evaluate(() => {
        const has = (sel) => !!document.querySelector(sel);
        const textHas = (needle) => {
            const body = document.body ? document.body.innerText : '';
            return body.includes(needle);
        };
        const btnIds = Array.from(document.querySelectorAll('button[id]')).map((b) => b.id);
        return {
            url: location.pathname + location.search,
            datePicker: {
                cashier: has('#ledger-date'),
                production: has('#production-date-picker'),
                anyDateInput: has('input[type="date"]'),
            },
            shiftControl: {
                segmentedToggle: has('[role="group"][aria-label="Shift"]'),
                lockedPill: textHas('Locked to'),
                shiftBadge: /\b(AM|PM) Shift\b/.test(document.body.innerText),
            },
            dayControls: {
                closeDay: has('#close-day-btn') || has('#production-close-day'),
                reopenDay: has('#reopen-day-btn') || has('#production-reopen-day'),
                closeDayId: has('#close-day-btn') ? 'close-day-btn'
                    : (has('#production-close-day') ? 'production-close-day' : null),
                reopenDayId: has('#reopen-day-btn') ? 'reopen-day-btn'
                    : (has('#production-reopen-day') ? 'production-reopen-day' : null),
                dayStatusPill: has('#action-bar-day-status'),
                dayStatusIndicator: has('#production-day-status'),
                closeDayWord: textHas('Close Day'),
                reopenDayWord: textHas('Reopen Day'),
            },
            shiftLifecycle: {
                closePmShift: has('#finalize-pm-btn') || has('#finalize-production-pm'),
                reopenShift: textHas('Reopen Shift'),
                finalizedBanner: textHas('is finalized'),
            },
            otherActions: {
                carryBeginnings: has('#carry-beginnings-btn') || has('#production-carry-beginnings-btn'),
                recomputeSales: has('#recompute-sales-btn'),
                recordAddition: textHas('Record Addition'),
                print: textHas('Print'),
                offlineAccess: has('#offline-access-btn'),
                posLink: has('a[href*="/pos"]'),
                tabs: Array.from(document.querySelectorAll('.tab-panel')).map((p) => p.id),
            },
            createOrderButton: textHas('Create order'),
            buttons: btnIds,
        };
    });
}

test.describe('daily-ledger production vs cashier parity', () => {
    test('production Daily Sheet renders the day/shift controls', async ({ page }) => {
        const landed = await login(page, PRODUCTION);
        expect(landed, 'production login must leave the login page').not.toContain('/login');

        await page.goto('/daily-ledger/admin/commissary');
        await page.waitForLoadState('networkidle');
        // Wait for the control under test rather than racing the render: a missing control must
        // fail loudly, not be reported as an absence of the feature.
        await page.waitForSelector('#production-close-day', { timeout: 15000 });

        const inv = await inventory(page);
        console.log('PRODUCTION_INVENTORY ' + JSON.stringify(inv));

        // Contract requirements (R1-R7) — the delivered behaviour, not a mechanism.
        expect(inv.datePicker.production, 'production view must render the date picker').toBe(true);
        expect(inv.dayControls.closeDayId, 'production view must offer Close Day').toBe('production-close-day');
        expect(inv.dayControls.reopenDayId, 'production_in_charge must NOT be offered Reopen').toBeNull();
        expect(inv.otherActions.recordAddition, 'production view keeps Record Addition').toBe(true);
        // PARITY with the cashier ledger: an explicit open/closed signal must always be shown.
        expect(inv.dayControls.dayStatusIndicator, 'production view must show the day status').toBe(true);
    });

    test('cashier ledger inventory for comparison', async ({ page }) => {
        await login(page, CASHIER);
        await page.goto('/daily-ledger/ledger');
        await page.waitForLoadState('networkidle');
        await page.waitForSelector('#ledger-date', { timeout: 15000 });

        const inv = await inventory(page);
        await page.screenshot({ path: 'test-results/parity-cashier-ledger.png', fullPage: false });
        console.log('CASHIER_INVENTORY ' + JSON.stringify(inv));

        expect(inv.datePicker.cashier, 'cashier ledger must render its date picker').toBe(true);
    });

    test('admin sees the admin-only Reopen, gated on the day being CLOSED (cashier parity)', async ({ page }) => {
        await login(page, { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123', label: 'admin' });
        await page.goto('/daily-ledger/admin/commissary');
        await page.waitForLoadState('networkidle');
        // The day must be OPEN for this test: Close Day is the control that proves it, and waiting
        // on it turns a render race into a clear failure instead of a misleading inventory.
        await page.waitForSelector('#production-close-day', { timeout: 15000 });

        const inv = await inventory(page);
        await page.screenshot({ path: 'test-results/parity-admin-daily-sheet.png', fullPage: false });
        console.log('ADMIN_INVENTORY ' + JSON.stringify(inv));

        expect(inv.dayControls.closeDayId, 'admin must be offered Close Day while the day is open').toBe('production-close-day');
        expect(inv.dayControls.dayStatusIndicator, 'admin view must show the day status').toBe(true);
        // The cashier offers Reopen only when the day is closed; on an open day the control
        // must be absent, otherwise apiReopenDay writes a false closed->open audit row.
        expect(
            inv.dayControls.reopenDayId,
            'Reopen must NOT be offered while the day is open (it would log a transition that never happened)',
        ).toBeNull();
        expect(inv.dayControls.closeDayWord, 'Close Day label present while open').toBe(true);
    });
});
