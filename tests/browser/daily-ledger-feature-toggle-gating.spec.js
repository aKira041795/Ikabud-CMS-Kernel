// @ts-check
/**
 * Cash & Paper Check is opt-in: OFF by default, and hidden from the sidebar until an admin turns it
 * on. Owner: "there's a Cash and Paper Check, i want this to be on/off at settings. default, off".
 *
 * Both halves matter, so both are asserted:
 *   MUST-REFUSE with the feature off -- no nav link AND a direct URL is refused (hiding a link is not
 *     access control, so the route has its own gate).
 *   MUST-ALLOW with it on -- nav link present and the page renders.
 *
 * The ON case drives the real settings screen and its save path, because the risky part is the save
 * wiring, not the flag itself. The setting is restored to OFF in a finally block so a mid-test failure
 * cannot leave the tenant with the feature switched on.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(240000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const SHELL = '/daily-ledger/admin/commissary';          // a page that carries the sidebar
// The Consignee Dispatch entry exists ONLY in the layouts/app.disyl shell -- the commissary and
// variances shells never had it -- so it must be checked on a page that uses that layout.
const SHELL_APP = '/daily-ledger/admin/products';
const TARGET = '/daily-ledger/admin/reconciliation';      // the gated page
const SETTINGS = '/daily-ledger/admin/settings';
const NAV_LINK = '#wb-sidebar a[aria-label="Cash and Paper Check"]';

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

/** Flip the toggle on the real settings screen and wait until the server actually agrees. */
async function setFeature(page, on) {
    await page.goto(SETTINGS, { waitUntil: 'domcontentloaded' });
    const box = page.locator('#feature-cash-paper-check');
    await expect(box).toBeVisible({ timeout: 30000 });
    if (on) { await box.check(); } else { await box.uncheck(); }
    await page.locator('#save-perm-btn').click();
    // Poll the EFFECT, not a toast: the gated page must agree with what we asked for.
    await expect.poll(async () => (await page.request.get(TARGET)).status(), { timeout: 30000 })
        .toBe(on ? 200 : 403);
}

test('Cash & Paper Check: off by default, hidden and refused; on when enabled', async ({ page }) => {
    await login(page);

    try {
        // ── MUST-REFUSE: the default ─────────────────────────────────────────
        await page.goto(SHELL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
        const navCount = await page.locator(NAV_LINK).count();
        console.log('nav links with the feature OFF:', navCount);
        expect(navCount, 'a disabled feature must not appear in the sidebar').toBe(0);

        const refused = await page.goto(TARGET, { waitUntil: 'domcontentloaded' });
        console.log('direct URL with the feature OFF:', refused.status());
        expect(refused.status(), 'hiding the nav link is not access control').toBe(403);

        // A feature that is ON today must stay visible: the consignee report has its own switch, which
        // is true for this tenant, so gating the nav must not have hidden it.
        await page.goto(SHELL_APP, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
        const consigneeLinks = await page.locator('#wb-sidebar a[aria-label="Consignee Dispatch Ledger"]').count();
        console.log('consignee nav links on the app shell (setting is ON for this tenant):', consigneeLinks);
        expect(consigneeLinks, 'an enabled feature must remain reachable').toBe(1);

        // ── MUST-ALLOW: enable it for real ───────────────────────────────────
        await setFeature(page, true);

        await page.goto(SHELL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
        const navCountOn = await page.locator(NAV_LINK).count();
        console.log('nav links with the feature ON:', navCountOn);
        expect(navCountOn, 'an enabled feature must appear in the sidebar').toBe(1);

        const allowed = await page.goto(TARGET, { waitUntil: 'domcontentloaded' });
        console.log('direct URL with the feature ON:', allowed.status());
        expect(allowed.status()).toBe(200);
    } finally {
        // Always leave the tenant as found: OFF. Re-navigate before counting, because saving settings
        // refreshes only the main content -- the sidebar is rendered server-side at page load, so the
        // nav in the current DOM is a stale render from before the toggle.
        await setFeature(page, false);
        await page.goto(SHELL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
        const restored = await page.locator(NAV_LINK).count();
        console.log('nav links after restore:', restored);
        expect(restored).toBe(0);
    }
});
