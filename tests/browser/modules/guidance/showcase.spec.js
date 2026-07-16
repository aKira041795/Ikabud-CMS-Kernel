/**
 * Guidance end-to-end independence showcase.
 *
 * This spec is declared by modules/guidance/workbench-contract.json and is
 * launched by `php ikabud workbench:run guidance`. The contract service owns
 * the canonical run identity; WorkbenchReporter writes browser evidence under
 * that same ID for Superadmin correlation.
 */

// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('../../GuidanceAdapter');

const manifestPath = path.resolve(
    __dirname,
    '../../../../modules/guidance/module.json'
);
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const contract = JSON.parse(fs.readFileSync(
    path.resolve(__dirname, '../../../../modules/guidance/workbench-contract.json'),
    'utf8'
));
const adminNavigation = (manifest.nav || []).filter((item) =>
    !Array.isArray(item.roles) || item.roles.includes('admin')
);
const showcaseScenario = (contract.scenarios || []).find(
    (scenario) => scenario.id === 'guidance-independent-showcase'
);
const showcasePages = Array.from(new Set([
    ...adminNavigation.map((item) => String(item.url || '')),
    ...((showcaseScenario && showcaseScenario.pages) || []),
])).filter(Boolean);

test.describe('guidance:independent-showcase', () => {
    test.beforeAll(async ({ integrity }) => {
        integrity.fingerprint('modules/guidance/module.json');
        integrity.fingerprint('modules/guidance/workbench-contract.json');
        integrity.fingerprint('modules/guidance/WorkbenchComprehensionProvider.php');
    });

    test('uses the canonical contract run identity', async () => {
        expect(process.env.WB_RUN_ID).toMatch(/^[A-Za-z0-9._-]+$/);
        expect(process.env.ARK_MODULE).toBe('guidance');
        expect(process.env.MODULE).toBe('guidance');
        expect(process.env.HYBRID_GATE).toMatch(/^(critical|major|off)$/);
    });

    test('renders the authenticated Guidance Workbench shell', async ({ page, shell }) => {
        await shell.expectVisible();
        await expect(page).toHaveURL(/\/admin\/guidance(?:\/|$)/);
        await expect(page.locator('h1').first()).toBeVisible();
    });

    test('serves every declared showcase and admin navigation route', async ({ page }) => {
        expect(adminNavigation.length).toBeGreaterThan(0);
        expect(showcasePages.length).toBeGreaterThan(adminNavigation.length);

        for (const url of showcasePages) {
            expect(url).toMatch(/^\/admin\/guidance/);

            const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
            expect(response, url + ' did not return a response').not.toBeNull();
            expect(response.status(), url).toBeLessThan(400);
            await expect(page).not.toHaveURL(/\/guidance\/login/);
            await expect(page.locator('[data-wb-component="app-shell"]')).toBeVisible();
        }
    });

    test('retains the Workbench shell at the mobile viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 });
        await page.goto('/admin/guidance', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-wb-component="app-shell"]')).toBeVisible();
        await expect(page.locator('#wb-menu-btn')).toBeVisible();
    });
});
