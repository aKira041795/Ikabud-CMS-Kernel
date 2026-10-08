// @ts-check
const { test, expect } = require('@playwright/test');

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };
const CASHIER = {
    username: process.env.TEST_CASHIER_A || 'cashier-miputak',
    fullName: process.env.TEST_CASHIER_A || 'cashier-miputak',
    password: process.env.TEST_CASHIER_PASS || 'cmiputak123'
};

async function login(page, user) {
    await page.context().clearCookies();
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', user.username);
    await page.fill('input[name="full_name"]', user.fullName);
    await page.fill('input[name="password"]', user.password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]')
    ]);
}

async function setFeature(page, enabled) {
    await login(page, ADMIN);
    await page.goto('/daily-ledger/admin/settings', { waitUntil: 'domcontentloaded' });
    const toggle = page.locator('#feature-branch-products');
    await expect(toggle).toBeVisible();
    if ((await toggle.isChecked()) !== enabled) {
        await toggle.setChecked(enabled);
    }
    const responsePromise = page.waitForResponse((response) =>
        response.url().includes('/daily-ledger/api/v1/admin/settings/permissions') && response.request().method() === 'POST'
    );
    await page.click('#save-perm-btn');
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.ok).toBe(true);
    expect(body.branch_product_self_management).toBe(enabled);
}

test.describe.serial('Daily Ledger — branch product self-management gate', () => {
    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        try {
            await setFeature(page, false);
        } finally {
            await page.close();
        }
    });

    test('screen is reachable for a branch user when setting is on', async ({ page }) => {
        await setFeature(page, true);
        await login(page, CASHIER);
        await page.goto('/daily-ledger/products', { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/\/daily-ledger\/products$/);
        await expect(page.getByRole('heading', { name: 'Branch Products' })).toBeVisible();
        await expect(page.locator('.wb-nav-item[aria-label="Branch Products"]')).toBeVisible();
    });

    test('screen is not reachable and nav is hidden when setting is off', async ({ page }) => {
        await setFeature(page, false);
        await login(page, CASHIER);
        await page.goto('/daily-ledger/products', { waitUntil: 'domcontentloaded' });
        expect(await page.locator('body').innerText()).toContain('Branch product self-management is disabled.');
        expect(await page.locator('.wb-nav-item[aria-label="Branch Products"]').count()).toBe(0);
    });
});
