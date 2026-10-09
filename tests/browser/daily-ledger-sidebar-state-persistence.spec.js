// @ts-check
/**
 * The sidebar's expanded/collapsed state must SURVIVE navigating to another view.
 *
 * Reported: "when clicked to expand, maintain the state. current behaviour - reverts to collapsed
 * when opening another view."
 *
 * Cause: every daily-ledger shell hardcoded x-data="{ sidebarOpen: false }", so each page load reset
 * the sidebar to collapsed. There are three separate shells, and this journey deliberately crosses
 * between two DIFFERENT ones -- commissary.disyl renders its own shell, while products.disyl extends
 * layouts/app.disyl -- because a fix that only worked within one template would pass a single-page
 * test and still fail in the app.
 *
 * w-20 collapsed = 80px, w-64 expanded = 256px (transition-all duration-300, hence expect.poll).
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
// A non-admin, to prove the shared-device case below.
const PROD = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };

// Two pages whose shells come from different template files.
const SHELL_A = '/daily-ledger/admin/commissary';   // admin/commissary.disyl -- own shell
const SHELL_B = '/daily-ledger/admin/products';     // products.disyl -> layouts/app.disyl

async function loginAs(page, creds) {
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', creds.username);
    await page.fill('input[name="full_name"]', creds.fullName);
    await page.fill('input[name="password"]', creds.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

const login = (page) => loginAs(page, ADMIN);

/** Sidebar width in px, once Alpine has applied the w-20/w-64 binding. */
const sidebarWidth = (page) => page.locator('#wb-sidebar').evaluate((el) => Math.round(el.getBoundingClientRect().width));

/** A nav label only exists visually when the sidebar is expanded (x-show="sidebarOpen"). */
const navLabelsVisible = (page) => page.locator('#wb-sidebar .wb-nav-item span:visible').count();

async function goto(page, path) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

test('a fresh visitor still gets a COLLAPSED sidebar (the default must not regress)', async ({ page }) => {
    await login(page);
    await goto(page, SHELL_A);
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeLessThan(100);
    console.log('fresh sidebar width:', await sidebarWidth(page));
    // Nothing has been stored yet, so the collapsed default must not have written a preference.
    const stored = await page.evaluate(() => { try { return localStorage.getItem('dl_sidebar_open'); } catch (e) { return 'unreadable'; } });
    console.log('stored value on first paint:', JSON.stringify(stored));
    expect(stored === null || stored === '0').toBe(true);
});

test('expanding survives navigating to another view, across a different shell template', async ({ page }) => {
    await login(page);
    await goto(page, SHELL_A);

    const collapsed = await sidebarWidth(page);
    await page.locator('#wb-sidebar button[aria-label="Expand sidebar"]').first().click();
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);
    const expanded = await sidebarWidth(page);
    console.log(`collapsed=${collapsed}px expanded=${expanded}px`);
    expect(expanded).toBeGreaterThan(collapsed);

    // THE BUG: this navigation used to reset it to collapsed.
    await goto(page, SHELL_B);
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);
    console.log('after navigating to a different shell:', await sidebarWidth(page), 'px');

    // The user-facing truth, not just the width: the labels are readable.
    expect(await navLabelsVisible(page)).toBeGreaterThan(0);

    // And back again, so this is persistence rather than a one-way latch.
    await goto(page, SHELL_A);
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);
});

test('collapsing also survives, so the state cannot get stuck expanded', async ({ page }) => {
    await login(page);

    // Expand first, so the collapse below is a real transition rather than the default.
    await goto(page, SHELL_A);
    await page.locator('#wb-sidebar button[aria-label="Expand sidebar"]').first().click();
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);

    await page.locator('#wb-sidebar button[aria-label="Collapse sidebar"]').first().click();
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeLessThan(100);

    await goto(page, SHELL_B);
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeLessThan(100);
    console.log('collapsed state carried across views:', await sidebarWidth(page), 'px');

    // Collapsed means icon-only, so the labels must be hidden.
    expect(await navLabelsVisible(page)).toBe(0);
});

test('a reload keeps the chosen state too (not only client-side navigation)', async ({ page }) => {
    await login(page);
    await goto(page, SHELL_A);
    await page.locator('#wb-sidebar button[aria-label="Expand sidebar"]').first().click();
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);
    console.log('after a full reload:', await sidebarWidth(page), 'px');
});

/** localStorage keys the shell uses, so scoping can be observed rather than assumed. */
const storedKeys = (page) => page.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('dl_sidebar_open') === 0));

test('a non-admin gets the COLLAPSED full-width view even after an admin expanded on this device', async ({ page }) => {
    // The admin expands the sidebar on this browser.
    await loginAs(page, ADMIN);
    await goto(page, SHELL_A);
    await page.locator('#wb-sidebar button[aria-label="Expand sidebar"]').first().click();
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeGreaterThan(200);
    const adminKeys = await storedKeys(page);
    console.log('stored keys after admin expanded:', JSON.stringify(adminKeys));
    expect(adminKeys.length).toBeGreaterThan(0);

    // Same browser, same localStorage -- now a NON-ADMIN. localStorage is shared by every account on
    // a device, so an unscoped key would hand this user the admin's expanded sidebar and cost them
    // the full-width ledger view.
    await page.goto('/daily-ledger/logout', { waitUntil: 'domcontentloaded' });
    await loginAs(page, PROD);
    await goto(page, SHELL_A);
    await expect.poll(() => sidebarWidth(page), { timeout: 10000 }).toBeLessThan(100);
    console.log('non-admin width on a device where admin expanded:', await sidebarWidth(page), 'px');

    // Scoping, not a wipe: the admin's own preference survives for the admin.
    const afterKeys = await storedKeys(page);
    console.log('stored keys after the non-admin loaded:', JSON.stringify(afterKeys));
    expect(afterKeys.some((k) => k.endsWith(':admin'))).toBe(true);
    // And collapsed means icon-only, so the labels must be hidden for this user too.
    expect(await navLabelsVisible(page)).toBe(0);
});
