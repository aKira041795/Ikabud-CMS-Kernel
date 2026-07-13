/**
 * PAL Navigation Smoke Test — verifies page routing and shell structure.
 *
 * This is NOT a lifecycle test. For the seeded lifecycle, see
 * pal-seeded-lifecycle.spec.js.
 *
 * Gaps/fingerprints via integrity annotations.
 * Pass/fail via WorkbenchReporter — no afterEach needed.
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');

test.describe('pal:navigation-smoke', function() {

    test.beforeAll(async function({ integrity }) {
        integrity.fingerprint('modules/project-audit-ledger/services/JobOrderWorkflow.php');
        integrity.gap('Deterministic seeding via API — currently navigates existing data');
    });

    test('dashboard loads with app shell', async function({ page, shell }) {
        await shell.expectVisible();
        await expect(page.locator('#wb-main h1')).toBeVisible();
    });

    test('navigate to project list via sidebar', async function({ page, shell }) {
        await shell.navigateViaSidebar('All Job Orders');
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger\/projects/);
        await expect(page.locator('[data-ikb-list="pal-project"]')).toBeVisible();
    });

    test('open first project detail and verify header', async function({ page }) {
        await page.goto('/admin/project-audit-ledger/projects');
        await page.waitForSelector('[data-ikb-list="pal-project"]', { timeout: 10000 });
        var firstRow = page.locator('[data-ikb-list="pal-project"] tbody tr').first();
        await expect(firstRow).toBeVisible();
        var link = firstRow.locator('a').first();
        var href = await link.getAttribute('href');
        if (href) {
            await page.goto(href);
            await expect(page.locator('#wb-main')).toBeVisible();
        }
    });

    test('navigate back to dashboard', async function({ page, shell }) {
        await shell.navigateViaSidebar('Dashboard');
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger$/);
    });
});
